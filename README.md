# CrossServerPM

<p align="center">
  <img src="icon.png" alt="CrossServerPM logo" width="220">
</p>

CrossServerPM lets players send private messages between linked PocketMine-MP
servers.

Example:

```text
/msg Carl hi
```

If Carl is on another linked server, Carl can receive:

```text
[Titan Server] Player -> Me: hi
```

The formats are configurable in `config.yml`.

## Description

- Send private messages to players on the same server or another linked server.
- Reply to the last private message with `/reply` or `/r`.
- Link only the servers you own by using the same network id and secret.
- Choose between `mysql`, `relay`, `redis`, or `file` transport.
- Customize message prefixes and formats.
- Check linked status, connected servers, and configured offline servers in-game.

## How to use?

1. Add the plugin to every PocketMine-MP server you want linked.
2. Restart each server once so `config.yml` is created.
3. Pick one transport in `config.yml`: `mysql`, `relay`, `redis`, or `file`.
4. Copy the matching example from `resources/examples/` if you want a full starting point.
5. Run `/xmsg key generate` on one server.
6. Copy the generated `network.secret` to every linked server.
7. Use the same `network.id` and transport settings on every linked server.
8. Give every server a unique `server.id`.
9. Set `enabled: true`.
10. Restart the servers or run `/xmsg reload`.

## Requirements

- PocketMine-MP API 5.
- PHP `pdo_mysql` extension for `transport: mysql`.
- PHP `curl` extension for `transport: relay`.
- Redis/Valkey server access for `transport: redis`. The PHP Redis extension is not required.
- A shared folder available to every linked server for `transport: file`.
- Node.js 18 or newer only if you run the optional reference relay app from this source repository.

## Example configs

Use only one transport at a time. Pick one file from `resources/examples/`,
copy it into `plugin_data/CrossServerPM/config.yml`, then edit it.

- `resources/examples/mysql.yml` - Use one shared MySQL/MariaDB database.
- `resources/examples/relay.yml` - Use the included HTTP relay app.
- `resources/examples/redis.yml` - Use Redis/Valkey, including Redis Cloud.
- `resources/examples/file.yml` - Use one shared folder for same-machine servers.

On every linked server, keep these the same:

- `transport`
- `network.id`
- `network.secret`
- `network.servers`
- The settings for the selected transport

On each linked server, change these:

- `server.id`
- `server.display-name`

## Transport options

### MySQL

Use `transport: mysql` if you want the easiest public-plugin setup.

All linked servers connect to the same MySQL/MariaDB database. You do not need
to run a separate relay app or configure a relay URL.

Use the same values on every linked server:

```yaml
transport: mysql
network:
  id: "titan-network"
  secret: "same-secret-on-every-linked-server"
  servers:
    survival: "Titan Survival"
    skyblock: "Titan Skyblock"
mysql:
  host: "mysql.example.net"
  port: 3306
  database: "titan_crossserverpm"
  username: "titan_crossserverpm"
  password: "fake-password-change-this"
```

Only change this per server:

```yaml
server:
  id: "survival"
  display-name: "Titan Survival"
```

For the second server, use something like:

```yaml
server:
  id: "skyblock"
  display-name: "Titan Skyblock"
```

### Relay

Use `transport: relay` if you want servers to talk to a relay service instead
of a database.

The URL is the address of the relay service. It can be on your domain, but only
if that domain is actually hosting the relay. Your normal Minecraft address is
not enough unless the relay is also running there.

Example:

```yaml
transport: relay
relay:
  url: "https://relay.titan-network.example"
```

A small reference relay is available in this source repository at `relay/server.js`:

```text
cd relay
node server.js
```

You can also start it with:

```text
cd relay
npm start
```

If the relay is protected with `RELAY_ACCESS_KEY`, put the same value in
`relay.access-key`:

```yaml
transport: relay
relay:
  url: "https://relay.titan-network.example"
  access-key: "change-this"
```

For public internet use, put the relay behind HTTPS with a reverse proxy.

### Redis / Valkey

Use `transport: redis` if your host provides Redis/Valkey or you already run it
for your network.

