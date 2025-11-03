// Minimal client-side app to demonstrate:
// - Team login (store team headers locally)
// - Capture orders offline into IndexedDB (fallback to localStorage if needed)
// - Sync queued orders when navigator.onLine === true

(function(){
  const apiBase = localStorage.getItem('API_BASE_URL') || ''; // optionally configure
// Minimal client-side app to demonstrate:
// - Team login (store team headers locally)
// - Capture orders offline into IndexedDB (fallback to localStorage if needed)
// - Sync queued orders when navigator.onLine === true

(function(){
  const apiBase = localStorage.getItem('API_BASE_URL') || ''; // optionally configure

  // Simple storage abstraction: try IndexedDB, fallback to localStorage
  const Storage = (function(){
    const DB_NAME = 'bkmb-pwa-db';
    const STORE = 'orders';

    function idbOpen(){
      return new Promise((resolve, reject)=>{
        if (!('indexedDB' in window)) return resolve(null);
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
        req.onsuccess = ()=>resolve(req.result);
        req.onerror = ()=>resolve(null);
      });
    }

    async function getDB(){
      if (!window._bkmb_db) window._bkmb_db = await idbOpen();
      return window._bkmb_db;
    }

    async function add(order){
      const db = await getDB();
      if (db) {
        return new Promise((res, rej)=>{
          const tx = db.transaction(STORE, 'readwrite');
          tx.objectStore(STORE).put(order);
          tx.oncomplete = ()=>res(true);
          tx.onerror = ()=>res(false);
        });
      }
      // fallback
      const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]');
      list.push(order);
      localStorage.setItem('bkmb_orders', JSON.stringify(list));
      return true;
    }

    async function all(){
      const db = await getDB();
      if (db) {
        return new Promise((res)=>{
          const tx = db.transaction(STORE, 'readonly');
          const req = tx.objectStore(STORE).getAll();
          req.onsuccess = ()=>res(req.result || []);
          req.onerror = ()=>res([]);
        });
      }
      return JSON.parse(localStorage.getItem('bkmb_orders')||'[]');
    }

    async function remove(id){
      const db = await getDB();
      if (db) {
        return new Promise((res)=>{
          const tx = db.transaction(STORE, 'readwrite');
          tx.objectStore(STORE).delete(id);
          tx.oncomplete = ()=>res(true);
          tx.onerror = ()=>res(false);
        });
      }
      const list = JSON.parse(localStorage.getItem('bkmb_orders')||'[]').filter(o=>o.id!==id);
      localStorage.setItem('bkmb_orders', JSON.stringify(list));
      return true;
    }

    return { add, all, remove };
  })();

  // DOM bindings
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

  window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent the mini-infobar from appearing on mobile
    e.preventDefault();
    deferredPrompt = e;
    installBox.classList.remove('hidden');
  });

  installBtn && installBtn.addEventListener('click', async ()=>{
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const choice = await deferredPrompt.userChoice;
    deferredPrompt = null;
    installBox.classList.add('hidden');
  });

  function updateNetworkUI(){
    if (navigator.onLine) { networkStatus.textContent='Online'; networkStatus.className='status-online'; }
    else { networkStatus.textContent='Offline'; networkStatus.className='status-offline'; }
  }

  window.addEventListener('online', ()=>{ updateNetworkUI(); trySync(); });
  window.addEventListener('offline', updateNetworkUI);
  updateNetworkUI();

  async function renderOrders(){
    const list = await Storage.all();
    ordersList.innerHTML = list.length ? list.map(o=>`<div class="order"><strong>${o.customer}</strong><div>${o.address}</div><div>${o.notes||''}</div><small>id:${o.id}</small></div>`).join('') : '<div>No local orders</div>';
  }

  async function trySync(){
    const list = await Storage.all();
    if (!list.length) { syncStatus.textContent = 'No queued orders'; return; }
    if (!navigator.onLine) { syncStatus.textContent = 'Waiting for network'; return; }
    syncStatus.textContent = 'Syncing...';

    // Perform sequential sync to backend. Use team headers stored in localStorage
    const teamName = localStorage.getItem('teamName');
    const teamCode = localStorage.getItem('teamCode');
    for (const order of list) {
      try {
        const resp = await fetch((apiBase || '') + '/wp-json/order-manager/v1/orders', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Team-Name': teamName || '',
            'X-Access-Code': teamCode || ''
          },
          body: JSON.stringify(order)
        });
        if (resp.ok) {
          await Storage.remove(order.id);
        }
      } catch (err) {
        console.warn('sync failed for', order.id, err);
        syncStatus.textContent = 'Sync error, will retry';
        return;
      }
    }
    syncStatus.textContent = 'All queued orders synced';
    renderOrders();
  }

  loginBtn.addEventListener('click', ()=>{
    const team = qs('#teamName').value.trim();
    const code = qs('#teamCode').value.trim();
    if (!team || !code) return alert('Please provide team name and code');
    // Store team credentials in localStorage (app uses headers per request)
    localStorage.setItem('teamName', team);
    localStorage.setItem('teamCode', code);
    loginSection.classList.add('hidden');
    appSection.classList.remove('hidden');
    // If install prompt was deferred, show it now
    if (deferredPrompt) installBox.classList.remove('hidden');
  });

  saveOrderBtn.addEventListener('click', async ()=>{
    const customer = qs('#customerName').value.trim();
    const address = qs('#address').value.trim();
    const notes = qs('#notes').value.trim();
    if (!customer || !address) return alert('Customer and address required');
    const order = { id: 'o_'+Date.now(), customer, address, notes, createdAt: new Date().toISOString() };
    await Storage.add(order);
    renderOrders();
    syncStatus.textContent = 'Queued for sync';
    if (navigator.onLine) trySync();
  });

  // Initial render
  renderOrders();
  trySync();

})();
