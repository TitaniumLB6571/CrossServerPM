<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\relay;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;

final class RelayInitializeTask extends RelayTask{
	/**
	 * @param array<string, mixed> $relay
	 */
	public function __construct(CrossServerPM $plugin, array $relay){
		parent::__construct($relay);
		$this->storeLocal("plugin", $plugin);
	}

	public function onRun() : void{
		try{
			$result = $this->get("/health");
			if(!($result["ok"] ?? false)){
				$result["error"] ??= "relay health check failed";
			}
			$this->setResult($result);
		}catch(Throwable $throwable){
			$this->setResult($this->errorResult($throwable));
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleInitializeResult($this->getResult());
	}
}
