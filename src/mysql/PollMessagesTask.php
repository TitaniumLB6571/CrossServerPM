<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\mysql;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function array_fill;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function is_array;
use function serialize;
use function unserialize;

final class PollMessagesTask extends MysqlTask{
	/**
	 * @param array<string, mixed> $mysql
	 * @param list<string> $localPlayerKeys
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $mysql,
		private readonly string $networkHash,
		private readonly string $serverId,
		array $localPlayerKeys,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($mysql);
		$this->localPlayerKeys = serialize($localPlayerKeys);
		$this->storeLocal("plugin", $plugin);
	}

	private readonly string $localPlayerKeys;

	/**
	 * @return list<string>
	 */
	private function localPlayerKeys() : array{
		$localPlayerKeys = unserialize($this->localPlayerKeys, ["allowed_classes" => false]);
		return is_array($localPlayerKeys) ? $localPlayerKeys : [];
	}

	public function onRun() : void{
		try{
			$pdo = $this->connect();
			$servers = $this->table("servers");
			$presence = $this->table("presence");
			$messages = $this->table("messages");

			$serverStatement = $pdo->prepare(
				"SELECT server_id, server_name, online_players, updated_at
				FROM " . $servers . "
				WHERE network_hash = ? AND server_id <> ? AND updated_at >= ?
				ORDER BY server_name ASC"
			);
			$serverStatement->execute([$this->networkHash, $this->serverId, $this->now - $this->stalePresenceSeconds]);
			$servers = $serverStatement->fetchAll();

			$presenceStatement = $pdo->prepare(
				"SELECT player_key, player_name, server_id, server_name, updated_at
				FROM " . $presence . "
				WHERE network_hash = ? AND server_id <> ? AND updated_at >= ?
				ORDER BY server_name ASC, player_name ASC"
			);
			$presenceStatement->execute([$this->networkHash, $this->serverId, $this->now - $this->stalePresenceSeconds]);
			$players = $presenceStatement->fetchAll();

			$deliveredMessages = [];
			$localPlayerKeys = $this->localPlayerKeys();
			if($localPlayerKeys !== []){
				$placeholders = implode(", ", array_fill(0, count($localPlayerKeys), "?"));
				$messageStatement = $pdo->prepare(
					"SELECT id, recipient_key, recipient_name, sender_name, sender_display, sender_server_id, sender_server_name, body, created_at
					FROM " . $messages . "
					WHERE network_hash = ?
						AND target_server_id = ?
						AND delivered_at IS NULL
						AND created_at >= ?
						AND recipient_key IN (" . $placeholders . ")
					ORDER BY id ASC
					LIMIT 50"
				);
				$messageStatement->execute(array_values(array_merge([
					$this->networkHash,
					$this->serverId,
					$this->now - $this->messageTtlSeconds,
				], $localPlayerKeys)));
				$deliveredMessages = $messageStatement->fetchAll();
			}

			if($deliveredMessages !== []){
				$ids = [];
				foreach($deliveredMessages as $message){
					$ids[] = (int) $message["id"];
				}
				$placeholders = implode(", ", array_fill(0, count($ids), "?"));
				$update = $pdo->prepare("UPDATE " . $messages . " SET delivered_at = ? WHERE id IN (" . $placeholders . ") AND delivered_at IS NULL");
				$update->execute(array_values(array_merge([$this->now], $ids)));
			}

			$this->setResult([
				"ok" => true,
				"servers" => $servers,
				"players" => $players,
				"messages" => $deliveredMessages,
			]);
		}catch(Throwable $throwable){
			$this->setResult($this->errorResult($throwable));
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handlePollResult($this->getResult());
	}
}
