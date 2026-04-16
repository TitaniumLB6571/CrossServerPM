<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\redis;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function array_flip;
use function count;
use function is_array;
use function json_decode;
use function serialize;
use function unserialize;

final class RedisPollMessagesTask extends RedisTask{
	/**
	 * @param array<string, mixed> $redis
	 * @param list<string> $localPlayerKeys
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $redis,
		private readonly string $networkHash,
		private readonly string $serverId,
		array $localPlayerKeys,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($redis);
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
		$socket = null;
		try{
			$socket = $this->connect();
			$serverKey = $this->key($this->networkHash . ":servers");
			$presenceKey = $this->key($this->networkHash . ":presence");
			$messageKey = $this->key($this->networkHash . ":messages:" . $this->serverId);

			$servers = [];
			$deleteServers = [];
			foreach($this->hgetall($socket, $serverKey) as $serverId => $encoded){
				$row = json_decode($encoded, true);
				if(!is_array($row)){
					$deleteServers[] = $serverId;
					continue;
				}
				if((int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
					$deleteServers[] = $serverId;
					continue;
				}
				if(($row["server_id"] ?? "") !== $this->serverId){
					$servers[] = $row;
				}
			}
			$this->hdel($socket, $serverKey, $deleteServers);

			$players = [];
			$deletePresence = [];
			foreach($this->hgetall($socket, $presenceKey) as $playerKey => $encoded){
				$row = json_decode($encoded, true);
				if(!is_array($row)){
					$deletePresence[] = $playerKey;
					continue;
				}
				if((int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
					$deletePresence[] = $playerKey;
					continue;
				}
				if(($row["server_id"] ?? "") !== $this->serverId){
					$players[] = $row;
				}
			}
			$this->hdel($socket, $presenceKey, $deletePresence);

			$localKeys = array_flip($this->localPlayerKeys());
			$messages = [];
			$deleteMessages = [];
			foreach($this->hgetall($socket, $messageKey) as $messageId => $encoded){
				$row = json_decode($encoded, true);
				if(!is_array($row) || (int) ($row["created_at"] ?? 0) < $this->now - $this->messageTtlSeconds){
					$deleteMessages[] = $messageId;
					continue;
				}
				if(isset($localKeys[(string) ($row["recipient_key"] ?? "")])){
					$row["delivered_at"] = $this->now;
					$messages[] = $row;
					$deleteMessages[] = $messageId;
					if(count($messages) >= 50){
						break;
					}
				}
			}
			$this->hdel($socket, $messageKey, $deleteMessages);

			$this->setResult([
				"ok" => true,
				"servers" => $servers,
				"players" => $players,
				"messages" => $messages,
			]);
		}catch(Throwable $throwable){
			$this->setResult($this->errorResult($throwable));
		}finally{
			$this->close($socket);
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handlePollResult($this->getResult());
	}
}
