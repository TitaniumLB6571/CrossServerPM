<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\file;

use pocketmine\scheduler\AsyncTask;
use RuntimeException;
use Throwable;
use function fclose;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_replace;
use function rewind;
use function rtrim;
use function serialize;
use function stream_get_contents;
use function unserialize;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_UN;

abstract class FileTask extends AsyncTask{
	/**
	 * @param array<string, mixed> $file
	 */
	public function __construct(
		array $file
	){
		$this->file = serialize($file);
	}

	private readonly string $file;

	/**
	 * @return array<string, mixed>
	 */
	protected function file() : array{
		$file = unserialize($this->file, ["allowed_classes" => false]);
		return is_array($file) ? $file : [];
	}

	protected function ensureDirectory() : string{
		$file = $this->file();
		$path = rtrim((string) $file["path"], "/\\");
		if($path === ""){
			throw new RuntimeException("file transport path is empty");
		}
		if(!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)){
			throw new RuntimeException("failed to create shared folder: " . $path);
		}
		return $path;
	}

	protected function jsonPath(string $name) : string{
		$safeName = preg_replace('/[^A-Za-z0-9_.-]/', "_", $name) ?? $name;
		return $this->ensureDirectory() . DIRECTORY_SEPARATOR . $safeName . ".json";
	}

	/**
	 * @return mixed
	 */
	protected function mutateJson(string $path, callable $callback) : mixed{
		$handle = fopen($path, "c+");
		if($handle === false){
			throw new RuntimeException("failed to open shared file: " . $path);
		}

		try{
			if(!flock($handle, LOCK_EX)){
				throw new RuntimeException("failed to lock shared file: " . $path);
			}

			$contents = stream_get_contents($handle);
			$data = $contents === false || $contents === "" ? [] : json_decode($contents, true);
			if(!is_array($data)){
				$data = [];
			}

			$result = $callback($data);
			rewind($handle);
			ftruncate($handle, 0);
			fwrite($handle, json_encode($data) ?: "{}");
			flock($handle, LOCK_UN);
			return $result;
		}finally{
			fclose($handle);
		}
	}

	/**
	 * @return array{ok: false, error: string}
	 */
	protected function errorResult(Throwable $throwable) : array{
		return [
			"ok" => false,
			"error" => $throwable->getMessage(),
		];
	}
}
