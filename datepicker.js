/* datepicker.js — universal Shopify-style date picker shared across the tool.
 *
 * State is persisted in localStorage["app.dateRange"] so all pages of the tool
 * read the SAME date range. Changing the range on one page broadcasts to all
 * other pages (same tab via custom event, other tabs via the storage event).
 *
 * Usage on each page:
 *
 *   <button id="myDateBtn"></button>
 *   <script src="datepicker.js"></script>
 *   <script>
 *     AppDatePicker.attach(document.getElementById('myDateBtn'), {
 *       onChange: ({preset, from, to, label}) => {
 *         // re-fetch / re-filter your data here
 *       }
 *     });
 *   </script>
 *
 * Public API:
 *   AppDatePicker.attach(triggerEl, {onChange})  → returns {detach()}
 *   AppDatePicker.getRange()                     → {preset, from, to, label}
 *   AppDatePicker.setRange(preset, from?, to?)   → also broadcasts
 *   AppDatePicker.subscribe(cb)                  → returns unsubscribe()
 */
(function (global) {
  'use strict';

  /* ── CONSTANTS ─────────────────────────────────────────────────── */
  var STORAGE_KEY = 'app.dateRange';
  var EVENT_NAME  = 'app:dateChanged';

  var PRESETS = [
    { v: 'today',     l: 'Today' },
    { v: 'yesterday', l: 'Yesterday' },
    { v: 'last7',     l: 'Last 7 days' },
    { v: 'last14',    l: 'Last 14 days' },
    { v: 'last30',    l: 'Last 30 days' },
    { v: 'thisweek',  l: 'This week' },
    { v: 'lastweek',  l: 'Last week' },
    { v: 'thismonth', l: 'This month' },
    { v: 'lastmonth', l: 'Last month' },
    { v: 'thisyear',  l: 'This year' },
    { v: 'lastyear',  l: 'Last year' },
    { v: 'all',       l: 'Maximum' },
    { v: 'custom',    l: 'Custom' }
  ];

  var MN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var MN_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var DN = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

  /* ── DATE HELPERS — match the dashboard's helpers exactly ──────── */
  function toYMD(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function formatDisplayDate(ymd) {
    if (!ymd) return '';
    var d = new Date(ymd + 'T00:00:00');
    return d.getDate() + ' ' + MN_SHORT[d.getMonth()] + ' ' + d.getFullYear();
  }
  function parseDate(val) {
    if (!val) return new Date(NaN);
    var s = String(val).trim();
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return new Date(s + 'T00:00:00');
    var dmy = s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/);
    if (dmy) return new Date(dmy[3] + '-' + dmy[2].padStart(2, '0') + '-' + dmy[1].padStart(2, '0') + 'T00:00:00');
    return new Date(s);
  }
  // legacy aliases used internally
  function pad(n) { return String(n).padStart(2, '0'); }
  function ymd(d) { return toYMD(d); }
  function parseYMD(s) { if (!s) return null; var d = parseDate(s); return isNaN(d) ? null : d; }
  function fmtDisplay(s) { return formatDisplayDate(s); }
  function sameDay(a, b) {
    if (!a || !b) return false;
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }
  function getPresetRange(v) {
    var t = new Date(); t.setHours(0, 0, 0, 0);
    var sub = function (n) { var d = new Date(t); d.setDate(t.getDate() - n); return d; };
    switch (v) {
      case 'today':     return { from: ymd(t), to: ymd(t) };
      case 'yesterday': { var y = sub(1); return { from: ymd(y), to: ymd(y) }; }
      case 'last7':     return { from: ymd(sub(6)),  to: ymd(t) };
      case 'last14':    return { from: ymd(sub(13)), to: ymd(t) };
      case 'last30':    return { from: ymd(sub(29)), to: ymd(t) };
      case 'thisweek':  { var d = new Date(t); d.setDate(t.getDate() - t.getDay()); return { from: ymd(d), to: ymd(t) }; }
      case 'lastweek':  { var e = new Date(t); e.setDate(t.getDate() - t.getDay() - 1); var s = new Date(e); s.setDate(e.getDate() - 6); return { from: ymd(s), to: ymd(e) }; }
      case 'thismonth': return { from: ymd(new Date(t.getFullYear(), t.getMonth(), 1)), to: ymd(t) };
      case 'lastmonth': { var le = new Date(t.getFullYear(), t.getMonth(), 0); var ls = new Date(t.getFullYear(), t.getMonth() - 1, 1); return { from: ymd(ls), to: ymd(le) }; }
      case 'thisyear':  return { from: t.getFullYear() + '-01-01', to: ymd(t) };
      case 'lastyear':  { var ly = t.getFullYear() - 1; return { from: ly + '-01-01', to: ly + '-12-31' }; }
      case 'all':       return { from: '', to: '' };
      default:          return null;
    }
  }
  function presetFromRange(from, to) {
    // Try to map a from/to pair back to a preset for display
    if (!from && !to) return 'all';
    for (var i = 0; i < PRESETS.length; i++) {
      var p = PRESETS[i];
      if (p.v === 'custom' || p.v === 'all') continue;
      var r = getPresetRange(p.v);
      if (r && r.from === from && r.to === to) return p.v;
    }
    return 'custom';
  }
  function buildLabel(state) {
    if (!state) return 'Maximum';
    if (state.preset === 'all') return 'Maximum';
    var p = PRESETS.find(function (x) { return x.v === state.preset; });
    var pl = p ? p.l : 'Custom';
    if (state.from && state.to) {
      if (state.from === state.to) return pl + ': ' + fmtDisplay(state.from);
      return pl + ': ' + fmtDisplay(state.from) + ' - ' + fmtDisplay(state.to);
    }
    return pl;
  }

  /* ── STATE (localStorage) ──────────────────────────────────────── */
  // The whole tool always starts on "Today". This runs ONCE per page load —
  // forcing Today into localStorage as the page boots, so every section opens
  // on the current day. Within the same session, manual changes the user makes
  // still propagate across already-open tabs via the storage event.
  function defaultState() {
    var r = getPresetRange('today');
    var s = { preset: 'today', from: r.from, to: r.to };
    s.label = buildLabel(s);
    return s;
  }
  (function forceTodayOnInit() {
    try {
      var d = defaultState();
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ preset: d.preset, from: d.from, to: d.to }));
    } catch (e) {}
  })();
  function loadState() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        var s = JSON.parse(raw);
        if (s && typeof s === 'object') {
          if (!s.preset) s.preset = 'today';
          if (!s.from)   s.from   = '';
          if (!s.to)     s.to     = '';
          // For "today" / "yesterday" / "last7" etc. — the absolute dates roll over
          // each day. Recompute them on every load so the range is always fresh.
          if (s.preset && s.preset !== 'custom' && s.preset !== 'all') {
            var fresh = getPresetRange(s.preset);
            if (fresh) { s.from = fresh.from; s.to = fresh.to; }
          }
          s.label = buildLabel(s);
          return s;
        }
      }
    } catch (e) {}
    return defaultState();
  }
  function saveState(state) {
    state.label = buildLabel(state);
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ preset: state.preset, from: state.from, to: state.to }));
    notify(state);
  }
  var listeners = [];
  function notify(state) {
    listeners.slice().forEach(function (cb) { try { cb(state); } catch (e) { console.error(e); } });
    try { window.dispatchEvent(new CustomEvent(EVENT_NAME, { detail: state })); } catch (e) {}
  }
  // Cross-tab sync: when storage changes in another tab, broadcast locally
  window.addEventListener('storage', function (ev) {
    if (ev.key === STORAGE_KEY) {
      var s = loadState();
      listeners.slice().forEach(function (cb) { try { cb(s); } catch (e) {} });
      window.dispatchEvent(new CustomEvent(EVENT_NAME, { detail: s }));
    }
  });

  /* ── CSS INJECTION (once) ──────────────────────────────────────── */
  var CSS_INJECTED = false;
  function injectCSS() {
    if (CSS_INJECTED) return;
    CSS_INJECTED = true;
    var css = ''
      + '.adp-trigger{display:inline-flex;align-items:center;gap:8px;background:#FFFFFF;border:1px solid #DCDCDC;border-radius:8px;padding:0 12px;height:34px;font-size:13px;font-weight:600;color:#1A1A1A;cursor:pointer;white-space:nowrap;outline:none;box-shadow:0 1px 0 rgba(0,0,0,0.04);transition:all .15s;font-family:inherit;}'
      + '.adp-trigger:hover{background:#F7F7F7;}'
      + '.adp-trigger.adp-open{background:#F7F7F7;border-color:#BBB;}'
      + '.adp-trigger .adp-tic{width:16px;height:16px;color:#1A1A1A;flex-shrink:0;display:block;}'
      + '.adp-trigger .adp-tlabel{font-weight:600;color:#1A1A1A;}'
      + '.adp-trigger .adp-tcaret{font-size:9px;opacity:.7;margin-left:4px;color:#1A1A1A;}'

      + '.adp-pop{position:fixed;z-index:1000;background:#FFFFFF;border:1px solid #E1E3E5;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,0.18),0 0 0 1px rgba(0,0,0,0.04);overflow:hidden;display:none;font-family:Inter,system-ui,Segoe UI,Roboto,sans-serif;}'
      + '.adp-pop.adp-open{display:block;}'
      + '.adp-grid{display:grid;grid-template-columns:180px 1fr;}'
      + '.adp-presets{border-right:1px solid #EEF2F7;padding:8px 0;max-height:380px;overflow-y:auto;}'
      + '.adp-preset{display:flex;align-items:center;gap:10px;padding:9px 16px;cursor:pointer;font-size:13px;color:#1A1A1A;background:transparent;border:none;width:100%;text-align:left;font-family:inherit;}'
      + '.adp-preset:hover{background:#F5F5F5;}'
      + '.adp-preset.active{background:#F1F1F1;font-weight:600;color:#1A1A1A;}'
      + '.adp-radio{width:14px;height:14px;border-radius:50%;border:1.5px solid #CBD5E1;flex-shrink:0;display:flex;align-items:center;justify-content:center;}'
      + '.adp-preset.active .adp-radio{border:2px solid #1A1A1A;background:#1A1A1A;}'
      + '.adp-preset.active .adp-radio::after{content:"";width:5px;height:5px;border-radius:50%;background:#FFFFFF;}'

      + '.adp-cals{display:flex;padding:14px 18px;gap:18px;}'
      + '.adp-cal{width:240px;}'
      + '.adp-cal-h{display:flex;align-items:center;justify-content:space-between;height:28px;margin-bottom:8px;}'
      + '.adp-cal-h .adp-cal-t{font-size:14px;font-weight:700;color:#1A1A1A;}'
      + '.adp-nav{width:28px;height:28px;border:none;background:transparent;border-radius:6px;cursor:pointer;color:#606770;font-size:18px;display:flex;align-items:center;justify-content:center;line-height:1;}'
      + '.adp-nav:hover{background:#F1F1F1;}'
      + '.adp-nav.adp-hide{visibility:hidden;}'
      + '.adp-wd{display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:4px;}'
      + '.adp-wd>div{text-align:center;font-size:11px;color:#8A8D91;font-weight:600;padding:6px 0;}'
      + '.adp-days{display:grid;grid-template-columns:repeat(7,1fr);gap:0;}'
      + '.adp-day{position:relative;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;color:#1A1A1A;user-select:none;}'
      + '.adp-day.adp-empty{cursor:default;}'
      + '.adp-day .adp-bg{position:absolute;top:2px;bottom:2px;left:0;right:0;background:#EBEBEB;z-index:0;pointer-events:none;}'
      + '.adp-day .adp-bg.l{left:50%;}.adp-day .adp-bg.r{right:50%;}'
      + '.adp-day .adp-num{position:relative;z-index:1;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:50%;pointer-events:none;}'
      + '.adp-day.sel .adp-num{background:#1A1A1A;color:#FFFFFF;font-weight:700;}'
      + '.adp-day.in-range .adp-num{color:#1A1A1A;font-weight:600;}'
      + '.adp-day.today:not(.sel) .adp-num{outline:1.5px solid #1A1A1A;outline-offset:-1.5px;}'
      + '.adp-day:hover:not(.adp-empty):not(.sel) .adp-num{background:#F1F1F1;}'

      + '.adp-foot{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#FAFAFA;border-top:1px solid #EEF2F7;}'
      + '.adp-foot-l{font-size:13px;font-weight:600;color:#1A1A1A;}'
      + '.adp-foot-tz{font-size:11px;color:#9CA3AF;margin-top:2px;}'
      + '.adp-foot-btns{display:flex;gap:8px;}'
      + '.adp-btn{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;border:none;}'
      + '.adp-btn-cancel{background:#FFFFFF;border:1px solid #D1D5DB;color:#1A1A1A;}'
      + '.adp-btn-cancel:hover{background:#F1F1F1;}'
      + '.adp-btn-update{background:#1A1A1A;color:#FFFFFF;}'
      + '.adp-btn-update:hover{background:#000000;}'
      + '.adp-btn-update:disabled{background:#C4C4C4;cursor:default;}'

      + '@media (max-width: 720px){.adp-grid{grid-template-columns:1fr;}.adp-presets{max-height:none;border-right:none;border-bottom:1px solid #EEF2F7;display:flex;flex-wrap:wrap;padding:8px;}.adp-preset{width:auto;padding:6px 10px;border-radius:14px;}.adp-cals{flex-direction:column;align-items:center;}}'
      ;
    var s = document.createElement('style');
    s.id = 'adp-style';
    s.textContent = css;
    document.head.appendChild(s);
  }

  /* ── POPOVER (single instance, reused) ─────────────────────────── */
  var popoverEl = null;
  var popoverState = null; // current temp state of an open popover
  var popoverHost = null;  // trigger element that opened it
  var hoverDate = null;
  var leftDate = null;     // Date object representing the left calendar's first day

  function buildPopover() {
    if (popoverEl) return popoverEl;
    popoverEl = document.createElement('div');
    popoverEl.className = 'adp-pop';
    popoverEl.innerHTML =
      '<div class="adp-grid">'
        + '<div class="adp-presets" data-presets></div>'
        + '<div class="adp-cals" data-cals></div>'
      + '</div>'
      + '<div class="adp-foot">'
        + '<div><div class="adp-foot-l" data-rangelbl></div><div class="adp-foot-tz">Dates shown in Cairo Time</div></div>'
        + '<div class="adp-foot-btns">'
          + '<button class="adp-btn adp-btn-cancel" data-cancel>Cancel</button>'
          + '<button class="adp-btn adp-btn-update" data-update>Update</button>'
        + '</div>'
      + '</div>';
    document.body.appendChild(popoverEl);

    // dismiss on outside click / Escape — uses CAPTURE phase so this check runs
    // BEFORE our popoverEl handler mutates the DOM (otherwise e.target would be a
    // detached node and popoverEl.contains() would wrongly return false, closing
    // the popover after the first day click).
    document.addEventListener('mousedown', function (e) {
      if (!popoverEl.classList.contains('adp-open')) return;
      if (popoverEl.contains(e.target) || (popoverHost && popoverHost.contains(e.target))) return;
      closePopover();
    }, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePopover(); });

    // wire footer
    popoverEl.querySelector('[data-cancel]').addEventListener('click', closePopover);
    popoverEl.querySelector('[data-update]').addEventListener('click', applyPopover);

    // Event delegation on the popover root — survives re-renders.
    // Uses mousedown so the click fires before the next mouseenter/render race.
    popoverEl.addEventListener('mousedown', function (e) {
      var pBtn = e.target.closest && e.target.closest('[data-pv]');
      if (pBtn && popoverEl.contains(pBtn)) { e.preventDefault(); pickPreset(pBtn.dataset.pv); return; }
      var nav = e.target.closest && e.target.closest('[data-prev],[data-next]');
      if (nav && popoverEl.contains(nav)) {
        e.preventDefault();
        var dir = nav.hasAttribute('data-prev') ? -1 : 1;
        leftDate = new Date(leftDate.getFullYear(), leftDate.getMonth() + dir, 1);
        renderPopover();
        return;
      }
      var dayEl = e.target.closest && e.target.closest('[data-d]');
      if (dayEl && popoverEl.contains(dayEl)) {
        e.preventDefault();
        clickDay(dayEl.dataset.d);
      }
    });
    // Hover preview on mousemove (single delegated listener — no per-cell bindings).
    popoverEl.addEventListener('mouseover', function (e) {
      if (!popoverState || !popoverState.from || popoverState.to) return;
      var dayEl = e.target.closest && e.target.closest('[data-d]');
      if (!dayEl) return;
      if (hoverDate === dayEl.dataset.d) return;
      hoverDate = dayEl.dataset.d;
      // Update only the hover preview (cheap re-render of cells, no event re-binding needed).
      renderPopover();
    });
    popoverEl.addEventListener('mouseleave', function () {
      if (hoverDate) { hoverDate = null; renderPopover(); }
    });

    return popoverEl;
  }

  function openPopover(triggerEl) {
    buildPopover();
    popoverHost = triggerEl;
    var s = loadState();
    popoverState = { preset: s.preset, from: s.from, to: s.to };
    hoverDate = null;
    // initialize left calendar to first month of range or current month
    var seed = parseYMD(s.from) || new Date();
    leftDate = new Date(seed.getFullYear(), seed.getMonth(), 1);
    renderPopover();
    // Position
    var r = triggerEl.getBoundingClientRect();
    popoverEl.classList.add('adp-open');
    var pw = popoverEl.offsetWidth, ph = popoverEl.offsetHeight;
    var left = Math.max(8, Math.min(window.innerWidth - pw - 8, r.right - pw));
    var top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
    popoverEl.style.left = left + 'px';
    popoverEl.style.top = top + 'px';
    triggerEl.classList.add('adp-open');
  }

  function closePopover() {
    if (!popoverEl) return;
    popoverEl.classList.remove('adp-open');
    if (popoverHost) popoverHost.classList.remove('adp-open');
    popoverHost = null;
    popoverState = null;
  }

  function applyPopover() {
    if (!popoverState) return;
    if (popoverState.preset !== 'custom' && popoverState.preset !== 'all') {
      var r = getPresetRange(popoverState.preset);
      if (r) { popoverState.from = r.from; popoverState.to = r.to; }
    } else if (popoverState.preset === 'all') {
      popoverState.from = ''; popoverState.to = '';
    } else {
      // custom — require both
      if (!popoverState.from || !popoverState.to) return;
    }
    saveState({ preset: popoverState.preset, from: popoverState.from, to: popoverState.to });
    closePopover();
    refreshAllTriggers();
  }

  function pickPreset(v) {
    popoverState.preset = v;
    if (v !== 'custom' && v !== 'all') {
      var r = getPresetRange(v);
      if (r) {
        popoverState.from = r.from;
        popoverState.to   = r.to;
        var d = parseYMD(r.from || r.to);
        if (d) leftDate = new Date(d.getFullYear(), d.getMonth(), 1);
      }
    } else if (v === 'all') {
      popoverState.from = ''; popoverState.to = '';
    }
    renderPopover();
  }

  function clickDay(dStr) {
    popoverState.preset = 'custom';
    if (!popoverState.from || (popoverState.from && popoverState.to)) {
      popoverState.from = dStr; popoverState.to = '';
    } else {
      if (dStr < popoverState.from) {
        popoverState.to = popoverState.from;
        popoverState.from = dStr;
      } else {
        popoverState.to = dStr;
      }
    }
    renderPopover();
  }

  function renderPopover() {
    // presets
    var pres = popoverEl.querySelector('[data-presets]');
    pres.innerHTML = PRESETS.map(function (p) {
      return '<button class="adp-preset' + (popoverState.preset === p.v ? ' active' : '') + '" data-pv="' + p.v + '">'
           + '<span class="adp-radio"></span><span>' + p.l + '</span></button>';
    }).join('');

    // calendars (2 months)
    var cals = popoverEl.querySelector('[data-cals]');
    var ly = leftDate.getFullYear(), lm = leftDate.getMonth();
    var ry = lm === 11 ? ly + 1 : ly, rm = lm === 11 ? 0 : lm + 1;
    cals.innerHTML = renderCal(ly, lm, true, false) + renderCal(ry, rm, false, true);

    // footer
    var lbl = popoverEl.querySelector('[data-rangelbl]');
    if (popoverState.preset === 'all') lbl.textContent = 'All time';
    else if (popoverState.from && popoverState.to) lbl.textContent = fmtDisplay(popoverState.from) + ' – ' + fmtDisplay(popoverState.to);
    else if (popoverState.from) lbl.textContent = fmtDisplay(popoverState.from) + ' – …';
    else lbl.textContent = 'Select a date range';

    var btn = popoverEl.querySelector('[data-update]');
    btn.disabled = !(popoverState.preset !== 'custom' || (popoverState.from && popoverState.to));
  }

  function renderCal(year, month, showPrev, showNext) {
    var firstDOW = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var todayStr = ymd(new Date());
    var fS = popoverState.from, tS = popoverState.to;
    // hover preview
    if (fS && !tS && hoverDate) {
      if (hoverDate < fS) { tS = fS; fS = hoverDate; } else { tS = hoverDate; }
    }
    var html = ''
      + '<div class="adp-cal">'
        + '<div class="adp-cal-h">'
          + '<button class="adp-nav' + (showPrev ? '' : ' adp-hide') + '" data-prev>‹</button>'
          + '<div class="adp-cal-t">' + MN[month] + ' ' + year + '</div>'
          + '<button class="adp-nav' + (showNext ? '' : ' adp-hide') + '" data-next>›</button>'
        + '</div>'
        + '<div class="adp-wd">' + DN.map(function (d) { return '<div>' + d + '</div>'; }).join('') + '</div>'
        + '<div class="adp-days">';
    for (var i = 0; i < firstDOW; i++) html += '<div class="adp-day adp-empty"></div>';
    for (var d = 1; d <= daysInMonth; d++) {
      var ds = year + '-' + pad(month + 1) + '-' + pad(d);
      var isStart = ds === fS, isEnd = ds === tS, isSingle = isStart && isEnd;
      var inRange = fS && tS && !isSingle && ds > fS && ds < tS;
      var sel = isStart || isEnd;
      var cls = ['adp-day'];
      if (sel) cls.push('sel');
      if (inRange) cls.push('in-range');
      if (ds === todayStr) cls.push('today');
      var bg = '';
      if (inRange) bg = '<div class="adp-bg"></div>';
      else if (isStart && tS && tS !== fS) bg = '<div class="adp-bg l"></div>';
      else if (isEnd && fS && tS !== fS) bg = '<div class="adp-bg r"></div>';
      html += '<div class="' + cls.join(' ') + '" data-d="' + ds + '">' + bg + '<div class="adp-num">' + d + '</div></div>';
    }
    var total = firstDOW + daysInMonth, pad2 = (7 - total % 7) % 7;
    for (var j = 0; j < pad2; j++) html += '<div class="adp-day adp-empty"></div>';
    html += '</div></div>';
    return html;
  }

  /* ── ATTACH ────────────────────────────────────────────────────── */
  var attachedTriggers = [];
  function refreshTrigger(el) {
    var s = loadState();
    var lbl = el.querySelector('.adp-tlabel');
    if (lbl) lbl.textContent = s.label;
    el.dataset.adpLabel = s.label;
  }
  function refreshAllTriggers() {
    attachedTriggers.forEach(refreshTrigger);
  }

  function attach(triggerEl, opts) {
    if (!triggerEl) return;
    if (triggerEl.dataset.adpAttached === '1') return;
    triggerEl.dataset.adpAttached = '1';
    injectCSS();
    opts = opts || {};

    // Convert button into our styled trigger
    triggerEl.classList.add('adp-trigger');
    triggerEl.type = 'button';
    triggerEl.innerHTML =
        '<svg class="adp-tic" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
          + '<rect x="2.75" y="4.5" width="14.5" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/>'
          + '<path d="M2.75 8.5h14.5" stroke="currentColor" stroke-width="1.4"/>'
          + '<path d="M6.5 2.5v3M13.5 2.5v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
          + '<rect x="6" y="11" width="2.2" height="2.2" rx="0.4" fill="currentColor"/>'
        + '</svg>'
      + '<span class="adp-tlabel"></span>'
      + '<span class="adp-tcaret">▼</span>';
    refreshTrigger(triggerEl);
    attachedTriggers.push(triggerEl);

    triggerEl.addEventListener('click', function (e) {
      e.stopPropagation();
      if (popoverEl && popoverEl.classList.contains('adp-open') && popoverHost === triggerEl) closePopover();
      else openPopover(triggerEl);
    });

    if (typeof opts.onChange === 'function') {
      var unsub = subscribe(opts.onChange);
      // fire once with current state so the page hydrates from localStorage
      try { opts.onChange(loadState()); } catch (e) { console.error(e); }
      return {
        detach: function () {
          unsub();
          var i = attachedTriggers.indexOf(triggerEl);
          if (i >= 0) attachedTriggers.splice(i, 1);
        }
      };
    }
    return {
      detach: function () {
        var i = attachedTriggers.indexOf(triggerEl);
        if (i >= 0) attachedTriggers.splice(i, 1);
      }
    };
  }

  function subscribe(cb) { listeners.push(cb); return function () { var i = listeners.indexOf(cb); if (i >= 0) listeners.splice(i, 1); }; }
  function getRange() { return loadState(); }
  function setRange(preset, from, to) {
    var s = { preset: preset || 'custom', from: from || '', to: to || '' };
    if (s.preset !== 'custom' && s.preset !== 'all') {
      var r = getPresetRange(s.preset);
      if (r) { s.from = r.from; s.to = r.to; }
    }
    saveState(s);
    refreshAllTriggers();
  }

  global.AppDatePicker = {
    attach: attach,
    subscribe: subscribe,
    getRange: getRange,
    setRange: setRange,
    PRESETS: PRESETS,
    getPresetRange: getPresetRange,
    // re-exposed helpers (same semantics as the dashboard's originals)
    toYMD: toYMD,
    formatDisplayDate: formatDisplayDate,
    parseDate: parseDate
  };
})(window);
