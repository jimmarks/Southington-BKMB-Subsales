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
            <button id="eodBtn" class="sm-btn sm-auth-hidden">End of Day Tally</button>
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
                <div class="btn-row"><button id="saveOrderBtn" class="sm-btn">Save Order</button></div>
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

  async function renderEod(){
    ensureEodExists();
    const container = qs('#eodContent'); if (!container) return;
    container.innerHTML = '<p>Computing totals...</p>';
    // Gather local and remote orders
    let local = [];
    try{ local = await Storage.all() || []; }catch(e){ local = []; }
    let remote = [];
    try{
      const teamName = localStorage.getItem('teamName') || '';
      const teamCode = localStorage.getItem('teamCode') || '';
      const url = apiBase ? (apiBase + '/orders?limit=10000') : '/wp-json/order-manager/v1/orders?limit=10000';
      const resp = await fetch(url, { headers: { 'X-Team-Name': teamName, 'X-Access-Code': teamCode } });
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
    let totalDonation = 0; let totalCash = 0; let totalCheck = 0;
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
      // Determine created timestamp from several possible locations
      const created = od && (od.createdAt || od.created_at) || o.createdAt || o.created_at || null;
      if (!isSameDayForServer(o, created)) return; // skip orders not from today (per server)
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
    }
    all.forEach(o => { try{ extractOrderInfo(o); }catch(e){} });
    // Render table
    let html = '<table class="widefat fixed" style="margin-bottom:12px"><thead><tr><th>Product</th><th style="text-align:right">Qty sold</th></tr></thead><tbody>';
    products.forEach(p => { const q = prodTotals[p.id] || 0; html += `<tr><td>${escapeHtml(p.name||p.id)}</td><td style="text-align:right">${q}</td></tr>`; });
    html += '</tbody></table>';
    html += '<table class="widefat fixed" style="max-width:320px"><tbody>';
    html += `<tr><td><strong>Total Donation</strong></td><td style="text-align:right">$${Number(totalDonation||0).toFixed(2)}</td></tr>`;
    html += `<tr><td><strong>Total Cash</strong></td><td style="text-align:right">$${Number(totalCash||0).toFixed(2)}</td></tr>`;
    html += `<tr><td><strong>Total Check</strong></td><td style="text-align:right">$${Number(totalCheck||0).toFixed(2)}</td></tr>`;
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
    const DB = 'bkmb-pwa-db';
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
  const ordersList = qs('#ordersList');
  const networkStatus = qs('#networkStatus');
  const syncStatus = qs('#syncStatus');
  const installBox = qs('#installBox');
  const installBtn = qs('#installBtn');

  // Products will be rendered dynamically from configured products (localized in cfg.products)
  const donationAmount = qs('#donationAmount');
  const orderTotalEl = qs('#orderTotal');
  const payCheck = qs('#payCheck');
  const payCash = qs('#payCash');
  const checkNumberRow = qs('#checkNumberRow');
  const checkNumber = qs('#checkNumber');

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
        if (digits.length === 10) { pn.classList.remove('invalid'); try{ pn.setCustomValidity(''); }catch(e){} }
        else { pn.classList.add('invalid'); try{ pn.setCustomValidity('Phone number must be 10 digits'); }catch(e){} }
      }catch(e){}
    });
    pn.addEventListener('blur', ()=>{
      try{ const v = pn.value.replace(/\D/g,''); if (v.length !== 10) { try{ pn.reportValidity(); }catch(e){} } }catch(e){}
    });
  })();

  // Debug/troubleshooting helpers removed — the PWA now uses a plain text
  // #address input as the single source of truth. Previous deep-inspection,
  // proximity scanning, and debug panels were removed to simplify behavior.

      // All deep-inspection/proximity scanning removed.

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

      const fields = ['#customerName','#address','#cellNumber','#notes','#donationAmount','#checkNumber'];
      fields.forEach(s=>{ const el = qs(s); if (!el) return; try{ if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') el.value = ''; }catch(e){} });
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
      if (payCheck) payCheck.checked = false; if (payCash) payCash.checked = false;
      try{ if (checkNumberRow) checkNumberRow.classList.add('hidden'); }catch(e){}
      // reset computed total
      try{ if (orderTotalEl) orderTotalEl.textContent = '$0.00'; }catch(e){}
      // re-run compute to update total state
      try{ computeTotal(); }catch(e){}
      // remove edit-mode UI marker when clearing the form
      try{ document.body.classList.remove('sm-edit-mode'); }catch(e){}
    }catch(e){ /* silent */ }
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
        return j;
      }
      return null;
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
  let address = '';
  try{ address = (qs('#address') && qs('#address').value && qs('#address').value.trim()) || ''; }catch(e){ address = ''; }
  if (!customer || !address) return alert('Customer and address required');
    const notes = qs('#notes') && qs('#notes').value || '';
    const cell = qs('#cellNumber') && qs('#cellNumber').value.trim();
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
    const paymentMethod = (payCheck && payCheck.checked) ? 'check' : ((payCash && payCash.checked) ? 'cash' : '');
    const chkNumber = (checkNumber && checkNumber.value) ? checkNumber.value.trim() : '';
    // capture team member info from storage
    const enteredById = localStorage.getItem('teamMemberId') || '';
    const enteredByName = localStorage.getItem('teamMemberName') || '';
  const teamName = localStorage.getItem('teamName') || '';
  const teamCode = localStorage.getItem('teamCode') || '';

    // capture GPS coordinates (best-effort)
    const pos = await getCurrentPositionPromise(5000);
    const geo = pos && pos.coords ? { latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: pos.coords.accuracy } : null;

    const order = {
      id: 'o-' + Date.now(),
      customer, address,
      cellNumber: cell,
      products: products,
      donationAmount: donation,
      paymentMethod,
      checkNumber: chkNumber,
      notes,
      createdAt: new Date().toISOString(),
      entered_by_id: enteredById,
      entered_by_name: enteredByName,
      teamName: teamName,
      teamCode: teamCode,
      geo
    };
    // If we're editing an existing order, handle update vs create
    try{
      if (window._editingOrder && window._editingOrder.orderId) {
        // update existing
        const editing = window._editingOrder;
          order.id = editing.orderId; // preserve id
        if (editing.local) {
          // update local queued order
          await Storage.update(order);
          renderOrders();
          syncStatus && (syncStatus.textContent='Local order updated');
          // Show confirmation popup (don't clear form yet - OK button will do it)
          showOrderConfirmation(order, true);
          if (navigator.onLine) trySync();
          return;
        } else {
          // remote edit: if online, attempt PUT; if offline, queue op and save local copy
          const payload = {
            order_id: order.id,
            user_id: order.entered_by_id || localStorage.getItem('teamName') || 'team',
            customer: order.customer,
            address: order.address,
            notes: order.notes,
            products: order.products || [],
            donationAmount: order.donationAmount,
            paymentMethod: order.paymentMethod,
            checkNumber: order.checkNumber,
            cellNumber: order.cellNumber,
            createdAt: order.createdAt,
            entered_by_id: order.entered_by_id || '',
            entered_by_name: order.entered_by_name || '',
            team_name: order.teamName || localStorage.getItem('teamName') || '',
            team_code: order.teamCode || localStorage.getItem('teamCode') || '',
            geo: order.geo || null
          };
          if (navigator.onLine) {
            try{
              const url = apiBase ? (apiBase + '/orders/' + encodeURIComponent(order.id)) : '/wp-json/order-manager/v1/orders/' + encodeURIComponent(order.id);
              const resp = await fetch(url, { method: 'PUT', headers: { 'Content-Type':'application/json', 'X-Team-Name': localStorage.getItem('teamName')||'', 'X-Access-Code': localStorage.getItem('teamCode')||'' }, body: JSON.stringify(payload) });
              if (resp.ok) { alert('Order updated on server'); }
              else { alert('Update failed: ' + resp.status); }
            }catch(e){ await Storage.queueOperation({ type:'update', order_id: order.id, payload }); alert('Offline or error: update queued'); }
          } else {
            await Storage.queueOperation({ type:'update', order_id: order.id, payload }); alert('Offline: update queued');
          }
          // store local copy for UI
          try{ await Storage.update(order); }catch(e){}
          renderOrders();
          // Show confirmation popup (don't clear form yet - OK button will do it)
          showOrderConfirmation(order, true);
          if (navigator.onLine) trySync();
          return;
        }
      }
    }catch(e){ console.warn('update flow error', e); }

    // default: create new queued order
    await Storage.add(order);
    renderOrders();
    syncStatus && (syncStatus.textContent='Queued for sync');
    // Show confirmation popup (don't clear form yet - OK button will do it)
    showOrderConfirmation(order, false);
    if (navigator.onLine) trySync();
  });

  async function trySync(){
    // First, process any queued operations (update/delete) before sending creates
    try{
      const ops = await Storage.allQueuedOps();
      if (ops && ops.length) {
        if (!navigator.onLine) { syncStatus && (syncStatus.textContent='Waiting for network'); return; }
        syncStatus && (syncStatus.textContent='Processing queued operations...');
        const teamName = localStorage.getItem('teamName')||'';
        const teamCode = localStorage.getItem('teamCode')||'';
        for (const op of ops) {
          try{
            if (op.type === 'delete'){
              const url = apiBase ? (apiBase + '/orders/' + encodeURIComponent(op.order_id)) : '/wp-json/order-manager/v1/orders/' + encodeURIComponent(op.order_id);
              const resp = await fetch(url, { method: 'DELETE', headers: { 'X-Team-Name': teamName, 'X-Access-Code': teamCode } });
              if (resp.ok) { await Storage.removeQueuedOp(op._id); }
            } else if (op.type === 'update'){
              const url = apiBase ? (apiBase + '/orders/' + encodeURIComponent(op.order_id)) : '/wp-json/order-manager/v1/orders/' + encodeURIComponent(op.order_id);
              const resp = await fetch(url, { method: 'PUT', headers: { 'Content-Type':'application/json', 'X-Team-Name': teamName, 'X-Access-Code': teamCode }, body: JSON.stringify(op.payload) });
              if (resp.ok) { 
                await Storage.removeQueuedOp(op._id); 
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
            products: order.products || [],
            donationAmount: order.donationAmount,
            paymentMethod: order.paymentMethod,
            checkNumber: order.checkNumber,
            cellNumber: order.cellNumber,
            createdAt: order.createdAt,
            entered_by_id: order.entered_by_id || '',
            entered_by_name: order.entered_by_name || '',
            team_name: order.teamName || teamName || localStorage.getItem('teamName') || '',
            team_code: order.teamCode || teamCode || localStorage.getItem('teamCode') || '',
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
            let errorDetail = '';
            try {
              const errText = await resp.text();
              errorDetail = errText ? ': ' + errText.substring(0, 200) : '';
            } catch(e) {}
            const errorMsg = 'Sync failed for order ' + (order.customer || order.id) + ' - HTTP ' + resp.status + errorDetail;
            syncStatus && (syncStatus.textContent = errorMsg);
            if (window.smShowSnackbar) {
              window.smShowSnackbar(errorMsg, { timeout: 10000 });
            } else {
              alert(errorMsg);
            }
            return; // stop syncing on first error
          }
        } catch (err) {
          console.warn('sync failed for', order.id, err);
          const errorMsg = 'Sync failed for order ' + (order.customer || order.id) + ': ' + (err.message || err.toString());
          syncStatus && (syncStatus.textContent = errorMsg);
          if (window.smShowSnackbar) {
            window.smShowSnackbar(errorMsg, { timeout: 10000 });
          } else {
            alert(errorMsg);
          }
          return;
        }
      }
      syncStatus && (syncStatus.textContent='All queued orders synced');
      renderOrders();
    }catch(e){ console.warn('trySync error', e); }
  }

  // Service worker registration (prefer pluginBase so SW is loaded from plugin assets)
  (function(){ if ('serviceWorker' in navigator) { const swBase = pluginBase || portalBase || '/'; const swPath = (swBase.endsWith('/') ? swBase : (swBase + '/')) + 'service-worker.js'; const scope = (swBase.endsWith('/') ? swBase : (swBase + '/')); navigator.serviceWorker.register(swPath, { scope }).then(()=>console.log('sw registered')).catch((e)=>console.warn('sw register failed', e)); } })();

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

    modal.innerHTML = `<div class="modal-header"><strong>My orders (${memberName||memberId||'You'})</strong><div><button id="closeMyOrdersBtn" class="sm-btn">Close</button></div></div><div class="modal-body"><div class="modal-col"> <h4>Local (queued)</h4>${localHtml}</div><div class="modal-col"><h4>Remote (synced)</h4>${remoteHtml}</div></div>`;
    const closeBtn = qs('#closeMyOrdersBtn'); if (closeBtn) closeBtn.addEventListener('click', ()=>{ modal.classList.add('hidden'); });

    // Attach delegated handlers for edit/delete
    modal.querySelectorAll('.edit-order').forEach(b=>{ b.addEventListener('click', async (e)=>{ const id = b.getAttribute('data-local-id'); const ord = await Storage.get(id); if (ord) enterEditMode(ord, { local:true }); modal.classList.add('hidden'); }); });
    modal.querySelectorAll('.delete-order').forEach(b=>{ b.addEventListener('click', async (e)=>{ const id = b.getAttribute('data-local-id'); if (!confirm('Delete this local queued order?')) return; // perform deletion but allow undo
  const ord = await Storage.get(id); if (!ord) return; await Storage.remove(id); await renderOrders(); await renderInlay(); window.showUndoSnackbar('Local order deleted', async ()=>{ try{ await Storage.add(ord); await renderOrders(); await renderInlay(); }catch(e){} });
    }); });
    modal.querySelectorAll('.edit-order-remote').forEach(b=>{ b.addEventListener('click', async ()=>{ const id = b.getAttribute('data-remote-id'); // find remote order data
      const remote = remoteFiltered.find(r=>String(r.order_id||r.order_id) === String(id));
      if (remote){ const od = (remote.order_data && typeof remote.order_data === 'string') ? (function(){ try{ return JSON.parse(remote.order_data); }catch(e){ return {}; } })() : (remote.order_data || {}); const orderObj = Object.assign({}, od, { id: remote.order_id || remote.order_id }); enterEditMode(orderObj, { local:false, remoteRaw: remote }); modal.classList.add('hidden'); }
    }); });
    modal.querySelectorAll('.delete-order-remote').forEach(b=>{ b.addEventListener('click', async ()=>{ const id = b.getAttribute('data-remote-id'); if (!confirm('Delete this order from server?')) return; // attempt delete now or queue
      const teamName = localStorage.getItem('teamName')||''; const teamCode = localStorage.getItem('teamCode')||'';
      if (navigator.onLine) {
        try{ const url = apiBase ? (apiBase + '/orders/' + encodeURIComponent(id)) : '/wp-json/order-manager/v1/orders/' + encodeURIComponent(id); const resp = await fetch(url, { method: 'DELETE', headers: { 'X-Team-Name': teamName, 'X-Access-Code': teamCode } }); if (resp.ok) { alert('Order deleted'); } else { alert('Delete failed: ' + resp.status); } }catch(e){ alert('Delete failed: ' + e); }
      } else {
        const opId = await Storage.queueOperation({ type:'delete', order_id: id });
  window.showUndoSnackbar('Delete queued (will run when online)', async ()=>{ try{ if (opId) await Storage.removeQueuedOp(opId); await renderOrders(); await renderInlay(); }catch(e){} });
      }
      // refresh remote list
      try{ await fetchRemoteOrders(); }catch(e){}
      // close modal and refresh UI
      modal.classList.add('hidden');
    }); });
    modal.classList.remove('hidden');
  // manual helpers removed
  }

  const myOrdersBtn = qs('#myOrdersBtn'); if (myOrdersBtn) myOrdersBtn.addEventListener('click', showMyOrders);
  const eodBtn = qs('#eodBtn'); if (eodBtn) eodBtn.addEventListener('click', async ()=>{ try{ qs('#eodInlay') || ensureEodExists(); qs('#eodInlay').classList.remove('hidden'); await renderEod(); }catch(e){ console.warn('EOD open error', e); } });
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

// Debug helper removed — plain text address field in use.
