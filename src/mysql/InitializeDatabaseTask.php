<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\mysql;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;

final class InitializeDatabaseTask extends MysqlTask{
	public function __construct(CrossServerPM $plugin, array $mysql){
		parent::__construct($mysql);
		$this->storeLocal("plugin", $plugin);
	}

	public function onRun() : void{
		try{
			$pdo = $this->connect();
			$servers = $this->table("servers");
			$presence = $this->table("presence");
			$messages = $this->table("messages");

			$pdo->exec(
				"CREATE TABLE IF NOT EXISTS " . $servers . " (
					network_hash CHAR(64) NOT NULL,
					server_id VARCHAR(64) NOT NULL,
					server_name VARCHAR(96) NOT NULL,
					online_players INT UNSIGNED NOT NULL,
					updated_at BIGINT UNSIGNED NOT NULL,
					PRIMARY KEY (network_hash, server_id),
					KEY idx_updated (network_hash, updated_at)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			);

			$pdo->exec(
				"CREATE TABLE IF NOT EXISTS " . $presence . " (
					network_hash CHAR(64) NOT NULL,
					player_key VARCHAR(64) NOT NULL,
					player_name VARCHAR(64) NOT NULL,
					server_id VARCHAR(64) NOT NULL,
					server_name VARCHAR(96) NOT NULL,
					updated_at BIGINT UNSIGNED NOT NULL,
					PRIMARY KEY (network_hash, player_key),
					KEY idx_server (network_hash, server_id),
					KEY idx_updated (network_hash, updated_at)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			);

			$pdo->exec(
				"CREATE TABLE IF NOT EXISTS " . $messages . " (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					network_hash CHAR(64) NOT NULL,
					target_server_id VARCHAR(64) NOT NULL,
					recipient_key VARCHAR(64) NOT NULL,
					recipient_name VARCHAR(64) NOT NULL,
					sender_name VARCHAR(64) NOT NULL,
					sender_display VARCHAR(96) NOT NULL,
					sender_server_id VARCHAR(64) NOT NULL,
					sender_server_name VARCHAR(96) NOT NULL,
					body TEXT NOT NULL,
					created_at BIGINT UNSIGNED NOT NULL,
					delivered_at BIGINT UNSIGNED NULL,
					PRIMARY KEY (id),
					KEY idx_delivery (network_hash, target_server_id, delivered_at, created_at),
					KEY idx_recipient (network_hash, recipient_key)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			);

			$this->setResult(["ok" => true]);
		}catch(Throwable $throwable){
			$this->setResult($this->errorResult($throwable));
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleInitializeResult($this->getResult());
	}
}
