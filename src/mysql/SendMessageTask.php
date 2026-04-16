<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\mysql;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;

final class SendMessageTask extends MysqlTask{
	/**
	 * @param array<string, mixed> $mysql
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $mysql,
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
		parent::__construct($mysql);
		$this->storeLocal("plugin", $plugin);
	}

	public function onRun() : void{
		try{
			$pdo = $this->connect();
			$messages = $this->table("messages");
			$statement = $pdo->prepare(
				"INSERT INTO " . $messages . " (
					network_hash,
					target_server_id,
					recipient_key,
					recipient_name,
					sender_name,
					sender_display,
					sender_server_id,
					sender_server_name,
					body,
					created_at,
					delivered_at
				) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)"
			);
			$statement->execute([
				$this->networkHash,
				$this->targetServerId,
				$this->targetKey,
				$this->targetName,
				$this->senderName,
				$this->senderDisplay,
				$this->senderServerId,
				$this->senderServerName,
				$this->body,
				$this->now,
			]);

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
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleSendResult($this->getResult());
	}
}
