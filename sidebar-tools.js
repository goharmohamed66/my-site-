// Sidebar shared behavior:
//   1. Inject the "Reports" group into every page's sidebar (one source of truth
//      for adding new reports — edit REPORTS_LIST below and they show up everywhere).
//   2. Make every .app-sidebar-group collapsible (click header to toggle).
//   3. Persist open/close state in localStorage so it survives reloads.
//   4. Default = collapsed UNLESS the active page sits inside the group.
(function () {
  // ── Reports list ────────────────────────────────────────────────────
  // Each entry shows up under the Reports group. Add new reports here only.
  const REPORTS_LIST = [
    {
      slug: 'amount-spent',
      name: 'Amount Spent',
      // path RELATIVE to the project root (the script computes pathPrefix below)
      href: 'reports/amount-spent.html',
      svg : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'
    },
    {
      slug: 'profit-analysis',
      name: 'Profit Analysis',
      href: 'reports/profit-analysis.html',
      // Trending-up chart icon — distinct from the dollar of Amount Spent.
      svg : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>'
    },
  ];
  const REPORTS_GROUP_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h4"/></svg>';

  // ── All tools / Automation Tools groups (injected on pages that don't
  //    already render them — keeps every sidebar identical regardless of
  //    which page hosts it). Detected by group header text so we never
  //    duplicate. Edit here to add new tools across the entire app.
  const ALL_TOOLS_GROUP = {
    name: 'All tools',
    svg: '<svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><path d="M11.452 0h1.698c.47 0 .85.38.85.85v1.699c0 .469-.38.85-.85.85h-1.699a.85.85 0 0 1-.849-.85v-1.7c0-.469.38-.849.85-.849M13.15 5.301h-1.699a.85.85 0 0 0-.849.85V7.85c0 .469.38.849.85.849h1.698c.47 0 .85-.38.85-.85V6.15a.85.85 0 0 0-.85-.849M.85 10.602h1.699c.469 0 .85.38.85.85v1.698c0 .47-.381.85-.85.85h-1.7A.85.85 0 0 1 0 13.15v-1.699c0-.469.38-.85.85-.85M7.85 10.602h-1.7a.85.85 0 0 0-.85.85v1.698c.001.47.381.85.85.85h1.7c.469 0 .849-.38.849-.85v-1.699a.85.85 0 0 0-.85-.85M13.15 10.602h-1.699a.85.85 0 0 0-.849.85v1.698c0 .47.38.85.85.85h1.698c.47 0 .85-.38.85-.85v-1.699a.85.85 0 0 0-.85-.85M6.15 5.301h1.7c.469 0 .849.38.849.85V7.85c0 .469-.38.849-.85.849H6.15a.85.85 0 0 1-.85-.85V6.15c.001-.469.381-.849.85-.849M2.549 5.301h-1.7a.85.85 0 0 0-.849.85V7.85c0 .469.38.849.85.849h1.699c.469 0 .85-.38.85-.85V6.15a.85.85 0 0 0-.85-.849M7.85 0h-1.7a.85.85 0 0 0-.85.85v1.699c.001.469.381.85.85.85h1.7c.469 0 .849-.381.849-.85v-1.7A.85.85 0 0 0 7.849 0M.85 0h1.699c.469 0 .85.38.85.85v1.699c0 .469-.381.85-.85.85h-1.7A.85.85 0 0 1 0 2.548v-1.7C0 .38.38 0 .85 0"/></svg>',
    children: [
      { name:'Extract Reviews & Details', href:'extract-reviews.html', matchPaths:['extract-reviews.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="14" y2="16"/></svg>' },
      { name:'Buyer Persona', href:'buyer-persona.html', matchPaths:['buyer-persona.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' },
      { name:'Copywriting', href:'copywriting.html', matchPaths:['copywriting.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>' },
      { name:'Creative Image Ads', href:'creative-image-ads.html', matchPaths:['creative-image-ads.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>' },
      { name:'Headlines Generator', href:'headlines.html', matchPaths:['headlines.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M18 4v16"/><path d="M6 12h12"/></svg>' },
      { name:'Landing page content', href:'landing-page.html', matchPaths:['landing-page.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>' },
      { name:'Landing page images', href:'landing-page-images.html', matchPaths:['landing-page-images.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>' }
    ]
  };
  const AUTOMATION_TOOLS_GROUP = {
    name: 'Automation Tools',
    svg: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="M12 18v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="m16.24 16.24 2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="m16.24 7.76 2.83-2.83"/></svg>',
    children: [
      { name:'Landing Page Auto', href:'landing-auto.html', matchPaths:['landing-auto.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>' },
      // Financial Modeling Updater — writes Return % + tROAS into the
      // brand's "Financial Modeling (Monetization)" sheet for the active
      // date range. See automation-fm-update.html.
      { name:'Financial Updater', href:'automation-fm-update.html', matchPaths:['automation-fm-update.html'],
        svg:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h4"/><path d="M16 19l3 3 5-5" transform="translate(-3 -3) scale(0.6) translate(20 8)"/></svg>' }
    ]
  };

  // ── Products hub (collapsible group, injected before the first divider) ─
  // Entry point for the Clients → Brands → Products hierarchy. Acts like the
  // "Automation Tools" group: parent header + two children (Products | Sheets).
  const PRODUCTS_GROUP = {
    name: 'Products',
    svg: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
    children: [
      {
        name: 'Products',
        href: 'clients.html',
        // The Products page lives at clients → client → brand → product
        matchPaths: ['clients.html', 'client.html', 'brand.html', 'product.html'],
        svg: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>'
      },
      {
        name: 'Sheets',
        href: 'sheets.html',
        matchPaths: ['sheets.html'],
        // Google Sheets brand icon (kept as filled multi-color so the row
        // visually identifies the linked Drive product, like Drive icons
        // in the storage connector card).
        svg: '<svg width="14" height="14" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path style="fill:#12B347" d="M456.348,495.304c0,9.217-7.479,16.696-16.696,16.696H72.348c-9.217,0-16.696-7.479-16.696-16.696V16.696C55.652,7.479,63.131,0,72.348,0h233.739c4.424,0,8.674,1.761,11.804,4.892l133.565,133.565c3.131,3.13,4.892,7.379,4.892,11.804V495.304z"/><path style="fill:#0F993E" d="M456.348,495.304V150.278c0-4.437-1.766-8.691-4.909-11.822L317.389,4.871C314.258,1.752,310.019,0,305.601,0H256v512h183.652C448.873,512,456.348,504.525,456.348,495.304z"/><path style="fill:#12B347" d="M451.459,138.459L317.891,4.892C314.76,1.76,310.511,0,306.082,0h-16.691l0.001,150.261c0,9.22,7.475,16.696,16.696,16.696h150.26v-16.696C456.348,145.834,454.589,141.589,451.459,138.459z"/><path style="fill:#FFFFFF" d="M372.87,211.478H139.13c-9.217,0-16.696,7.479-16.696,16.696v200.348c0,9.217,7.479,16.696,16.696,16.696H372.87c9.217,0,16.696-7.479,16.696-16.696V228.174C389.565,218.957,382.087,211.478,372.87,211.478z M155.826,311.652h66.783v33.391h-66.783V311.652z M256,311.652h100.174v33.391H256V311.652z M356.174,278.261H256V244.87h100.174V278.261z M222.609,244.87v33.391h-66.783V244.87H222.609z M155.826,378.435h66.783v33.391h-66.783V378.435z M256,411.826v-33.391h100.174v33.391H256z"/><path style="fill:#E6F3FF" d="M372.87,211.478H256v33.391h100.174v33.391H256v33.391h100.174v33.391H256v33.391h100.174v33.391H256v33.391h116.87c9.22,0,16.696-7.475,16.696-16.696V228.174C389.565,218.953,382.09,211.478,372.87,211.478z"/></svg>'
      }
    ]
  };

  // ── Top-level sidebar items (Orders / Shipping / Confirmation / Ads) ─
  // Single source of truth for the items above the first divider. Pages
  // that opt into full rendering via <aside data-render-sidebar> get
  // these built from this list — adding a new top-level item is one
  // entry here, no need to edit every HTML file.
  const TOP_ITEMS = [
    { name: 'Orders', href: 'index.html', matchPaths: ['index.html', ''],
      svg: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>' },
    { name: 'Shipping Analytics', href: 'shipping.html', matchPaths: ['shipping.html'],
      svg: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>' },
    { name: 'Confirmation', href: 'confirmation.html', matchPaths: ['confirmation.html'], extraActiveSearch: /[?&]mode=confirmation\b/,
      svg: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>' },
    { name: 'Ads Analysis', href: 'ads.html', matchPaths: ['ads.html'],
      svg: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>' }
  ];
  const FOOTER_SETTINGS_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';

  // ── Inject global Almarai @font-face + Arabic font + nav weight ─────
  if (!document.getElementById('app-global-font-css')) {
    // Make sure Inter Black (900) is loaded so the EXTRA-BOLD nav actually renders
    if (!document.querySelector('link[data-app-inter-900]')) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap';
      link.setAttribute('data-app-inter-900', '1');
      document.head.appendChild(link);
    }
    const fontCss = document.createElement('style');
    fontCss.id = 'app-global-font-css';
    // Resolve the font URL relative to the page (works for /reports/ subfolder too)
    const fontPath = (/\/reports\//.test(location.pathname) ? '../' : '') + 'fonts/Almarai-Regular.ttf';
    fontCss.textContent = `
      @font-face {
        font-family: 'Almarai';
        src: url('${fontPath}') format('truetype');
        font-weight: 400 700;
        font-style: normal;
        font-display: swap;
        unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF;
      }
      /* Apply Almarai to every Arabic glyph anywhere in the SaaS */
      html, body, button, input, textarea, select, option, table, .app-sidebar-item,
      .app-sidebar-group-header, .field-input, .field-textarea, .btn, .toast {
        font-family: 'Inter', 'Almarai', system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
      }
      /* Sidebar top-level items: extra-bold (heavier than before)
         + force line-height: 1 so item height stays the same on every page,
         even pages whose body sets line-height: 24px (e.g. index.html). */
      .app-sidebar-item { font-weight: 900 !important; line-height: 1 !important; }
      .app-sidebar-item span { line-height: 1 !important; }
      .app-sidebar-group-header { line-height: 1 !important; }
      .app-sidebar-group-header span { line-height: 1 !important; }
      /* Force consistent icon sizes — React Sidebar in shipping.html uses
         16px on group headers while the static HTML uses 14px. Pin both
         to 14px so every page has identical row heights. */
      .app-sidebar-group-header > svg { width: 14px !important; height: 14px !important; }
      .app-sidebar-item > svg { width: 16px !important; height: 16px !important; }
      /* Children of collapsible groups stay slightly lighter for hierarchy */
      .app-sidebar-group .app-sidebar-group-items .app-sidebar-item {
        font-weight: 700 !important;
      }
      /* Group headers (All tools / Automation Tools / Products / Reports …) —
         every dimension pinned so navigating between pages (or between
         static / React sidebars) never changes the visual rhythm. */
      .app-sidebar .app-sidebar-group-header {
        font-size: 13px !important;
        color: #1A1A1A !important;
        font-weight: 900 !important;
        text-transform: none !important;
        letter-spacing: 0 !important;
        padding: 7px 28px 7px 12px !important;
        height: 32px !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        border-radius: 8px !important;
        box-sizing: border-box !important;
      }
      /* Children inside any group: identical density, identical indent. */
      .app-sidebar .app-sidebar-group .app-sidebar-group-items .app-sidebar-item {
        font-size: 13px !important;
        font-weight: 700 !important;
        height: 30px !important;
        padding: 7px 12px 7px 32px !important;
        gap: 10px !important;
        color: #303030 !important;
        border-radius: 8px !important;
        box-sizing: border-box !important;
      }
      /* Top-level items (Orders, Shipping, Ads) use the same height pin. */
      .app-sidebar .app-sidebar-items > .app-sidebar-item {
        height: 32px !important;
        padding: 7px 12px !important;
        box-sizing: border-box !important;
      }
      /* Group itself: no extra spacing — keeps every section's footprint
         identical regardless of which page you're on. */
      .app-sidebar .app-sidebar-group { margin: 0 !important; padding: 0 !important; }
      /* Item gap inside the items wrapper — uniform across React + static. */
      .app-sidebar .app-sidebar-items { gap: 2px !important; }
    `;
    document.head.appendChild(fontCss);
  }

  // ── Inject CSS once ─────────────────────────────────────────────────
  if (!document.getElementById('app-sidebar-collapse-css')) {
    const css = document.createElement('style');
    css.id = 'app-sidebar-collapse-css';
    css.textContent = `
      .app-sidebar-group-header { cursor: pointer; user-select: none; transition: background 0.12s; position: relative; padding-right: 28px !important; }
      .app-sidebar-group-header:hover { background: #E2E2E2; }
      /* Caret rendered as a CSS pseudo-element so React re-renders cannot
         strip it the way they strip injected SVG <span>s.
         DEFAULT (no class): collapsed look — caret points RIGHT.
         .expanded class: caret points DOWN.
         This means every page renders collapsed on first paint and only
         opens groups that JS explicitly marks as expanded — no flash of
         opened content followed by collapsing. */
      .app-sidebar-group-header::after {
        content: '';
        position: absolute;
        right: 12px; top: 50%;
        width: 7px; height: 7px;
        border-right: 1.6px solid currentColor;
        border-bottom: 1.6px solid currentColor;
        opacity: 0.55;
        transform: translateY(-35%) rotate(-45deg);
        transition: transform 0.18s ease;
      }
      .app-sidebar-group.expanded > .app-sidebar-group-header::after {
        transform: translateY(-65%) rotate(45deg);
      }
      .app-sidebar-group-items {
        max-height: 0; overflow: hidden;
        transition: max-height 0.22s ease;
      }
      .app-sidebar-group.expanded .app-sidebar-group-items { max-height: 600px; }
      .app-sidebar-group .app-sidebar-group-items .app-sidebar-item {
        font-size: 13px !important; font-weight: 700 !important;
        padding: 7px 12px 7px 32px !important;   /* !important beats React inline style */
        position: relative;
        color: #303030;
      }
      /* Unified ACTIVE style across EVERY page (works for both the static
         HTML sidebars in landing-auto/ads/etc AND the React-rendered
         sidebar in shipping.html — its inline styles get overridden via
         !important). No more bright white pill that makes one page feel
         visually different from the rest. */
      .app-sidebar .app-sidebar-item.active,
      .app-sidebar .app-sidebar-group .app-sidebar-group-items .app-sidebar-item.active {
        background: transparent !important;
        box-shadow: none !important;
        color: #1A1A1A !important;
      }
      .app-sidebar .app-sidebar-item:hover,
      .app-sidebar .app-sidebar-item.active:hover {
        background: #E2E2E2 !important;
      }
      /* Branch indicator: small "↳" arrow on the left of every child item */
      .app-sidebar-group .app-sidebar-group-items .app-sidebar-item::before {
        content: '↳';
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-55%);
        font-size: 14px;
        line-height: 1;
        color: #9AA0A6;
        font-weight: 400;
      }
    `;
    document.head.appendChild(css);
  }

  const CARET_SVG = '<svg class="grp-caret" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 4.5 6 8 9 4.5"/></svg>';

  // Are we currently inside /reports/ ? If so use ../ for sibling links.
  function getPathPrefix() {
    const path = location.pathname;
    return /\/reports\//.test(path) ? '../' : '';
  }
  function getCurrentReportSlug() {
    const m = location.pathname.match(/\/reports\/([a-z0-9-]+)\.html$/i);
    return m ? m[1] : null;
  }

  function injectReportsGroup() {
    const nav = document.querySelector('.app-sidebar .app-sidebar-items');
    if (!nav) return;
    if (nav.querySelector('[data-reports-group]')) return; // already injected by us
    // Also skip if the page (e.g. shipping.html React sidebar) already has a
    // Reports group rendered — detect by group header text "Reports".
    const headers = nav.querySelectorAll('.app-sidebar-group-header');
    for (let i = 0; i < headers.length; i++) {
      const txt = (headers[i].textContent || '').trim();
      if (txt === 'Reports') return;
    }
    const prefix = getPathPrefix();
    const currentSlug = getCurrentReportSlug();
    const items = REPORTS_LIST.map(r => {
      const isActive = r.slug === currentSlug ? 'active' : '';
      return `<a href="${prefix}${r.href}" class="app-sidebar-item ${isActive}">${r.svg}<span>${r.name}</span></a>`;
    }).join('');
    const html = `
      <div class="app-sidebar-divider" data-reports-group="divider"></div>
      <div class="app-sidebar-group" data-reports-group="group">
        <div class="app-sidebar-group-header">
          ${REPORTS_GROUP_SVG}
          <span>Reports</span>
        </div>
        <div class="app-sidebar-group-items">${items}</div>
      </div>
    `;
    // Append at the END of the nav so Reports is the last group in the sidebar.
    nav.insertAdjacentHTML('beforeend', html);
  }

  function getGroupLabel(group) {
    const header = group.querySelector('.app-sidebar-group-header');
    const labelEl = header && header.querySelector('span');
    return (labelEl?.textContent || 'group').trim();
  }

  // Apply saved expanded state from localStorage to every group on the page.
  // Default = collapsed (no class). We only ADD `.expanded` when needed.
  // Idempotent — runs after every React re-render via the observer.
  function applyCollapsedState() {
    const groups = document.querySelectorAll('.app-sidebar .app-sidebar-group');
    groups.forEach(group => {
      const label = getGroupLabel(group);
      let saved = null;
      try { saved = localStorage.getItem('app.sidebar.grp.' + label); } catch (e) {}
      let expanded;
      if (saved !== null) {
        expanded = saved === '0';   // legacy: '1'=collapsed, '0'=expanded
      } else {
        // Default: expanded ONLY if this group contains the active page.
        const items = group.querySelector('.app-sidebar-group-items');
        expanded = !!items?.querySelector('.app-sidebar-item.active');
      }
      group.classList.toggle('expanded', expanded);
      // Strip legacy `.collapsed` if any old HTML still has it.
      group.classList.remove('collapsed');
    });
  }

  // Click handler bound ONCE on document — survives React re-renders since we
  // never re-bind. Catches header clicks via event delegation.
  if (!window.__appSidebarClickWired) {
    window.__appSidebarClickWired = true;
    document.addEventListener('click', (e) => {
      const header = e.target.closest('.app-sidebar .app-sidebar-group-header');
      if (!header) return;
      const group = header.closest('.app-sidebar-group');
      if (!group) return;
      const label = getGroupLabel(group);
      const willExpand = !group.classList.contains('expanded');
      group.classList.toggle('expanded', willExpand);
      // Saved as legacy schema ('1' = collapsed, '0' = expanded) so older
      // builds still read state correctly during a transition window.
      try { localStorage.setItem('app.sidebar.grp.' + label, willExpand ? '0' : '1'); } catch (e) {}
    });
  }

  // Inject the Products group as the FIRST group in the sidebar — sits
  // right after Orders / Shipping / Ads and before All tools. Renders with
  // exactly the same shape (collapsible <div class="app-sidebar-group">) so
  // every group across the app has identical dimensions / typography.
  function injectClientsItem() {
    const nav = document.querySelector('.app-sidebar .app-sidebar-items');
    if (!nav) return;
    if (nav.querySelector('[data-products-group]')) return;
    const prefix = getPathPrefix();
    const here = (location.pathname.split('/').pop() || '').toLowerCase();
    const childrenHtml = PRODUCTS_GROUP.children.map(c => {
      const isActive = c.matchPaths.some(p => here === p);
      return `<a href="${prefix}${c.href}" class="app-sidebar-item ${isActive ? 'active' : ''}">${c.svg}<span>${c.name}</span></a>`;
    }).join('');
    // Trailing divider so Products has the same separator below it as the
    // other groups have between them — keeps the visual rhythm uniform.
    const html = `
      <div class="app-sidebar-group" data-products-group="1">
        <div class="app-sidebar-group-header">
          ${PRODUCTS_GROUP.svg}
          <span>${PRODUCTS_GROUP.name}</span>
        </div>
        <div class="app-sidebar-group-items">${childrenHtml}</div>
      </div>
      <div class="app-sidebar-divider" data-products-divider="1"></div>
    `;
    // Insert AFTER the first divider (which separates the root items from
    // the All tools group). Products lands as the first collapsible group,
    // immediately above All tools / Automation Tools, with its own divider
    // below it so it visually matches the spacing of every other group.
    const firstDivider = nav.querySelector('.app-sidebar-divider');
    if (firstDivider) firstDivider.insertAdjacentHTML('afterend', html);
    else nav.insertAdjacentHTML('beforeend', html);
  }

  // ── Inject "All tools" / "Automation Tools" groups when the page's
  //    static HTML doesn't already render them. Detected by group header
  //    text so we never duplicate (index.html, ads.html, copywriting.html
  //    etc. ship them in HTML; clients.html / sheets.html / settings.html
  //    don't). Same shape as a normal collapsible group so all sidebar
  //    rules apply. Always insert them BEFORE the Reports divider/group
  //    (which is appended at the end via insertAdjacentHTML('beforeend')).
  function hasGroupNamed(nav, label) {
    var headers = nav.querySelectorAll('.app-sidebar-group-header');
    for (var i = 0; i < headers.length; i++) {
      var txt = (headers[i].textContent || '').trim();
      if (txt === label) return true;
    }
    return false;
  }
  function buildGroupHtml(group, marker) {
    var prefix = getPathPrefix();
    var here = (location.pathname.split('/').pop() || '').toLowerCase();
    var childrenHtml = group.children.map(function (c) {
      var isActive = (c.matchPaths || []).some(function (p) { return here === p; });
      return '<a href="' + prefix + c.href + '" class="app-sidebar-item ' + (isActive ? 'active' : '') + '">' + c.svg + '<span>' + c.name + '</span></a>';
    }).join('');
    return ''
      + '<div class="app-sidebar-divider" data-' + marker + '="divider"></div>'
      + '<div class="app-sidebar-group" data-' + marker + '="group">'
      +   '<div class="app-sidebar-group-header">' + group.svg + '<span>' + group.name + '</span></div>'
      +   '<div class="app-sidebar-group-items">' + childrenHtml + '</div>'
      + '</div>';
  }
  // Find a group element in the nav by its header label. Pages hardcode
  // these groups (e.g. "Automation Tools" with one child) and we need to
  // patch missing children INTO them — not skip just because the group
  // already exists.
  function findGroupByLabel(nav, label) {
    var headers = nav.querySelectorAll('.app-sidebar-group-header');
    for (var i = 0; i < headers.length; i++) {
      var span = headers[i].querySelector('span');
      var txt  = (span && span.textContent || '').trim();
      if (txt === label) return headers[i].closest('.app-sidebar-group');
    }
    return null;
  }
  // For an existing group element, insert any children defined in `group`
  // that aren't already present (matched by href file name) — keeping the
  // JS array order, so e.g. "Buyer Persona" lands ABOVE the
  // hardcoded "Copywriting" instead of being appended at the end.
  // Idempotent: presence is re-checked from the live DOM each iteration.
  function augmentGroupChildren(groupEl, group) {
    if (!groupEl || !group || !group.children) return;
    var items = groupEl.querySelector('.app-sidebar-group-items');
    if (!items) return;
    var prefix = getPathPrefix();
    var here = (location.pathname.split('/').pop() || '').toLowerCase();
    function findLink(fname) {
      var hit = null;
      items.querySelectorAll('a[href]').forEach(function (a) {
        if (lastSegment(a.getAttribute('href') || '') === fname) hit = a;
      });
      return hit;
    }
    group.children.forEach(function (c, idx) {
      var fname = lastSegment(c.href);
      if (findLink(fname)) return;
      var isActive = (c.matchPaths || []).some(function (p) { return here === p; });
      var html = '<a href="' + prefix + c.href + '" class="app-sidebar-item ' + (isActive ? 'active' : '') + '">'
               + c.svg + '<span>' + c.name + '</span></a>';
      // Anchor before the first LATER sibling (by array order) that's
      // already in the DOM; if none exists, append at the end.
      var anchorEl = null;
      for (var j = idx + 1; j < group.children.length; j++) {
        anchorEl = findLink(lastSegment(group.children[j].href));
        if (anchorEl) break;
      }
      if (anchorEl) anchorEl.insertAdjacentHTML('beforebegin', html);
      else items.insertAdjacentHTML('beforeend', html);
    });
  }
  function injectToolGroups() {
    var nav = document.querySelector('.app-sidebar .app-sidebar-items');
    if (!nav) return;
    var anchor = nav.querySelector('[data-reports-group="divider"]');
    // ALL TOOLS — inject the whole group if missing, OR augment children.
    var allEl = findGroupByLabel(nav, ALL_TOOLS_GROUP.name);
    if (!allEl && !nav.querySelector('[data-all-tools-group]')) {
      var html = buildGroupHtml(ALL_TOOLS_GROUP, 'all-tools-group');
      if (anchor) anchor.insertAdjacentHTML('beforebegin', html);
      else nav.insertAdjacentHTML('beforeend', html);
    } else {
      augmentGroupChildren(allEl || nav.querySelector('[data-all-tools-group]'), ALL_TOOLS_GROUP);
    }
    // AUTOMATION TOOLS — same treatment. Pages ship "Landing Page Auto"
    // hardcoded; we top up "Financial Updater" (and any future tool).
    var autoEl = findGroupByLabel(nav, AUTOMATION_TOOLS_GROUP.name);
    if (!autoEl && !nav.querySelector('[data-auto-tools-group]')) {
      var html2 = buildGroupHtml(AUTOMATION_TOOLS_GROUP, 'auto-tools-group');
      if (anchor) anchor.insertAdjacentHTML('beforebegin', html2);
      else nav.insertAdjacentHTML('beforeend', html2);
    } else {
      augmentGroupChildren(autoEl || nav.querySelector('[data-auto-tools-group]'), AUTOMATION_TOOLS_GROUP);
    }
  }

  // ── Canonical icon library ─────────────────────────────────
  // Single source of truth for every sidebar icon across the app.
  // Static HTML pages, React-rendered sidebars (shipping/ads), and
  // injected groups (Products/Reports/All tools/Automation) all get
  // the SAME SVG via enforceCanonicalIcons() — guarantees identical
  // visuals regardless of which page hosts the sidebar.
  // Identified by either the link's href (last segment) or, for
  // group headers and items without a link, by their visible label.
  var CANONICAL_SVG = {
    // ── Top-level items ──
    'index.html'         : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
    'shipping.html'      : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    'ads.html'           : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    // ── Products group children ──
    'clients.html'       : PRODUCTS_GROUP.children[0].svg,
    'sheets.html'        : PRODUCTS_GROUP.children[1].svg,
    // ── All tools children ──
    'extract-reviews.html'     : ALL_TOOLS_GROUP.children[0].svg,
    'buyer-persona.html'       : ALL_TOOLS_GROUP.children[1].svg,
    'copywriting.html'         : ALL_TOOLS_GROUP.children[2].svg,
    'creative-image-ads.html'  : ALL_TOOLS_GROUP.children[3].svg,
    'headlines.html'           : ALL_TOOLS_GROUP.children[4].svg,
    'landing-page.html'        : ALL_TOOLS_GROUP.children[5].svg,
    'landing-page-images.html' : ALL_TOOLS_GROUP.children[6].svg,
    // ── Automation Tools children ──
    'landing-auto.html'         : AUTOMATION_TOOLS_GROUP.children[0].svg,
    'automation-fm-update.html' : AUTOMATION_TOOLS_GROUP.children[1].svg,
    // ── Reports children (resolved at runtime from REPORTS_LIST) ──
    'amount-spent.html'  : REPORTS_LIST[0].svg,
    // ── Footer ──
    'settings.html'      : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'
  };
  // Group headers identified by their span label (no href).
  var CANONICAL_GROUP_SVG = {
    'Products'         : PRODUCTS_GROUP.svg,
    'All tools'        : ALL_TOOLS_GROUP.svg,
    'Automation Tools' : AUTOMATION_TOOLS_GROUP.svg,
    'Reports'          : REPORTS_GROUP_SVG
  };

  function lastSegment(href) {
    if (!href) return '';
    // Strip trailing slash, query, hash; keep just the file name.
    var s = String(href).split('?')[0].split('#')[0];
    if (s.endsWith('/')) s = s.slice(0, -1);
    var parts = s.split('/');
    return parts[parts.length - 1].toLowerCase();
  }

  // Replace the FIRST <svg> child of `el` with `canonicalHtml`. Skips
  // if the element already carries a marker so we don't run on every
  // tick. The marker is bumped whenever we change a canonical SVG so
  // outdated markers force a one-time refresh.
  var ICON_REV = '4';
  function setCanonicalIcon(el, canonicalHtml) {
    if (!canonicalHtml) return;
    if (el.getAttribute('data-icon-rev') === ICON_REV) return;
    var svg = el.querySelector(':scope > svg');
    if (svg) {
      // Replace via outerHTML so all attributes (viewBox, fill, stroke
      // colors that the canonical SVG specifies) take effect.
      var tmp = document.createElement('div');
      tmp.innerHTML = canonicalHtml;
      var newSvg = tmp.firstElementChild;
      if (newSvg) svg.replaceWith(newSvg);
    } else {
      // No svg yet → prepend canonical at the start of the element.
      el.insertAdjacentHTML('afterbegin', canonicalHtml);
    }
    el.setAttribute('data-icon-rev', ICON_REV);
  }

  function enforceCanonicalIcons() {
    var nav = document.querySelector('.app-sidebar');
    if (!nav) return;
    // 1. Every link with an href → look up canonical by its file name.
    var links = nav.querySelectorAll('.app-sidebar-item[href], a.app-sidebar-item');
    links.forEach(function (a) {
      var href = a.getAttribute('href') || '';
      var key = lastSegment(href);
      var canonical = CANONICAL_SVG[key];
      if (canonical) setCanonicalIcon(a, canonical);
    });
    // 2. Every group header → look up canonical by its span label.
    var headers = nav.querySelectorAll('.app-sidebar-group-header');
    headers.forEach(function (h) {
      var span = h.querySelector('span');
      var label = span ? (span.textContent || '').trim() : '';
      var canonical = CANONICAL_GROUP_SVG[label];
      if (canonical) setCanonicalIcon(h, canonical);
    });
  }

  // ── Auto-wrap every <table> in a horizontally-scrollable
  //    container so wide tables scroll INSIDE their card on mobile
  //    instead of forcing the whole page to scroll sideways.
  //    Idempotent via data-tbl-wrapped marker.
  function wrapTablesForMobile() {
    var tables = document.querySelectorAll('table:not([data-tbl-wrapped])');
    tables.forEach(function (t) {
      // Skip nested tables inside an existing scroll wrapper.
      var parent = t.parentElement;
      if (parent && (
        parent.classList.contains('tbl-scroll') ||
        parent.classList.contains('table-scroll') ||
        parent.classList.contains('scroll-x') ||
        parent.classList.contains('auto-tbl-scroll')
      )) {
        t.setAttribute('data-tbl-wrapped', '1');
        return;
      }
      var wrap = document.createElement('div');
      wrap.className = 'auto-tbl-scroll';
      t.parentNode.insertBefore(wrap, t);
      wrap.appendChild(t);
      t.setAttribute('data-tbl-wrapped', '1');
    });
  }

  // Inject the CSS for the auto wrapper once (mobile.css also has
  // a fallback, but this guarantees it works even if mobile.css
  // failed to load).
  if (!document.getElementById('auto-tbl-scroll-css')) {
    var ts = document.createElement('style');
    ts.id = 'auto-tbl-scroll-css';
    ts.textContent = ''
      + '.auto-tbl-scroll{max-width:100%;width:100%;}'
      + '@media (max-width:900px){'
      +   '.auto-tbl-scroll{'
      +     'overflow-x:auto !important;'
      +     '-webkit-overflow-scrolling:touch;'
      +     'overscroll-behavior-x:contain;'
      +     'max-width:100%;width:100%;'
      +   '}'
      +   /* Ensure ancestors don't grow with the table */
      +   '.app-main, .page, .panel, .perf-card, .section, .conn-card{min-width:0 !important;}'
      + '}';
    document.head.appendChild(ts);
  }

  // Inject "Confirmation" as a top-level item RIGHT AFTER Shipping
  // Analytics. Top-level items are hardcoded per-page, but injecting
  // here means we don't have to edit every HTML file.
  function injectConfirmationItem() {
    var nav = document.querySelector('.app-sidebar .app-sidebar-items');
    if (!nav) return;
    if (nav.querySelector('[data-confirmation-item]')) return;
    // Anchor: try several locators so injection works on every page —
    //  1. <a href="…shipping.html"> (most pages)
    //  2. <a> whose visible label is "Shipping Analytics" (React-rendered
    //     sidebars on shipping.html / ads.html sometimes change href format)
    //  3. As a last resort, append to the end of the top-level items
    //     above the first divider — keeps Confirmation visible no matter
    //     what the host sidebar looks like.
    var ship = nav.querySelector('a[href$="shipping.html"], a[href*="shipping.html?"]');
    if (!ship) {
      var allItems = nav.querySelectorAll('.app-sidebar-item');
      for (var i = 0; i < allItems.length; i++) {
        var span = allItems[i].querySelector('span');
        if (span && /shipping\s*analytics/i.test(span.textContent || '')) { ship = allItems[i]; break; }
      }
    }
    var prefix = getPathPrefix();
    var here = (location.pathname.split('/').pop() || '').toLowerCase();
    var inConfMode = /[?&]mode=confirmation\b/.test(location.search);
    var isActive = here === 'confirmation.html' || inConfMode;
    var html = '<a href="' + prefix + 'confirmation.html" '
             + 'class="app-sidebar-item ' + (isActive ? 'active' : '') + '" '
             + 'data-confirmation-item="1">'
             + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'
             + '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'
             + '</svg><span>Confirmation</span></a>';
    if (ship) {
      ship.insertAdjacentHTML('afterend', html);
      if (isActive) ship.classList.remove('active');
    } else {
      // Final fallback — drop it before the first divider (or at the end
      // of the top-level items if there's no divider).
      var divider = nav.querySelector('.app-sidebar-divider');
      if (divider) divider.insertAdjacentHTML('beforebegin', html);
      else nav.insertAdjacentHTML('beforeend', html);
    }
  }

  // ── Full sidebar renderer ───────────────────────────────────────────
  // Pages that opt in via <aside class="app-sidebar" data-render-sidebar>
  // get their ENTIRE sidebar (top-level items + all groups + footer)
  // built here from the JS config. Adding/removing/renaming items is a
  // one-file change. Pages that DON'T have the attribute keep their
  // hardcoded inline sidebar and rely on the inject/augment fallbacks
  // — so this is fully backwards-compatible during the migration.
  function isActive(matchPaths, here, extraActive) {
    if (extraActive) return true;
    if (!matchPaths) return false;
    return matchPaths.some(function (p) {
      if (p === '') return here === '' || here === 'index.html';
      return here === p;
    });
  }
  function itemLink(c, here, prefix, opts) {
    opts = opts || {};
    var active = opts.forceInactive ? false : isActive(c.matchPaths, here, opts.extraActive);
    var extraAttrs = opts.extraAttrs || '';
    return '<a href="' + prefix + c.href + '" class="app-sidebar-item' + (active ? ' active' : '') + '"' + extraAttrs + '>'
         + c.svg + '<span>' + c.name + '</span></a>';
  }
  function groupBlock(group, here, prefix, marker) {
    var children = group.children.map(function (c) { return itemLink(c, here, prefix); }).join('');
    return '<div class="app-sidebar-group"' + (marker ? ' data-' + marker + '="group"' : '') + '>'
         +   '<div class="app-sidebar-group-header">' + group.svg + '<span>' + group.name + '</span></div>'
         +   '<div class="app-sidebar-group-items">' + children + '</div>'
         + '</div>';
  }
  function renderSidebar(aside) {
    var here = (location.pathname.split('/').pop() || '').toLowerCase();
    var prefix = getPathPrefix();
    var search = location.search;

    // Reports group is built from REPORTS_LIST so /loop / /init flows can
    // add a new report by editing only this file.
    var reportsGroup = {
      name: 'Reports',
      svg: REPORTS_GROUP_SVG,
      children: REPORTS_LIST.map(function (r) {
        return { name: r.name, href: r.href, svg: r.svg, matchPaths: [r.slug + '.html'] };
      })
    };

    // If ANY top item has a matching extraActiveSearch, treat that as the
    // winner — path-based matches on other items lose their active state.
    // Real case: /shipping.html?mode=confirmation should highlight
    // Confirmation only, not Shipping Analytics too.
    var searchActiveItem = null;
    TOP_ITEMS.forEach(function (it) {
      if (it.extraActiveSearch && it.extraActiveSearch.test(search)) searchActiveItem = it;
    });

    var html = '<nav class="app-sidebar-items">';
    TOP_ITEMS.forEach(function (it) {
      var attrs = it.name === 'Confirmation' ? ' data-confirmation-item="1"' : '';
      var extra, forceInactive;
      if (searchActiveItem) {
        // Only the search-matched item is active; everyone else inactive.
        extra = (it === searchActiveItem);
        forceInactive = (it !== searchActiveItem);
      } else {
        extra = false;
        forceInactive = false;
      }
      html += itemLink(it, here, prefix, { extraActive: extra, forceInactive: forceInactive, extraAttrs: attrs });
    });
    html += '<div class="app-sidebar-divider"></div>';
    html += groupBlock(PRODUCTS_GROUP, here, prefix, 'products-group');
    html += '<div class="app-sidebar-divider"></div>';
    html += groupBlock(ALL_TOOLS_GROUP, here, prefix, 'all-tools-group');
    html += '<div class="app-sidebar-divider"></div>';
    html += groupBlock(AUTOMATION_TOOLS_GROUP, here, prefix, 'auto-tools-group');
    html += '<div class="app-sidebar-divider" data-reports-group="divider"></div>';
    html += groupBlock(reportsGroup, here, prefix, 'reports-group');
    html += '</nav>';
    html += '<div class="app-sidebar-footer">'
          +   '<a href="' + prefix + 'settings.html" class="app-sidebar-item" style="text-decoration:none;">'
          +     FOOTER_SETTINGS_SVG + '<span>Settings</span>'
          +   '</a>'
          + '</div>';

    aside.innerHTML = html;
    aside.setAttribute('data-sidebar-rendered', '1');
    // Expand the group that contains the active page so the user lands
    // looking at the right tool without waiting for init() to run.
    applyCollapsedState();
  }
  function renderAnyPendingSidebar() {
    var aside = document.querySelector('aside.app-sidebar[data-render-sidebar]:not([data-sidebar-rendered])');
    if (aside) renderSidebar(aside);
  }
  // Early-render: try once now (script may run before <aside> is parsed),
  // then watch <html> so we render the moment the aside appears in the DOM.
  // Catching it pre-DOMContentLoaded keeps the sidebar visible immediately
  // — no flash of an empty rail before init() fires.
  renderAnyPendingSidebar();
  if (window.MutationObserver && document.documentElement) {
    var earlyObs = new MutationObserver(function () {
      renderAnyPendingSidebar();
    });
    earlyObs.observe(document.documentElement, { childList: true, subtree: true });
  }

  function init() {
    // If the page opted into full rendering, do it now (safety net in case
    // the early observer hasn't fired yet for any reason).
    renderAnyPendingSidebar();
    // Reports first — so Products can anchor BEFORE the Reports divider and
    // land below All tools / Automation Tools.
    injectReportsGroup();
    injectToolGroups();
    injectClientsItem();
    injectConfirmationItem();
    applyCollapsedState();
    // Final pass: every sidebar icon (existing HTML, React-rendered,
    // and JS-injected) gets the same canonical SVG. Idempotent via
    // data-icon-rev so it's free on repeat runs.
    enforceCanonicalIcons();
    // Wrap any wide <table> so scroll stays inside the card.
    wrapTablesForMobile();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  // React-rendered sidebars (shipping.html, ads.html) keep re-rendering as
  // their state changes (filters, dates, etc.), each render wipes our caret
  // + dataset markers. So we watch the sidebar permanently and re-init on
  // every mutation. wireCollapsibles is idempotent (data-collapseWired
  // guard) so re-running is essentially free.
  if (window.MutationObserver) {
    var pending = false;
    function debouncedInit() {
      if (pending) return;
      pending = true;
      requestAnimationFrame(function () { pending = false; init(); });
    }
    var obs = new MutationObserver(function () {
      var nav = document.querySelector('.app-sidebar-items');
      if (nav) debouncedInit();
    });
    obs.observe(document.body, { childList: true, subtree: true });
    // No disconnect — let it run for the lifetime of the page so React
    // re-renders never strip the caret / ↳ tree indicator again.
  }
  // Polling safety net — React-rendered sidebars (shipping.html, ads.html)
  // re-render their nav and wipe injected nodes faster than MutationObserver
  // can react. We tick every 250 ms and re-inject if the Products marker is
  // missing. injectClientsItem early-returns when the marker is present, so
  // the polling cost is essentially zero on every page.
  setInterval(function () {
    var nav = document.querySelector('.app-sidebar .app-sidebar-items');
    if (!nav) return;
    if (!nav.querySelector('[data-products-group]')) {
      injectClientsItem();
    }
    if (!nav.querySelector('[data-confirmation-item]')) {
      injectConfirmationItem();
    }
    // Always run injectToolGroups — it both injects missing groups AND
    // augments existing ones with any children defined in JS but not in
    // the page's hardcoded HTML (e.g. "Financial Updater" is missing
    // from every page that ships only "Landing Page Auto"). Idempotent
    // via per-link have-set so this is essentially free on repeat runs.
    injectToolGroups();
  }, 120);
})();
