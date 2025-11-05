// BKMB Subsales PWA client (plugin-hosted) — single-file clean implementation
(function(){
  'use strict';
  // Prefer plugin-localized settings (PHP uses SUBSALES_PWA_CONFIG), fall back to legacy BKMB_PWA_CONFIG
  const cfg = window.SUBSALES_PWA_CONFIG || window.BKMB_PWA_CONFIG || {};
  const apiBase = (cfg.apiBase || cfg.api_base || localStorage.getItem('API_BASE_URL') || '').replace(/\/+$/, '');
  const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
  const portalBase = cfg.portalBase || '';
  const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';
  const brandName = cfg.brandName || cfg.brand_name || 'Subsales';
  const brandingImage = cfg.brandingImage || cfg.branding_image || '';

  // Apply runtime style overrides from admin settings (primary color and variant)
  (function applyRuntimeStyles(){
    try{
      const primary = cfg.primaryColor || cfg.primary_color || '#2d6cdf';
      const variant = cfg.styleVariant || cfg.style_variant || 'default';
      // set CSS variable for primary color (SM prefix)
      document.documentElement.style.setProperty('--sm-primary', primary);
      // set body class for variant handling (styles defined in stylesheet)
      document.body.classList.remove('sm-variant-default','sm-variant-flat','sm-variant-rounded','sm-variant-dark');
      document.body.classList.add('sm-variant-' + (variant || 'default'));
    }catch(e){}
  })();

  const qs = s => document.querySelector(s);

  // Ensure root container
  // Prefer server-rendered shortcode root `subsales-pwa-root`, then older `sm-pwa-root`, else create `subsales-pwa-root`.
  const root = qs('#subsales-pwa-root') || qs('#sm-pwa-root') || (function(){ const d=document.createElement('div'); d.id='subsales-pwa-root'; document.body.appendChild(d); return d; })();

  // Single clean UI template: only inject if the host page did not provide a loginSection
  if (!qs('#loginSection')) {
    // Load centralized stylesheet from plugin when injecting UI (falls back if already loaded)
    (function loadPwaStylesheet(){
      try{
        const id = 'subsales-pwa-style-link';
        if (document.getElementById(id)) return;
        // pluginBase is provided by localized SUBSALES_PWA_CONFIG when enqueued via WordPress
        if (!pluginBase) return;
        const href = pluginBase.endsWith('/') ? (pluginBase + 'styles.css') : (pluginBase + '/styles.css');
        const link = document.createElement('link'); link.rel = 'stylesheet'; link.id = id; link.href = href; document.head.appendChild(link);
      }catch(e){}
    })();
    root.innerHTML = `
      <div id="sm-pwa-ui">
        <header class="sm-header">
          <div class="sm-header-left"></div>
          <div class="sm-header-center">
            <img id="brandHeaderImage" class="sm-brand-image" src="${brandingImage||''}" /><br/>
            <h1 class="sm-brand-name">${brandName}</h1>
          </div>
          <div class="sm-header-right">
            <div id="headerStatus" class="sm-auth-hidden" title="Network status"><span id="headerDot" class="sm-header-dot"></span><span id="headerStatusText">Offline</span></div>
            <div id="installBox" class="sm-install-box hidden"><button id="installBtn" class="sm-btn">Install App</button></div>
            <button id="myOrdersBtn" class="sm-btn sm-auth-hidden">My orders</button>
            <button id="openInlayBtn" class="sm-btn hidden">Queued Orders</button>
          </div>
        </header>
        <main class="sm-main">
          <section id="loginSection" class="sm-login-section">
            <h2>Team Login</h2>
            <input id="teamName" placeholder="Team name" />
            <input id="teamCode" placeholder="Access code" />
            <div class="sm-row"><button id="loginBtn" class="sm-btn">Login</button></div>
          </section>
          <section id="appSection" class="sm-app-section hidden">
            <div class="sm-row">
              <div class="sm-leftcol">
                <h2>Create Order</h2>
                <label>Customer name</label>
                <input id="customerName" placeholder="Customer name" />
                <label>Address</label>
                <input id="address" placeholder="Address" />
                <label for="cellNumber">Cell number</label>
                <input id="cellNumber" type="tel" inputmode="tel" placeholder="Cell number" maxlength="10" />
                <div class="row row-spaced">
                  <div class="col-2"><label>Turkey</label><input id="turkeyQty" type="number" min="0" placeholder="0" /></div>
                  <div class="col-2"><label>Ham</label><input id="hamQty" type="number" min="0" placeholder="0" /></div>
                  <div class="col-2"><label>Combo</label><input id="comboQty" type="number" min="0" placeholder="0" /></div>
                </div>
                <label>Donation amount (USD)</label>
                <input id="donationAmount" type="number" min="0" step="0.01" placeholder="$0.00" />
                <div class="order-total"><strong>Order total: <span id="orderTotal">$0.00</span></strong></div>
                <div class="pay-options">
                  <label for="payCheck"><input type="checkbox" id="payCheck" /> <span class="pay-label">Pay by check</span></label>
                  <label for="payCash"><input type="checkbox" id="payCash" /> <span class="pay-label">Pay by cash</span></label>
                </div>
                <div id="checkNumberRow" class="hidden check-number-row"><label>Check number</label><input id="checkNumber" placeholder="Check number" /></div>
                <label>Notes</label>
                <textarea id="notes" placeholder="Notes (optional)"></textarea>
                <div class="btn-row"><button id="saveOrderBtn" class="sm-btn">Save Order</button></div>
              </div>
              <aside class="sm-aside">
                <h3>Status</h3>
                <div id="networkStatus" class="status-offline">Offline</div>
                <div id="syncStatus">Not synced</div>
              </aside>
            </div>
            <h3>Local orders</h3>
            <div id="ordersList"></div>
          </section>
        </main>
      </div>`;
  }

  // Populate any existing header elements (server-side shortcode output) with branding
  (function populateBranding(){
    try{
      // Set any header images with id `brandHeaderImage`
      const imgs = document.querySelectorAll('#brandHeaderImage');
      imgs.forEach(img => {
        if (brandingImage) { img.src = brandingImage; img.classList.remove('hidden'); }
          else { img.classList.add('hidden'); }
      });

  // Update visible headings inside plugin roots
  const hdrs = document.querySelectorAll('#subsales-pwa-root h1, #sm-pwa-root h1');
      hdrs.forEach(h => { h.textContent = brandName; });
      // Update document title
      try { document.title = brandName + ' — PWA'; } catch(e){}
      // Ensure there's an open-inlay button in the header (for pages that don't render it)
      const header = document.querySelector('#subsales-pwa-root header, #sm-pwa-root header, header');
      if (header && !document.querySelector('#openInlayBtn')) {
        const btn = document.createElement('button'); btn.id = 'openInlayBtn'; btn.textContent = 'Queued Orders'; btn.className = 'sm-btn hidden';
        header.appendChild(btn);
      }
    }catch(e){ /* noop */ }
  })();

  // Inlay (popup) creation and rendering
  function ensureInlayExists(){
    if (qs('#ordersInlay')) return;
    const div = document.createElement('div');
    div.id = 'ordersInlay';
    div.className = 'orders-inlay hidden';
    div.innerHTML = `
      <div class="inlay-header">
        <strong>Queued Orders</strong>
        <button id="closeInlayBtn" class="sm-btn">Close</button>
      </div>
      <table>
        <thead><tr><th>Order ID</th><th>Name</th></tr></thead>
        <tbody id="inlayTableBody"></tbody>
      </table>
    `;
    document.body.appendChild(div);
    // attach handlers
    const closeBtn = qs('#closeInlayBtn'); if (closeBtn) closeBtn.addEventListener('click', ()=>{ qs('#ordersInlay').classList.add('hidden'); });
    const openBtn = qs('#openInlayBtn'); if (openBtn) openBtn.addEventListener('click', ()=>{ qs('#ordersInlay').classList.remove('hidden'); });
  }

  async function renderInlay(){
    ensureInlayExists();
    const list = await Storage.all();
    const tbody = qs('#inlayTableBody'); if (!tbody) return;
    // Only show queued-orders inlay when an authenticated session exists
    function isAuthenticated(){
      try{
        const team = localStorage.getItem('teamName');
        const code = localStorage.getItem('teamCode');
        const expiry = localStorage.getItem('sessionExpiry');
        if (!team || !code) return false;
        if (!expiry) return false;
        const exp = new Date(expiry).getTime();
        return exp && exp > Date.now();
      }catch(e){ return false; }
    }

    if (!isAuthenticated()) {
      // if not authenticated, hide the open-inlay control and avoid exposing queued orders
      tbody.innerHTML = '<tr><td colspan="2">Please log in to view queued orders</td></tr>';
      qs('#openInlayBtn') && qs('#openInlayBtn').classList.add('hidden');
      return;
    }

    if (!list || !list.length) { tbody.innerHTML = '<tr><td colspan="2">No queued orders</td></tr>'; qs('#openInlayBtn') && qs('#openInlayBtn').classList.add('hidden'); return; }
    tbody.innerHTML = list.map(o=>`<tr><td>${o.id}</td><td>${(o.customer||'')}</td></tr>`).join('');
    qs('#openInlayBtn') && qs('#openInlayBtn').classList.remove('hidden');
  }

  // Storage: IndexedDB with localStorage fallback
  const Storage = (function(){
    const DB = 'bkmb-pwa-db';
    const STORE = 'orders';
    function idbOpen(){
      return new Promise((resolve)=>{
        if (!('indexedDB' in window)) return resolve(null);
        const r = indexedDB.open(DB, 1);
        r.onupgradeneeded = () => { try { r.result.createObjectStore(STORE, { keyPath: 'id' }); } catch(e){} };
        r.onsuccess = ()=>resolve(r.result);
        r.onerror = ()=>resolve(null);
      });
    }
    async function getDB(){ if (!window._bkmb_db) window._bkmb_db = await idbOpen(); return window._bkmb_db; }
    async function add(o){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).put(o); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); list.push(o); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    async function all(){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readonly'); const req = tx.objectStore(STORE).getAll(); req.onsuccess = ()=>res(req.result||[]); req.onerror = ()=>res([]); }); } return JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); }
    async function remove(id){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).delete(id); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]').filter(x=>x.id!==id); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    return { add, all, remove };
  })();

  // Elements
  const loginSection = qs('#loginSection');
  const appSection = qs('#appSection');
  const loginBtn = qs('#loginBtn');
  const saveOrderBtn = qs('#saveOrderBtn');
  const ordersList = qs('#ordersList');
  const networkStatus = qs('#networkStatus');
  const syncStatus = qs('#syncStatus');
  const installBox = qs('#installBox');
  const installBtn = qs('#installBtn');

  const turkeyQty = qs('#turkeyQty');
  const hamQty = qs('#hamQty');
  const comboQty = qs('#comboQty');
  const donationAmount = qs('#donationAmount');
  const orderTotalEl = qs('#orderTotal');
  const payCheck = qs('#payCheck');
  const payCash = qs('#payCash');
  const checkNumberRow = qs('#checkNumberRow');
  const checkNumber = qs('#checkNumber');

  // Defensive address getter: tries the canonical #address first, then several
  // common alternate selectors (autocomplete-injected inputs). If an alternate
  // is found it will copy the value back to the canonical input and dispatch
  // an input event so any listeners or validations pick up the change.
  function getCanonicalAddress(){
    try{
      const primary = qs('#address');
      const primaryVal = primary && primary.value && primary.value.trim();
      if (primaryVal) return primaryVal;
      const altSelectors = [
        '#addressAutocomplete',
        '.pac-target-input',
        'input[aria-label="Address suggestions"]',
        'input[placeholder="Address"]',
        'input[role="combobox"]',
        '.gm-places-autocomplete input'
      ];
      for (const sel of altSelectors){
        const alt = qs(sel);
        if (alt && alt.value && alt.value.trim()){
          const v = alt.value.trim();
          if (primary) { primary.value = v; try{ primary.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} }
          console.debug('getCanonicalAddress: fallback selector matched', sel, v);
          return v;
        }
      }
      // some injected elements expose textContent rather than value
      for (const sel of altSelectors){
        const alt = qs(sel);
        if (alt && alt.textContent && alt.textContent.trim()){
          const v = alt.textContent.trim();
          if (primary) { primary.value = v; try{ primary.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} }
          console.debug('getCanonicalAddress: fallback textContent matched', sel, v);
          return v;
        }
      }
      console.debug('getCanonicalAddress: no address found', { primaryPresent: !!primary, primaryVal });
    }catch(e){ console.warn('getCanonicalAddress error', e); }
    return (qs('#address') && qs('#address').value && qs('#address').value.trim()) || '';
  }

  // Enforce 10-digit phone rule and basic formatting live for cellNumber
  (function setupPhoneValidation(){
    const pn = qs('#cellNumber');
    if (!pn) return;
    // Ensure maxlength is set (defensive)
    try { pn.setAttribute('maxlength','10'); } catch(e){}
    pn.addEventListener('input', ()=>{
      try{
        // keep only digits and cap at 10
        const digits = pn.value.replace(/\D/g,'').slice(0,10);
        if (pn.value !== digits) pn.value = digits;
        if (digits.length === 10) { pn.classList.remove('invalid'); try{ pn.setCustomValidity(''); }catch(e){} }
        else { pn.classList.add('invalid'); try{ pn.setCustomValidity('Phone number must be 10 digits'); }catch(e){} }
      }catch(e){}
    });
    pn.addEventListener('blur', ()=>{
      try{ const v = pn.value.replace(/\D/g,''); if (v.length !== 10) { try{ pn.reportValidity(); }catch(e){} } }catch(e){}
    });
  })();

  // Install prompt handling
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e)=>{
    try{
      e.preventDefault();
      deferredPrompt = e;
      // expose to window for debugging
      try{ window._deferredPWAPrompt = e; }catch(ex){}
      installBox && installBox.classList.remove('hidden');
      console.log('PWA beforeinstallprompt event captured; call install button to prompt.');
    }catch(err){ console.warn('beforeinstallprompt handler error', err); }
  });

  installBtn && installBtn.addEventListener('click', async ()=>{
    try{
      if (!deferredPrompt) {
        // Provide actionable diagnostics to help the user understand why install isn't available
        let manifestHref = '(none)';
        try{ const m = document.querySelector('link[rel="manifest"]'); manifestHref = m ? m.href : '(none)'; }catch(e){}
        const protocol = location.protocol || '(unknown)';
        let swRegs = [];
        if ('serviceWorker' in navigator) {
          try { swRegs = await navigator.serviceWorker.getRegistrations(); } catch(e){ swRegs = []; }
        }
        const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone || false;
        const msg = 'Install not available. Possible reasons:\n - Not a secure origin (HTTPS or localhost) (current: ' + protocol + ')\n - Service worker not registered (registrations=' + (swRegs ? swRegs.length : 0) + ')\n - Manifest not available (href=' + manifestHref + ')\n - Already installed (display-mode: ' + isStandalone + ')\n\nCheck the console for more details.';
        alert(msg);
        console.log('PWA install diagnostics', { protocol, manifestHref, swRegistrations: swRegs, isStandalone });
        return;
      }
      deferredPrompt.prompt();
      try{ await deferredPrompt.userChoice; }catch(e){}
      deferredPrompt = null;
      try{ window._deferredPWAPrompt = null; }catch(e){}
      installBox && installBox.classList.add('hidden');
    }catch(err){ console.warn('installBtn click error', err); }
  });

  function updateNetworkUI(){ if (networkStatus) { if (navigator.onLine) { networkStatus.textContent='Online'; networkStatus.classList.remove('status-offline'); networkStatus.classList.add('status-online'); } else { networkStatus.textContent='Offline'; networkStatus.classList.remove('status-online'); networkStatus.classList.add('status-offline'); } } }
  window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); });
  window.addEventListener('offline', updateNetworkUI);
  updateNetworkUI();

  // Also update header status indicator if present
  function updateHeaderStatus(){
    const statusEl = qs('#headerStatus');
    const dot = qs('#headerDot');
    const txt = qs('#headerStatusText');
    // Update top-level status element (we style the dot via #headerStatus)
    if (statusEl) {
      if (navigator.onLine) { statusEl.classList.remove('status-offline'); statusEl.classList.add('status-online'); }
      else { statusEl.classList.remove('status-online'); statusEl.classList.add('status-offline'); }
    }
    // Backwards-compatible inner dot/text updates (if present)
    if (dot) {
      if (navigator.onLine) { dot.classList.remove('status-offline'); dot.classList.add('status-online'); }
      else { dot.classList.remove('status-online'); dot.classList.add('status-offline'); }
    }
    if (txt) {
      if (navigator.onLine) { txt.textContent = 'Online'; txt.classList.remove('status-offline'); txt.classList.add('status-online'); }
      else { txt.textContent = 'Offline'; txt.classList.remove('status-online'); txt.classList.add('status-offline'); }
    }
  }
  window.addEventListener('online', updateHeaderStatus);
  window.addEventListener('offline', updateHeaderStatus);
  updateHeaderStatus();

  // Reveal auth-only controls that are server-rendered hidden with `.sm-auth-hidden`
  function revealAuthControls(){
    try{
      const nodes = document.querySelectorAll('.sm-auth-hidden');
      nodes.forEach(n => n.classList.remove('sm-auth-hidden'));
    }catch(e){}
  }

  function computeTotal(){ const t = parseInt(turkeyQty && turkeyQty.value,10) || 0; const h = parseInt(hamQty && hamQty.value,10) || 0; const c = parseInt(comboQty && comboQty.value,10) || 0; const d = parseFloat(donationAmount && donationAmount.value) || 0; const perItem = 10.00; const total = (t + h + c) * perItem + d; if (orderTotalEl) orderTotalEl.textContent = '$' + total.toFixed(2); return total; }
  [turkeyQty, hamQty, comboQty, donationAmount].forEach(el=>{ if (!el) return; el.addEventListener('input', computeTotal); });

  // Clear the order form inputs after a successful save
  function clearOrderForm(){
    try{
      const fields = ['#customerName','#address','#cellNumber','#notes','#turkeyQty','#hamQty','#comboQty','#donationAmount','#checkNumber'];
      fields.forEach(s=>{ const el = qs(s); if (!el) return; if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') { try{ el.value = ''; }catch(e){} } });
      if (payCheck) payCheck.checked = false; if (payCash) payCash.checked = false;
      try{ if (checkNumberRow) checkNumberRow.classList.add('hidden'); }catch(e){}
      // reset computed total
      try{ if (orderTotalEl) orderTotalEl.textContent = '$0.00'; }catch(e){}
      // re-run compute to update total state
      try{ computeTotal(); }catch(e){}
    }catch(e){ console.warn('clearOrderForm failed', e); }
  }

  if (payCheck) payCheck.addEventListener('change', ()=>{ if (payCheck.checked) { checkNumberRow && checkNumberRow.classList.remove('hidden'); if (payCash) payCash.checked=false; } else { checkNumberRow && checkNumberRow.classList.add('hidden'); } });
  if (payCash) payCash.addEventListener('change', ()=>{ if (payCash.checked) { if (payCheck) { payCheck.checked=false; checkNumberRow && checkNumberRow.classList.add('hidden'); } } });

  async function renderOrders(){
    // Main orders list is no longer used for queued orders; they are shown in the inlay.
    const list = await Storage.all();
    if (!ordersList) return;
    // keep main area minimal
    ordersList.innerHTML = '<div>No local orders</div>';
    // render queued orders into the inlay popup
    await renderInlay();
  }

  // Login flow: POST to /auth/login to validate credentials and fetch server-side config
  async function serverLogin(team, code){
    try{
      const url = apiBase ? (apiBase + '/auth/login') : '/wp-json/order-manager/v1/auth/login';
      const resp = await fetch(url, { method:'POST', headers:{ 'Content-Type':'application/json' }, body: JSON.stringify({ team_name: team, access_code: code }) });
      if (!resp.ok) {
        const txt = await resp.text().catch(()=>'');
        throw new Error('Login failed: ' + resp.status + ' ' + txt);
      }
      const body = await resp.json().catch(()=>null);
      // store team id/name if provided by server
      if (body && body.team) {
        try { localStorage.setItem('teamId', body.team.id); } catch(e){}
        try { localStorage.setItem('teamName', body.team.name); } catch(e){}
      }
      return body || { success:true };
    }catch(err){ console.warn('serverLogin error', err); throw err; }
  }

  // Fetch team members from server for authenticated team
  async function fetchTeamMembers(){
    try{
      const url = apiBase ? (apiBase + '/teams/members') : '/wp-json/order-manager/v1/teams/members';
      const teamName = localStorage.getItem('teamName')||'';
      const teamCode = localStorage.getItem('teamCode')||'';
      const resp = await fetch(url, { headers: { 'X-Team-Name': teamName, 'X-Access-Code': teamCode } });
      if (!resp.ok) return [];
      const j = await resp.json().catch(()=>[]);
      return Array.isArray(j) ? j : [];
    }catch(e){ console.warn('fetchTeamMembers failed', e); return []; }
  }

  async function fetchAppConfig(){
    try{
      const url = apiBase ? (apiBase + '/config') : '/wp-json/order-manager/v1/config';
      const teamName = localStorage.getItem('teamName')||'';
      const teamCode = localStorage.getItem('teamCode')||'';
      const resp = await fetch(url, { headers: { 'X-Team-Name': teamName, 'X-Access-Code': teamCode } });
      if (!resp.ok) return null;
      const j = await resp.json().catch(()=>null);
      if (j && j.google_maps_api_key) {
        localStorage.setItem('bkmb_google_maps_key', j.google_maps_api_key);
        return j;
      }
      return j;
    }catch(e){ console.warn('fetchAppConfig failed', e); return null; }
  }
  // session duration will be provided by server-localized config as milliseconds (cfg.sessionDuration)

  // Populate a team member <select> element with members array
  function populateTeamMemberSelect(members){
    if (!members) members = [];
    let sel = qs('#teamMemberSelect');
    if (!sel) {
  sel = document.createElement('select'); sel.id = 'teamMemberSelect'; sel.className = 'sm-select'; sel.innerHTML = '<option value="">Choose your name</option>';
      if (loginSection) loginSection.appendChild(sel);
    }
    sel.innerHTML = '<option value="">Choose your name</option>' + members.map(m=>`<option value="${m.id}">${m.name||m.display_name||m.email||m.id}</option>`).join('');
  }

  // Continue to app after selecting member
  function ensureContinueButton(){
    if (qs('#loginContinueBtn')) return;
  const btn = document.createElement('button'); btn.id = 'loginContinueBtn'; btn.textContent = 'Continue'; btn.className = 'sm-btn';
    loginSection && loginSection.appendChild(btn);
    btn.addEventListener('click', ()=>{
      const sel = qs('#teamMemberSelect');
      if (sel && !sel.value) return alert('Please select your name');
      if (sel) {
        const opt = sel.options[sel.selectedIndex];
        try { localStorage.setItem('teamMemberId', sel.value); } catch(e){}
        try { localStorage.setItem('teamMemberName', opt ? opt.text : ''); } catch(e){}
      }
      // Show app
  if (loginSection) { loginSection.classList.add('hidden'); try{ loginSection.style.display='none'; }catch(e){} }
  if (appSection) { appSection.classList.remove('hidden'); try{ appSection.style.display='block'; }catch(e){} }
  // reveal any server-side auth-only controls
  revealAuthControls();
  if (deferredPrompt) installBox && installBox.classList.remove('hidden');
      trySync();
    });
  }

  loginBtn && loginBtn.addEventListener('click', async ()=>{
    const team = (qs('#teamName') && qs('#teamName').value.trim()) || '';
    const code = (qs('#teamCode') && qs('#teamCode').value.trim()) || '';
    if (!team||!code) return alert('Team and code required');
    try{
      // Call server to validate
      await serverLogin(team, code);
      localStorage.setItem('teamName', team);
      localStorage.setItem('teamCode', code);

      // session duration handling: get the admin-configured value from localized config
      try{
        const sd = cfg.sessionDuration || cfg.session_duration || localStorage.getItem('sessionDuration') || '86400000';
        const durMs = parseInt(sd,10) || 86400000;
        const expiryMs = Date.now() + durMs;
        try { localStorage.setItem('sessionExpiry', new Date(expiryMs).toISOString()); } catch(e){}
        try { localStorage.setItem('sessionDuration', String(durMs)); } catch(e){}
      }catch(e){}

      // fetch config (google maps key etc.)
      await fetchAppConfig();

      // fetch team members and require selection if present
      const members = await fetchTeamMembers();
      if (members && members.length) {
        populateTeamMemberSelect(members);
        ensureContinueButton();
        // leave loginSection visible so user can pick member and press Continue
        return;
      }

      // no members to select — enter app
  if (loginSection) { loginSection.classList.add('hidden'); try{ loginSection.style.display='none'; }catch(e){} }
  if (appSection) { appSection.classList.remove('hidden'); try{ appSection.style.display='block'; }catch(e){} }
      // reveal any server-side auth-only controls
      revealAuthControls();
      if (deferredPrompt) installBox && installBox.classList.remove('hidden');
          trySync();
    }catch(err){ alert('Login failed. Check team name and code.'); }
  });

  // On boot, auto-restore session if not expired
  (function restoreSession(){
    try{
      const team = localStorage.getItem('teamName');
      const code = localStorage.getItem('teamCode');
      const expiry = localStorage.getItem('sessionExpiry');
      if (team && code && expiry) {
        const expTime = new Date(expiry).getTime();
        if (expTime && expTime > Date.now()) {
          // valid session
          if (loginSection) { loginSection.classList.add('hidden'); try{ loginSection.style.display='none'; }catch(e){} }
          if (appSection) { appSection.classList.remove('hidden'); try{ appSection.style.display='block'; }catch(e){} }
          // reveal any server-side auth-only controls
          revealAuthControls();
          // ensure config and members are loaded
          fetchAppConfig().catch(()=>{});
          fetchTeamMembers().then(members=>{ if (members && members.length) populateTeamMemberSelect(members); }).catch(()=>{});
          trySync();
        } else {
          // expired — clear stored session
          try { localStorage.removeItem('sessionExpiry'); } catch(e){}
        }
      }
    }catch(e){}
  })();

  

  // helper to get current position as a Promise with timeout
  function getCurrentPositionPromise(timeout=5000){
    return new Promise((resolve)=>{
      if (!('geolocation' in navigator)) return resolve(null);
      let resolved=false;
      const timer = setTimeout(()=>{ if (!resolved) { resolved=true; resolve(null); } }, timeout);
      navigator.geolocation.getCurrentPosition((pos)=>{ if (resolved) return; resolved=true; clearTimeout(timer); resolve(pos); }, (err)=>{ if (resolved) return; resolved=true; clearTimeout(timer); resolve(null); }, { enableHighAccuracy:true, timeout: timeout });
    });
  }

  saveOrderBtn && saveOrderBtn.addEventListener('click', async ()=>{
    // canonical customer/address values. Use a defensive getter for address because
    // autocomplete widgets sometimes inject alternate inputs that don't update the
    // original #address value reliably in all environments.
    const customer = qs('#customerName') && qs('#customerName').value.trim();
    const address = getCanonicalAddress();
    if (!customer || !address) return alert('Customer and address required');
    const notes = qs('#notes') && qs('#notes').value || '';
    const cell = qs('#cellNumber') && qs('#cellNumber').value.trim();
    const turkey = parseInt(qs('#turkeyQty') && qs('#turkeyQty').value,10) || 0;
    const ham = parseInt(qs('#hamQty') && qs('#hamQty').value,10) || 0;
    const combo = parseInt(qs('#comboQty') && qs('#comboQty').value,10) || 0;
    const donation = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0;
    const paymentMethod = (payCheck && payCheck.checked) ? 'check' : ((payCash && payCash.checked) ? 'cash' : '');
    const chkNumber = (checkNumber && checkNumber.value) ? checkNumber.value.trim() : '';
    // capture team member info from storage
    const enteredById = localStorage.getItem('teamMemberId') || '';
    const enteredByName = localStorage.getItem('teamMemberName') || '';

    // capture GPS coordinates (best-effort)
    const pos = await getCurrentPositionPromise(5000);
    const geo = pos && pos.coords ? { latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: pos.coords.accuracy } : null;

    const order = {
      id: 'o_'+Date.now(),
      customer, address,
      cellNumber: cell,
      turkeyQty: turkey, hamQty: ham, comboQty: combo,
      donationAmount: donation,
      paymentMethod,
      checkNumber: chkNumber,
      notes,
      createdAt: new Date().toISOString(),
      entered_by_id: enteredById,
      entered_by_name: enteredByName,
      geo
    };

  await Storage.add(order);
  renderOrders();
  // clear the form so user can enter a new order
  try{ clearOrderForm(); }catch(e){}
    syncStatus && (syncStatus.textContent='Queued for sync');
    if (navigator.onLine) trySync();
  });

  async function trySync(){
    const list = await Storage.all();
  if (!list || !list.length) { syncStatus && (syncStatus.textContent='No queued orders'); return; }
    if (!navigator.onLine) { syncStatus && (syncStatus.textContent='Waiting for network'); return; }
    syncStatus && (syncStatus.textContent='Syncing...');
    const teamName = localStorage.getItem('teamName');
    const teamCode = localStorage.getItem('teamCode');
    for (const order of list) {
      try {
        const url = apiBase ? (apiBase + '/orders') : '/wp-json/order-manager/v1/orders';
        const payload = {
          order_id: order.id,
          user_id: order.entered_by_id || teamName || 'team',
          customer: order.customer,
          address: order.address,
          notes: order.notes,
          turkeyQty: order.turkeyQty,
          hamQty: order.hamQty,
          comboQty: order.comboQty,
          donationAmount: order.donationAmount,
          paymentMethod: order.paymentMethod,
          checkNumber: order.checkNumber,
          cellNumber: order.cellNumber,
          createdAt: order.createdAt,
          entered_by_id: order.entered_by_id || '',
          entered_by_name: order.entered_by_name || '',
          geo: order.geo || null
        };
        const resp = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Team-Name': teamName || '', 'X-Access-Code': teamCode || '' },
          body: JSON.stringify(payload)
        });
        if (resp.ok) {
          await Storage.remove(order.id);
        } else {
          console.warn('sync failed status', resp.status);
        }
      } catch (err) {
        console.warn('sync failed for', order.id, err);
        syncStatus && (syncStatus.textContent='Sync error, will retry');
        return;
      }
    }
    syncStatus && (syncStatus.textContent='All queued orders synced');
    renderOrders();
  }

  // Service worker registration (scope provided by portalBase/pluginBase)
  (function(){ if ('serviceWorker' in navigator) { const swBase = portalBase || pluginBase || '/'; const swPath = (swBase.endsWith('/') ? swBase : (swBase + '/')) + 'service-worker.js'; const scope = (swBase.endsWith('/') ? swBase : (swBase + '/')); navigator.serviceWorker.register(swPath, { scope }).then(()=>console.log('sw registered')).catch((e)=>console.warn('sw register failed', e)); } })();

  // Google Places autocomplete loader
  window.initSMPlaces = function(){
    try {
    const addr = qs('#address'); if (!addr) return;
      const places = (window.google && google.maps && google.maps.places) ? google.maps.places : null;
      if (!places) return;
      // Prefer the newer PlaceAutocompleteElement when available (recommended by Google),
      // but fall back to the classic Autocomplete for environments that don't expose it.
      if (places.PlaceAutocompleteElement) {
        try {
          // Create the element and insert it before the existing input. Keep the original
          // input as the canonical value holder so other code continues to work.
          const pae = new places.PlaceAutocompleteElement({});
          // ensure the created element is full-width so selection UI uses the full viewport width
          try { pae.style.display = 'block'; pae.style.width = '100%'; pae.style.boxSizing = 'border-box'; } catch(e){}
          // try to set fields if supported
          try { pae.setFields && pae.setFields(['formatted_address','geometry']); } catch(e){}
          // insert element into DOM but keep the original input visible so the label/placeholder
          // remain clear to users. Provide accessible attributes on the injected element.
          try { pae.setAttribute && pae.setAttribute('placeholder', 'Search for address'); } catch(e){}
          try { pae.setAttribute && pae.setAttribute('aria-label', 'Search for address suggestions'); } catch(e){}
          // ensure the injected element has an id so labels can target it
          try { if (!pae.id) pae.id = 'addressAutocomplete'; } catch(e){}
          // Hide the original free-text input so users only interact with the
          // Google-provided element. Keep the original input as a hidden
          // canonical storage field (id=#address) so existing code continues
          // to read/write the address value.
          try { if (addr) { addr.style.display = 'none'; addr.setAttribute('aria-hidden','true'); } } catch(e){}
          // link the existing label (if present) to the injected element so it reads as an Address field
          try {
            const lbl = addr.previousElementSibling && addr.previousElementSibling.tagName === 'LABEL' ? addr.previousElementSibling : null;
            if (lbl) {
              try { lbl.htmlFor = pae.id; } catch(e){}
              try { lbl.id = lbl.id || 'label-address'; } catch(e){}
              try { lbl.textContent = 'Search for address'; } catch(e){}
              try { pae.setAttribute && pae.setAttribute('aria-labelledby', lbl.id); } catch(e){}
            }
          } catch(e){}
          // keep the original input as the canonical hidden storage and update its value when a place is selected
          // (some PlaceAutocompleteElement builds may not expose the same properties as
          // classic Autocomplete, so we defensively map common fields below)
          addr.parentNode.insertBefore(pae, addr);
          // Ensure a visible label exists and is linked to the autocomplete input.
          // Some hosts render plain text instead of a <label>; create one if missing.
          let lblEl = addr.previousElementSibling && addr.previousElementSibling.tagName === 'LABEL' ? addr.previousElementSibling : null;
          if (!lblEl) {
            try {
              const wrapper = document.createElement('label'); wrapper.id = 'label-address'; wrapper.textContent = 'Search for address';
              // insert the label immediately before the injected element so it labels the visible input
              addr.parentNode.insertBefore(wrapper, pae);
              lblEl = wrapper;
            } catch(e) { /* noop */ }
          }
          try {
            if (lblEl) { lblEl.htmlFor = pae.id; lblEl.id = lblEl.id || 'label-address'; lblEl.textContent = 'Search for address'; }
          } catch(e){}
          // Some autocomplete elements render their own inner <input> inside a shadow root
          // or nested structure. Provide a small helper to find that input, bind input
          // events that copy the visible value into the canonical #address, set
          // accessible attributes, and observe mutations so re-renders re-bind.
          const findInnerInput = (rootEl) => {
            try{
              if (!rootEl) return null;
              // 1) shadowRoot host
              if (rootEl.shadowRoot) {
                const s = rootEl.shadowRoot.querySelector('input, [role="combobox"]');
                if (s) return s;
              }
              // 2) direct query inside element
              if (typeof rootEl.querySelector === 'function') {
                const q = rootEl.querySelector('input, [role="combobox"]');
                if (q) return q;
              }
              // 3) check first child that may host a shadowRoot
              const first = rootEl.querySelector && rootEl.querySelector('*');
              if (first && first.shadowRoot) {
                const s2 = first.shadowRoot.querySelector('input, [role="combobox"]');
                if (s2) return s2;
              }
              // 4) as a last resort, if the host element behaves like an input, return it
              if (rootEl.matches && rootEl.matches('input, [role="combobox"], [contenteditable]')) return rootEl;
            }catch(e){}
            return null;
          };

          const bindInnerInput = (innerInput, labelEl) => {
            if (!innerInput) return;
            try {
              // ensure an id exists so label can reference it
              if (!innerInput.id) innerInput.id = 'address_input_internal';
              try { innerInput.setAttribute('placeholder','Search for address'); }catch(e){}
              try { innerInput.setAttribute('aria-label','Search for address'); }catch(e){}
              try { if (labelEl && labelEl.id) innerInput.setAttribute('aria-labelledby', labelEl.id); }catch(e){}
              // copy visible value into canonical #address on input so validations/readers work
              const copyValue = ()=>{ try{ const v = (innerInput.value || innerInput.textContent || ''); const tv = (v && typeof v === 'string') ? v.trim() : ''; if (addr) { addr.value = tv; try{ addr.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} } }catch(e){} };
              innerInput.removeEventListener('input', copyValue);
              innerInput.addEventListener('input', copyValue);
              innerInput.removeEventListener('change', copyValue);
              innerInput.addEventListener('change', copyValue);
              innerInput.removeEventListener('keyup', copyValue);
              innerInput.addEventListener('keyup', copyValue);
              // also trigger a copy after a short delay in case autocomplete selection manipulates value asynchronously
              innerInput.addEventListener('blur', ()=>{ setTimeout(copyValue,50); if (innerInput._poll) { clearInterval(innerInput._poll); innerInput._poll = null; } });
              // Start a short-lived poll while the control is focused to catch programmatic value changes
              innerInput.addEventListener('focus', ()=>{
                try{
                  if (innerInput._poll) clearInterval(innerInput._poll);
                  innerInput._poll = setInterval(copyValue, 150);
                }catch(e){}
              });
              // ensure we copy immediately once we bind
              try{ copyValue(); }catch(e){}
            } catch(e) { console.warn('bindInnerInput failed', e); }
          };

          // initial attempt to find and bind inner input
          try {
            const inner = findInnerInput(pae);
            if (inner) {
              bindInnerInput(inner, lblEl);
            } else {
              // Some implementations (eg. <gmp-place-autocomplete>) create their
              // inner input asynchronously (or inside a shadowRoot created later).
              // Start a short-lived poll and a MutationObserver to catch the input
              // the moment it's created, then bind and stop polling/observing.
              let pollCount = 0;
              const pollMax = 60; // ~60*150ms = ~9 seconds max
              const pollInterval = 150;
              const poller = setInterval(()=>{
                try{
                  pollCount++;
                  const ii = findInnerInput(pae);
                  if (ii) {
                    try { bindInnerInput(ii, lblEl); } catch(e){}
                    clearInterval(poller);
                    if (mo) { try{ mo.disconnect(); }catch(e){} }
                  } else if (pollCount >= pollMax) {
                    clearInterval(poller);
                    // As a last-resort, try to copy any textContent/value from the host element
                    try{ const hostVal = (pae.value || pae.textContent || ''); if (hostVal && addr) { addr.value = (hostVal+'').trim(); try{ addr.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} } }catch(e){}
                  }
                }catch(e){}
              }, pollInterval);

              let mo = null;
              try {
                mo = new MutationObserver((muts)=>{
                  try{
                    const ii = findInnerInput(pae);
                    if (ii) {
                      try { bindInnerInput(ii, lblEl); } catch(e){}
                      try { clearInterval(poller); } catch(e){}
                      try { mo.disconnect(); } catch(e){}
                    }
                  }catch(e){}
                });
                mo.observe(pae, { childList:true, subtree:true, attributes:true });
                pae._internalMO = mo;
              } catch(e) { /* noop */ }

              // Also listen for focus on the host element as a hint to re-probe immediately
              try{
                pae.addEventListener('focus', ()=>{
                  try{ const ii = findInnerInput(pae); if (ii) { bindInnerInput(ii, lblEl); try{ clearInterval(poller); }catch(e){} if (mo) try{ mo.disconnect(); }catch(e){} } }catch(e){}
                }, { passive:true, capture:true });
              }catch(e){}
            }
          } catch(e) { console.warn('inner input binding failed', e); }
          // wire place selection back to the original input
          const handlePlace = ()=>{
            try{
              const place = (typeof pae.getPlace === 'function') ? pae.getPlace() : null;
              if (!place) return;
              // prefer formatted_address, fall back to common alternatives
              const candidate = place.formatted_address || place.formattedAddress || place.description || place.name || (place.result && place.result.formatted_address) || '';
              if (candidate) {
                // write into canonical (hidden) address field
                try { addr.value = candidate; } catch(e){}
              } else if (place.address_components && Array.isArray(place.address_components)) {
                // build a readable address from components
                try{
                  const parts = place.address_components.map(c=>c.long_name).filter(Boolean);
                  if (parts.length) addr.value = parts.join(' ');
                }catch(e){}
              }
              // notify other listeners (computeTotal, etc.) that the address changed
              try{ addr.dispatchEvent(new Event('input', { bubbles:true })); }catch(e){}
            }catch(e){}
          };
          if (typeof pae.addEventListener === 'function') {
            pae.addEventListener('place_changed', handlePlace);
          } else if (typeof pae.addListener === 'function') {
            pae.addListener('place_changed', handlePlace);
          }
          return; // done
        } catch(err) {
          // if anything goes wrong with the newer element, fall back to Autocomplete below
          console.warn('PlaceAutocompleteElement failed, falling back to Autocomplete', err);
        }
      }

  // Fallback: use classic Autocomplete. Ensure the input is full-width so the picker aligns.
  try { addr.style.display = 'block'; addr.style.width = '100%'; addr.style.boxSizing = 'border-box'; addr.placeholder = 'Search for address'; } catch(e){}
      const ac = new places.Autocomplete(addr, { types: ['geocode'] });
      try { ac.setFields && ac.setFields(['formatted_address','geometry']); } catch(e){}
      try {
        if (typeof ac.addListener === 'function') {
          ac.addListener('place_changed', ()=>{
            try{ const place = (typeof ac.getPlace === 'function') ? ac.getPlace() : null; if (place && place.formatted_address) addr.value = place.formatted_address; }catch(e){}
          });
        }
      } catch(e){}
    } catch(e){ console.warn('places init failed', e); }
  };
  const savedKey = localStorage.getItem('bkmb_google_maps_key') || googleMapsApiKey;
  if (savedKey) { 
    const s = document.createElement('script');
    // Use loading=async to follow Google best-practice for script loading and avoid console warnings
    s.src = 'https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(savedKey)+'&libraries=places&loading=async&callback=initSMPlaces';
    s.async = true; s.defer = true; document.head.appendChild(s);
  }

  // Compatibility bridge for closed/minified autocomplete widgets (best-effort)
  // Provides: copyPlaceToCanonical(host), patchGmpPlaceAutocomplete(), live-capture listeners
  function copyPlaceToCanonical(host){
    try{
      const addrEl = document.querySelector('#address'); if (!addrEl) return;
      let candidate = '';
      // direct API attempts
      try{ if (host && typeof host.getPlace === 'function'){ const p = host.getPlace(); if (p && p.formatted_address) candidate = p.formatted_address; } }catch(e){}
      try{ if (!candidate && host && host.place && host.place.formatted_address) candidate = host.place.formatted_address; }catch(e){}
      try{ if (!candidate && host && 'value' in host && host.value) candidate = host.value; }catch(e){}
      try{ if (!candidate && host && host.getAttribute) { const v = host.getAttribute('value') || host.getAttribute('aria-label') || host.getAttribute('placeholder'); if (v) candidate = v; } }catch(e){}
      try{ if (!candidate && host && host.textContent) { const t = host.textContent.trim(); if (t) candidate = t; } }catch(e){}

      function looksLikeAddress(s){ if(!s||typeof s!=='string') return false; const trimmed=s.trim(); if(!trimmed) return false; if (/\d+\s+\w+/.test(trimmed) && /,/.test(trimmed)) return true; if (/\b(St|Street|Ave|Avenue|Blvd|Boulevard|Rd|Road|Ln|Drive|Dr)\b/i.test(trimmed)) return true; if (trimmed.length>15 && trimmed.split(' ').length>2) return true; return false; }

      function findStringInObject(obj, depth, seen){
        if (!obj || depth<=0) return null;
        if (typeof obj === 'string') return looksLikeAddress(obj) ? obj : null;
        if (typeof obj === 'number') return null;
        if (Array.isArray(obj)){
          for (const it of obj){ const r = findStringInObject(it, depth-1, seen); if (r) return r; }
          return null;
        }
        if (typeof obj === 'object'){
          if (seen.has(obj)) return null; seen.add(obj);
          for (const k of Object.keys(obj)){
            try{
              const v = obj[k];
              if (typeof v === 'string' && looksLikeAddress(v)) return v;
              if (typeof v === 'object'){
                const r = findStringInObject(v, depth-1, seen); if (r) return r;
              }
            }catch(e){}
          }
        }
        return null;
      }

      // predictions-like structures
      try{
        if (!candidate && host && host.predictions && host.predictions.length){
          const p0 = host.predictions[0];
          if (p0){ if (p0.description) candidate = p0.description; else if (p0.formatted_address) candidate = p0.formatted_address; else if (p0.structured_formatting && (p0.structured_formatting.main_text || p0.structured_formatting.secondary_text)) candidate = [p0.structured_formatting.main_text, p0.structured_formatting.secondary_text].filter(Boolean).join(', '); }
        }
      }catch(e){}

      // valueOf deep scan
      try{ if (!candidate && host && typeof host.valueOf === 'function'){ const v = host.valueOf(); const found = findStringInObject(v, 3, new WeakSet()); if (found) candidate = found; } }catch(e){}

      // deep scan host properties
      try{ if (!candidate && host){ const found = findStringInObject(host, 3, new WeakSet()); if (found) candidate = found; } }catch(e){}

      // targeted extraction for observed minified `er` patterns
      try{
        if (!candidate && host){
          const preds = host.predictions || (typeof host.valueOf === 'function' && host.valueOf().predictions) || host.Tg || null;
          if (preds && Array.isArray(preds) && preds.length){
            const p0 = preds[0];
            try{
              const er = p0 && (p0.er || p0.Er || p0['er']);
              if (er){
                if (Array.isArray(er.Oh)){
                  if (er.Oh.length>2 && Array.isArray(er.Oh[2]) && typeof er.Oh[2][0] === 'string') candidate = er.Oh[2][0];
                  else if (er.Oh.length>2 && typeof er.Oh[2] === 'string') candidate = er.Oh[2];
                }
                if (!candidate && Array.isArray(er.My) && er.My.length>2 && Array.isArray(er.My[2]) && typeof er.My[2][0] === 'string') candidate = er.My[2][0];
              }
            }catch(e){}
          }
        }
      }catch(e){}

      if (candidate && candidate !== (addrEl.value||'')){
        addrEl.value = candidate;
        try{ addrEl.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){}
        try{ localStorage.setItem('sm_debug_widget_snapshot', JSON.stringify({ ts: new Date().toISOString(), bridgedFrom: host && host.tagName ? host.tagName : '<host>', value: candidate })); }catch(e){}
      }
    }catch(e){ console.warn('copyPlaceToCanonical error', e); }
  }

  function patchGmpPlaceAutocomplete(){
    try{
      if (!window.customElements || !customElements.get) return;
      const C = customElements.get('gmp-place-autocomplete');
      if (!C) return;
      const proto = C.prototype;
      const names = Object.getOwnPropertyNames(proto || {});
      const candidates = names.filter(n => /(select|place|autocomplete|choose|pick|_on|_handle|_select)/i.test(n));
      candidates.forEach(name => {
        try{
          const orig = proto[name];
          if (typeof orig === 'function'){
            proto[name] = function(...args){ const res = orig.apply(this,args); try{ copyPlaceToCanonical(this); }catch(e){} return res; };
          }
        }catch(e){}
      });

      // Wrap connectedCallback to attach an observer that tries to copy on mutations
      try{
        const origConn = proto.connectedCallback;
        proto.connectedCallback = function(...args){ if (origConn) try{ origConn.apply(this,args); }catch(e){} try{ copyPlaceToCanonical(this); const mo = new MutationObserver(()=>copyPlaceToCanonical(this)); mo.observe(this, { attributes:true, childList:true, subtree:true }); this.__sm_mo = mo; }catch(e){} };
      }catch(e){}

      // Wrap disconnectedCallback to clean up
      try{
        const origDisc = proto.disconnectedCallback;
        proto.disconnectedCallback = function(...args){ if (origDisc) try{ origDisc.apply(this,args); }catch(e){} try{ if (this.__sm_mo){ this.__sm_mo.disconnect(); delete this.__sm_mo; } }catch(e){} };
      }catch(e){}

      // Document-level hooks as extra fallback
      try{ document.addEventListener('click', ()=>{ setTimeout(()=>{ document.querySelectorAll('gmp-place-autocomplete,#addressAutocomplete').forEach(copyPlaceToCanonical); }, 50); }, true); }catch(e){}

      console.log('patched gmp-place-autocomplete prototype, candidates:', candidates);
    }catch(e){ console.warn('patchGmpPlaceAutocomplete error', e); }
  }

  // Try to patch immediately and also when the element is defined later
  try{
    patchGmpPlaceAutocomplete();
    if (customElements && customElements.whenDefined) {
      customElements.whenDefined('gmp-place-autocomplete').then(patchGmpPlaceAutocomplete).catch(()=>{});
    }
    setTimeout(patchGmpPlaceAutocomplete, 1000);
    setTimeout(patchGmpPlaceAutocomplete, 3000);
  }catch(e){}

  // Global live-capture: listen for events and try to extract address values
  (function(){
    function tryCopyValueFromEvent(e){
      try{
        const path = (e.composedPath && e.composedPath()) || (e.path || []);
        if (!path || !path.length) return false;
        const host = path.find(n => n && n.tagName && n.tagName.toLowerCase && n.tagName.toLowerCase() === 'gmp-place-autocomplete');
        if (!host) return false;
        let val = '';
        for (const node of path){
          if (!node) continue;
          try{
            if (node.nodeType === 1 && node.matches && (node.matches('input,textarea,[role="combobox"]'))) {
              val = node.value || (node.getAttribute && node.getAttribute('value')) || '';
              if (val) break;
            }
            if (node && typeof node.value === 'string' && node.value.trim()) { val = node.value.trim(); break; }
            if (node && node.dataset && node.dataset.value) { val = node.dataset.value; break; }
          }catch(err){}
        }
        if (!val && e.detail){
          try{
            if (typeof e.detail === 'string') val = e.detail;
            else if (e.detail && (e.detail.formatted_address || e.detail.value || e.detail.place)){
              val = e.detail.formatted_address || e.detail.value || (e.detail.place && e.detail.place.formatted_address) || '';
            }
          }catch(err){}
        }
        if (val && val.trim()){
          const addr = document.querySelector('#address');
          if (addr){ addr.value = val; try{ addr.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} }
          try{ localStorage.setItem('sm_debug_widget_snapshot', JSON.stringify({ ts: new Date().toISOString(), via: 'live-capture', value: val })); }catch(e){}
          console.log('SM-live-capture: copied value ->', val);
          return true;
        }
        return false;
      }catch(err){ console.warn('SM-live-capture error', err); return false; }
    }

    const handler = function(e){ tryCopyValueFromEvent(e); };
    ['input','change','click','keyup'].forEach(evt => document.addEventListener(evt, handler, true));

    // Expose manual probe trigger
    window._smDebugProbe = function(){
      try{
        const host = document.querySelector('#addressAutocomplete') || document.querySelector('gmp-place-autocomplete');
        if (!host) return console.log('SM-probe: host not found');
        try{ copyPlaceToCanonical(host); }catch(e){ console.warn('SM-probe copy failed', e); }
        console.log('SM-probe result (snapshot key):', localStorage.getItem('sm_debug_widget_snapshot'));
        return localStorage.getItem('sm_debug_widget_snapshot');
      }catch(e){ console.warn('SM-probe error', e); }
    };
    console.log('SM-live-capture installed: listening for input/change/click/keyup (capture)');
  })();

  // Boot: do not render queued orders or attempt sync until the user is authenticated.
  // Session restore or a successful login will call renderOrders() and trySync().

  // Fetch remote orders (all) — returns array of orders from server
  async function fetchRemoteOrders(limit=1000){
    try{
      const url = apiBase ? (apiBase + '/orders?limit=' + encodeURIComponent(limit)) : '/wp-json/order-manager/v1/orders?limit=' + encodeURIComponent(limit);
      const teamName = localStorage.getItem('teamName') || '';
      const teamCode = localStorage.getItem('teamCode') || '';
      const resp = await fetch(url, { headers: { 'X-Team-Name': teamName, 'X-Access-Code': teamCode } });
      if (!resp.ok) return [];
      const j = await resp.json().catch(()=>[]);
      return Array.isArray(j) ? j : [];
    }catch(e){ console.warn('fetchRemoteOrders failed', e); return []; }
  }

  // Show 'My orders' — local queued and remote orders for current entered_by_id/name
  async function showMyOrders(){
    const memberId = localStorage.getItem('teamMemberId') || '';
    const memberName = localStorage.getItem('teamMemberName') || '';
    const local = await Storage.all();
    const localFiltered = local.filter(o => (memberId && o.entered_by_id && o.entered_by_id === memberId) || (memberName && o.entered_by_name && o.entered_by_name === memberName));
    const remote = await fetchRemoteOrders(1000);
    const remoteFiltered = (remote || []).filter(r => {
      // r.order_data may be present as object (server decodes) or as json string
      const od = r.order_data || (r.order_data === undefined ? null : r.order_data);
      if (od) {
        try{
          // od should already be object, but be defensive
          const entered = typeof od === 'string' ? JSON.parse(od) : od;
          if (memberId && entered.entered_by_id && entered.entered_by_id === memberId) return true;
          if (memberName && entered.entered_by_name && entered.entered_by_name === memberName) return true;
        }catch(e){}
      }
      // fallback: check r.user_id
      if (memberId && r.user_id && String(r.user_id) === String(memberId)) return true;
      return false;
    });

    // Render modal-like overlay
    const modalId = 'myOrdersModal';
    let modal = qs('#' + modalId);
    if (!modal){
      modal = document.createElement('div'); modal.id = modalId; modal.className = 'sm-modal hidden';
      document.body.appendChild(modal);
    }
    modal.innerHTML = `<div class="modal-header"><strong>My orders (${memberName||memberId||'You'})</strong><div><button id="closeMyOrdersBtn" class="sm-btn">Close</button></div></div><div class="modal-body"><div class="modal-col"> <h4>Local (queued)</h4>${localFiltered.length? localFiltered.map(o=>`<div class="order"><strong>${o.id}</strong><div>${o.customer||''}</div><div>${o.createdAt||''}</div><div>Geo: ${o.geo ? (o.geo.latitude+','+o.geo.longitude) : 'n/a'}</div></div>`).join('') : '<div>No local orders</div>'}</div><div class="modal-col"><h4>Remote (synced)</h4>${remoteFiltered.length? remoteFiltered.map(r=>`<div class="order"><strong>${r.order_id||r.order_id}</strong><div>${(r.order_data && (r.order_data.customer || (r.order_data.customer===undefined? (r.order_data.customer) : ''))) || ''}</div><div>${r.created_at || ''}</div></div>`).join('') : '<div>No remote orders</div>'}</div></div>`;
    const closeBtn = qs('#closeMyOrdersBtn'); if (closeBtn) closeBtn.addEventListener('click', ()=>{ modal.classList.add('hidden'); });
    modal.classList.remove('hidden');
  }

  const myOrdersBtn = qs('#myOrdersBtn'); if (myOrdersBtn) myOrdersBtn.addEventListener('click', showMyOrders);
  // Force Sync button
  const forceSyncBtn = qs('#forceSyncBtn');
  if (forceSyncBtn) {
    forceSyncBtn.addEventListener('click', async ()=>{
      if (!navigator.onLine) { alert('No network connection. Sync will start when online.'); return; }
      try {
        syncStatus && (syncStatus.textContent = 'Manual sync...');
        await trySync();
        alert('Sync completed (or queued items attempted).');
      } catch (e) { console.warn('Manual sync error', e); alert('Sync failed. See console for details.'); }
    });
  }

  // Logout button: clear session and return to login screen
  const logoutBtn = qs('#logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', ()=>{
      if (!confirm('Log out of the app?')) return;
      try {
        // clear session-related keys
        localStorage.removeItem('teamName');
        localStorage.removeItem('teamCode');
        localStorage.removeItem('sessionExpiry');
        localStorage.removeItem('sessionDuration');
        localStorage.removeItem('teamMemberId');
        localStorage.removeItem('teamMemberName');
        localStorage.removeItem('teamId');
      } catch(e){}
  // show login
  if (loginSection) { loginSection.classList.remove('hidden'); try{ loginSection.style.display='block'; }catch(e){} }
  if (appSection) { appSection.classList.add('hidden'); try{ appSection.style.display='none'; }catch(e){} }
      // update UI
      updateNetworkUI();
      updateHeaderStatus();
      renderOrders();
    });
  }

})();
// BKMB Subsales PWA client (plugin-hosted)

