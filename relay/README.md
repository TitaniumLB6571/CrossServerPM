# CrossServerPM Relay

This is the reference HTTP relay for CrossServerPM `transport: relay`.

It is dependency-free and stores presence/messages in memory. That is fine for a
small setup, but restarting the relay clears current presence and undelivered
messages.

## Start

```text
node server.js
```

Or:

```text
npm start
```

By default it listens on `0.0.0.0:8080`.

## Environment

- `HOST` - Listen host. Default: `0.0.0.0`.
- `PORT` - Listen port. Default: `8080`.
- `RELAY_ACCESS_KEY` - Optional relay-wide access key.
- `MAX_BODY_BYTES` - Maximum JSON request body size. Default: `1048576`.

Example:

```text
PORT=8080 RELAY_ACCESS_KEY=change-this node server.js
```

On Windows PowerShell:

```powershell
$env:PORT = "8080"
$env:RELAY_ACCESS_KEY = "change-this"
node server.js
```

## Plugin config

```yaml
transport: relay
relay:
  url: "https://relay.example.com"
  access-key: "change-this"
```

If `RELAY_ACCESS_KEY` is not set on the relay, leave `relay.access-key` empty in
the plugin config.

## Endpoints

- `GET /health`
- `POST /heartbeat`
- `POST /poll`
- `POST /send`

Run the relay behind HTTPS for public internet use.
