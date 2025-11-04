// Minimal client-side PWA app
// Features: team login, offline order capture (IndexedDB + localStorage fallback), background sync when online

(function(){
  const apiBase = (window.SUBSALES_PWA_CONFIG && window.SUBSALES_PWA_CONFIG.apiBase) || localStorage.getItem('API_BASE_URL') || '';

  const qs = s => document.querySelector(s);

  // UI elements
  const loginSection = qs('#loginSection');
  const appSection = qs('#appSection');
  const loginBtn = qs('#loginBtn');
  const saveOrderBtn = qs('#saveOrderBtn');
  const ordersList = qs('#ordersList');
  const networkStatus = qs('#networkStatus');
  const syncStatus = qs('#syncStatus');
  const installBox = qs('#installBox');
  const installBtn = qs('#installBtn');

  // Deferred install prompt
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; if (installBox) installBox.classList.remove('hidden'); });
  installBtn && installBtn.addEventListener('click', async ()=>{ if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; if (installBox) installBox.classList.add('hidden'); });

  // Simple Storage: IndexedDB with localStorage fallback
  const Storage = (function(){
  const DB_NAME = 'subsales-pwa-db';
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
  async function getDB(){ if (!window._subsales_db) window._subsales_db = await idbOpen(); return window._subsales_db; }
  async function add(order){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).put(order); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('subsales_orders')||'[]'); list.push(order); localStorage.setItem('subsales_orders', JSON.stringify(list)); return true; }
  async function all(){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readonly'); const req = tx.objectStore(STORE).getAll(); req.onsuccess = ()=>res(req.result || []); req.onerror = ()=>res([]); }); } return JSON.parse(localStorage.getItem('subsales_orders')||'[]'); }
  async function remove(id){ const db = await getDB(); if (db) { return new Promise((res)=>{ const tx = db.transaction(STORE, 'readwrite'); tx.objectStore(STORE).delete(id); tx.oncomplete = ()=>res(true); tx.onerror = ()=>res(false); }); } const list = JSON.parse(localStorage.getItem('subsales_orders')||'[]').filter(o=>o.id!==id); localStorage.setItem('subsales_orders', JSON.stringify(list)); return true; }
    return { add, all, remove };
  })();

  function updateNetworkUI(){ if (networkStatus) { if (navigator.onLine) { networkStatus.textContent='Online'; networkStatus.className='status-online'; } else { networkStatus.textContent='Offline'; networkStatus.className='status-offline'; } } }
  window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); });
  window.addEventListener('offline', updateNetworkUI);
  updateNetworkUI();

  async function renderOrders(){ const list = await Storage.all(); if (!ordersList) return; if (!list || !list.length) { ordersList.innerHTML = '<div>No local orders</div>'; return; } ordersList.innerHTML = list.map(o=>{ const qtyInfo = `Turkey: ${o.turkeyQty||0} — Ham: ${o.hamQty||0} — Combo: ${o.comboQty||0}`; const donation = o.donationAmount ? `Donation: $${parseFloat(o.donationAmount).toFixed(2)}` : ''; return `<div class="order"><strong>${o.customer}</strong><div>${o.address}</div><div>${qtyInfo}</div><div>${donation}</div><div>${o.notes||''}</div><small>id:${o.id}</small></div>`; }).join(''); }

  async function trySync(){ const list = await Storage.all(); if (!list || !list.length) { if (syncStatus) syncStatus.textContent = 'No queued orders'; return; } if (!navigator.onLine) { if (syncStatus) syncStatus.textContent = 'Waiting for network'; return; } if (syncStatus) syncStatus.textContent = 'Syncing...'; const teamName = localStorage.getItem('teamName'); const teamCode = localStorage.getItem('teamCode'); for (const order of list) { try { const resp = await fetch((apiBase || '') + '/wp-json/order-manager/v1/orders', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Team-Name': teamName || '', 'X-Access-Code': teamCode || '' }, body: JSON.stringify({ order_id: order.id, user_id: teamName || 'team', customer: order.customer, address: order.address, turkeyQty: order.turkeyQty||0, hamQty: order.hamQty||0, comboQty: order.comboQty||0, donationAmount: order.donationAmount||0, notes: order.notes, createdAt: order.createdAt }) }); if (resp.ok) { await Storage.remove(order.id); } } catch (err) { console.warn('sync failed for', order.id, err); if (syncStatus) syncStatus.textContent = 'Sync error, will retry'; return; } } if (syncStatus) syncStatus.textContent = 'All queued orders synced'; renderOrders(); }

  // Bind login
  loginBtn && loginBtn.addEventListener('click', ()=>{ const team = qs('#teamName') ? qs('#teamName').value.trim() : ''; const code = qs('#teamCode') ? qs('#teamCode').value.trim() : ''; if (!team || !code) return alert('Please provide team name and code'); localStorage.setItem('teamName', team); localStorage.setItem('teamCode', code); if (loginSection) loginSection.classList.add('hidden'); if (appSection) appSection.classList.remove('hidden'); if (deferredPrompt && installBox) installBox.classList.remove('hidden'); trySync(); });

  // Bind save order
  saveOrderBtn && saveOrderBtn.addEventListener('click', async ()=>{ const customer = qs('#customerName') ? qs('#customerName').value.trim() : ''; const address = qs('#address') ? qs('#address').value.trim() : ''; const notes = qs('#notes') ? qs('#notes').value.trim() : ''; const turkeyQty = parseInt((qs('#turkeyQty') && qs('#turkeyQty').value) || 0,10) || 0; const hamQty = parseInt((qs('#hamQty') && qs('#hamQty').value) || 0,10) || 0; const comboQty = parseInt((qs('#comboQty') && qs('#comboQty').value) || 0,10) || 0; const donationAmount = parseFloat((qs('#donationAmount') && qs('#donationAmount').value) || 0) || 0; if (!customer || !address) return alert('Customer and address required'); const order = { id: 'o_'+Date.now(), customer, address, turkeyQty, hamQty, comboQty, donationAmount, notes, createdAt: new Date().toISOString() }; await Storage.add(order); renderOrders(); if (syncStatus) syncStatus.textContent = 'Queued for sync'; if (navigator.onLine) trySync(); });
  // Payment checkbox behavior and total update
  const payCheck = qs('#payCheck');
  const payCash = qs('#payCash');
  const checkNumberRow = qs('#checkNumberRow');
  function updatePaymentUI(){ if (payCheck && payCheck.checked) { checkNumberRow && checkNumberRow.classList.remove('hidden'); } else { checkNumberRow && checkNumberRow.classList.add('hidden'); } }
  if (payCheck) payCheck.addEventListener('change', updatePaymentUI);
  if (payCash) payCash.addEventListener('change', ()=>{ if (payCash.checked && payCheck) { payCheck.checked = false; updatePaymentUI(); } });

  function computeTotal(){ const turkey = parseInt((qs('#turkeyQty') && qs('#turkeyQty').value) || 0,10)||0; const ham = parseInt((qs('#hamQty') && qs('#hamQty').value) || 0,10)||0; const combo = parseInt((qs('#comboQty') && qs('#comboQty').value) || 0,10)||0; const donation = parseFloat((qs('#donationAmount') && qs('#donationAmount').value) || 0) || 0; const total = ((turkey+ham+combo)*10) + donation; const el = qs('#orderTotal'); if (el) el.textContent = total.toFixed(2); return total; }
  ['#turkeyQty','#hamQty','#comboQty','#donationAmount'].forEach(id=>{ const el = qs(id); if (el) el.addEventListener('input', computeTotal); });
  // ensure initial total
  computeTotal();

  // enhance address with Google Places if key provided
  (function(){ const key = (window.SUBSALES_PWA_CONFIG && window.SUBSALES_PWA_CONFIG.googleMapsApiKey) || ''; if (!key) return; if (window.google && google.maps && google.maps.places) { initAutocomplete(); return; } window.initAutocomplete = function(){ try { const input = document.getElementById('address'); if (!input) return; const ac = new google.maps.places.Autocomplete(input, {}); ac.setFields(['formatted_address','address_components','geometry','name']); ac.addListener('place_changed', ()=>{ const place = ac.getPlace(); if (place && place.formatted_address) input.value = place.formatted_address; }); } catch(e){} }; const s = document.createElement('script'); s.src = 'https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(key)+'&libraries=places&callback=initAutocomplete'; s.async=true; s.defer=true; document.head.appendChild(s); })();

  // Initial render
  renderOrders();
  trySync();

})();
    if (!team || !code) return alert('Please provide team name and code');
