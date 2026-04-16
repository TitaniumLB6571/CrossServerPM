<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\service;

use function strtolower;

final class ReplyRegistry{
	/** @var array<string, ReplyTarget> */
	private array $targets = [];

	public function set(string $playerName, string $targetName, ?string $serverId) : void{
		$this->targets[strtolower($playerName)] = new ReplyTarget($targetName, $serverId);
	}

	public function get(string $playerName) : ?ReplyTarget{
		return $this->targets[strtolower($playerName)] ?? null;
	}
}
