// Minimal client-side PWA app
// Features: team login, offline order capture (IndexedDB + localStorage fallback), background sync when online

(function(){
  const apiBase = (window.BKMB_PWA_CONFIG && window.BKMB_PWA_CONFIG.apiBase) || localStorage.getItem('API_BASE_URL') || '';

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

  // Initial render
  renderOrders();
  trySync();

})();
    if (!team || !code) return alert('Please provide team name and code');
