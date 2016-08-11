<?php
namespace Corpus;

class DB {
	private $driver;

	/**
	 * @var array
	 */
	private $config;

	public $last_query = null;

	public function __construct(array $config = []) {
		$this->config = $config;
	}

	public function __call($name, $arguments) {
		return call_user_func_array([$this->driver, $name], $arguments);
	}

	public function __get($name) {
		return $this->driver->$name;
	}

	public function __set($name, $value) {
		$this->driver->$name = $value;
	}

	public function query($sql, array $params = []) {
		static $injector;
		if (!$injector)
			$injector = function ($value) use ($params) {
				switch ($value[0]) {
					case ':':
						$key = substr($value, 1);
					//no break
					case '?':
						$key = isset($key) ? $key : (int)substr($value, 1);
						if (array_key_exists($key, $params))
							return $this->filter($params[$key]);
				}

				return $value;
			};

		return $this->execute(preg_replace_callback('~(\?[0-9]+|\:[a-z_][a-z0-9_]*~', $injector, $sql));
	}

	public function scalar($sql, array $params = []) {
		$result = $this->query($sql, $params);
		if ( is_object($result) ) {
			return reset($result->fetch_row());
		}

		return null;
	}

	public function execute($sql) {
		$this->last_query = $sql;

		return $this->provider()->query($sql);
	}

	public function update($table, array $params = [], $where = [], $limit = null) {
		$sql = 'UPDATE ' . $table;
		if ($params) {
			$sql .= ' ';
			foreach ($params as $key => $value)
				if (!is_numeric($key) && is_scalar($value))
					$sql .= $key . '=' . $this->filter($value) . ',';

			$sql[strlen($sql) - 1] = ' ';

			$sql .= 'WHERE 1' . $this->combine($where);

			if ($limit) {
				$sql .= ' LIMIT ' . (int)$limit;
			}
		}

		return (bool)$this->execute($sql);
	}

	public function delete($table, $where = [], $limit = null) {
		if ($where) {
			$sql = 'DELETE FROM ' . $table . ' WHERE 1' . $this->combine($where);

			if ($limit) {
				$sql .= ' LIMIT ' . (int)$limit;
			}

			return (bool)$this->execute($sql);
		}

		return false;
	}

	public function insert($table, array $data = [], array $update = []) {
		$sql = 'INSERT INTO ' . $table;

		if (is_integer(key($data))) {
			$sql .= '(`' . implode('`,`', array_keys(reset($value))) . '`) VALUES ';
			$callback = [$this, 'escape'];

			foreach ($data as $value) {
				$sql .= '(' . implode(',', array_map($callback, $value)) . '),';
			}
		} else {
			foreach ($data as $key => $value) {
				$sql .= $key . '=' . $this->filter($value) . ',';
			}
		}

		$sql[strlen($sql) - 1] = '';

		if ($update) {

			$sql .= ' ON DUPLICATE KEY UPDATE ';
			foreach ($update as $field) {
				$sql .= '`'.$field.'` = VALUES(`'.$field.'`),';
			}
			$sql[strlen($sql) - 1] = '';
		}

		return (int) $this->execute($sql);
	}

	protected function escape($value) {
		return $this->provider()->escape($value);
	}

	/**
	 * @return \DB\Driver
	 */
	protected function provider() {
		if (!$this->driver) {
			$driver = Config::get('db.{default}.driver');
			$this->driver = new $driver;
		}

		return $this->driver;
	}

	private function filter($value) {
		if (is_object($value) || is_array($value)) {
			$values = array();
			foreach ($value as $element) {
				$values[] = $this->filter($element);
			}
			return implode(",", $values);
		} else if (!is_bool($value) && preg_match('~^\-?(?(?=[1-9])[0-9]*?\.?|0(?(?=[0-9])\.|\.?))[0-9]*$~', $value)) {
			if (strpos($value, ".") !== false) {
				return round($value, 5);
			} else {
				return (int)$value;
			}
		} else if (is_string($value)) {
			return "'" . str_replace(['\\\\%', '\\\\_'], ['\%', '\_'], $this->escape($value)) . "'";
		} else if (is_bool($value)) {
			return $value ? 'TRUE' : 'FALSE';
		} else if (is_null($value)) {
			return 'NULL';
		} else {
			return '';
		}
	}

	private function combine($params, $scalar = 'AND', $mixed = 'OR') {
		$result = '';
		foreach ($params as $key => $value) {
			if (is_scalar($value)) {
				$result .= ' ' . $scalar . ' ' . $key . '=' . $this->filter($value);
			} else {
				$result .= ' ' . $mixed . ' (' . $this->combine($value) . ')';
			}
		}

		return $result;
	}
}