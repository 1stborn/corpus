<?php
namespace Corpus;

use mysqli;

class DB {
	private $driver;

	/**
	 * @var array
	 */
	private $config;

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

	protected function provider() {
		if ( !$this->driver ) {
			$this->driver = new mysqli(
				$this->config['host'],
				$this->config['user'],
				$this->config['password'],
				$this->config['dbname']);

			$this->driver->set_charset(Config::get('db.charset'));
		}

		return $this->driver;
	}

	public function query($sql, array $params = []) {
		return $this->provider()->query($sql);
	}
}