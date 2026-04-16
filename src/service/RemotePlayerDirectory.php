<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\service;

use function array_values;
use function count;
use function ksort;
use function strtolower;

final class RemotePlayerDirectory{
	/** @var array<string, RemotePlayer> */
	private array $players = [];

	/** @var array<string, RemoteServer> */
	private array $servers = [];

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param list<array<string, mixed>> $serverRows
	 */
	public function replaceAll(array $rows, array $serverRows = []) : void{
		$this->players = [];
		$this->servers = [];
		foreach($rows as $row){
			$playerKey = (string) ($row["player_key"] ?? "");
			$playerName = (string) ($row["player_name"] ?? "");
			$serverId = (string) ($row["server_id"] ?? "");
			$serverName = (string) ($row["server_name"] ?? "");
			if($playerKey === "" || $playerName === "" || $serverId === ""){
				continue;
			}

			$this->players[$playerKey] = new RemotePlayer(
				$playerKey,
				$playerName,
				$serverId,
				$serverName === "" ? $serverId : $serverName,
				(int) ($row["updated_at"] ?? 0)
			);
		}

		foreach($serverRows as $row){
			$serverId = (string) ($row["server_id"] ?? "");
			$serverName = (string) ($row["server_name"] ?? "");
			if($serverId === ""){
				continue;
			}

			$this->servers[$serverId] = new RemoteServer(
				$serverId,
				$serverName === "" ? $serverId : $serverName,
				(int) ($row["online_players"] ?? 0),
				(int) ($row["updated_at"] ?? 0)
			);
		}

		if($this->servers === []){
			$counts = [];
			$latest = [];
			$names = [];
			foreach($this->players as $player){
				$counts[$player->serverId] = ($counts[$player->serverId] ?? 0) + 1;
				if(!isset($latest[$player->serverId]) || $player->updatedAt > $latest[$player->serverId]){
					$latest[$player->serverId] = $player->updatedAt;
					$names[$player->serverId] = $player->serverName;
				}
			}
			foreach($counts as $serverId => $onlinePlayers){
				$this->servers[$serverId] = new RemoteServer(
					$serverId,
					$names[$serverId] ?? $serverId,
					$onlinePlayers,
					$latest[$serverId] ?? 0
				);
			}
		}
	}

	public function clear() : void{
		$this->players = [];
		$this->servers = [];
	}

	public function find(string $playerName, ?string $serverId) : ?RemotePlayer{
		$key = strtolower($playerName);
		$player = $this->players[$key] ?? null;
		if($player instanceof RemotePlayer && ($serverId === null || $player->serverId === $serverId)){
			return $player;
		}
		foreach($this->players as $player){
			if(strtolower($player->playerName) === $key && ($serverId === null || $player->serverId === $serverId)){
				return $player;
			}
		}
		return null;
	}

	public function count() : int{
		return count($this->players);
	}

	/**
	 * @return array<string, RemoteServer>
	 */
	public function getServers() : array{
		$servers = $this->servers;
		ksort($servers);
		return $servers;
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function getPlayersByServer() : array{
		$result = [];
		foreach($this->players as $player){
			$result[$player->serverName] ??= [];
			$result[$player->serverName][] = $player->playerName;
		}
		foreach($result as &$players){
			$players = array_values($players);
		}
		unset($players);
		ksort($result);
		return $result;
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function getPlayersByServerId() : array{
		$result = [];
		foreach($this->players as $player){
			$result[$player->serverId] ??= [];
			$result[$player->serverId][] = $player->playerName;
		}
		foreach($result as &$players){
			$players = array_values($players);
		}
		unset($players);
		ksort($result);
		return $result;
	}
}
