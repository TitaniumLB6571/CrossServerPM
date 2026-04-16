<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\file;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;
use function array_flip;
use function array_values;
use function count;
use function is_array;
use function serialize;
use function unserialize;

final class FilePollMessagesTask extends FileTask{
	/**
	 * @param array<string, mixed> $file
	 * @param list<string> $localPlayerKeys
	 */
	public function __construct(
		CrossServerPM $plugin,
		array $file,
		private readonly string $networkHash,
		private readonly string $serverId,
		array $localPlayerKeys,
		private readonly int $now,
		private readonly int $stalePresenceSeconds,
		private readonly int $messageTtlSeconds
	){
		parent::__construct($file);
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
			$serverPath = $this->jsonPath($this->networkHash . "-servers");
			$presencePath = $this->jsonPath($this->networkHash . "-presence");
			$messagePath = $this->jsonPath($this->networkHash . "-messages-" . $this->serverId);

			$servers = $this->mutateJson($serverPath, function(array &$storedServers) : array{
				$servers = [];
				foreach($storedServers as $serverId => $row){
					if(!is_array($row) || (int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
						unset($storedServers[$serverId]);
						continue;
					}
					if(($row["server_id"] ?? "") !== $this->serverId){
						$servers[] = $row;
					}
				}
				return $servers;
			});

			$players = $this->mutateJson($presencePath, function(array &$presence) : array{
				$players = [];
				foreach($presence as $playerKey => $row){
					if(!is_array($row) || (int) ($row["updated_at"] ?? 0) < $this->now - $this->stalePresenceSeconds){
						unset($presence[$playerKey]);
						continue;
					}
					if(($row["server_id"] ?? "") !== $this->serverId){
						$players[] = $row;
					}
				}
				return $players;
			});

			$localKeys = array_flip($this->localPlayerKeys());
			$messages = $this->mutateJson($messagePath, function(array &$storedMessages) use ($localKeys) : array{
				$messages = [];
				foreach($storedMessages as $messageId => $message){
					if(!is_array($message) || (int) ($message["created_at"] ?? 0) < $this->now - $this->messageTtlSeconds){
						unset($storedMessages[$messageId]);
						continue;
					}
					if(isset($localKeys[(string) ($message["recipient_key"] ?? "")])){
						$message["delivered_at"] = $this->now;
						$messages[] = $message;
						unset($storedMessages[$messageId]);
						if(count($messages) >= 50){
							break;
						}
					}
				}
				return $messages;
			});

			$this->setResult([
				"ok" => true,
				"servers" => array_values($servers),
				"players" => array_values($players),
				"messages" => array_values($messages),
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
