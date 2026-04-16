<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\listener;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;

final class PresenceListener implements Listener{
	public function __construct(
		private readonly CrossServerPM $plugin
	){}

	public function onJoin(PlayerJoinEvent $event) : void{
		$this->plugin->registerLocalPlayer($event->getPlayer());
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		$this->plugin->unregisterLocalPlayer($event->getPlayer());
	}
}
