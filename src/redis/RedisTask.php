<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\redis;

use pocketmine\scheduler\AsyncTask;
use RuntimeException;
use Throwable;
use function array_map;
use function count;
use function fclose;
use function fgets;
use function fwrite;
use function is_array;
use function is_resource;
use function rtrim;
use function serialize;
use function strlen;
use function stream_set_timeout;
use function stream_socket_client;
use function substr;
use function unserialize;
use const STREAM_CLIENT_CONNECT;

abstract class RedisTask extends AsyncTask{
	/**
	 * @param array<string, mixed> $redis
	 */
	public function __construct(
		array $redis
	){
		$this->redis = serialize($redis);
	}

	private readonly string $redis;

	/**
	 * @return array<string, mixed>
	 */
	protected function redis() : array{
		$redis = unserialize($this->redis, ["allowed_classes" => false]);
		return is_array($redis) ? $redis : [];
	}

	/**
	 * @return resource
	 */
	protected function connect(){
		$redis = $this->redis();
		$target = "tcp://" . (string) $redis["host"] . ":" . (int) $redis["port"];
		$timeout = (int) $redis["timeout-seconds"];
		$socket = @stream_socket_client($target, $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
		if(!is_resource($socket)){
			throw new RuntimeException("failed to connect to Redis: " . $error);
		}
		stream_set_timeout($socket, $timeout);

		$username = (string) ($redis["username"] ?? "");
		$password = (string) ($redis["password"] ?? "");
		if($password !== ""){
			if($username !== ""){
				$this->command($socket, "AUTH", $username, $password);
			}else{
				$this->command($socket, "AUTH", $password);
			}
		}

		$database = (int) ($redis["database"] ?? 0);
		if($database > 0){
			$this->command($socket, "SELECT", (string) $database);
		}

		return $socket;
	}

	/**
	 * @param resource $socket
	 * @return mixed
	 */
	protected function command($socket, string ...$parts) : mixed{
		$payload = "*" . count($parts) . "\r\n";
		foreach($parts as $part){
			$payload .= "$" . strlen($part) . "\r\n" . $part . "\r\n";
		}
		fwrite($socket, $payload);
		return $this->readResponse($socket);
	}

	/**
	 * @param resource $socket
	 * @return mixed
	 */
	private function readResponse($socket) : mixed{
		$line = fgets($socket);
		if($line === false){
			throw new RuntimeException("empty Redis response");
		}
		$type = $line[0];
		$value = rtrim(substr($line, 1), "\r\n");

		if($type === "+"){
			return $value;
		}
		if($type === "-"){
			throw new RuntimeException("Redis error: " . $value);
		}
		if($type === ":"){
			return (int) $value;
		}
		if($type === "$"){
			$length = (int) $value;
			if($length < 0){
				return null;
			}
			$data = "";
			while(strlen($data) < $length){
				$chunk = fgets($socket, $length - strlen($data) + 1);
				if($chunk === false){
					throw new RuntimeException("truncated Redis bulk string");
				}
				$data .= $chunk;
			}
			fgets($socket);
			return $data;
		}
		if($type === "*"){
			$count = (int) $value;
			if($count < 0){
				return null;
			}
			$result = [];
			for($i = 0; $i < $count; ++$i){
				$result[] = $this->readResponse($socket);
			}
			return $result;
		}

		throw new RuntimeException("unknown Redis response type: " . $type);
	}

	protected function close(mixed $socket) : void{
		if(is_resource($socket)){
			fclose($socket);
		}
	}

	protected function key(string $name) : string{
		$redis = $this->redis();
		$prefix = (string) ($redis["key-prefix"] ?? "cspm");
		return $prefix . ":" . $name;
	}

	/**
	 * @return array<string, string>
	 */
	protected function hgetall(mixed $socket, string $key) : array{
		$response = $this->command($socket, "HGETALL", $key);
		if(!is_array($response)){
			return [];
		}

		$result = [];
		for($i = 0; $i < count($response); $i += 2){
			$result[(string) $response[$i]] = (string) ($response[$i + 1] ?? "");
		}
		return $result;
	}

	/**
	 * @param list<string> $fields
	 */
	protected function hdel(mixed $socket, string $key, array $fields) : void{
		if($fields === []){
			return;
		}
		$this->command($socket, "HDEL", $key, ...array_map("strval", $fields));
	}

	/**
	 * @return array{ok: false, error: string}
	 */
	protected function errorResult(Throwable $throwable) : array{
		return [
			"ok" => false,
			"error" => $throwable->getMessage(),
		];
	}
}
