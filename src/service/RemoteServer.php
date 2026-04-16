<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\service;

final class RemoteServer{
	public function __construct(
		public readonly string $serverId,
		public readonly string $serverName,
		public readonly int $onlinePlayers,
		public readonly int $updatedAt
	){}
}
