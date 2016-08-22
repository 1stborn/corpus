<?php
namespace Corpus;

use Interop\Container\ContainerInterface;

class Session {
	protected $data;

	private $delete;

	/**
	 * @var ContainerInterface
	 */
	private $ci;

	public function __construct(ContainerInterface $ci) {
		$this->ci = $ci;
	}

	public function flash($message = null) {
		$this->init();

		if (!array_key_exists('__flash__', $this->data))
			$this->data['__flash__'] = [];

		return $message
			? array_push($this->data['__flash__'], $message)
			: array_pop($this->data['__flash__']);
	}

	public function __get($name) {
		$this->init();

		return array_key_exists($name, $this->data) ? $this->data[$name] : null;
	}

	public function __set($name, $value) {
		$this->init();

		if (is_null($value)) {
			unset($this->data[$name]);
			$this->delete[$name] = 1;
		} else {
			unset($this->delete[$name]);
			$this->data[$name] = $value;
		}
	}

	public function get($param, $default = null) {
		return $this->__get($param) ?: $default;
	}

	public function set($param, $value = null) {
		if (is_array($param))
			foreach ($param as $key => $value)
				$this->__set($key, $value);
		else
			$this->__set($param, $value);

		return $this;
	}

	public function __destruct() {
		if ($this->data || $this->delete) {
			$cache = $this->ci->get('cache');

			if ($this->delete)
				$cache->hdel($this->getSID(), array_keys($this->delete));

			if ($this->data) {
				$cache->hmset($this->getSID(), $this->data);
				if ($lifetime = Config::get('session.lifetime', 0))
					$cache->expire($this->getSID(), $lifetime);
			}
		}
	}

	protected function init() {
		if ($this->data === null)
			$this->data = $this->ci->get('cache')->hgetall($this->getSID()) ?: [];
	}

	private function getSID($scope = '') {
		$name = Config::get('session.name');

		if (!$id = $this->ci->get('cookies')->getCookie($name))
			$this->ci->get('cookies')->setCookie(
				$name,
				$id = sha1(fread(fopen('/dev/urandom', 'r'), 100)),
				Config::get('session.lifetime'));

		return '__session.' . ($scope ? $scope . '.' : '') . $id;
	}
}