// ZPrime: clock, weather refresh, edit mode, add/edit dialogs, theme.
(function () {
  'use strict';

  /* ---------- theme (auto / light / dark), remembered per device ---------- */
  var THEME_KEY = 'zprime_theme';
  var root = document.documentElement;
  function readTheme() { try { return localStorage.getItem(THEME_KEY) || 'auto'; } catch (e) { return 'auto'; } }
  function applyTheme(t) {
    if (t === 'light' || t === 'dark') root.setAttribute('data-theme', t); else root.removeAttribute('data-theme');
  }
  applyTheme(readTheme());
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-theme-toggle]');
    if (!btn) return;
    var next = { auto: 'light', light: 'dark', dark: 'auto' }[readTheme()] || 'auto';
    try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* storage blocked: still apply for this visit */ }
    applyTheme(next);
    btn.textContent = 'Theme: ' + next;
  });

  /* ---------- clock ---------- */
  var clock = document.querySelector('[data-clock]');
  if (clock) {
    var tz = clock.getAttribute('data-tz') || undefined;
    var hmEl = clock.querySelector('[data-clock-hm]');
    var sEl = clock.querySelector('[data-clock-s]');
    var dEl = clock.querySelector('[data-clock-date]');
    var timeFmt, dateFmt;
    try {
      timeFmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: tz });
      dateFmt = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: tz });
    } catch (e) {
      timeFmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
      dateFmt = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    var tick = function () {
      var now = new Date();
      var t = timeFmt.format(now).split(':');
      hmEl.textContent = t[0] + ':' + t[1];
      sEl.textContent = t[2];
      dEl.textContent = dateFmt.format(now);
    };
    tick();
    setInterval(tick, 1000);
  }

  /* ---------- weather: refresh now and every 10 minutes ---------- */
  var wx = document.querySelector('[data-weather]');
  if (wx) {
    var tEl = wx.querySelector('[data-wx-temp]');
    var lEl = wx.querySelector('[data-wx-label]');
    var url = wx.getAttribute('data-weather-url');
    var refresh = function () {
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('weather ' + r.status); return r.json(); })
        .then(function (d) {
          tEl.textContent = Math.round(d.temp) + '°' + d.unit;
          lEl.textContent = d.label + (d.stale ? ' (old)' : '');
        })
        .catch(function () { if (!tEl.textContent.trim() || tEl.textContent.trim() === '--') tEl.textContent = '—'; });
    };
    refresh();
    setInterval(refresh, 600000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
  }

  /* ---------- edit mode per block ---------- */
  document.addEventListener('click', function (ev) {
    var tog = ev.target.closest('[data-edit-toggle]');
    if (!tog) return;
    var block = tog.closest('[data-block]');
    var on = !block.classList.contains('editing');
    block.classList.toggle('editing', on);
    tog.setAttribute('aria-pressed', on ? 'true' : 'false');
    tog.textContent = on ? 'Done' : 'Edit';
  });

  /* ---------- add / edit dialogs ---------- */
  function fill(dlg, d) {
    var f = dlg.querySelector('form');
    var set = function (name, v) { var el = f.elements[name]; if (el) el.value = v || ''; };
    var isEdit = !!d.id;
    set('id', d.id);
    dlg.querySelector('[data-dlg-title]').textContent = (isEdit ? 'Edit ' : 'Add ') + (dlg.id === 'link-dialog' ? 'website' : 'place');
    if (dlg.id === 'link-dialog') {
      set('title', d.title); set('url', d.url);
      var row = dlg.querySelector('[data-icon-row]');
      if (row) {
        row.hidden = !isEdit;
        row.setAttribute('data-link-id', d.id || '');
        paintFav(row.querySelector('[data-dlg-fav]'), d.icon, d.title);
        setIconStatus(dlg, d.icon ? 'Fetched from the site itself.' : 'No icon yet. Try refreshing.', false);
      }
    }
    else { set('name', d.name); set('where', d.where); }
  }
  document.addEventListener('click', function (ev) {
    var open = ev.target.closest('[data-open-dialog]');
    if (open) {
      var dlg = document.getElementById(open.getAttribute('data-open-dialog'));
      if (!dlg) return;
      fill(dlg, open.hasAttribute('data-reset') ? {} : {
        id: open.getAttribute('data-id'), title: open.getAttribute('data-title'), url: open.getAttribute('data-url'),
        name: open.getAttribute('data-name'), where: open.getAttribute('data-where'),
        icon: open.getAttribute('data-icon')
      });
      if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
      var first = dlg.querySelector('input[type=text]');
      if (first) first.focus();
      return;
    }
    var close = ev.target.closest('[data-close-dialog]');
    if (close) {
      var d = close.closest('dialog');
      if (d.close) d.close(); else d.removeAttribute('open');
      return;
    }
    // a tap on the dimmed backdrop closes the dialog
    if (ev.target.tagName === 'DIALOG' && ev.target.close) ev.target.close();
  });

  /* ---------- website icons ---------- */
  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }
  // Shows the icon inside a .fav tile, or its letter when there is none / it fails to load.
  function paintFav(tile, iconUrl, title) {
    if (!tile) return;
    var letter = tile.getAttribute('data-letter') || ((title || '?').trim().charAt(0).toUpperCase() || '?');
    tile.textContent = '';
    if (iconUrl) {
      var img = document.createElement('img');
      img.alt = ''; img.width = 24; img.height = 24;
      img.src = iconUrl;
      tile.appendChild(img);
      tile.classList.add('fav-icon');
    } else {
      tile.textContent = letter;
      tile.classList.remove('fav-icon');
    }
  }
  // A broken image falls back to the letter tile.
  document.addEventListener('error', function (ev) {
    var img = ev.target;
    if (img && img.tagName === 'IMG' && img.parentNode && img.parentNode.hasAttribute('data-fav')) {
      paintFav(img.parentNode, '', '');
    }
  }, true);
  function setIconStatus(dlg, text, isError) {
    var el = dlg && dlg.querySelector('[data-icon-status]');
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('is-error', !!isError);
  }
  var ICON_ERRORS = {
    403: 'Your session expired. Reload the page and try again.',
    401: 'Please sign in again.',
    404: 'That website no longer exists.'
  };
  // Asks the server to (re)fetch the icon of one website. Calls back with {ok, icon|error}.
  function refreshIcon(base, id, done) {
    fetch(base + '/' + encodeURIComponent(id) + '/icon/refresh', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken(), Accept: 'application/json' }
    })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: ICON_ERRORS[r.status] || 'The server could not refresh the icon.' }; });
      })
      .then(done)
      .catch(function () { done({ ok: false, error: 'No connection. Try again in a moment.' }); });
  }
  function applyIconToCard(id, iconUrl) {
    var li = document.querySelector('[data-link-id="' + id + '"][data-needs-icon], .site-item[data-link-id="' + id + '"]');
    if (!li) return;
    li.removeAttribute('data-needs-icon');
    paintFav(li.querySelector('[data-fav]'), iconUrl, '');
    var edit = li.querySelector('[data-open-dialog][data-id]');
    if (edit) edit.setAttribute('data-icon', iconUrl || '');
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-refresh-icon]');
    if (!btn) return;
    var dlg = btn.closest('dialog');
    var row = btn.closest('[data-icon-row]');
    var id = row && row.getAttribute('data-link-id');
    if (!id) return;
    btn.disabled = true;
    setIconStatus(dlg, 'Looking for the icon\u2026', false);
    refreshIcon(dlg.getAttribute('data-icon-base'), id, function (res) {
      btn.disabled = false;
      if (res.ok) {
        paintFav(row.querySelector('[data-dlg-fav]'), res.icon, '');
        applyIconToCard(id, res.icon);
        setIconStatus(dlg, 'Icon updated.', false);
      } else {
        setIconStatus(dlg, res.error || 'The icon could not be refreshed.', true);
      }
    });
  });
  // Websites that were never tried (just added, or saved before icons existed) get their icon
  // fetched once, one after another, without holding up the page.
  (function autoFetch() {
    var block = document.querySelector('[data-links]');
    if (!block) return;
    var dlg = block.querySelector('dialog[data-icon-base]');
    if (!dlg) return;
    var base = dlg.getAttribute('data-icon-base');
    var queue = Array.prototype.slice.call(block.querySelectorAll('.site-item[data-needs-icon]'));
    (function next() {
      var li = queue.shift();
      if (!li) return;
      var id = li.getAttribute('data-link-id');
      refreshIcon(base, id, function (res) {
        li.removeAttribute('data-needs-icon');
        if (res.ok) applyIconToCard(id, res.icon);
        next();
      });
    })();
  })();

  /* ---------- reorder: move an item one step earlier / later ---------- */
  // Keeps the first item's "earlier" and the last item's "later" button disabled.
  function updateMoveButtons(list) {
    var items = list.querySelectorAll(':scope > li');
    Array.prototype.forEach.call(items, function (li, i) {
      var up = li.querySelector('[data-move="up"]');
      var down = li.querySelector('[data-move="down"]');
      if (up) up.disabled = i === 0;
      if (down) down.disabled = i === items.length - 1;
    });
  }
  function moveStatus(block, text) {
    var el = block.querySelector('[data-move-status]');
    if (!el) return;
    el.textContent = text || '';
    el.hidden = !text;
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-move]');
    if (!btn || btn.disabled) return;
    var li = btn.closest('li[data-item-id]');
    var list = li && li.parentNode;
    var block = btn.closest('[data-block]');
    if (!li || !list || !list.hasAttribute('data-move-base')) return;
    var dir = btn.getAttribute('data-move');

    // Lock the whole list while the request runs, so two quick taps cannot race.
    var buttons = list.querySelectorAll('[data-move]');
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
    moveStatus(block, '');

    fetch(list.getAttribute('data-move-base') + '/' + encodeURIComponent(li.getAttribute('data-item-id')) + '/move', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken(), 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: 'dir=' + encodeURIComponent(dir)
    })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'The server could not save the new order.' }; })
          .then(function (j) { if (!r.ok && !j.error) j.error = ICON_ERRORS[r.status]; return j; });
      })
      .catch(function () { return { ok: false, error: 'No connection. The order was not changed.' }; })
      .then(function (res) {
        if (res.ok) {
          if (dir === 'up' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
          if (dir === 'down' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
        } else {
          moveStatus(block, res.error || 'The order was not changed.');
        }
        updateMoveButtons(list);
        // keep keyboard focus on a button that still works
        var again = li.querySelector('[data-move="' + dir + '"]');
        var other = li.querySelector('[data-move="' + (dir === 'up' ? 'down' : 'up') + '"]');
        if (again && !again.disabled) again.focus(); else if (other && !other.disabled) other.focus();
      });
  });

  /* ---------- confirm before removing ---------- */
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  /* ---------- label the theme button with the current choice ---------- */
  var tb = document.querySelector('[data-theme-toggle]');
  if (tb) tb.textContent = 'Theme: ' + readTheme();
})();
