<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\relay;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function is_array;
use function serialize;
use function unserialize;

final class RelayPollTask extends RelayTask{
	/**
	 * @param array<string, mixed> $relay
	 * @param list<string> $localPlayerKeys
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $relay,
		private readonly string $networkHash,
		private readonly string $serverId,
		array $localPlayerKeys,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($relay);
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
			$this->setResult($this->post("/poll", [
				"network_hash" => $this->networkHash,
				"server_id" => $this->serverId,
				"local_player_keys" => $this->localPlayerKeys(),
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
		$plugin->handlePollResult($this->getResult());
	}
}
