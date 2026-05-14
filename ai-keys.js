// ai-keys.js — Sync AI provider API keys from Settings → Connectors → AI
// into localStorage so the existing tool code (which reads from localStorage)
// keeps working without any per-tool refactor.
//
// Tools call window.aiKeysReady (a Promise) to wait until the sync completes
// before kicking off any AI request, so the first run after page load doesn't
// race the network.
(function (global) {
  const API_BASE  = 'https://indigo-dog-836598.hostingersite.com/github/api';
  const API_TOKEN = 'tk_4d2b9f7a8c6e1530a4f2d9b7e8c6a1f5';
  const KEYS = {
    claude:     'app.anthropicApiKey',
    chatgpt:    'app.openaiApiKey',
    openrouter: 'app.openrouterApiKey',
    gemini:     'app.geminiApiKey',
  };
  global.aiKeysReady = (async () => {
    try {
      const r = await fetch(API_BASE + '/connectors.php?type=ai', {
        headers: { 'Authorization': 'Bearer ' + API_TOKEN }
      });
      if (!r.ok) return;
      const d = await r.json();
      (d.rows || []).forEach(c => {
        const lsKey = KEYS[c.provider];
        if (lsKey && c.token) localStorage.setItem(lsKey, c.token);
      });
    } catch (e) { /* keys may already be in localStorage from a previous load */ }
  })();
})(window);
