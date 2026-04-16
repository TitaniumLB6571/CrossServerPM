<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\service;

final class RemotePlayer{
	public function __construct(
		public readonly string $playerKey,
		public readonly string $playerName,
		public readonly string $serverId,
		public readonly string $serverName,
		public readonly int $updatedAt
	){}
}
