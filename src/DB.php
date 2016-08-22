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
		return $this->execute(preg_replace_callback('~(?:\?[0-9]+|\:[a-z_][a-z0-9_]*)~i', function ($value) use ($params) {
			list($code, $key) = [($value = current($value))[0], substr($value, 1)];

			switch ($code) {
				case '?':
					$key = (int)$key;
				//no break;
				case ':':
					return $this->filter(array_key_exists($key, $params) ? $params[$key] : null);
			}

			return $value;
		}, $sql));
	}

	public function current($sql, array $params = []) {
		return is_object($result = $this->query($sql, $params)) ? $result->fetch_row() : null;
	}

	public function scalar($sql, array $params = []) {
		return is_object($result = $this->query($sql, $params)) && ($row = $result->fetch_row()) ? reset($row) : null;
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
	 * @return DB\Driver
	 */
	protected function provider() {
		if (!$this->driver) {
			$this->driver = new $this->config['driver']($this->config);
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