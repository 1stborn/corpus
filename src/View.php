<?php
namespace Corpus;

abstract class View {
	protected $extension = '.php';

	private $data = [];

	abstract function render($args = [], $template = null);

	abstract function find($template);

	public function set($name, $value = null) {
		if ( is_array($name) )
			foreach($name as $key => $value )
				$this->{$key} = $value;
		else if ( is_scalar($name) )
			$this->{$name} = $value;

		return $this;
	}

	public function getContext() {
		return $this->data;
	}

	public function __get($name) {
		return $this->data[$name] ?? null;
	}

	public function __set($name, $value) {
		$this->data[$name] = $value;
	}
}