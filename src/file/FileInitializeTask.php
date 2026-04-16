<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\file;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use Throwable;

final class FileInitializeTask extends FileTask{
	/**
	 * @param array<string, mixed> $file
	 */
	public function __construct(CrossServerPM $plugin, array $file){
		parent::__construct($file);
		$this->storeLocal("plugin", $plugin);
	}

	public function onRun() : void{
		try{
			$this->ensureDirectory();
			$this->setResult(["ok" => true]);
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
