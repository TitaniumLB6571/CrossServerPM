<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\util;

use function bin2hex;
use function random_bytes;

final class RandomSecret{
	public static function generate() : string{
		return bin2hex(random_bytes(32));
	}
}
