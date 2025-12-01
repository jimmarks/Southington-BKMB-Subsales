// Simple nearby autocomplete client
// - Uses existing getCurrentPositionPromise(timeout) from app.js
// - Calls /wp-json/subsales/v1/nearby?lat=..&lng=..&r=..&max=..
// - Caches responses in IndexedDB and Cache API
// - Renders a minimal suggestion dropdown and writes selected label to #address

(function(){
  console.log('subsalesNearby: debug build initialized');
  const DB_NAME = 'subsales-cache-v1';
  const STORE = 'nearby';
  const CACHE_NAME = 'subsales-nearby-cache';
  const CACHE_TTL_MS = 24 * 60 * 60 * 1000; // 24h

  // tiny indexedDB helper
  function idbOpen(){
    return new Promise((resolve,reject)=>{
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = ()=>{
        const db = req.result; if(!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE,{ keyPath: 'key' });
      };
      req.onsuccess = ()=>resolve(req.result);
      req.onerror = ()=>reject(req.error);
    });
  }
  async function idbGet(key){
    try{
      const db = await idbOpen();
      return new Promise((res,rej)=>{
        const tx = db.transaction(STORE,'readonly');
        const store = tx.objectStore(STORE);
        const r = store.get(key);
        r.onsuccess = ()=>res(r.result ? r.result.value : null);
        r.onerror = ()=>rej(r.error);
      });
    }catch(e){return null}
  }
  async function idbPut(key,value){
    try{
      const db = await idbOpen();
      return new Promise((res,rej)=>{
        const tx = db.transaction(STORE,'readwrite');
        const store = tx.objectStore(STORE);
        const r = store.put({ key: key, value: value, ts: Date.now() });
        r.onsuccess = ()=>res(true);
        r.onerror = ()=>rej(r.error);
      });
    }catch(e){return null}
  }

  function cacheKey(lat,lng,radius,max){
    // bucket lat/lng to 4 decimal places ~ 11m
    return `${Math.round(lat*10000)}_${Math.round(lng*10000)}_${radius}_${max}`;
  }

  async function fetchNearby(lat,lng,radius=500,max=50){
    const key = cacheKey(lat,lng,radius,max);
    console.log('subsalesNearby: fetchNearby called', { lat, lng, radius, max, key });
    try{
      const cached = await idbGet(key);
      if(cached && cached.ts && (Date.now() - cached.ts) < CACHE_TTL_MS){
        console.log('subsalesNearby: cache hit (IndexedDB)', { key, ageMs: Date.now()-cached.ts, count: (cached.results||[]).length });
        return cached;
      }
    }catch(e){ console.warn('subsalesNearby: idbGet error', e); }

    const url = `/wp-json/subsales/v1/nearby?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&r=${encodeURIComponent(radius)}&max=${encodeURIComponent(max)}`;
    console.log('subsalesNearby: fetching from server', url);
    try{
      const resp = await fetch(url, { cache: 'no-store' });
      if(!resp.ok) throw new Error('network ' + resp.status);
      const json = await resp.json();
      console.log('subsalesNearby: server returned', (json && json.results && json.results.length) || 0, 'results');
      // cache raw response in Cache API for SW access
      try{ const c = await caches.open(CACHE_NAME); await c.put(url, new Response(JSON.stringify(json))); }catch(e){ console.warn('subsalesNearby: cache.put failed', e); }
      await idbPut(key, { results: json.results, ts: Date.now() });
      return { results: json.results };
    }catch(err){
      console.warn('subsalesNearby: fetch failed, trying Cache API', err);
      // network failed, try Cache API
      try{
        const c = await caches.open(CACHE_NAME);
        const cachedResp = await c.match(url);
        if(cachedResp){ const j = await cachedResp.json(); console.log('subsalesNearby: cache API hit', (j && j.results && j.results.length)||0); return { results: j.results || [] }; }
      }catch(e){ console.warn('subsalesNearby: cache match failed', e); }
      return { results: [] };
    }
  }

  // Load per-ZIP JSON from uploads directory and cache in memory / IndexedDB
  const ZIP_CACHE = {}; // in-memory cache: zip -> array of records
  let ZIP_BASE_URL = '/wp-content/uploads/subsales-zipdata/'; // default, updated from zip-index.json
  
  async function loadZipData(zip){
    zip = (''+zip).trim();
    if(!/^[0-9]{5}$/.test(zip)) throw new Error('invalid zip');
    if(ZIP_CACHE[zip]){ console.log('subsalesNearby: zip cache hit', zip); return ZIP_CACHE[zip]; }
    const url = ZIP_BASE_URL + zip + '.json';
    console.log('subsalesNearby: loading zip data', url);
    try{
      const resp = await fetch(url, { cache: 'no-store' });
      if(!resp.ok) throw new Error('network ' + resp.status);
      const j = await resp.json();
      const arr = Array.isArray(j) ? j : (j.results || []);
      ZIP_CACHE[zip] = arr;
      // also persist small index in idb for offline
      try{ await idbPut('zip_' + zip, { ts: Date.now(), results: arr }); }catch(e){ console.warn('subsalesNearby: idb store zip failed', e); }
      console.log('subsalesNearby: loaded', arr.length, 'records for zip', zip);
      return arr;
    }catch(e){
      console.warn('subsalesNearby: loadZipData failed', e);
      // attempt to read from idb
      try{ const cached = await idbGet('zip_' + zip); if(cached && cached.results){ ZIP_CACHE[zip] = cached.results; console.log('subsalesNearby: loaded zip from idb', zip); return cached.results; } }catch(ie){ console.warn('subsalesNearby: idb read failed', ie); }
      throw e;
    }
  }

    // Scan IDB for any stored zip_* entries and return concatenated results (fallback search)
    async function idbGetAllZips(){
      try{
        const db = await idbOpen();
        return new Promise((res, rej)=>{
          const tx = db.transaction(STORE,'readonly');
          const store = tx.objectStore(STORE);
          const all = [];
          const req = store.openCursor();
          req.onsuccess = function(){
            const cur = req.result;
            if(!cur){ res(all); return; }
            const k = cur.key;
            if(typeof k === 'string' && k.indexOf('zip_') === 0){ const v = cur.value && cur.value.value; if(v && Array.isArray(v.results)) all.push(...v.results); else if(v && Array.isArray(v)) all.push(...v); }
            cur.continue();
          };
          req.onerror = ()=>rej(req.error);
        });
      }catch(e){ return []; }
    }

  // Prefetch a list of zips in the background and store them in IndexedDB + memory cache.
  function prefetchAllZips(indexData){
    // Handle both formats: array of zips or object with {zips: [], baseUrl: ''}
    let zipList = [];
    if(Array.isArray(indexData)){
      zipList = indexData;
    } else if(indexData && typeof indexData === 'object'){
      if(indexData.baseUrl) ZIP_BASE_URL = indexData.baseUrl;
      zipList = indexData.zips || [];
    }
    
    if(!Array.isArray(zipList) || zipList.length === 0) return;
    console.log('subsalesNearby: prefetching zips', zipList, 'from', ZIP_BASE_URL);
    const work = async ()=>{
      for(let i=0;i<zipList.length;i++){
        const z = (''+zipList[i]).trim();
        if(!/^[0-9]{5}$/.test(z)) continue;
        try{ await loadZipData(z); }
        catch(e){ console.warn('subsalesNearby: prefetch zip failed', z, e); }
        // yield to idle/batch
        await new Promise(r=>setTimeout(r, 200));
      }
      console.log('subsalesNearby: prefetch complete');
    };
    // update UI to indicate prefetch start (orange)
    try{ updateHeaderStatus('orange','Prefetching address index…'); }catch(e){}
    if('requestIdleCallback' in window){ requestIdleCallback(()=>{ work().catch(()=>{}); }, { timeout: 2000 }); }
    else setTimeout(()=>{ work().catch(()=>{}); }, 1000);
  }

  // Fetch a bundled broad recommendation list (fallback) from the plugin's pwa folder
  async function fetchBroadRecommendations(){
    // try multiple ways to resolve base path
    let base = './';
    if(window.SUBSALES_PLUGIN_URL) base = window.SUBSALES_PLUGIN_URL;
    else if(window.SUBSALES_PLUGIN_BASE) base = window.SUBSALES_PLUGIN_BASE;
    else if(window.SUBSALES_PWA_BASE) base = window.SUBSALES_PWA_BASE;
    else {
      // try to locate the current script src
      const scripts = document.getElementsByTagName('script');
      for(let i=0;i<scripts.length;i++){
        const s = scripts[i].src || '';
        if(s.indexOf('address-autocomplete.js') !== -1){ base = s.replace(/address-autocomplete.js.*$/,''); break; }
        if(s.indexOf('/pwa/app.js') !== -1){ base = s.replace(/app.js.*$/,''); break; }
      }
    }
    const url = (base.endsWith('/') ? base : base + '/') + 'global-suggestions.json';
    console.log('subsalesNearby: fetchBroadRecommendations loading', url);
    try{
      const r = await fetch(url, { cache: 'no-store' });
      if(!r.ok) throw new Error('network '+r.status);
      const j = await r.json();
      console.log('subsalesNearby: broad recommendations', (j && j.length) || 0);
      return j || [];
    }catch(e){ console.warn('subsalesNearby: broad fetch failed', e); return []; }
  }

  // Simple dropdown renderer
  // Lightweight fuzzy scoring: prefers startsWith > contains > small edit distance
  function levenshtein(a, b){
    if(!a || !b) return (a||'').length + (b||'').length;
    a = a.split(''); b = b.split('');
    const m = a.length, n = b.length;
    const dp = Array(m+1).fill(null).map(()=>Array(n+1).fill(0));
    for(let i=0;i<=m;i++) dp[i][0]=i;
    for(let j=0;j<=n;j++) dp[0][j]=j;
    for(let i=1;i<=m;i++){
      for(let j=1;j<=n;j++){
        const cost = a[i-1]===b[j-1] ? 0 : 1;
        dp[i][j] = Math.min(dp[i-1][j]+1, dp[i][j-1]+1, dp[i-1][j-1]+cost);
      }
    }
    return dp[m][n];
  }

  function fuzzyScore(q, text){
    if(!q) return 1;
    q = q.toLowerCase(); text = (text||'').toLowerCase();
    if(text === q) return 1000;
    if(text.indexOf(q) === 0) return 900; // starts with
    const idx = text.indexOf(q);
    if(idx > -1) return 800 - idx; // contains, prefer earlier
    // token presence
    const toks = q.split(/\s+/).filter(Boolean);
    let tokenMatches = 0;
    for(const t of toks) if(text.indexOf(t) !== -1) tokenMatches++;
    if(tokenMatches === toks.length) return 700 + tokenMatches;
    // fallback to small edit distance on shorter strings
    const dist = levenshtein(q, text.slice(0, Math.max(q.length + 3, q.length*2)));
    if(dist <= 2) return 600 - dist;
    return -1;
  }

  function rankItems(items, q, limit){
    if(!q) return items.slice(0, limit);
    const scored = items.map(it=>{
      const label = normalizeAddress(it) + ' ' + ((it.city||'') + ' ' + (it.state||'') + ' ' + (it.zip||''));
      return { it, score: fuzzyScore(q, label) };
    }).filter(s=>s.score > -1).sort((a,b)=>b.score - a.score).slice(0, limit).map(s=>s.it);
    return scored;
  }

  // Normalize an item into: "Number Street, City, State ZIP" when possible
  function normalizeAddress(item){
    if(!item) return '';
    const label = (item.label || '').trim();
    let street = (item.street || '').trim();
    const city = (item.city || '').trim();
    const state = (item.state || '').trim();
    const zip = (item.zip || '').trim();

    if(!street && label){
      // take first segment before comma as street candidate
      const before = label.split(',')[0].trim();
      street = before;
    }

    // try to extract number and street name
    let number = '';
    let streetName = street;
    // match trailing number like "Main St 123" or "Main St #123"
    const trailing = street.match(/^(.*?)[\s,#]+(\d+[A-Za-z0-9-]*)$/);
    if(trailing){ streetName = trailing[1].trim(); number = trailing[2].trim(); }
    else {
      // match leading number in label or street
      const leadLabel = label.match(/^(\d+[A-Za-z0-9-]*)\s+(.*)$/);
      if(leadLabel){ number = leadLabel[1]; streetName = (leadLabel[2] || streetName).split(',')[0].trim(); }
      else {
        const leadStreet = street.match(/^(\d+[A-Za-z0-9-]*)\s+(.*)$/);
        if(leadStreet){ number = leadStreet[1]; streetName = leadStreet[2].trim(); }
      }
    }

    let streetOut = streetName || '';
    if(number) streetOut = number + ' ' + streetOut;

    let cityPart = '';
    if(city) cityPart = city + (state ? ', ' + state : '');
    else if(state) cityPart = state;

    let tail = '';
    if(zip) tail = (cityPart ? cityPart + ' ' + zip : zip);
    else tail = cityPart;

    if(tail) return (streetOut ? streetOut + ', ' + tail : tail);
    return streetOut || label || '';
  }
  function renderDropdown(inputEl, items){
    console.log('subsalesNearby: renderDropdown', { items: items ? items.length : 0 });
    removeDropdown(inputEl);
    const list = document.createElement('div');
    list.className = 'subsales-nearby-list';
    // accessibility
    list.setAttribute('role','listbox');
    list.id = 'subsales-nearby-list';
    list.style.position = 'absolute';
    list.style.zIndex = 20000;
    list.style.background = '#fff';
    list.style.border = '1px solid rgba(0,0,0,0.12)';
    list.style.boxShadow = '0 6px 18px rgba(0,0,0,0.08)';
    list.style.borderRadius = '6px';
    list.style.maxHeight = '240px';
    list.style.overflow = 'auto';
    list.style.minWidth = (inputEl.offsetWidth || 200) + 'px';

    items.forEach((it, idx)=>{
      const row = document.createElement('div');
      row.className = 'subsales-nearby-row';
      // touch-friendly sizing
      row.style.padding = '12px 14px';
      row.style.minHeight = '48px';
      row.style.fontSize = '16px';
      row.style.cursor = 'pointer';
      row.style.borderBottom = '1px solid rgba(0,0,0,0.04)';
      row.innerText = normalizeAddress(it);
      row.setAttribute('role','option');
      row.setAttribute('data-index', ''+idx);
      row.id = 'subsales-option-' + idx;
      // unify selection logic so we can trigger it from pointer/touch/click
      const doSelect = ()=>{
        console.log('subsalesNearby: suggestion selected', it);
        // Only update the input value if user hasn't continued typing
        // This allows users to freely type their own address
        const currentVal = (inputEl.value || '').trim();
        inputEl.value = normalizeAddress(it);
        try{ inputEl.dataset.subsalesSelected = JSON.stringify(it); }catch(e){}
        removeDropdown(inputEl);
        // Don't trigger input event to avoid re-showing suggestions
        inputEl.focus();
      };

      // pointerdown handles touch and mouse earlier than click (prevents blur issues on mobile)
      row.addEventListener('pointerdown', (e)=>{ try{ e.preventDefault(); }catch(ex){} doSelect(); });
      // fallback click handler for older browsers
      row.addEventListener('click', (e)=>{ e.preventDefault(); doSelect(); });
      // keep a lightweight touchstart highlight for visual feedback (don't prevent default here)
      row.addEventListener('touchstart', (e)=>{ row.classList.add('subsales-highlight'); });
      list.appendChild(row);
    });

    // position list
    const rect = inputEl.getBoundingClientRect();
    list.style.top = (window.scrollY + rect.bottom + 6) + 'px';
    list.style.left = (window.scrollX + rect.left) + 'px';

  // prevent blur when interacting with the suggestion list; use pointer events for broad support
  try{ list.addEventListener('pointerdown', (e)=>{ e.preventDefault(); }); }catch(ex){ /* ignore */ }
  list.addEventListener('mousedown', (e)=>{ e.preventDefault(); }); // fallback
    document.body.appendChild(list);
    // close on outside click
    setTimeout(()=>{
      document.addEventListener('click', function onDocClick(ev){ if(!list.contains(ev.target) && ev.target !== inputEl){ removeDropdown(inputEl); document.removeEventListener('click', onDocClick); } });
    }, 10);
    // set aria attributes on input
    try{ inputEl.setAttribute('aria-controls', list.id); inputEl.setAttribute('aria-expanded','true'); inputEl.setAttribute('aria-autocomplete','list'); }catch(e){}
  }
  function removeDropdown(inputEl){
    const prev = document.querySelectorAll('.subsales-nearby-list');
    prev.forEach(p=>p.parentNode && p.parentNode.removeChild(p));
    try{ if(inputEl) { inputEl.setAttribute('aria-expanded','false'); inputEl.removeAttribute('aria-controls'); } }catch(e){}
  }

  // Public init: attach to #address input
  async function initNearbyAutocomplete(opts){
    opts = opts || {};
    const radius = opts.radius || 500;
    const max = opts.max || 50;
    const input = document.querySelector('#address');
    if(!input) return;

    // Disable browser autofill / saved-info popups on this input
    try{
      input.setAttribute('autocomplete','off');
      input.setAttribute('autocorrect','off');
      input.setAttribute('autocapitalize','off');
      input.setAttribute('spellcheck','false');
      input.setAttribute('data-lpignore','true'); // LastPass / password managers
    }catch(e){}

  // Only show suggestions after the user starts typing into the address field.
    // This avoids presenting suggestions at the login screen or when the `#address` input is hidden.
  const DEBOUNCE_MS = 300;
    let inputTimer = null;
    let lastFetchTs = 0;
    let retryButtonShownFor = null; // track which input has retry
  let currentZipLoaded = null; // string zip loaded into memory

    function matchesQuery(item, q){
      if(!q) return true;
      const s = (item.label || '') + ' ' + (item.street||'') + ' ' + (item.city||'');
      return s.toLowerCase().indexOf(q.toLowerCase()) !== -1;
    }

      

    input.addEventListener('input', (ev)=>{
      const q = (ev.target.value || '').trim();
      // clear dropdown and retry if empty
      if(!q){ removeDropdown(input); // remove retry button if present
        const b = document.getElementById('subsales-retry-btn'); if(b) b.parentNode && b.parentNode.removeChild(b); retryButtonShownFor = null; return; }

      // debounce
      if(inputTimer) clearTimeout(inputTimer);
      inputTimer = setTimeout(async ()=>{
        // throttle repeated requests
        const now = Date.now(); if(now - lastFetchTs < 1000) return; lastFetchTs = now;
        console.log('subsalesNearby: input triggered fetch for query', q);
        // If a ZIP is loaded, use it as authoritative source (fast, local-files based)
        if(currentZipLoaded){
          try{
            const data = await loadZipData(currentZipLoaded);
            const items = (data||[]).filter(i=>matchesQuery(i,q)).slice(0,50);
            if(items.length) renderDropdown(input, items);
            else removeDropdown(input);
          }catch(e){ console.warn('subsalesNearby: zip search failed', e); removeDropdown(input); }
          return;
        }

        // If no ZIP loaded, try to detect a trailing 5-digit ZIP within the typed value
        const zipMatch = q.match(/([0-9]{5})$/);
        if(zipMatch){
          const zip = zipMatch[1];
          try{
            const data = await loadZipData(zip);
            currentZipLoaded = zip;
            const items = (data||[]).filter(i=>matchesQuery(i,q)).slice(0,50);
            if(items.length) renderDropdown(input, items);
            else removeDropdown(input);
            // ensure retry not shown
            const b = document.getElementById('subsales-retry-btn'); if(b){ b.parentNode && b.parentNode.removeChild(b); retryButtonShownFor = null; }
            return;
          }catch(e){ console.warn('subsalesNearby: load zip failed', zip, e); /* fall back to broad recs */ }
        }

        // no zip context: search all cached ZIPs (memory first, then IDB)
        let pool = [];
        if(Object.keys(ZIP_CACHE).length > 0){ for(const z of Object.keys(ZIP_CACHE)){ pool.push(...(ZIP_CACHE[z]||[])); } }
        if(pool.length === 0){ try{ pool = await idbGetAllZips(); }catch(e){ console.warn('subsalesNearby: idb get all zips failed', e); } }
  const poolItems = pool || [];
  const matches = rankItems(poolItems, q, 50);
  if(matches.length) renderDropdown(input, matches); else removeDropdown(input);
        // if nothing found and no prefetch has happened, trigger prefetch of zip-index.json
        if(matches.length === 0 && Object.keys(ZIP_CACHE).length === 0){
          if(retryButtonShownFor !== input){ showReloadAndPrefetch(input); retryButtonShownFor = input; }
        }
      }, DEBOUNCE_MS);
    });

    // keep focus behavior inert — suggestions are now driven by input events
    input.addEventListener('focus', ()=>{ /* no-op: wait for typing */ });

    // keyboard navigation for dropdown
    let highlighted = -1;
    function clearHighlight(list){
      const prev = list && list.querySelectorAll('.subsales-highlight');
      if(prev) prev.forEach(p=>p.classList.remove('subsales-highlight'));
      highlighted = -1;
    }
    function highlightIndex(list, idx){
      clearHighlight(list);
      const el = list && list.querySelector('[data-index="'+idx+'"]');
      if(el){ el.classList.add('subsales-highlight'); el.setAttribute('aria-selected','true'); highlighted = idx; el.scrollIntoView({block:'nearest'}); }
    }

    input.addEventListener('keydown', (ev)=>{
      const list = document.getElementById('subsales-nearby-list');
      if(!list) return;
      const items = list.querySelectorAll('[data-index]');
      if(!items || items.length === 0) return;
      if(ev.key === 'ArrowDown'){
        ev.preventDefault();
        const next = Math.min(items.length-1, (highlighted === -1 ? 0 : highlighted + 1));
        highlightIndex(list, next);
      } else if(ev.key === 'ArrowUp'){
        ev.preventDefault();
        const prev = Math.max(0, (highlighted === -1 ? items.length-1 : highlighted - 1));
        highlightIndex(list, prev);
      } else if(ev.key === 'Enter'){
        ev.preventDefault();
        if(highlighted > -1){ const el = list.querySelector('[data-index="'+highlighted+'"]'); if(el) el.click(); }
      } else if(ev.key === 'Escape'){
        removeDropdown(input);
      }
    });

    // also provide a manual trigger by attribute: data-nearby
    if(input.dataset && input.dataset.nearby === 'true'){
      input.dispatchEvent(new Event('focus'));
    }
  }

  // Show a reload/prefetch button to populate local ZIP datasets when no local data is present
  async function showReloadAndPrefetch(inputEl){
    removeDropdown(inputEl);
    let btn = document.getElementById('subsales-retry-btn');
    if(!btn){
      btn = document.createElement('button');
      btn.id = 'subsales-retry-btn';
      btn.type = 'button';
      btn.innerText = 'Load address data';
      btn.style.marginLeft = '8px';
      btn.style.padding = '10px 12px';
      btn.style.borderRadius = '8px';
      btn.style.fontSize = '15px';
      btn.style.border = '1px solid rgba(0,0,0,0.12)';
      btn.style.background = '#fff';
      btn.style.cursor = 'pointer';
      btn.setAttribute('aria-label','Load address index for offline suggestions');
      btn.addEventListener('click', async ()=>{
        console.log('subsalesNearby: load address data clicked');
        btn.disabled = true; btn.innerText = 'Loading…';
        try{
          const resp = await fetch((window.SUBSALES_PWA_CONFIG && window.SUBSALES_PWA_CONFIG.pluginBase ? window.SUBSALES_PWA_CONFIG.pluginBase : './') + 'zip-index.json', { cache: 'no-store' });
          if(resp && resp.ok){ 
            const indexData = await resp.json(); 
            prefetchAllZips(indexData);
          }
        }catch(e){ console.warn('subsalesNearby: trigger prefetch failed', e); }
        // allow button to be used again
        btn.disabled = false; btn.innerText = 'Load address data';
      });
      inputEl.parentNode && inputEl.parentNode.insertBefore(btn, inputEl.nextSibling);
    }
  }

  // Expose on window for quick use
  window.subsalesNearby = {
    init: initNearbyAutocomplete,
    fetchNearby: fetchNearby,
    prefetch: function(){ // expose prefetch so app.js can call it on login
      (async ()=>{
        try{
          const resp = await fetch((window.SUBSALES_PWA_CONFIG && window.SUBSALES_PWA_CONFIG.pluginBase ? window.SUBSALES_PWA_CONFIG.pluginBase : './') + 'zip-index.json', { cache: 'no-store' });
          if(!resp.ok) return;
          const indexData = await resp.json();
          prefetchAllZips(indexData);
        }catch(e){ console.warn('subsalesNearby: manual prefetch failed', e); }
      })();
    }
  };

  // Auto-init when DOM ready
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ()=>{ initNearbyAutocomplete(); }); else initNearbyAutocomplete();

  // Start background prefetch of zips listed in zip-index.json (non-blocking)
  (function(){
    const tryPrefetch = async ()=>{
      try{
        const resp = await fetch((window.SUBSALES_PWA_CONFIG && window.SUBSALES_PWA_CONFIG.pluginBase ? window.SUBSALES_PWA_CONFIG.pluginBase : './') + 'zip-index.json', { cache: 'no-store' });
        if(!resp.ok) return;
        const indexData = await resp.json();
        prefetchAllZips(indexData);
      }catch(e){ console.warn('subsalesNearby: zip-index prefetch failed', e); }
    };
    // delay prefetch slightly so it doesn't block initial paint
    if('requestIdleCallback' in window) requestIdleCallback(tryPrefetch, { timeout: 3000 }); else setTimeout(tryPrefetch, 2500);
  })();

  // header status updater helper (uses existing header UI if present)
  function updateHeaderStatus(color, text){
    try{
      const dot = document.getElementById('headerDot');
      const txt = document.getElementById('headerStatusText');
      if(dot) dot.style.backgroundColor = (color === 'green' ? '#28a745' : (color === 'orange' ? '#ff9f00' : 'transparent'));
      if(txt && typeof text === 'string') txt.textContent = text;
    }catch(e){ /* noop */ }
  }

  // listen for prefetch complete to mark green or partial (called from work())
  // We'll monkeypatch prefetchAllZips' internal work completion by detecting ZIP_CACHE changes in a small interval.
  (function watchPrefetchCompletion(){
    let lastCount = Object.keys(ZIP_CACHE).length;
    const check = ()=>{
      const nowCount = Object.keys(ZIP_CACHE).length;
      if(nowCount > 0 && nowCount !== lastCount){
        // still loading; set orange
        updateHeaderStatus('orange','Loading address data…');
        lastCount = nowCount;
      }
      // when prefetch likely finished (no change after 2s), mark green
      setTimeout(()=>{
        const after = Object.keys(ZIP_CACHE).length;
        if(after === lastCount && after > 0){ updateHeaderStatus('green','Address index loaded'); }
        else check();
      }, 2000);
    };
    // start watching after short delay
    setTimeout(check, 1500);
  })();


})();