// Debug helper: when enabled via URL hash `#sm-debug` or query `?sm-debug=1`,
// watch for autocomplete widgets and capture their DOM for troubleshooting.
(function(){
  try{
    const enabled = (location.hash && location.hash.indexOf('sm-debug')!==-1) || (new URLSearchParams(location.search).get('sm-debug')==='1');
    if (!enabled) return;
    const KEY = 'sm_debug_widget_snapshot';
    const selectors = [
      '#addressAutocomplete',
      'place-autocomplete',
      'place-autocomplete-element',
      'google-places-autocomplete',
      'google-place-autocomplete',
      'google-places',
      '.pac-target-input',
      '.pac-container',
      '.gm-places-autocomplete',
      'input[role="combobox"]'
    ];

    const snapshot = (data)=>{
      try{
        const out = Object.assign({ ts: new Date().toISOString() }, data || {});
        try { localStorage.setItem(KEY, JSON.stringify(out)); } catch(e){}
        try { console.log('SM-Debug snapshot:', out); } catch(e){}
        try { if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(JSON.stringify(out)); } catch(e){}
        // show a small overlay with the snapshot summary
        try{
          let el = document.getElementById('smDebugOverlay');
          if (!el) {
            el = document.createElement('div'); el.id = 'smDebugOverlay';
            el.style.position = 'fixed'; el.style.right = '12px'; el.style.bottom = '12px'; el.style.zIndex = 999999; el.style.background = 'rgba(0,0,0,0.75)'; el.style.color = '#fff'; el.style.fontSize='12px'; el.style.padding='8px 10px'; el.style.borderRadius='6px'; el.style.maxWidth='320px'; el.style.maxHeight='220px'; el.style.overflow='auto'; el.style.boxShadow='0 2px 8px rgba(0,0,0,0.6)'; document.body.appendChild(el);
          }
          el.textContent = 'SM-Debug: snapshot saved to localStorage key "' + KEY + '" — click to open';
          el.onclick = ()=>{ try{ const v = localStorage.getItem(KEY); if (v) { const w = window.open(); w.document.body.style.whiteSpace='pre-wrap'; w.document.body.textContent = v; } }catch(e){} };
        }catch(e){}
      }catch(e){ console.warn('sm-debug snapshot failed', e); }
    };

    const findInner = (root)=>{
      try{
        if (!root) return null;
        if (root.shadowRoot) {
          const s = root.shadowRoot.querySelector('input, [role="combobox"]'); if (s) return s;
        }
        if (root.querySelector) {
          const q = root.querySelector('input, [role="combobox"]'); if (q) return q;
        }
        const first = root.querySelector && root.querySelector('*');
        if (first && first.shadowRoot) { const s2 = first.shadowRoot.querySelector('input, [role="combobox"]'); if (s2) return s2; }
        if (root.matches && root.matches('input, [role="combobox"]')) return root;
      }catch(e){}
      return null;
    };

    const captureEl = (el)=>{
      try{
        if (!el) return null;
        const outer = el.outerHTML || (el.cloneNode(true) && (el.cloneNode(true).outerHTML || null)) || null;
        const inner = findInner(el);
        const innerInfo = inner ? { id: inner.id || null, value: (inner.value||inner.textContent||'').toString(), outerHTML: inner.outerHTML ? inner.outerHTML : null } : null;
        const canonical = (document.querySelector('#address') && document.querySelector('#address').value) || '';
        return { selector: el.tagName ? el.tagName.toLowerCase() : null, outerHTML: outer, innerInfo, canonical };
      }catch(e){ return { error: String(e) }; }
    };

    const probeNow = ()=>{
      try{
        // try each selector and capture the first match
        for (const sel of selectors) {
          const found = document.querySelector(sel);
          if (found) { const s = captureEl(found); snapshot({ foundSelector: sel, details: s }); return s; }
        }
        // as a fallback, look for any element that looks like a google autocomplete container by attribute heuristics
        const all = Array.from(document.querySelectorAll('*'));
        for (const el of all) {
          try{
            const text = (el.className||'') + ' ' + (el.id||'') + ' ' + (el.tagName||'');
            if (/(pac|autocomplete|places|gm-places)/i.test(text)) { const s = captureEl(el); snapshot({ foundSelector: '(heuristic)', details: s }); return s; }
          }catch(e){}
        }
        snapshot({ foundSelector: null, details: null, note: 'no widget found at probe time', canonical: (document.querySelector('#address') && document.querySelector('#address').value) || '' });
      }catch(e){ snapshot({ error: String(e) }); }
    };

    // Observe additions to DOM so we capture widget the moment it appears
    const mo = new MutationObserver((mutList)=>{
      try{
        for (const m of mutList) {
          if (!m.addedNodes || !m.addedNodes.length) continue;
          for (const n of m.addedNodes) {
            try{
              if (!(n instanceof Element)) continue;
              for (const sel of selectors) {
                try{ if (n.matches && n.matches(sel)) { const s = captureEl(n); snapshot({ foundSelector: sel, details: s, via: 'mutation-added' }); return; } }catch(e){}
              }
              // also check children quickly
              for (const sel of selectors) {
                const c = n.querySelector && n.querySelector(sel);
                if (c) { const s = captureEl(c); snapshot({ foundSelector: sel, details: s, via: 'mutation-child' }); return; }
              }
            }catch(e){}
          }
        }
      }catch(e){}
    });
    mo.observe(document.documentElement || document.body || document, { childList:true, subtree:true });

    // Expose a manual trigger so the user or console can probe on demand
    try{ window._smDebugProbe = probeNow; }catch(e){}
    // Probe once immediately to capture existing widget if present
    setTimeout(probeNow, 250);

    console.info('SM-Debug enabled: watching for autocomplete widgets. When a widget appears its DOM will be saved to localStorage key "' + KEY + '". You can also call window._smDebugProbe() to force a probe.');
  }catch(e){ console.warn('SM-Debug setup failed', e); }
})();
