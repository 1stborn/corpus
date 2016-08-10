<?php
namespace Corpus;

class Config {
	public static $env = null;

	private static $targets = [];

	public static function get($name, ...$args) {
		static $cache = [];

		if ( !array_key_exists($name, $cache) ) {
			$path = explode('.', self::resolve($name));

			$value = static::load(array_shift($path));

			foreach ($path as $part)
				if (array_key_exists($part, $value))
					$value = $value[$part];
				else
					return array_key_exists(0, $args) ? $args[0] : null;

			$cache[$name] = $value;
		}

		return is_string($cache[$name]) && $args ? vsprintf($cache[$name], $args) : $cache[$name];
	}

	public static function load($target) {
		if ( !array_key_exists($target, self::$targets) ) {
			self::$targets[$target] = [];

			foreach ([CONFIG_DIR . '/%s.php',
				      CONFIG_DIR . '/' . static::$env . '/%s.php'] as $dir)
				if ( file_exists($file = sprintf($dir, $target)) ) {
					self::$targets[$target] =
						self::merge(self::$targets[$target], require $file);
				}
		}

		return self::$targets[$target];
	}

	private static function resolve(string $path) {
		$space = substr($path, 0, strpos($path, '.'));

		for ( $i = strlen($path) - 1; $i >= 0; $i-- )
			if ( $path[$i] == '}' ) {
				for($end = $i; $i >= 0; $i--)
					if ( $path[$i] == '{' )
						break;
				$i++;
				if ( $value = static::get($space . '.' . substr($path, $i, $end - $i)) )
					return self::resolve(substr($path, 0, $i - 1) . $value . substr($path, $end + 1));
			}

		return $path;
	}

	public static function merge(array $array, ...$arrays): array {
		$merged = $array;

		foreach ($arrays as $arr )
			foreach ($arr as $key => &$value)
				if ( is_int($key) )
					$merged[] = $value;
				else if ( is_array($value) && array_key_exists($key, $merged) && is_array($merged[$key]) )
					$merged[$key] = self::merge($merged[$key], $value);
				else
					$merged[$key] = $value;

		return $merged;
	}
}