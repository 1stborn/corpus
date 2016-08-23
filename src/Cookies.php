<?php
namespace Corpus;

use Interop\Container\ContainerInterface;

class Cookies extends \Slim\Http\Cookies {
	/**
	 * @var ContainerInterface
	 */
	private $ci;

	public function __construct(ContainerInterface $ci, array $cookies = []) {
		$this->ci = $ci;

		parent::__construct($cookies);
	}

	public function setCookie($name, $value, $lifetime = 0) {
		$this->set($name, ['value' => $value, 'expires' => time() + $lifetime, 'path' => '/']);
	}

	public function getCookie($name, $default = null) {
		return $this->get($name, $this->ci->get('request')->getCookieParam($name, $default));
	}
}
