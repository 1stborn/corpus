<?php
namespace Corpus\DB;

use Corpus\Config;

abstract class Driver {
	protected $config = [
	  'host'     => '127.0.0.1',
	  'port'     => '3306',
	  'user'     => 'root',
	  'password' => '',
	  'dbname'   => 'test'
	];

	public function __construct($config) {
		$this->config = Config::merge($this->config, $config);
	}

	abstract public function connection();

	abstract public function escape($value);

	abstract public function query($sql);
}