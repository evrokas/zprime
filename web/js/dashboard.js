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
    if (dlg.id === 'link-dialog') { set('title', d.title); set('url', d.url); }
    else { set('name', d.name); set('where', d.where); }
  }
  document.addEventListener('click', function (ev) {
    var open = ev.target.closest('[data-open-dialog]');
    if (open) {
      var dlg = document.getElementById(open.getAttribute('data-open-dialog'));
      if (!dlg) return;
      fill(dlg, open.hasAttribute('data-reset') ? {} : {
        id: open.getAttribute('data-id'), title: open.getAttribute('data-title'), url: open.getAttribute('data-url'),
        name: open.getAttribute('data-name'), where: open.getAttribute('data-where')
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

  /* ---------- confirm before removing ---------- */
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  /* ---------- label the theme button with the current choice ---------- */
  var tb = document.querySelector('[data-theme-toggle]');
  if (tb) tb.textContent = 'Theme: ' + readTheme();
})();
