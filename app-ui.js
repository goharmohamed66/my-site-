// app-ui.js — Shared in-app toast + confirm components.
// Replaces native window.alert/confirm with styled UI that matches the
// rest of the dashboard (Inter font, 8-12px radii, navy/black palette).
//
// Public API:
//   appToast(message, opts?)   → fire-and-forget toast (auto-dismiss).
//   appConfirm(message, opts?) → returns Promise<boolean>.
//
// opts (toast):
//   { type: 'info'|'success'|'warn'|'error', duration: ms, title: '…' }
// opts (confirm):
//   { title: '…', okText: 'Delete', cancelText: 'Cancel',
//     danger: true, description: '…' }

(function (global) {
  if (global.appToast && global.appConfirm) return; // idempotent

  // ── CSS injected once ─────────────────────────────────────────────
  function ensureCss() {
    if (document.getElementById('app-ui-css')) return;
    var s = document.createElement('style');
    s.id = 'app-ui-css';
    s.textContent = ''
      // Toast container — stacks bottom-right
      + '.au-toasts{position:fixed;bottom:18px;right:18px;display:flex;flex-direction:column;gap:10px;z-index:100000;pointer-events:none;max-width:calc(100vw - 36px);}'
      + '.au-toast{pointer-events:auto;background:#FFFFFF;color:#1A1A1A;border:1px solid #E1E3E5;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12),0 0 0 1px rgba(0,0,0,0.02);padding:12px 14px 12px 12px;display:flex;align-items:flex-start;gap:10px;min-width:260px;max-width:380px;font-family:Inter,system-ui,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.5;animation:au-toast-in .22s cubic-bezier(.34,1.56,.64,1);}'
      + '.au-toast.leaving{animation:au-toast-out .18s ease-in forwards;}'
      + '.au-toast .au-ic{flex-shrink:0;width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;}'
      + '.au-toast.success .au-ic{background:#D1FAE5;color:#065F46;}'
      + '.au-toast.error .au-ic{background:#FEE2E2;color:#991B1B;}'
      + '.au-toast.warn .au-ic{background:#FEF3C7;color:#92400E;}'
      + '.au-toast.info .au-ic{background:#E7F0FD;color:#1877F2;}'
      + '.au-toast .au-body{flex:1;min-width:0;}'
      + '.au-toast .au-title{font-weight:700;color:#1A1A1A;margin-bottom:2px;}'
      + '.au-toast .au-msg{color:#4A5568;}'
      + '.au-toast .au-close{flex-shrink:0;background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:14px;line-height:1;padding:2px 4px;border-radius:4px;font-family:inherit;}'
      + '.au-toast .au-close:hover{color:#1A1A1A;background:#F1F1F1;}'
      // Confirm modal
      + '.au-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,0.45);backdrop-filter:blur(2px);z-index:100001;display:flex;align-items:center;justify-content:center;padding:18px;animation:au-fade-in .15s ease;overscroll-behavior:contain;}'
      + '.au-modal-bg.leaving{animation:au-fade-out .14s ease-in forwards;}'
      + '.au-modal{background:#FFFFFF;border-radius:14px;width:100%;max-width:420px;padding:24px;box-shadow:0 24px 60px rgba(0,0,0,0.22),0 0 0 1px rgba(0,0,0,0.04);font-family:Inter,system-ui,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;animation:au-pop-in .22s cubic-bezier(.34,1.56,.64,1);}'
      + '.au-modal-bg.leaving .au-modal{animation:au-pop-out .15s ease-in forwards;}'
      + '.au-modal h3{font-size:16px;font-weight:700;color:#1A1A1A;margin:0 0 8px;line-height:1.3;}'
      + '.au-modal p{font-size:13px;color:#4A5568;line-height:1.55;margin:0 0 18px;white-space:pre-wrap;word-break:break-word;}'
      + '.au-modal .au-actions{display:flex;justify-content:flex-end;gap:8px;}'
      + '.au-modal .au-btn{display:inline-flex;align-items:center;justify-content:center;padding:0 16px;height:38px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;border:1px solid transparent;min-width:88px;transition:background .12s,border-color .12s;}'
      + '.au-modal .au-btn-cancel{background:#FFFFFF;color:#1A1A1A;border-color:#DCDCDC;}'
      + '.au-modal .au-btn-cancel:hover{background:#FAFAFA;border-color:#1A1A1A;}'
      + '.au-modal .au-btn-ok{background:#1A1A1A;color:#FFFFFF;}'
      + '.au-modal .au-btn-ok:hover{background:#333333;}'
      + '.au-modal .au-btn-danger{background:#DC2626;color:#FFFFFF;}'
      + '.au-modal .au-btn-danger:hover{background:#B91C1C;}'
      + '.au-modal .au-input{width:100%;padding:11px 14px;border:1px solid #DCDCDC;border-radius:8px;font-size:14px;color:#1A1A1A;font-family:inherit;outline:none;background:#FFFFFF;transition:border-color .12s,box-shadow .12s;}'
      + '.au-modal .au-input:focus{border-color:#1A1A1A;box-shadow:0 0 0 3px rgba(26,26,26,0.08);}'
      // Animations
      + '@keyframes au-toast-in{from{transform:translateY(16px);opacity:0;}to{transform:none;opacity:1;}}'
      + '@keyframes au-toast-out{to{transform:translateY(8px);opacity:0;}}'
      + '@keyframes au-fade-in{from{opacity:0;}to{opacity:1;}}'
      + '@keyframes au-fade-out{to{opacity:0;}}'
      + '@keyframes au-pop-in{from{transform:scale(0.94);opacity:0;}to{transform:scale(1);opacity:1;}}'
      + '@keyframes au-pop-out{to{transform:scale(0.96);opacity:0;}}'
      // Respect reduced-motion preference
      + '@media (prefers-reduced-motion: reduce){.au-toast,.au-modal-bg,.au-modal{animation:none !important;}}';
    document.head.appendChild(s);
  }

  function ensureToastHost() {
    var host = document.querySelector('.au-toasts');
    if (host) return host;
    host = document.createElement('div');
    host.className = 'au-toasts';
    document.body.appendChild(host);
    return host;
  }

  var ICONS = { success: '✓', error: '!', warn: '!', info: 'i' };

  global.appToast = function appToast(message, opts) {
    ensureCss();
    opts = opts || {};
    var type = opts.type || 'info';
    var duration = opts.duration != null ? opts.duration : (type === 'error' ? 5500 : 3500);
    var host = ensureToastHost();
    var el = document.createElement('div');
    el.className = 'au-toast ' + type;
    var titleHtml = opts.title ? '<div class="au-title">' + escapeText(opts.title) + '</div>' : '';
    el.innerHTML =
        '<span class="au-ic">' + (ICONS[type] || 'i') + '</span>'
      + '<div class="au-body">' + titleHtml + '<div class="au-msg">' + escapeText(message) + '</div></div>'
      + '<button class="au-close" aria-label="Close">×</button>';
    host.appendChild(el);
    var killed = false;
    function dismiss() {
      if (killed) return; killed = true;
      el.classList.add('leaving');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
    }
    el.querySelector('.au-close').addEventListener('click', dismiss);
    if (duration > 0) setTimeout(dismiss, duration);
    return { dismiss: dismiss };
  };

  // Text-input modal — in-app replacement for window.prompt().
  // Resolves to the trimmed string or null on cancel/empty.
  // opts: { title, placeholder, defaultValue, okText, cancelText, description }
  global.appPrompt = function appPrompt(message, opts) {
    ensureCss();
    opts = opts || {};
    var title = opts.title || message || 'Enter a value';
    var description = opts.description || '';
    var placeholder = opts.placeholder || '';
    var defaultValue = opts.defaultValue != null ? String(opts.defaultValue) : '';
    var okText = opts.okText || 'OK';
    var cancelText = opts.cancelText || 'Cancel';
    return new Promise(function (resolve) {
      var bg = document.createElement('div');
      bg.className = 'au-modal-bg';
      bg.innerHTML =
          '<div class="au-modal" role="dialog" aria-modal="true">'
        +   '<h3>' + escapeText(title) + '</h3>'
        +   (description ? '<p>' + escapeText(description) + '</p>' : '')
        +   '<input type="text" class="au-input" value="' + escapeText(defaultValue) + '" placeholder="' + escapeText(placeholder) + '">'
        +   '<div class="au-actions" style="margin-top:14px;">'
        +     '<button class="au-btn au-btn-cancel" data-act="cancel">' + escapeText(cancelText) + '</button>'
        +     '<button class="au-btn au-btn-ok" data-act="ok">' + escapeText(okText) + '</button>'
        +   '</div>'
        + '</div>';
      document.body.appendChild(bg);
      var input = bg.querySelector('.au-input');
      var done = false;
      function close(result) {
        if (done) return; done = true;
        bg.classList.add('leaving');
        setTimeout(function () { if (bg.parentNode) bg.parentNode.removeChild(bg); }, 160);
        document.removeEventListener('keydown', onKey);
        resolve(result);
      }
      function submit() {
        var v = (input.value || '').trim();
        close(v ? v : null);
      }
      bg.addEventListener('click', function (e) { if (e.target === bg) close(null); });
      bg.querySelector('[data-act="cancel"]').addEventListener('click', function () { close(null); });
      bg.querySelector('[data-act="ok"]').addEventListener('click', submit);
      function onKey(e) {
        if (e.key === 'Escape') close(null);
        if (e.key === 'Enter')  submit();
      }
      document.addEventListener('keydown', onKey);
      setTimeout(function () { input.focus(); input.select(); }, 30);
    });
  };

  global.appConfirm = function appConfirm(message, opts) {
    ensureCss();
    opts = opts || {};
    var title = opts.title || 'Are you sure?';
    var okText = opts.okText || 'OK';
    var cancelText = opts.cancelText || 'Cancel';
    var danger = !!opts.danger;
    return new Promise(function (resolve) {
      var bg = document.createElement('div');
      bg.className = 'au-modal-bg';
      bg.innerHTML =
          '<div class="au-modal" role="dialog" aria-modal="true">'
        +   '<h3>' + escapeText(title) + '</h3>'
        +   '<p>' + escapeText(message) + '</p>'
        +   '<div class="au-actions">'
        +     '<button class="au-btn au-btn-cancel" data-act="cancel">' + escapeText(cancelText) + '</button>'
        +     '<button class="au-btn ' + (danger ? 'au-btn-danger' : 'au-btn-ok') + '" data-act="ok">' + escapeText(okText) + '</button>'
        +   '</div>'
        + '</div>';
      document.body.appendChild(bg);
      var done = false;
      function close(result) {
        if (done) return; done = true;
        bg.classList.add('leaving');
        setTimeout(function () { if (bg.parentNode) bg.parentNode.removeChild(bg); }, 160);
        resolve(result);
      }
      bg.addEventListener('click', function (e) { if (e.target === bg) close(false); });
      bg.querySelector('[data-act="cancel"]').addEventListener('click', function () { close(false); });
      bg.querySelector('[data-act="ok"]').addEventListener('click', function () { close(true); });
      function onKey(e) {
        if (e.key === 'Escape') { close(false); document.removeEventListener('keydown', onKey); }
        if (e.key === 'Enter')  { close(true);  document.removeEventListener('keydown', onKey); }
      }
      document.addEventListener('keydown', onKey);
      // Autofocus the primary action
      setTimeout(function () { bg.querySelector('[data-act="ok"]').focus(); }, 30);
    });
  };

  function escapeText(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m];
    });
  }

  // ── Brand-prefix resolution helpers ────────────────────────────────
  // Pages (Orders / Shipping / Ads) need a single answer for "which SKU
  // prefixes should I filter rows by right now?". The answer comes from
  // the topbar's active brand if one is selected, OR from the union of
  // brands that own the currently-selected stores in the Store dropdown.
  //
  //   getEffectivePrefixesAsync({ selectedStoreRawIds: [3,5] })
  //     → ['ga']                  // when store 3 belongs to Gahiz
  //     → []                      // when nothing is scoped (= show all)
  //
  // - selectedStoreRawIds: numeric connector ids of the chosen CMS stores
  //   (NOT the "c3" string ids; just the raw integers).
  global.getEffectivePrefixesAsync = async function getEffectivePrefixesAsync(opts) {
    opts = opts || {};
    var prefixes = [];
    // 1. Topbar active brand wins — most explicit signal.
    try {
      var ab = global.getActiveBrandAsync ? await global.getActiveBrandAsync() : null;
      if (ab && ab.meta && Array.isArray(ab.meta.sku_prefixes)) {
        prefixes = ab.meta.sku_prefixes.slice();
      }
    } catch (e) {}
    if (prefixes.length) return normalizePrefixes(prefixes);
    // 2. Fall back to the brands that own the currently-selected stores.
    var raw = (opts.selectedStoreRawIds || []).map(Number).filter(function(n){return n>0;});
    if (!raw.length) return [];
    try {
      var brands = global.getAllBrandsAsync ? await global.getAllBrandsAsync() : [];
      var rawSet = new Set(raw);
      brands.forEach(function (b) {
        var cms = b && b.meta && b.meta.assets && b.meta.assets.cms;
        if (!Array.isArray(cms)) return;
        var owns = cms.some(function (id) { return rawSet.has(Number(id)); });
        if (!owns) return;
        var pre = b.meta.sku_prefixes;
        if (Array.isArray(pre)) prefixes = prefixes.concat(pre);
      });
    } catch (e) {}
    return normalizePrefixes(prefixes);
  };

  // Build a word-boundary regex matching any of the given prefixes.
  // "re" matches "re-gm-001" and "(re-gm)" but NOT "career". Returns null
  // when the array is empty so callers can treat that as "no filter".
  global.buildPrefixRegex = function buildPrefixRegex(prefixes) {
    var arr = normalizePrefixes(prefixes || []);
    if (!arr.length) return null;
    var esc = arr.map(function (p) { return p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
    return new RegExp('(^|[^a-z0-9])(?:' + esc.join('|') + ')(?=[-_\\s)/]|$)', 'i');
  };

  function normalizePrefixes(arr) {
    var seen = {};
    var out = [];
    (arr || []).forEach(function (p) {
      var s = String(p == null ? '' : p).trim().toLowerCase();
      if (s && !seen[s]) { seen[s] = 1; out.push(s); }
    });
    return out;
  }
})(window);
