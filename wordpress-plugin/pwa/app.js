// BKMB Subsales PWA client (plugin-hosted)
(function(){
  // Prefer config injected by WP; fall back to localStorage
  const apiBase = (window.BKMB_PWA_CONFIG && window.BKMB_PWA_CONFIG.apiBase) || localStorage.getItem('API_BASE_URL') || '';
  const pluginBase = (window.BKMB_PWA_CONFIG && window.BKMB_PWA_CONFIG.pluginBase) || '';
  const portalBase = (window.BKMB_PWA_CONFIG && window.BKMB_PWA_CONFIG.portalBase) || '';

  // Storage (IndexedDB preferred)
  const Storage = (function(){
    const DB_NAME = 'bkmb-pwa-db';
    const STORE = 'orders';
    function idbOpen(){
      return new Promise((resolve)=>{
        if (!('indexedDB' in window)) return resolve(null);
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
        req.onsuccess = ()=>resolve(req.result);
        req.onerror = ()=>resolve(null);
      });
    }
    async function getDB(){ if (!window._bkmb_db) window._bkmb_db = await idbOpen(); return window._bkmb_db; }
    async function add(order){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).put(order); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); list.push(order); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    async function all(){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readonly'); const req = tx.objectStore(STORE).getAll(); req.onsuccess = ()=>res(req.result || []); req.onerror = ()=>res([]); }); } return JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); }
    async function remove(id){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).delete(id); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]').filter(o=>o.id!==id); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    return { add, all, remove };
  })();

  // DOM
  const qs = s => document.querySelector(s);
  const loginSection = qs('#loginSection');
  const appSection = qs('#appSection');
  const loginBtn = qs('#loginBtn');
  const saveOrderBtn = qs('#saveOrderBtn');
  const ordersList = qs('#ordersList');
  const networkStatus = qs('#networkStatus');
  const syncStatus = qs('#syncStatus');
  const installBox = qs('#installBox');
  const installBtn = qs('#installBtn');

  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; installBox.classList.remove('hidden'); });
  installBtn && installBtn.addEventListener('click', async ()=>{ if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; installBox.classList.add('hidden'); });

  function updateNetworkUI(){ if (navigator.onLine) { networkStatus.textContent='Online'; networkStatus.className='status-online'; } else { networkStatus.textContent='Offline'; networkStatus.className='status-offline'; } }
  window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); });
  window.addEventListener('offline', updateNetworkUI);
  updateNetworkUI();

  async function renderOrders(){ const list = await Storage.all(); if (!list || !list.length) { ordersList.innerHTML = '<div>No local orders</div>'; return; } ordersList.innerHTML = list.map(o=>{ const qtyInfo = `Turkey: ${o.turkeyQty||0} — Ham: ${o.hamQty||0} — Combo: ${o.comboQty||0}`; const donation = o.donationAmount ? `Donation: $${parseFloat(o.donationAmount).toFixed(2)}` : ''; return `<div class="order"><strong>${o.customer}</strong><div>${o.address}</div><div>${qtyInfo}</div><div>${donation}</div><div>${o.notes||''}</div><small>id:${o.id}</small></div>`; }).join(''); }

  async function trySync(){ const list = await Storage.all(); if (!list.length) { syncStatus.textContent = 'No queued orders'; return; } if (!navigator.onLine) { syncStatus.textContent = 'Waiting for network'; return; } syncStatus.textContent = 'Syncing...'; const teamName = localStorage.getItem('teamName'); const teamCode = localStorage.getItem('teamCode'); for (const order of list) { try { const resp = await fetch((apiBase || '') + '/wp-json/order-manager/v1/orders', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Team-Name': teamName || '', 'X-Access-Code': teamCode || '' }, body: JSON.stringify(order) }); if (resp.ok) { await Storage.remove(order.id); } } catch (err) { console.warn('sync failed for', order.id, err); syncStatus.textContent = 'Sync error, will retry'; return; } } syncStatus.textContent = 'All queued orders synced'; renderOrders(); }

  loginBtn.addEventListener('click', ()=>{ const team = qs('#teamName').value.trim(); const code = qs('#teamCode').value.trim(); if (!team || !code) return alert('Please provide team name and code'); localStorage.setItem('teamName', team); localStorage.setItem('teamCode', code); loginSection.classList.add('hidden'); appSection.classList.remove('hidden'); if (deferredPrompt) installBox.classList.remove('hidden'); });

  saveOrderBtn.addEventListener('click', async ()=>{ const customer = qs('#customerName').value.trim(); const address = qs('#address').value.trim(); const notes = qs('#notes').value.trim(); const turkeyQty = parseInt(qs('#turkeyQty') && qs('#turkeyQty').value,10) || 0; const hamQty = parseInt(qs('#hamQty') && qs('#hamQty').value,10) || 0; const comboQty = parseInt(qs('#comboQty') && qs('#comboQty').value,10) || 0; const donationAmount = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0; if (!customer || !address) return alert('Customer and address required'); const order = { id: 'o_'+Date.now(), customer, address, turkeyQty, hamQty, comboQty, donationAmount, notes, createdAt: new Date().toISOString() }; await Storage.add(order); renderOrders(); syncStatus.textContent = 'Queued for sync'; if (navigator.onLine) trySync(); });

  // Register service worker if config was not used elsewhere
  (function(){
    if ('serviceWorker' in navigator) {
      // Prefer portalBase (site slug) if provided, otherwise pluginBase, otherwise site-root
      const swPath = (portalBase ? portalBase + 'service-worker.js' : (pluginBase ? pluginBase + 'service-worker.js' : '/service-worker.js'));
      const swScope = (portalBase ? portalBase : (pluginBase ? pluginBase : '/'));
      navigator.serviceWorker.register(swPath, { scope: swScope }).then(()=>console.log('sw registered')).catch(()=>{});
    }
  })();

  // Init
  renderOrders();
  trySync();

})();
/* BKMB Subsales — plugin-embedded PWA client
   This version expects BKMB_PWA_SETTINGS injected by the plugin with:
   - api_base: REST API base (e.g. https://example.com/wp-json/order-manager/v1)
   - plugin_url: URL to the plugin's pwa/ folder (ending with /)
*/

(function(){
  const apiBase = (typeof BKMB_PWA_SETTINGS !== 'undefined' && BKMB_PWA_SETTINGS.api_base) ? BKMB_PWA_SETTINGS.api_base : '/wp-json/order-manager/v1';
  const pluginUrl = (typeof BKMB_PWA_SETTINGS !== 'undefined' && BKMB_PWA_SETTINGS.plugin_url) ? BKMB_PWA_SETTINGS.plugin_url : '';

  // Service worker registration preferred order: portalBase (site slug) -> pluginUrl -> none
  const portalBase2 = (window.BKMB_PWA_CONFIG && window.BKMB_PWA_CONFIG.portalBase) || '';
  const swBase = portalBase2 || pluginUrl;
  if ('serviceWorker' in navigator && swBase) {
    navigator.serviceWorker.register(swBase + 'service-worker.js', { scope: swBase })
      .then(()=>console.log('BKMB PWA service worker registered'))
      .catch(err=>console.warn('BKMB PWA sw failed', err));
  }

  // Simple storage abstraction (IndexedDB with localStorage fallback)
  const Storage = (function(){
    const DB_NAME = 'bkmb-pwa-db';
    const STORE = 'orders';

    function idbOpen(){
      return new Promise((resolve)=>{
        if (!('indexedDB' in window)) return resolve(null);
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
        req.onsuccess = ()=>resolve(req.result);
        req.onerror = ()=>resolve(null);
      });
    }
    async function getDB(){ if (!window._bkmb_db) window._bkmb_db = await idbOpen(); return window._bkmb_db; }
    async function add(order){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE,'readwrite'); tx.objectStore(STORE).put(order); tx.oncomplete=()=>res(true); tx.onerror=()=>res(false); }); } const list=JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); list.push(order); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    async function all(){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx=db.transaction(STORE,'readonly'); const req=tx.objectStore(STORE).getAll(); req.onsuccess=()=>res(req.result||[]); req.onerror=()=>res([]); }); } return JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); }
    async function remove(id){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx=db.transaction(STORE,'readwrite'); tx.objectStore(STORE).delete(id); tx.oncomplete=()=>res(true); tx.onerror=()=>res(false); }); } const list=JSON.parse(localStorage.getItem('bkmb_orders')||'[]').filter(o=>o.id!==id); localStorage.setItem('bkmb_orders', JSON.stringify(list)); return true; }
    return { add, all, remove };
  })();

  // DOM helpers; if the plugin placed a root element, use it, otherwise create one
  function qs(s) { return document.querySelector(s); }
  function q(s){ return document.createElement(s); }

  const container = qs('#bkmb-pwa-root') || (function(){ const d=q('div'); d.id='bkmb-pwa-root'; document.body.appendChild(d); return d; })();

  // Build UI inside container if not already present
  if (!qs('#bkmb-pwa-ui')) {
    container.innerHTML = `
      <div id="bkmb-pwa-ui">
        <header><h1>BKMB Subsales</h1><div id="installBox" style="display:none"><button id="installBtn">Install App</button></div></header>
        <section id="loginSection">
          <h2>Team Login</h2>
          <input id="teamName" placeholder="Team name" />
          <input id="teamCode" placeholder="Access code" />
          <button id="loginBtn">Login</button>
        </section>
        <section id="appSection" style="display:none">
          <div style="display:flex;gap:12px">
            <div style="flex:1">
              <h2>Create Order</h2>
              <input id="customerName" placeholder="Customer name" />
              <input id="address" placeholder="Address" />
              <div style="display:flex;gap:8px;margin-top:6px;margin-bottom:6px">
                <div><label>Turkey</label><input id="turkeyQty" type="number" min="0" value="0" /></div>
                <div><label>Ham</label><input id="hamQty" type="number" min="0" value="0" /></div>
                <div><label>Combo</label><input id="comboQty" type="number" min="0" value="0" /></div>
              </div>
              <input id="donationAmount" type="number" min="0" step="0.01" value="0" placeholder="Donation amount (USD)" />
              <textarea id="notes" placeholder="Notes (optional)"></textarea>
              <button id="saveOrderBtn">Save Order</button>
            </div>
            <div style="width:260px">
              <h3>Status</h3>
              <div id="networkStatus">Offline</div>
              <div id="syncStatus">Not synced</div>
            </div>
          </div>
          <h3>Local orders</h3>
          <div id="ordersList"></div>
        </section>
      </div>`;
  }

  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e)=>{ e.preventDefault(); deferredPrompt = e; const ib=qs('#installBox'); if (ib) ib.style.display='block'; });
  qs('#installBtn') && qs('#installBtn').addEventListener('click', async ()=>{ if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt=null; qs('#installBox').style.display='none'; });

  function updateNetworkUI(){ const ns=qs('#networkStatus'); if (!ns) return; if (navigator.onLine){ ns.textContent='Online'; ns.style.color='green'; } else { ns.textContent='Offline'; ns.style.color='orange'; } }
  window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); }); window.addEventListener('offline', updateNetworkUI); updateNetworkUI();

  async function renderOrders(){ const list = await Storage.all(); const root = qs('#ordersList'); if (!root) return; if (!list || !list.length) { root.innerHTML='<div>No local orders</div>'; return; } root.innerHTML = list.map(o=>{ const qtyInfo = `Turkey: ${o.turkeyQty||0} — Ham: ${o.hamQty||0} — Combo: ${o.comboQty||0}`; const donation = o.donationAmount ? `Donation: $${parseFloat(o.donationAmount).toFixed(2)}` : ''; return `<div style="border:1px solid #ddd;padding:8px;margin-bottom:8px"><strong>${o.customer}</strong><div>${o.address}</div><div>${qtyInfo}</div><div>${donation}</div><div>${o.notes||''}</div><small>${o.id}</small></div>`; }).join(''); }

  async function trySync(){ const list = await Storage.all(); if (!list.length){ qs('#syncStatus') && (qs('#syncStatus').textContent='No queued orders'); return; } if (!navigator.onLine){ qs('#syncStatus') && (qs('#syncStatus').textContent='Waiting for network'); return; } qs('#syncStatus') && (qs('#syncStatus').textContent='Syncing...'); const teamName = localStorage.getItem('teamName'); const teamCode = localStorage.getItem('teamCode'); for (const order of list){ try{ const resp = await fetch(apiBase + '/orders', { method: 'POST', headers: { 'Content-Type':'application/json', 'X-Team-Name': teamName||'', 'X-Access-Code': teamCode||'' }, body: JSON.stringify({ order_id: order.id, user_id: teamName||'team', customer: order.customer, address: order.address, notes: order.notes, createdAt: order.createdAt }) }); if (resp.ok) { await Storage.remove(order.id); } } catch(err){ console.warn('sync failed', err); qs('#syncStatus') && (qs('#syncStatus').textContent='Sync error, will retry'); return; } } qs('#syncStatus') && (qs('#syncStatus').textContent='All queued orders synced'); renderOrders(); }

  // Login and save order handlers
  qs('#loginBtn').addEventListener('click', ()=>{ const team=qs('#teamName').value.trim(); const code=qs('#teamCode').value.trim(); if (!team||!code) return alert('Provide team and code'); localStorage.setItem('teamName', team); localStorage.setItem('teamCode', code); qs('#loginSection').style.display='none'; qs('#appSection').style.display='block'; if (deferredPrompt) qs('#installBox').style.display='block'; trySync(); });

  qs('#saveOrderBtn').addEventListener('click', async ()=>{ const customer=qs('#customerName').value.trim(); const address=qs('#address').value.trim(); const notes=qs('#notes').value.trim(); const turkeyQty = parseInt(qs('#turkeyQty') && qs('#turkeyQty').value,10) || 0; const hamQty = parseInt(qs('#hamQty') && qs('#hamQty').value,10) || 0; const comboQty = parseInt(qs('#comboQty') && qs('#comboQty').value,10) || 0; const donationAmount = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0; if (!customer||!address) return alert('Customer and address required'); const order={ id:'o_'+Date.now(), customer, address, turkeyQty, hamQty, comboQty, donationAmount, notes, createdAt:new Date().toISOString() }; await Storage.add(order); renderOrders(); qs('#syncStatus') && (qs('#syncStatus').textContent='Queued for sync'); if (navigator.onLine) trySync(); });

  // initial
  renderOrders(); trySync();

})();
