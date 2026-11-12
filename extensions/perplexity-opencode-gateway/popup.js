document.addEventListener('DOMContentLoaded', () => {
  const wsPortInput = document.getElementById('wsPort');
  const maxPerHourInput = document.getElementById('maxPerHour');
  const maxPerDayInput = document.getElementById('maxPerDay');
  const saveBtn = document.getElementById('saveBtn');
  const tabBadge = document.getElementById('tabBadge');
  const hourStats = document.getElementById('hourStats');
  const dayStats = document.getElementById('dayStats');
  const hourProgress = document.getElementById('hourProgress');
  const dayProgress = document.getElementById('dayProgress');
  const sendTestBtn = document.getElementById('sendTestBtn');
  const testPrompt = document.getElementById('testPrompt');
  const testResponse = document.getElementById('testResponse');
  const openPerplexityBtn = document.getElementById('openPerplexityBtn');
  const agentDot = document.getElementById('agentDot');

  function refreshStatus() {
    chrome.runtime.sendMessage({ type: 'GET_GATEWAY_STATUS' }, (res) => {
      if (!res) return;
      const { config, stats, perplexityTabId } = res;

      if (config) {
        wsPortInput.value = config.wsPort || 8085;
        maxPerHourInput.value = config.maxRequestsPerHour || 100;
        maxPerDayInput.value = config.maxRequestsPerDay || 500;
      }

      if (stats) {
        const hCur = stats.requestsThisHour || 0;
        const hMax = config?.maxRequestsPerHour || 100;
        const dCur = stats.requestsToday || 0;
        const dMax = config?.maxRequestsPerDay || 500;

        hourStats.innerText = `${hCur} / ${hMax}`;
        dayStats.innerText = `${dCur} / ${dMax}`;

        hourProgress.style.width = `${Math.min(100, (hCur / hMax) * 100)}%`;
        dayProgress.style.width = `${Math.min(100, (dCur / dMax) * 100)}%`;
      }

      if (perplexityTabId) {
        tabBadge.innerText = 'Вкладка: Подключена';
        tabBadge.classList.add('active');
      } else {
        tabBadge.innerText = 'Вкладка: Не найдена';
        tabBadge.classList.remove('active');
      }
    });
  }

  saveBtn.addEventListener('click', () => {
    const portVal = parseInt(wsPortInput.value, 10) || 8085;
    const config = {
      port: portVal,
      wsPort: portVal,
      maxRequestsPerHour: parseInt(maxPerHourInput.value, 10) || 100,
      maxRequestsPerDay: parseInt(maxPerDayInput.value, 10) || 500
    };

    chrome.runtime.sendMessage({ type: 'UPDATE_CONFIG', config }, (res) => {
      if (res && res.success) {
        saveBtn.innerText = 'Сохранено!';
        setTimeout(() => { saveBtn.innerText = 'Сохранить настройки'; }, 1500);
        refreshStatus();
      }
    });
  });

  sendTestBtn.addEventListener('click', () => {
    const prompt = testPrompt.value.trim();
    if (!prompt) return;

    sendTestBtn.disabled = true;
    sendTestBtn.innerText = 'Отправка...';
    testResponse.style.display = 'block';
    testResponse.innerText = 'Ожидание ответа от Perplexity...';

    chrome.runtime.sendMessage({ type: 'DISPATCH_QUERY', prompt }, (res) => {
      sendTestBtn.disabled = false;
      sendTestBtn.innerText = 'Отправить тестовый запрос';

      if (res && res.success) {
        testResponse.innerText = res.data.content;
      } else {
        testResponse.innerText = 'Ошибка: ' + (res ? res.error : 'Неизвестная ошибка');
      }
      refreshStatus();
    });
  });

  openPerplexityBtn.addEventListener('click', () => {
    chrome.tabs.create({ url: 'https://www.perplexity.ai' });
  });

  refreshStatus();
  setInterval(refreshStatus, 2000);
});
