/**
 * Background Service Worker
 * Manages WebSocket/HTTP Long-Poll connection to local client (OpenCode),
 * handles rate limits, query dispatch to Perplexity tab, and session tracking.
 */

const DEFAULT_CONFIG = {
  port: 8085,
  wsPort: 8085,
  maxRequestsPerHour: 100,
  maxRequestsPerDay: 500,
  agentMode: true,
  autoOpenPerplexity: true,
  pollingIntervalMs: 1000
};

let currentConfig = { ...DEFAULT_CONFIG };
let perplexityTabId = null;
let stats = {
  requestsTotal: 0,
  requestsToday: 0,
  requestsThisHour: 0,
  lastResetHour: Date.now(),
  lastResetDay: Date.now(),
  history: []
};

// Rate limit helper
function checkAndUpdateRateLimits() {
  const now = Date.now();
  if (now - stats.lastResetHour > 3600 * 1000) {
    stats.requestsThisHour = 0;
    stats.lastResetHour = now;
  }
  if (now - stats.lastResetDay > 24 * 3600 * 1000) {
    stats.requestsToday = 0;
    stats.lastResetDay = now;
  }

  if (stats.requestsThisHour >= currentConfig.maxRequestsPerHour) {
    throw new Error(`Rate limit exceeded: Hourly limit of ${currentConfig.maxRequestsPerHour} queries reached.`);
  }

  if (stats.requestsToday >= currentConfig.maxRequestsPerDay) {
    throw new Error(`Rate limit exceeded: Daily limit of ${currentConfig.maxRequestsPerDay} queries reached.`);
  }

  stats.requestsTotal++;
  stats.requestsToday++;
  stats.requestsThisHour++;
  saveStats();
}

function loadSettings() {
  chrome.storage.local.get(['config', 'stats'], (res) => {
    if (res.config) currentConfig = { ...DEFAULT_CONFIG, ...res.config };
    if (res.stats) stats = { ...stats, ...res.stats };
  });
}

function saveStats() {
  chrome.storage.local.set({ stats });
}

chrome.runtime.onInstalled.addListener(() => {
  loadSettings();
  console.log('[Perplexity-Gateway] Extension installed.');
});

loadSettings();

/**
 * Pings content script in tab to see if it responds.
 */
function pingTab(tabId) {
  return new Promise((resolve) => {
    chrome.tabs.sendMessage(tabId, { type: 'PING' }, (response) => {
      if (chrome.runtime.lastError || !response || response.status !== 'pong') {
        resolve(false);
      } else {
        resolve(true);
      }
    });
  });
}

/**
 * Injects content.js programmatically if not already present.
 */
async function ensureContentScriptInjected(tabId) {
  const isAlive = await pingTab(tabId);
  if (isAlive) {
    return true;
  }

  try {
    await chrome.scripting.executeScript({
      target: { tabId: tabId },
      files: ['content.js']
    });
    // Wait a brief moment for script initialization
    for (let i = 0; i < 5; i++) {
      await new Promise(r => setTimeout(r, 200));
      if (await pingTab(tabId)) {
        return true;
      }
    }
  } catch (err) {
    console.warn('[Perplexity-Gateway] Could not executeScript on tab:', err);
  }

  return false;
}

/**
 * Wait for a tab to finish loading (status === 'complete')
 */
function waitForTabComplete(tabId, timeoutMs = 15000) {
  return new Promise((resolve, reject) => {
    const startTime = Date.now();
    const interval = setInterval(async () => {
      try {
        const tab = await chrome.tabs.get(tabId);
        if (tab.status === 'complete') {
          clearInterval(interval);
          resolve(tab);
          return;
        }
      } catch (e) {
        clearInterval(interval);
        reject(e);
        return;
      }

      if (Date.now() - startTime > timeoutMs) {
        clearInterval(interval);
        resolve(null); // Continue anyway
      }
    }, 300);
  });
}

/**
 * Finds or opens Perplexity tab and guarantees content.js is ready.
 */
