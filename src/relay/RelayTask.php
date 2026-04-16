<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\relay;

use pocketmine\scheduler\AsyncTask;
use Throwable;
use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;
use function is_array;
use function json_decode;
use function json_encode;
use function rtrim;
use function serialize;
use function str_starts_with;
use function unserialize;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

abstract class RelayTask extends AsyncTask{
	/**
	 * @param array<string, mixed> $relay
	 */
	public function __construct(
		array $relay
	){
		$this->relay = serialize($relay);
	}

	private readonly string $relay;

	/**
	 * @return array<string, mixed>
	 */
	protected function relay() : array{
		$relay = unserialize($this->relay, ["allowed_classes" => false]);
		return is_array($relay) ? $relay : [];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	protected function post(string $path, array $payload) : array{
		return $this->request("POST", $path, $payload);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function get(string $path) : array{
		return $this->request("GET", $path, null);
	}

	/**
	 * @param array<string, mixed>|null $payload
	 * @return array<string, mixed>
	 */
	private function request(string $method, string $path, ?array $payload) : array{
		$relay = $this->relay();
		$url = rtrim((string) $relay["url"], "/") . $path;
		$body = "";
		if($payload !== null){
			$encoded = json_encode($payload);
			if($encoded === false){
				return ["ok" => false, "error" => "failed to encode relay payload"];
			}
			$body = $encoded;
		}

		$curl = curl_init($url);
		if($curl === false){
			return ["ok" => false, "error" => "failed to initialize curl"];
		}

		$headers = [
			"Accept: application/json",
			"User-Agent: CrossServerPM/0.1.0",
		];
		if($payload !== null){
			$headers[] = "Content-Type: application/json";
		}
		$accessKey = (string) ($relay["access-key"] ?? "");
		if($accessKey !== ""){
			$headers[] = "X-CrossServerPM-Relay-Key: " . $accessKey;
		}

		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => (int) $relay["timeout-seconds"],
			CURLOPT_TIMEOUT => (int) $relay["timeout-seconds"],
			CURLOPT_HTTPHEADER => $headers,
		];
		if($method === "POST"){
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = $body;
		}else{
			$options[CURLOPT_CUSTOMREQUEST] = $method;
		}
		curl_setopt_array($curl, $options);

		$response = curl_exec($curl);
		if($response === false){
			$error = curl_error($curl);
			curl_close($curl);
			return ["ok" => false, "error" => $error];
		}

		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if($status < 200 || $status >= 300){
			return ["ok" => false, "error" => "relay returned HTTP " . $status];
		}
		if(!is_string($response) || $response === ""){
			return ["ok" => false, "error" => "relay returned an empty response"];
		}
		if(str_starts_with($response, "\xEF\xBB\xBF")){
			$response = substr($response, 3);
		}

		$decoded = json_decode($response, true);
		return is_array($decoded) ? $decoded : ["ok" => false, "error" => "relay returned invalid JSON"];
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
