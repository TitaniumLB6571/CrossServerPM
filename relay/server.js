"use strict";

const http = require("http");

const version = "0.1.0";
const host = process.env.HOST || "0.0.0.0";
const port = Number.parseInt(process.env.PORT || "8080", 10);
const relayAccessKey = process.env.RELAY_ACCESS_KEY || "";
const maxBodyBytes = Number.parseInt(process.env.MAX_BODY_BYTES || "1048576", 10);
const networks = new Map();

function getNetwork(hash) {
  if (!networks.has(hash)) {
    networks.set(hash, {
      servers: new Map(),
      presence: new Map(),
      messages: [],
      nextMessageId: 1,
    });
  }
  return networks.get(hash);
}

function sendJson(res, status, body) {
  const encoded = JSON.stringify(body);
  res.writeHead(status, {
    "Content-Type": "application/json",
    "Content-Length": Buffer.byteLength(encoded),
  });
  res.end(encoded);
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    let body = "";
    req.on("data", chunk => {
      body += chunk;
      if (body.length > maxBodyBytes) {
        reject(new Error("request body too large"));
        req.destroy();
      }
    });
    req.on("end", () => {
      try {
        resolve(body === "" ? {} : JSON.parse(body));
      } catch (error) {
        reject(error);
      }
    });
    req.on("error", reject);
  });
}

function cleanNetwork(network, now, stalePresenceSeconds, messageTtlSeconds) {
  const stalePresenceCutoff = now - stalePresenceSeconds;
  for (const [serverId, server] of network.servers) {
    if (server.updated_at < stalePresenceCutoff) {
      network.servers.delete(serverId);
    }
  }

  for (const [playerKey, player] of network.presence) {
    if (player.updated_at < stalePresenceCutoff) {
      network.presence.delete(playerKey);
    }
  }

  const messageCutoff = now - messageTtlSeconds;
  network.messages = network.messages.filter(message => {
    if (message.created_at < messageCutoff) {
      return false;
    }
    return !(message.delivered_at !== null && message.delivered_at < messageCutoff);
  });
}

function requireString(payload, key) {
  const value = payload[key];
  return typeof value === "string" ? value : "";
}

function isAuthorized(req) {
  return relayAccessKey === "" || req.headers["x-crossserverpm-relay-key"] === relayAccessKey;
}

async function handleHealth(req, res) {
  sendJson(res, 200, {
    ok: true,
    name: "CrossServerPM Relay",
    version,
    networks: networks.size,
    uptime_seconds: Math.floor(process.uptime()),
  });
}

async function handleHeartbeat(req, res) {
  const payload = await readJson(req);
  const networkHash = requireString(payload, "network_hash");
  const serverId = requireString(payload, "server_id");
  const serverName = requireString(payload, "server_name") || serverId;
  const now = Number(payload.now) || Math.floor(Date.now() / 1000);
  if (networkHash === "" || serverId === "") {
    sendJson(res, 400, { ok: false, error: "network_hash and server_id are required" });
    return;
  }

  const network = getNetwork(networkHash);
  network.servers.set(serverId, {
    server_id: serverId,
    server_name: serverName,
    online_players: Number(payload.online_players) || 0,
    updated_at: now,
  });

  for (const [playerKey, player] of network.presence) {
    if (player.server_id === serverId) {
      network.presence.delete(playerKey);
    }
  }

  const players = Array.isArray(payload.players) ? payload.players : [];
  for (const player of players) {
    const key = typeof player.key === "string" ? player.key : "";
    const name = typeof player.name === "string" ? player.name : "";
    if (key === "" || name === "") {
      continue;
    }
    network.presence.set(key, {
      player_key: key,
      player_name: name,
      server_id: serverId,
      server_name: serverName,
      updated_at: now,
    });
  }

  cleanNetwork(
    network,
    now,
    Number(payload.stale_presence_seconds) || 15,
    Number(payload.message_ttl_seconds) || 30,
  );
  sendJson(res, 200, { ok: true });
}