async function ensurePerplexityTab() {
  if (perplexityTabId) {
    try {
      const tab = await chrome.tabs.get(perplexityTabId);
      if (tab && tab.url && tab.url.includes('perplexity.ai')) {
        const ready = await ensureContentScriptInjected(tab.id);
        if (ready) return tab.id;
      }
    } catch (e) {
      perplexityTabId = null;
    }
  }

  // Look for any open perplexity.ai tabs
  const tabs = await chrome.tabs.query({ url: '*://*.perplexity.ai/*' });
  if (tabs.length > 0) {
    for (const t of tabs) {
      perplexityTabId = t.id;
      const ready = await ensureContentScriptInjected(t.id);
      if (ready) return t.id;
    }
  }

  // If autoOpen is permitted, open a new tab
  if (currentConfig.autoOpenPerplexity) {
    const newTab = await chrome.tabs.create({ url: 'https://www.perplexity.ai', active: false });
    perplexityTabId = newTab.id;
    await waitForTabComplete(newTab.id);
    await ensureContentScriptInjected(newTab.id);
    return perplexityTabId;
  }

  throw new Error('Вкладка https://www.perplexity.ai не открыта или не отвечает. Пожалуйста, откройте Perplexity в Chrome и обновите страницу.');
}

/**
 * Executes a prompt query on Perplexity tab
 */
async function queryPerplexity(prompt, mode = 'default') {
  checkAndUpdateRateLimits();
  const tabId = await ensurePerplexityTab();

  return new Promise((resolve, reject) => {
    chrome.tabs.sendMessage(tabId, {
      type: 'EXECUTE_QUERY',
      payload: { prompt, mode }
    }, (response) => {
      if (chrome.runtime.lastError) {
        return reject(new Error(chrome.runtime.lastError.message || 'Ошибка соединения со вкладкой Perplexity.'));
      }
      if (!response || !response.success) {
        return reject(new Error(response?.error || 'Не удалось получить ответ от вкладки Perplexity.'));
      }

      // Record history
      stats.history.unshift({
        timestamp: Date.now(),
        prompt: prompt.slice(0, 100),
        success: true
      });
      if (stats.history.length > 50) stats.history.pop();
      saveStats();

      resolve(response.data);
    });
  });
}

/**
 * Message listener from Popup / Options
 */
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg.type === 'PERPLEXITY_TAB_READY') {
    if (sender.tab && sender.tab.id) {
      perplexityTabId = sender.tab.id;
    }
    sendResponse({ ack: true });
    return false;
  }

  if (msg.type === 'GET_GATEWAY_STATUS') {
    // Check if perplexity tab is actually alive
    if (perplexityTabId) {
      pingTab(perplexityTabId).then(alive => {
        sendResponse({
          config: currentConfig,
          stats: stats,
          perplexityTabId: alive ? perplexityTabId : null
        });
      });
      return true;
    }

    sendResponse({
      config: currentConfig,
      stats: stats,
      perplexityTabId: null
    });
    return false;
  }

  if (msg.type === 'UPDATE_CONFIG') {
    currentConfig = { ...currentConfig, ...msg.config };
    chrome.storage.local.set({ config: currentConfig });
    sendResponse({ success: true, config: currentConfig });
    return false;
  }

  if (msg.type === 'DISPATCH_QUERY') {
    queryPerplexity(msg.prompt, msg.mode)
      .then(data => sendResponse({ success: true, data }))
      .catch(err => sendResponse({ success: false, error: err.message || String(err) }));
    return true;
  }
});

/**
 * Autonomous HTTP poll loop:
 * Checks http://127.0.0.1:8085/poll for incoming queries from OpenCode,
 * dispatches them to Perplexity tab, and posts back results.
 */
async function pollLoop() {
  while (true) {
    try {
      const port = currentConfig.port || 8085;
      const res = await fetch(`http://127.0.0.1:${port}/poll`, {
        method: 'GET',
        headers: { 'Cache-Control': 'no-cache' }
      });

      if (res.status === 200) {
        const item = await res.json();
        if (item && item.id) {
          handleIncomingRequest(item, port);
        }
      }
    } catch (e) {
      // Local relay might not be running at the moment, wait and retry
    }
    await new Promise(r => setTimeout(r, currentConfig.pollingIntervalMs || 1000));
  }
}

async function handleIncomingRequest(item, port) {
  try {
    const prompt = item.prompt || (item.messages && item.messages.map(m => `${m.role}: ${m.content}`).join('\n')) || '';
    const result = await queryPerplexity(prompt, item.mode);

    await fetch(`http://127.0.0.1:${port}/respond`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: item.id,
        data: {
          id: 'chatcmpl-' + Date.now(),
          object: 'chat.completion',
          created: Math.floor(Date.now() / 1000),
          model: 'perplexity-gateway',
          choices: [
            {
              index: 0,
              message: {
                role: 'assistant',
                content: result.content
              },
              finish_reason: 'stop'
            }
          ]
        }
      })
    });
  } catch (err) {
    await fetch(`http://127.0.0.1:${port}/respond`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: item.id,
        error: err.message || String(err)
      })
    });
  }
}

// Start background poll loop
pollLoop();
