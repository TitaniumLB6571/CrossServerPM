<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM;

use pocketmine\utils\Config;
use function hash;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function max;
use function preg_replace;
use function trim;

final class PluginSettings{
	/**
	 * @param array<string, mixed> $mysql
	 * @param array<string, mixed> $relay
	 * @param array<string, mixed> $redis
	 * @param array<string, mixed> $file
	 * @param array<string, string> $knownServers
	 * @param array<string, string> $messages
	 */
	public function __construct(
		public readonly bool $enabled,
		public readonly string $transport,
		public readonly string $networkId,
		public readonly string $networkSecret,
		public readonly string $networkHash,
		public readonly string $serverId,
		public readonly string $serverDisplayName,
		public readonly array $mysql,
		public readonly array $relay,
		public readonly array $redis,
		public readonly array $file,
		public readonly array $knownServers,
		public readonly int $pollIntervalSeconds,
		public readonly int $heartbeatIntervalSeconds,
		public readonly int $stalePresenceSeconds,
		public readonly int $messageTtlSeconds,
		public readonly int $maxMessageLength,
		public readonly int $commandCooldownSeconds,
		public readonly bool $allowConsoleMessaging,
		public readonly string $consoleName,
		public readonly array $messages
	){}

	public static function fromConfig(Config $config) : self{
		$enabled = self::bool($config->get("enabled", false));
		$transport = self::string($config->get("transport", "mysql"), "mysql");
		$networkId = self::string($config->getNested("network.id", "my-network"), "my-network");
		$networkSecret = self::string($config->getNested("network.secret", ""), "");
		$serverId = self::string($config->getNested("server.id", "survival"), "survival");
		$serverDisplayName = self::string($config->getNested("server.display-name", $serverId), $serverId);
		$knownServers = self::knownServers($config->getNested("network.servers", []));
		$tablePrefix = preg_replace('/[^A-Za-z0-9_]/', "", self::string($config->getNested("mysql.table-prefix", "cspm_"), "cspm_"));
		if($tablePrefix === ""){
			$tablePrefix = "cspm_";
		}

		$mysql = [
			"host" => self::string($config->getNested("mysql.host", "127.0.0.1"), "127.0.0.1"),
			"port" => self::int($config->getNested("mysql.port", 3306), 3306),
			"database" => self::string($config->getNested("mysql.database", "crossserverpm"), "crossserverpm"),
			"username" => self::string($config->getNested("mysql.username", "root"), "root"),
			"password" => self::string($config->getNested("mysql.password", ""), ""),
			"table-prefix" => $tablePrefix,
			"timeout-seconds" => self::int($config->getNested("mysql.timeout-seconds", 3), 3),
		];
		$relay = [
			"url" => self::string($config->getNested("relay.url", ""), ""),
			"access-key" => self::string($config->getNested("relay.access-key", ""), ""),
			"timeout-seconds" => self::int($config->getNested("relay.timeout-seconds", 3), 3),
		];
		$redis = [
			"host" => self::string($config->getNested("redis.host", "127.0.0.1"), "127.0.0.1"),
			"port" => self::int($config->getNested("redis.port", 6379), 6379),
			"username" => self::string($config->getNested("redis.username", ""), ""),
			"password" => self::string($config->getNested("redis.password", ""), ""),
			"database" => self::int($config->getNested("redis.database", 0), 0),
			"key-prefix" => self::string($config->getNested("redis.key-prefix", "cspm"), "cspm"),
			"timeout-seconds" => self::int($config->getNested("redis.timeout-seconds", 3), 3),
		];
		$file = [
			"path" => self::string($config->getNested("file.path", "crossserverpm-shared"), "crossserverpm-shared"),
		];

		$messages = [];
		$configMessages = $config->get("messages", []);
		if(is_array($configMessages)){
			foreach($configMessages as $key => $value){
				if(is_string($key) && is_string($value)){
					$messages[$key] = $value;
				}
			}
		}

		return new self(
			$enabled,
			$transport,
			$networkId,
			$networkSecret,
			$networkSecret === "" ? "" : hash("sha256", $networkId . "\0" . $networkSecret),
			$serverId,
			$serverDisplayName,
			$mysql,
			$relay,
			$redis,
			$file,
			$knownServers,
			max(1, self::int($config->getNested("runtime.poll-interval-seconds", 1), 1)),
			max(1, self::int($config->getNested("runtime.heartbeat-interval-seconds", 5), 5)),
			max(5, self::int($config->getNested("runtime.stale-presence-seconds", 15), 15)),
			max(5, self::int($config->getNested("runtime.message-ttl-seconds", 30), 30)),
			max(1, self::int($config->getNested("runtime.max-message-length", 256), 256)),
			max(0, self::int($config->getNested("runtime.command-cooldown-seconds", 1), 1)),
			self::bool($config->getNested("runtime.allow-console-messaging", false)),
			self::string($config->getNested("runtime.console-name", "Console"), "Console"),
			$messages
		);
	}

	public function isReady() : bool{
		if(!$this->enabled || $this->networkId === "" || $this->networkSecret === "" || $this->serverId === ""){
			return false;
		}

		if($this->transport === "mysql"){
			return $this->mysql["host"] !== "" && $this->mysql["database"] !== "" && $this->mysql["username"] !== "";
		}

		if($this->transport === "relay"){
			return $this->relay["url"] !== "";
		}

		if($this->transport === "redis"){
			return $this->redis["host"] !== "";
		}

		if($this->transport === "file"){
			return $this->file["path"] !== "";
		}

		return false;
	}

	public function isMysqlReady() : bool{
		return $this->isReady() && $this->transport === "mysql";
	}

	private static function string(mixed $value, string $default) : string{
		return is_string($value) ? trim($value) : $default;
	}

	private static function int(mixed $value, int $default) : int{
		return is_numeric($value) ? (int) $value : $default;
	}

	private static function bool(mixed $value) : bool{
		if(is_bool($value)){
			return $value;
		}
		return $value === 1 || $value === "1" || $value === "true";
	}

	/**
	 * @return array<string, string>
	 */
	private static function knownServers(mixed $value) : array{
		if(!is_array($value)){
			return [];
		}

		$servers = [];
		foreach($value as $key => $entry){
			$serverId = "";
			$serverName = "";
			if(is_string($key)){
				$serverId = self::string($key, "");
				$serverName = is_string($entry) ? self::string($entry, $serverId) : $serverId;
			}elseif(is_array($entry)){
				$serverId = self::string($entry["id"] ?? "", "");
				$serverName = self::string($entry["display-name"] ?? ($entry["name"] ?? $serverId), $serverId);
			}
			if($serverId !== ""){
				$servers[$serverId] = $serverName === "" ? $serverId : $serverName;
			}
		}
		return $servers;
	}
}
