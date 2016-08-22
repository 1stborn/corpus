<?php
namespace Corpus;

class RedisException extends \Exception {}
class RedisConnectionException extends RedisException {}

class Redis {
	private $config = [];

	public function __construct(array $config = []) {
		$this->config = $config;
	}
	
	private function socket($reconnect = false) {
		static $connection;

		if ( $reconnect || !$connection ) {
			$connection = @fsockopen(
				$this->config['hostname'],
				$this->config['port'],
				$errno, $errstr,
				$this->config['timeout']);

			if ( !$connection )
				throw new RedisConnectionException("Unable to connect {$this->config['hostname']}:{$this->config['port']}: ({$errno}) {$errstr}");

			stream_set_timeout($connection, -1, null); // infinitive read timeout
			stream_set_blocking($connection, 1);

			empty($this->config['password']) or
				$this->auth($this->config['password']);

			empty($this->config['database']) or
				$this->select((int) $this->config['database']);
		}

		return $connection;
	}

	public function close() {
		fclose($this->socket());
	}

	public function __call($name, $args) {
		static $crlf = "\r\n";
		$args = (array) $args;

		array_unshift($args, $name);

		$command = '*' . count($args) . $crlf;
		foreach ($args as $arg)
			$command .= '$' . strlen($arg) . $crlf . $arg . $crlf;

		do {
			try {
				return $this->exec($command);
			} catch ( RedisConnectionException $e ) {
				$this->socket(true);
			}
		} while ( true );

		return null;
	}

	private function exec($command) {
		$written = 0;
		$length = strlen($command);

		while ( $written < $length ) {
			$bytes = $this->write($this->socket(), substr($command, $written));

			if ( $bytes === false ) {
				if ( $this->socket(true) ) {
					$written = 0;
					continue;
				}
				else
					throw new RedisConnectionException('Failed to write entire command to stream');
			}

			$written += $bytes;
		}

		return $written == $length ? $this->response() : null;
	}

	public function hmset($name, array $values) {
		$args = [$name];
		foreach ($values as $key => $value) {
			$args[] = $key;
			$args[] = serialize($value);
		}

		return $this->__call('hmset', $args);
	}

	private function mget($values) {
		$args = [];
		if ( $values )
			for ($i = 0, $l = sizeof($values) - 1; $i < $l; $i += 2)
				$args[$values[$i]] = unserialize($values[$i + 1]) ?? null;

		return $args;
	}

	public function hmget($name, array $fields) {
		return $this->mget($this->__call('hmget', $fields));
	}

	public function hgetall($name) {
		return $this->mget($this->__call('hgetall', $name));
	}

	private function write($stream, $bytes) {
		file_put_contents('/tmp/redis.log', $bytes, FILE_APPEND);

		if (!isset($bytes[0]) )
			return 0;
		else if (($result = @fwrite($stream, $bytes)) !== 0)
			return $result;
		else if ( stream_select($read = null, $write = [ $stream ], $except = null, 0) === false ) {
			if (!$write)
				return 0;
			else if (($result = @fwrite($stream, $bytes)) !== 0)
				return $result;
		}

		return false;
	}

	private function response() {
		if ( ( $data = @fgets($this->socket(), 512) ) === false )
			throw new RedisConnectionException("Connnection broken");

		$reply = trim($data);

		switch ($reply[0]) {
			case '+': /* Inline reply */
				return ( $response = substr(trim($reply), 1) ) === 'OK' ? true : $response;
			case ':': /* Integer reply */
				return intval(substr(trim($reply), 1));
			case '$': /* Bulk reply */
				if ($reply == '$-1')
					return null;
				$read = 0;
				$size = intval(substr($reply, 1));
				$response = '';
				if ($size > 0) {
					do {
						$block_size = ($size - $read) > 1024 ? 1024 : ($size - $read);
						if (($r = fread($this->socket(), $block_size)) === false)
							throw new RedisException('Failed to read response from stream');
						else {
							$read += strlen($r);
							$response .= $r;
						}
					} while ($read < $size);
				}
				fread($this->socket(), 2); /* discard crlf */
				return $response;
			case '*':/* Multi-bulk reply */
				$response = [];
				$count = intval(substr($reply, 1));
				if ($count == '-1')
					return null;
				else
					for ($i = 0; $i < $count; $i++)
						$response[] = $this->response();

				return $response;
			case '-': /* Error reply */
				throw new RedisException(trim(substr($reply, 4)));
			default:
				throw new RedisException("Unknown response: {$reply}");
		}
	}
}