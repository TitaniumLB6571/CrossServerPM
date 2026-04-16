<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\task;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use pocketmine\scheduler\Task;

final class NetworkTickTask extends Task{
	public function __construct(
		private readonly CrossServerPM $plugin
	){}

	public function onRun() : void{
		$this->plugin->tickNetwork();
	}
}
