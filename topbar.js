(function () {
  var css = ''
    + '.topbar{background:#000000;color:#FFFFFF;padding:0;display:flex;flex-direction:column;position:sticky;top:0;z-index:9999;}'
    + '.topbar .topbar-row1{display:flex;align-items:center;gap:20px;padding:8px 16px;height:48px;box-sizing:border-box;}'
    + '.topbar .brand-shop{display:flex;align-items:center;gap:6px;color:#FFFFFF;font-style:italic;font-weight:800;font-size:15px;min-width:224px;text-decoration:none;}'
    + '.topbar .brand-shop .logo-icon-svg{width:22px;height:26px;flex-shrink:0;}'
    + '.topbar .search-wrap{flex:1;max-width:720px;margin:0 auto;background:#1A1A1A;border:1px solid #2A2A2A;border-radius:8px;padding:7px 12px;display:flex;align-items:center;gap:10px;box-sizing:border-box;}'
    + '.topbar .search-icon{color:#888;font-size:13px;}'
    + '.topbar .search-input{flex:1;background:transparent;border:none;outline:none;color:#FFFFFF;font-size:13px;padding:2px 0;}'
    + '.topbar .search-input::placeholder{color:#888;}'
    + '.topbar .kbd{display:inline-flex;align-items:center;gap:4px;background:#2A2A2A;border-radius:4px;padding:3px 7px;font-size:11px;font-weight:600;color:#BBB;letter-spacing:0.5px;white-space:nowrap;flex-shrink:0;}'
    + '.topbar .top-icons{display:flex;gap:10px;align-items:center;}'
    + '.topbar .icon-btn-dark{background:transparent;border:1px solid #2A2A2A;border-radius:8px;width:32px;height:32px;color:#FFFFFF;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;position:relative;padding:0;}'
    + '.topbar .icon-btn-dark:hover{background:#1A1A1A;}'
    + '.topbar .icon-btn-dark .badge-dot{position:absolute;top:-3px;right:-3px;background:#FF3B5C;color:#FFFFFF;border-radius:999px;font-size:9px;font-weight:700;min-width:14px;height:14px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:1.5px solid #000;}'
    + '.topbar .profile-trigger{display:flex;align-items:center;gap:8px;background:transparent;border:1px solid #2A2A2A;border-radius:8px;padding:3px 10px 3px 4px;color:#FFFFFF;cursor:pointer;font-family:inherit;height:32px;font-size:12px;font-weight:600;}'
    + '.topbar .profile-trigger:hover{background:#1A1A1A;}'
    + '.topbar .profile-trigger .pa{width:24px;height:24px;border-radius:6px;color:#FFFFFF;font-weight:700;font-size:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}'
    + '.topbar .profile-trigger .pn{max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
    + '.topbar .pdrop{position:absolute;top:48px;right:14px;width:280px;max-height:calc(100vh - 60px);overflow-y:auto;background:#FFFFFF;color:#1A1A1A;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.18);padding:6px;z-index:10000;font-family:inherit;}'
    + '.topbar .pdrop[hidden]{display:none;}'
    + '.topbar .pdrop .pi{display:flex;align-items:center;justify-content:space-between;padding:9px 10px;border-radius:8px;cursor:pointer;font-size:13px;}'
    + '.topbar .pdrop .pi:hover{background:#F5F5F5;}'
    + '.topbar .pdrop .pi.active{background:#F0F0F0;}'
    + '.topbar .pdrop .pi .left{display:flex;align-items:center;gap:10px;min-width:0;flex:1;}'
    + '.topbar .pdrop .pi .pav{width:30px;height:30px;border-radius:8px;color:#FFFFFF;font-weight:700;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}'
    + '.topbar .pdrop .pi .info{display:flex;flex-direction:column;min-width:0;}'
    + '.topbar .pdrop .pi .nm{font-size:13px;font-weight:600;color:#1A1A1A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
    + '.topbar .pdrop .pi .em{font-size:11px;color:#6D7175;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
    + '.topbar .pdrop .pi .check{color:#1A1A1A;font-size:13px;font-weight:700;}'
    + '.topbar .pdrop .pi .ic{font-size:14px;width:30px;text-align:center;color:#5C5F62;}'
    + '.topbar .pdrop .divider{height:1px;background:#EEEEEE;margin:6px 4px;}'
    + '.topbar .pdrop .pi.create{color:#1A1A1A;font-weight:600;}'
    + '.topbar .pdrop .pi.logout{color:#444;}'
    // Mobile: search bar is hidden, so push the bell + profile pill
    // (and the profile-trigger directly, in case top-icons is missing)
    // hard to the right edge — same visual position as on desktop.
    + '@media (max-width:768px){'
    +   '.topbar .topbar-row1 > .top-icons{margin-left:auto !important;}'
    +   '.topbar .topbar-row1 > .profile-trigger{margin-left:auto !important;}'
    +   '.topbar .top-icons{margin-left:auto !important;}'
    + '}';

  var logoSvg = ''
    + '<svg class="logo-icon-svg" viewBox="0 0 109 124" xmlns="http://www.w3.org/2000/svg">'
    + '<path d="M74.7,14.8c-0.1-0.6-0.6-1-1.1-1c-0.5,0-9.3,0.7-9.3,0.7s-6.2-6.1-6.9-6.8c-0.7-0.7-2-0.5-2.5-0.3 c-0.1,0-1.3,0.4-3.4,1.1c-2-5.8-5.6-11.1-11.8-11.1c-0.2,0-0.3,0-0.5,0C37.3,0.7,36,0,34.5,0C22.9,0,17.4,14.5,15.6,21.9 c-4.5,1.4-7.8,2.4-8.2,2.5c-2.5,0.8-2.6,0.9-2.9,3.2C4.2,29.5,0,67.4,0,67.4l40.4,7.6L80.5,67c0,0-5.7-38.2-5.8-51.5L74.7,14.8z M48.7,11.3l-5.5,1.7c0-0.4,0-0.7,0-1.1c0-3.4-0.5-6.2-1.2-8.4C44.7,4,46.9,7.4,48.7,11.3z M37.7,4.2c0.8,2.1,1.4,5,1.4,9 c0,0.2,0,0.4,0,0.6c-3.6,1.1-7.5,2.3-11.4,3.5C29.9,9.8,33.9,5.7,37.7,4.2z M33.3,1.5c0.7,0,1.4,0.2,2.1,0.7 c-5.1,2.4-10.6,8.5-12.9,20.6c-3.1,1-6.2,1.9-9,2.8C16,18.8,20.6,1.5,33.3,1.5z" fill="#95BF47"/>'
    + '<path d="M73.6,13.7c-0.5,0-9.3,0.7-9.3,0.7s-6.2-6.1-6.9-6.8c-0.3-0.3-0.6-0.4-1-0.5L54.5,124l40.1-8.7 c0,0-9.7-65.6-9.8-66.1C84.7,48.7,74.2,14.7,73.6,13.7z" fill="#5E8E3E"/>'
    + '<path d="M48.4,30.5l-4.9,14.6c0,0-4.3-2.3-9.6-2.3c-7.8,0-8.1,4.9-8.1,6.1c0,6.7,17.5,9.3,17.5,25 c0,12.4-7.8,20.3-18.4,20.3c-12.7,0-19.2-7.9-19.2-7.9l3.4-11.3c0,0,6.7,5.7,12.3,5.7c3.7,0,5.2-2.9,5.2-5 c0-8.8-14.4-9.2-14.4-23.5C12.2,40.1,20.9,28.4,38,28.4C44.8,28.4,48.4,30.5,48.4,30.5z" fill="#FFFFFF"/>'
    + '</svg>';

  var hamburgerSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';

  var html = ''
    + '<header class="topbar">'
    + '<div class="topbar-row1">'
    + '<button class="topbar-hamburger" id="topbarHamburger" type="button" aria-label="Open menu">' + hamburgerSvg + '</button>'
    + '<a href="shipping.html" class="brand-shop">' + logoSvg + '<span>shopify</span></a>'
    + '<div class="search-wrap">'
    + '<span class="search-icon">🔍</span>'
    + '<input class="search-input" type="text" placeholder="Search">'
    + '<span class="kbd">CTRL K</span>'
    + '</div>'
    + '<div class="top-icons">'
    + '<button class="icon-btn-dark" title="Notifications">🔔<span class="badge-dot">1</span></button>'
    + '<button class="profile-trigger" id="topbarProfileBtn" type="button" title="Switch brand"><span class="pa" id="topbarProfileAvatar">SG</span><span class="pn" id="topbarProfileName">Loading…</span></button>'
    + '</div>'
    + '<div class="pdrop" id="topbarProfileDrop" hidden></div>'
    + '</div>'
    + '</header>';

  // Brand-color palette (deterministic per brand id so the same brand always
  // gets the same colored tile across the app).
  var BRAND_COLORS = ['#FF4D8D','#7A4DFF','#3CCBA8','#FFA94D','#4D8DFF','#FF6B6B','#8B5CF6','#10B981'];
  function brandColor(id){ return BRAND_COLORS[(parseInt(id||0,10) || 0) % BRAND_COLORS.length]; }
  function brandInitials(name){
    return String(name||'?').trim().split(/\s+/).map(function(w){return w[0]||'';}).join('').slice(0,2).toUpperCase() || '?';
  }

  // Special "All brands" admin profile — id=0 means no filtering.
  var ADMIN_PROFILE = { id: 0, name: 'All brands (Admin)', color: '#1A1A1A', isAdmin: true };

  // ── Active brand persisted across pages ─────────────────────────────
  function getActiveBrandId(){
    try { var v = localStorage.getItem('app.activeBrandId'); return v == null ? 0 : parseInt(v, 10); } catch(e){ return 0; }
  }
  function setActiveBrandId(id){
    try { localStorage.setItem('app.activeBrandId', String(id)); } catch(e){}
    window.dispatchEvent(new CustomEvent('app:brandChanged', { detail: { brandId: id } }));
  }

  // ── Load brand list (cached for this page) ──────────────────────────
  var __brandsCache = null;
  function brandsApiBase(){
    // Same host the rest of the app uses. Falls back to current origin in dev.
    return 'https://indigo-dog-836598.hostingersite.com/api';
  }
  function brandsApiToken(){ return 'tk_4d2b9f7a8c6e1530a4f2d9b7e8c6a1f5'; }
  async function loadBrands(){
    if (__brandsCache) return __brandsCache;
    try {
      var r = await fetch(brandsApiBase() + '/brands.php', { headers: { 'Authorization': 'Bearer ' + brandsApiToken() }});
      if (!r.ok) return [];
      var d = await r.json();
      __brandsCache = (d.rows || []).filter(function(b){ return b && b.id; });
      return __brandsCache;
    } catch(e){ return []; }
  }

  function renderTrigger(activeBrand){
    var av = document.getElementById('topbarProfileAvatar');
    var nm = document.getElementById('topbarProfileName');
    if (!av || !nm) return;
    if (activeBrand.isAdmin) {
      av.style.background = '#1A1A1A';
      av.textContent = 'AD';
      av.innerHTML = 'AD';
      nm.textContent = 'All brands';
      return;
    }
    var logo = activeBrand.meta && activeBrand.meta.logo;
    if (logo) {
      av.style.background = '#F1F1F1';
      av.innerHTML = '<img src="' + logo + '" style="width:100%;height:100%;object-fit:cover;border-radius:6px;display:block;">';
    } else {
      av.style.background = brandColor(activeBrand.id);
      av.textContent = brandInitials(activeBrand.name);
    }
    nm.textContent = activeBrand.name;
  }

  function escapeHtml(s){return String(s == null ? '' : s).replace(/[&<>"']/g, function(m){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);});}

  function renderDropdown(brands, activeId){
    var drop = document.getElementById('topbarProfileDrop');
    if (!drop) return;
    var rows = [];
    var allActive = activeId === 0;
    rows.push(
      '<div class="pi ' + (allActive?'active':'') + '" data-bid="0">'
      + '<div class="left"><div class="pav" style="background:#1A1A1A;">AD</div>'
      + '<div class="info"><span class="nm">All brands</span><span class="em">Admin view — sees every brand</span></div></div>'
      + (allActive ? '<span class="check">✔</span>' : '') + '</div>'
    );
    rows.push('<div class="divider"></div>');
    if (!brands.length) {
      rows.push('<div class="pi" style="color:#6D7175;font-size:12px;cursor:default;">No brands yet — create one below.</div>');
    } else {
      brands.forEach(function(b){
        var act = b.id === activeId;
        var logo = b.meta && b.meta.logo;
        var avHtml = logo
          ? '<div class="pav" style="background:#F1F1F1;overflow:hidden;"><img src="' + logo + '" style="width:100%;height:100%;object-fit:cover;display:block;"></div>'
          : '<div class="pav" style="background:' + brandColor(b.id) + ';">' + escapeHtml(brandInitials(b.name)) + '</div>';
        rows.push(
          '<div class="pi ' + (act?'active':'') + '" data-bid="' + b.id + '">'
          + '<div class="left">' + avHtml
          + '<div class="info"><span class="nm">' + escapeHtml(b.name) + '</span>'
          + (b.client_name ? '<span class="em">' + escapeHtml(b.client_name) + '</span>' : '')
          + '</div></div>'
          + (act ? '<span class="check">✔</span>' : '') + '</div>'
        );
      });
    }
    rows.push('<div class="divider"></div>');
    rows.push('<a class="pi create" href="settings.html#brands" style="text-decoration:none;color:inherit;"><div class="left"><span class="ic">＋</span><span class="nm">Create store</span></div></a>');
    rows.push('<a class="pi" href="settings.html#account" style="text-decoration:none;color:inherit;"><div class="left"><span class="ic">⚙</span><span class="nm">Account settings</span></div></a>');
    rows.push('<div class="divider"></div>');
    rows.push('<div class="pi logout" id="pdLogout"><div class="left"><span class="ic">⎋</span><span class="nm">Log out</span></div></div>');
    drop.innerHTML = rows.join('');

    drop.querySelectorAll('.pi[data-bid]').forEach(function(el){
      el.addEventListener('click', function(){
        var bid = parseInt(el.getAttribute('data-bid'), 10);
        setActiveBrandId(bid);
        var active = bid === 0 ? ADMIN_PROFILE : (brands.find(function(x){return x.id===bid;}) || ADMIN_PROFILE);
        renderTrigger(active);
        renderDropdown(brands, bid);
        drop.hidden = true;
      });
    });
    var lo = drop.querySelector('#pdLogout');
    if (lo) lo.addEventListener('click', function(){
      try { localStorage.removeItem('app.userProfile.v1'); } catch(e){}
      // No real auth yet — just bounce to settings as a placeholder.
      window.location.href = 'settings.html#account';
    });
  }

  async function refresh(){
    // Invalidate the cache so a brand that just had its logo / name updated
    // shows the fresh data immediately (no page reload needed).
    __brandsCache = null;
    var brands = await loadBrands();
    var activeId = getActiveBrandId();
    if (activeId !== 0 && !brands.some(function(b){ return b.id === activeId; })) activeId = 0;
    var active = activeId === 0 ? ADMIN_PROFILE : brands.find(function(b){return b.id===activeId;});
    renderTrigger(active);
    renderDropdown(brands, activeId);
  }

  function ensureViewportMeta(){
    if (!document.querySelector('meta[name="viewport"]')) {
      var m = document.createElement('meta');
      m.name = 'viewport'; m.content = 'width=device-width, initial-scale=1.0, viewport-fit=cover';
      document.head.appendChild(m);
    }
  }
  function ensureMobileCss(){
    if (document.getElementById('app-mobile-css')) return;
    var link = document.createElement('link');
    link.id = 'app-mobile-css';
    link.rel = 'stylesheet';
    // Cache-bust so updates to mobile.css reach users immediately.
    // Bump this constant whenever mobile.css changes meaningfully.
    var v = '20260503h';
    link.href = (/\/reports\//.test(location.pathname) ? '../' : '') + 'mobile.css?v=' + v;
    document.head.appendChild(link);
  }
  function wireDrawer(){
    var btn = document.getElementById('topbarHamburger');
    if (!btn) return;
    // Sidebar in React-rendered pages (shipping.html, ads.html) might not
    // exist yet at init time. Always re-query at click time and rely on
    // event delegation for backdrop / link-tap-to-close.
    var backdrop = document.querySelector('.app-sidebar-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'app-sidebar-backdrop';
      document.body.appendChild(backdrop);
    }
    function getSb(){ return document.querySelector('.app-sidebar'); }
    function open(){ var sb = getSb(); if (!sb) return; sb.classList.add('open'); backdrop.classList.add('open'); }
    function close(){ var sb = getSb(); if (sb) sb.classList.remove('open'); backdrop.classList.remove('open'); }
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      var sb = getSb();
      if (!sb) return;
      sb.classList.contains('open') ? close() : open();
    });
    backdrop.addEventListener('click', close);
    // Delegated link-tap-to-close — survives React re-renders.
    document.addEventListener('click', function(e){
      var sb = getSb();
      if (!sb || !sb.classList.contains('open')) return;
      if (sb.contains(e.target) && e.target.closest('a, .app-sidebar-item')) close();
    });
    window.addEventListener('resize', function(){ if (window.innerWidth > 768) close(); });
  }

  function init() {
    if (document.getElementById('shopify-topbar-styles')) return;
    ensureViewportMeta();
    ensureMobileCss();
    var style = document.createElement('style');
    style.id = 'shopify-topbar-styles';
    style.textContent = css;
    document.head.appendChild(style);
    // If a page has a static <header class="topbar"> from before topbar.js
    // existed, swap it out so the new dropdown profile actually appears.
    var existing = document.querySelector('header.topbar');
    if (existing) {
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      existing.replaceWith(tmp.firstElementChild);
    } else {
      document.body.insertAdjacentHTML('afterbegin', html);
    }

    var btn = document.getElementById('topbarProfileBtn');
    var drop = document.getElementById('topbarProfileDrop');
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      drop.hidden = !drop.hidden;
    });
    document.addEventListener('click', function(e){
      if (!drop.hidden && !drop.contains(e.target) && !btn.contains(e.target)) drop.hidden = true;
    });

    refresh();
    wireDrawer();
    // Other tabs / pages can update the list — listen to storage events.
    window.addEventListener('storage', function (e) { if (e.key === 'app.activeBrandId') refresh(); });
    window.refreshTopbarProfile = refresh;
    // Expose so other pages can read the current brand without re-implementing.
    window.getActiveBrandId = getActiveBrandId;
    window.setActiveBrandId = setActiveBrandId;
    // Returns the full active brand object (with meta.assets, meta.ad_account_ids,
    // meta.sku_prefixes…) so other pages can filter their data. Returns null
    // when the user is on the "All brands" admin view.
    window.getActiveBrand = function getActiveBrand(){
      const id = getActiveBrandId();
      if (!id || !__brandsCache) return null;
      return __brandsCache.find(b => b.id === id) || null;
    };
    // Async variant — waits for the brands list to load on first call.
    window.getActiveBrandAsync = async function getActiveBrandAsync(){
      const id = getActiveBrandId();
      if (!id) return null;
      const list = await loadBrands();
      return list.find(b => b.id === id) || null;
    };
    // Full brands list (cached). Used by Orders/Shipping/Ads to map selected
    // CMS-store ids → owning brand → that brand's sku_prefixes, so the Store
    // dropdown can drive prefix-filtering even when topbar is "All brands".
    window.getAllBrandsAsync = async function getAllBrandsAsync(){
      return await loadBrands();
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
