<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\file;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function is_array;
use function serialize;
use function unserialize;

final class FileHeartbeatTask extends FileTask{
	/**
	 * @param array<string, mixed> $file
	 * @param list<array{key: string, name: string}> $players
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $file,
		private readonly string $networkHash,
		private readonly string $serverId,
		private readonly string $serverName,
		private readonly int $onlinePlayerCount,
		array $players,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($file);
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
			$serverPath = $this->jsonPath($this->networkHash . "-servers");
			$presencePath = $this->jsonPath($this->networkHash . "-presence");
			$messagePath = $this->jsonPath($this->networkHash . "-messages-" . $this->serverId);

			$this->mutateJson($serverPath, function(array &$servers) : void{
				foreach($servers as $serverId => $row){
					if(!is_array($row) || (int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
						unset($servers[$serverId]);
					}
				}
				$servers[$this->serverId] = [
					"server_id" => $this->serverId,
					"server_name" => $this->serverName,
					"online_players" => $this->onlinePlayerCount,
					"updated_at" => $this->now,
				];
			});

			$this->mutateJson($presencePath, function(array &$presence) : void{
				foreach($presence as $playerKey => $row){
					if(!is_array($row) || ($row["server_id"] ?? "") === $this->serverId || (int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
						unset($presence[$playerKey]);
					}
				}
				foreach($this->players() as $player){
					$presence[$player["key"]] = [
						"player_key" => $player["key"],
						"player_name" => $player["name"],
						"server_id" => $this->serverId,
						"server_name" => $this->serverName,
						"updated_at" => $this->now,
					];
				}
			});

			$this->mutateJson($messagePath, function(array &$messages) : void{
				foreach($messages as $messageId => $message){
					if(!is_array($message) || (int) ($message["created_at"] ?? 0) < $this->now - $this->messageTtlSeconds || ((int) ($message["delivered_at"] ?? 0) > 0 && (int) $message["delivered_at"] < $this->now - $this->messageTtlSeconds)){
						unset($messages[$messageId]);
					}
				}
			});

			$this->setResult(["ok" => true]);
		}catch(Throwable $throwable){
			$this->setResult($this->errorResult($throwable));
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleHeartbeatResult($this->getResult());
	}
}
