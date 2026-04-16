<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\redis;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function bin2hex;
use function json_encode;
use function random_bytes;

final class RedisSendMessageTask extends RedisTask{
	/**
	 * @param array<string, mixed> $redis
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $redis,
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
		parent::__construct($redis);
		$this->storeLocal("plugin", $plugin);
	}

	public function onRun() : void{
		$socket = null;
		try{
			$socket = $this->connect();
			$messageId = $this->senderServerId . ":" . $this->now . ":" . bin2hex(random_bytes(8));
			$this->command($socket, "HSET", $this->key($this->networkHash . ":messages:" . $this->targetServerId), $messageId, json_encode([
				"id" => $messageId,
				"network_hash" => $this->networkHash,
				"target_server_id" => $this->targetServerId,
				"recipient_key" => $this->targetKey,
				"recipient_name" => $this->targetName,
				"sender_name" => $this->senderName,
				"sender_display" => $this->senderDisplay,
				"sender_server_id" => $this->senderServerId,
				"sender_server_name" => $this->senderServerName,
				"body" => $this->body,
				"created_at" => $this->now,
				"delivered_at" => null,
			]) ?: "{}");

			$this->setResult([
				"ok" => true,
				"sender" => $this->senderName,
				"target" => $this->targetName,
				"target_server_id" => $this->targetServerId,
				"target_server_name" => $this->targetServerName,
				"body" => $this->body,
			]);
		}catch(Throwable $throwable){
			$result = $this->errorResult($throwable);
			$result["sender"] = $this->senderName;
			$this->setResult($result);
		}finally{
			$this->close($socket);
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleSendResult($this->getResult());
	}
}
