<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\mysql;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function is_array;
use function serialize;
use function unserialize;

final class HeartbeatTask extends MysqlTask{
	/**
	 * @param array<string, mixed> $mysql
	 * @param list<array{key: string, name: string}> $players
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $mysql,
		private readonly string $networkHash,
		private readonly string $serverId,
		private readonly string $serverName,
		private readonly int $onlinePlayerCount,
		array $players,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($mysql);
		$this->players = serialize($players);
		$this->storeLocal("plugin", $plugin);
	}

	private readonly string $players;

	/**
	 * @return list<array{key: string, name: string}>
	 */
	private function players() : array{
		$players = unserialize($this->players, ["allowed_classes" => false]);
		return is_array($players) ? $players : [];
	}

	public function onRun() : void{
		try{
			$pdo = $this->connect();
			$servers = $this->table("servers");
			$presence = $this->table("presence");
			$messages = $this->table("messages");

			$pdo->beginTransaction();
			$upsertServer = $pdo->prepare(
				"REPLACE INTO " . $servers . " (network_hash, server_id, server_name, online_players, updated_at)
				VALUES (?, ?, ?, ?, ?)"
			);
			$upsertServer->execute([
				$this->networkHash,
				$this->serverId,
				$this->serverName,
				$this->onlinePlayerCount,
				$this->now,
			]);

			$deleteLocal = $pdo->prepare("DELETE FROM " . $presence . " WHERE network_hash = ? AND server_id = ?");
			$deleteLocal->execute([$this->networkHash, $this->serverId]);

			$insert = $pdo->prepare(
				"REPLACE INTO " . $presence . " (network_hash, player_key, player_name, server_id, server_name, updated_at)
				VALUES (?, ?, ?, ?, ?, ?)"
			);
			foreach($this->players() as $player){
				$insert->execute([
					$this->networkHash,
					$player["key"],
					$player["name"],
					$this->serverId,
					$this->serverName,
					$this->now,
				]);
			}
			$pdo->commit();

			$deleteStalePresence = $pdo->prepare("DELETE FROM " . $presence . " WHERE network_hash = ? AND updated_at < ?");
			$deleteStalePresence->execute([$this->networkHash, $this->now - $this->stalePresenceSeconds]);

			$deleteStaleServers = $pdo->prepare("DELETE FROM " . $servers . " WHERE network_hash = ? AND updated_at < ?");
			$deleteStaleServers->execute([$this->networkHash, $this->now - $this->stalePresenceSeconds]);

			$deleteOldMessages = $pdo->prepare(
				"DELETE FROM " . $messages . " WHERE network_hash = ? AND (created_at < ? OR (delivered_at IS NOT NULL AND delivered_at < ?))"
			);
			$cutoff = $this->now - $this->messageTtlSeconds;
			$deleteOldMessages->execute([$this->networkHash, $cutoff, $cutoff]);

			$this->setResult(["ok" => true]);
		}catch(Throwable $throwable){
			if(isset($pdo) && $pdo->inTransaction()){
				$pdo->rollBack();
			}
			$this->setResult($this->errorResult($throwable));
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleHeartbeatResult($this->getResult());
	}
}
