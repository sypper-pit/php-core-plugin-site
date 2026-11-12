/**
 * Content script running in https://www.perplexity.ai/*
 * Interacts directly with Perplexity page or intercepts internal session / fetches.
 */

// Global flag to prevent duplicate listeners if injected multiple times
if (!window.__PERPLEXITY_GATEWAY_INITIALIZED__) {
  window.__PERPLEXITY_GATEWAY_INITIALIZED__ = true;

  console.log('[Perplexity-Gateway] Content script loaded on perplexity.ai');

  // Notify background that a Perplexity tab is active and ready
  function notifyReady() {
    try {
      chrome.runtime.sendMessage({
        type: 'PERPLEXITY_TAB_READY',
        url: window.location.href
      }, () => {
        // Ignore potential errors if runtime is not immediately available
        if (chrome.runtime.lastError) {}
      });
    } catch (e) {}
  }

  notifyReady();

  // Listen for messages from background script
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.type === 'PING') {
      sendResponse({ status: 'pong', url: window.location.href, ready: true });
      return false;
    }

    if (message.type === 'CHECK_STATUS') {
      sendResponse({
        active: true,
        url: window.location.href,
        loggedIn: checkLoggedIn()
      });
      return false;
    }

    if (message.type === 'EXECUTE_QUERY') {
      handleQuery(message.payload)
        .then(result => sendResponse({ success: true, data: result }))
        .catch(err => sendResponse({ success: false, error: err.message || String(err) }));
      return true; // Keep sendResponse channel open for async response
    }
  });

  function checkLoggedIn() {
    return !!document.querySelector('button[aria-label="User profile"], [data-testid="user-profile"], img[alt*="avatar"], .user-avatar');
  }

  /**
   * Automates query input in the Perplexity web interface
   */
  async function handleQuery({ prompt, mode = 'default' }) {
    // Wait for input textarea to become available if page is still rendering
    const textarea = await waitForSelector('textarea[placeholder*="Ask"], textarea[placeholder*="Спросите"], textarea', 5000);
    if (!textarea) {
      throw new Error('Search input field not found on Perplexity page. Make sure the page is fully loaded.');
    }

    // Set text properly with native input value setter to trigger React listeners
    textarea.focus();
    const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value')?.set;
    if (nativeSetter) {
      nativeSetter.call(textarea, prompt);
    } else {
      textarea.value = prompt;
    }

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));

    // Submit query
    await new Promise(r => setTimeout(r, 400));
    const submitButton = document.querySelector('button[aria-label="Submit"], button[aria-label*="Search"], button[aria-label*="Отправить"], button svg[data-icon="arrow-right"]')?.closest('button')
      || document.querySelector('button.bg-super')
      || document.querySelector('button[type="submit"]');

    if (submitButton && !submitButton.disabled) {
      submitButton.click();
    } else {
      // Fallback: send Enter keydown & keypress
      textarea.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true }));
      textarea.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true }));
    }

    // Wait for response completion
    const responseText = await waitForResponseCompletion();
    return {
      content: responseText,
      model: 'perplexity-web'
    };
  }

  function waitForSelector(selector, timeoutMs = 5000) {
    return new Promise((resolve) => {
      const el = document.querySelector(selector);
      if (el) return resolve(el);

      const startTime = Date.now();
      const interval = setInterval(() => {
        const found = document.querySelector(selector);
        if (found) {
          clearInterval(interval);
          resolve(found);
        } else if (Date.now() - startTime > timeoutMs) {
          clearInterval(interval);
          resolve(null);
        }
      }, 200);
    });
  }

  /**
   * Monitors DOM mutations to extract generated response when finished
   */
  function waitForResponseCompletion(timeoutMs = 60000) {
    return new Promise((resolve, reject) => {
      const startTime = Date.now();
      let lastContent = '';
      let stableCount = 0;

      const interval = setInterval(() => {
        const answerElements = document.querySelectorAll('.prose, [data-testid="answer"], div[class*="answer"], .default.font-sans');
        const latestAnswer = answerElements[answerElements.length - 1];

        if (latestAnswer) {
          const text = latestAnswer.innerText.trim();
          if (text && text === lastContent) {
            stableCount++;
            const isGenerating = !!document.querySelector('button[aria-label="Stop generating"], button svg[data-icon="stop"]');
            if (!isGenerating && stableCount >= 3) {
              clearInterval(interval);
              resolve(text);
              return;
            }
          } else if (text !== lastContent) {
            lastContent = text;
            stableCount = 0;
          }
        }

        if (Date.now() - startTime > timeoutMs) {
          clearInterval(interval);
          if (lastContent) {
            resolve(lastContent);
          } else {
            reject(new Error('Timeout waiting for Perplexity response.'));
          }
        }
      }, 500);
    });
  }
}
