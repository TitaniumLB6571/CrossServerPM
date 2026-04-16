<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\service;

final class ReplyTarget{
	public function __construct(
		public readonly string $playerName,
		public readonly ?string $serverId
	){}
}
