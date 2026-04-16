<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\relay;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;

final class RelaySendMessageTask extends RelayTask{
	/**
	 * @param array<string, mixed> $relay
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $relay,
		private readonly string $networkHash,
		private readonly string $senderName,
		private readonly string $senderDisplay,
		private readonly string $senderServerId,
		private readonly string $senderServerName,
		private readonly string $targetKey,
		private readonly string $targetName,
		private readonly string $targetServerId,
		private readonly string $targetServerName,
		private readonly string $body,
		private readonly int $now
	){
		parent::__construct($relay);
		$this->storeLocal("plugin", $plugin);
	}

	public function onRun() : void{
		try{
			$result = $this->post("/send", [
				"network_hash" => $this->networkHash,
				"sender_name" => $this->senderName,
				"sender_display" => $this->senderDisplay,
				"sender_server_id" => $this->senderServerId,
				"sender_server_name" => $this->senderServerName,
				"target_key" => $this->targetKey,
				"target_name" => $this->targetName,
				"target_server_id" => $this->targetServerId,
				"target_server_name" => $this->targetServerName,
				"body" => $this->body,
				"now" => $this->now,
			]);
			if($result["ok"] ?? false){
				$result += [
					"sender" => $this->senderName,
					"target" => $this->targetName,
					"target_server_id" => $this->targetServerId,
					"target_server_name" => $this->targetServerName,
					"body" => $this->body,
				];
			}else{
				$result["sender"] = $this->senderName;
			}
			$this->setResult($result);
		}catch(Throwable $throwable){
			$result = $this->errorResult($throwable);
			$result["sender"] = $this->senderName;
			$this->setResult($result);
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleSendResult($this->getResult());
	}
}
