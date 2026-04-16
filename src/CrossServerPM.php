<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM;

use NorixDevelopment\CrossServerPM\file\FileHeartbeatTask;
use NorixDevelopment\CrossServerPM\file\FileInitializeTask;
use NorixDevelopment\CrossServerPM\file\FilePollMessagesTask;
use NorixDevelopment\CrossServerPM\file\FileSendMessageTask;
use NorixDevelopment\CrossServerPM\listener\PresenceListener;
use NorixDevelopment\CrossServerPM\mysql\HeartbeatTask;
use NorixDevelopment\CrossServerPM\mysql\InitializeDatabaseTask;
use NorixDevelopment\CrossServerPM\mysql\PollMessagesTask;
use NorixDevelopment\CrossServerPM\mysql\SendMessageTask;
use NorixDevelopment\CrossServerPM\redis\RedisHeartbeatTask;
use NorixDevelopment\CrossServerPM\redis\RedisInitializeTask;
use NorixDevelopment\CrossServerPM\redis\RedisPollMessagesTask;
use NorixDevelopment\CrossServerPM\redis\RedisSendMessageTask;
use NorixDevelopment\CrossServerPM\relay\RelayHeartbeatTask;
use NorixDevelopment\CrossServerPM\relay\RelayInitializeTask;
use NorixDevelopment\CrossServerPM\relay\RelayPollTask;
use NorixDevelopment\CrossServerPM\relay\RelaySendMessageTask;
use NorixDevelopment\CrossServerPM\service\MessageFormatter;
use NorixDevelopment\CrossServerPM\service\RemotePlayer;
use NorixDevelopment\CrossServerPM\service\RemotePlayerDirectory;
use NorixDevelopment\CrossServerPM\service\RemoteServer;
use NorixDevelopment\CrossServerPM\service\ReplyRegistry;
use NorixDevelopment\CrossServerPM\task\NetworkTickTask;
use NorixDevelopment\CrossServerPM\updater\PoggitUpdateCheckTask;
use NorixDevelopment\CrossServerPM\util\RandomSecret;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\TextFormat;
use function array_keys;
use function array_map;
use function array_shift;
use function count;
use function in_array;
use function implode;
use function is_array;
use function ksort;
use function microtime;
use function strlen;
use function strtolower;
use function time;
use function trim;

final class CrossServerPM extends PluginBase{
	private PluginSettings $settings;
	private MessageFormatter $formatter;
	private RemotePlayerDirectory $directory;
	private ReplyRegistry $replies;
	private ?TaskHandler $networkTask = null;
	private bool $transportReady = false;
	private bool $initializing = false;
	private bool $heartbeatRunning = false;
	private bool $pollRunning = false;
	private bool $presenceDirty = true;
	private int $nextHeartbeatAt = 0;
	private int $nextPollAt = 0;
	private bool $updateCheckRunning = false;
	private int $nextUpdateCheckAt = 0;

	/** @var array<string, float> */
	private array $lastMessageAt = [];

	/** @var array<string, Player> */
	private array $localPlayers = [];

	/** @var array<string, bool> */
	private array $pendingUpdateCheckRequesters = [];

	/** @var array<string, mixed>|null */
	private ?array $latestUpdate = null;

	/** @var array<string, bool> */
	private array $notifiedUpdatePlayers = [];

	protected function onEnable() : void{
		$this->saveDefaultConfig();
		$this->directory = new RemotePlayerDirectory();
		$this->replies = new ReplyRegistry();
		$this->loadSettings();
		$this->rebuildLocalPlayerCache();

		$this->getServer()->getPluginManager()->registerEvents(new PresenceListener($this), $this);
		$this->networkTask = $this->getScheduler()->scheduleRepeatingTask(new NetworkTickTask($this), 20);
		$this->initializeTransport();
		$this->submitUpdateCheck(false);
	}

