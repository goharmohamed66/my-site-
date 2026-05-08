// google-picker.js — Custom Drive folder browser (no Google Picker SDK).
//
// Why not Google Picker?
//  - Picker needs a public origin & signed app → won't work in localhost preview
//  - Picker shows the user's ENTIRE Drive (no permission scoping)
//
// What this does instead:
//  - Pops a small modal listing the subfolders inside the connector's allowed
//    root folder (configured per connector, defaults to GOOGLE_DEFAULT_ROOT_FOLDER)
//  - User can drill into nested subfolders with breadcrumbs, but cannot escape
//    the root → permission scoping enforced server- and client-side
//  - On pick, downloads every file in the chosen folder and hands a File[]
//    to the caller (same shape as <input webkitdirectory>)
//
// Public API:
//   await pickGoogleDriveFolder(filesCb, { onProgress, onError });
(function (global) {
  const API_BASE  = 'https://indigo-dog-836598.hostingersite.com/api';
  const API_TOKEN = 'tk_4d2b9f7a8c6e1530a4f2d9b7e8c6a1f5';

  async function api(path) {
    const r = await fetch(API_BASE + path, { headers: { 'Authorization': 'Bearer ' + API_TOKEN } });
    if (!r.ok) throw new Error('API ' + r.status);
    return r.json();
  }

  // overrideRootId — when caller wants to scope the picker to a specific
  // folder (e.g. the active brand's drive_folder_id) instead of the
  // connector's full root. Hard scope: openFolderModal's breadcrumb stack
  // refuses to navigate above the root, so this becomes a real boundary.
  async function getActiveConnector(overrideRootId) {
    const conns = await api('/connectors.php?type=storage&provider=google_drive');
    const c = (conns.rows || [])[0];
    if (!c) throw new Error('No Google Drive connector — open Settings → Connectors → Google Drive → Connect first.');
    let rootFolderId;
    if (overrideRootId) {
      rootFolderId = overrideRootId;
    } else {
      const root = await api('/google-drive.php?action=root&connector_id=' + c.id);
      rootFolderId = root.root_folder_id;
    }
    return { connectorId: c.id, accountName: c.name, rootFolderId: rootFolderId };
  }

  // Return everything in the folder. The modal decides what to show based
  // on its current mode (folder picker hides files; files picker shows them).
  async function listFolder(connectorId, folderId) {
    const data = await api('/google-drive.php?action=list&connector_id=' + connectorId + '&folder_id=' + encodeURIComponent(folderId));
    return data.files || [];
  }

  async function listFolderRecursive(connectorId, folderId, prefix, depth = 0) {
    if (depth > 6) return [];
    const items = await listFolder(connectorId, folderId);
    let out = [];
    for (const e of items) {
      if (e.mimeType === 'application/vnd.google-apps.folder') {
        const sub = await listFolderRecursive(connectorId, e.id, prefix + e.name + '/', depth + 1);
        out = out.concat(sub);
      } else {
        out.push({ ...e, _path: prefix + e.name });
      }
    }
    return out;
  }

  async function downloadDriveFile(connectorId, fileId, name, mime) {
    const url = API_BASE + '/google-drive.php?action=download&connector_id=' + connectorId + '&file_id=' + encodeURIComponent(fileId);
    const r = await fetch(url, { headers: { 'Authorization': 'Bearer ' + API_TOKEN } });
    if (!r.ok) throw new Error('Drive download failed for ' + name);
    const blob = await r.blob();
    return new File([blob], name, { type: mime || blob.type || 'application/octet-stream' });
  }

  // ── Inject modal CSS once ────────────────────────────────────────────
  function ensureModalCss() {
    if (document.getElementById('drive-modal-css')) return;
    const css = document.createElement('style');
    css.id = 'drive-modal-css';
    css.textContent = `
      .drive-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;font-family:'Inter',system-ui,sans-serif;}
      .drive-modal{background:#FFFFFF;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.25);width:560px;max-width:92vw;max-height:80vh;display:flex;flex-direction:column;overflow:hidden;}
      .drive-modal-head{padding:16px 18px;border-bottom:1px solid #E1E3E5;display:flex;align-items:center;gap:10px;}
      .drive-modal-head .ic{width:24px;height:24px;flex-shrink:0;}
      .drive-modal-head h3{font-size:15px;font-weight:700;color:#1A1A1A;flex:1;margin:0;}
      .drive-modal-head .close{background:none;border:none;font-size:20px;cursor:pointer;color:#6D7175;padding:0 4px;}
      .drive-modal-search{padding:10px 14px;border-bottom:1px solid #DCDCDC;background:#F1F1F1;}
      .drive-modal-search input{width:100%;padding:9px 12px;border:1px solid #DCDCDC;border-radius:8px;font-size:13px;font-family:inherit;background:#F1F1F1;outline:none;}
      .drive-modal-search input:focus{border-color:#1A1A1A;background:#FFFFFF;}
      .drive-modal-bread{padding:10px 18px;border-bottom:1px solid #F1F1F1;background:#FAFBFB;font-size:12px;color:#6D7175;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
      .drive-modal-bread .crumb{cursor:pointer;color:#005BD3;}
      .drive-modal-bread .crumb:hover{text-decoration:underline;}
      .drive-modal-bread .crumb.last{color:#1A1A1A;cursor:default;font-weight:600;}
      .drive-modal-bread .sep{color:#C9CCCF;}
      .drive-modal-list{flex:1;overflow-y:auto;padding:8px 0;}
      .drive-row{display:flex;align-items:center;gap:10px;padding:9px 18px;cursor:pointer;font-size:13px;color:#1A1A1A;}
      .drive-row:hover{background:#F6F6F7;}
      .drive-row.file{color:#6D7175;cursor:default;}
      .drive-row .row-ic{width:18px;height:18px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;}
      .drive-row .row-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
      .drive-row .row-meta{color:#8C9196;font-size:11px;flex-shrink:0;}
      .drive-modal-foot{padding:12px 18px;border-top:1px solid #E1E3E5;display:flex;align-items:center;gap:8px;background:#FAFBFB;}
      .drive-modal-foot .info{flex:1;font-size:12px;color:#6D7175;}
      .drive-modal-foot button{height:32px;padding:0 14px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid #DCDCDC;background:#FFFFFF;color:#1A1A1A;font-family:inherit;}
      .drive-modal-foot button:disabled{opacity:0.55;cursor:not-allowed;}
      .drive-modal-foot button.primary{background:#1A1A1A;color:#FFFFFF;border-color:#1A1A1A;}
      .drive-state{padding:40px 20px;text-align:center;color:#6D7175;font-size:13px;}
      .drive-state .sp{display:inline-block;width:16px;height:16px;border:2px solid rgba(0,0,0,0.15);border-top-color:#1A1A1A;border-radius:50%;animation:driveSpin .7s linear infinite;}
      @keyframes driveSpin{to{transform:rotate(360deg);}}
    `;
    document.head.appendChild(css);
  }

  const FOLDER_ICON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="#5F6368"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
  const FILE_ICON   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9AA0A6" stroke-width="1.6"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
  const DRIVE_ICON  = '<svg width="22" height="22" viewBox="0 0 512 512"><path d="m38.5 418.9 22.5 39c4.7 8.2 11.4 14.7 19.3 19.4l80.7-57.2.2-82.2-80.6-36.3-80.7 36c0 9.1 2.3 18.2 7 26.4z" fill="#06d"/><path d="M256.3 173.8 260.5 66.7 175.9 34.1c-7.9 4.7-14.7 11.1-19.4 19.3L7.1 311.2c-4.7 8.2-7.1 17.3-7.1 26.4l161.3.3z" fill="#00ad3c"/><path d="M256.3 173.8 333.8 132.1 337.2 34.4c-7.9-4.7-17-7.1-26.4-7.1L202.3 27.1c-9.4 0-18.5 2.6-26.4 7z" fill="#00831e"/><path d="M350.7 338.2 161.3 337.9 80.4 477.3c7.9 4.7 17 7.1 26.4 7.1l297.9.5c9.4 0 18.5-2.6 26.4-7l.3-93.7z" fill="#0084ff"/><path d="M431.1 477.9c7.9-4.7 14.7-11.1 19.4-19.3l9.4-16.1 45-77.6c4.7-8.2 7.1-17.3 7.1-26.4l-93.2-49-67.8 48.8z" fill="#ff4131"/><path d="M430.8 182.9 356.5 53.8c-4.7-8.2-11.4-14.7-19.3-19.4L256.3 173.8 350.7 338.2l161 .3c0-9.1-2.3-18.2-7-26.4z" fill="#ffba00"/></g></svg>';

  function fmtSize(bytes) {
    if (!bytes) return '';
    const n = parseInt(bytes, 10);
    if (n < 1024) return n + ' B';
    if (n < 1024*1024) return Math.round(n/1024) + ' KB';
    return (n/1024/1024).toFixed(1) + ' MB';
  }

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  // ── Open the folder browser modal ────────────────────────────────────
  // opts: { onPick, onCancel, mode?, rootLabel? }
  //   mode = 'folder' (default) → single folder pick (returns {id,name})
  //   mode = 'files'             → multi-select files (returns [{id,name,mimeType}])
  //   rootLabel                  → friendly name shown in the first breadcrumb
  //                                (e.g. brand name when picker is brand-scoped)
  function openFolderModal(ctx, opts) {
    ensureModalCss();
    const { onPick, onCancel } = opts;
    const mode = opts.mode || 'folder';
    const isFilesMode = mode === 'files';
    // Stack of {id, name} for breadcrumbs. Stack[0] is the root.
    const stack = [{ id: ctx.rootFolderId, name: opts.rootLabel || 'Allowed Drive folder' }];

    const bg = document.createElement('div');
    bg.className = 'drive-modal-bg';
    bg.innerHTML = `
      <div class="drive-modal" onclick="event.stopPropagation()">
        <div class="drive-modal-head">
          <span class="ic">${DRIVE_ICON}</span>
          <h3>${isFilesMode ? 'Pick files from Drive' : 'Pick a product folder from Drive'}</h3>
          <button class="close" data-act="close" title="Close">×</button>
        </div>
        <div class="drive-modal-search">
          <input type="text" id="dmSearch" placeholder="${isFilesMode ? 'Search files & folders…' : 'Search by product or folder name…'}">
        </div>
        <div class="drive-modal-bread" id="dmBread"></div>
        <div class="drive-modal-list" id="dmList"></div>
        <div class="drive-modal-foot">
          <span class="info" id="dmInfo">${isFilesMode ? 'Tap files to select. Double-click folders to open.' : 'Click a folder to select, double-click to open inside.'}</span>
          <button data-act="cancel">Cancel</button>
          <button class="primary" data-act="pick" disabled>${isFilesMode ? 'Add 0 files' : 'Use this folder'}</button>
        </div>
      </div>`;
    document.body.appendChild(bg);

    const list   = bg.querySelector('#dmList');
    const bread  = bg.querySelector('#dmBread');
    const info   = bg.querySelector('#dmInfo');
    const pickBtn = bg.querySelector('[data-act="pick"]');
    const searchInput = bg.querySelector('#dmSearch');

    let selected = null;       // {id, name} of folder selected by single click (folder mode)
    const selectedFiles = new Map(); // id → {id, name, mimeType} (files mode)
    let searchTerm = '';
    let currentFolders = [];   // last-loaded folder rows in current view
    let searchMode = false;    // true while showing global search results
    let searchResults = [];    // global search hits

    function close() { bg.remove(); }
    bg.addEventListener('click', (e) => { if (e.target === bg) { close(); onCancel && onCancel(); } });
    bg.querySelector('[data-act="close"]').addEventListener('click', () => { close(); onCancel && onCancel(); });
    bg.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(); onCancel && onCancel(); });
    pickBtn.addEventListener('click', () => {
      if (isFilesMode) {
        if (selectedFiles.size === 0) return;
        const arr = Array.from(selectedFiles.values());
        close();
        onPick(arr);
        return;
      }
      // Folder mode: use single-click-selected folder; fall back to current breadcrumb
      const target = selected || (stack.length > 1 ? stack[stack.length - 1] : null);
      if (!target) return;
      close();
      onPick(target);
    });
    let searchT;
    searchInput.addEventListener('input', () => {
      searchTerm = searchInput.value.trim();
      clearTimeout(searchT);
      // Empty search → render the current folder again
      if (!searchTerm) {
        searchMode = false;
        renderList();
        return;
      }
      // Wait 300ms after typing stops, then run global search inside allowed root
      searchT = setTimeout(runGlobalSearch, 300);
    });

    function renderBreadcrumbs() {
      bread.innerHTML = stack.map((s, i) => {
        const isLast = i === stack.length - 1;
        return `<span class="crumb ${isLast ? 'last' : ''}" data-i="${i}">${escapeHtml(s.name)}</span>`
             + (isLast ? '' : '<span class="sep">›</span>');
      }).join('');
      bread.querySelectorAll('.crumb:not(.last)').forEach(el => {
        el.addEventListener('click', () => {
          const i = parseInt(el.dataset.i, 10);
          stack.splice(i + 1);
          loadCurrent();
        });
      });
    }

    let currentFiles = [];

    async function runGlobalSearch() {
      if (!searchTerm) return;
      searchMode = true;
      list.innerHTML = '<div class="drive-state"><span class="sp"></span> Searching all folders…</div>';
      try {
        const res = await api('/google-drive.php?action=search&connector_id=' + ctx.connectorId + '&q=' + encodeURIComponent(searchTerm));
        searchResults = res.files || [];
        renderList();
      } catch (e) {
        list.innerHTML = '<div class="drive-state" style="color:#D72C0D;">Search failed: ' + escapeHtml(e.message) + '</div>';
      }
    }

    // Pretty type tag for files (Sheet / Doc / Slides / xlsx / docx / file)
    function fileTypeTag(mime, name) {
      if (!mime) return /\.(xlsx|csv)$/i.test(name||'') ? 'Sheet' : (/\.(docx|doc|txt)$/i.test(name||'') ? 'Doc' : 'File');
      if (mime.includes('spreadsheet')) return 'Sheet';
      if (mime.includes('presentation')) return 'Slides';
      if (mime.includes('document')) return 'Doc';
      if (mime.includes('vnd.openxmlformats-officedocument.spreadsheetml')) return 'xlsx';
      if (mime.includes('vnd.openxmlformats-officedocument.wordprocessingml')) return 'docx';
      if (mime.startsWith('image/')) return 'Image';
      return 'File';
    }

    function renderList() {
      const folders = searchMode ? searchResults : currentFolders;
      const files   = searchMode ? [] : currentFiles;
      if (!folders.length && !files.length) {
        list.innerHTML = '<div class="drive-state">' + (searchMode ? 'No matches for "' + escapeHtml(searchTerm) + '".' : 'Empty folder.') + '</div>';
        return;
      }
      list.innerHTML =
        folders.map(f => `<div class="drive-row${!isFilesMode && selected && selected.id === f.id ? ' selected' : ''}" data-folder="${escapeHtml(f.id)}" data-name="${escapeHtml(f.name)}">
          <span class="row-ic">${FOLDER_ICON}</span>
          <span class="row-name">${escapeHtml(f.name)}</span>
        </div>`).join('') +
        files.map(f => `<div class="drive-row file${isFilesMode && selectedFiles.has(f.id) ? ' selected' : ''}" data-file="${escapeHtml(f.id)}" data-name="${escapeHtml(f.name)}" data-mime="${escapeHtml(f.mimeType||'')}">
          <span class="row-ic">${FILE_ICON}</span>
          <span class="row-name">${escapeHtml(f.name)}</span>
          <span class="row-meta">${fileTypeTag(f.mimeType, f.name)}${f.size ? ' · ' + fmtSize(f.size) : ''}</span>
        </div>`).join('');
      // Folder rows: single click selects (folder mode), dbl click drills.
      list.querySelectorAll('[data-folder]').forEach(row => {
        row.addEventListener('click', () => {
          if (isFilesMode) {
            // In files mode, single click on folder still drills (no select).
            stack.push({ id: row.dataset.folder, name: row.dataset.name });
            loadCurrent();
            return;
          }
          selected = { id: row.dataset.folder, name: row.dataset.name };
          list.querySelectorAll('.drive-row.selected').forEach(r => r.classList.remove('selected'));
          row.classList.add('selected');
          pickBtn.disabled = false;
          pickBtn.textContent = 'Use "' + selected.name + '"';
        });
        row.addEventListener('dblclick', () => {
          stack.push({ id: row.dataset.folder, name: row.dataset.name });
          selected = null;
          loadCurrent();
        });
      });
      // File rows: clickable in files mode (toggles selection).
      if (isFilesMode) {
        list.querySelectorAll('[data-file]').forEach(row => {
          row.addEventListener('click', () => {
            const fid = row.dataset.file;
            if (selectedFiles.has(fid)) {
              selectedFiles.delete(fid);
              row.classList.remove('selected');
            } else {
              selectedFiles.set(fid, { id: fid, name: row.dataset.name, mimeType: row.dataset.mime });
              row.classList.add('selected');
            }
            pickBtn.disabled = selectedFiles.size === 0;
            pickBtn.textContent = 'Add ' + selectedFiles.size + ' file' + (selectedFiles.size === 1 ? '' : 's');
          });
        });
      }
      const totalCount = folders.length + files.length;
      info.textContent = totalCount + ' result' + (totalCount === 1 ? '' : 's')
        + (searchMode ? ' matching "' + searchTerm + '"' : '');
    }

    async function loadCurrent() {
      const cur = stack[stack.length - 1];
      // Reset selection + search when navigating
      selected = null;
      searchMode = false;
      searchTerm = '';
      if (searchInput.value) searchInput.value = '';
      // Files mode: enable button as soon as ≥1 file is selected (handled
      // separately by file-row click handlers). Folder mode: legacy behavior.
      if (isFilesMode) {
        pickBtn.disabled = selectedFiles.size === 0;
        pickBtn.textContent = 'Add ' + selectedFiles.size + ' file' + (selectedFiles.size === 1 ? '' : 's');
      } else {
        pickBtn.disabled = stack.length === 1;
        pickBtn.textContent = stack.length === 1 ? 'Pick a folder first' : 'Use this folder';
      }
      renderBreadcrumbs();
      list.innerHTML = '<div class="drive-state"><span class="sp"></span> Loading…</div>';
      try {
        const items = await listFolder(ctx.connectorId, cur.id);
        currentFolders = items.filter(i => i.mimeType === 'application/vnd.google-apps.folder')
                               .sort((a, b) => a.name.localeCompare(b.name));
        currentFiles = items.filter(i => i.mimeType !== 'application/vnd.google-apps.folder')
                            .sort((a, b) => a.name.localeCompare(b.name));
        // Folder picker stays clean — only show folders. Files picker shows everything.
        if (!isFilesMode) currentFiles = [];
        renderList();
      } catch (e) {
        list.innerHTML = '<div class="drive-state" style="color:#D72C0D;">Error: ' + escapeHtml(e.message) + '</div>';
      }
    }

    loadCurrent();
    setTimeout(() => searchInput.focus(), 80);
  }

  // ── Public API ───────────────────────────────────────────────────────
  // Lightweight variant: just return the picked {id, name} without downloading.
  // Used by Settings → Drive connector to set folder IDs (Operations root,
  // Products folder, PRODUCT TEMPLATE) without forcing a recursive download.
  // opts.rootFolderId — scope the picker to this folder (e.g. brand folder)
  // opts.rootLabel    — friendly label for the root breadcrumb
  global.pickGoogleDriveFolderId = async function pickGoogleDriveFolderId(onPick, opts) {
    opts = opts || {};
    const onError = opts.onError || ((e) => alert(e.message));
    let ctx;
    try { ctx = await getActiveConnector(opts.rootFolderId); }
    catch (e) { onError(e); return; }
    openFolderModal(ctx, {
      rootLabel: opts.rootLabel,
      onCancel: () => {},
      onPick: (folder) => { try { onPick(folder); } catch (e) { onError(e); } }
    });
  };

  // Files mode: multi-select files (Sheets / Docs / Slides / xlsx / docx /
  // any file). Returns an array of {id, name, mimeType} via the callback.
  // opts.rootFolderId — scope the picker to this folder (e.g. brand folder)
  // opts.rootLabel    — friendly label for the root breadcrumb
  global.pickGoogleDriveFiles = async function pickGoogleDriveFiles(onPick, opts) {
    opts = opts || {};
    const onError = opts.onError || ((e) => alert(e.message));
    let ctx;
    try { ctx = await getActiveConnector(opts.rootFolderId); }
    catch (e) { onError(e); return; }
    openFolderModal(ctx, {
      mode: 'files',
      rootLabel: opts.rootLabel,
      onCancel: () => {},
      onPick: (files) => { try { onPick(files); } catch (e) { onError(e); } }
    });
  };

  // Programmatic helpers (no UI) — list a folder's immediate children, used
  // by Import buttons to bulk-add without opening the modal.
  global.driveListFolder = async function driveListFolder(folderId, opts) {
    opts = opts || {};
    let ctx;
    try { ctx = await getActiveConnector(folderId); } catch (e) { throw e; }
    return await listFolder(ctx.connectorId, folderId);
  };
  // Find an immediate child folder by (case-insensitive) name. Returns
  // the matched folder {id, name, mimeType} or null.
  global.driveFindSubfolder = async function driveFindSubfolder(parentId, name) {
    const items = await global.driveListFolder(parentId);
    const target = String(name || '').trim().toLowerCase();
    return items.find(i =>
      i.mimeType === 'application/vnd.google-apps.folder'
      && String(i.name || '').trim().toLowerCase() === target
    ) || null;
  };

  global.pickGoogleDriveFolder = async function pickGoogleDriveFolder(onFiles, opts) {
    opts = opts || {};
    const onError = opts.onError || ((e) => alert(e.message));
    const onProgress = opts.onProgress || (() => {});
    let ctx;
    try { ctx = await getActiveConnector(); }
    catch (e) { onError(e); return; }

    openFolderModal(ctx, {
      onCancel: () => { /* user closed */ },
      onPick: async (folder) => {
        try {
          onProgress({ phase: 'listing', folderName: folder.name });
          const items = await listFolderRecursive(ctx.connectorId, folder.id, folder.name + '/');
          // Download what tools can actually ingest: images + text + docx +
          // Google Docs/Sheets (server-side exported to text/csv) + xlsx.
          // Skip videos / slides / large binaries.
          const wanted = items.filter(i => {
            const m = i.mimeType || '';
            const n = i.name || '';
            // Google native types we can export to text
            if (m === 'application/vnd.google-apps.document')    return true; // → .txt
            if (m === 'application/vnd.google-apps.spreadsheet') return true; // → .csv
            // Skip other Google native types (slides, drawings, etc.)
            if (m.startsWith('application/vnd.google-apps'))     return false;
            // Images
            if (m.startsWith('image/')) return true;
            // Word + Excel
            if (m === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') return true; // .docx
            if (m === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')      return true; // .xlsx
            // Plain text by extension
            if (/\.(txt|md|docx|csv|xlsx)$/i.test(n)) return true;
            return false;
          });
          const total = wanted.length;
          const files = [];
          for (let i = 0; i < wanted.length; i++) {
            const it = wanted[i];
            onProgress({ phase: 'downloading', current: i + 1, total, name: it.name });
            try {
              const f = await downloadDriveFile(ctx.connectorId, it.id, it.name, it.mimeType);
              try { Object.defineProperty(f, 'webkitRelativePath', { value: it._path, configurable: true }); } catch (e) {}
              files.push(f);
            } catch (e) { console.warn('skip', it.name, e.message); }
          }
          onFiles(files);
        } catch (e) { onError(e); }
      }
    });
  };
})(window);
