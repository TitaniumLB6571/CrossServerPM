<?php

declare(strict_types=1);

namespace NorixDevelopment\CrossServerPM\service;

use pocketmine\utils\TextFormat;
use function strtr;

final class MessageFormatter{
	private const DEFAULTS = [
		"prefix" => "§1§lCross§6PM§r §8>§r",
		"incoming-remote" => "{prefix} §7[§b{server}§7] §f{sender} §7-> §eMe§7: §f{message}",
		"outgoing-remote" => "{prefix} §7[§b{server}§7] §eYou §7-> §f{target}§7: §f{message}",
		"incoming-local" => "{prefix} §f{sender} §7-> §eMe§7: §f{message}",
		"outgoing-local" => "{prefix} §eYou §7-> §f{target}§7: §f{message}",
		"usage-msg" => "{prefix} §cUsage: /msg <player> <message>",
		"usage-reply" => "{prefix} §cUsage: /reply <message>",
		"player-only" => "{prefix} §cThis command can only be used in-game.",
		"console-disabled" => "{prefix} §cConsole messaging is disabled in config.yml.",
		"not-configured" => "{prefix} §cCross-server messaging is not configured.",
		"target-not-found" => "{prefix} §cThat player is not online on any linked server.",
		"no-reply-target" => "{prefix} §cNo one has messaged you yet.",
		"message-too-long" => "{prefix} §cThat message is too long. Max length: {max} characters.",
		"cooldown" => "{prefix} §cPlease wait before sending another message.",
		"send-failed" => "{prefix} §cMessage failed: {reason}",
		"reload-complete" => "{prefix} §aConfiguration reloaded.",
		"update-check-started" => "{prefix} §7Checking Poggit for updates...",
		"update-available" => "{prefix} §eUpdate available: §f{current} §7-> §a{latest}§7. Download: §b{url}",
		"update-none" => "{prefix} §aCrossServerPM is up to date. Current version: §f{version}",
		"update-no-release" => "{prefix} §eNo compatible Poggit release was found yet. {reason}",
		"update-check-failed" => "{prefix} §cUpdate check failed: {reason}",
	];

	/**
	 * @param array<string, string> $messages
	 */
	public function __construct(
		private readonly array $messages
	){}

	public function incomingRemote(string $sender, string $server, string $message) : string{
		return $this->message("incoming-remote", ["sender" => $sender, "server" => $server, "message" => $message]);
	}

	public function outgoingRemote(string $target, string $server, string $message) : string{
		return $this->message("outgoing-remote", ["target" => $target, "server" => $server, "message" => $message]);
	}

	public function incomingLocal(string $sender, string $message) : string{
		return $this->message("incoming-local", ["sender" => $sender, "message" => $message]);
	}

	public function outgoingLocal(string $target, string $message) : string{
		return $this->message("outgoing-local", ["target" => $target, "message" => $message]);
	}

	/**
	 * @param array<string, scalar|null> $context
	 */
	public function message(string $key, array $context = []) : string{
		$template = $this->messages[$key] ?? self::DEFAULTS[$key] ?? "";
		$template = TextFormat::colorize($template);
		$replacements = [
			"{prefix}" => TextFormat::colorize($this->messages["prefix"] ?? self::DEFAULTS["prefix"]),
		];
		foreach($context as $contextKey => $value){
			$replacements["{" . $contextKey . "}"] = $value === null ? "" : (string) $value;
		}
		return strtr($template, $replacements);
	}
}