```yaml
transport: redis
redis:
  host: "redis.example.com"
  port: 6379
  username: ""
  password: "change-this"
  database: 0
  key-prefix: "cspm"
```

The plugin talks to Redis directly over TCP, so it does not need the PHP Redis
extension.

### File

Use `transport: file` only when every linked server can access the same folder.
This is useful when multiple servers run on one machine.

```yaml
transport: file
file:
  path: "/home/minecraft/shared-crossserverpm"
```

Do not use file transport for unrelated servers on different machines unless
you know the folder is a reliable shared mount.

## In-game commands

- `/msg <player> <message>` - Send a private message.
- `/tell <player> <message>` - Alias for `/msg`.
- `/w <player> <message>` - Alias for `/msg`.
- `/reply <message>` - Reply to the last private message.
- `/r <message>` - Alias for `/reply`.
- Console can use `/msg` and `/reply` when `runtime.allow-console-messaging` is `true`.
- `/xmsg status` - Show transport, network, and readiness status.
- `/xmsg servers` - List connected servers and configured servers that are not connected.
- `/xmsg reload` - Reload config.
- `/xmsg key generate` - Generate a shared network secret. (this gets put in your config automatically when running the commmand)

## Server list command

`/xmsg servers` always shows the local server. It also shows remote servers
that are currently sending heartbeats through the selected transport.

To show servers that are not connected, list the expected servers in
`network.servers` on every linked server:

```yaml
network:
  servers:
    survival: "Titan Survival"
    skyblock: "Titan Skyblock"
    factions: "Titan Factions"
```

If `skyblock` is not sending heartbeats, `/xmsg servers` will show it as
`NOT CONNECTED`.

## Implemented Features

- [x] Cross-server `/msg`.
- [x] Cross-server `/reply`.
- [x] Local private messaging fallback.
- [x] MySQL transport.
- [x] HTTP relay transport.
- [x] Redis/Valkey transport.
- [x] Shared-folder file transport.
- [x] Configurable server names.
- [x] Configurable message formats.
- [x] Private server groups using `network.id` and `network.secret`.
- [x] Message length limit.
- [x] Command cooldown.
- [x] Stale player cleanup.
- [x] Optional console `/msg` and `/reply`.
- [x] Admin status command.
- [x] Connected/offline server list command.

## Future Features

- [ ] SocialSpy for private messages only.
- [ ] Ignore system with `/ignore`, `/unignore`, and `/ignore list`.
- [ ] Toggle private messages with `/pmtoggle`.
- [ ] Staff bypass permissions for ignore, PM toggle, and cooldown checks.
- [ ] Configurable incoming message sounds.
- [ ] Cross-server staff chat with `/staffchat` and `/sc`.
- [ ] Admin cross-server broadcasts.
- [ ] Offline mail for players who are not currently online.
- [ ] Last seen lookup for players across linked servers.
- [ ] Friend system with cross-server online status.
- [ ] Vanish integration so hidden players are not advertised in presence.
- [ ] Public API for other plugins.
- [ ] Custom PocketMine events for private messages, staff chat, broadcasts, offline mail, and presence updates.
- [ ] Better transport diagnostics, including last heartbeat, last poll, and recent error details.
- [ ] Cleanup command for stale backend data.
- [ ] More message formatting options for staff spy, offline mail, console messages, and blocked messages.

## Permissions

- `crossserverpm.msg` - Use `/msg`, `/tell`, `/w`, `/reply`, and `/r`.
- `crossserverpm.admin` - Use `/xmsg status`, `/xmsg servers`,
  `/xmsg reload`, and `/xmsg key generate`.

## Notes

- Keep `network.secret` private.
- The same `network.secret` must be copied to every linked server.
- Every linked server must have a different `server.id`.
- Restart after editing config, or run `/xmsg reload`.
- If remote players do not show up, check `/xmsg status` first.
- The plugin stores a hash of `network.id + network.secret` in transport data,
  not the raw secret.
- There is no central CrossServerPM service. MySQL, Redis, file, and relay
  transports only use the backend that you configure.
- The relay transport sends HTTP requests only to your configured relay URL and
  does not execute remote code.
