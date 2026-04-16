<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\redis;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;

final class RedisInitializeTask extends RedisTask{
	/**
	 * @param array<string, mixed> $redis
	 */
	public function __construct(CrossServerPM $plugin, array $redis){
		parent::__construct($redis);
		$this->storeLocal("plugin", $plugin);
	}

	public function onRun() : void{
		$socket = null;
		try{
			$socket = $this->connect();
			$this->command($socket, "PING");
			$this->setResult(["ok" => true]);
		}catch(Throwable $throwable){
			$this->setResult($this->errorResult($throwable));
		}finally{
			$this->close($socket);
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleInitializeResult($this->getResult());
	}
}
