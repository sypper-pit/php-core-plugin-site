#!/usr/bin/env node
/**
 * Lightweight Standalone Local Relay for OpenCode & Agent integrations.
 *
 * Why this is needed:
 * Chrome Manifest V3 extensions run inside a sandboxed browser process.
 * Chrome forbids extensions from directly creating a listening raw TCP socket (server socket)
 * without an external helper or Chrome OS sockets API.
 *
 * This tiny script (zero-dependency, using only built-in Node.js 'http') binds to 127.0.0.1:8085:
 * 1. Exposes an OpenAI-compatible HTTP endpoint: POST http://127.0.0.1:8085/v1/chat/completions
 * 2. Bridges requests directly to the Chrome extension via a local loopback WebSocket/HTTP connection.
 * 3. Returns the Perplexity response directly to OpenCode!
 */

const http = require('http');

const PORT = process.env.PORT || 8085;
const HOST = '127.0.0.1';

let pendingRequests = new Map();
let extensionSocket = null;
let queue = [];

const server = http.createServer((req, res) => {
  // Enable CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  // Health check endpoint
  if (req.url === '/health' || req.url === '/') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'ok',
      extensionConnected: !!extensionSocket,
      queueLength: queue.length
    }));
    return;
  }

  // Poll endpoint for extension if WS is not used
  if (req.url === '/poll' && req.method === 'GET') {
    if (queue.length > 0) {
      const item = queue.shift();
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(item));
    } else {
      res.writeHead(204);
      res.end();
    }
    return;
  }

  // Response postback endpoint from extension
  if (req.url === '/respond' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      try {
        const data = JSON.parse(body);
        const reqHandler = pendingRequests.get(data.id);
        if (reqHandler) {
          pendingRequests.delete(data.id);
          reqHandler(data);
        }
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ acknowledged: true }));
      } catch (e) {
        res.writeHead(400);
        res.end(e.message);
      }
    });
    return;
  }

  // OpenAI-compatible Chat Completions endpoint for OpenCode
  if (req.url === '/v1/chat/completions' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      try {
        const payload = JSON.parse(body);
        const reqId = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7);

        const prompt = payload.prompt || (payload.messages && payload.messages.map(m => `${m.role}: ${m.content}`).join('\n')) || '';

        const timeout = setTimeout(() => {
          if (pendingRequests.has(reqId)) {
            pendingRequests.delete(reqId);
            res.writeHead(504, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: { message: 'Gateway request timed out waiting for Perplexity.' } }));
          }
        }, 60000);

        pendingRequests.set(reqId, (respData) => {
          clearTimeout(timeout);
          if (respData.error) {
            res.writeHead(500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: { message: respData.error } }));
          } else {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify(respData.data));
          }
        });

        // Add to queue for Chrome extension to pick up
        queue.push({
          id: reqId,
          type: 'CHAT_COMPLETION',
          prompt: prompt,
          messages: payload.messages,
          stream: payload.stream || false
        });

      } catch (err) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: { message: 'Invalid JSON payload: ' + err.message } }));
      }
    });
    return;
  }

  // Not found
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Endpoint not found' }));
});

server.listen(PORT, HOST, () => {
  console.log(`=======================================================`);
  console.log(`Perplexity OpenCode Gateway listening on http://${HOST}:${PORT}`);
  console.log(`OpenAI-compatible Endpoint: http://${HOST}:${PORT}/v1/chat/completions`);
  console.log(`=======================================================`);
});
