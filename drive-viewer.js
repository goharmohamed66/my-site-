// drive-viewer.js — Shared embedded Drive viewer.
// Opens any Google Drive file (Sheet / Doc / Slides / image / pdf / folder)
// inside a full-screen modal that uses Google's own UI via /edit?embedded=true
// or /preview. The user never leaves the dashboard.
//
// Usage from any page:
//   openDriveViewer({ id: '1Abc…', name: 'Financial Modeling', mimeType: '…' });
// or quickly with just the URL string from Drive:
//   openDriveViewer({ url: 'https://docs.google.com/spreadsheets/d/...' });
(function (global) {
  if (global.openDriveViewer) return; // single registration

  // Inject styles once
  function ensureStyles(){
    if (document.getElementById('drive-viewer-styles')) return;
    var css = ''
      // Full-screen overlay starting RIGHT BELOW the topbar (48px tall) so the
      // user still feels the app shell. No outer dim — viewer fills the
      // available canvas as if it were a regular page.
      + '.dv-backdrop{position:fixed;left:0;right:0;top:48px;bottom:0;background:#F1F1F1;z-index:9998;display:none;flex-direction:column;animation:dv-fade .15s;}'
      + '.dv-backdrop.open{display:flex;}'
      + '@keyframes dv-fade{from{opacity:0}to{opacity:1}}'
      + '.dv-shell{flex:1;display:flex;flex-direction:column;background:#FFFFFF;border-top:1px solid #E1E3E5;overflow:hidden;}'
      + '.dv-head{display:flex;align-items:center;justify-content:space-between;padding:6px 14px;border-bottom:1px solid #E1E3E5;background:#FFFFFF;flex-shrink:0;height:36px;}'
      + '.dv-head .dv-title{display:flex;align-items:center;gap:8px;min-width:0;flex:1;}'
      + '.dv-head .dv-icon{font-size:14px;flex-shrink:0;}'
      + '.dv-head .dv-name{font-size:12px;font-weight:600;color:#1A1A1A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.dv-head .dv-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;}'
      + '.dv-head .dv-btn{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid #DCDCDC;border-radius:6px;background:#FFFFFF;cursor:pointer;font-size:11px;font-weight:600;color:#1A1A1A;font-family:inherit;text-decoration:none;}'
      + '.dv-head .dv-btn:hover{background:#FAFAFA;border-color:#1A1A1A;}'
      + '.dv-head .dv-close{background:none;border:none;cursor:pointer;font-size:16px;color:#6D7175;padding:2px 8px;border-radius:6px;line-height:1;font-family:inherit;}'
      + '.dv-head .dv-close:hover{background:#F1F1F1;color:#1A1A1A;}'
      + '.dv-body{flex:1;background:#FFFFFF;position:relative;min-height:0;}'
      + '.dv-iframe{width:100%;height:100%;border:0;display:block;background:#FFFFFF;}'
      + '.dv-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#6D7175;font-size:13px;background:#FFFFFF;}'
      + '.dv-loading .dv-spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(0,0,0,0.15);border-top-color:#1A1A1A;border-radius:50%;animation:dv-rot .7s linear infinite;margin-right:8px;}'
      + '@keyframes dv-rot{to{transform:rotate(360deg)}}'
      + '.dv-img{max-width:100%;max-height:100%;display:block;margin:auto;object-fit:contain;}'
      // Hide page scrollbars while viewer is open — the iframe handles its own
      + 'body.dv-open{overflow:hidden;}';
    var s = document.createElement('style');
    s.id = 'drive-viewer-styles';
    s.textContent = css;
    document.head.appendChild(s);
  }

  // Build the embed URL based on mime type / file id.
  function embedUrlFor(file){
    var id = file.id;
    var mime = file.mimeType || '';
    // Google native types — use /edit?embedded=true so the user can edit
    // inline (Drive UI handles auth via cookies).
    if (mime === 'application/vnd.google-apps.spreadsheet' || /spreadsheet/i.test(mime)) {
      return 'https://docs.google.com/spreadsheets/d/' + id + '/edit?embedded=true&rm=demo';
    }
    if (mime === 'application/vnd.google-apps.document' || /document/i.test(mime)) {
      return 'https://docs.google.com/document/d/' + id + '/edit?embedded=true&rm=demo';
    }
    if (mime === 'application/vnd.google-apps.presentation' || /presentation|slides/i.test(mime)) {
      return 'https://docs.google.com/presentation/d/' + id + '/edit?embedded=true&rm=demo';
    }
    if (mime === 'application/vnd.google-apps.folder') {
      return 'https://drive.google.com/embeddedfolderview?id=' + id + '#list';
    }
    if (mime.indexOf('image/') === 0) {
      // Images are rendered via <img> below (better quality than embed)
      return null;
    }
    if (mime === 'application/pdf') {
      return 'https://drive.google.com/file/d/' + id + '/preview';
    }
    // Generic Drive file preview (works for most files)
    return 'https://drive.google.com/file/d/' + id + '/preview';
  }

  function openInDriveUrl(file){
    var id = file.id;
    var mime = file.mimeType || '';
    if (/spreadsheet/i.test(mime)) return 'https://docs.google.com/spreadsheets/d/' + id + '/edit';
    if (/document/i.test(mime))    return 'https://docs.google.com/document/d/' + id + '/edit';
    if (/presentation|slides/i.test(mime)) return 'https://docs.google.com/presentation/d/' + id + '/edit';
    if (mime === 'application/vnd.google-apps.folder') return 'https://drive.google.com/drive/folders/' + id;
    return 'https://drive.google.com/file/d/' + id + '/view';
  }

  function iconFor(mime, name){
    if (mime && mime.indexOf('image/') === 0) return '🖼';
    if (/video/.test(mime || '')) return '🎬';
    if (/spreadsheet/.test(mime || '')) return '📊';
    if (/document/.test(mime || '')) return '📝';
    if (/presentation|slides/.test(mime || '')) return '📽';
    if (mime === 'application/vnd.google-apps.folder') return '📁';
    if (mime === 'application/pdf') return '📕';
    return '📄';
  }

  function escapeHtml(s){return String(s == null ? '' : s).replace(/[&<>"']/g, function(m){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);});}

  function close(){
    var bd = document.getElementById('dv-backdrop');
    if (bd) bd.classList.remove('open');
    document.body.classList.remove('dv-open');
    _currentFile = null;
  }

  // Track the currently-open file so we can switch to the equivalent file
  // in another brand when the user flips brands from the topbar.
  var _currentFile = null;

  // Strip a trailing parenthesized suffix like " - (Old Home)" or "(3andak)"
  // from a sheet/file name so the same logical sheet across brands maps to
  // the same key. Examples:
  //   "Financial Modeling ( Monetization)- (Old Home)" → "Financial Modeling ( Monetization)"
  //   "Competitive Analysis (Rehla)"                   → "Competitive Analysis"
  //   "S5_Benchmarking & Forecasting - (X)"            → "S5_Benchmarking & Forecasting"
  function stripBrandSuffix(name){
    var s = String(name == null ? '' : name).trim();
    // Drop ONE trailing "(...)" chunk and any preceding " - " separator.
    s = s.replace(/\s*[-–—]?\s*\([^()]*\)\s*$/, '').trim();
    return s.toLowerCase().replace(/\s+/g, ' ');
  }

  // Find the best linked sheet in `brand` whose name matches `name`
  // after stripping brand suffixes. Returns the sheet object or null.
  function findEquivalentSheet(brand, name){
    var sheets = (brand && brand.meta && brand.meta.linked_sheets) || [];
    if (!sheets.length || !name) return null;
    var key = stripBrandSuffix(name);
    if (!key) return null;
    // Exact stripped match wins. Fall back to substring containment so
    // small variations (extra spaces / different separator) still map.
    var exact = sheets.find(function(s){ return stripBrandSuffix(s.name) === key; });
    if (exact) return exact;
    return sheets.find(function(s){
      var k = stripBrandSuffix(s.name);
      return k && (k.indexOf(key) !== -1 || key.indexOf(k) !== -1);
    }) || null;
  }

  // Wire the brand-change listener once. When the viewer is open and the
  // user picks a different brand from the topbar, look up the same
  // logical file in the new brand's linked_sheets and re-render the
  // iframe with that file id. Folders are left alone (no per-brand
  // folder map yet) — viewer just stays on the current folder.
  if (!global.__dvBrandWired) {
    global.__dvBrandWired = true;
    window.addEventListener('app:brandChanged', async function(){
      if (!_currentFile) return;
      var bd = document.getElementById('dv-backdrop');
      if (!bd || !bd.classList.contains('open')) return;
      // Folders skipped — we don't track per-brand folder equivalents.
      if (_currentFile.mimeType === 'application/vnd.google-apps.folder') return;
      try {
        var brand = global.getActiveBrandAsync ? await global.getActiveBrandAsync() : null;
        if (!brand) return; // "All brands" → leave file as-is
        var match = findEquivalentSheet(brand, _currentFile.name);
        if (!match) return;
        // Reopen with the new brand's equivalent. openDriveViewer is
        // idempotent — same backdrop, swapped iframe + title.
        global.openDriveViewer({ id: match.id, name: match.name, mimeType: match.mimeType });
      } catch (e) { /* swallow — viewer stays on the previous file */ }
    });
  }

  global.openDriveViewer = function openDriveViewer(file){
    if (!file) return;
    // Allow passing { url } as a shortcut: parse out the id
    if (file.url && !file.id) {
      var m = file.url.match(/\/d\/([a-zA-Z0-9_-]+)/) || file.url.match(/[?&]id=([a-zA-Z0-9_-]+)/);
      if (m) file.id = m[1];
      if (/spreadsheets/.test(file.url)) file.mimeType = 'application/vnd.google-apps.spreadsheet';
      else if (/\/document/.test(file.url)) file.mimeType = 'application/vnd.google-apps.document';
      else if (/presentation/.test(file.url)) file.mimeType = 'application/vnd.google-apps.presentation';
      else if (/folders/.test(file.url)) file.mimeType = 'application/vnd.google-apps.folder';
    }
    if (!file.id) { console.warn('openDriveViewer: missing id'); return; }
    // Remember what's open so the topbar brand-switch listener can swap
    // to the equivalent file in the newly-selected brand.
    _currentFile = { id: file.id, name: file.name || '', mimeType: file.mimeType || '' };
    ensureStyles();

    var bd = document.getElementById('dv-backdrop');
    if (!bd) {
      bd = document.createElement('div');
      bd.id = 'dv-backdrop';
      bd.className = 'dv-backdrop';
      bd.innerHTML = ''
        + '<div class="dv-shell">'
        +   '<div class="dv-head">'
        +     '<div class="dv-title"><span class="dv-icon" id="dv-icon"></span><span class="dv-name" id="dv-name"></span></div>'
        +     '<div class="dv-actions">'
        +       '<button class="dv-close" id="dv-close" title="Close">✕</button>'
        +     '</div>'
        +   '</div>'
        +   '<div class="dv-body" id="dv-body"></div>'
        + '</div>';
      document.body.appendChild(bd);
      bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
      document.getElementById('dv-close').addEventListener('click', close);
    }

    document.getElementById('dv-icon').textContent = iconFor(file.mimeType, file.name);
    document.getElementById('dv-name').textContent = file.name || 'Untitled';

    var body = document.getElementById('dv-body');
    var url = embedUrlFor(file);
    if ((file.mimeType || '').indexOf('image/') === 0) {
      // Use Drive's CDN-served image for higher quality than the iframe
      body.innerHTML = '<div style="background:#1A1A1A;width:100%;height:100%;display:flex;align-items:center;justify-content:center;padding:24px;">'
        + '<img class="dv-img" src="https://drive.google.com/thumbnail?id=' + encodeURIComponent(file.id) + '&sz=w2400">'
        + '</div>';
    } else if (url) {
      body.innerHTML = '<div class="dv-loading"><span class="dv-spin"></span> Loading…</div>'
        + '<iframe class="dv-iframe" src="' + url + '" allowfullscreen></iframe>';
      // Strip the loading state once the iframe fires load
      var ifr = body.querySelector('iframe');
      ifr.addEventListener('load', function(){ var l = body.querySelector('.dv-loading'); if (l) l.remove(); });
    } else {
      body.innerHTML = '<div class="dv-loading">Cannot preview this file type.</div>';
    }
    bd.classList.add('open');
    document.body.classList.add('dv-open');
  };
  global.closeDriveViewer = close;
})(window);
