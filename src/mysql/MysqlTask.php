<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\mysql;

use PDO;
use pocketmine\scheduler\AsyncTask;
use Throwable;
use function is_array;
use function serialize;
use function unserialize;

abstract class MysqlTask extends AsyncTask{
	/**
	 * @param array<string, mixed> $mysql
	 */
	public function __construct(
		array $mysql
	){
		$this->mysql = serialize($mysql);
	}

	private readonly string $mysql;

	/**
	 * @return array<string, mixed>
	 */
	protected function mysql() : array{
		$mysql = unserialize($this->mysql, ["allowed_classes" => false]);
		return is_array($mysql) ? $mysql : [];
	}

	protected function connect() : PDO{
		$mysql = $this->mysql();
		$host = (string) $mysql["host"];
		$port = (int) $mysql["port"];
		$database = (string) $mysql["database"];
		$username = (string) $mysql["username"];
		$password = (string) $mysql["password"];
		$timeout = (int) $mysql["timeout-seconds"];
		$dsn = "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $database . ";charset=utf8mb4";

		return new PDO($dsn, $username, $password, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_TIMEOUT => $timeout,
		]);
	}

	protected function table(string $name) : string{
		$mysql = $this->mysql();
		return "`" . (string) $mysql["table-prefix"] . $name . "`";
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
