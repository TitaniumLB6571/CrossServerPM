<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\updater;

use NorixDevelopment\CrossServerPM\CrossServerPM;
use pocketmine\scheduler\AsyncTask;
use Throwable;
use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function function_exists;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function preg_match;
use function rawurlencode;
use function serialize;
use function str_contains;
use function str_starts_with;
use function unserialize;
use function version_compare;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;

final class PoggitUpdateCheckTask extends AsyncTask{
	/**
	 * @param array<string, mixed> $updater
	 */
	public function __construct(CrossServerPM $plugin, array $updater, string $currentVersion, string $pmmpApiVersion){
		$this->settings = serialize([
			"updater" => $updater,
			"current-version" => $currentVersion,
			"pmmp-api-version" => $pmmpApiVersion,
		]);
		$this->storeLocal("plugin", $plugin);
	}

	private readonly string $settings;

	public function onRun() : void{
		try{
			$settings = $this->settings();
			$updater = $settings["updater"];
			$pluginName = (string) ($updater["plugin-name"] ?? "CrossServerPM");
			$currentVersion = (string) ($settings["current-version"] ?? "");
			$pmmpApiVersion = (string) ($settings["pmmp-api-version"] ?? "");
			if($pluginName === ""){
				$this->setResult(["ok" => false, "error" => "updater.plugin-name is empty"]);
				return;
			}
			if($currentVersion === ""){
				$this->setResult(["ok" => false, "error" => "current plugin version is empty"]);
				return;
			}

			$releases = $this->fetchReleases($updater, $pluginName);
			$best = null;
			foreach($releases as $release){
				if(!$this->isUsableRelease($release, (bool) ($updater["include-prereleases"] ?? false), $pmmpApiVersion)){
					continue;
				}
				if($best === null || version_compare((string) $release["version"], (string) $best["version"], ">")){
					$best = $release;
				}
			}

			if(!is_array($best)){
				$this->setResult([
					"ok" => true,
					"update_available" => false,
					"release_found" => false,
					"current_version" => $currentVersion,
					"message" => "No compatible Poggit release was found.",
				]);
				return;
			}

			$latestVersion = (string) ($best["version"] ?? "");
			$this->setResult([
				"ok" => true,
				"update_available" => version_compare($latestVersion, $currentVersion, ">"),
				"release_found" => true,
				"current_version" => $currentVersion,
				"latest_version" => $latestVersion,
				"release_url" => (string) ($best["html_url"] ?? ""),
				"download_url" => (string) ($best["artifact_url"] ?? ""),
				"is_pre_release" => (bool) ($best["is_pre_release"] ?? false),
			]);
		}catch(Throwable $throwable){
			$this->setResult([
				"ok" => false,
				"error" => $throwable->getMessage(),
			]);
		}
	}

	public function onCompletion() : void{
		/** @var CrossServerPM $plugin */
		$plugin = $this->fetchLocal("plugin");
		$plugin->handleUpdateCheckResult($this->getResult());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function settings() : array{
		$settings = unserialize($this->settings, ["allowed_classes" => false]);
		if(!is_array($settings)){
			return ["updater" => []];
		}
		$updater = $settings["updater"] ?? [];
		$settings["updater"] = is_array($updater) ? $updater : [];
		return $settings;
	}

	/**
	 * @param array<string, mixed> $updater
	 * @return list<array<string, mixed>>
	 */
	private function fetchReleases(array $updater, string $pluginName) : array{
		if(!function_exists("curl_init")){
			throw new \RuntimeException("PHP curl extension is not available");
		}

		$apiUrl = (string) ($updater["api-url"] ?? "https://poggit.pmmp.io/releases.json");
		$separator = str_contains($apiUrl, "?") ? "&" : "?";
		$url = $apiUrl . $separator . "name=" . rawurlencode($pluginName);
		$curl = curl_init($url);
		if($curl === false){
			throw new \RuntimeException("failed to initialize curl");
		}

		$timeout = (int) ($updater["timeout-seconds"] ?? 5);
		$verifyTls = (bool) ($updater["verify-tls"] ?? false);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => $verifyTls,
			CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
			CURLOPT_HTTPHEADER => [
				"Accept: application/json",
				"User-Agent: CrossServerPM-Updater/1.0",
			],
		]);

		$response = curl_exec($curl);
		if($response === false){
			$error = curl_error($curl);
			curl_close($curl);
			throw new \RuntimeException($error);
		}

		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if($status < 200 || $status >= 300){
			throw new \RuntimeException("Poggit returned HTTP " . $status);
		}
		if(!is_string($response) || $response === ""){
			throw new \RuntimeException("Poggit returned an empty response");
		}
		if(str_starts_with($response, "\xEF\xBB\xBF")){
			$response = substr($response, 3);
		}

		$decoded = json_decode($response, true);
		if(!is_array($decoded)){
			throw new \RuntimeException("Poggit returned invalid JSON");
		}

		$releases = [];
		foreach($decoded as $release){
			if(is_array($release)){
				$releases[] = $release;
			}
		}
		return $releases;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function isUsableRelease(array $release, bool $includePrereleases, string $pmmpApiVersion) : bool{
		$version = $release["version"] ?? null;
		if(!is_string($version) || $version === ""){
			return false;
		}
		if(($release["state_name"] ?? "Approved") !== "Approved"){
			return false;
		}
		if($this->bool($release["is_obsolete"] ?? false) || $this->bool($release["is_outdated"] ?? false) || $this->bool($release["is_abandoned"] ?? false)){
			return false;
		}
		if(!$includePrereleases && $this->bool($release["is_pre_release"] ?? false)){
			return false;
		}
		return $this->isApiCompatible($release["api"] ?? [], $pmmpApiVersion);
	}

	private function bool(mixed $value) : bool{
		if(is_bool($value)){
			return $value;
		}
		return $value === 1 || $value === "1" || $value === "true";
	}

	private function isApiCompatible(mixed $apiRanges, string $pmmpApiVersion) : bool{
		if($pmmpApiVersion === "" || !is_array($apiRanges) || $apiRanges === []){
			return true;
		}
		$currentMajor = $this->apiMajor($pmmpApiVersion);
		foreach($apiRanges as $range){
			if(!is_array($range)){
				continue;
			}
			$from = (string) ($range["from"] ?? "");
			$to = (string) ($range["to"] ?? "");
			$fromMajor = $this->apiMajor($from);
			$toMajor = $this->apiMajor($to);
			if($currentMajor !== null && $fromMajor !== null && $toMajor !== null && $currentMajor >= $fromMajor && $currentMajor <= $toMajor){
				return true;
			}
			if(($from === "" || version_compare($pmmpApiVersion, $from, ">=")) && ($to === "" || version_compare($pmmpApiVersion, $to, "<="))){
				return true;
			}
		}
		return false;
	}

	private function apiMajor(string $version) : ?int{
		if(preg_match('/^(\d+)/', $version, $matches) !== 1){
			return null;
		}
		return (int) $matches[1];
	}
}
