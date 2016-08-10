<?php
namespace Corpus\View;

use Corpus\Config;
use Corpus\View;

use Twig_Environment;
use Twig_Error_Loader;
use Twig_Loader_Filesystem;
use Twig_Template;

class Twig extends View {
	protected $extension = '.twig';

	private static $twig;

	private $template;

	public function __construct($template = null) {
		if ( !self::$twig ) {
			$loader = new Twig_Loader_Filesystem;

			foreach ( Config::get('view.templates') as $namespace => $path )
				$loader->addPath($path, $namespace);

			self::$twig = new Twig_Environment($loader, Config::get('twig'));

			foreach ( Config::get('twig.extensions', []) as $extension ) {
				$extension = \Twig_Extension::class . '_' . ucfirst($extension);
				if ( class_exists($extension) ) {
					self::$twig->addExtension(new $extension);
				}
			}
		}

		if ( $template )
			$this->template = self::$twig->loadTemplate($template);
	}

	public function render($args = [], $template = null) {
		$template = $this->getTemplate($template);
		if ( $template instanceof Twig_Template )
			return $template->render(array_merge($this->getContext(), (array)$args));

		return null;
	}

	private function getTemplate($template = null) {
		if ( $template instanceof Twig_Template )
			return $template;
		else if ( $template === null )
			return $this->template;
		else if ( !$template )
			return self::$twig->loadTemplate(Config::get('view.default') . $this->extension);
		else if ( substr($template, -strlen($this->extension)) != $this->extension ) {
			return self::$twig->loadTemplate($template . $this->extension);
		}else
			return self::$twig->loadTemplate($template);
	}

	function find($template) {
		try {
			return $this->getTemplate($template);
		} catch ( Twig_Error_Loader $ignored ) {}

		return null;
	}
}