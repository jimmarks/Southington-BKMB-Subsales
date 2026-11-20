/* Sub Sale Admin - dashboard, texts and orders for the people running a sale.
   Online-only by design: every number and every thread is fetched live, so
   there is no cache to be wrong. Built with textContent throughout - customer
   names, addresses and texts are other people's typing. */
(function () {
  'use strict';

  var cfg = window.SUBSALES_ADMIN || {};
  var root = document.getElementById('app');
  var state = { tab: 'dash', day: '', days: [], unread: 0, chat: null, order: null, q: '', pay: '', oday: '' };
  var timers = [];

  // ---- small helpers ------------------------------------------------------

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'class') { el.className = v; }
      else if (k === 'text') { el.textContent = v; }
      else if (k.slice(0, 2) === 'on') { el.addEventListener(k.slice(2), v); }
      else { el.setAttribute(k, v === true ? '' : v); }
    });
    [].concat(kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) { return; }
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  function money(n) { return '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  function phoneFmt(p) {
    var d = String(p || '').replace(/\D/g, '').slice(-10);
    return d.length === 10 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : String(p || '');
  }

  function dayLabel(iso) {
    var d = new Date(iso + 'T12:00:00');
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
  }

  function whenShort(ts) {
    var d = new Date(ts * 1000), now = new Date();
    if (d.toDateString() === now.toDateString()) {
      return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function whenFull(ts) {
    return new Date(ts * 1000).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  function clearTimers() { timers.forEach(clearInterval); timers = []; }
  function every(ms, fn) { timers.push(setInterval(fn, ms)); }

  function toast(msg) {
    var t = h('div', { class: 'toast', role: 'status', text: msg });
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2600);
  }

  function api(path, opts) {
    opts = opts || {};
    return fetch(cfg.api + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (res.status === 401 || (res.status === 403 && data && data.code === 'rest_forbidden')
            || (data && data.code === 'rest_cookie_invalid_nonce')) {
          // Twelve hours are up (or the session ended elsewhere).
          location.reload();
          throw new Error('signed out');
        }
        if (!res.ok) { throw new Error((data && data.message) || 'Something went wrong.'); }
        return data;
      });
    });
  }

  // ---- connection state ---------------------------------------------------

  var offlineBar = null;
  function showOffline() {
    if (navigator.onLine) { if (offlineBar) { offlineBar.remove(); offlineBar = null; } return; }
    if (!offlineBar) {
      offlineBar = h('div', { class: 'offline', role: 'alert', text: 'No connection - numbers and texts are not updating.' });
      document.body.appendChild(offlineBar);
    }
  }
  window.addEventListener('online', function () { showOffline(); render(); });
  window.addEventListener('offline', showOffline);

  // ---- sign in ------------------------------------------------------------

  function viewSignIn() {
    clearTimers();
    var err = h('div', { class: 'err', role: 'alert', hidden: true });
    var user = h('input', { id: 'u', type: 'text', autocomplete: 'username', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
    var pass = h('input', { id: 'p', type: 'password', autocomplete: 'current-password' });
    var btn = h('button', { type: 'submit', text: 'Sign in' });

    var form = h('form', {
      onsubmit: function (e) {
        e.preventDefault();
        err.hidden = true;
        btn.disabled = true; btn.textContent = 'Signing in…';
        fetch(cfg.api + '/admin-app/login', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username: user.value.trim(), password: pass.value })
        }).then(function (res) {
          return res.json().catch(function () { return {}; }).then(function (d) {
            if (!res.ok) { throw new Error((d && d.message) || 'Could not sign in.'); }
            location.reload(); // the page comes back carrying this session's key
          });
        }).catch(function (e2) {
          err.textContent = e2.message; err.hidden = false;
          btn.disabled = false; btn.textContent = 'Sign in';
        });
      }
    }, [
      h('label', { for: 'u', text: 'Username' }), user,
      h('label', { for: 'p', text: 'Password' }), pass,
      btn, err
    ]);

    var box = h('div', { class: 'signin' }, [
      h('h1', { text: 'Sub Sale Admin' }),
      h('p', { text: cfg.brand || '' })
    ]);
    if (cfg.denied) {
      box.appendChild(h('div', { class: 'err', text: 'This account is not set up for the admin app. Ask the sub sale chair to add you as a Sub Sale Manager.' }));
      box.appendChild(h('button', { type: 'button', text: 'Sign in as someone else', onclick: function () {
        fetch(cfg.api + '/admin-app/logout', { method: 'POST', credentials: 'same-origin' }).then(function () { location.reload(); });
      } }));
    } else {
      box.appendChild(form);
    }
    root.replaceChildren(box);
  }

  // ---- shell --------------------------------------------------------------

  function tabs() {
    function tab(id, ico, label) {
      var b = h('button', { type: 'button', class: state.tab === id ? 'on' : '', 'aria-current': state.tab === id ? 'page' : null,
        onclick: function () { state.tab = id; state.chat = null; state.order = null; render(); } },
        [h('span', { class: 'ico', 'aria-hidden': 'true', text: ico }), label]);
      if (id === 'texts' && state.unread > 0) {
        b.appendChild(h('span', { class: 'badge', text: String(state.unread), 'aria-label': state.unread + ' unread' }));
      }
      return b;
    }
    return h('nav', { class: 'tabs', 'aria-label': 'Sections' }, [
      tab('dash', '▦', 'Dashboard'), tab('texts', '✉', 'Texts'), tab('orders', '⌕', 'Orders')
    ]);
  }

  function bar(title, sub, left, right) {
    return h('header', { class: 'bar' }, [
      left || null,
      h('h1', {}, [title, sub ? h('span', { class: 'sub', text: sub }) : null]),
      right || null
    ]);
  }

  function signOutBtn() {
    return h('button', { type: 'button', text: 'Sign out', onclick: function () {
      fetch(cfg.api + '/admin-app/logout', { method: 'POST', credentials: 'same-origin' }).then(function () { location.reload(); });
    } });
  }

  function page(kids) { root.replaceChildren.apply(root, [].concat(kids, [tabs()])); }

  // ---- dashboard ----------------------------------------------------------

  function viewDash() {
    clearTimers();
    var body = h('div', { class: 'pad' }, [h('div', { class: 'empty', text: 'Loading…' })]);
    var stamp = h('div', { class: 'note', 'aria-live': 'off' });
    var asOf = 0;
    page([bar('Dashboard', cfg.user, null, signOutBtn()), body]);

    function load() {
      api('/admin-app/dashboard' + (state.day ? '?day=' + encodeURIComponent(state.day) : '')).then(function (d) {
        state.day = d.day; state.days = d.days; state.unread = d.unread; asOf = Date.now();
        draw(d);
      }).catch(function (e) {
        if (e.message !== 'signed out') { body.replaceChildren(h('div', { class: 'banner bad', text: e.message })); }
      });
    }

    function draw(d) {
      var t = d.totals, season = d.day === 'season';
      var i = d.days.indexOf(d.day);
      function go(to) { state.day = to; load(); }

      var strip = h('button', { type: 'button', class: 'texts-strip' + (d.unread ? ' has' : ''),
        onclick: function () { state.tab = 'texts'; render(); } }, [
        h('span', { class: 'n', text: String(d.unread) }),
        h('span', { class: 't', text: d.unread === 1 ? 'new text waiting' : (d.unread ? 'new texts waiting' : 'No new texts') }),
        h('span', { class: 'go', 'aria-hidden': 'true', text: '›' })
      ]);

      var days = h('div', { class: 'days' }, [
        h('button', { type: 'button', 'aria-label': 'Previous sale day', disabled: season || i <= 0, text: '◀',
          onclick: function () { go(d.days[i - 1]); } }),
        h('div', { class: 'label' }, [
          season ? 'Whole season' : dayLabel(d.day),
          h('small', { text: season ? 'every sale day so far' : (d.day === d.today ? 'today' : (i === d.days.length - 1 ? 'last sale day' : '')) })
        ]),
        h('button', { type: 'button', 'aria-label': 'Next sale day', disabled: season || i < 0 || i >= d.days.length - 1, text: '▶',
          onclick: function () { go(d.days[i + 1]); } }),
        h('button', { type: 'button', class: 'season' + (season ? ' on' : ''), 'aria-pressed': season ? 'true' : 'false', text: 'Season',
          onclick: function () { go(season ? '' : 'season'); } })
      ]);

      var prods = Object.keys(t.products).sort().map(function (name) {
        return h('div', { class: 'row' }, [h('span', { class: 'k', text: name }), h('span', { class: 'v', text: String(t.products[name]) })]);
      });

      function row(k, v, cls) { return h('div', { class: 'row ' + (cls || '') }, [h('span', { class: 'k', text: k }), h('span', { class: 'v', text: v })]); }

      var moneyRows = [
        row('Subs', money(t.subs)), row('Donations', money(t.donations)),
        row('Total sales', money(t.total), 'total'),
        row('Cash', money(t.cash), 'indent'), row('Check', money(t.check), 'indent'), row('Digital', money(t.digital), 'indent')
      ];
      if (t.none > 0) { moneyRows.push(row('No payment method', money(t.none), 'indent')); }
      if (t.refunded_count > 0) {
        moneyRows.push(row(t.refunded_count + ' refunded (not counted)', money(t.refunded_amount)));
      }

      body.replaceChildren(
        strip, days,
        h('div', { class: 'card' }, [row('Orders', String(t.orders), 'big')]),
        prods.length ? h('div', { class: 'card' }, prods) : h('div', { class: 'card' }, [h('div', { class: 'row' }, [h('span', { class: 'k', text: 'No subs sold yet' })])]),
        h('div', { class: 'card' }, moneyRows),
        stamp
      );
      tick();
      root.replaceChild(tabs(), root.lastChild); // unread badge
    }

    function tick() {
      if (!asOf) { return; }
      var s = Math.round((Date.now() - asOf) / 1000);
      stamp.textContent = 'Updated ' + (s < 2 ? 'just now' : s + 's ago') + ' · pull down to refresh';
    }

    // Pull to refresh: a drag down from the very top.
    var startY = null;
    body.addEventListener('touchstart', function (e) { startY = window.scrollY <= 0 ? e.touches[0].clientY : null; }, { passive: true });
    body.addEventListener('touchend', function (e) {
      if (startY !== null && e.changedTouches[0].clientY - startY > 80) { stamp.textContent = 'Refreshing…'; load(); }
      startY = null;
    }, { passive: true });

    load();
    every(30000, function () { if (navigator.onLine) { load(); } });
    every(1000, tick);
  }

  // ---- texts --------------------------------------------------------------

  function viewTexts() {
    clearTimers();
    var list = h('div', { class: 'list' }, [h('div', { class: 'empty', text: 'Loading…' })]);
    page([bar('Texts', null), list]);

    function load() {
      api('/admin-app/conversations').then(function (d) {
        state.unread = d.unread;
        if (!d.conversations.length) { list.replaceChildren(h('div', { class: 'empty', text: 'No texts yet.' })); return; }
        list.replaceChildren.apply(list, d.conversations.map(function (c) {
          return h('button', { type: 'button', class: 'item' + (c.unread ? ' unread' : ''),
            onclick: function () { state.chat = c.phone; render(); } }, [
            h('span', { class: 'dot', 'aria-hidden': 'true' }),
            h('span', { class: 'main' }, [
              h('span', { class: 'top' }, [
                h('span', { class: 'name', text: c.name || phoneFmt(c.phone) }),
                h('span', { class: 'when', text: whenShort(c.last_at) })
              ]),
              h('span', { class: 'prev', text: c.preview + (c.unread ? '' : '') })
            ])
          ]);
        }));
        root.replaceChild(tabs(), root.lastChild);
      }).catch(function (e) {
        if (e.message !== 'signed out') { list.replaceChildren(h('div', { class: 'banner bad', text: e.message })); }
      });
    }
    load();
    every(15000, function () { if (navigator.onLine) { load(); } });
  }

  function viewChat(phone) {
    clearTimers();
    var chat = h('div', { class: 'chat' });
    var head = h('div', {});
    var foot = h('div', {});
    var lastId = -1;
    var back = h('button', { type: 'button', 'aria-label': 'Back to texts', text: '‹ Texts', onclick: function () { state.chat = null; render(); } });
    var titleBar = bar(phoneFmt(phone), null, back);
    page([titleBar, head, chat, h('div', { class: 'chat-space' }), foot]);

    function load(first) {
      api('/admin-app/thread?phone=' + encodeURIComponent(phone)).then(function (d) {
        titleBar.querySelector('h1').replaceChildren(d.name || phoneFmt(phone), h('span', { class: 'sub', text: d.name ? phoneFmt(phone) : '' }));

        // One line, never the whole order: the screen belongs to the conversation.
        if (d.orders.length === 1) {
          var o = d.orders[0];
          head.replaceChildren(h('button', { type: 'button', class: 'orderline',
            text: 'Order · ' + (o.summary || 'details') + ' · ' + money(o.total) + ' ›',
            onclick: function () { state.tab = 'orders'; state.order = o.id; state.chat = null; render(); } }));
        } else if (d.orders.length > 1) {
          head.replaceChildren(h('button', { type: 'button', class: 'orderline', text: d.orders.length + ' orders ›',
            onclick: function () { state.tab = 'orders'; state.q = String(phone).replace(/\D/g, '').slice(-10); state.chat = null; render(); } }));
        } else { head.replaceChildren(); }

        var newest = d.messages.length ? d.messages[d.messages.length - 1].id : 0;
        if (newest !== lastId) {
          var kids = [], prev = 0;
          d.messages.forEach(function (m) {
            if (m.at - prev > 3600) { kids.push(h('div', { class: 'stamp', text: whenFull(m.at) })); }
            prev = m.at;
            var failed = m.out && (m.status === 'failed' || m.status === 'skipped');
            kids.push(h('div', { class: 'bubble ' + (m.out ? 'out' : 'in') + (failed ? ' failed' : ''), text: m.body }));
            if (failed) { kids.push(h('div', { class: 'status', text: 'Not delivered' })); }
          });
          chat.replaceChildren.apply(chat, kids.length ? kids : [h('div', { class: 'empty', text: 'Nothing yet.' })]);
          lastId = newest;
          window.scrollTo(0, document.body.scrollHeight);
        }

        if (first) { drawReply(d.opted_out); }
      }).catch(function (e) {
        if (e.message !== 'signed out' && first) { chat.replaceChildren(h('div', { class: 'banner bad', text: e.message })); }
      });
    }

    function drawReply(optedOut) {
      if (optedOut) {
        foot.replaceChildren(h('div', { class: 'reply off', text: 'This customer opted out of texts.' }));
        return;
      }
      var box = h('textarea', { rows: '1', 'aria-label': 'Reply', placeholder: 'Text message' });
      var send = h('button', { type: 'button', text: 'Send' });
      send.addEventListener('click', function () {
        var text = box.value.trim();
        if (!text) { return; }
        send.disabled = true;
        api('/admin-app/reply', { method: 'POST', body: { phone: phone, body: text } }).then(function () {
          box.value = ''; send.disabled = false; load(false);
        }).catch(function (e) {
          send.disabled = false;
          if (e.message !== 'signed out') { toast(e.message); }
        });
      });
      foot.replaceChildren(h('div', { class: 'reply' }, [box, send]));
    }

    load(true);
    every(8000, function () { if (navigator.onLine) { load(false); } });
  }

  // ---- orders -------------------------------------------------------------

  function viewOrders() {
    clearTimers();
    var list = h('div', { class: 'list' });
    var input = h('input', { type: 'search', 'aria-label': 'Search orders', placeholder: 'Name, phone, address or check #', value: state.q });
    var daySel = h('select', { 'aria-label': 'Sale day' }, [h('option', { value: '', text: 'All days' })].concat(
      state.days.slice().reverse().map(function (d) { return h('option', { value: d, text: dayLabel(d), selected: state.oday === d }); })));
    var chips = h('div', { class: 'chips' });

    function chip(val, label) {
      return h('button', { type: 'button', class: state.pay === val ? 'on' : '', 'aria-pressed': state.pay === val ? 'true' : 'false', text: label,
        onclick: function () { state.pay = (state.pay === val ? '' : val); drawChips(); load(); } });
    }
    function drawChips() { chips.replaceChildren(chip('cash', 'Cash'), chip('check', 'Check'), chip('digital', 'Digital'), daySel); }
    drawChips();
    daySel.addEventListener('change', function () { state.oday = daySel.value; load(); });

    var debounce;
    input.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () { state.q = input.value.trim(); load(); }, 300);
    });

    page([bar('Orders', null), h('div', { class: 'search' }, [input, chips]), list]);

    function load() {
      list.replaceChildren(h('div', { class: 'empty', text: 'Searching…' }));
      var qs = '?q=' + encodeURIComponent(state.q) + '&pay=' + encodeURIComponent(state.pay) + '&day=' + encodeURIComponent(state.oday);
      api('/admin-app/orders' + qs).then(function (d) {
        if (!d.orders.length) { list.replaceChildren(h('div', { class: 'empty', text: 'No orders match.' })); return; }
        list.replaceChildren.apply(list, d.orders.map(function (o) {
          return h('button', { type: 'button', class: 'item', onclick: function () { state.order = o.id; render(); } }, [
            h('span', { class: 'main' }, [
              h('span', { class: 'top' }, [
                h('span', { class: 'name', text: o.customer || '(no name)' }),
                h('span', { class: 'when', text: money(o.total) })
              ]),
              h('span', { class: 'prev', text: o.address }),
              h('span', { class: 'prev' }, [
                h('span', { class: 'pay ' + o.payment, text: o.payment || 'unpaid' }),
                ' ' + [o.seller, o.team, dayLabel(o.day)].filter(Boolean).join(' · ')
              ])
            ])
          ]);
        }));
      }).catch(function (e) {
        if (e.message !== 'signed out') { list.replaceChildren(h('div', { class: 'banner bad', text: e.message })); }
      });
    }
    load();
  }

  function viewOrder(id) {
    clearTimers();
    var body = h('div', { class: 'pad form' }, [h('div', { class: 'empty', text: 'Loading…' })]);
    function leave() {
      api('/admin-app/orders/' + id + '/lock', { method: 'POST', body: { release: true } }).catch(function () {});
      state.order = null; render();
    }
    page([bar('Edit order', null, h('button', { type: 'button', 'aria-label': 'Back to orders', text: '‹ Orders', onclick: leave })), body]);

    Promise.all([
      api('/admin-app/orders/' + id),
      api('/admin-app/orders/' + id + '/lock', { method: 'POST', body: {} })
    ]).then(function (r) { draw(r[0], r[1].held_by); }).catch(function (e) {
      if (e.message !== 'signed out') { body.replaceChildren(h('div', { class: 'banner bad', text: e.message })); }
    });

    function draw(o, heldBy) {
      var d = o.data || {};
      var snap = d.price_snapshot || {};
      var orig = d.products || [];
      var pay = String(d.paymentMethod || '').toLowerCase();

      function field(labelText, el, idv) { el.id = idv; return [h('label', { for: idv, text: labelText }), el]; }
      var customer = h('input', { type: 'text', value: d.customer || '', autocomplete: 'off' });
      var address = h('input', { type: 'text', value: d.address || '', autocomplete: 'off' });
      var unit = h('input', { type: 'text', value: d.unit || '', autocomplete: 'off' });
      var cell = h('input', { type: 'tel', inputmode: 'tel', value: d.cellNumber || d.cell || '', autocomplete: 'off' });
      var donation = h('input', { type: 'number', inputmode: 'decimal', min: '0', step: '0.01', value: d.donationAmount || '' });
      var checkNo = h('input', { type: 'text', inputmode: 'numeric', value: d.checkNumber || '', autocomplete: 'off' });
      var notes = h('textarea', { rows: '2' }); notes.value = d.notes || '';
      var totalEl = h('span', {});
      var qtyInputs = [];

      // Price as it was when the order was taken, not today's price list.
      function priceFor(p) {
        if (snap[p.id] !== undefined) { return Number(snap[p.id]); }
        var was = orig.filter(function (x) { return String(x.id) === String(p.id); })[0];
        return was ? Number(was.price) : Number(p.price);
      }

      var prodRows = (o.products || []).map(function (p) {
        var was = orig.filter(function (x) { return String(x.id) === String(p.id); })[0];
        var qty = h('input', { type: 'number', inputmode: 'numeric', min: '0', value: was ? String(was.qty) : '0', 'aria-label': p.name + ' quantity' });
        qtyInputs.push({ p: p, el: qty });
        function bump(n) { qty.value = String(Math.max(0, (parseInt(qty.value, 10) || 0) + n)); total(); }
        qty.addEventListener('input', total);
        return h('div', { class: 'prod' }, [
          h('div', {}, [h('div', { class: 'pn', text: p.name }), h('div', { class: 'pp', text: money(priceFor(p)) + ' each' })]),
          h('div', { class: 'step' }, [
            h('button', { type: 'button', 'aria-label': 'One fewer ' + p.name, text: '−', onclick: function () { bump(-1); } }),
            qty,
            h('button', { type: 'button', 'aria-label': 'One more ' + p.name, text: '+', onclick: function () { bump(1); } })
          ])
        ]);
      });

      function total() {
        var sum = parseFloat(donation.value) || 0;
        qtyInputs.forEach(function (q) { sum += (parseInt(q.el.value, 10) || 0) * priceFor(q.p); });
        totalEl.textContent = money(sum);
        return sum;
      }
      donation.addEventListener('input', total);

      var payBox = h('div', { class: 'payopts', role: 'group', 'aria-label': 'Payment' });
      var checkWrap = h('div', {});
      function drawPay() {
        payBox.replaceChildren.apply(payBox, ['cash', 'check', 'digital'].map(function (m) {
          return h('button', { type: 'button', class: pay === m ? 'on' : '', 'aria-pressed': pay === m ? 'true' : 'false',
            text: m.charAt(0).toUpperCase() + m.slice(1), onclick: function () { pay = m; drawPay(); } });
        }));
        checkWrap.replaceChildren.apply(checkWrap, pay === 'check' ? field('Check number', checkNo, 'f-check') : []);
      }
      drawPay();

      var kids = [];
      if (heldBy) {
        var warn = h('div', { class: 'banner warn', role: 'alert' }, [
          h('strong', { text: heldBy + ' is editing this order.' }),
          ' Saving now could overwrite their changes.',
          h('br', {}),
          h('button', { type: 'button', text: 'Edit anyway', onclick: function () {
            api('/admin-app/orders/' + id + '/lock', { method: 'POST', body: { force: true } }).then(function () { warn.remove(); });
          } })
        ]);
        kids.push(warn);
      }
      if (o.digital_payment) {
        var dp = o.digital_payment;
        var label = { paid: 'Card payment successful', refunded: 'Card payment refunded', failed: 'Card payment failed',
          expired: 'Card payment expired', cancelled_by_seller: 'Card payment cancelled', initiated: 'Card payment never completed' }[dp.status] || dp.status;
        kids.push(h('div', { class: 'banner ' + (dp.status === 'paid' ? 'info' : 'warn') }, [
          h('strong', { text: label }), ' · ' + money(dp.total) + (parseFloat(dp.fee) > 0 ? ' (includes ' + money(dp.fee) + ' fee)' : ''),
          dp.status === 'paid' ? h('div', { text: 'Changing what was bought does not change what was charged. Refunds are done on the desktop.' }) : null
        ]));
      }

      kids = kids.concat(
        field('Customer name', customer, 'f-name'),
        field('Address', address, 'f-addr'),
        field('Apt / unit', unit, 'f-unit'),
        field('Phone', cell, 'f-cell'),
        [h('label', { text: 'Subs' })], prodRows,
        field('Donation amount (USD)', donation, 'f-don'),
        [h('div', { class: 'totalline' }, [h('span', { text: 'Order total' }), totalEl])],
        [h('label', { text: 'Payment' }), payBox, checkWrap],
        field('Delivery instructions', notes, 'f-notes')
      );

      var save = h('button', { type: 'button', class: 'primary', text: 'Save order' });
      save.addEventListener('click', function () {
        if (!customer.value.trim() || !address.value.trim()) { toast('Customer and address are required.'); return; }
        // Start from the order as stored, so fields this form does not show
        // (consent, location, who entered it) go back untouched.
        var out = JSON.parse(JSON.stringify(d));
        out.customer = customer.value.trim();
        out.address = address.value.trim();
        out.unit = unit.value.trim();
        out.cellNumber = cell.value.trim();
        out.notes = notes.value;
        out.donationAmount = parseFloat(donation.value) || 0;
        out.paymentMethod = pay;
        out.checkNumber = pay === 'check' ? checkNo.value.trim() : '';
        out.products = [];
        qtyInputs.forEach(function (q) {
          var n = parseInt(q.el.value, 10) || 0;
          if (n > 0) { out.products.push({ id: q.p.id, name: q.p.name, price: priceFor(q.p), qty: n }); }
        });
        save.disabled = true; save.textContent = 'Saving…';
        api('/admin-app/orders/' + id, { method: 'PUT', body: out }).then(function () {
          toast('Order saved.'); leave();
        }).catch(function (e) {
          save.disabled = false; save.textContent = 'Save order';
          if (e.message !== 'signed out') { toast(e.message); }
        });
      });

      kids.push(h('div', { class: 'actions' }, [h('button', { type: 'button', text: 'Cancel', onclick: leave }), save]));
      kids.push(h('div', { class: 'note', text: 'Taken by ' + (o.seller || 'unknown') + (o.team ? ' · ' + o.team : '') + ' · ' + dayLabel(o.day) }));
      body.replaceChildren.apply(body, kids);
      total();
    }
  }

  // ---- router -------------------------------------------------------------

  function render() {
    if (!cfg.nonce) { viewSignIn(); return; }
    window.scrollTo(0, 0);
    if (state.tab === 'texts') { return state.chat ? viewChat(state.chat) : viewTexts(); }
    if (state.tab === 'orders') { return state.order ? viewOrder(state.order) : viewOrders(); }
    viewDash();
  }

  if ('serviceWorker' in navigator && cfg.base) {
    navigator.serviceWorker.register(cfg.base + 'sw.js', { scope: cfg.base }).catch(function () {});
  }
  showOffline();
  render();
})();
