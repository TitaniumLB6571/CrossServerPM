<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\redis;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function is_array;
use function json_decode;
use function json_encode;
use function serialize;
use function unserialize;

final class RedisHeartbeatTask extends RedisTask{
	/**
	 * @param array<string, mixed> $redis
	 * @param list<array{key: string, name: string}> $players
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $redis,
		private readonly string $networkHash,
		private readonly string $serverId,
		private readonly string $serverName,
		private readonly int $onlinePlayerCount,
		array $players,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($redis);
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
		$socket = null;
		try{
			$socket = $this->connect();
			$serverKey = $this->key($this->networkHash . ":servers");
			$presenceKey = $this->key($this->networkHash . ":presence");
			$messageKey = $this->key($this->networkHash . ":messages:" . $this->serverId);

			$deleteServers = [];
			foreach($this->hgetall($socket, $serverKey) as $serverId => $encoded){
				$row = json_decode($encoded, true);
				if(!is_array($row) || (int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
					$deleteServers[] = $serverId;
				}
			}
			$this->hdel($socket, $serverKey, $deleteServers);
			$this->command($socket, "HSET", $serverKey, $this->serverId, json_encode([
				"server_id" => $this->serverId,
				"server_name" => $this->serverName,
				"online_players" => $this->onlinePlayerCount,
				"updated_at" => $this->now,
			]) ?: "{}");

			$deletePresence = [];
			foreach($this->hgetall($socket, $presenceKey) as $playerKey => $encoded){
				$row = json_decode($encoded, true);
				if(!is_array($row)){
					$deletePresence[] = $playerKey;
					continue;
				}
				if(($row["server_id"] ?? "") === $this->serverId || (int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
					$deletePresence[] = $playerKey;
				}
			}
			$this->hdel($socket, $presenceKey, $deletePresence);

			foreach($this->players() as $player){
				$this->command($socket, "HSET", $presenceKey, $player["key"], json_encode([
					"player_key" => $player["key"],
					"player_name" => $player["name"],
					"server_id" => $this->serverId,
					"server_name" => $this->serverName,
					"updated_at" => $this->now,
				]) ?: "{}");
			}

			$deleteMessages = [];
			foreach($this->hgetall($socket, $messageKey) as $messageId => $encoded){
				$row = json_decode($encoded, true);
				if(!is_array($row) || (int) ($row["created_at"] ?? 0) < $this->now - $this->messageTtlSeconds || ((int) ($row["delivered_at"] ?? 0) > 0 && (int) $row["delivered_at"] < $this->now - $this->messageTtlSeconds)){
					$deleteMessages[] = $messageId;
				}
			}
			$this->hdel($socket, $messageKey, $deleteMessages);

			$this->setResult(["ok" => true]);
		}catch(Throwable $throwable){
			$this->setResult($this->errorResult($throwable));
		}finally{
			$this->close($socket);
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleHeartbeatResult($this->getResult());
	}
}