async function handlePoll(req, res) {
  const payload = await readJson(req);
  const networkHash = requireString(payload, "network_hash");
  const serverId = requireString(payload, "server_id");
  const now = Number(payload.now) || Math.floor(Date.now() / 1000);
  if (networkHash === "" || serverId === "") {
    sendJson(res, 400, { ok: false, error: "network_hash and server_id are required" });
    return;
  }

  const network = getNetwork(networkHash);
  const stalePresenceSeconds = Number(payload.stale_presence_seconds) || 15;
  const messageTtlSeconds = Number(payload.message_ttl_seconds) || 30;
  cleanNetwork(network, now, stalePresenceSeconds, messageTtlSeconds);

  const presenceCutoff = now - stalePresenceSeconds;
  const servers = Array.from(network.servers.values())
    .filter(server => server.server_id !== serverId && server.updated_at >= presenceCutoff)
    .sort((a, b) => a.server_name.localeCompare(b.server_name));

  const players = Array.from(network.presence.values())
    .filter(player => player.server_id !== serverId && player.updated_at >= presenceCutoff)
    .sort((a, b) => a.server_name.localeCompare(b.server_name) || a.player_name.localeCompare(b.player_name));

  const localKeys = new Set(Array.isArray(payload.local_player_keys) ? payload.local_player_keys : []);
  const messageCutoff = now - messageTtlSeconds;
  const messages = [];
  for (const message of network.messages) {
    if (
      message.target_server_id === serverId &&
      message.delivered_at === null &&
      message.created_at >= messageCutoff &&
      localKeys.has(message.recipient_key)
    ) {
      message.delivered_at = now;
      messages.push(message);
      if (messages.length >= 50) {
        break;
      }
    }
  }

  sendJson(res, 200, { ok: true, servers, players, messages });
}

async function handleSend(req, res) {
  const payload = await readJson(req);
  const networkHash = requireString(payload, "network_hash");
  const targetServerId = requireString(payload, "target_server_id");
  const targetKey = requireString(payload, "target_key");
  if (networkHash === "" || targetServerId === "" || targetKey === "") {
    sendJson(res, 400, { ok: false, error: "network_hash, target_server_id, and target_key are required" });
    return;
  }

  const network = getNetwork(networkHash);
  const now = Number(payload.now) || Math.floor(Date.now() / 1000);
  network.messages.push({
    id: network.nextMessageId++,
    network_hash: networkHash,
    target_server_id: targetServerId,
    recipient_key: targetKey,
    recipient_name: requireString(payload, "target_name"),
    sender_name: requireString(payload, "sender_name"),
    sender_display: requireString(payload, "sender_display"),
    sender_server_id: requireString(payload, "sender_server_id"),
    sender_server_name: requireString(payload, "sender_server_name"),
    body: requireString(payload, "body"),
    created_at: now,
    delivered_at: null,
  });
  sendJson(res, 200, { ok: true });
}

const routes = new Map([
  ["GET /health", handleHealth],
  ["POST /heartbeat", handleHeartbeat],
  ["POST /poll", handlePoll],
  ["POST /send", handleSend],
]);

const server = http.createServer(async (req, res) => {
  const pathname = new URL(req.url, "http://127.0.0.1").pathname;
  const handler = routes.get(`${req.method} ${pathname}`);
  if (handler === undefined) {
    sendJson(res, 404, { ok: false, error: "not found" });
    return;
  }
  if (!isAuthorized(req)) {
    sendJson(res, 401, { ok: false, error: "invalid relay access key" });
    return;
  }

  try {
    await handler(req, res);
  } catch (error) {
    sendJson(res, 500, { ok: false, error: error instanceof Error ? error.message : "internal error" });
  }
});

server.listen(port, host, () => {
  console.log(`CrossServerPM relay ${version} listening on ${host}:${port}`);
  if (relayAccessKey === "") {
    console.log("RELAY_ACCESS_KEY is not set; relay-wide access key protection is disabled.");
  }
});
