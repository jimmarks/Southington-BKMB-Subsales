// BKMB Subsales PWA client (plugin-hosted) — consolidated clean implementation
(function(){
  const cfg = window.BKMB_PWA_CONFIG || {};
  const apiBase = cfg.apiBase || cfg.api_base || (localStorage.getItem('API_BASE_URL') || '');
  const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
  const portalBase = cfg.portalBase || '';
  const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';

  const qs = s => document.querySelector(s);

  // Ensure root container
  const root = qs('#bkmb-pwa-root') || (function(){ const d=document.createElement('div'); d.id='bkmb-pwa-root'; document.body.appendChild(d); return d; })();

  // Inject UI if shortcode didn't provide it
  if (!qs('#bkmb-pwa-ui')) {
    root.innerHTML = `
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
              <label>Customer name</label>
              <input id="customerName" placeholder="Customer name" />
              <label>Address</label>
              <input id="address" placeholder="Address" />
              <label>Cell number</label>
              <input id="cellNumber" type="tel" inputmode="numeric" pattern="[0-9]*" placeholder="Cell number" />
              <div style="display:flex;gap:8px;margin-top:6px;margin-bottom:6px">
                <div><label>Turkey</label><input id="turkeyQty" type="number" min="0" value="0" /></div>
                <div><label>Ham</label><input id="hamQty" type="number" min="0" value="0" /></div>
                <div><label>Combo</label><input id="comboQty" type="number" min="0" value="0" /></div>
              </div>
              <label>Donation amount (USD)</label>
              <input id="donationAmount" type="number" min="0" step="0.01" value="0" placeholder="$0.00" />
              <div style="margin-top:6px"><strong>Order total: $<span id="orderTotal">0.00</span></strong></div>
              <div style="margin-top:8px">
                <label><input type="checkbox" id="payCheck" /> Pay by check</label>
                <label style="margin-left:12px"><input type="checkbox" id="payCash" /> Pay by cash</label>
              </div>
              <div id="checkNumberRow" style="display:none;margin-top:6px"><label>Check number</label><input id="checkNumber" placeholder="Check number" /></div>
              <label>Notes</label>
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

  // Install prompt handling
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e)=>{ e.preventDefault(); deferredPrompt = e; installBox && (installBox.style.display='block'); });
  installBtn && installBtn.addEventListener('click', async ()=>{ if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; installBox && (installBox.style.display='none'); });

  function updateNetworkUI(){ if (networkStatus) { if (navigator.onLine) { networkStatus.textContent='Online'; networkStatus.style.color='green'; } else { networkStatus.textContent='Offline'; networkStatus.style.color='orange'; } } }
  window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); });
  window.addEventListener('offline', updateNetworkUI);
  updateNetworkUI();

  function computeTotal(){ const t = parseInt(turkeyQty && turkeyQty.value,10) || 0; const h = parseInt(hamQty && hamQty.value,10) || 0; const c = parseInt(comboQty && comboQty.value,10) || 0; const d = parseFloat(donationAmount && donationAmount.value) || 0; const perItem = 10.00; const total = (t + h + c) * perItem + d; if (orderTotalEl) orderTotalEl.textContent = total.toFixed(2); return total; }
  [turkeyQty, hamQty, comboQty, donationAmount].forEach(el=>{ if (!el) return; el.addEventListener('input', computeTotal); });

  if (payCheck) payCheck.addEventListener('change', ()=>{ if (payCheck.checked) { checkNumberRow.style.display='block'; if (payCash) payCash.checked=false; } else { checkNumberRow.style.display='none'; } });
  if (payCash) payCash.addEventListener('change', ()=>{ if (payCash.checked) { if (payCheck) { payCheck.checked=false; checkNumberRow.style.display='none'; } } });

  async function renderOrders(){ const list = await Storage.all(); if (!ordersList) return; if (!list || !list.length) { ordersList.innerHTML = '<div>No local orders</div>'; return; } ordersList.innerHTML = list.map(o=>{ const qtyInfo = `Turkey: ${o.turkeyQty||0} — Ham: ${o.hamQty||0} — Combo: ${o.comboQty||0}`; const donation = o.donationAmount ? `Donation: $${parseFloat(o.donationAmount).toFixed(2)}` : ''; const payment = o.paymentMethod ? `Payment: ${o.paymentMethod}${o.checkNumber?(' (check #'+o.checkNumber+')'):''}` : ''; return `<div style="border:1px solid #ddd;padding:8px;margin-bottom:8px"><strong>${o.customer}</strong><div>${o.address}</div><div>${qtyInfo}</div><div>${donation}</div><div>${payment}</div><div>${o.notes||''}</div><small>${o.id}</small></div>`; }).join(''); }

  loginBtn && loginBtn.addEventListener('click', ()=>{ const team = qs('#teamName').value.trim(); const code = qs('#teamCode').value.trim(); if (!team||!code) return alert('Team and code required'); localStorage.setItem('teamName', team); localStorage.setItem('teamCode', code); loginSection && (loginSection.style.display='none'); appSection && (appSection.style.display='block'); if (deferredPrompt) installBox && (installBox.style.display='block'); trySync(); });

  saveOrderBtn && saveOrderBtn.addEventListener('click', async ()=>{ const customer = qs('#customerName').value.trim(); const address = qs('#address').value.trim(); if (!customer||!address) return alert('Customer and address required'); const notes = qs('#notes').value||''; const cell = qs('#cellNumber') && qs('#cellNumber').value.trim(); const turkey = parseInt(qs('#turkeyQty') && qs('#turkeyQty').value,10) || 0; const ham = parseInt(qs('#hamQty') && qs('#hamQty').value,10) || 0; const combo = parseInt(qs('#comboQty') && qs('#comboQty').value,10) || 0; const donation = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0; const paymentMethod = (payCheck && payCheck.checked) ? 'check' : ((payCash && payCash.checked) ? 'cash' : ''); const chkNumber = (checkNumber && checkNumber.value) ? checkNumber.value.trim() : ''; const order = { id: 'o_'+Date.now(), customer, address, cellNumber: cell, turkeyQty: turkey, hamQty: ham, comboQty: combo, donationAmount: donation, paymentMethod, checkNumber: chkNumber, notes, createdAt: new Date().toISOString() }; await Storage.add(order); renderOrders(); syncStatus && (syncStatus.textContent='Queued for sync'); if (navigator.onLine) trySync(); });

  async function trySync(){ const list = await Storage.all(); if (!list || !list.length) { syncStatus && (syncStatus.textContent='No queued orders'); return; } if (!navigator.onLine) { syncStatus && (syncStatus.textContent='Waiting for network'); return; } syncStatus && (syncStatus.textContent='Syncing...'); const teamName = localStorage.getItem('teamName'); const teamCode = localStorage.getItem('teamCode'); for (const order of list) { try { const resp = await fetch((apiBase || '') + '/orders', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Team-Name': teamName || '', 'X-Access-Code': teamCode || '' }, body: JSON.stringify({ order_id: order.id, user_id: teamName||'team', customer: order.customer, address: order.address, notes: order.notes, turkeyQty: order.turkeyQty, hamQty: order.hamQty, comboQty: order.comboQty, donationAmount: order.donationAmount, paymentMethod: order.paymentMethod, checkNumber: order.checkNumber, cellNumber: order.cellNumber, createdAt: order.createdAt }) }); if (resp.ok) { await Storage.remove(order.id); } } catch (err) { console.warn('sync failed for', order.id, err); syncStatus && (syncStatus.textContent='Sync error, will retry'); return; } } syncStatus && (syncStatus.textContent='All queued orders synced'); renderOrders(); }

  // Service worker registration (scope provided by portalBase/pluginBase)
  (function(){ if ('serviceWorker' in navigator) { const swBase = portalBase || pluginBase || '/'; const swPath = (swBase.endsWith('/') ? swBase : (swBase + '/')) + 'service-worker.js'; const scope = (swBase.endsWith('/') ? swBase : (swBase + '/')); navigator.serviceWorker.register(swPath, { scope }).then(()=>console.log('sw registered')).catch((e)=>console.warn('sw register failed', e)); } })();

  // Google Places autocomplete loader
  window.initBKMBPlaces = function(){ try { const addr = qs('#address'); if (!addr) return; const ac = new google.maps.places.Autocomplete(addr, { types: ['geocode'] }); ac.setFields(['formatted_address', 'geometry']); ac.addListener('place_changed', ()=>{ const place = ac.getPlace(); if (place && place.formatted_address) addr.value = place.formatted_address; }); } catch(e){ console.warn('places init failed', e); } };
  if (googleMapsApiKey) { const s = document.createElement('script'); s.src = 'https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(googleMapsApiKey)+'&libraries=places&callback=initBKMBPlaces'; s.async = true; s.defer = true; document.head.appendChild(s); }

  // Boot
  renderOrders();
  trySync();

})();
// BKMB Subsales PWA client (plugin-hosted)
(function(){
  // Configuration injected by the plugin
  const cfg = window.BKMB_PWA_CONFIG || {};
  const apiBase = cfg.apiBase || cfg.api_base || (localStorage.getItem('API_BASE_URL') || '');
  const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
  const portalBase = cfg.portalBase || '';
  const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';

  // Storage (IndexedDB preferred, localStorage fallback)
  const Storage = (function(){
    const DB_NAME = 'bkmb-pwa-db';
    const STORE = 'orders';
    function idbOpen(){
      return new Promise((resolve)=>{
        if (!('indexedDB' in window)) return resolve(null);
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => { try { req.result.createObjectStore(STORE, { keyPath: 'id' }); } catch(e){} };
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

  // Helpers
  const qs = s => document.querySelector(s);

  // Ensure a root container exists
  const root = qs('#bkmb-pwa-root') || (function(){ const d = document.createElement('div'); d.id='bkmb-pwa-root'; document.body.appendChild(d); return d; })();

  if (!qs('#bkmb-pwa-ui')) {
    root.innerHTML = `
      <div id="bkmb-pwa-ui">
        <header><h1>BKMB Subsales</h1><div id="installBox" class="hidden"><button id="installBtn">Install App</button></div></header>
        <section id="loginSection">
          <h2>Team Login</h2>
          <input id="teamName" placeholder="Team name" />
          <input id="teamCode" placeholder="Access code" />
          <button id="loginBtn">Login</button>
        </section>
        <section id="appSection" style="display:none">
          <div style="display:flex;gap:12px">
            <div style="flex:1">
              // BKMB Subsales PWA client (plugin-hosted) — single clean implementation
              (function(){
                const cfg = window.BKMB_PWA_CONFIG || {};
                const apiBase = cfg.apiBase || cfg.api_base || (localStorage.getItem('API_BASE_URL') || '');
                const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
                const portalBase = cfg.portalBase || '';
                const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';

                const qs = s => document.querySelector(s);

                const root = qs('#bkmb-pwa-root') || (function(){ const d=document.createElement('div'); d.id='bkmb-pwa-root'; document.body.appendChild(d); return d; })();

                // Inject UI if not present
                if (!qs('#bkmb-pwa-ui')) {
                  root.innerHTML = `
                    <div id="bkmb-pwa-ui">
                      <header><h1>BKMB Subsales</h1></header>
                      <section id="loginSection">
                        <input id="teamName" placeholder="Team name" />
                        <input id="teamCode" placeholder="Access code" />
                        <button id="loginBtn">Login</button>
                      </section>
                      <section id="appSection" style="display:none">
                        <input id="customerName" placeholder="Customer name" />
                        <input id="address" placeholder="Address" />
                        <input id="cellNumber" type="tel" placeholder="Cell number" />
                        <div style="display:flex;gap:8px"><input id="turkeyQty" type="number" value="0" /><input id="hamQty" type="number" value="0" /><input id="comboQty" type="number" value="0" /></div>
                        <input id="donationAmount" type="number" step="0.01" value="0" />
                        <div>Order total: $<span id="orderTotal">0.00</span></div>
                        <label><input id="payCheck" type="checkbox" /> Pay by check</label>
                        <label><input id="payCash" type="checkbox" /> Pay by cash</label>
                        <div id="checkNumberRow" style="display:none"><input id="checkNumber" placeholder="Check number" /></div>
                        <textarea id="notes"></textarea>
                        <button id="saveOrderBtn">Save Order</button>
                        <div id="syncStatus"></div>
                        <div id="ordersList"></div>
                      </section>
                    </div>`;
                }

                // Simple storage (localStorage)
                const Storage = {
                  async add(o){ const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); list.push(o); localStorage.setItem('bkmb_orders', JSON.stringify(list)); },
                  async all(){ return JSON.parse(localStorage.getItem('bkmb_orders')||'[]'); },
                  async remove(id){ const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]').filter(x=>x.id!==id); localStorage.setItem('bkmb_orders', JSON.stringify(list)); }
                };

                // Elements
                const loginBtn = qs('#loginBtn');
                const saveOrderBtn = qs('#saveOrderBtn');
                const orderTotalEl = qs('#orderTotal');
                const turkeyQty = qs('#turkeyQty');
                const hamQty = qs('#hamQty');
                const comboQty = qs('#comboQty');
                const donationAmount = qs('#donationAmount');
                const payCheck = qs('#payCheck');
                const payCash = qs('#payCash');
                const checkNumberRow = qs('#checkNumberRow');

                function computeTotal(){ const t = parseInt(turkeyQty && turkeyQty.value,10)||0; const h = parseInt(hamQty && hamQty.value,10)||0; const c = parseInt(comboQty && comboQty.value,10)||0; const d = parseFloat(donationAmount && donationAmount.value)||0; const total = (t+h+c)*10 + d; if (orderTotalEl) orderTotalEl.textContent = total.toFixed(2); }
                [turkeyQty, hamQty, comboQty, donationAmount].forEach(el=>el && el.addEventListener('input', computeTotal));

                if (payCheck) payCheck.addEventListener('change', ()=>{ if (payCheck.checked) { checkNumberRow.style.display='block'; if (payCash) payCash.checked=false; } else { checkNumberRow.style.display='none'; } });
                if (payCash) payCash.addEventListener('change', ()=>{ if (payCash.checked) { if (payCheck) payCheck.checked=false; checkNumberRow.style.display='none'; } });

                async function renderOrders(){ const list = await Storage.all(); const r = qs('#ordersList'); if (!r) return; if (!list.length) { r.innerHTML = '<div>No local orders</div>'; return; } r.innerHTML = list.map(o=>`<div style="border:1px solid #ddd;padding:8px;margin-bottom:8px"><strong>${o.customer}</strong><div>${o.address}</div><small>${o.id}</small></div>`).join(''); }

                loginBtn && loginBtn.addEventListener('click', ()=>{ const team = qs('#teamName').value.trim(); const code = qs('#teamCode').value.trim(); if (!team||!code) return alert('Team and code required'); localStorage.setItem('teamName', team); localStorage.setItem('teamCode', code); qs('#loginSection').style.display='none'; qs('#appSection').style.display='block'; });

                saveOrderBtn && saveOrderBtn.addEventListener('click', async ()=>{ const customer = qs('#customerName').value.trim(); const address = qs('#address').value.trim(); if (!customer||!address) return alert('Customer and address required'); const order = { id:'o_'+Date.now(), customer, address, turkeyQty: parseInt(qs('#turkeyQty') && qs('#turkeyQty').value,10)||0, hamQty: parseInt(qs('#hamQty') && qs('#hamQty').value,10)||0, comboQty: parseInt(qs('#comboQty') && qs('#comboQty').value,10)||0, donationAmount: parseFloat(qs('#donationAmount') && qs('#donationAmount').value)||0, notes: qs('#notes') && qs('#notes').value||'', createdAt: new Date().toISOString() }; await Storage.add(order); renderOrders(); qs('#syncStatus') && (qs('#syncStatus').textContent='Queued for sync'); });

                // Init
                computeTotal();
                renderOrders();

              })();
  const checkNumberRow = qs('#checkNumberRow');
  const checkNumber = qs('#checkNumber');

  // Install prompt
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; installBox && installBox.classList.remove('hidden'); });
  installBtn && installBtn.addEventListener('click', async ()=>{ if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; installBox && installBox.classList.add('hidden'); });

  function updateNetworkUI(){ if (networkStatus) { if (navigator.onLine) { networkStatus.textContent='Online'; networkStatus.style.color='green'; } else { networkStatus.textContent='Offline'; networkStatus.style.color='orange'; } } }
  window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); });
  window.addEventListener('offline', updateNetworkUI);
  updateNetworkUI();

  async function renderOrders(){ const list = await Storage.all(); if (!ordersList) return; if (!list || !list.length) { ordersList.innerHTML = '<div>No local orders</div>'; return; } ordersList.innerHTML = list.map(o=>{ const qtyInfo = `Turkey: ${o.turkeyQty||0} — Ham: ${o.hamQty||0} — Combo: ${o.comboQty||0}`; const donation = o.donationAmount ? `Donation: $${parseFloat(o.donationAmount).toFixed(2)}` : ''; const payment = o.paymentMethod ? `Payment: ${o.paymentMethod}${o.checkNumber?(' (check #'+o.checkNumber+')'):''}` : ''; return `<div style="border:1px solid #ddd;padding:8px;margin-bottom:8px"><strong>${o.customer}</strong><div>${o.address}</div><div>${qtyInfo}</div><div>${donation}</div><div>${payment}</div><div>${o.notes||''}</div><small>${o.id}</small></div>`; }).join(''); }

  function computeTotal(){ const t = parseInt(turkeyQty && turkeyQty.value,10) || 0; const h = parseInt(hamQty && hamQty.value,10) || 0; const c = parseInt(comboQty && comboQty.value,10) || 0; const donation = parseFloat(donationAmount && donationAmount.value) || 0; const perItem = 10.00; const total = (t + h + c) * perItem + donation; if (orderTotalEl) orderTotalEl.textContent = total.toFixed(2); return total; }

  // Bind inputs to recompute total
  [turkeyQty, hamQty, comboQty, donationAmount].forEach(el=>{ if (!el) return; el.addEventListener('input', computeTotal); });

  // Payment checkbox behavior
  if (payCheck) payCheck.addEventListener('change', ()=>{ if (payCheck.checked) { checkNumberRow.style.display='block'; payCash.checked = false; } else { checkNumberRow.style.display='none'; } });
  if (payCash) payCash.addEventListener('change', ()=>{ if (payCash.checked) { if (payCheck) { payCheck.checked = false; checkNumberRow.style.display='none'; } } });

  async function trySync(){ const list = await Storage.all(); if (!list || !list.length) { syncStatus && (syncStatus.textContent = 'No queued orders'); return; } if (!navigator.onLine) { syncStatus && (syncStatus.textContent = 'Waiting for network'); return; } syncStatus && (syncStatus.textContent = 'Syncing...'); const teamName = localStorage.getItem('teamName'); const teamCode = localStorage.getItem('teamCode'); for (const order of list) { try { const resp = await fetch((apiBase || '') + '/orders', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Team-Name': teamName || '', 'X-Access-Code': teamCode || '' }, body: JSON.stringify({ order_id: order.id, user_id: teamName||'team', customer: order.customer, address: order.address, notes: order.notes, turkeyQty: order.turkeyQty, hamQty: order.hamQty, comboQty: order.comboQty, donationAmount: order.donationAmount, paymentMethod: order.paymentMethod, checkNumber: order.checkNumber, cellNumber: order.cellNumber, createdAt: order.createdAt }) }); if (resp.ok) { await Storage.remove(order.id); } } catch (err) { console.warn('sync failed for', order.id, err); syncStatus && (syncStatus.textContent = 'Sync error, will retry'); return; } } syncStatus && (syncStatus.textContent = 'All queued orders synced'); renderOrders(); }

  // Login and save order handlers
  loginBtn && loginBtn.addEventListener('click', ()=>{ const team = qs('#teamName').value.trim(); const code = qs('#teamCode').value.trim(); if (!team || !code) return alert('Please provide team name and code'); localStorage.setItem('teamName', team); localStorage.setItem('teamCode', code); if (loginSection) loginSection.style.display='none'; if (appSection) appSection.style.display='block'; if (deferredPrompt) installBox && installBox.classList.remove('hidden'); trySync(); });

  saveOrderBtn && saveOrderBtn.addEventListener('click', async ()=>{ const customer = qs('#customerName').value.trim(); const address = qs('#address').value.trim(); const notes = qs('#notes').value.trim(); const cell = qs('#cellNumber') && qs('#cellNumber').value.trim(); const turkey = parseInt(qs('#turkeyQty') && qs('#turkeyQty').value,10) || 0; const ham = parseInt(qs('#hamQty') && qs('#hamQty').value,10) || 0; const combo = parseInt(qs('#comboQty') && qs('#comboQty').value,10) || 0; const donation = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0; const paymentMethod = (payCheck && payCheck.checked) ? 'check' : ((payCash && payCash.checked) ? 'cash' : ''); const chkNumber = (checkNumber && checkNumber.value) ? checkNumber.value.trim() : ''; if (!customer || !address) return alert('Customer and address required'); const order = { id: 'o_'+Date.now(), customer, address, cellNumber: cell, turkeyQty: turkey, hamQty: ham, comboQty: combo, donationAmount: donation, paymentMethod, checkNumber: chkNumber, notes, createdAt: new Date().toISOString() }; await Storage.add(order); renderOrders(); syncStatus && (syncStatus.textContent = 'Queued for sync'); if (navigator.onLine) trySync(); });

  // Register service worker if available
  (function(){ if ('serviceWorker' in navigator) { const swBase = portalBase || pluginBase || '/'; const swPath = (swBase.endsWith('/') ? swBase : (swBase + '/')) + 'service-worker.js'; const scope = (swBase.endsWith('/') ? swBase : (swBase + '/')); navigator.serviceWorker.register(swPath, { scope }).then(()=>console.log('sw registered')).catch((e)=>console.warn('sw register failed', e)); } })();

  // Google Places autocomplete loader (if key provided)
  window.initBKMBPlaces = function(){ try { const addr = qs('#address'); if (!addr) return; const ac = new google.maps.places.Autocomplete(addr, { types: ['geocode'] }); ac.setFields(['formatted_address', 'geometry']); ac.addListener('place_changed', ()=>{ const place = ac.getPlace(); if (place && place.formatted_address) addr.value = place.formatted_address; }); } catch(e){ console.warn('places init failed', e); } };
  if (googleMapsApiKey) {
    const s = document.createElement('script');
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(googleMapsApiKey)}&libraries=places&callback=initBKMBPlaces`;
    s.async = true; s.defer = true; document.head.appendChild(s);
  }

  // Init
  renderOrders();
  trySync();

})();
// BKMB Subsales PWA client (plugin-hosted)
(function(){
  // BKMB Subsales PWA client (plugin-hosted) — cleaned single-file version
  (function(){
    // Prefer config injected by WP; fall back to localStorage
    const cfg = window.BKMB_PWA_CONFIG || {};
    const apiBase = cfg.apiBase || cfg.api_base || (localStorage.getItem('API_BASE_URL') || '');
    const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
    const portalBase = cfg.portalBase || '';
    const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';

    // Storage (IndexedDB preferred)
    // BKMB Subsales PWA client (plugin-hosted) — cleaned single-file version
    (function(){
      // Prefer config injected by WP; fall back to localStorage
      const cfg = window.BKMB_PWA_CONFIG || {};
      const apiBase = cfg.apiBase || cfg.api_base || (localStorage.getItem('API_BASE_URL') || '');
      const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
      const portalBase = cfg.portalBase || '';
      const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';

      // Storage (IndexedDB preferred)
      const Storage = (function(){
        const DB_NAME = 'bkmb-pwa-db';
        const STORE = 'orders';
        function idbOpen(){
          return new Promise((resolve)=>{
            if (!('indexedDB' in window)) return resolve(null);
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => { try { req.result.createObjectStore(STORE, { keyPath: 'id' }); } catch(e){} };
            req.onsuccess = ()=>resolve(req.result);
            req.onerror = ()=>resolve(null);
          // BKMB Subsales PWA client (plugin-hosted) — cleaned single-file version
          (function(){
            // Prefer config injected by WP; fall back to localStorage
            const cfg = window.BKMB_PWA_CONFIG || {};
            const apiBase = cfg.apiBase || cfg.api_base || (localStorage.getItem('API_BASE_URL') || '');
            const pluginBase = cfg.pluginBase || cfg.plugin_url || '';
            const portalBase = cfg.portalBase || '';
            const googleMapsApiKey = cfg.googleMapsApiKey || cfg.google_maps_api_key || '';

            // Storage (IndexedDB preferred)
            const Storage = (function(){
              const DB_NAME = 'bkmb-pwa-db';
              const STORE = 'orders';
              function idbOpen(){
                return new Promise((resolve)=>{
                  if (!('indexedDB' in window)) return resolve(null);
                  const req = indexedDB.open(DB_NAME, 1);
                  req.onupgradeneeded = () => { try { req.result.createObjectStore(STORE, { keyPath: 'id' }); } catch(e){} };
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

            // DOM helpers
            const qs = s => document.querySelector(s);

            // Ensure UI exists (shortcode may provide markup; keep id checks)
            const root = qs('#bkmb-pwa-root') || (function(){ const d = document.createElement('div'); d.id='bkmb-pwa-root'; document.body.appendChild(d); return d; })();

            if (!qs('#bkmb-pwa-ui')) {
              root.innerHTML = `
                <div id="bkmb-pwa-ui">
                  <header><h1>BKMB Subsales</h1><div id="installBox" class="hidden"><button id="installBtn">Install App</button></div></header>
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
                        <label>Customer name</label>
                        <input id="customerName" placeholder="Customer name" />
                        <label>Address</label>
                        <input id="address" placeholder="Address" />
                        <label>Cell number</label>
                        <input id="cellNumber" type="tel" inputmode="numeric" pattern="[0-9]*" placeholder="Cell number" />
                        <div style="display:flex;gap:8px;margin-top:6px;margin-bottom:6px">
                          <div><label>Turkey</label><input id="turkeyQty" type="number" min="0" value="0" /></div>
                          <div><label>Ham</label><input id="hamQty" type="number" min="0" value="0" /></div>
                          <div><label>Combo</label><input id="comboQty" type="number" min="0" value="0" /></div>
                        </div>
                        <label>Donation amount (USD)</label>
                        <input id="donationAmount" type="number" min="0" step="0.01" value="0" placeholder="$0.00" />
                        <div style="margin-top:6px"><strong>Order total: $<span id="orderTotal">0.00</span></strong></div>

                        <div style="margin-top:8px">
                          <label><input type="checkbox" id="payCheck" /> Pay by check</label>
                          <label style="margin-left:12px"><input type="checkbox" id="payCash" /> Pay by cash</label>
                        </div>
                        <div id="checkNumberRow" style="display:none;margin-top:6px"><label>Check number</label><input id="checkNumber" placeholder="Check number" /></div>

                        <label>Notes</label>
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

            // Install prompt
            let deferredPrompt = null;
            window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; installBox && installBox.classList.remove('hidden'); });
            installBtn && installBtn.addEventListener('click', async ()=>{ if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; installBox && installBox.classList.add('hidden'); });

            function updateNetworkUI(){ if (networkStatus) { if (navigator.onLine) { networkStatus.textContent='Online'; networkStatus.style.color='green'; } else { networkStatus.textContent='Offline'; networkStatus.style.color='orange'; } } }
            window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); });
            window.addEventListener('offline', updateNetworkUI);
            updateNetworkUI();

            async function renderOrders(){ const list = await Storage.all(); if (!ordersList) return; if (!list || !list.length) { ordersList.innerHTML = '<div>No local orders</div>'; return; } ordersList.innerHTML = list.map(o=>{ const qtyInfo = `Turkey: ${o.turkeyQty||0} — Ham: ${o.hamQty||0} — Combo: ${o.comboQty||0}`; const donation = o.donationAmount ? `Donation: $${parseFloat(o.donationAmount).toFixed(2)}` : ''; const payment = o.paymentMethod ? `Payment: ${o.paymentMethod}${o.checkNumber?(' (check #'+o.checkNumber+')'):''}` : ''; return `<div style="border:1px solid #ddd;padding:8px;margin-bottom:8px"><strong>${o.customer}</strong><div>${o.address}</div><div>${qtyInfo}</div><div>${donation}</div><div>${payment}</div><div>${o.notes||''}</div><small>${o.id}</small></div>`; }).join(''); }

            function computeTotal(){ const t = parseInt(turkeyQty && turkeyQty.value,10) || 0; const h = parseInt(hamQty && hamQty.value,10) || 0; const c = parseInt(comboQty && comboQty.value,10) || 0; const donation = parseFloat(donationAmount && donationAmount.value) || 0; const perItem = 10.00; const total = (t + h + c) * perItem + donation; if (orderTotalEl) orderTotalEl.textContent = total.toFixed(2); return total; }

            // Bind inputs to recompute total
            [turkeyQty, hamQty, comboQty, donationAmount].forEach(el=>{ if (!el) return; el.addEventListener('input', computeTotal); });

            // Payment checkbox behavior
            if (payCheck) payCheck.addEventListener('change', ()=>{ if (payCheck.checked) { checkNumberRow.style.display='block'; payCash.checked = false; } else { checkNumberRow.style.display='none'; } });
            if (payCash) payCash.addEventListener('change', ()=>{ if (payCash.checked) { if (payCheck) { payCheck.checked = false; checkNumberRow.style.display='none'; } } });

            async function trySync(){ const list = await Storage.all(); if (!list || !list.length) { syncStatus && (syncStatus.textContent = 'No queued orders'); return; } if (!navigator.onLine) { syncStatus && (syncStatus.textContent = 'Waiting for network'); return; } syncStatus && (syncStatus.textContent = 'Syncing...'); const teamName = localStorage.getItem('teamName'); const teamCode = localStorage.getItem('teamCode'); for (const order of list) { try { const resp = await fetch((apiBase || '') + '/orders', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Team-Name': teamName || '', 'X-Access-Code': teamCode || '' }, body: JSON.stringify({ order_id: order.id, user_id: teamName||'team', customer: order.customer, address: order.address, notes: order.notes, turkeyQty: order.turkeyQty, hamQty: order.hamQty, comboQty: order.comboQty, donationAmount: order.donationAmount, paymentMethod: order.paymentMethod, checkNumber: order.checkNumber, cellNumber: order.cellNumber, createdAt: order.createdAt }) }); if (resp.ok) { await Storage.remove(order.id); } } catch (err) { console.warn('sync failed for', order.id, err); syncStatus && (syncStatus.textContent = 'Sync error, will retry'); return; } } syncStatus && (syncStatus.textContent = 'All queued orders synced'); renderOrders(); }

            // Login and save order handlers
            loginBtn && loginBtn.addEventListener('click', ()=>{ const team = qs('#teamName').value.trim(); const code = qs('#teamCode').value.trim(); if (!team || !code) return alert('Please provide team name and code'); localStorage.setItem('teamName', team); localStorage.setItem('teamCode', code); if (loginSection) loginSection.style.display='none'; if (appSection) appSection.style.display='block'; if (deferredPrompt) installBox && installBox.classList.remove('hidden'); trySync(); });

            saveOrderBtn && saveOrderBtn.addEventListener('click', async ()=>{ const customer = qs('#customerName').value.trim(); const address = qs('#address').value.trim(); const notes = qs('#notes').value.trim(); const cell = qs('#cellNumber') && qs('#cellNumber').value.trim(); const turkey = parseInt(qs('#turkeyQty') && qs('#turkeyQty').value,10) || 0; const ham = parseInt(qs('#hamQty') && qs('#hamQty').value,10) || 0; const combo = parseInt(qs('#comboQty') && qs('#comboQty').value,10) || 0; const donation = parseFloat(qs('#donationAmount') && qs('#donationAmount').value) || 0; const paymentMethod = (payCheck && payCheck.checked) ? 'check' : ((payCash && payCash.checked) ? 'cash' : ''); const chkNumber = (checkNumber && checkNumber.value) ? checkNumber.value.trim() : ''; if (!customer || !address) return alert('Customer and address required'); const order = { id: 'o_'+Date.now(), customer, address, cellNumber: cell, turkeyQty: turkey, hamQty: ham, comboQty: combo, donationAmount: donation, paymentMethod, checkNumber: chkNumber, notes, createdAt: new Date().toISOString() }; await Storage.add(order); renderOrders(); syncStatus && (syncStatus.textContent = 'Queued for sync'); if (navigator.onLine) trySync(); });

            // Register service worker if available
            (function(){ if ('serviceWorker' in navigator) { const swBase = portalBase || pluginBase || '/'; const swPath = (swBase.endsWith('/') ? swBase : (swBase + '/')) + 'service-worker.js'; const scope = (swBase.endsWith('/') ? swBase : (swBase + '/')); navigator.serviceWorker.register(swPath, { scope }).then(()=>console.log('sw registered')).catch((e)=>console.warn('sw register failed', e)); } })();

            // Google Places autocomplete loader (if key provided)
            window.initBKMBPlaces = function(){ try { const addr = qs('#address'); if (!addr) return; const ac = new google.maps.places.Autocomplete(addr, { types: ['geocode'] }); ac.setFields(['formatted_address', 'geometry']); ac.addListener('place_changed', ()=>{ const place = ac.getPlace(); if (place && place.formatted_address) addr.value = place.formatted_address; }); } catch(e){ console.warn('places init failed', e); } };
            if (googleMapsApiKey) {
              const s = document.createElement('script');
              s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(googleMapsApiKey)}&libraries=places&callback=initBKMBPlaces`;
              s.async = true; s.defer = true; document.head.appendChild(s);
            }

            // Init
            renderOrders();
            trySync();

          })();