	protected function onDisable() : void{
		if($this->networkTask instanceof TaskHandler){
			$this->networkTask->cancel();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		return match($command->getName()){
			"msg" => $this->handleMessageCommand($sender, $args),
			"reply" => $this->handleReplyCommand($sender, $args),
			"xmsg" => $this->handleAdminCommand($sender, $args),
			default => false,
		};
	}

	public function tickNetwork() : void{
		if(!$this->settings->isReady() || !$this->transportReady){
			return;
		}

		$now = time();
		if(!$this->heartbeatRunning && ($this->presenceDirty || $now >= $this->nextHeartbeatAt)){
			$this->submitHeartbeat($now);
		}
		if(!$this->pollRunning && $now >= $this->nextPollAt){
			$this->submitPoll($now);
		}
	}

	public function markPresenceDirty() : void{
		$this->presenceDirty = true;
	}

	public function registerLocalPlayer(Player $player) : void{
		$this->localPlayers[strtolower($player->getName())] = $player;
		$this->markPresenceDirty();
		$this->notifyPlayerAboutStoredUpdate($player);
	}

	public function unregisterLocalPlayer(Player $player) : void{
		unset($this->localPlayers[strtolower($player->getName())]);
		$this->markPresenceDirty();
	}

	public function handleInitializeResult(array $result) : void{
		$this->initializing = false;
		$this->transportReady = (bool) ($result["ok"] ?? false);
		$transportName = match($this->settings->transport){
			"relay" => "Relay",
			"redis" => "Redis",
			"file" => "File",
			default => "MySQL",
		};
		if(!$this->transportReady){
			$this->getLogger()->warning($transportName . " transport failed to initialize: " . (string) ($result["error"] ?? "unknown error"));
			return;
		}

		$this->presenceDirty = true;
		$this->getLogger()->info($transportName . " transport is ready.");
	}

	public function handleHeartbeatResult(array $result) : void{
		$this->heartbeatRunning = false;
		$this->nextHeartbeatAt = time() + $this->settings->heartbeatIntervalSeconds;
		if(!($result["ok"] ?? false)){
			$this->getLogger()->warning("Presence heartbeat failed: " . (string) ($result["error"] ?? "unknown error"));
		}
	}

	public function handlePollResult(array $result) : void{
		$this->pollRunning = false;
		$this->nextPollAt = time() + $this->settings->pollIntervalSeconds;
		if(!($result["ok"] ?? false)){
			$this->getLogger()->warning("Message poll failed: " . (string) ($result["error"] ?? "unknown error"));
			return;
		}

		$players = $result["players"] ?? [];
		$servers = $result["servers"] ?? [];
		$this->directory->replaceAll(is_array($players) ? $players : [], is_array($servers) ? $servers : []);
		foreach($result["messages"] ?? [] as $message){
			if(!is_array($message)){
				continue;
			}
			$recipientKey = (string) ($message["recipient_key"] ?? "");
			$senderName = (string) ($message["sender_name"] ?? "");
			$senderServerId = (string) ($message["sender_server_id"] ?? "");
			$senderServerName = (string) ($message["sender_server_name"] ?? "");
			$body = (string) ($message["body"] ?? "");
			if($this->settings->allowConsoleMessaging && $recipientKey === $this->consoleKey()){
				$this->getLogger()->info("[PM from " . $senderName . "@" . $senderServerName . "] " . $body);
				$this->replies->set($this->settings->consoleName, $senderName, $senderServerId);
				continue;
			}
			$recipient = $this->findLocalPlayerByKey($recipientKey);
			if(!$recipient instanceof Player){
				continue;
			}

			$recipient->sendMessage($this->formatter->incomingRemote($senderName, $senderServerName, $body));
			$this->replies->set($recipient->getName(), $senderName, $senderServerId);
		}
	}

	public function handleSendResult(array $result) : void{
		$senderName = (string) ($result["sender"] ?? "");
		$isConsoleSender = $this->settings->allowConsoleMessaging && $this->isConsoleName($senderName);
		$sender = $isConsoleSender ? null : $this->findLocalPlayer($senderName);
		if(!$isConsoleSender && !$sender instanceof Player){
			return;
		}

		if(!($result["ok"] ?? false)){
			$reason = (string) ($result["error"] ?? "unknown error");
			if($isConsoleSender){
				$this->getLogger()->warning("Console message failed: " . $reason);
			}else{
				$sender->sendMessage($this->formatter->message("send-failed", ["reason" => $reason]));
			}
			return;
		}

		$target = (string) ($result["target"] ?? "");
		$targetServerId = (string) ($result["target_server_id"] ?? "");
		$targetServerName = (string) ($result["target_server_name"] ?? "");
		$body = (string) ($result["body"] ?? "");
		if($isConsoleSender){
			$this->getLogger()->info("[PM to " . $target . "@" . $targetServerName . "] " . $body);
			$this->replies->set($this->settings->consoleName, $target, $targetServerId);
		}else{
			$sender->sendMessage($this->formatter->outgoingRemote($target, $targetServerName, $body));
			$this->replies->set($sender->getName(), $target, $targetServerId);
		}
	}

	public function tickUpdater() : void{
		if(!($this->settings->updater["enabled"] ?? false) || $this->updateCheckRunning){
			return;
		}
		if(time() >= $this->nextUpdateCheckAt){
			$this->submitUpdateCheck(false);
		}
	}

	public function handleUpdateCheckResult(array $result) : void{
		$this->updateCheckRunning = false;
		$this->nextUpdateCheckAt = time() + ((int) ($this->settings->updater["check-interval-hours"] ?? 12) * 3600);

		$requesters = array_keys($this->pendingUpdateCheckRequesters);
		$this->pendingUpdateCheckRequesters = [];

		if(!($result["ok"] ?? false)){
			$this->latestUpdate = null;
			$message = $this->formatter->message("update-check-failed", ["reason" => (string) ($result["error"] ?? "unknown error")]);
			foreach($requesters as $requester){
				$this->sendUpdateRequesterMessage($requester, $message);
			}
			if($requesters === [] && ($this->settings->updater["notify-console"] ?? true)){
				$this->getLogger()->warning(TextFormat::clean($message));
			}
			return;
		}

		if($result["update_available"] ?? false){
			$this->latestUpdate = $result;
			$this->notifiedUpdatePlayers = [];
			$message = $this->updateAvailableMessage($result);
			foreach($requesters as $requester){
				$this->sendUpdateRequesterMessage($requester, $message);
			}
			if($requesters === []){
				$this->notifyUpdateAvailable($result);
			}
			return;
		}

		$this->latestUpdate = null;
		if(!($result["release_found"] ?? true)){
			$message = $this->formatter->message("update-no-release", [
				"reason" => (string) ($result["message"] ?? "No compatible Poggit release was found."),
			]);
			foreach($requesters as $requester){
				$this->sendUpdateRequesterMessage($requester, $message);
			}
			if($requesters === [] && ($this->settings->updater["notify-console"] ?? true)){
				$this->getLogger()->info(TextFormat::clean($message));
			}
			return;
		}

		$message = $this->formatter->message("update-none", [
			"version" => (string) ($result["current_version"] ?? $this->currentPluginVersion()),
		]);
		foreach($requesters as $requester){
			$this->sendUpdateRequesterMessage($requester, $message);
		}
	}

	private function handleMessageCommand(CommandSender $sender, array $args) : bool{
		if(!$this->canUseMessaging($sender)){
			return true;
		}
		if(count($args) < 2){
			$sender->sendMessage($this->formatter->message("usage-msg"));
			return true;
		}

		$target = (string) array_shift($args);
		$message = trim(implode(" ", array_map("strval", $args)));
		$this->sendPrivateMessage($sender, $target, $message, null);
		return true;
	}

	private function handleReplyCommand(CommandSender $sender, array $args) : bool{
		if(!$this->canUseMessaging($sender)){
			return true;
		}
		if($args === []){
			$sender->sendMessage($this->formatter->message("usage-reply"));
			return true;
		}

		$replyTarget = $this->replies->get($this->senderName($sender));
		if($replyTarget === null){
			$sender->sendMessage($this->formatter->message("no-reply-target"));
			return true;
		}

		$message = trim(implode(" ", array_map("strval", $args)));
		$this->sendPrivateMessage($sender, $replyTarget->playerName, $message, $replyTarget->serverId);
		return true;
	}

	private function handleAdminCommand(CommandSender $sender, array $args) : bool{
		$subcommand = strtolower((string) ($args[0] ?? "help"));
		array_shift($args);

		match($subcommand){
			"status" => $this->sendStatus($sender),
			"servers" => $this->sendServers($sender),
			"update" => $this->handleUpdateCommand($sender),
			"reload" => $this->handleReload($sender),
			"key" => $this->handleKeyCommand($sender, $args),
			default => $this->sendAdminHelp($sender),
		};

		return true;
	}

	private function sendPrivateMessage(CommandSender $sender, string $targetName, string $message, ?string $targetServerId) : void{
		if($message === ""){
			$sender->sendMessage($this->formatter->message("usage-msg"));
			return;
		}
		if($this->settings->maxMessageLength > 0 && strlen($message) > $this->settings->maxMessageLength){
			$sender->sendMessage($this->formatter->message("message-too-long", ["max" => $this->settings->maxMessageLength]));
			return;
		}
		if(!$this->checkCooldown($sender)){
			$sender->sendMessage($this->formatter->message("cooldown"));
			return;
		}

		$senderName = $this->senderName($sender);
		if($this->settings->allowConsoleMessaging && $this->isConsoleName($targetName) && ($targetServerId === null || $targetServerId === $this->settings->serverId)){
			if($this->isConsoleName($senderName)){
				$sender->sendMessage($this->formatter->message("target-not-found"));
				return;
			}
			$this->getLogger()->info("[PM from " . $senderName . "] " . $message);
			$sender->sendMessage($this->formatter->outgoingLocal($this->settings->consoleName, $message));
			$this->replies->set($this->settings->consoleName, $senderName, null);
			$this->replies->set($senderName, $this->settings->consoleName, null);
			return;
		}

		$localTarget = $targetServerId === null ? $this->findLocalPlayer($targetName) : null;
		if($localTarget instanceof Player){
			if($sender instanceof Player && strtolower($localTarget->getName()) === strtolower($sender->getName())){
				$sender->sendMessage($this->formatter->message("target-not-found"));
				return;
			}
			$localTarget->sendMessage($this->formatter->incomingLocal($senderName, $message));
			$sender->sendMessage($this->formatter->outgoingLocal($localTarget->getName(), $message));
			$this->replies->set($localTarget->getName(), $senderName, null);
			$this->replies->set($senderName, $localTarget->getName(), null);
			return;
		}

		if(!$this->settings->isReady() || !$this->transportReady){
			$sender->sendMessage($this->formatter->message("not-configured"));
			return;
		}

		$remoteTarget = $this->directory->find($targetName, $targetServerId);
		if(!$remoteTarget instanceof RemotePlayer){
			$sender->sendMessage($this->formatter->message("target-not-found"));
			return;
		}

		$this->submitSendMessage($sender, $remoteTarget, $message);
	}

	private function checkCooldown(CommandSender $sender) : bool{
		$cooldown = $this->settings->commandCooldownSeconds;
		if($cooldown <= 0){
			return true;
		}

		$key = strtolower($this->senderName($sender));
		$now = microtime(true);
		$last = $this->lastMessageAt[$key] ?? 0.0;
		if($now - $last < $cooldown){
			return false;
		}

		$this->lastMessageAt[$key] = $now;
		return true;
	}

	private function submitSendMessage(CommandSender $sender, RemotePlayer $target, string $message) : void{
		$senderName = $this->senderName($sender);
		$senderDisplay = $this->senderDisplayName($sender);
		if($this->settings->transport === "relay"){
			$this->getServer()->getAsyncPool()->submitTask(new RelaySendMessageTask(
				$this,
				$this->settings->relay,
				$this->settings->networkHash,
				$senderName,
				$senderDisplay,
				$this->settings->serverId,
				$this->settings->serverDisplayName,
				$target->playerKey,
				$target->playerName,
				$target->serverId,
				$target->serverName,
				$message,
				time()
			));
			return;
		}
		if($this->settings->transport === "redis"){
			$this->getServer()->getAsyncPool()->submitTask(new RedisSendMessageTask(
				$this,
				$this->settings->redis,
				$this->settings->networkHash,
				$senderName,
				$senderDisplay,
				$this->settings->serverId,
				$this->settings->serverDisplayName,
				$target->playerKey,
				$target->playerName,
				$target->serverId,
				$target->serverName,
				$message,
				time()
			));
			return;
		}
		if($this->settings->transport === "file"){
			$this->getServer()->getAsyncPool()->submitTask(new FileSendMessageTask(
				$this,
				$this->settings->file,
				$this->settings->networkHash,
				$senderName,
				$senderDisplay,
				$this->settings->serverId,
				$this->settings->serverDisplayName,
				$target->playerKey,
				$target->playerName,
				$target->serverId,
				$target->serverName,
				$message,
				time()
			));
			return;
		}

		$this->getServer()->getAsyncPool()->submitTask(new SendMessageTask(
			$this,
			$this->settings->mysql,
			$this->settings->networkHash,
			$senderName,
			$senderDisplay,
			$this->settings->serverId,
			$this->settings->serverDisplayName,
			$target->playerKey,
			$target->playerName,
			$target->serverId,
			$target->serverName,
			$message,
			time()
		));
	}

	private function submitHeartbeat(int $now) : void{
		$this->heartbeatRunning = true;
		$this->presenceDirty = false;

		$players = [];
		foreach($this->localPlayers as $player){
			$players[] = [
				"key" => strtolower($player->getName()),
				"name" => $player->getName(),
			];
		}
		if($this->settings->allowConsoleMessaging){
			$players[] = [
				"key" => $this->consoleKey(),
				"name" => $this->settings->consoleName,
			];
		}

		if($this->settings->transport === "relay"){
			$this->getServer()->getAsyncPool()->submitTask(new RelayHeartbeatTask(
				$this,
				$this->settings->relay,
				$this->settings->networkHash,
				$this->settings->serverId,
				$this->settings->serverDisplayName,
				count($this->localPlayers),
				$players,
				$now,
				$this->settings->stalePresenceSeconds,
				$this->settings->messageTtlSeconds
			));
			return;
		}
		if($this->settings->transport === "redis"){
			$this->getServer()->getAsyncPool()->submitTask(new RedisHeartbeatTask(
				$this,
				$this->settings->redis,
				$this->settings->networkHash,
				$this->settings->serverId,
				$this->settings->serverDisplayName,
				count($this->localPlayers),
				$players,
				$now,
				$this->settings->stalePresenceSeconds,
				$this->settings->messageTtlSeconds
			));
			return;
		}
		if($this->settings->transport === "file"){
			$this->getServer()->getAsyncPool()->submitTask(new FileHeartbeatTask(
				$this,
				$this->settings->file,
				$this->settings->networkHash,
				$this->settings->serverId,
				$this->settings->serverDisplayName,
				count($this->localPlayers),
				$players,
				$now,
				$this->settings->stalePresenceSeconds,
				$this->settings->messageTtlSeconds
			));
			return;
		}

		$this->getServer()->getAsyncPool()->submitTask(new HeartbeatTask(
			$this,
			$this->settings->mysql,
			$this->settings->networkHash,
			$this->settings->serverId,
			$this->settings->serverDisplayName,
			count($this->localPlayers),
			$players,
			$now,
			$this->settings->stalePresenceSeconds,
			$this->settings->messageTtlSeconds
		));
	}

	private function submitPoll(int $now) : void{
		$this->pollRunning = true;

		$localPlayerKeys = array_keys($this->localPlayers);
		if($this->settings->allowConsoleMessaging){
			$localPlayerKeys[] = $this->consoleKey();
		}

		if($this->settings->transport === "relay"){
			$this->getServer()->getAsyncPool()->submitTask(new RelayPollTask(
				$this,
				$this->settings->relay,
				$this->settings->networkHash,
				$this->settings->serverId,
				$localPlayerKeys,
				$now,
				$this->settings->stalePresenceSeconds,
				$this->settings->messageTtlSeconds
			));
			return;
		}
		if($this->settings->transport === "redis"){
			$this->getServer()->getAsyncPool()->submitTask(new RedisPollMessagesTask(
				$this,
				$this->settings->redis,
				$this->settings->networkHash,
				$this->settings->serverId,
				$localPlayerKeys,
				$now,
				$this->settings->stalePresenceSeconds,
				$this->settings->messageTtlSeconds
			));
			return;
		}
		if($this->settings->transport === "file"){
			$this->getServer()->getAsyncPool()->submitTask(new FilePollMessagesTask(
				$this,
				$this->settings->file,
				$this->settings->networkHash,
				$this->settings->serverId,
				$localPlayerKeys,
				$now,
				$this->settings->stalePresenceSeconds,
				$this->settings->messageTtlSeconds
			));
			return;
		}

		$this->getServer()->getAsyncPool()->submitTask(new PollMessagesTask(
			$this,
			$this->settings->mysql,
			$this->settings->networkHash,
			$this->settings->serverId,
			$localPlayerKeys,
			$now,
			$this->settings->stalePresenceSeconds,
			$this->settings->messageTtlSeconds
		));
	}

	private function initializeTransport() : void{
		$this->transportReady = false;
		if(!$this->settings->enabled){
			$this->getLogger()->info("Plugin is disabled in config. Set enabled: true after configuring a transport.");
			return;
		}
		if(!$this->settings->isReady()){
			$hint = match($this->settings->transport){
				"relay" => "network.secret and relay.url",
				"redis" => "network.secret and Redis settings",
				"file" => "network.secret and file.path",
				default => "network.secret and MySQL settings",
			};
			$this->getLogger()->warning("Cross-server messaging is not ready. Configure " . $hint . ".");
			return;
		}
		if(!in_array($this->settings->transport, ["mysql", "relay", "redis", "file"], true)){
			$this->getLogger()->warning("Unsupported transport: " . $this->settings->transport);
			return;
		}
		if($this->initializing){
			return;
		}

		$this->initializing = true;
		if($this->settings->transport === "relay"){
			$this->getServer()->getAsyncPool()->submitTask(new RelayInitializeTask($this, $this->settings->relay));
			return;
		}
		if($this->settings->transport === "redis"){
			$this->getServer()->getAsyncPool()->submitTask(new RedisInitializeTask($this, $this->settings->redis));
			return;
		}
		if($this->settings->transport === "file"){
			$this->getServer()->getAsyncPool()->submitTask(new FileInitializeTask($this, $this->settings->file));
			return;
		}

		$this->getServer()->getAsyncPool()->submitTask(new InitializeDatabaseTask($this, $this->settings->mysql));
	}

	private function loadSettings() : void{
		$this->settings = PluginSettings::fromConfig($this->getConfig());
		$this->formatter = new MessageFormatter($this->settings->messages);
	}

	private function handleReload(CommandSender $sender) : void{
		$this->reloadConfig();
		$this->loadSettings();
		$this->directory->clear();
		$this->presenceDirty = true;
		$this->initializeTransport();
		$sender->sendMessage($this->formatter->message("reload-complete"));
	}

	private function handleUpdateCommand(CommandSender $sender) : void{
		$requester = $this->updateRequesterKey($sender);
		$this->pendingUpdateCheckRequesters[$requester] = true;
		$sender->sendMessage($this->formatter->message("update-check-started"));
		$this->submitUpdateCheck(true);
	}

	private function handleKeyCommand(CommandSender $sender, array $args) : void{
		if(strtolower((string) ($args[0] ?? "")) !== "generate"){
			$sender->sendMessage(TextFormat::YELLOW . "Usage: /xmsg key generate");
			return;
		}

		$secret = RandomSecret::generate();
		$this->getConfig()->setNested("network.secret", $secret);
		$this->getConfig()->save();
		$this->reloadConfig();
		$this->loadSettings();
		$this->initializeTransport();

		$sender->sendMessage($this->formatter->message("prefix") . TextFormat::GREEN . " Generated a new network secret.");
		$sender->sendMessage(TextFormat::GRAY . "Copy this value to every linked server:");
		$sender->sendMessage(TextFormat::AQUA . $secret);
	}

	private function sendStatus(CommandSender $sender) : void{
		$ready = $this->settings->isReady() && $this->transportReady;
		$sender->sendMessage(TextFormat::GOLD . "CrossServerPM status");
		$sender->sendMessage(TextFormat::GRAY . "Enabled: " . TextFormat::WHITE . ($this->settings->enabled ? "yes" : "no"));
		$sender->sendMessage(TextFormat::GRAY . "Ready: " . TextFormat::WHITE . ($ready ? "yes" : "no"));
		$sender->sendMessage(TextFormat::GRAY . "Transport: " . TextFormat::WHITE . $this->settings->transport);
		$sender->sendMessage(TextFormat::GRAY . "Network: " . TextFormat::WHITE . $this->settings->networkId);
		$sender->sendMessage(TextFormat::GRAY . "Server: " . TextFormat::WHITE . $this->settings->serverId . TextFormat::GRAY . " (" . TextFormat::WHITE . $this->settings->serverDisplayName . TextFormat::GRAY . ")");
		$sender->sendMessage(TextFormat::GRAY . "Remote players: " . TextFormat::WHITE . $this->directory->count());
	}

	private function sendServers(CommandSender $sender) : void{
		$connectedServers = $this->directory->getServers();
		$playersByServer = $this->directory->getPlayersByServerId();
		$knownServers = $this->settings->knownServers;
		$knownServers[$this->settings->serverId] = $this->settings->serverDisplayName;
		foreach($connectedServers as $serverId => $server){
			$knownServers[$serverId] ??= $server->serverName;
		}
		ksort($knownServers);

		$sender->sendMessage(TextFormat::GOLD . "CrossServerPM servers");
		$sender->sendMessage(TextFormat::GREEN . "CONNECTED " . TextFormat::WHITE . $this->settings->serverDisplayName . TextFormat::GRAY . " (" . TextFormat::WHITE . $this->settings->serverId . TextFormat::GRAY . ", local, " . TextFormat::WHITE . count($this->localPlayers) . TextFormat::GRAY . " players)");

		$remoteCount = 0;
		$offlineCount = 0;
		foreach($knownServers as $serverId => $serverName){
			if($serverId === $this->settings->serverId){
				continue;
			}

			$server = $connectedServers[$serverId] ?? null;
			if($server instanceof RemoteServer){
				++$remoteCount;
				$players = $playersByServer[$serverId] ?? [];
				$sender->sendMessage(TextFormat::GREEN . "CONNECTED " . TextFormat::WHITE . $server->serverName . TextFormat::GRAY . " (" . TextFormat::WHITE . $serverId . TextFormat::GRAY . ", " . TextFormat::WHITE . $server->onlinePlayers . TextFormat::GRAY . " players)");
				$sender->sendMessage(TextFormat::DARK_GRAY . "  Visible recipients: " . TextFormat::GRAY . ($players === [] ? "none" : implode(", ", $players)));
				continue;
			}

			++$offlineCount;
			$sender->sendMessage(TextFormat::RED . "NOT CONNECTED " . TextFormat::WHITE . $serverName . TextFormat::GRAY . " (" . TextFormat::WHITE . $serverId . TextFormat::GRAY . ")");
		}

		if($remoteCount === 0 && $offlineCount === 0){
			$sender->sendMessage(TextFormat::YELLOW . "No remote server heartbeats are currently visible.");
		}
		if($this->settings->knownServers === []){
			$sender->sendMessage(TextFormat::GRAY . "Add network.servers in config.yml to show expected servers that are not connected.");
		}
	}

	private function sendAdminHelp(CommandSender $sender) : void{
		$sender->sendMessage(TextFormat::GOLD . "CrossServerPM commands");
		$sender->sendMessage(TextFormat::GRAY . "/xmsg status " . TextFormat::WHITE . "Show transport status.");
		$sender->sendMessage(TextFormat::GRAY . "/xmsg servers " . TextFormat::WHITE . "List connected and configured offline servers.");
		$sender->sendMessage(TextFormat::GRAY . "/xmsg update " . TextFormat::WHITE . "Check Poggit for plugin updates.");
		$sender->sendMessage(TextFormat::GRAY . "/xmsg reload " . TextFormat::WHITE . "Reload config.");
		$sender->sendMessage(TextFormat::GRAY . "/xmsg key generate " . TextFormat::WHITE . "Create a shared network secret.");
	}

	private function submitUpdateCheck(bool $manual) : void{
		if(!$manual && !($this->settings->updater["enabled"] ?? false)){
			return;
		}
		if($this->updateCheckRunning){
			return;
		}

		$this->updateCheckRunning = true;
		$this->getServer()->getAsyncPool()->submitTask(new PoggitUpdateCheckTask(
			$this,
			$this->settings->updater,
			$this->currentPluginVersion(),
			$this->getServer()->getApiVersion()
		));
	}

	private function currentPluginVersion() : string{
		$configVersion = trim((string) ($this->settings->updater["current-version"] ?? ""));
		return $configVersion === "" ? $this->getDescription()->getVersion() : $configVersion;
	}

	private function updateRequesterKey(CommandSender $sender) : string{
		return $sender instanceof Player ? "player:" . strtolower($sender->getName()) : "console";
	}

	private function sendUpdateRequesterMessage(string $requester, string $message) : void{
		if($requester === "console"){
			$this->getLogger()->info(TextFormat::clean($message));
			return;
		}

		$playerName = substr($requester, strlen("player:"));
		$player = $this->findLocalPlayerByKey($playerName);
		if($player instanceof Player){
			$player->sendMessage($message);
		}
	}

	/**
	 * @param array<string, mixed> $update
	 */
	private function notifyUpdateAvailable(array $update) : void{
		$message = $this->updateAvailableMessage($update);
		if($this->settings->updater["notify-console"] ?? true){
			$this->getLogger()->warning(TextFormat::clean($message));
		}
		if(!($this->settings->updater["notify-ops"] ?? true)){
			return;
		}
		foreach($this->localPlayers as $player){
			$this->notifyPlayerAboutStoredUpdate($player);
		}
	}

	private function notifyPlayerAboutStoredUpdate(Player $player) : void{
		if($this->latestUpdate === null || !($this->settings->updater["notify-ops"] ?? true) || !$player->hasPermission("crossserverpm.admin")){
			return;
		}

		$key = strtolower($player->getName());
		if($this->notifiedUpdatePlayers[$key] ?? false){
			return;
		}

		$this->notifiedUpdatePlayers[$key] = true;
		$player->sendMessage($this->updateAvailableMessage($this->latestUpdate));
	}

	/**
	 * @param array<string, mixed> $update
	 */
	private function updateAvailableMessage(array $update) : string{
		return $this->formatter->message("update-available", [
			"current" => (string) ($update["current_version"] ?? $this->currentPluginVersion()),
			"latest" => (string) ($update["latest_version"] ?? "unknown"),
			"url" => $this->updateUrl($update),
		]);
	}

	/**
	 * @param array<string, mixed> $update
	 */
	private function updateUrl(array $update) : string{
		$releaseUrl = (string) ($update["release_url"] ?? "");
		if($releaseUrl !== ""){
			return $releaseUrl;
		}
		$downloadUrl = (string) ($update["download_url"] ?? "");
		if($downloadUrl !== ""){
			return $downloadUrl;
		}
		return "https://poggit.pmmp.io/p/" . (string) ($this->settings->updater["plugin-name"] ?? "CrossServerPM");
	}

	private function canUseMessaging(CommandSender $sender) : bool{
		if($sender instanceof Player){
			return true;
		}
		if($this->settings->allowConsoleMessaging){
			return true;
		}
		$sender->sendMessage($this->formatter->message("console-disabled"));
		return false;
	}

	private function senderName(CommandSender $sender) : string{
		return $sender instanceof Player ? $sender->getName() : $this->settings->consoleName;
	}

	private function senderDisplayName(CommandSender $sender) : string{
		return $sender instanceof Player ? $sender->getDisplayName() : $this->settings->consoleName;
	}

	private function consoleKey() : string{
		return "__console__:" . strtolower($this->settings->serverId);
	}

	private function isConsoleName(string $name) : bool{
		return strtolower($name) === strtolower($this->settings->consoleName);
	}

	private function findLocalPlayer(string $name) : ?Player{
		return $this->localPlayers[strtolower($name)] ?? null;
	}

	private function findLocalPlayerByKey(string $key) : ?Player{
		return $this->localPlayers[strtolower($key)] ?? null;
	}

	private function rebuildLocalPlayerCache() : void{
		$this->localPlayers = [];
		foreach($this->getServer()->getOnlinePlayers() as $player){
			$this->localPlayers[strtolower($player->getName())] = $player;
		}
	}
}
