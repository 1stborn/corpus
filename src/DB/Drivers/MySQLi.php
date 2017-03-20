<?php
namespace Corpus\DB\Drivers;

use Corpus\Config;
use Corpus\DB\Driver;

class MySQLi extends Driver {
	/**
	 * @var \mysqli
	 */
	private $connection = null;

	public function connection() {
		if ( !$this->connection || !$this->connection->ping() ) {
			$this->connection = new \mysqli(
				$this->config['host'],
				$this->config['user'],
				$this->config['password'],
				$this->config['dbname'],
				$this->config['port']
			);
			$this->connection->set_charset(Config::get('db.charset', 'utf8'));
		}

		return $this->connection;
	}

	public function __get($name) {
		return $this->connection()->{$name};
	}

	public function escape($value) {
		return $this->connection()->real_escape_string($value);
	}

	public function query($sql) {
		if ( $this->connection()->real_query($sql) ) {
			switch (strtolower(substr(trim($sql), 0, 4))) {
				case 'inse':
					return $this->connection->insert_id;
				case 'dele':
				case 'upda':
					return $this->connection->affected_rows;
				case 'sele':
				case 'show':
				case 'desc':
					return $this->connection->store_result();
				default:
					return null;
			}
		} else {
			throw new \Exception($this->connection->error . "\n$sql", $this->connection->errno);
		}
	}
}