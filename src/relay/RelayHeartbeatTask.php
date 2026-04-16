<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\relay;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function is_array;
use function serialize;
use function unserialize;

final class RelayHeartbeatTask extends RelayTask{
	/**
	 * @param array<string, mixed> $relay
	 * @param list<array{key: string, name: string}> $players
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $relay,
		private readonly string $networkHash,
		private readonly string $serverId,
		private readonly string $serverName,
		private readonly int $onlinePlayerCount,
		array $players,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($relay);
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
			$this->setResult($this->post("/heartbeat", [
				"network_hash" => $this->networkHash,
				"server_id" => $this->serverId,
				"server_name" => $this->serverName,
				"online_players" => $this->onlinePlayerCount,
				"players" => $this->players(),
				"now" => $this->now,
				"stale_presence_seconds" => $this->stalePresenceSeconds,
				"message_ttl_seconds" => $this->messageTtlSeconds,
			]));
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
