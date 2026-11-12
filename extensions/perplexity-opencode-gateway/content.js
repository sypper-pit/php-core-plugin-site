/**
 * Content script running in https://www.perplexity.ai/*
 * Interacts directly with Perplexity page or intercepts internal session / fetches.
 */

console.log('[Perplexity-Gateway] Content script loaded on perplexity.ai');

// Notify background that a Perplexity tab is active and ready
function notifyReady() {
  chrome.runtime.sendMessage({
    type: 'PERPLEXITY_TAB_READY',
    url: window.location.href
  });
}

notifyReady();

// Listen for messages from background script
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message.type === 'EXECUTE_QUERY') {
    handleQuery(message.payload)
      .then(result => sendResponse({ success: true, data: result }))
      .catch(err => sendResponse({ success: false, error: err.message }));
    return true; // Keep sendResponse channel open for async response
  }

  if (message.type === 'CHECK_STATUS') {
    sendResponse({
      active: true,
      url: window.location.href,
      loggedIn: checkLoggedIn()
    });
    return false;
  }
});

function checkLoggedIn() {
  // Checks if user avatar or account controls exist
  const hasUserElement = !!document.querySelector('button[aria-label="User profile"], [data-testid="user-profile"], img[alt*="avatar"], .user-avatar');
  return hasUserElement;
}

/**
 * Automates query input in the Perplexity web interface
 */
async function handleQuery({ prompt, mode = 'default' }) {
  const textarea = document.querySelector('textarea[placeholder*="Ask"], textarea[placeholder*="Спросите"], textarea');
  if (!textarea) {
    throw new Error('Search input field not found on Perplexity page. Make sure the page is fully loaded.');
  }

  // Set text
  textarea.focus();
  textarea.value = prompt;
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
  textarea.dispatchEvent(new Event('change', { bubbles: true }));

  // Submit query
  await new Promise(r => setTimeout(r, 400));
  const submitButton = document.querySelector('button[aria-label="Submit"], button[aria-label*="Search"], button[aria-label*="Отправить"], button svg[data-icon="arrow-right"]')?.closest('button')
    || document.querySelector('button.bg-super');

  if (submitButton && !submitButton.disabled) {
    submitButton.click();
  } else {
    // Fallback: send Enter key
    textarea.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true }));
  }

  // Wait for response completion
  const responseText = await waitForResponseCompletion();
  return {
    content: responseText,
    model: 'perplexity-web'
  };
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
      // Perplexity answer container selector
      const answerElements = document.querySelectorAll('.prose, [data-testid="answer"], div[class*="answer"], .default.font-sans');
      const latestAnswer = answerElements[answerElements.length - 1];

      if (latestAnswer) {
        const text = latestAnswer.innerText.trim();
        if (text && text === lastContent) {
          stableCount++;
          // If text hasn't changed for 3 checks (1.5s) and stop button is gone
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
