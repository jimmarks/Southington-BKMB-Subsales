// BKMB Subsales PWA client (plugin-hosted) — single-file clean implementation
(function(){
  'use strict';
  
  // COMPREHENSIVE DEBUG LOGGING



  // Prefer plugin-localized settings (PHP uses SUBSALES_PWA_CONFIG), fall back to legacy BKMB_PWA_CONFIG
  const cfg = window.SUBSALES_PWA_CONFIG || window.BKMB_PWA_CONFIG || {};

  const apiBase = (cfg.apiBase || cfg.api_base || localStorage.getItem('API_BASE_URL') || '').replace(/\/+$/, '');
  const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
  const portalBase = cfg.portalBase || '';
  const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';
  const brandName = cfg.brandName || cfg.brand_name || 'Subsales';
  const brandingImage = cfg.brandingImage || cfg.branding_image || '';



  // Global error handler for logging
  window.addEventListener('error', function(event) {
    if (window.PWALogger) {
      window.PWALogger.logError('system', 'Uncaught error', {
        message: event.message,
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
        error: event.error
      });
    }
  });
  
  window.addEventListener('unhandledrejection', function(event) {
    if (window.PWALogger) {
      window.PWALogger.logError('system', 'Unhandled promise rejection', {
        reason: event.reason,
        promise: String(event.promise)
      });
    }
  });
  
  // Listen for address autocomplete module ready event
  let addressAutocompleteReady = false;
  let addressAutocompleteLoading = false;
  window.addEventListener('subsalesNearbyReady', function() {

    addressAutocompleteReady = true;
  });
  
  // Load address autocomplete module dynamically (only after login)
  function loadAddressAutocomplete() {
    if (addressAutocompleteReady || addressAutocompleteLoading) {

      return Promise.resolve();
    }

    addressAutocompleteLoading = true;
    
    return new Promise((resolve, reject) => {
      try {
        // Find the base path from app.js
        const appScript = document.querySelector('script[src$="app.js"]');
        const base = appScript ? appScript.src.replace(/app\.js(\?.*)?$/, '') : './';
        
        const script = document.createElement('script');
        script.src = base + 'address-autocomplete.js';
        script.onload = () => {

          resolve();
        };
        script.onerror = (e) => {
          console.error('[LoadModule] Failed to load address-autocomplete.js', e);
          addressAutocompleteLoading = false;
          reject(e);
        };
        document.head.appendChild(script);
      } catch(e) {
        console.error('[LoadModule] Error loading address-autocomplete.js', e);
        addressAutocompleteLoading = false;
        reject(e);
      }
    });
  }

  // Apply runtime style overrides from admin settings (primary color and variant)
  (function applyRuntimeStyles(){
    try{
      const primary = cfg.primaryColor || cfg.primary_color || '#2d6cdf';
      const variant = cfg.styleVariant || cfg.style_variant || 'default';
      // Prefer scoping the CSS variable and variant class to the PWA root so
      // the host/admin page doesn't inherit PWA colors. Fall back to the
      // document element/body when no PWA root exists (standalone PWA page).
      const pwaRootEl = document.getElementById('subsales-pwa-root') || document.getElementById('sm-pwa-root');
      if (pwaRootEl) {
        pwaRootEl.style.setProperty('--sm-primary', primary);
        pwaRootEl.classList.remove('sm-variant-default','sm-variant-flat','sm-variant-rounded','sm-variant-dark');
        pwaRootEl.classList.add('sm-variant-' + (variant || 'default'));
      } else {
        document.documentElement.style.setProperty('--sm-primary', primary);
        document.body.classList.remove('sm-variant-default','sm-variant-flat','sm-variant-rounded','sm-variant-dark');
        document.body.classList.add('sm-variant-' + (variant || 'default'));
      }
    }catch(e){}
  })();

  // Simple snackbar/toast with optional Undo action
  (function(){
    // ensure DOM element exists
    function ensureSnackbar(){
      let el = qs('#smSnackbar');
      if (el) return el;
      el = document.createElement('div'); el.id = 'smSnackbar'; el.className = 'sm-snackbar hidden';
      el.innerHTML = '<span id="smSnackbarMsg"></span> <button id="smSnackbarAction" class="sm-btn sm-snackbar-action" style="display:none;">Undo</button>';
      document.body.appendChild(el);
      const action = qs('#smSnackbarAction'); if (action) action.addEventListener('click', ()=>{ const cb = el._undoCb; if (typeof cb === 'function') { try{ cb(); }catch(e){} } hideSnackbar(); });
      return el;
    }
    function showSnackbar(message, opts){
      try{
        const el = ensureSnackbar();
        const msg = qs('#smSnackbarMsg'); if (msg) msg.textContent = message || '';
        const action = qs('#smSnackbarAction'); if (opts && opts.actionLabel) { action.style.display='inline-block'; action.textContent = opts.actionLabel; el._undoCb = opts.onAction || null; } else { action.style.display='none'; el._undoCb = null; }
        el.classList.remove('hidden'); el.classList.add('show');
        if (el._timer) { clearTimeout(el._timer); el._timer = null; }
        if (opts && opts.timeout && opts.timeout > 0) { el._timer = setTimeout(()=>{ hideSnackbar(); }, opts.timeout); }
      }catch(e){ console.warn('showSnackbar error', e); }
    }
    function hideSnackbar(){ const el = qs('#smSnackbar'); if (!el) return; el.classList.add('hidden'); el.classList.remove('show'); if (el._timer) { clearTimeout(el._timer); el._timer = null; } el._undoCb = null; }
    window.smShowSnackbar = showSnackbar;
    window.smHideSnackbar = hideSnackbar;
    // convenience: undo snackbar
    window.showUndoSnackbar = function(msg, undoCb, timeout){ showSnackbar(msg, { actionLabel:'Undo', onAction: undoCb, timeout: timeout || 7000 }); };
  })();

  const qs = s => document.querySelector(s);
  
  // Helper function for comprehensive logging with GPS
  async function logWithContext(category, message, additionalContext = {}) {
    if (!window.PWALogger || !window.PWALogger.debugEnabled) return;
    
    const context = {
      ...additionalContext,
      timestamp: new Date().toISOString(),
      online: navigator.onLine,
      user_id: localStorage.getItem('userId') || localStorage.getItem('teamMemberId'),
      user_name: localStorage.getItem('userName') || localStorage.getItem('teamMemberName'),
      team_id: localStorage.getItem('selectedTeamId') || localStorage.getItem('teamId'),
      team_name: localStorage.getItem('selectedTeamName') || localStorage.getItem('teamName'),
      login_mode: localStorage.getItem('loginMode') || 'legacy',
      session_id: localStorage.getItem('pwaSessionId')
    };
    
    // Try to get GPS coordinates if available
    if ('geolocation' in navigator) {
      try {
        const position = await new Promise((resolve, reject) => {
          navigator.geolocation.getCurrentPosition(resolve, reject, {
            timeout: 1000,
            maximumAge: 30000,
            enableHighAccuracy: false
          });
        });
        
        context.gps_latitude = position.coords.latitude;
        context.gps_longitude = position.coords.longitude;
        context.gps_accuracy = position.coords.accuracy;
        context.gps_timestamp = new Date(position.timestamp).toISOString();
      } catch(e) {
        context.gps_error = e.message;
      }
    }
    
    window.PWALogger.log(category, message, context);
  }

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
            <button id="myOrdersBtn" class="sm-btn sm-auth-hidden">My orders</button>
            <button id="eodBtn" class="sm-btn sm-auth-hidden">End of Day Tally</button>
            <button id="openInlayBtn" class="sm-btn hidden">Queued Orders</button>
          </div>
        </header>
        <main class="sm-main">
          <section id="loginSection" class="sm-login-section">
            <!-- Legacy login (team+code) -->
            <div id="legacyLogin" class="sm-login-mode">
              <h2>Team Login</h2>
              <input id="teamName" placeholder="Team name" />
              <input id="teamCode" placeholder="Access code" />
              <div class="sm-row"><button id="loginBtn" class="sm-btn">Login</button></div>
            </div>
            
            <!-- User login (name+phone) -->
            <div id="userLogin" class="sm-login-mode hidden">
              <h2>Login</h2>
              <label for="userName">Full Name</label>
              <div style="position:relative;">
                <input id="userName" placeholder="Start typing your name..." autocomplete="off" />
                <div id="userNameSuggestions" class="sm-suggestions hidden"></div>
              </div>
              <label for="userPhone">Phone Number</label>
              <input id="userPhone" type="tel" inputmode="tel" placeholder="10 digits" maxlength="10" pattern="[0-9]{10}" autocomplete="off" />
              <div id="teamSelectRow" class="sm-row hidden">
                <label>Select Team</label>
                <select id="userTeamSelect">
                  <option value="">-- Select team --</option>
                </select>
              </div>
              <div id="individualSalesRow" class="sm-row hidden">
                <label><input type="checkbox" id="individualSales" /> Individual Sales (not for a team)</label>
              </div>
              <div class="sm-row"><button id="userLoginBtn" class="sm-btn">Login</button></div>
            </div>
          </section>
          <section id="appSection" class="sm-app-section hidden">
            <div class="sm-row">
              <div class="sm-leftcol">
                <h2>Create Order</h2>
                <label style="display: flex; align-items: center; gap: 8px; background: #fff3cd; padding: 10px; border-radius: 4px; margin-bottom: 10px; cursor: pointer;">
                  <input type="checkbox" id="donationOnly" />
                  <span style="font-weight: 600;">📌 Anonymous Donation (no customer details needed)</span>
                </label>
                <label>Customer name</label>
                <input id="customerName" placeholder="Customer name" />
                <label>Address</label>
                <input id="address" placeholder="Address" />
                <label for="cellNumber">Phone Number</label>
                <input id="cellNumber" type="tel" inputmode="tel" placeholder="Phone Number" maxlength="10" required />
                <div class="row row-spaced">
                  <div id="productsContainer" class="row row-products" style="width:100%; display:flex; gap:8px; flex-wrap:wrap"></div>
                </div>
                <label>Donation amount (USD)</label>
                <input id="donationAmount" type="number" inputmode="decimal" min="0" step="0.01" placeholder="$0.00" />
                <div class="order-total"><strong>Order total: <span id="orderTotal">$0.00</span></strong></div>
                <div class="pay-options">
                  <label for="payCheck"><input type="checkbox" id="payCheck" /> <span class="pay-label">Pay by check</span></label>
                  <label for="payCash"><input type="checkbox" id="payCash" /> <span class="pay-label">Pay by cash</span></label>
                </div>
                <div id="checkNumberRow" class="hidden check-number-row"><label>Check number</label><input id="checkNumber" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="Check number" /></div>
                <label>Delivery Instructions</label>
                <textarea id="notes" placeholder="house color, long driveway etc"></textarea>
                <div class="btn-row" style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px;">
                  <button id="saveOrderBtn" class="sm-btn">Save Order</button>
                  <button id="clearFormBtn" class="sm-btn" style="background-color: #dc3545; color: white;">Clear Form</button>
                </div>
              </div>
              <aside class="sm-aside">
                <h3>Status</h3>
                <div id="networkStatus" class="status-offline">Offline</div>
                <div id="syncStatus">Not synced</div>
              </aside>
            </div>
            <!-- Local orders removed from main view; available via My Orders modal/inlay -->
          </section>
        </main>
      </div>`;
  }

  // Populate form with an order object for editing
  function enterEditMode(orderObj, opts){
    try{
      if (!orderObj) return;
      opts = opts || {};
      // ensure app visible
      if (loginSection) { loginSection.classList.add('hidden'); try{ loginSection.style.display='none'; }catch(e){} }
      if (appSection) { appSection.classList.remove('hidden'); try{ appSection.style.display='block'; }catch(e){} }
      // set simple fields
      try{ if (qs('#customerName')) qs('#customerName').value = orderObj.customer || orderObj.name || ''; }catch(e){}
      try{ if (qs('#cellNumber')) qs('#cellNumber').value = orderObj.cellNumber || orderObj.cell || ''; }catch(e){}
      try{ if (qs('#notes')) qs('#notes').value = orderObj.notes || ''; }catch(e){}
      try{ if (qs('#donationAmount')) qs('#donationAmount').value = (orderObj.donationAmount !== undefined) ? orderObj.donationAmount : (orderObj.donation || ''); }catch(e){}
      try{ if (qs('#checkNumber')) qs('#checkNumber').value = orderObj.checkNumber || ''; }catch(e){}
      // products: set qty inputs
      try{
        // ensure products inputs are present (re-render if needed) then clear and populate
        try{ renderProducts(); }catch(e){}
        // clear first
        const prodInputs = document.querySelectorAll('input[data-product-id]'); prodInputs.forEach(i=>{ try{ i.value=''; }catch(e){} });
        const prods = orderObj.products || [];
        prods.forEach(p=>{ try{ const inp = document.querySelector('input[data-product-id="' + p.id + '"]'); if (inp) inp.value = (p.qty || p.qty === 0) ? p.qty : (p.quantity || ''); }catch(e){} });
      }catch(e){}
      // address: set canonical and try to populate visible autocomplete widget
      try{ const addr = orderObj.address || orderObj.formatted_address || ''; if (qs('#address')) qs('#address').value = addr; populateAddressWidget(addr); }catch(e){}
      // A card payment is already captured on this order, so what was bought is
      // fixed - all money settles on sales day and there is no later step that
      // could absorb a difference. Delivery details stay editable. The server
      // rejects the change too (a device is not a trust boundary); this is only
      // so the seller sees why before they try.
      try{ applyPaidOrderLock(orderObj); }catch(e){}
      // mark editing state
      try{ window._editingOrder = { orderId: orderObj.id || orderObj.order_id || orderObj.orderId || null, local: !!opts.local }; }catch(e){}
  // mark document as being in edit mode so UI can show a watermark or other affordances
  try{ document.body.classList.add('sm-edit-mode'); }catch(e){}
      // inject watermark CSS once (keeps file edits minimal and avoids requiring stylesheet changes)
      try{
        if (!document.getElementById('sm-edit-mode-style')){
          const style = document.createElement('style'); style.id = 'sm-edit-mode-style';
          style.textContent = `body.sm-edit-mode::before{ content: 'EDIT MODE'; position:fixed; left:50%; top:50%; transform:translate(-50%,-50%) rotate(-25deg); font-size:9vw; font-weight:800; color:#000; opacity:0.06; pointer-events:none; z-index:99998; white-space:nowrap; text-align:center; letter-spacing:0.2em; } @media (min-width:1200px){ body.sm-edit-mode::before{ font-size:96px; } }`;
          document.head.appendChild(style);
        }
      }catch(e){}
      // payment method: restore check/cash UI
      try{
        const pm = (orderObj.paymentMethod || orderObj.payment_method || orderObj.payment || (orderObj.order_data && (orderObj.order_data.paymentMethod || orderObj.order_data.payment_method || orderObj.order_data.payment)) || '').toString().toLowerCase();
        if (pm === 'check') { if (payCheck) payCheck.checked = true; if (payCash) payCash.checked = false; checkNumberRow && checkNumberRow.classList.remove('hidden'); }
        else if (pm === 'cash') { if (payCash) payCash.checked = true; if (payCheck) payCheck.checked = false; checkNumberRow && checkNumberRow.classList.add('hidden'); }
        else { if (payCash) payCash.checked = false; if (payCheck) payCheck.checked = false; checkNumberRow && checkNumberRow.classList.add('hidden'); }
        // normalize onto order object so save flow picks it up
        try{ orderObj.paymentMethod = pm; }catch(e){}
      }catch(e){}
      // compute total after populating
      try{ computeTotal(); }catch(e){}
      // re-run input handlers for phone validation and other live listeners
      try{ if (qs('#cellNumber')) qs('#cellNumber').dispatchEvent(new Event('input',{bubbles:true})); }catch(e){}
    }catch(e){ console.warn('enterEditMode error', e); }
  }

  // Populate visible autocomplete widgets with a given address string (best-effort)
  function populateAddressWidget(addressStr){
    try{
      if (!addressStr) return;
      // Only set the canonical plain-text address field. Do not attempt to
      // touch or bridge third-party autocomplete widgets.
      const canonical = qs('#address');
      if (canonical) {
        canonical.value = addressStr;
        try{ canonical.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){}
      }
    }catch(e){ console.warn('populateAddressWidget error', e); }
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
      if (header) {
        if (!document.querySelector('#openInlayBtn')) {
          const btn = document.createElement('button'); btn.id = 'openInlayBtn'; btn.textContent = 'Queued Orders'; btn.className = 'sm-btn hidden';
          header.appendChild(btn);
        }
        // Ensure End-of-Day button exists so we can reveal it after auth even when server shortcode
        if (!document.querySelector('#eodBtn')) {
          const eod = document.createElement('button'); eod.id = 'eodBtn'; eod.textContent = 'EOD Tally'; eod.className = 'sm-btn sm-auth-hidden';
          // prefer inserting after the My orders button so placement is: My orders | EOD Tally | Logout
          const myOrders = header.querySelector('#myOrdersBtn');
          if (myOrders && myOrders.parentNode) {
            // insert after myOrders (before its next sibling) if possible
            const next = myOrders.nextElementSibling;
            if (next) myOrders.parentNode.insertBefore(eod, next);
            else myOrders.parentNode.appendChild(eod);
          } else {
            header.appendChild(eod);
          }
          // attach click handler (functions are hoisted)
          try{ eod.addEventListener('click', async ()=>{ qs('#eodInlay') || ensureEodExists(); qs('#eodInlay').classList.remove('hidden'); await renderEod(); }); }catch(e){}
        }
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

  // End-of-day tally inlay/modal
  function ensureEodExists(){
    if (qs('#eodInlay')) return;
    const div = document.createElement('div');
    div.id = 'eodInlay';
    div.className = 'orders-inlay hidden';
    div.innerHTML = `
      <div class="inlay-header">
        <strong>EOD Tally</strong>
        <button id="closeEodBtn" class="sm-btn">Close</button>
      </div>
      <div id="eodContent" style="padding:12px;">
        <p>Loading tally...</p>
      </div>
    `;
    document.body.appendChild(div);
    const closeBtn = qs('#closeEodBtn'); if (closeBtn) closeBtn.addEventListener('click', ()=>{ qs('#eodInlay').classList.add('hidden'); });
  }

  // Order confirmation popup (compact, mobile-friendly)
  function ensureOrderConfirmationExists(){
    if (qs('#orderConfirmation')) return;
    const div = document.createElement('div');
    div.id = 'orderConfirmation';
    div.className = 'order-confirmation hidden';
    div.innerHTML = `
      <div class="confirmation-content">
        <h3 id="confirmationTitle">Order Saved</h3>
        <div id="confirmationDetails"></div>
        <div class="confirmation-buttons">
          <button id="confirmOkBtn" class="sm-btn sm-btn-primary">OK</button>
          <button id="confirmEditBtn" class="sm-btn">Edit</button>
        </div>
      </div>
    `;
    document.body.appendChild(div);
    // Inject styles for compact mobile-friendly modal
    if (!document.getElementById('order-confirmation-style')){
      const style = document.createElement('style'); style.id = 'order-confirmation-style';
      style.textContent = `
        .order-confirmation { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999; display:flex; align-items:center; justify-content:center; padding:16px; }
        .order-confirmation.hidden { display:none; }
        .confirmation-content { background:#fff; border-radius:8px; padding:24px; max-width:400px; width:100%; box-shadow:0 4px 20px rgba(0,0,0,0.3); }
        .confirmation-content h3 { margin:0 0 16px; font-size:20px; text-align:center; color:#333; }
        #confirmationDetails { margin-bottom:20px; font-size:14px; line-height:1.6; }
        #confirmationDetails strong { display:block; margin-top:12px; margin-bottom:4px; color:#555; }
        #confirmationDetails .product-line { padding:4px 0; border-bottom:1px solid #eee; }
        #confirmationDetails .product-line:last-child { border-bottom:none; }
        .confirmation-buttons { display:flex; gap:12px; justify-content:center; }
        .confirmation-buttons .sm-btn { flex:1; min-height:44px; font-size:16px; border:none; border-radius:6px; cursor:pointer; }
        .confirmation-buttons .sm-btn-primary { background:var(--sm-primary, #2d6cdf); color:#fff; font-weight:600; }
        .confirmation-buttons .sm-btn:not(.sm-btn-primary) { background:#f0f0f0; color:#333; }
      `;
      document.head.appendChild(style);
    }
  }

  // Show order confirmation popup
  function showOrderConfirmation(order, isUpdate){
    try{
      ensureOrderConfirmationExists();
      const modal = qs('#orderConfirmation');
      const title = qs('#confirmationTitle');
      const details = qs('#confirmationDetails');
      if (!modal || !details) return;
      
      // Set title
      if (title) title.textContent = isUpdate ? 'Order Updated' : 'Order Saved';
      
      // Build details HTML
      let html = `<strong>Order #:</strong> ${escapeHtml(order.id)}<br>`;
      html += `<strong>Customer:</strong> ${escapeHtml(order.customer || order.name || '')}<br>`;
      if (order.products && order.products.length) {
        html += `<strong>Products:</strong>`;
        order.products.forEach(p => {
          html += `<div class="product-line">${escapeHtml(p.name || p.id)}: ${p.qty || p.quantity || 0}</div>`;
        });
      }
      details.innerHTML = html;
      
      // Attach button handlers
      const okBtn = qs('#confirmOkBtn');
      const editBtn = qs('#confirmEditBtn');
      
      if (okBtn) {
        okBtn.onclick = () => {
          modal.classList.add('hidden');
          clearOrderForm();
          window._editingOrder = null;
          // If a driver was editing a child's order, return to the money view.
          if (window._driverEditing) { window._driverEditing = false; try{ driverReturnFromEdit(); }catch(e){} }
        };
      }
      
      if (editBtn) {
        editBtn.onclick = () => {
          modal.classList.add('hidden');
          enterEditMode(order, { local: true });
        };
      }
      
      // Show modal
      modal.classList.remove('hidden');
    }catch(e){ console.warn('showOrderConfirmation error', e); }
  }

  // ==========================================================================
  // Driver "Money Accountability" view
  // A driver (role='driver') logs in exactly like a child, selects their team,
  // and sees the team's running cash/check totals (per child + team) instead of
  // the sales form. Money is computed server-side (/team-tally) and re-synced
  // every 60s. Drivers can drill into a child and edit orders via the same
  // remote-edit flow children use.
  // ==========================================================================
  let _driverTallyData = null;
  let _driverTimer = null;
  let _driverLastUpdate = 0;
  let _driverClockTimer = null;

  function driverHeaders(){
    return {
      'X-User-ID': localStorage.getItem('userId') || '',
      'X-Team-ID': localStorage.getItem('selectedTeamId') || ''
    };
  }
  function dmMoney(n){ return '$' + (Number(n||0)).toFixed(2); }
  function dmTimeLabel(dt){
    if (!dt) return '';
    // Server sends a UTC ISO string (…Z); show it in the device's local time.
    try{
      const d = new Date(dt);
      if (!isNaN(d.getTime())) return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }catch(e){}
    const m = String(dt).match(/(\d{2}):(\d{2})/);
    return m ? (m[1] + ':' + m[2]) : String(dt);
  }
  function dmItemsSummary(items, products){
    if (!items) return '';
    const names = {};
    (products||[]).forEach(p => { names[String(p.id)] = p.name; });
    return Object.keys(items).map(pid => (names[pid]||pid) + ' ' + items[pid]).join(' · ');
  }

  // Route to the driver view only when authenticated into the app view (login
  // screen dismissed) as a driver with a real team. Otherwise ensure the money
  // view is torn down. Safe/no-op for children & admins.
  function applyDriverRoleView(){
    try{
      const role = localStorage.getItem('userRole');
      const teamId = localStorage.getItem('selectedTeamId');
      const login = qs('#loginSection');
      const loginHidden = !login || login.classList.contains('hidden') || login.style.display === 'none';
      const app = qs('#appSection');
      const appVisible = app && !app.classList.contains('hidden') && app.style.display !== 'none';
      if (role === 'driver' && teamId && teamId !== '-1' && loginHidden && appVisible) {
        enterDriverMode();
      } else {
        driverExitMode();
      }
    }catch(e){}
  }

  // Tear down the driver view (used on logout / when not authenticated).
  function driverExitMode(){
    if (_driverTimer) { clearInterval(_driverTimer); _driverTimer = null; }
    if (_driverClockTimer) { clearInterval(_driverClockTimer); _driverClockTimer = null; }
    const dm = qs('#driverMoney'); if (dm) dm.classList.add('hidden');
  }

  function ensureDriverMoneyExists(){
    if (qs('#driverMoney')) return;
    const app = qs('#appSection'); if (!app) return;
    const div = document.createElement('div');
    div.id = 'driverMoney';
    div.className = 'sm-driver-money hidden';
    div.innerHTML = '<div class="dm-loading">Loading team totals…</div>';
    app.appendChild(div);
  }

  // Hide/show the seller UI regardless of which shell is live (static
  // index.html markup vs the app.js template) by toggling every #appSection
  // child except our money panel.
  function driverForEachAppChild(fn){
    const app = qs('#appSection'); if (!app) return;
    Array.prototype.slice.call(app.children).forEach(fn);
  }
  function driverHideSalesUI(){ driverForEachAppChild(function(ch){ if (ch.id !== 'driverMoney') ch.style.display = 'none'; }); }
  function driverShowSalesUI(){ driverForEachAppChild(function(ch){ if (ch.id !== 'driverMoney') ch.style.display = ''; }); }

  function enterDriverMode(){
    ensureDriverMoneyExists();
    driverHideSalesUI();
    const mo = qs('#myOrdersBtn'); if (mo) mo.classList.add('hidden');
    const eod = qs('#eodBtn'); if (eod) eod.classList.add('hidden');
    const dm = qs('#driverMoney'); if (dm) dm.classList.remove('hidden');

    loadDriverTally();
    if (_driverTimer) clearInterval(_driverTimer);
    _driverTimer = setInterval(loadDriverTally, 60000);
    if (_driverClockTimer) clearInterval(_driverClockTimer);
    _driverClockTimer = setInterval(driverUpdateClock, 15000);
    if (!window._driverVisHooked) {
      window._driverVisHooked = true;
      document.addEventListener('visibilitychange', function(){
        const el = qs('#driverMoney');
        if (!document.hidden && el && !el.classList.contains('hidden')) loadDriverTally();
      });
    }
    // Returning to the money view via the Clear button when mid-edit.
    if (!window._driverClearHooked) {
      window._driverClearHooked = true;
      const clr = qs('#clearFormBtn');
      if (clr) clr.addEventListener('click', function(){
        if (window._driverEditing) { window._driverEditing = false; driverReturnFromEdit(); }
      });
    }
  }

  function driverUpdateClock(){
    const el = qs('#dmUpdated'); if (!el || !_driverLastUpdate) return;
    const secs = Math.max(0, Math.round((Date.now() - _driverLastUpdate)/1000));
    const label = secs < 5 ? 'just now' : (secs < 60 ? (secs + 's ago') : (Math.round(secs/60) + 'm ago'));
    el.textContent = 'Updated ' + label;
  }

  async function loadDriverTally(){
    const teamId = localStorage.getItem('selectedTeamId');
    if (!teamId || teamId === '-1') return;
    try{
      const base = apiBase ? apiBase : '/wp-json/order-manager/v1';
      const resp = await fetch(base + '/team-tally?team_id=' + encodeURIComponent(teamId), { headers: driverHeaders() });
      if (!resp.ok){ driverShowError(resp.status === 403 ? 'You are not the driver for this team.' : ('Could not load totals (error ' + resp.status + ').')); return; }
      const data = await resp.json();
      _driverTallyData = data;
      _driverLastUpdate = Date.now();
      renderDriverTally(data);
    }catch(e){ driverShowError('Network error — could not reach the server.'); }
  }

  // On failure: keep the last good tally (with a stale badge) if we have one,
  // otherwise show an error + Retry — never a stuck spinner.
  function driverShowError(msg){
    if (_driverTallyData){
      renderDriverTally(_driverTallyData);
      const badge = qs('#dmStale'); if (badge) badge.classList.remove('hidden');
      return;
    }
    const el = qs('#driverMoney'); if (!el) return;
    el.innerHTML = '<div class="dm-error"><p>' + escapeHtml(msg) + '</p><button id="dmRetry" class="sm-btn">Retry</button></div>';
    const rb = el.querySelector('#dmRetry'); if (rb) rb.addEventListener('click', loadDriverTally);
  }

  function renderDriverTally(data){
    const el = qs('#driverMoney'); if (!el) return;
    const t = (data && data.totals) || { cash:0, check:0, donation:0, digital:0, total:0, sales_total:0, order_count:0, checks_count:0, items:{} };
    const products = (data && data.products) || [];
    const sellers = (data && data.sellers) || [];

    let html = '';
    html += '<div class="dm-head">';
    html +=   '<div class="dm-titles"><div class="dm-title">Money Accountability</div>' +
              '<div class="dm-sub">' + escapeHtml(data.team_name||'') + (data.campaign_date ? (' · ' + escapeHtml(data.campaign_date)) : '') + '</div></div>';
    html +=   '<div class="dm-meta"><span id="dmUpdated" class="dm-updated">Updated just now</span>' +
              '<span id="dmStale" class="dm-stale hidden">offline — showing last count</span>' +
              '<button id="dmRefresh" class="sm-btn dm-refresh">Refresh</button></div>';
    html += '</div>';

    // Reconciliation hero — what the driver should physically have
    html += '<div class="dm-hero">';
    html +=   '<div class="dm-hero-label">You should have</div>';
    html +=   '<div class="dm-hero-total">' + dmMoney(t.total) + '</div>';
    html +=   '<div class="dm-hero-split">';
    html +=     '<div class="dm-chip"><span>Cash</span><strong>' + dmMoney(t.cash) + '</strong></div>';
    html +=     '<div class="dm-chip"><span>Checks</span><strong>' + dmMoney(t.check) + '</strong>' +
                '<em>' + (t.checks_count||0) + (t.checks_count===1?' check':' checks') + '</em></div>';
    html +=     '<div class="dm-chip"><span>Digital</span><strong>' + dmMoney(t.digital) + '</strong>' +
                '<em>already paid — nothing to collect</em></div>';
    html +=   '</div>';
    html += '</div>';

    // Team total line
    const itemsSum = dmItemsSummary(t.items, products);
    html += '<div class="dm-teamline"><strong>Team total</strong> · ' + (t.order_count||0) +
            (t.order_count===1?' order':' orders') + (itemsSum ? (' · ' + escapeHtml(itemsSum)) : '') +
            (t.donation ? (' · donations ' + dmMoney(t.donation)) : '') + '</div>';

    // Per-child list
    html += '<div class="dm-sellers">';
    if (!sellers.length){
      html += '<div class="dm-empty">No children on this team for today.</div>';
    } else {
      sellers.forEach(s => {
        const zero = !(s.order_count > 0);
        html += '<div class="dm-seller' + (zero?' dm-zero':'') + '" data-seller="' + escapeHtml(String(s.user_id)) + '">';
        html +=   '<button type="button" class="dm-seller-row" data-seller="' + escapeHtml(String(s.user_id)) + '">';
        html +=     '<span class="dm-name">' + escapeHtml(s.name||'Unknown') + '</span>';
        if (zero){
          html +=   '<span class="dm-noorders">No sales yet</span>';
        } else {
          html +=   '<span class="dm-cash">' + dmMoney(s.cash) + '</span>';
          html +=   '<span class="dm-check">' + dmMoney(s.check) + '</span>';
          html +=   '<span class="dm-digital">' + dmMoney(s.digital) + '</span>';
          html +=   '<span class="dm-total">' + dmMoney(s.total) + '</span>';
        }
        html +=   '</button>';
        html +=   '<div class="dm-drill hidden" data-drill="' + escapeHtml(String(s.user_id)) + '">' + renderDriverDrill(s, products) + '</div>';
        html += '</div>';
      });
    }
    html += '</div>';

    el.innerHTML = html;
    driverUpdateClock();
    driverWireEvents();
  }

  function renderDriverDrill(seller, products){
    const orders = seller.orders || [];
    let h = '';
    const itemsSum = dmItemsSummary(seller.items, products);
    if (itemsSum) h += '<div class="dm-drill-items">Items: ' + escapeHtml(itemsSum) + '</div>';
    if (!orders.length){ h += '<div class="dm-empty">No orders.</div>'; return h; }
    orders.forEach(o => {
      const prod = (o.products||[]).map(p => (p.qty + '× ' + (p.name||p.id))).join(', ');
      const payLabel = o.payment === 'check' ? ('Check' + (o.check_number ? (' #' + escapeHtml(String(o.check_number))) : '')) :
                       (o.payment === 'cash' ? 'Cash' : (o.payment === 'digital' ? 'Digital (paid)' : (o.payment||'—')));
      h += '<div class="dm-order">';
      h +=   '<div class="dm-order-head"><span class="dm-order-time">' + escapeHtml(dmTimeLabel(o.created_at)) + '</span>' +
             '<span class="dm-order-pay">' + payLabel + '</span>' +
             '<span class="dm-order-total">' + dmMoney(o.total) + '</span>' +
             '<button type="button" class="sm-btn dm-edit" data-order-id="' + escapeHtml(String(o.order_id)) + '">Edit</button></div>';
      h +=   '<div class="dm-order-items">' + escapeHtml(prod || '—') + (o.donation ? (' · donation ' + dmMoney(o.donation)) : '') + '</div>';
      h += '</div>';
    });
    return h;
  }

  function driverWireEvents(){
    const el = qs('#driverMoney'); if (!el) return;
    const rf = el.querySelector('#dmRefresh'); if (rf) rf.addEventListener('click', loadDriverTally);
    el.querySelectorAll('.dm-seller-row').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-seller');
        const drill = el.querySelector('.dm-drill[data-drill="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
        if (drill) drill.classList.toggle('hidden');
      });
    });
    el.querySelectorAll('.dm-edit').forEach(b => {
      b.addEventListener('click', (ev) => { ev.stopPropagation(); driverEditOrder(b.getAttribute('data-order-id')); });
    });
  }

  async function driverEditOrder(orderId){
    if (!orderId) return;
    try{
      const base = apiBase ? apiBase : '/wp-json/order-manager/v1';
      const resp = await fetch(base + '/orders/' + encodeURIComponent(orderId), { headers: driverHeaders() });
      if (!resp.ok){ alert('Could not load that order to edit.'); return; }
      const row = await resp.json();
      const od = (row.order_data && typeof row.order_data === 'string')
        ? (function(){ try{ return JSON.parse(row.order_data); }catch(e){ return {}; } })()
        : (row.order_data || {});
      const orderObj = Object.assign({}, od, { id: row.order_id || orderId });
      // Show the sales form to edit; remember to return to the money view after save.
      window._driverEditing = true;
      const dm = qs('#driverMoney'); if (dm) dm.classList.add('hidden');
      driverShowSalesUI();
      try{ window.scrollTo(0,0); }catch(e){}
      enterEditMode(orderObj, { local:false, remoteRaw: row });
    }catch(e){ alert('Could not load that order to edit.'); }
  }

  function driverReturnFromEdit(){
    driverHideSalesUI();
    const dm = qs('#driverMoney'); if (dm) dm.classList.remove('hidden');
    // Reload now and again shortly, to catch the queued PUT once it syncs.
    loadDriverTally();
    setTimeout(loadDriverTally, 2000);
  }

  async function renderEod(){
    ensureEodExists();
    const container = qs('#eodContent'); if (!container) return;
    container.innerHTML = '<p>Computing totals...</p>';
    
    // Get current user ID (supports both user mode and legacy mode)
    const loginMode = localStorage.getItem('loginMode') || 'legacy';
    let currentUserId, currentUserName, currentTeamId;
    
    if (loginMode === 'user') {
      // User mode: use userId from localStorage
      currentUserId = localStorage.getItem('userId') || '';
      currentUserName = localStorage.getItem('userName') || '';
      currentTeamId = localStorage.getItem('selectedTeamId') || '';
    } else {
      // Legacy mode: use teamMemberId
      currentUserId = localStorage.getItem('teamMemberId') || '';
      currentUserName = localStorage.getItem('teamMemberName') || '';
      currentTeamId = localStorage.getItem('teamId') || '';
    }
    
    // Check if we're in individual mode (team_id = -1)
    const isIndividualMode = (currentTeamId === '-1' || currentTeamId === -1);
    
    // Gather local and remote orders
    let local = [];
    try{ local = await Storage.all() || []; }catch(e){ local = []; }
    let remote = [];
    try{
      const url = apiBase ? (apiBase + '/orders?limit=10000') : '/wp-json/order-manager/v1/orders?limit=10000';
      
      // Use appropriate auth headers based on login mode
      const headers = {};
      if (loginMode === 'user') {
        headers['X-User-ID'] = localStorage.getItem('userId') || '';
        headers['X-Team-ID'] = localStorage.getItem('selectedTeamId') || '';
      } else {
        headers['X-Team-Name'] = localStorage.getItem('teamName') || '';
        headers['X-Access-Code'] = localStorage.getItem('teamCode') || '';
      }
      
      const resp = await fetch(url, { headers: headers });
      if (resp && resp.ok) { remote = await resp.json(); }
    }catch(e){ remote = []; }
    // Fetch server date info so we align "today" with server time
    let serverInfo = null;
    try{
      const timeUrl = apiBase ? (apiBase + '/time') : '/wp-json/order-manager/v1/time';
      const tr = await fetch(timeUrl);
      if (tr && tr.ok) serverInfo = await tr.json();
    }catch(e){ serverInfo = null; }
    // Normalize orders: accept both local objects and remote DB rows that may include order_data
    const all = (local||[]).concat(remote||[]);
    // Build product totals map
    // Use the client products config (productsConfig) that is maintained elsewhere in the app
    const products = (productsConfig && Array.isArray(productsConfig)) ? productsConfig : [];
    const prodTotals = {};
    products.forEach(p => { prodTotals[p.id] = 0; });
    let totalDonation = 0; let totalCash = 0; let totalCheck = 0; let totalDigital = 0;
    // Only include today's orders (in server time). Helper to detect same-day using serverInfo.
    function isSameDayForServer(o, created){
      try{
        // If server returned an explicit flag for server-side rows, trust it first
        if (o && typeof o.is_today !== 'undefined') return !!o.is_today;
        // fallback: if we don't have serverInfo, use local detection
        if (!serverInfo || !serverInfo.server_date) {
          if(!created) return false;
          const d = new Date(created);
          if (isNaN(d.getTime())) return false;
          const now = new Date();
          return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
        }
        const serverDate = serverInfo.server_date; // 'YYYY-MM-DD'
        const gmtOffset = parseFloat(serverInfo.gmt_offset || 0); // hours
        const offsetSec = Math.round(gmtOffset * 3600);
        // Determine created timestamp in seconds
        let ts = null;
        if (o && o.created_at_ts) ts = parseInt(o.created_at_ts,10);
        else if (created) {
          const parsed = Date.parse(created);
          if (!isNaN(parsed)) ts = Math.floor(parsed/1000);
        }
        if (!ts) return false;
        // shift by server offset to get server-local day and compare YYYY-MM-DD
        const serverLocalTs = ts + offsetSec;
        const ymd = new Date(serverLocalTs * 1000).toISOString().slice(0,10);
        return ymd === serverDate;
      }catch(e){ return false; }
    }

    function extractOrderInfo(o){
      // order may be local-format (with createdAt) or remote row with order_data/created_at
      const od = (o.order_data && typeof o.order_data === 'object') ? o.order_data : (o.order_data && typeof o.order_data === 'string' ? (function(){ try{ return JSON.parse(o.order_data); }catch(e){ return {}; } })() : o);
      
      // Filter by current user FIRST (always show only MY orders)
      const isMyOrder = (currentUserId && od.entered_by_id && od.entered_by_id === currentUserId) || 
                        (currentUserId && od.subsales_user_id && od.subsales_user_id === currentUserId) ||
                        (currentUserName && od.entered_by_name && od.entered_by_name === currentUserName) ||
                        (currentUserId && o.user_id && String(o.user_id) === String(currentUserId));
      
      if (!isMyOrder) return; // skip orders not from current user
      
      // Date/tally filtering depends on mode:
      // Individual mode (team -1): Show all MY untallied orders regardless of date or which team created them
      // Team mode: Show only MY orders from today
      if (isIndividualMode) {
        // For individual mode, only show orders that haven't been tallied yet (any team)
        const isTallied = (o.tallied === 1 || o.tallied === '1' || od.tallied === 1 || od.tallied === '1');
        
        // Skip tallied orders
        if (isTallied) return;
      } else {
        // Team mode: only include today's orders
        const created = od && (od.createdAt || od.created_at) || o.createdAt || o.created_at || null;
        if (!isSameDayForServer(o, created)) return; // skip orders not from today (per server)
      }
      const productsList = od.products || [];
      const donation = parseFloat( od.donationAmount || od.donation || od.donation_amount || 0 ) || 0;
      const payment = od.paymentMethod || od.payment_method || od.payment || '';
      // compute order total: sum products price*qty plus donation when product prices available
      let orderTotal = 0;
      if (Array.isArray(productsList)){
        productsList.forEach(pi => {
          try{
            const pid = pi.id || pi.product_id || pi.name;
            const qty = parseInt(pi.qty || pi.quantity || pi.qty_sold || 0,10) || 0;
            // add to product totals if known
            if (pid && prodTotals.hasOwnProperty(pid)) prodTotals[pid] += qty;
            // try price
            const price = parseFloat(pi.price || pi.unit_price || 0) || 0;
            orderTotal += qty * price;
          }catch(e){}
        });
      }
      orderTotal += donation;
      // add donation and payment buckets
      totalDonation += donation;
      if (payment === 'cash') totalCash += orderTotal;
      else if (payment === 'check') totalCheck += orderTotal;
      else if (payment === 'digital') totalDigital += orderTotal;
    }
    all.forEach(o => { try{ extractOrderInfo(o); }catch(e){} });
    
    // Update header to show user filter
    const headerEl = qs('#eodInlay .inlay-header strong');
    if (headerEl) {
      const filterText = isIndividualMode ? 'Untallied Orders' : 'Today Only';
      headerEl.textContent = `EOD Tally - ${filterText} (${currentUserName || currentUserId || 'You'})`;
    }
    
    // Render table
    let html = '<table class="widefat fixed" style="margin-bottom:12px"><thead><tr><th>Product</th><th style="text-align:right">Qty sold</th></tr></thead><tbody>';
    products.forEach(p => { const q = prodTotals[p.id] || 0; html += `<tr><td>${escapeHtml(p.name||p.id)}</td><td style="text-align:right">${q}</td></tr>`; });
    html += '</tbody></table>';
    html += '<table class="widefat fixed" style="max-width:320px"><tbody>';
    html += `<tr><td><strong>Total Donation</strong></td><td style="text-align:right">$${Number(totalDonation||0).toFixed(2)}</td></tr>`;
    html += `<tr><td><strong>Total Cash</strong></td><td style="text-align:right">$${Number(totalCash||0).toFixed(2)}</td></tr>`;
    html += `<tr><td><strong>Total Check</strong></td><td style="text-align:right">$${Number(totalCheck||0).toFixed(2)}</td></tr>`;
    html += `<tr><td><strong>Total Digital</strong></td><td style="text-align:right">$${Number(totalDigital||0).toFixed(2)}</td></tr>`;
    const totalSales = totalCash + totalCheck + totalDigital + totalDonation;
    html += `<tr style="border-top:2px solid #0f172a"><td><strong>Total Sales</strong></td><td style="text-align:right"><strong>$${Number(totalSales||0).toFixed(2)}</strong></td></tr>`;
    html += '</tbody></table>';
    container.innerHTML = html;
  }

  function escapeHtml(s){ if (s===null||s===undefined) return ''; return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

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
    const DB = 'subsales-pwa-db';
    const STORE = 'orders';
    function idbOpen(){
      return new Promise((resolve)=>{
        if (!('indexedDB' in window)) return resolve(null);
        // use version 2 to allow two object stores: orders and ops
        const r = indexedDB.open(DB, 2);
        r.onupgradeneeded = (ev) => { try {
            const db = r.result;
            if (!db.objectStoreNames.contains(STORE)) {
              db.createObjectStore(STORE, { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains('ops')) {
              db.createObjectStore('ops', { keyPath: '_id' });
            }
          } catch(e){} };
        r.onsuccess = ()=>resolve(r.result);
        r.onerror = ()=>resolve(null);
      });
    }
    async function getDB(){ if (!window._bkmb_db) window._bkmb_db = await idbOpen(); return window._bkmb_db; }
    async function add(o){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).put(o); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); list.push(o); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    async function all(){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readonly'); const req = tx.objectStore(STORE).getAll(); req.onsuccess = ()=>res(req.result||[]); req.onerror = ()=>res([]); }); } return JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); }
    async function remove(id){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).delete(id); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]').filter(x=>x.id!==id); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    async function get(id){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readonly'); const req = tx.objectStore(STORE).get(id); req.onsuccess = ()=>res(req.result||null); req.onerror = ()=>res(null); }); } const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); return list.find(x=>x.id===id) || null; }
    async function update(o){ // upsert by id
      if (!o || !o.id) return false;
      const db = await getDB();
      if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).put(o); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); }
      const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]');
      const idx = list.findIndex(x=>x.id===o.id);
      if (idx>=0) list[idx] = o; else list.push(o);
      localStorage.setItem('bkmb_orders', JSON.stringify(list));
      return true;
    }
  // Operations store in IndexedDB 'ops' with fallback to localStorage
  async function opsAdd(op){ try{ if (!op || !op.type) return null; op._id = op._id || ('op_' + Date.now() + '_' + Math.floor(Math.random()*10000)); const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction('ops', 'readwrite'); tx.objectStore('ops').put(op); tx.oncomplete = ()=>res(op._id); tx.onerror = ()=>res(null); }); } // fallback
    const arr = JSON.parse(localStorage.getItem('bkmb_ops')||'[]'); arr.push(op); localStorage.setItem('bkmb_ops', JSON.stringify(arr)); return op._id; }catch(e){ return null; } }
  async function opsAll(){ try{ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction('ops','readonly'); const req = tx.objectStore('ops').getAll(); req.onsuccess = ()=>res(req.result||[]); req.onerror = ()=>res([]); }); } return JSON.parse(localStorage.getItem('bkmb_ops')||'[]'); }catch(e){ return []; } }
  async function opsRemove(opId){ try{ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction('ops','readwrite'); tx.objectStore('ops').delete(opId); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const arr = JSON.parse(localStorage.getItem('bkmb_ops')||'[]').filter(o=>o._id !== opId); localStorage.setItem('bkmb_ops', JSON.stringify(arr)); return true; }catch(e){ return false; } }
  async function opsClear(){ try{ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction('ops','readwrite'); tx.objectStore('ops').clear(); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } localStorage.removeItem('bkmb_ops'); return true; }catch(e){ return false; } }
  async function queueOperation(op){ return await opsAdd(op); }
  async function allQueuedOps(){ return await opsAll(); }
  async function removeQueuedOp(opId){ return await opsRemove(opId); }
  return { add, all, remove, get, update, queueOperation, allQueuedOps, removeQueuedOp, opsClear };
  })();

  // Elements
  const loginSection = qs('#loginSection');
  const appSection = qs('#appSection');
  const loginBtn = qs('#loginBtn');
  const saveOrderBtn = qs('#saveOrderBtn');
  const clearFormBtn = qs('#clearFormBtn');
  const ordersList = qs('#ordersList');
  const networkStatus = qs('#networkStatus');
  const syncStatus = qs('#syncStatus');

  const salesClosedSection = qs('#salesClosedSection');
  const salesClosedMessage = qs('#salesClosedMessage');
  const salesClosedRetry = qs('#salesClosedRetry');

  // Products will be rendered dynamically from configured products (localized in cfg.products)
  const donationAmount = qs('#donationAmount');
  const orderTotalEl = qs('#orderTotal');
  const payCheck = qs('#payCheck');
  const payCash = qs('#payCash');
  const checkNumberRow = qs('#checkNumberRow');
  const checkNumber = qs('#checkNumber');

  // Digital payment (Square QR) elements — Phase 5
  const payDigital = qs('#payDigital');
  const payDigitalOption = qs('#payDigitalOption');
  const digitalPaymentPanel = qs('#digitalPaymentPanel');
  const digitalConfirmPanel = qs('#digitalConfirmPanel');
  const digitalQrPanel = qs('#digitalQrPanel');
  const digitalReadyBtn = qs('#digitalReadyBtn');
  const digitalBackBtn1 = qs('#digitalBackBtn1');
  const digitalCancelBtn = qs('#digitalCancelBtn');
  const digitalStatusText = qs('#digitalStatusText');
  const digitalQrImg = qs('#digitalQrImg');
  const digitalExpiryText = qs('#digitalExpiryText');
  // Phase 4 exposed this as digitalPaymentsEnabled both on the localized SUBSALES_PWA_CONFIG
  // and the /config REST response; cfg already IS window.SUBSALES_PWA_CONFIG when present,
  // but check both explicitly per spec in case cfg fell back to BKMB_PWA_CONFIG/{}.
  const digitalPaymentsEnabled = !!(cfg.digitalPaymentsEnabled || (window.SUBSALES_PWA_CONFIG && window.SUBSALES_PWA_CONFIG.digitalPaymentsEnabled));
  if (payDigitalOption) { if (digitalPaymentsEnabled) payDigitalOption.classList.remove('hidden'); else payDigitalOption.classList.add('hidden'); }

  let digitalPollInterval = null;
  let digitalWaitingMsgInterval = null;
  let digitalCurrentAttemptId = null;
  let digitalExpiresAt = null;
  let digitalOrderData = null; // snapshot of buyer/items captured at "Ready" tap time

  let salesEnabled = true;

  // Products configuration: mutable cache. Prefer reading from window.SUBSALES_PWA_CONFIG at render time.
  let productsConfig = (cfg.products && Array.isArray(cfg.products)) ? cfg.products.slice() : [];

  // Render product quantity inputs into #productsContainer. Show only visible products by default.
  function renderProducts(){
    try{
      const container = qs('#productsContainer'); if (!container) return;
      container.innerHTML = '';
      // Prefer freshest products definition from the injected global config
      const injected = (window.SUBSALES_PWA_CONFIG && Array.isArray(window.SUBSALES_PWA_CONFIG.products)) ? window.SUBSALES_PWA_CONFIG.products : null;
      const cfgProducts = (cfg && Array.isArray(cfg.products)) ? cfg.products : null;
      let list = injected || cfgProducts || (productsConfig && productsConfig.length ? productsConfig : null);
      if (!list || !list.length) {
        // fallback to legacy defaults
        list = [ { id: 'turkey', name: 'Turkey', price: '10.00', visible: 1 }, { id: 'ham', name: 'Ham', price: '10.00', visible: 1 }, { id: 'combo', name: 'Combo', price: '10.00', visible: 1 } ];
      }
      list.forEach(p => {
        try{
          if (p.visible === 0 || p.visible === '0' || p.visible === false) return; // skip hidden
          const pid = String(p.id || p.name).replace(/[^a-z0-9-_]/ig,'_');
          const label = document.createElement('label'); label.textContent = p.name;
          const wrapper = document.createElement('div'); wrapper.className = 'col-2';
          const input = document.createElement('input'); input.type = 'number'; input.inputMode = 'numeric'; input.min = '0'; input.step = '1'; input.placeholder = '0'; input.setAttribute('data-product-id', p.id); input.setAttribute('data-product-price', String(p.price||'0')); input.id = 'product_' + pid + '_qty';
          input.className = 'product-qty-input';
          input.addEventListener('input', computeTotal);
          wrapper.appendChild(label);
          wrapper.appendChild(input);
          container.appendChild(wrapper);
        }catch(e){}
      });
    }catch(e){ console.warn('renderProducts failed', e); }
  }

  // Initial render: wait for DOM ready and also schedule a short delayed render to handle late-injected globals
  try{
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      try { renderProducts(); } catch(e){}
    } else {
      document.addEventListener('DOMContentLoaded', ()=>{ try{ renderProducts(); }catch(e){} });
    }
    // extra delayed re-render to catch cases where config/script is injected after boot
    setTimeout(()=>{ try{ renderProducts(); }catch(e){} }, 500);
  }catch(e){}

  // Defensive address getter: tries the canonical #address first, then several
  // common alternate selectors (autocomplete-injected inputs). If an alternate
  // is found it will copy the value back to the canonical input and dispatch
  // an input event so any listeners or validations pick up the change.
  function getCanonicalAddress(){
    try{
      const primary = qs('#address');
      return (primary && primary.value && primary.value.trim()) || '';
    }catch(e){ return ''; }
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
        // Only mark invalid if field has content but isn't 10 digits
        if (digits.length === 0) {
          pn.classList.remove('invalid');
          try{ pn.setCustomValidity(''); }catch(e){}
        } else if (digits.length === 10) {
          pn.classList.remove('invalid');
          try{ pn.setCustomValidity(''); }catch(e){}
        } else {
          pn.classList.add('invalid');
          try{ pn.setCustomValidity('Phone number must be 10 digits'); }catch(e){}
        }
      }catch(e){}
    });
    pn.addEventListener('blur', ()=>{
      try{
        const v = pn.value.replace(/\D/g,'');
        // Only complain about a HALF-typed number. An empty field just means
        // the seller hasn't got to it yet - they tab out to the address and
        // back constantly, and popping a validation bubble every time they
        // leave an untouched field made the form feel broken. Saving still
        // requires a phone number; that check lives on the Save button.
        if (v.length > 0 && v.length !== 10) { try{ pn.reportValidity(); }catch(e){} }
      }catch(e){}
    });
  })();

  // Debug/troubleshooting helpers removed — the PWA now uses a plain text
  // #address input as the single source of truth. Previous deep-inspection,
  // proximity scanning, and debug panels were removed to simplify behavior.

      // All deep-inspection/proximity scanning removed.

  // ========================================
  // PWA Install Prompt with iOS Support & Analytics
  // ========================================
  let deferredPrompt = null;
  const installPrompt = qs('#installPrompt');
  const installPromptBtn = qs('#installPromptBtn');
  const installDismissBtn = qs('#installDismissBtn');
  const iosInstallModal = qs('#iosInstallModal');
  const iosInstallCloseBtn = qs('#iosInstallCloseBtn');
  
  // Helper: Detect iOS
  function isIOSDevice() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
  }
  
  // Helper: Check if already installed
  function isAppInstalled() {
    // Check display mode
    const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone || false;
    // Check localStorage flag
    const installedFlag = localStorage.getItem('pwa_installed');
    return isStandalone || installedFlag === 'true';
  }
  
  // Helper: Track install analytics
  function trackInstallEvent(eventType, details = {}) {
    try {
      const event = {
        type: eventType,
        timestamp: new Date().toISOString(),
        userAgent: navigator.userAgent,
        isIOS: isIOSDevice(),
        isStandalone: isAppInstalled(),
        ...details
      };
      
      // Log to console

      // Store in localStorage for later sync
      const analytics = JSON.parse(localStorage.getItem('pwa_install_analytics') || '[]');
      analytics.push(event);
      // Keep last 50 events
      if (analytics.length > 50) analytics.shift();
      localStorage.setItem('pwa_install_analytics', JSON.stringify(analytics));
      
      // Log to PWALogger if available
      if (window.PWALogger) {
        window.PWALogger.log('install', eventType, event);
      }
    } catch(e) {
      console.warn('Install analytics error:', e);
    }
  }
  
  // Helper: Show install prompt
  function showInstallPrompt() {
    try {
      // Don't show if dismissed, installed, or not supported
      if (localStorage.getItem('pwa_install_dismissed') === 'true') return;
      if (isAppInstalled()) return;
      
      if (installPrompt) {
        installPrompt.classList.remove('hidden');
        trackInstallEvent('prompt_shown');
      }
    } catch(e) {
      console.warn('showInstallPrompt error:', e);
    }
  }
  
  // Helper: Hide install prompt
  function hideInstallPrompt() {
    if (installPrompt) installPrompt.classList.add('hidden');
  }
  
  // Listen for beforeinstallprompt (Android/Chrome)
  window.addEventListener('beforeinstallprompt', (e) => {
    try {
      e.preventDefault();
      deferredPrompt = e;
      // Expose to window for debugging
      try { window._deferredPWAPrompt = e; } catch(ex) {}

      trackInstallEvent('beforeinstallprompt_captured');
      
      // Show prompt if conditions met
      showInstallPrompt();
    } catch(err) {
      console.warn('beforeinstallprompt handler error', err);
    }
  });
  
  // Listen for successful app install
  window.addEventListener('appinstalled', (e) => {
    try {

      localStorage.setItem('pwa_installed', 'true');
      trackInstallEvent('app_installed', { method: 'native' });
      
      // Hide prompt
      hideInstallPrompt();
      
      // Show success message
      if (window.smShowSnackbar) {
        window.smShowSnackbar('✅ App installed successfully!', { timeout: 5000 });
      }
      
      // Clear deferred prompt
      deferredPrompt = null;
      try { window._deferredPWAPrompt = null; } catch(ex) {}
    } catch(err) {
      console.warn('appinstalled handler error', err);
    }
  });
  
  // Install button click handler
  if (installPromptBtn) {
    installPromptBtn.addEventListener('click', async () => {
      try {
        // iOS device - show manual instructions
        if (isIOSDevice()) {
          trackInstallEvent('ios_instructions_shown');
          if (iosInstallModal) {
            iosInstallModal.classList.remove('hidden');
          }
          return;
        }
        
        // Android/Chrome - use native prompt
        if (!deferredPrompt) {
          // Diagnostics if prompt not available
          let manifestHref = '(none)';
          try {
            const m = document.querySelector('link[rel="manifest"]');
            manifestHref = m ? m.href : '(none)';
          } catch(e) {}
          
          const protocol = location.protocol || '(unknown)';
          let swRegs = [];
          if ('serviceWorker' in navigator) {
            try {
              swRegs = await navigator.serviceWorker.getRegistrations();
            } catch(e) {
              swRegs = [];
            }
          }
          
          trackInstallEvent('install_not_available', {
            protocol,
            manifestHref,
            swRegistrations: swRegs.length,
            isStandalone: isAppInstalled()
          });
          
          alert('Install not currently available. This may be because:\n• Already installed\n• Not running on HTTPS\n• Browser doesn\'t support PWA installation\n\nCheck console for details.');

          return;
        }
        
        // Show native install prompt
        trackInstallEvent('install_prompt_shown');
        deferredPrompt.prompt();
        
        // Wait for user choice
        const choiceResult = await deferredPrompt.userChoice;
        trackInstallEvent('install_user_choice', { outcome: choiceResult.outcome });
        
        if (choiceResult.outcome === 'accepted') {

          // Note: appinstalled event will handle success UI
        } else {

        }
        
        // Clear deferred prompt
        deferredPrompt = null;
        try { window._deferredPWAPrompt = null; } catch(e) {}
        hideInstallPrompt();
      } catch(err) {
        console.warn('installPromptBtn click error', err);
        trackInstallEvent('install_error', { error: err.message });
      }
    });
  }
  
  // Dismiss button click handler
  if (installDismissBtn) {
    installDismissBtn.addEventListener('click', () => {
      try {
        localStorage.setItem('pwa_install_dismissed', 'true');
        trackInstallEvent('install_dismissed');
        hideInstallPrompt();

      } catch(err) {
        console.warn('installDismissBtn click error', err);
      }
    });
  }
  
  // iOS modal close button
  if (iosInstallCloseBtn) {
    iosInstallCloseBtn.addEventListener('click', () => {
      try {
        if (iosInstallModal) {
          iosInstallModal.classList.add('hidden');
        }
        trackInstallEvent('ios_instructions_closed');
        // Mark as shown so we don't spam
        localStorage.setItem('pwa_install_dismissed', 'true');
        hideInstallPrompt();
      } catch(err) {
        console.warn('iosInstallCloseBtn click error', err);
      }
    });
  }
  
  // Check on page load if we should show the prompt
  // For iOS, show prompt even without beforeinstallprompt event
  setTimeout(() => {
    try {
      if (isIOSDevice() && !isAppInstalled()) {
        showInstallPrompt();
      }
    } catch(e) {
      console.warn('iOS install check error:', e);
    }
  }, 2000); // Delay to avoid interfering with initial page load

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

  // Add click handler to show session timer
  const headerStatus = qs('#headerStatus');
  if (headerStatus) {
    headerStatus.addEventListener('click', () => {
      const expiry = localStorage.getItem('sessionExpiry');
      if (!expiry) {
        if (window.smShowSnackbar) {
          window.smShowSnackbar('No active session', { timeout: 3000 });
        }
        return;
      }
      
      const expTime = new Date(expiry).getTime();
      const now = Date.now();
      const remaining = expTime - now;
      
      if (remaining <= 0) {
        if (window.smShowSnackbar) {
          window.smShowSnackbar('Session expired', { timeout: 3000 });
        }
        return;
      }
      
      // Calculate hours and minutes
      const hours = Math.floor(remaining / (1000 * 60 * 60));
      const minutes = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
      
      const timerText = `Session Timer: ${hours}hrs ${minutes}min left`;
      
      if (window.smShowSnackbar) {
        window.smShowSnackbar(timerText, { timeout: 5000 });
      } else {
        alert(timerText);
      }
    });
    
    // Make it look clickable
    headerStatus.style.cursor = 'pointer';
    headerStatus.title = 'Click to view session timer';
  }

  // Reveal auth-only controls that are server-rendered hidden with `.sm-auth-hidden`
  function revealAuthControls(){
    try{
      const nodes = document.querySelectorAll('.sm-auth-hidden');
      nodes.forEach(n => n.classList.remove('sm-auth-hidden'));
    }catch(e){}
    // Drivers see the team money view instead of the sales form.
    try{ applyDriverRoleView(); }catch(e){}
  }

  // Compute total by iterating product qty inputs and using configured prices
  function computeTotal(){
    try{
      let total = 0;
      const inputs = document.querySelectorAll('input[data-product-id]');
      inputs.forEach(inp => {
        try{
          const q = parseInt(inp.value,10) || 0;
          const pid = inp.getAttribute('data-product-id');
          const priceAttr = inp.getAttribute('data-product-price');
          let price = parseFloat(priceAttr || '0');
          // If price not present on input, try to find it from productsConfig
          if ( !price || isNaN(price) ) {
            const found = productsConfig.find(p=>String(p.id) === String(pid));
            price = found ? parseFloat(found.price || 0) : 0;
          }
          total += q * (isNaN(price) ? 0 : price);
        }catch(e){}
      });
      const d = parseFloat(donationAmount && donationAmount.value) || 0;
      total += d;
      if (orderTotalEl) orderTotalEl.textContent = '$' + total.toFixed(2);
      return total;
    }catch(e){ console.warn('computeTotal error', e); return 0; }
  }
  // attach listeners: donation and product inputs will add listeners when rendered
  if (donationAmount) donationAmount.addEventListener('input', computeTotal);

  // Clear the order form inputs after a successful save
  function clearOrderForm(){
    try{
      // helper: aggressively clear inner autocomplete inputs and host text
      const clearHostElement = (n) => {
        try{
          if (!n || !(n instanceof Element)) return;
          // try shadow root first
          try{ if (n.shadowRoot) { const ii = n.shadowRoot.querySelector('input, textarea, [role="combobox"]'); if (ii) { ii.value = ''; try{ ii.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} } } }catch(e){}
          // then try subtree
          try{ if (n.querySelector) { const ii = n.querySelector('input, textarea, [role="combobox"]'); if (ii) { ii.value = ''; try{ ii.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} } } }catch(e){}
          // if the node itself is input-like, clear it
          try{ if (n.tagName && /INPUT|TEXTAREA/i.test(n.tagName)) { n.value = ''; try{ n.dispatchEvent && n.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} } }catch(e){}
          // clear host value/text as well
          try{ if ('value' in n) n.value = ''; }catch(e){}
          try{ if (n.textContent) n.textContent = ''; }catch(e){}
        }catch(e){}
      };

      const fields = ['#customerName','#address','#unitFloorApt','#cellNumber','#notes','#donationAmount','#checkNumber'];
      fields.forEach(s=>{ const el = qs(s); if (!el) return; try{ if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') el.value = ''; }catch(e){} });
      
      // Clear phone field validation state to prevent red border on empty field
      try {
        const cellField = qs('#cellNumber');
        if (cellField) {
          cellField.classList.remove('invalid');
          cellField.setCustomValidity('');
        }
      } catch(e) {}
      // Also clear common injected autocomplete inputs (keep canonical address cleared too)
      try{
        // selectors that may host the visible autocomplete input
        const autoHosts = ['#addressAutocomplete','gmp-place-autocomplete','place-autocomplete','place-autocomplete-element','place-autocomplete','google-places-autocomplete','.pac-target-input','.gm-places-autocomplete','input[role="combobox"]','input[aria-label]','input[placeholder]'];
        // Explicitly try targeted hosts first
        ['#addressAutocomplete','gmp-place-autocomplete'].forEach(sel=>{ try{ const el = document.querySelector(sel); if (el) clearHostElement(el); }catch(e){} });
        const tried = new Set();
        autoHosts.forEach(sel=>{
          try{
            const nodes = Array.from(document.querySelectorAll(sel));
            nodes.forEach(n=>{
              try{
                if (tried.has(n)) return; tried.add(n);
                // find inner input if element wraps one (shadowRoot or subtree)
                // use clearHostElement to aggressively clear inner controls and host text
                try{ clearHostElement(n); }catch(e){}
              }catch(e){}
            });
          }catch(e){}
        });
        // Extra sweep: clear any visible combobox-like inputs that may be left behind
        try{
          const extras = Array.from(document.querySelectorAll('input[role="combobox"], input.pac-target-input, input[aria-label], input[placeholder]'));
          extras.forEach(ex=>{ try{ if (ex && ex.value && /search|address/i.test(ex.placeholder || ex.getAttribute('aria-label') || '')) { ex.value = ''; try{ ex.dispatchEvent(new Event('input',{bubbles:true})); }catch(e){} } }catch(e){} });
        }catch(e){}
      }catch(e){}

      // clear any dynamic product qty inputs
      try{ const prodInputs = document.querySelectorAll('input[data-product-id]'); prodInputs.forEach(i=>{ try{ i.value=''; }catch(e){} }); }catch(e){}
      if (payCheck) payCheck.checked = false; if (payCash) payCash.checked = false; if (payDigital) payDigital.checked = false;
      try{ if (checkNumberRow) checkNumberRow.classList.add('hidden'); }catch(e){}
      try{ hideDigitalPanel(); }catch(e){}
      // reset computed total
      try{ if (orderTotalEl) orderTotalEl.textContent = '$0.00'; }catch(e){}
      // re-run compute to update total state
      try{ computeTotal(); }catch(e){}
      // remove edit-mode UI marker when clearing the form
      try{ document.body.classList.remove('sm-edit-mode'); }catch(e){}
      // release any paid-order lock, or it would carry into the next order
      try{ applyPaidOrderLock(null); }catch(e){}
    }catch(e){ /* silent */ }
  }

  if (payCheck) payCheck.addEventListener('change', ()=>{ if (payCheck.checked) { checkNumberRow && checkNumberRow.classList.remove('hidden'); if (payCash) payCash.checked=false; if (payDigital && payDigital.checked) { payDigital.checked=false; hideDigitalPanel(); } } else { checkNumberRow && checkNumberRow.classList.add('hidden'); } });
  if (payCash) payCash.addEventListener('change', ()=>{ if (payCash.checked) { if (payCheck) { payCheck.checked=false; checkNumberRow && checkNumberRow.classList.add('hidden'); } if (payDigital && payDigital.checked) { payDigital.checked=false; hideDigitalPanel(); } } });
  if (payDigital) payDigital.addEventListener('change', ()=>{
    if (payDigital.checked) {
      if (payCheck) payCheck.checked = false;
      if (payCash) payCash.checked = false;
      checkNumberRow && checkNumberRow.classList.add('hidden');
      showDigitalConfirmPanel();
    } else {
      hideDigitalPanel();
    }
  });

  // ---- Digital payment (Square QR) flow — Phase 5 ----
  const DIGITAL_WAITING_MESSAGES = ['Waiting for buyer...', 'Still waiting...', 'Almost there...'];

  function digitalAuthHeaders(){
    const loginMode = localStorage.getItem('loginMode') || 'legacy';
    const headers = { 'Content-Type': 'application/json' };
    if (loginMode === 'user') {
      headers['X-User-ID'] = localStorage.getItem('userId') || '';
      headers['X-Team-ID'] = localStorage.getItem('selectedTeamId') || '';
    } else {
      headers['X-Team-Name'] = localStorage.getItem('teamName') || '';
      headers['X-Access-Code'] = localStorage.getItem('teamCode') || '';
    }
    return headers;
  }

  function showDigitalConfirmPanel(){
    if (!digitalPaymentPanel) return;
    digitalPaymentPanel.classList.remove('hidden');
    if (digitalConfirmPanel) digitalConfirmPanel.classList.remove('hidden');
    if (digitalQrPanel) digitalQrPanel.classList.add('hidden');
  }

  function stopDigitalPolling(){
    if (digitalPollInterval) { clearInterval(digitalPollInterval); digitalPollInterval = null; }
    if (digitalWaitingMsgInterval) { clearInterval(digitalWaitingMsgInterval); digitalWaitingMsgInterval = null; }
  }

  // Resets the whole digital panel back to its starting (hidden) state. Deliberately does NOT
  // touch customer/address/phone/products/notes — callers that need those cleared too call
  // clearOrderForm() themselves (which itself calls this function).
  function hideDigitalPanel(){
    stopDigitalPolling();
    if (digitalPaymentPanel) digitalPaymentPanel.classList.add('hidden');
    if (digitalConfirmPanel) digitalConfirmPanel.classList.remove('hidden');
    if (digitalQrPanel) digitalQrPanel.classList.add('hidden');
    if (digitalQrImg) digitalQrImg.src = '';
    if (digitalExpiryText) digitalExpiryText.textContent = '';
    digitalCurrentAttemptId = null;
    digitalExpiresAt = null;
    digitalOrderData = null;
  }

  function updateDigitalExpiryText(){
    if (!digitalExpiryText) return;
    if (!digitalExpiresAt) { digitalExpiryText.textContent = ''; return; }
    const msLeft = digitalExpiresAt - Date.now();
    if (msLeft <= 0) { handleDigitalExpired(); return; }
    const secs = Math.floor(msLeft / 1000);
    const mm = Math.floor(secs / 60), ss = secs % 60;
    digitalExpiryText.textContent = 'Expires in ' + mm + ':' + (ss < 10 ? '0' : '') + ss;
  }

  function startDigitalWaitingMessages(){
    let i = 0;
    if (digitalStatusText) digitalStatusText.textContent = DIGITAL_WAITING_MESSAGES[0];
    updateDigitalExpiryText();
    digitalWaitingMsgInterval = setInterval(()=>{
      i = (i + 1) % DIGITAL_WAITING_MESSAGES.length;
      if (digitalStatusText) digitalStatusText.textContent = DIGITAL_WAITING_MESSAGES[i];
      updateDigitalExpiryText();
    }, 3000);
  }

  function handleDigitalExpired(){
    stopDigitalPolling();
    if (payDigital) payDigital.checked = false;
    hideDigitalPanel();
    alert('The QR code expired. Please try again or use Cash/Check.');
  }

  function handleDigitalCancelledOrExpired(status){
    stopDigitalPolling();
    if (payDigital) payDigital.checked = false;
    hideDigitalPanel();
    alert(status === 'expired' ? 'The QR code expired. Please try again or use Cash/Check.' : 'The digital payment was cancelled.');
  }

  // Stable per-device id, generated once and kept in localStorage.
  function deviceTag(){
    let d = null;
    try { d = localStorage.getItem('bkmb_device_tag'); } catch(e){}
    if (!d) {
      d = Math.random().toString(36).slice(2, 8);
      try { localStorage.setItem('bkmb_device_tag', d); } catch(e){}
    }
    return d;
  }

  // Order ids used to be just 'o-' + Date.now(). Two children saving an order in
  // the same millisecond then produced the SAME id - and the server treats a
  // repeat id as an already-synced duplicate, returns 200, and the app deletes
  // its local copy. The second child's sale vanished silently. Real orders have
  // landed as little as 3ms apart, so this was luck rather than safety.
  // The device tag makes two phones unable to collide at all; the random suffix
  // covers two orders from the same phone in one millisecond.
  function newOrderId(){
    return 'o-' + Date.now() + '-' + deviceTag() + '-' + Math.floor(Math.random() * 10000);
  }

  // Builds the exact same order object the cash/check Save path builds (same id pattern, same
  // full field set), for a NEW order. Shared by the Save-button handler and the digital "paid"
  // handler so there is exactly one place that constructs a new order object.
  // Lock the "what was bought" fields when an order already carries a captured
  // digital payment. Cash and check orders are untouched - nothing has been
  // charged, so a seller correcting them costs nobody anything.
  function applyPaidOrderLock(orderObj){
    const paid = orderObj && orderObj.paymentMethod === 'digital' && !!(orderObj.digitalAttemptId || orderObj.digital_attempt_id);
    const note = qs('#paidOrderLockNote');
    const lockable = []
      .concat(Array.from(document.querySelectorAll('input[data-product-id]')))
      .concat([qs('#donationAmount'), qs('#checkNumber')].filter(Boolean));

    lockable.forEach(function(el){
      el.readOnly = paid;
      el.disabled = paid;
      el.style.backgroundColor = paid ? '#f5f5f5' : '';
      el.style.cursor = paid ? 'not-allowed' : '';
    });

    if (note) { note.style.display = paid ? '' : 'none'; }
  }

  async function buildNewOrderObject(paymentMethod, data, extra){
    extra = extra || {};
    const pos = await getCurrentPositionPromise(5000);
    const geo = pos && pos.coords ? { latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: pos.coords.accuracy } : null;

    const loginMode = localStorage.getItem('loginMode') || 'legacy';
    const authCreds = {};
    if (loginMode === 'user') {
      authCreds._sync_user_id = localStorage.getItem('userId') || '';
      authCreds._sync_team_id = localStorage.getItem('selectedTeamId') || '';
    } else {
      authCreds._sync_team_name = localStorage.getItem('teamName') || '';
      authCreds._sync_team_code = localStorage.getItem('teamCode') || '';
    }

    return {
      id: newOrderId(),
      customer: data.customer,
      address: data.address,
      cellNumber: data.cell,
      products: data.products,
      price_snapshot: data.priceSnapshot,
      donationAmount: data.donation,
      donationOnly: !!data.donationOnly,
      paymentMethod,
      checkNumber: data.chkNumber || '',
      notes: data.notes,
      createdAt: new Date().toISOString(),
      subsales_user_id: data.subsalesUserId,
      subsales_team_id: data.subsalesTeamId,
      entered_by_id: data.enteredById,
      entered_by_name: data.enteredByName,
      teamName: data.teamName,
      teamCode: data.teamCode,
      geo,
      ...authCreds,
      ...extra
    };
  }

  async function handleDigitalPaid(){
    stopDigitalPolling();
    const orderData = digitalOrderData;
    const attemptId = digitalCurrentAttemptId;
    try{
      if (!orderData) { console.warn('digital payment marked paid but no captured order data was found'); return; }
      const order = await buildNewOrderObject('digital', orderData, { digitalAttemptId: attemptId });
      await Storage.add(order);
      renderOrders();
      showOrderConfirmation(order, false);
      if (navigator.onLine) {
        trySync().catch(()=>{});
      }
    }catch(e){
      console.warn('error finalizing digital order after payment', e);
      alert('Payment was received, but saving the order failed: ' + (e.message || e));
    }finally{
      if (payDigital) payDigital.checked = false;
      hideDigitalPanel();
    }
  }

  async function pollDigitalStatus(){
    if (!digitalCurrentAttemptId) { stopDigitalPolling(); return; }
    // Client-side belt-and-suspenders: react to expiry even if the server hasn't confirmed it yet.
    if (digitalExpiresAt && Date.now() > digitalExpiresAt) { handleDigitalExpired(); return; }
    try{
      const url = apiBase ? (apiBase + '/digital-payments/status/' + encodeURIComponent(digitalCurrentAttemptId)) : ('/wp-json/order-manager/v1/digital-payments/status/' + encodeURIComponent(digitalCurrentAttemptId));
      const resp = await fetch(url, { headers: digitalAuthHeaders() });
      if (!resp.ok) { console.warn('digital payment status poll got non-OK response', resp.status); return; }
      const data = await resp.json().catch(()=>null);
      const status = data && data.status;
      if (status === 'paid') { await handleDigitalPaid(); }
      else if (status === 'cancelled_by_seller' || status === 'expired') { handleDigitalCancelledOrExpired(status); }
      // status === 'initiated' (or anything else): keep polling, keep cycling the waiting message.
    }catch(e){
      // A single transient poll failure is not an emergency — same "don't panic on one blip"
      // judgment as the session-tracking heartbeat. Log and let the next tick retry.
      console.warn('digital payment status poll failed, will retry', e);
    }
  }

  function startDigitalPolling(){
    stopDigitalPolling();
    digitalPollInterval = setInterval(pollDigitalStatus, 3000);
  }

  digitalBackBtn1 && digitalBackBtn1.addEventListener('click', ()=>{
    if (payDigital) payDigital.checked = false;
    hideDigitalPanel();
  });

  digitalCancelBtn && digitalCancelBtn.addEventListener('click', ()=>{
    const attemptId = digitalCurrentAttemptId;
    if (attemptId) {
      const url = apiBase ? (apiBase + '/digital-payments/cancel/' + encodeURIComponent(attemptId)) : ('/wp-json/order-manager/v1/digital-payments/cancel/' + encodeURIComponent(attemptId));
      // Fire-and-forget: don't block the UI on the cancel response.
      fetch(url, { method: 'POST', headers: digitalAuthHeaders() }).catch(e => console.warn('digital payment cancel request failed', e));
    }
    if (payDigital) payDigital.checked = false;
    hideDigitalPanel();
  });

  digitalReadyBtn && digitalReadyBtn.addEventListener('click', async ()=>{
    if (digitalReadyBtn.disabled) return;
    digitalReadyBtn.disabled = true;
    try{
      // Same validation the Save button runs for buyer info (see saveOrderBtn handler below) —
      // NOTE: the current Save-button validation only requires customer+address+phone; there is
      // no existing "at least one product/donation" check to mirror, so none is added here
      // (a $0 checkout attempt will simply fail server-side and surface as the generic error below).
      const customer = qs('#customerName') && qs('#customerName').value.trim();
      let address = '';
      try{ address = (qs('#address') && qs('#address').value && qs('#address').value.trim()) || ''; }catch(e){ address = ''; }
      const unitFloorApt = qs('#unitFloorApt') && qs('#unitFloorApt').value.trim();
      if (unitFloorApt) { address = address + (address ? ' ' : '') + unitFloorApt; }
      const cell = qs('#cellNumber') && qs('#cellNumber').value.trim();

      if (!customer || !address) { alert('Customer and address required'); return; }
      if (!cell) { alert('Phone number is required'); return; }

      const notes = qs('#notes') && qs('#notes').value || '';
      const prodInputs = document.querySelectorAll('input[data-product-id]');
      const products = [];
      prodInputs.forEach(pi => {
        try{
          const pid = pi.getAttribute('data-product-id');
          const qty = parseInt(pi.value, 10) || 0;
          const price = parseFloat(pi.getAttribute('data-product-price') || ((productsConfig.find(p=>String(p.id)===String(pid))||{}).price)) || 0;
          if (qty > 0) products.push({ id: pid, name: (productsConfig.find(p=>String(p.id)===String(pid))||{}).name || pid, qty: qty, price: Number(price.toFixed(2)) });
        }catch(e){}
      });
      const donation = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0;
      const priceSnapshot = {};
      if (productsConfig && Array.isArray(productsConfig)) { productsConfig.forEach(p => { priceSnapshot[p.id] = p.price; }); }

      const salesMode = localStorage.getItem('salesMode') || localStorage.getItem('detectedSalesMode') || 'legacy';
      let subsalesUserId, subsalesTeamId, enteredById, enteredByName, teamName, teamCode;
      if (salesMode === 'user') {
        subsalesUserId = localStorage.getItem('userId') || '';
        subsalesTeamId = localStorage.getItem('selectedTeamId') || '-1';
        enteredById = subsalesUserId;
        enteredByName = localStorage.getItem('userName') || '';
      } else {
        enteredById = localStorage.getItem('teamMemberId') || '';
        enteredByName = localStorage.getItem('teamMemberName') || '';
        teamName = localStorage.getItem('teamName') || '';
        teamCode = localStorage.getItem('teamCode') || '';
      }

      // Snapshot now — this is what gets used to build the real order later, at "paid" time,
      // not whatever the DOM happens to hold then.
      digitalOrderData = { customer, address, cell, notes, products, donation, priceSnapshot, subsalesUserId, subsalesTeamId, enteredById, enteredByName, teamName, teamCode };

      const url = apiBase ? (apiBase + '/digital-payments/checkout') : '/wp-json/order-manager/v1/digital-payments/checkout';
      const resp = await fetch(url, {
        method: 'POST',
        headers: digitalAuthHeaders(),
        body: JSON.stringify({ customer, address, cellNumber: cell, products, notes, donationAmount: donation, price_snapshot: priceSnapshot })
      });

      if (!resp.ok) {
        let msg = 'Could not start the digital payment. Please try again or use Cash/Check.';
        try{ const j = await resp.json(); if (j && j.message) msg = j.message; }catch(e){}
        alert(msg);
        return;
      }

      const data = await resp.json();
      digitalCurrentAttemptId = data.attempt_id;
      digitalExpiresAt = data.expires_at ? new Date(data.expires_at).getTime() : null;

      if (digitalConfirmPanel) digitalConfirmPanel.classList.add('hidden');
      if (digitalQrPanel) digitalQrPanel.classList.remove('hidden');
      if (digitalQrImg) digitalQrImg.src = data.qr_code_data_uri || '';

      startDigitalWaitingMessages();
      startDigitalPolling();
    }catch(e){
      console.warn('digital payment checkout request failed', e);
      alert('Could not start the digital payment. Please try again or use Cash/Check.');
    }finally{
      digitalReadyBtn.disabled = false;
    }
  });

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
      if (j) {
        // store google maps key if present
        if (j.google_maps_api_key) {
          try{ localStorage.setItem('bkmb_google_maps_key', j.google_maps_api_key); }catch(e){}
        }
        // update products config and re-render products if needed
        try{
          if (j.products && Array.isArray(j.products)) {
            productsConfig.length = 0; // clear
            j.products.forEach(p => productsConfig.push(p));
            try{ renderProducts(); }catch(e){}
          }
        }catch(e){}
        if (typeof j.salesEnabled !== 'undefined') {
          if (j.salesEnabled) {
            hideSalesClosed();
          } else {
            showSalesClosed(j.salesClosedMessage || 'Sales are closed right now. Please check back later.');
          }
        }
        return j;
      }
      return null;
    }catch(e){ console.warn('fetchAppConfig failed', e); return null; }
  }
  // session duration will be provided by server-localized config as milliseconds (cfg.sessionDuration)

  function showSalesClosed(reason){
    salesEnabled = false;
    const msg = reason || 'Sales are closed right now. Please check back later.';
    if (salesClosedMessage) salesClosedMessage.textContent = msg;
    if (loginSection) { loginSection.classList.add('hidden'); try{ loginSection.style.display='none'; }catch(e){} }
    if (appSection) { appSection.classList.add('hidden'); try{ appSection.style.display='none'; }catch(e){} }
    if (salesClosedSection) { salesClosedSection.classList.remove('hidden'); try{ salesClosedSection.style.display='block'; }catch(e){} }
    try { localStorage.setItem('salesEnabled', '0'); localStorage.setItem('salesClosedMessage', msg); } catch(e){}
  }

  function hideSalesClosed(){
    salesEnabled = true;
    if (salesClosedSection) { salesClosedSection.classList.add('hidden'); try{ salesClosedSection.style.display='none'; }catch(e){} }
    try { localStorage.setItem('salesEnabled', '1'); } catch(e){}
    if (loginSection) { loginSection.classList.remove('hidden'); try{ loginSection.style.display='block'; }catch(e){} }
  }

  const cachedSalesFlag = localStorage.getItem('salesEnabled');
  if (cachedSalesFlag === '0') {
    showSalesClosed(localStorage.getItem('salesClosedMessage') || 'Sales are closed right now. Please check back later.');
  }

  async function refreshSalesStatus(){
    try{
      const url = apiBase ? (apiBase + '/config') : '/wp-json/order-manager/v1/config';
      const resp = await fetch(url);
      if (!resp.ok) return;
      const cfgResp = await resp.json().catch(()=>null);
      if (!cfgResp) return;
      if (typeof cfgResp.salesEnabled !== 'undefined') {
        if (cfgResp.salesEnabled) {
          hideSalesClosed();
        } else {
          showSalesClosed(cfgResp.salesClosedMessage || 'Sales are closed right now. Please check back later.');
        }
      }
    }catch(e){ console.warn('refreshSalesStatus failed', e); }
  }

  if (salesClosedRetry) {
    salesClosedRetry.addEventListener('click', ()=>{ refreshSalesStatus(); });
  }

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
      // Start PWA session tracking
      if (window.PWASessionTracking) {
        window.PWASessionTracking.start({
          userId: localStorage.getItem('teamMemberId'),
          userName: localStorage.getItem('teamMemberName'),
          teamId: localStorage.getItem('teamId'),
          teamName: localStorage.getItem('teamName')
        });
        window.PWASessionTracking.startAutoHeartbeat();
      }
      // Show app
  if (loginSection) { loginSection.classList.add('hidden'); try{ loginSection.style.display='none'; }catch(e){} }
  if (appSection) { appSection.classList.remove('hidden'); try{ appSection.style.display='block'; }catch(e){} }
  // reveal any server-side auth-only controls
  revealAuthControls();
  
  // Load address autocomplete module and prefetch (background - non-blocking)

  loadAddressAutocomplete().then(() => {

    if (window.subsalesNearby && typeof window.subsalesNearby.prefetch === 'function') {
      return window.subsalesNearby.prefetch();
    }
  }).then(() => {

  }).catch(e => {
    console.warn('[Team Member] Module load/prefetch failed:', e);
  });
  
  trySync();
    });
  }

  // ========== NEW USER LOGIN FUNCTIONS ==========
  
  // Debounce helper
  let userSearchTimeout;
  function debounceUserSearch(fn, delay) {
    return function(...args) {
      clearTimeout(userSearchTimeout);
      userSearchTimeout = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  // Search users by name for autocomplete
  async function searchUsers(query) {
    if (!query || query.length < 2) return [];
    try {
      const url = apiBase ? (apiBase + '/users/search') : '/wp-json/order-manager/v1/users/search';
      const resp = await fetch(url + '?q=' + encodeURIComponent(query));
      if (!resp.ok) return [];
      const users = await resp.json();
      return users || [];
    } catch(e) {
      console.warn('User search failed', e);
      return [];
    }
  }

  // User name input - autocomplete with custom dropdown

  const userNameInput = qs('#userName');
  const userNameSuggestions = qs('#userNameSuggestions');
  let searchTimeout;
  
  async function searchUsers(query) {
    if (!query || query.length < 2) return [];
    try {
      const url = apiBase ? (apiBase + '/users/search?q=' + encodeURIComponent(query)) : '/wp-json/order-manager/v1/users/search?q=' + encodeURIComponent(query);
      const resp = await fetch(url);
      if (!resp.ok) return [];
      const data = await resp.json();
      return Array.isArray(data) ? data : [];
    } catch(e) {
      console.warn('User search error:', e);
      return [];
    }
  }
  
  if (userNameInput && userNameSuggestions) {
    userNameInput.addEventListener('input', async function() {
      clearTimeout(searchTimeout);
      const query = userNameInput.value.trim();
      
      if (query.length < 2) {
        userNameSuggestions.innerHTML = '';
        userNameSuggestions.classList.add('hidden');
        return;
      }
      
      searchTimeout = setTimeout(async () => {
        const users = await searchUsers(query);
        if (users.length === 0) {
          userNameSuggestions.innerHTML = '';
          userNameSuggestions.classList.add('hidden');
          return;
        }
        
        userNameSuggestions.innerHTML = users.map(u => 
          `<div class="sm-suggestion-item" data-name="${escapeHtml(u.name)}">${escapeHtml(u.name)}</div>`
        ).join('');
        userNameSuggestions.classList.remove('hidden');
        
        // Add click handlers to suggestions
        userNameSuggestions.querySelectorAll('.sm-suggestion-item').forEach(item => {
          item.addEventListener('click', function() {
            const name = item.getAttribute('data-name');
            userNameInput.value = name;
            userNameSuggestions.innerHTML = '';
            userNameSuggestions.classList.add('hidden');
          });
        });
      }, 300);
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
      if (!userNameInput.contains(e.target) && !userNameSuggestions.contains(e.target)) {
        userNameSuggestions.classList.add('hidden');
      }
    });
  }
  
  // (a second escapeHtml() lived here; it used div.textContent and so did NOT
  //  escape quotes, yet silently overrode the regex version above for the whole
  //  file - including the attribute-context callers. Removed; use the one above.)

  // User login button handler
  const userLoginBtn = qs('#userLoginBtn');
  if (userLoginBtn) {
    userLoginBtn.addEventListener('click', async () => {

      // Log login attempt
      if (window.PWALogger) {
        window.PWALogger.log('auth', 'User login button clicked', {
          login_mode: 'user'
        });
      }
      
      const name = (qs('#userName') && qs('#userName').value.trim()) || '';
      const phone = (qs('#userPhone') && qs('#userPhone').value.trim()) || '';

      
      if (!name || !phone) {
        console.warn('Name or phone missing');
        return alert('Name and phone number are required');
      }
      
      // Validate phone (10 digits)
      const phoneDigits = phone.replace(/\D/g, '');
      if (phoneDigits.length !== 10) {
        console.warn('Invalid phone length:', phoneDigits.length);
        return alert('Phone number must be exactly 10 digits');
      }
      
      try {

        // Call server to validate user
        const url = apiBase ? (apiBase + '/auth/login') : '/wp-json/order-manager/v1/auth/login';
        const resp = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, phone: phoneDigits })
        });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          console.error('Login failed:', err);
          return alert(err.message || 'Login failed. Check your name and phone number.');
        }
        
        const data = await resp.json();


        // Store user session data
        try { localStorage.setItem('userId', data.user.id); } catch(e){}
        try { localStorage.setItem('userName', data.user.name); } catch(e){}
        // Role drives which screen we show after auth: 'driver' -> money view.
        try { localStorage.setItem('userRole', (data.user && data.user.role) ? data.user.role : 'member'); } catch(e){}
        try { localStorage.setItem('userPhone', phoneDigits); } catch(e){}
        
        // Store team member authentication credentials for PUT requests
        try { localStorage.setItem('memberEmail', data.user.email || ''); } catch(e){}
        try { localStorage.setItem('memberCode', data.user.phone || phoneDigits); } catch(e){}
        
        // Store user teams for potential switching
        if (data.teams && data.teams.length > 0) {
          try { localStorage.setItem('userTeams', JSON.stringify(data.teams)); } catch(e){}
        }
        
        // Get salesMode to determine team vs individual behavior
        const salesMode = localStorage.getItem('salesMode') || 'legacy';


        // Handle team selection based on salesMode
        if (salesMode === 'legacy') {
          // Team mode: Show team selector, NO individual option
          if (data.teams && data.teams.length > 0) {
            const teamSelect = qs('#userTeamSelect');
            const teamSelectRow = qs('#teamSelectRow');
            const individualRow = qs('#individualSalesRow');
            
            if (teamSelect && teamSelectRow) {
              teamSelect.innerHTML = '<option value="">-- Select team --</option>' +
                data.teams.map(t => `<option value="${t.id}">${t.name}</option>`).join('');
              teamSelectRow.classList.remove('hidden');
              
              // IMPORTANT: Hide individual sales option in team mode
              if (individualRow) individualRow.classList.add('hidden');
              
              // Wait for team selection
              return;
            }
          }
          
          // No teams in team mode - shouldn't happen, but handle gracefully
          alert('No teams found. Please contact administrator.');
          return;
          
        } else {
          // Individual mode: Auto-set to individual, no team selector
          const individualCheckbox = qs('#individualSales');
          if (individualCheckbox) {
            individualCheckbox.checked = true;
          }
          
          try { localStorage.setItem('selectedTeamId', '-1'); } catch(e){}
          try { localStorage.setItem('selectedTeamName', 'Individual'); } catch(e){}
        }
        
        // Session duration
        try {
          const sd = data.sessionDuration || cfg.sessionDuration || '86400000';
          const durMs = parseInt(sd, 10) || 86400000;
          const expiryMs = Date.now() + durMs;
          const expiryISO = new Date(expiryMs).toISOString();
          localStorage.setItem('sessionExpiry', expiryISO);
          localStorage.setItem('sessionDuration', String(durMs));
        } catch(e){}
        
        // Fetch config
        await fetchAppConfigUserMode();
        
        // Start PWA session tracking
        if (window.PWASessionTracking) {
          window.PWASessionTracking.start({
            userId: localStorage.getItem('userId'),
            userName: localStorage.getItem('userName'),
            teamId: localStorage.getItem('selectedTeamId'),
            teamName: localStorage.getItem('selectedTeamName')
          });
          window.PWASessionTracking.startAutoHeartbeat();
        }
        
        // Re-initialize PWA Logger with authenticated credentials
        if (window.PWALogger) {

          try {
            await window.PWALogger.init({
              apiBase: apiBase,
              teamName: localStorage.getItem('selectedTeamName') || '',
              userName: localStorage.getItem('userName') || '',
              sessionId: localStorage.getItem('pwaSessionId') || ''
            });
            
            // Enable auto-instrumentation
            window.PWALogger.instrumentUI();
            
            // Log successful login
            window.PWALogger.log('auth', 'User login successful', {
              user_id: localStorage.getItem('userId'),
              user_name: localStorage.getItem('userName'),
              team: localStorage.getItem('selectedTeamName'),
              login_mode: 'user'
            });
          } catch(e) {
            console.warn('Failed to re-initialize PWA Logger after login', e);
          }
        }
        
        // Show app FIRST - don't wait for module/prefetch
        if (loginSection) { loginSection.classList.add('hidden'); loginSection.style.display='none'; }
        if (appSection) { appSection.classList.remove('hidden'); appSection.style.display='block'; }
        
        // Request GPS permission at login (non-blocking)
        if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(
            (pos) => {
              if (window.PWALogger) {
                window.PWALogger.log('gps', 'GPS permission granted at login', {
                  lat: pos.coords.latitude,
                  lng: pos.coords.longitude
                });
              }
            },
            (error) => {
              if (window.PWALogger) {
                window.PWALogger.log('gps', 'GPS permission denied at login', {
                  error_code: error.code,
                  error_message: error.message
                });
              }
            },
            { enableHighAccuracy: true, timeout: 5000 }
          );
        }
        
        // Load address autocomplete module and prefetch ZIP data (non-blocking - happens in background)
        loadAddressAutocomplete().then(() => {

          if (window.subsalesNearby && typeof window.subsalesNearby.prefetch === 'function') {
            return window.subsalesNearby.prefetch();
          }
        }).then(() => {

        }).catch(e => {
          console.warn('[Login] Address autocomplete load/prefetch failed:', e);
        });
        
        revealAuthControls();
        if (deferredPrompt) showInstallPrompt();
        
      } catch(err) {
        console.error('User login error:', err);
        alert('Login failed. Please try again.');
      }
    });
  }

  // Handle team selection for user mode
  const userTeamSelect = qs('#userTeamSelect');
  const individualSalesCheckbox = qs('#individualSales');
  
  if (userTeamSelect) {
    userTeamSelect.addEventListener('change', function() {
      if (this.value && individualSalesCheckbox) {
        individualSalesCheckbox.checked = false;
        individualSalesCheckbox.disabled = true;
      } else if (individualSalesCheckbox) {
        individualSalesCheckbox.disabled = false;
      }
      
      // If a team is selected, enable second login
      if (this.value) {
        const selectedOption = this.options[this.selectedIndex];
        try { localStorage.setItem('selectedTeamId', this.value); } catch(e){}
        try { localStorage.setItem('selectedTeamName', selectedOption.text); } catch(e){}
      }
    });
  }
  
  if (individualSalesCheckbox) {
    individualSalesCheckbox.addEventListener('change', function() {
      if (this.checked && userTeamSelect) {
        userTeamSelect.value = '';
        userTeamSelect.disabled = true;
        // Set individual sales mode
        try { localStorage.setItem('selectedTeamId', '-1'); } catch(e){}
        try { localStorage.setItem('selectedTeamName', 'Individual'); } catch(e){}
        
        // Proceed to app
        proceedToAppFromUserLogin();
      } else if (userTeamSelect) {
        userTeamSelect.disabled = false;
      }
    });
  }
  
  // Add event listener for when team is selected
  if (userTeamSelect) {
    const origHandler = userTeamSelect.onchange;
    userTeamSelect.addEventListener('change', function() {
      if (this.value) {
        // Team selected, proceed to app
        proceedToAppFromUserLogin();
      }
    });
  }
  
  // Helper to proceed to app from user login
  async function proceedToAppFromUserLogin() {
    try {
      // Use appropriate session duration based on mode
      const isIndividual = localStorage.getItem('selectedTeamId') === '-1';
      const sd = isIndividual ? 
        (cfg.individualSessionDuration || '1209600000') : // 14 days for individual
        (cfg.sessionDuration || '86400000'); // 24 hours for team
      const durMs = parseInt(sd, 10) || 86400000;
      const expiryMs = Date.now() + durMs;
      const expiryISO = new Date(expiryMs).toISOString();
      localStorage.setItem('sessionExpiry', expiryISO);
      
      // Fetch config
      await fetchAppConfigUserMode();
      
      // Start PWA session tracking
      if (window.PWASessionTracking) {
        window.PWASessionTracking.start({
          userId: localStorage.getItem('userId'),
          userName: localStorage.getItem('userName'),
          teamId: localStorage.getItem('selectedTeamId'),
          teamName: localStorage.getItem('selectedTeamName')
        });
        window.PWASessionTracking.startAutoHeartbeat();
      }
      
      // Show app FIRST - don't wait for prefetch
      if (loginSection) { loginSection.classList.add('hidden'); loginSection.style.display='none'; }
      if (appSection) { appSection.classList.remove('hidden'); appSection.style.display='block'; }
      revealAuthControls();
      updateCurrentTeamDisplay();
      
      // Load address autocomplete module and prefetch (non-blocking - happens in background)

      loadAddressAutocomplete().then(() => {

        if (window.subsalesNearby && typeof window.subsalesNearby.prefetch === 'function') {
          return window.subsalesNearby.prefetch();
        }
      }).then(() => {

      }).catch(e => {
        console.warn('[Team Selection] Module load/prefetch failed:', e);
      });
      
      trySync();
    } catch(e) {
      console.error('Failed to proceed to app', e);
    }
  }
  
  // Update current team display in header
  function updateCurrentTeamDisplay() {
    const loginMode = localStorage.getItem('loginMode') || 'legacy';
    const currentTeamDisplay = qs('#currentTeamDisplay');
    const currentTeamName = qs('#currentTeamName');
    
    if (!currentTeamDisplay || !currentTeamName) return;
    
    if (loginMode === 'user') {
      const selectedTeamName = localStorage.getItem('selectedTeamName') || 'Team';
      currentTeamName.textContent = selectedTeamName;
      currentTeamDisplay.classList.remove('hidden');
    } else {
      // Legacy mode - show team name
      const teamName = localStorage.getItem('teamName');
      if (teamName) {
        currentTeamName.textContent = teamName;
        currentTeamDisplay.classList.remove('hidden');
      }
    }
  }

  // Fetch config in user mode
  async function fetchAppConfigUserMode() {
    try {
      const url = apiBase ? (apiBase + '/config') : '/wp-json/order-manager/v1/config';
      const userId = localStorage.getItem('userId') || '';
      const userPhone = localStorage.getItem('userPhone') || '';
      
      const resp = await fetch(url, {
        headers: {
          'X-User-Id': userId,
          'X-User-Phone': userPhone
        }
      });
      
      if (!resp.ok) return null;
      const j = await resp.json().catch(() => null);
      
      if (j) {
        // Store google maps key
        if (j.google_maps_api_key) {
          localStorage.setItem('bkmb_google_maps_key', j.google_maps_api_key);
        }
        // Update products
        if (j.products && Array.isArray(j.products)) {
          productsConfig.length = 0;
          j.products.forEach(p => productsConfig.push(p));
          try { renderProducts(); } catch(e){}
        }
        if (typeof j.salesEnabled !== 'undefined') {
          if (j.salesEnabled) {
            hideSalesClosed();
          } else {
            showSalesClosed(j.salesClosedMessage || 'Sales are closed right now. Please check back later.');
          }
        }
        return j;
      }
      return null;
    } catch(e) {
      console.warn('fetchAppConfigUserMode failed', e);
      return null;
    }
  }

  // ========== END USER LOGIN FUNCTIONS ==========

  loginBtn && loginBtn.addEventListener('click', async ()=>{

    // Log login attempt
    if (window.PWALogger) {
      window.PWALogger.log('auth', 'Legacy login button clicked', {
        login_mode: 'legacy'
      });
    }
    
    const team = (qs('#teamName') && qs('#teamName').value.trim()) || '';
    const code = (qs('#teamCode') && qs('#teamCode').value.trim()) || '';

    
    if (!team||!code) {
      console.warn('Team or code missing');
      return alert('Team and code required');
    }
    
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
        const expiryISO = new Date(expiryMs).toISOString();
        try { localStorage.setItem('sessionExpiry', expiryISO); } catch(e){}
        try { localStorage.setItem('sessionDuration', String(durMs)); } catch(e){}
      }catch(e){}

      // fetch config (google maps key etc.)
      await fetchAppConfig();

      // Start PWA session tracking for legacy team login
      if (window.PWASessionTracking) {
        window.PWASessionTracking.start({
          userId: null,
          userName: team,
          teamId: localStorage.getItem('teamId'),
          teamName: team
        });
        window.PWASessionTracking.startAutoHeartbeat();
      }
      
      // Update PWA Logger credentials now that we're logged in
      if (window.PWALogger) {
        window.PWALogger.updateCredentials(
          team || '',
          team || '',
          localStorage.getItem('pwaSessionId') || ''
        );
        
        // Log successful legacy login
        window.PWALogger.log('auth', 'Legacy login successful', {
          team_name: team,
          login_mode: 'legacy'
        });
      }

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
      
      // Request GPS permission at login (non-blocking)
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          (pos) => {
            if (window.PWALogger) {
              window.PWALogger.log('gps', 'GPS permission granted at login (legacy)', {
                lat: pos.coords.latitude,
                lng: pos.coords.longitude
              });
            }
          },
          (error) => {
            if (window.PWALogger) {
              window.PWALogger.log('gps', 'GPS permission denied at login (legacy)', {
                error_code: error.code,
                error_message: error.message
              });
            }
          },
          { enableHighAccuracy: true, timeout: 5000 }
        );
      }
      
      // reveal any server-side auth-only controls
      revealAuthControls();
      trySync();
    }catch(err){ alert('Login failed. Check team name and code.'); }
  });

  // On boot, detect sales mode and show appropriate login form
  (async function initLoginMode() {
    try {
      // Fetch config to determine login mode AND sales mode
      const url = apiBase ? (apiBase + '/config') : '/wp-json/order-manager/v1/config';
      const resp = await fetch(url).catch(() => null);
      const config = resp && resp.ok ? await resp.json().catch(() => null) : null;
      
      const loginMode = (config && config.loginMode) || 'legacy';
      const salesMode = (config && config.salesMode) || 'legacy';

      // Initialize PWA Logger early (before login) so we can log login attempts
      if (window.PWALogger) {
        try {
          await window.PWALogger.init({
            apiBase: apiBase,
            teamName: '', // Will be updated after login
            userName: '', // Will be updated after login
            sessionId: '' // Will be updated after login
          });
          
          // Enable auto-instrumentation for button clicks and input changes
          window.PWALogger.instrumentUI();
        } catch(e) {
          console.warn('Failed to initialize PWA Logger', e);
        }
      }
      
      const legacyLogin = qs('#legacyLogin');
      const userLogin = qs('#userLogin');
      
      // Store both modes for use during order submission
      if (config) {
        try { localStorage.setItem('loginMode', loginMode); } catch(e){}
        try { localStorage.setItem('salesMode', salesMode); } catch(e){}
        if (config.sessionDuration) {
          try { localStorage.setItem('sessionDuration', config.sessionDuration); } catch(e){}
        }
      }
      
      // Determine which login UI to show based on loginMode
      // salesMode will be used later during order submission
      if (loginMode === 'user' && userLogin && legacyLogin) {
        // User login mode (name + phone)
        legacyLogin.classList.add('hidden');
        userLogin.classList.remove('hidden');
      } else if (legacyLogin && userLogin) {
        // Legacy login mode (team + code)
        legacyLogin.classList.remove('hidden');
        userLogin.classList.add('hidden');
      }
    } catch(e) {
      console.warn('Failed to detect login mode', e);
      // Default to legacy
      const legacyLogin = qs('#legacyLogin');
      const userLogin = qs('#userLogin');
      if (legacyLogin) legacyLogin.classList.remove('hidden');
      if (userLogin) userLogin.classList.add('hidden');
    }
  })();

  // On boot, auto-restore session if not expired (wait for DOM to be ready)
  (function restoreSession(){
    const doRestore = () => {

      try{
        const loginMode = localStorage.getItem('loginMode') || 'legacy';

      if (loginMode === 'user') {
        // User mode session restore
        const userId = localStorage.getItem('userId');
        const userName = localStorage.getItem('userName');
        const userPhone = localStorage.getItem('userPhone');
        const expiry = localStorage.getItem('sessionExpiry');

        if (userId && userName && userPhone && expiry) {
          const expTime = new Date(expiry).getTime();
          if (expTime && expTime > Date.now()) {

            // valid user session
            if (loginSection) { loginSection.classList.add('hidden'); loginSection.style.display='none'; }
            if (appSection) { appSection.classList.remove('hidden'); appSection.style.display='block'; }
            revealAuthControls();
            updateCurrentTeamDisplay();
            fetchAppConfigUserMode().catch(()=>{});
            
            // Load address autocomplete module and prefetch ZIP data (non-blocking)

            loadAddressAutocomplete().then(() => {

              if (window.subsalesNearby && typeof window.subsalesNearby.prefetch === 'function') {
                return window.subsalesNearby.prefetch();
              }
            }).then(() => {

            }).catch(e => {
              console.warn('[Session Restore] Module load/prefetch failed:', e);
            });
            
            trySync();
            return;
          } else {

            // expired
            try { localStorage.removeItem('sessionExpiry'); } catch(e){}
          }
        }
      } else {
        // Legacy mode session restore
        const team = localStorage.getItem('teamName');
        const code = localStorage.getItem('teamCode');
        const expiry = localStorage.getItem('sessionExpiry');

        if (team && code && expiry) {
          const expTime = new Date(expiry).getTime();
          if (expTime && expTime > Date.now()) {

            // valid session
            if (loginSection) { loginSection.classList.add('hidden'); loginSection.style.display='none'; }
            if (appSection) { appSection.classList.remove('hidden'); appSection.style.display='block'; }
            revealAuthControls();
            updateCurrentTeamDisplay();
            fetchAppConfig().catch(()=>{});
            fetchTeamMembers().then(members=>{ if (members && members.length) populateTeamMemberSelect(members); }).catch(()=>{});
            
            // Load address autocomplete module and prefetch ZIP data (non-blocking)

            loadAddressAutocomplete().then(() => {

              if (window.subsalesNearby && typeof window.subsalesNearby.prefetch === 'function') {
                return window.subsalesNearby.prefetch();
              }
            }).then(() => {

            }).catch(e => {
              console.warn('[Session Restore] Module load/prefetch failed:', e);
            });
            
            trySync();
            return;
          } else {

            // expired
            try { localStorage.removeItem('sessionExpiry'); } catch(e){}
          }
        }
      }

    }catch(e){
      console.error('[Session Restore] Failed:', e);
    }
    };
    
    // Wait for DOM to be ready before manipulating UI
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', doRestore);
    } else {
      // DOM already ready, run immediately
      doRestore();
    }
  })();

  // Runtime session expiry monitor - check every 30 seconds
  setInterval(() => {
    try {
      const expiry = localStorage.getItem('sessionExpiry');
      if (!expiry) return; // No session to check
      
      const expTime = new Date(expiry).getTime();
      if (expTime && expTime < Date.now()) {
        // Session has expired - perform logout

        // Clear session data
        const keysToRemove = ['sessionExpiry', 'userId', 'userName', 'userPhone', 
                             'selectedTeamId', 'selectedTeamName', 'userTeams',
                             'teamName', 'teamCode', 'loginMode', 'salesMode'];
        keysToRemove.forEach(key => {
          try { localStorage.removeItem(key); } catch(e) {}
        });
        
        // Reload to show login screen
        location.reload();
      }
    } catch(e) {
      console.warn('Session check failed', e);
    }
  }, 30000); // Check every 30 seconds

  // Helper function to check session validity before actions
  // allowOrderEntry: if true, allows order entry to continue even if session expired (UX exception)
  function checkSessionValid(allowOrderEntry = false) {
    try {
      const expiry = localStorage.getItem('sessionExpiry');
      if (!expiry) return false; // No session
      
      const expTime = new Date(expiry).getTime();
      if (expTime && expTime < Date.now()) {
        // Session expired
        if (allowOrderEntry) {
          return true; // Allow order entry to continue (stores locally)
        }
        // For data viewing actions - clear and reload
        const keysToRemove = ['sessionExpiry', 'userId', 'userName', 'userPhone', 
                             'selectedTeamId', 'selectedTeamName', 'userTeams',
                             'teamName', 'teamCode', 'loginMode', 'salesMode'];
        keysToRemove.forEach(key => {
          try { localStorage.removeItem(key); } catch(e) {}
        });
        location.reload();
        return false;
      }
      return true; // Session valid
    } catch(e) {
      console.warn('Session check failed', e);
      return true; // Don't block on error
    }
  }

  // helper to get current position as a Promise with timeout
  function getCurrentPositionPromise(timeout=5000){
    return new Promise((resolve)=>{
      if (!('geolocation' in navigator)) return resolve(null);
      let resolved=false;
      const timer = setTimeout(()=>{ if (!resolved) { resolved=true; resolve(null); } }, timeout);
      navigator.geolocation.getCurrentPosition((pos)=>{ if (resolved) return; resolved=true; clearTimeout(timer); resolve(pos); }, (err)=>{ if (resolved) return; resolved=true; clearTimeout(timer); resolve(null); }, { enableHighAccuracy:true, timeout: timeout });
    });
  }

  // Form input references
  const customerInput = qs('#customerName');
  const addressInput = qs('#address');
  const cellInput = qs('#cellNumber');
  const notesInput = qs('#notes');
  const checkNumberInput = qs('#checkNumber');
  const donationOnlyCheckbox = qs('#donationOnly');
  const smsConsentNote = qs('#smsConsentNote');

  // Donation-only mode toggle handler
  donationOnlyCheckbox && donationOnlyCheckbox.addEventListener('change', function() {
    const isDonationMode = this.checked;
    
    if (isDonationMode) {
      // Pre-fill and lock customer fields
      if (customerInput) {
        customerInput.value = 'Anonymous Donation';
        customerInput.readOnly = true;
        customerInput.style.backgroundColor = '#f5f5f5';
      }
      if (addressInput) {
        addressInput.value = 'Donation';
        addressInput.readOnly = true;
        addressInput.style.backgroundColor = '#f5f5f5';
      }
      if (cellInput) {
        cellInput.value = '0000000000';
        cellInput.readOnly = true;
        cellInput.style.backgroundColor = '#f5f5f5';
      }
      // No receipt is sent for donation-only orders (the queue skips them), so
      // don't leave a promise of one on screen.
      if (smsConsentNote) smsConsentNote.style.display = 'none';

      // Disable and clear all product inputs
      document.querySelectorAll('input[data-product-id]').forEach(input => {
        input.value = '0';
        input.disabled = true;
        input.style.backgroundColor = '#f5f5f5';
        input.style.cursor = 'not-allowed';
      });
      
      // Update order total
      if (typeof computeTotal === 'function') {
        computeTotal();
      }
      
      if (window.PWALogger) {
        window.PWALogger.log('ui', 'Donation-only mode enabled');
      }
    } else {
      // Re-enable fields
      if (customerInput) {
        customerInput.value = '';
        customerInput.readOnly = false;
        customerInput.style.backgroundColor = '';
      }
      if (addressInput) {
        addressInput.value = '';
        addressInput.readOnly = false;
        addressInput.style.backgroundColor = '';
      }
      if (cellInput) {
        cellInput.value = '';
        cellInput.readOnly = false;
        cellInput.style.backgroundColor = '';
      }
      if (smsConsentNote) smsConsentNote.style.display = '';

      // Re-enable product inputs
      document.querySelectorAll('input[data-product-id]').forEach(input => {
        input.disabled = false;
        input.style.backgroundColor = '';
        input.style.cursor = '';
      });
      
      if (window.PWALogger) {
        window.PWALogger.log('ui', 'Donation-only mode disabled');
      }
    }
  });

  // Clear form button handler
  clearFormBtn && clearFormBtn.addEventListener('click', ()=>{
    if (confirm('Are you sure you want to clear all form fields? This cannot be undone.')) {
      // Clear all form fields
      customerInput && (customerInput.value = '');
      addressInput && (addressInput.value = '');
      cellInput && (cellInput.value = '');
      notesInput && (notesInput.value = '');
      checkNumberInput && (checkNumberInput.value = '');
      donationAmount && (donationAmount.value = '0');
      
      // Clear all product quantities
      const prodInputs = document.querySelectorAll('.sm-prod-row input[type="number"]');
      prodInputs.forEach(inp => inp.value = '0');
      
      // Reset payment method to cash
      const cashRadio = document.querySelector('input[name="paymentMethod"][value="cash"]');
      if (cashRadio) cashRadio.checked = true;
      
      // Hide check number field
      const checkRow = document.getElementById('checkNumberRow');
      if (checkRow) checkRow.classList.add('hidden');
      
      // Clear any stored selection data
      if (addressInput) {
        try { delete addressInput.dataset.subsalesSelected; } catch(e) {}
      }
      
      // Focus on customer name
      customerInput && customerInput.focus();
      
      if(window.PWALogger && window.PWALogger.debugEnabled){
        window.PWALogger.log('ui', 'Form cleared by user');
      }
    }
  });

  saveOrderBtn && saveOrderBtn.addEventListener('click', async ()=>{
    // Prevent duplicate submissions - disable button immediately
    if (saveOrderBtn.disabled) return;
    saveOrderBtn.disabled = true;
    saveOrderBtn.textContent = 'Saving...';
    
    // Track activity
    if (window.PWASessionTracking) {
      window.PWASessionTracking.track('save_order_click');
    }
    
    // Log order save attempt
    if (window.PWALogger) {
      window.PWALogger.log('order', 'Save order button clicked', {
        has_customer: !!(qs('#customerName') && qs('#customerName').value.trim()),
        has_address: !!(qs('#address') && qs('#address').value.trim())
      });
    }
    
    // Check session validity - allow order entry even if expired (stores locally for sync later)
    const sessionValid = checkSessionValid(true);
    
    // Check if session actually expired but we're allowing continuation
    const expiry = localStorage.getItem('sessionExpiry');
    const sessionExpired = expiry && new Date(expiry).getTime() < Date.now();
    
    if (sessionExpired && sessionValid) {
      // Inform user that order will be saved locally only
      const proceed = confirm('Your session has expired. The order will be saved locally and synced when you log in again. Continue?');
      if (!proceed) {
        // Re-enable button if user cancels
        saveOrderBtn.disabled = false;
        saveOrderBtn.textContent = 'Save Order';
        return;
      }
    }
    
    // canonical customer/address values. Use a defensive getter for address because
    // autocomplete widgets sometimes inject alternate inputs that don't update the
    // original #address value reliably in all environments.
  const customer = qs('#customerName') && qs('#customerName').value.trim();
  let address = '';
  try{ address = (qs('#address') && qs('#address').value && qs('#address').value.trim()) || ''; }catch(e){ address = ''; }
  // Append unit/floor/apt if provided
  const unitFloorApt = qs('#unitFloorApt') && qs('#unitFloorApt').value.trim();
  if (unitFloorApt) {
    address = address + (address ? ' ' : '') + unitFloorApt;
  }
  const cell = qs('#cellNumber') && qs('#cellNumber').value.trim();
  
  // Re-enable button before showing validation errors
  const reEnableButton = () => {
    saveOrderBtn.disabled = false;
    saveOrderBtn.textContent = 'Save Order';
  };
  
  if (!customer || !address) {
    reEnableButton();
    return alert('Customer and address required');
  }
  if (!cell) {
    reEnableButton();
    return alert('Phone number is required');
  }
  
  // Validate payment method
  const payCheckChecked = payCheck && payCheck.checked;
  const payCashChecked = payCash && payCash.checked;
  const chkNumber = (checkNumber && checkNumber.value) ? checkNumber.value.trim() : '';
  
  if (!payCheckChecked && !payCashChecked) {
    reEnableButton();
    return alert('Please select a payment method (Cash or Check)');
  }
  if (payCheckChecked && payCashChecked) {
    reEnableButton();
    return alert('Please select either Cash OR Check, not both');
  }
  if (payCheckChecked && !chkNumber) {
    reEnableButton();
    return alert('Check number is required when paying by check');
  }
  
    const notes = qs('#notes') && qs('#notes').value || '';
    // gather product quantities from rendered inputs
    const prodInputs = document.querySelectorAll('input[data-product-id]');
    const products = [];
    prodInputs.forEach(pi => {
      try{
        const pid = pi.getAttribute('data-product-id');
        const qty = parseInt(pi.value,10) || 0;
        const price = parseFloat(pi.getAttribute('data-product-price') || ( (productsConfig.find(p=>String(p.id)===String(pid))||{} ).price ) ) || 0;
        if (qty > 0) products.push({ id: pid, name: (productsConfig.find(p=>String(p.id)===String(pid))||{}).name || pid, qty: qty, price: Number(price.toFixed(2)) });
      }catch(e){}
    });
    const donation = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0;
    const paymentMethod = payCheckChecked ? 'check' : 'cash';
    
    // Create price snapshot of ALL products at order creation time (for historical accuracy)
    const priceSnapshot = {};
    if (productsConfig && Array.isArray(productsConfig)) {
      productsConfig.forEach(p => {
        priceSnapshot[p.id] = p.price;
      });
    }
    
    // Determine sales mode and capture appropriate auth data
    const salesMode = localStorage.getItem('salesMode') || localStorage.getItem('detectedSalesMode') || 'legacy';
    let subsalesUserId, subsalesTeamId, enteredById, enteredByName, teamName, teamCode;
    
    if (salesMode === 'user') {
      // User mode
      subsalesUserId = localStorage.getItem('userId') || '';
      subsalesTeamId = localStorage.getItem('selectedTeamId') || '-1';
      enteredById = subsalesUserId;
      enteredByName = localStorage.getItem('userName') || '';
    } else {
      // Legacy mode
      enteredById = localStorage.getItem('teamMemberId') || '';
      enteredByName = localStorage.getItem('teamMemberName') || '';
      teamName = localStorage.getItem('teamName') || '';
      teamCode = localStorage.getItem('teamCode') || '';
    }

    // If we're editing an existing order, start with the original data
    let order;
    const isEditing = window._editingOrder && window._editingOrder.orderId;
    
    if (isEditing) {
      // Load the existing order from storage to preserve all fields
      const existingOrder = await Storage.get(window._editingOrder.orderId);
      
      // Start with existing order data and update with form values
      order = existingOrder ? { ...existingOrder } : {};
      
      // Update with form values
      order.id = window._editingOrder.orderId; // preserve id
      order.customer = customer;
      order.address = address;
      order.cellNumber = cell;
      order.products = products;
      order.donationAmount = donation;
      order.paymentMethod = paymentMethod;
      order.checkNumber = chkNumber;
      order.notes = notes;
      // Update price snapshot (keep existing if not rebuilding)
      order.price_snapshot = priceSnapshot;
      
      // Ensure createdAt is preserved (fallback to now if missing)
      if (!order.createdAt) {
        order.createdAt = new Date().toISOString();
      }
      
      // Preserve subsales_user_id and subsales_team_id from original
      // Only update geo if we got a new position
      const pos = await getCurrentPositionPromise(5000);
      if (pos && pos.coords) {
        order.geo = { latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: pos.coords.accuracy };
      }
    } else {
      // Creating new order — shared with the digital-payment "paid" handler via buildNewOrderObject()
      // Record donation-only explicitly rather than leaving it to be inferred
      // later from the placeholder phone number. Both cases exist and they are
      // not the same thing: a donation (nobody to text) versus a normal sale
      // where the customer would not give a number.
      const donationOnly = !!(qs('#donationOnly') && qs('#donationOnly').checked);

      order = await buildNewOrderObject(paymentMethod, {
        customer, address, cell, products, priceSnapshot, donation, chkNumber, notes,
        subsalesUserId, subsalesTeamId, enteredById, enteredByName, teamName, teamCode,
        donationOnly
      });
    }
    
    // Handle save based on edit state - LOCAL FIRST approach
    try{
      if (isEditing) {
        // update existing
        const editing = window._editingOrder;
        if (editing.local) {
          // update local queued order
          await Storage.update(order);
          renderOrders();
          syncStatus && (syncStatus.textContent='Local order updated');
          
          // Show confirmation popup IMMEDIATELY (don't clear form yet - OK button will do it)
          showOrderConfirmation(order, true);
          
          // Re-enable button after short delay
          setTimeout(() => {
            saveOrderBtn.disabled = false;
            saveOrderBtn.textContent = 'Save Order';
          }, 2000);
          
          // Background sync (non-blocking)
          if (navigator.onLine) {
            setTimeout(() => trySync(), 100);
          }
          return;
        } else {
          // Queue update for synced order (will be sent via PUT to server)
          await Storage.queueOperation({ type: 'update', order_id: order.id, payload: order });
          // Also update local copy for immediate UI feedback
          await Storage.update(order);
          renderOrders();
          syncStatus && (syncStatus.textContent='Update queued for sync');
          
          // Show confirmation popup IMMEDIATELY
          showOrderConfirmation(order, true);
          
          // Re-enable button after short delay
          setTimeout(() => {
            saveOrderBtn.disabled = false;
            saveOrderBtn.textContent = 'Save Order';
          }, 2000);
          
          // Background sync (non-blocking)
          if (navigator.onLine) {
            setTimeout(() => trySync(), 100);
          }
          return;
        }
      } else {
        // LOCAL-FIRST: Save locally IMMEDIATELY, sync in background
        await Storage.add(order);
        renderOrders();
        syncStatus && (syncStatus.textContent='Saved locally, syncing...');
        
        // Log successful order save
        if (window.PWALogger) {
          window.PWALogger.log('order', 'Order saved locally (local-first)', {
            order_id: order.id,
            customer: order.customer ? 'present' : 'missing',
            products_count: order.products ? order.products.length : 0,
            online: navigator.onLine
          });
        }
        
        // Show confirmation popup IMMEDIATELY (local-first UX)
        showOrderConfirmation(order, false);
        
        // Re-enable button after 2 seconds to prevent rapid duplicates
        setTimeout(() => {
          saveOrderBtn.disabled = false;
          saveOrderBtn.textContent = 'Save Order';
        }, 2000);
        
        // Attempt background sync without blocking (truly async)
        if (navigator.onLine) {
          setTimeout(() => {
            trySync().then(() => {
              syncStatus && (syncStatus.textContent='Synced to server');
            }).catch(() => {
              syncStatus && (syncStatus.textContent='Saved locally, will sync later');
            });
          }, 100);
        } else {
          syncStatus && (syncStatus.textContent='Saved locally (offline)');
        }
      }
    }catch(e){ 
      console.warn('order save flow error', e);
      // Re-enable button on error
      saveOrderBtn.disabled = false;
      saveOrderBtn.textContent = 'Save Order';
      alert('Error saving order: ' + (e.message || e));
    }
  });

  async function trySync(){
    // First, process any queued operations (update/delete) before sending creates
    try{
      const ops = await Storage.allQueuedOps();
      if (ops && ops.length) {
        if (!navigator.onLine) { syncStatus && (syncStatus.textContent='Waiting for network'); return; }
        syncStatus && (syncStatus.textContent='Processing queued operations...');
        
        // Build headers based on login mode
        const loginMode = localStorage.getItem('loginMode') || 'legacy';
        const headers = {};
        if (loginMode === 'user') {
          headers['X-User-ID'] = localStorage.getItem('userId') || '';
          headers['X-Team-ID'] = localStorage.getItem('selectedTeamId') || '';
        } else {
          headers['X-Team-Name'] = localStorage.getItem('teamName') || '';
          headers['X-Access-Code'] = localStorage.getItem('teamCode') || '';
        }
        
        for (const op of ops) {
          try{
            // Use embedded credentials from payload as fallback
            const opHeaders = { ...headers };
            if (op.payload && op.payload._sync_user_id && op.payload._sync_team_id) {
              opHeaders['X-User-ID'] = op.payload._sync_user_id;
              opHeaders['X-Team-ID'] = op.payload._sync_team_id;
            } else if (op.payload && op.payload._sync_team_name && op.payload._sync_team_code) {
              opHeaders['X-Team-Name'] = op.payload._sync_team_name;
              opHeaders['X-Access-Code'] = op.payload._sync_team_code;
            }
            
            if (op.type === 'delete'){
              const url = apiBase ? (apiBase + '/orders/' + encodeURIComponent(op.order_id)) : '/wp-json/order-manager/v1/orders/' + encodeURIComponent(op.order_id);
              const resp = await fetch(url, { method: 'DELETE', headers: opHeaders });
              if (resp.ok) { await Storage.removeQueuedOp(op._id); }
            } else if (op.type === 'update'){
              const url = apiBase ? (apiBase + '/orders/' + encodeURIComponent(op.order_id)) : '/wp-json/order-manager/v1/orders/' + encodeURIComponent(op.order_id);
              const updateHeaders = { ...opHeaders, 'Content-Type':'application/json' };
              // Add team member authentication headers for PWA edits
              const memberEmail = localStorage.getItem('memberEmail');
              const memberCode = localStorage.getItem('memberCode');
              if (memberEmail && memberCode) {
                updateHeaders['X-Team-Email'] = memberEmail;
                updateHeaders['X-Member-Access-Code'] = memberCode;
              }
              const resp = await fetch(url, { method: 'PUT', headers: updateHeaders, body: JSON.stringify(op.payload) });
              if (resp.ok) { 
                await Storage.removeQueuedOp(op._id);
                // Update local order to reflect it's been synced
                if (op.payload && op.payload.id) {
                  await Storage.update(op.payload);
                }
              } else {
                console.warn('Queued operation failed:', op.type, op.order_id, 'status:', resp.status);
                const errorMsg = 'Failed to sync ' + op.type + ' for order ' + op.order_id + ' (HTTP ' + resp.status + ')';
                if (window.smShowSnackbar) window.smShowSnackbar(errorMsg, { timeout: 8000 });
              }
            }
          }catch(e){ 
            console.warn('Queued operation error:', op.type, op.order_id, e);
            const errorMsg = 'Error syncing ' + (op.type || 'operation') + ' for order ' + op.order_id + ': ' + (e.message || e.toString());
            if (window.smShowSnackbar) window.smShowSnackbar(errorMsg, { timeout: 8000 });
          }
        }
      }
    }catch(e){ /* ignore queued-op processing errors */ }

    // Then sync any local queued new orders
    try{
      const list = await Storage.all();
      if (!list || !list.length) { syncStatus && (syncStatus.textContent='No queued orders'); return; }
      if (!navigator.onLine) { syncStatus && (syncStatus.textContent='Waiting for network'); return; }
      syncStatus && (syncStatus.textContent='Syncing...');
      
      // Build headers based on login mode
      const loginMode = localStorage.getItem('loginMode') || 'legacy';
      const headers = { 'Content-Type': 'application/json' };
      if (loginMode === 'user') {
        headers['X-User-ID'] = localStorage.getItem('userId') || '';
        headers['X-Team-ID'] = localStorage.getItem('selectedTeamId') || '';
      } else {
        headers['X-Team-Name'] = localStorage.getItem('teamName') || '';
        headers['X-Access-Code'] = localStorage.getItem('teamCode') || '';
      }
      
      // One order the server rejects must not block every order behind it.
      // These stay in local storage and are retried on the next sync.
      let rejected = 0;

      for (const order of list) {
        try {
          const url = apiBase ? (apiBase + '/orders') : '/wp-json/order-manager/v1/orders';
          
          // Use embedded credentials as fallback if current session is expired
          const syncHeaders = { ...headers };
          if (order._sync_user_id && order._sync_team_id) {
            syncHeaders['X-User-ID'] = order._sync_user_id;
            syncHeaders['X-Team-ID'] = order._sync_team_id;
          } else if (order._sync_team_name && order._sync_team_code) {
            syncHeaders['X-Team-Name'] = order._sync_team_name;
            syncHeaders['X-Access-Code'] = order._sync_team_code;
          }
          
          const payload = {
            order_id: order.id,
            user_id: order.subsales_user_id || localStorage.getItem('userId') || '',
            team_id: order.subsales_team_id || localStorage.getItem('selectedTeamId') || '',
            customer: order.customer,
            address: order.address,
            notes: order.notes,
            products: order.products || [],
            price_snapshot: order.price_snapshot || {},
            donationAmount: order.donationAmount,
            donationOnly: !!order.donationOnly,
            paymentMethod: order.paymentMethod,
            checkNumber: order.checkNumber,
            cellNumber: order.cellNumber,
            createdAt: order.createdAt,
            geo: order.geo || null,
            // Include user/team info for leaderboards
            entered_by_name: order.entered_by_name || '',
            entered_by_id: order.entered_by_id || '',
            teamName: order.teamName || '',
            teamCode: order.teamCode || ''
          };

          const resp = await fetch(url, {
            method: 'POST',
            headers: syncHeaders,
            body: JSON.stringify(payload)
          });
          if (resp.ok) {
            await Storage.remove(order.id);
          } else {
            console.warn('sync failed status', resp.status);
            let errorDetail = '';
            try {
              const errText = await resp.text();
              console.error('Server error response:', errText);
              errorDetail = errText ? ': ' + errText.substring(0, 500) : '';
            } catch(e) {}
            const errorMsg = 'Sync failed for order ' + (order.customer || order.id) + ' - HTTP ' + resp.status + errorDetail;
            console.error('Full error message:', errorMsg);
            syncStatus && (syncStatus.textContent = errorMsg);
            // The server answered and refused THIS order, so the connection is
            // fine - keep going. Stopping here used to strand every order behind
            // a single bad one, on every retry, forever.
            rejected++;
          }
        } catch (err) {
          // fetch() threw, so this is the connection rather than the order.
          // Stop here: the rest would fail the same way, and retrying the whole
          // batch once the signal is back is both faster and quieter.
          console.warn('sync failed for', order.id, err);
          const errorMsg = 'Sync paused - no connection. Your orders are saved and will send automatically.';
          syncStatus && (syncStatus.textContent = errorMsg);
          if (window.smShowSnackbar) {
            window.smShowSnackbar(errorMsg, { timeout: 10000 });
          } else {
            alert(errorMsg);
          }
          renderOrders();
          return;
        }
      }

      if (rejected > 0) {
        const msg = rejected + ' order' + (rejected === 1 ? '' : 's') + " couldn't be sent and are still saved on this phone. Everything else went through.";
        syncStatus && (syncStatus.textContent = msg);
        if (window.smShowSnackbar) { window.smShowSnackbar(msg, { timeout: 10000 }); }
      } else {
        syncStatus && (syncStatus.textContent='All queued orders synced');
      }
      renderOrders();
    }catch(e){ console.warn('trySync error', e); }
  }

  // Service worker registration handled in index.html (uses portalBase for proper scope)
  // Duplicate registration removed to prevent scope conflicts

  // Google Places autocomplete loader intentionally disabled: plain-text
  // address entry is used. Previous initSMPlaces implementation removed.
  window.initSMPlaces = function(){ try{ window._sm_places_initialized = false; }catch(e){} };
  const savedKey = localStorage.getItem('bkmb_google_maps_key') || googleMapsApiKey;
  if (false && savedKey) { 
    const s = document.createElement('script');
    // Use loading=async to follow Google best-practice for script loading and avoid console warnings
    s.src = 'https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(savedKey)+'&libraries=places&loading=async&callback=initSMPlaces';
    s.async = true; s.defer = true; document.head.appendChild(s);
    // If the script fails to load (network, tracking prevention, TLS), unhide canonical and warn user
    try{
      s.onerror = function(){
        try{ console.warn('Google Maps script failed to load'); }catch(e){}
        try{ window._sm_places_initialized = false; }catch(e){}
        // expose canonical input so user may type the address manually
        try{ const c = qs('#address'); if (c) { c.style.display = 'block'; c.removeAttribute('aria-hidden'); } }catch(e){}
        try{ window.smShowSnackbar && window.smShowSnackbar('Google Places failed to load — please enter address manually.', { timeout: 8000 }); }catch(e){}
      };
      // timeout: if not initialized within 4s, assume blocked and unhide canonical field
      setTimeout(()=>{
        try{
          if (!window._sm_places_initialized) {
            console.warn('initSMPlaces not called within timeout — enabling manual address input');
            const c = qs('#address'); if (c) { c.style.display = 'block'; c.removeAttribute('aria-hidden'); }
            try{ window.smShowSnackbar && window.smShowSnackbar('Autocomplete unavailable — please enter address manually.', { timeout: 6000 }); }catch(e){}
          }
        }catch(e){}
      }, 4000);
    }catch(e){}
  }

  // Compatibility bridge for closed/minified autocomplete widgets (best-effort)
  // Provides: copyPlaceToCanonical(host), patchGmpPlaceAutocomplete(), live-capture listeners
  function copyPlaceToCanonical(host){
    // Disabled: plain text #address is the only source of truth. This
    // function previously attempted deep extraction from third-party
    // autocomplete hosts but has been intentionally removed.
    return;
  }

  function patchGmpPlaceAutocomplete(){
    // Prototype patching removed — no-op now to avoid modifying host components.
    return;
  }

  // Lightweight address-bridge: silently attach handlers to common autocomplete widgets
  // so their selected values are copied into the canonical #address field.
  function setupAddressBridge(){
    // Address bridge disabled — plain text #address input is the single source of truth.
    return;
  }

  // Autocomplete bridge disabled

  // Autocomplete extraction and live-capture disabled — using plain text #address field only.

  // Boot: do not render queued orders or attempt sync until the user is authenticated.
  // Session restore or a successful login will call renderOrders() and trySync().

  // Fetch remote orders (all) — returns array of orders from server
  async function fetchRemoteOrders(limit=1000){
    try{
      const url = apiBase ? (apiBase + '/orders?limit=' + encodeURIComponent(limit)) : '/wp-json/order-manager/v1/orders?limit=' + encodeURIComponent(limit);
      
      // Use appropriate auth headers based on login mode
      const loginMode = localStorage.getItem('loginMode') || 'legacy';
      const headers = {};
      if (loginMode === 'user') {
        headers['X-User-ID'] = localStorage.getItem('userId') || '';
        headers['X-Team-ID'] = localStorage.getItem('selectedTeamId') || '';
      } else {
        headers['X-Team-Name'] = localStorage.getItem('teamName') || '';
        headers['X-Access-Code'] = localStorage.getItem('teamCode') || '';
      }
      
      const resp = await fetch(url, { headers: headers });
      if (!resp.ok) return [];
      const j = await resp.json().catch(()=>[]);
      return Array.isArray(j) ? j : [];
    }catch(e){ console.warn('fetchRemoteOrders failed', e); return []; }
  }

  // Show 'My orders' — local queued and remote orders for current user
  // Individual mode: shows all untallied orders
  // Team mode: shows today's orders only
  async function showMyOrders(){

    // Get current user ID (supports both user mode and legacy mode)
    const loginMode = localStorage.getItem('loginMode') || 'legacy';

    // Log function execution start
    if (window.PWALogger) {
      window.PWALogger.log('ui', 'showMyOrders() function started', {
        login_mode: loginMode
      });
    }
    
    let currentUserId, currentUserName, currentTeamId;
    
    if (loginMode === 'user') {
      // User mode: use userId from localStorage
      currentUserId = localStorage.getItem('userId') || '';
      currentUserName = localStorage.getItem('userName') || '';
      currentTeamId = localStorage.getItem('selectedTeamId') || '';
    } else {
      // Legacy mode: use teamMemberId
      currentUserId = localStorage.getItem('teamMemberId') || '';
      currentUserName = localStorage.getItem('teamMemberName') || '';
      currentTeamId = localStorage.getItem('teamId') || '';
    }
    
    // Check if we're in individual mode (team_id = -1)
    const isIndividualMode = (currentTeamId === '-1' || currentTeamId === -1);


    // Helper function to check if order is from today
    const isToday = (dateStr) => {
      if (!dateStr) return false;
      try {
        const orderDate = new Date(dateStr);
        const today = new Date();
        return orderDate.getFullYear() === today.getFullYear() &&
               orderDate.getMonth() === today.getMonth() &&
               orderDate.getDate() === today.getDate();
      } catch(e) {
        return false;
      }
    };
    
    // Get ALL local orders (no filtering - these are by definition from this device/user)
    // Local orders are unsynced and need to be visible for verification before sync
    const local = await Storage.all();
    const localFiltered = local; // Show all local orders - no filtering needed for offline storage
    
    // Debug logging for troubleshooting

    // Log to server with detailed context
    if (window.PWALogger) {
      window.PWALogger.log('ui', 'My Orders - Local orders loaded (no filtering)', {
        login_mode: loginMode,
        current_user_id: currentUserId,
        current_user_name: currentUserName,
        total_local_orders: local.length,
        has_sample: !!local[0],
        sample_order_id: local[0] ? local[0].id : null,
        sample_entered_by_id: local[0] ? local[0].entered_by_id : null,
        sample_subsales_user_id: local[0] ? local[0].subsales_user_id : null,
        sample_entered_by_name: local[0] ? local[0].entered_by_name : null
      });
    }
    
    // Fetch and filter remote orders
    // Individual mode (user): show all untallied orders
    // Team mode (legacy): show today only
    const remote = await fetchRemoteOrders(1000);
    
    // Log remote fetch results
    if (window.PWALogger) {
      window.PWALogger.log('api', 'My Orders - Remote orders fetched', {
        total_remote_orders: remote ? remote.length : 0,
        online: navigator.onLine,
        login_mode: loginMode
      });
    }
    
    const remoteFiltered = (remote || []).filter(r => {
      // Date/tally filtering depends on mode:
      // Individual mode (team -1): Show all untallied orders regardless of date
      // Team mode: Show only today's orders
      if (isIndividualMode) {
        // Individual mode: show all untallied orders (tallied field not present or 0)
        if (r.tallied === 1 || r.tallied === '1') return false;
      } else {
        // Team mode: only show today's orders
        if (!isToday(r.created_at || r.createdAt)) return false;
      }
      
      // Check if order belongs to current user
      // r.order_data may be present as object (server decodes) or as json string
      const od = r.order_data || (r.order_data === undefined ? null : r.order_data);
      if (od) {
        try{
          // od should already be object, but be defensive
          const entered = typeof od === 'string' ? JSON.parse(od) : od;
          if (currentUserId && entered.entered_by_id && entered.entered_by_id === currentUserId) return true;
          if (currentUserId && entered.subsales_user_id && entered.subsales_user_id === currentUserId) return true;
          if (currentUserName && entered.entered_by_name && entered.entered_by_name === currentUserName) return true;
        }catch(e){}
      }
      // fallback: check r.user_id
      if (currentUserId && r.user_id && String(r.user_id) === String(currentUserId)) return true;
      return false;
    });
    
    // Log remote filtering results
    if (window.PWALogger) {
      window.PWALogger.log('ui', 'My Orders - Remote orders filtered', {
        total_remote: remote ? remote.length : 0,
        filtered_remote: remoteFiltered.length,
        current_user_id: currentUserId,
        sample_remote_id: remote && remote[0] ? (remote[0].order_id || remote[0].id) : null,
        sample_remote_user_id: remote && remote[0] ? remote[0].user_id : null
      });
    }

  // Render modal-like overlay
    const modalId = 'myOrdersModal';
    let modal = qs('#' + modalId);
    if (!modal){
      modal = document.createElement('div'); modal.id = modalId; modal.className = 'orders-inlay hidden';
      document.body.appendChild(modal);
    }
    // Build HTML with Edit/Delete actions for local and remote orders (mark pending ops)
    const queuedOps = await Storage.allQueuedOps().catch(()=>[]);
    const localHtml = localFiltered.length ? localFiltered.map(o=>{
      const hasOp = queuedOps && queuedOps.some(op=>String(op.order_id) === String(o.id));
      const badge = hasOp ? '<span class="sm-badge">Pending</span>' : '';
      return `<div class="order" data-local-id="${o.id}"><strong>${o.customer||''} ${badge}</strong><div>${o.address||''}</div><div>${o.createdAt||''}</div><div>Geo: ${o.geo ? (o.geo.latitude+','+o.geo.longitude) : 'n/a'}</div><div style="margin-top:6px"><button class="sm-btn edit-order" data-local-id="${o.id}">Edit</button> <button class="sm-btn delete-order" data-local-id="${o.id}" style="background:#dc2626;color:#fff">Delete</button></div></div>`;
    }).join('') : '<div>No local orders</div>';

    const remoteHtml = remoteFiltered.length ? remoteFiltered.map(r=>{
      // normalize order object
      const od = (r.order_data && typeof r.order_data === 'string') ? (function(){ try{ return JSON.parse(r.order_data); }catch(e){ return {}; } })() : (r.order_data || {});
      const cust = od.customer || r.customer || '';
      const created = r.created_at || r.createdAt || '';
      const hasOp = queuedOps && queuedOps.some(op=>String(op.order_id) === String(r.order_id || r.order_id));
      const badge = hasOp ? '<span class="sm-badge">Pending</span>' : '';
      return `<div class="order" data-remote-id="${r.order_id||r.order_id}"><strong>${cust} ${badge}</strong><div>${(od.address||r.address||'')}</div><div>${created}</div><div style="margin-top:6px"><button class="sm-btn edit-order-remote" data-remote-id="${r.order_id||r.order_id}">Edit</button> <button class="sm-btn delete-order-remote" data-remote-id="${r.order_id||r.order_id}" style="background:#dc2626;color:#fff">Delete</button></div></div>`;
    }).join('') : '<div>No remote orders</div>';

    // Add export buttons (offline only) - for emergency data recovery
    const exportButtonsHtml = !navigator.onLine && localFiltered.length > 0 
      ? `<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
           <p style="font-size: 12px; color: #666; margin-bottom: 8px;">⚠️ Offline Mode - Export for backup:</p>
           <button id="exportDownloadBtn" class="sm-btn" style="margin-right: 8px;">Download JSON</button>
           <button id="exportCopyBtn" class="sm-btn">Copy to Clipboard</button>
         </div>` 
      : '';

    // Remote orders label depends on mode
    const remoteLabel = isIndividualMode ? 'Remote (untallied)' : 'Remote (today only)';
    
    modal.innerHTML = `<div class="inlay-header"><strong>My Orders (${currentUserName||currentUserId||'You'})</strong><button id="closeMyOrdersBtn" class="sm-btn">Close</button></div><div style="display:flex;gap:12px;flex-wrap:wrap;padding:12px;"><div style="flex:1;min-width:280px;"> <h4>Local (pending sync)</h4>${localHtml}${exportButtonsHtml}</div><div style="flex:1;min-width:280px;"><h4>${remoteLabel}</h4>${remoteHtml}</div></div>`;
    const closeBtn = qs('#closeMyOrdersBtn'); 
    if (closeBtn) closeBtn.addEventListener('click', ()=>{ 
      modal.classList.add('hidden'); 
    });

    // Export buttons (offline mode only)
    const exportDownloadBtn = qs('#exportDownloadBtn');
    if (exportDownloadBtn) {
      exportDownloadBtn.addEventListener('click', async () => {
        try {
          const exportData = {
            exported_at: new Date().toISOString(),
            user: currentUserName || currentUserId || 'Unknown',
            login_mode: loginMode,
            orders: localFiltered
          };
          const dataStr = JSON.stringify(exportData, null, 2);
          const blob = new Blob([dataStr], { type: 'application/json' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = `orders-backup-${new Date().toISOString().split('T')[0]}.json`;
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
          
          await logWithContext('data', 'Orders exported to JSON file', { 
            order_count: localFiltered.length,
            user: currentUserName || currentUserId
          });
          
          if (window.smShowSnackbar) {
            window.smShowSnackbar(`✓ Downloaded ${localFiltered.length} orders`, { timeout: 3000 });
          } else {
            alert(`Downloaded ${localFiltered.length} orders successfully`);
          }
        } catch(err) {
          console.error('Export download failed:', err);
          alert('Export failed: ' + err.message);
        }
      });
    }

    const exportCopyBtn = qs('#exportCopyBtn');
    if (exportCopyBtn) {
      exportCopyBtn.addEventListener('click', async () => {
        try {
          const exportData = {
            exported_at: new Date().toISOString(),
            user: currentUserName || currentUserId || 'Unknown',
            login_mode: loginMode,
            orders: localFiltered
          };
          const dataStr = JSON.stringify(exportData, null, 2);
          
          // Try modern clipboard API first
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(dataStr);
          } else {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = dataStr;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
          }
          
          await logWithContext('data', 'Orders copied to clipboard', { 
            order_count: localFiltered.length,
            user: currentUserName || currentUserId
          });
          
          if (window.smShowSnackbar) {
            window.smShowSnackbar(`✓ Copied ${localFiltered.length} orders to clipboard`, { timeout: 3000 });
          } else {
            alert(`Copied ${localFiltered.length} orders to clipboard`);
          }
        } catch(err) {
          console.error('Copy to clipboard failed:', err);
          alert('Copy failed: ' + err.message);
        }
      });
    }

    // Attach delegated handlers for edit/delete
    modal.querySelectorAll('.edit-order').forEach(b=>{ b.addEventListener('click', async (e)=>{ 
      const id = b.getAttribute('data-local-id'); 
      await logWithContext('order', 'Edit local order clicked', { order_id: id });
      const ord = await Storage.get(id); 
      if (ord) {
        await logWithContext('order', 'Entering edit mode for local order', { 
          order_id: id,
          customer: ord.customer,
          address_present: !!ord.address
        });
        enterEditMode(ord, { local:true }); 
      }
      modal.classList.add('hidden'); 
    }); });
    
    modal.querySelectorAll('.delete-order').forEach(b=>{ b.addEventListener('click', async (e)=>{ 
      const id = b.getAttribute('data-local-id'); 
      await logWithContext('order', 'Delete local order clicked', { order_id: id });
      
      if (!confirm('Delete this local queued order?')) {
        await logWithContext('order', 'Delete local order cancelled', { order_id: id });
        return;
      }
      
      const ord = await Storage.get(id); 
      if (!ord) return; 
      
      await Storage.remove(id); 
      await logWithContext('order', 'Local order deleted', { 
        order_id: id,
        customer: ord.customer 
      });
      
      await renderOrders(); 
      await renderInlay(); 
      window.showUndoSnackbar('Local order deleted', async ()=>{ 
        try{ 
          await Storage.add(ord); 
          await logWithContext('order', 'Local order delete undone', { order_id: id });
          await renderOrders(); 
          await renderInlay(); 
        }catch(e){} 
      });
    }); });
    
    modal.querySelectorAll('.edit-order-remote').forEach(b=>{ b.addEventListener('click', async ()=>{ 
      const id = b.getAttribute('data-remote-id');
      await logWithContext('order', 'Edit remote order clicked', { order_id: id });
      
      const remote = remoteFiltered.find(r=>String(r.order_id||r.order_id) === String(id));
      if (remote){ 
        const od = (remote.order_data && typeof remote.order_data === 'string') ? (function(){ try{ return JSON.parse(remote.order_data); }catch(e){ return {}; } })() : (remote.order_data || {}); 
        const orderObj = Object.assign({}, od, { id: remote.order_id || remote.order_id }); 
        
        await logWithContext('order', 'Entering edit mode for remote order', { 
          order_id: id,
          customer: od.customer || remote.customer,
          address_present: !!(od.address || remote.address)
        });
        
        enterEditMode(orderObj, { local:false, remoteRaw: remote }); 
        modal.classList.add('hidden'); 
      }
    }); });
    
    modal.querySelectorAll('.delete-order-remote').forEach(b=>{ b.addEventListener('click', async ()=>{ 
      const id = b.getAttribute('data-remote-id'); 
      await logWithContext('order', 'Delete remote order clicked', { 
        order_id: id,
        online: navigator.onLine 
      });
      
      if (!confirm('Delete this order from server?')) {
        await logWithContext('order', 'Delete remote order cancelled', { order_id: id });
        return;
      }
      
      // Prompt for delete reason (required by backend)
      const deleteReason = prompt('Please enter a reason for deleting this order:');
      if (!deleteReason || deleteReason.trim() === '') {
        alert('Delete reason is required');
        await logWithContext('order', 'Delete cancelled - no reason provided', { order_id: id });
        return;
      }
      
      // Build headers based on login mode
      const loginMode = localStorage.getItem('loginMode') || 'legacy';
      const headers = {};
      if (loginMode === 'user') {
        const userId = localStorage.getItem('userId') || '';
        const teamId = localStorage.getItem('selectedTeamId') || '';
        headers['X-User-ID'] = userId;
        headers['X-Team-ID'] = teamId;
        
        // Log for debugging 401 errors
        if (window.PWALogger) {
          window.PWALogger.log('order', 'Delete request headers (user mode)', {
            has_user_id: !!userId,
            has_team_id: !!teamId,
            user_id: userId,
            team_id: teamId
          });
        }
      } else {
        const teamName = localStorage.getItem('teamName') || '';
        const accessCode = localStorage.getItem('teamCode') || '';
        headers['X-Team-Name'] = teamName;
        headers['X-Access-Code'] = accessCode;
        
        // Log for debugging 401 errors
        if (window.PWALogger) {
          window.PWALogger.log('order', 'Delete request headers (legacy mode)', {
            has_team_name: !!teamName,
            has_access_code: !!accessCode,
            team_name: teamName
          });
        }
      }
      
      // Validate we have auth headers before proceeding
      const hasAuth = (loginMode === 'user' && headers['X-User-ID'] && headers['X-Team-ID']) ||
                      (loginMode !== 'user' && headers['X-Team-Name'] && headers['X-Access-Code']);
      
      if (!hasAuth) {
        alert('Authentication error: Please log out and log back in.');
        await logWithContext('order', 'Delete failed - no auth headers', { login_mode: loginMode });
        return;
      }
      
      if (navigator.onLine) {
        try{ 
          const url = apiBase ? (apiBase + '/orders/' + encodeURIComponent(id)) : '/wp-json/order-manager/v1/orders/' + encodeURIComponent(id); 
          
          // Add timeout to prevent hanging
          const controller = new AbortController();
          const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
          
          const resp = await fetch(url, { 
            method: 'DELETE', 
            headers: {
              ...headers,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ delete_reason: deleteReason }),
            signal: controller.signal 
          }); 
          
          clearTimeout(timeoutId);
          
          if (resp.ok) { 
            await logWithContext('order', 'Remote order deleted successfully', { 
              order_id: id,
              status: resp.status 
            });
            alert('Order deleted successfully'); 
            // Refresh the orders list
            await renderInlay();
          } else { 
            const errorText = await resp.text().catch(() => 'Unknown error');
            await logWithContext('order', 'Remote order delete failed', { 
              order_id: id,
              status: resp.status,
              error: errorText 
            });
            
            if (resp.status === 401) {
              alert('Delete failed: Authentication error. Please log out and log back in.');
            } else if (resp.status === 404) {
              alert('Delete failed: Order not found on server. It may have already been deleted.');
            } else {
              alert('Delete failed: Server returned error ' + resp.status);
            }
          } 
        }catch(e){ 
          if (window.PWALogger) {
            window.PWALogger.logError('order', 'Remote order delete error', { 
              order_id: id,
              error: e.message,
              error_name: e.name 
            });
          }
          
          // Better error messages based on error type
          if (e.name === 'AbortError') {
            alert('Delete failed: Request timed out. Please check your connection and try again.');
          } else if (e.message && e.message.includes('Failed to fetch')) {
            alert('Delete failed: Network error. Please check your internet connection.');
          } else {
            alert('Delete failed: ' + (e.message || 'Unknown error'));
          }
        }
      } else {
        const opId = await Storage.queueOperation({ type:'delete', order_id: id });
        await logWithContext('order', 'Remote order delete queued (offline)', { 
          order_id: id,
          operation_id: opId 
        });
        
  window.showUndoSnackbar('Delete queued (will run when online)', async ()=>{ 
    try{ 
      if (opId) await Storage.removeQueuedOp(opId); 
      await logWithContext('order', 'Queued delete operation undone', { operation_id: opId });
      await renderOrders(); 
      await renderInlay(); 
    }catch(e){} 
  });
      }
      // refresh remote list
      try{ await fetchRemoteOrders(); }catch(e){}
      // close modal and refresh UI
      modal.classList.add('hidden');
    }); });
    modal.classList.remove('hidden');
    
    // Log modal display
    if (window.PWALogger) {
      window.PWALogger.log('ui', 'My Orders modal displayed', {
        local_orders_shown: localFiltered.length,
        remote_orders_shown: remoteFiltered.length,
        total_orders_shown: localFiltered.length + remoteFiltered.length
      });
    }
  // manual helpers removed
  }

  const myOrdersBtn = qs('#myOrdersBtn'); 
  if (myOrdersBtn) {

    myOrdersBtn.addEventListener('click', ()=>{ 

      // Log button click
      if (window.PWALogger) {
        window.PWALogger.log('ui', 'My Orders button clicked', {
          timestamp: new Date().toISOString(),
          online: navigator.onLine
        });
      }

      if(!checkSessionValid()) {

        if (window.PWALogger) {
          window.PWALogger.log('ui', 'My Orders blocked - session invalid', {});
        }
        return;
      }

      showMyOrders(); 
    });
  } else {
    console.warn('My Orders button NOT found in DOM');
  }
  
  const eodBtn = qs('#eodBtn'); 
  if (eodBtn) {

    eodBtn.addEventListener('click', async ()=>{ 
      if (window.PWASessionTracking) window.PWASessionTracking.track('eod_click');
      await logWithContext('ui', 'EOD Tally button clicked');

      if(!checkSessionValid()) {
        await logWithContext('ui', 'EOD blocked - session invalid');
        return;
      }
      
      try{ 
        await logWithContext('ui', 'EOD modal opening');
        qs('#eodInlay') || ensureEodExists(); 
        qs('#eodInlay').classList.remove('hidden'); 
        await renderEod();
        await logWithContext('ui', 'EOD modal displayed successfully');
      }catch(e){ 
        console.warn('EOD open error', e);
        if (window.PWALogger) {
          window.PWALogger.logError('ui', 'EOD modal error', e);
        }
      } 
    });
  } else {
    console.warn('EOD button NOT found in DOM');
  }
  // Force Sync button
  const forceSyncBtn = qs('#forceSyncBtn');
  if (forceSyncBtn) {
    forceSyncBtn.addEventListener('click', async ()=>{
      if (window.PWASessionTracking) window.PWASessionTracking.track('force_sync_click');
      await logWithContext('sync', 'Force Sync button clicked', {
        online: navigator.onLine
      });
      
      if(!checkSessionValid()) {
        await logWithContext('sync', 'Force Sync blocked - session invalid');
        return;
      }
      
      if (!navigator.onLine) {
        await logWithContext('sync', 'Force Sync failed - offline');
        alert('No network connection. Sync will start when online.');
        return;
      }
      
      try {
        syncStatus && (syncStatus.textContent = 'Manual sync...');
        await logWithContext('sync', 'Force Sync started');
        
        await trySync();
        
        if (window.PWASessionTracking) window.PWASessionTracking.track('sync_completed');
        await logWithContext('sync', 'Force Sync completed successfully');
        alert('Sync completed (or queued items attempted).');
      } catch (e) { 
        console.warn('Manual sync error', e);
        if (window.PWALogger) {
          window.PWALogger.logError('sync', 'Force Sync failed', e);
        }
        alert('Sync failed. See console for details.');
      }
    });
  }

  // Logout button: clear session and return to login screen
  const logoutBtn = qs('#logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async ()=>{
      await logWithContext('auth', 'Logout button clicked');
      
      if (!confirm('Log out of the app?')) {
        await logWithContext('auth', 'Logout cancelled by user');
        return;
      }
      
      await logWithContext('auth', 'Logout confirmed - clearing session');
      
      // End PWA session tracking
      if (window.PWASessionTracking) {
        window.PWASessionTracking.stopAutoHeartbeat();
        await window.PWASessionTracking.end();
      }
      try {
        // Clear legacy session keys
        localStorage.removeItem('teamName');
        localStorage.removeItem('teamCode');
        localStorage.removeItem('sessionExpiry');
        localStorage.removeItem('sessionDuration');
        localStorage.removeItem('teamMemberId');
        localStorage.removeItem('teamMemberName');
        localStorage.removeItem('teamId');
        
        // Clear user mode session keys
        localStorage.removeItem('userId');
        localStorage.removeItem('userName');
        localStorage.removeItem('userPhone');
        localStorage.removeItem('selectedTeamId');
        localStorage.removeItem('selectedTeamName');
        localStorage.removeItem('userTeams');
        localStorage.removeItem('userRole');
        // Tear down the driver money view so the next login starts clean.
        try{ driverExitMode(); }catch(e){}

        await logWithContext('auth', 'Logout complete - session cleared');
      } catch(e){
        if (window.PWALogger) {
          window.PWALogger.logError('auth', 'Logout error clearing localStorage', e);
        }
      }
  // show login
  if (loginSection) { loginSection.classList.remove('hidden'); try{ loginSection.style.display='block'; }catch(e){} }
  if (appSection) { appSection.classList.add('hidden'); try{ appSection.style.display='none'; }catch(e){} }
      // update UI
      updateNetworkUI();
      updateHeaderStatus();
      renderOrders();
    });
  }

  // Data compliance: Validate session on all form input focus to prevent data entry without login
  // This ensures personal information cannot be entered or viewed without valid authentication
  function addInputSessionValidation() {
    const protectedInputs = [
      '#customerName', '#address', '#unitFloorApt', '#cellNumber', '#notes',
      '#donationAmount', 'input[data-product-id]'
    ];
    
    protectedInputs.forEach(selector => {
      const elements = document.querySelectorAll(selector);
      elements.forEach(el => {
        el.addEventListener('focus', (e) => {
          // Check if session exists
          const expiry = localStorage.getItem('sessionExpiry');
          if (!expiry) {
            e.target.blur(); // Remove focus
            // Only reload if online
            if (navigator.onLine) {
              alert('Session expired. Please log in again.');
              location.reload();
            } else {

              if (window.smShowSnackbar) {
                window.smShowSnackbar('Working offline', { timeout: 3000 });
              }
            }
            return;
          }
          
          const expTime = new Date(expiry).getTime();
          if (expTime && expTime < Date.now()) {
            e.target.blur(); // Remove focus
            // Only enforce session expiry if online
            if (navigator.onLine) {
              alert('Session expired. Please log in again.');
              const keysToRemove = ['sessionExpiry', 'userId', 'userName', 'userPhone', 
                                   'selectedTeamId', 'selectedTeamName', 'userTeams',
                                   'teamName', 'teamCode', 'loginMode', 'salesMode'];
              keysToRemove.forEach(key => {
                try { localStorage.removeItem(key); } catch(e) {}
              });
              location.reload();
            } else {
              // Offline - allow continued use without reload

              if (window.smShowSnackbar) {
                window.smShowSnackbar('Working offline - session expired', { timeout: 3000 });
              }
            }
          }
        });
      });
    });
  }
  
  // Call after a short delay to ensure DOM is ready
  setTimeout(() => {
    addInputSessionValidation();
  }, 500);

})();
// BKMB Subsales PWA client (plugin-hosted)

// Debug helper removed — plain text address field in use.
