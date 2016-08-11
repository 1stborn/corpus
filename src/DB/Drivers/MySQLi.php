<?php
namespace Corpus\DB\Drivers;

use Corpus\Config;
use DB\Driver;

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

	public function escape($value) {
		return $this->connection()->real_escape_string($value);
	}

	public function query($sql) {
		if ( $this->connection()->real_query($sql) ) {
			return $this->connection->store_result();
		} else {
			throw new \Exception($this->connection->error, $this->connection->errno);
		}
	}
}