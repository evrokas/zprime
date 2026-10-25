// ZPrime "Forecast" block: next 24 hours and 7 days. Text from the server goes in through textContent only.
(function () {
  'use strict';
  var root = document.querySelector('[data-forecast]');
  if (!root) return;
  var body = root.querySelector('[data-fc-body]');
  var updated = root.querySelector('[data-fc-updated]');
  var lastLoad = 0;
  var data = null;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function deg(v) { return Math.round(v) + '°'; }
  /* Drawn icons instead of emoji: emoji are drawn by each phone's own font, and some (fog above all) are
     unrecognisable there. Same shapes everywhere; colour comes from CSS (.s sun, .c cloud, .d drops). */
  var NS = 'http://www.w3.org/2000/svg';
  var CLOUD = 'M7 16.5a3.6 3.6 0 0 1-.5-7.2 5.6 5.6 0 0 1 10.7-1.1A4.2 4.2 0 0 1 17.5 16.5z';
  function sv(tag, attrs, cls) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (cls) e.setAttribute('class', cls);
    return e;
  }
  function sun(g, cx, cy, r, from, to, angles) {
    g.appendChild(sv('circle', { cx: cx, cy: cy, r: r }, 's'));
    angles.forEach(function (a) {
      var rad = a * Math.PI / 180, c = Math.cos(rad), s = Math.sin(rad);
      g.appendChild(sv('line', { x1: (cx + c * from).toFixed(2), y1: (cy + s * from).toFixed(2), x2: (cx + c * to).toFixed(2), y2: (cy + s * to).toFixed(2) }, 's'));
    });
  }
  function cloud(g, dy) { g.appendChild(sv('path', { d: CLOUD, transform: 'translate(0 ' + dy + ')' }, 'c')); }
  function drops(g, d) { g.appendChild(sv('path', { d: d }, 'd')); }
  var KIND = {};
  [[0, 'clear'], [1, 'partly'], [2, 'partly'], [3, 'cloud'], [45, 'fog'], [48, 'fog'], [56, 'drizzle'], [66, 'rain'], [77, 'snow']].forEach(function (p) { KIND[p[0]] = p[1]; });
  for (var c = 51; c <= 57; c++) KIND[c] = 'drizzle';
  for (c = 61; c <= 67; c++) KIND[c] = 'rain';
  for (c = 80; c <= 82; c++) KIND[c] = 'rain';
  for (c = 71; c <= 77; c++) KIND[c] = 'snow';
  KIND[85] = KIND[86] = 'snow';
  for (c = 95; c <= 99; c++) KIND[c] = 'storm';

  function icon(code, fallback) {
    var holder = el('span', 'fc-icon');
    holder.setAttribute('aria-hidden', 'true');
    var kind = KIND[code];
    // Default is the emoji the server sends; 'drawn' (config forecast_icons) uses the SVG set below.
    if (!kind || root.getAttribute('data-icons') !== 'drawn') { holder.textContent = fallback || '·'; return holder; }
    var s = sv('svg', { viewBox: '0 0 24 24', focusable: 'false' });
    switch (kind) {
      case 'clear': sun(s, 12, 12, 4.2, 7, 9.8, [0, 45, 90, 135, 180, 225, 270, 315]); break;
      case 'partly':
        sun(s, 8.5, 8.5, 3.2, 5.2, 7, [180, 225, 270, 315, 0]);
        s.appendChild(sv('path', { d: CLOUD, transform: 'translate(2 3.5)' }, 'c f'));
        break;
      case 'cloud': cloud(s, 2.5); s.lastChild.setAttribute('class', 'c'); break;
      case 'fog':   // staggered mist lines, no cloud: the cloud-with-lines version read as a smudge on phones
        s.appendChild(sv('path', { d: 'M3.5 6.5h13M7.5 11h13M3.5 15.5h13M7.5 20h13' }, 'l'));
        break;
      case 'drizzle': cloud(s, -2); drops(s, 'M8 18l-.8 2M12 18l-.8 2M16 18l-.8 2'); break;
      case 'rain': cloud(s, -2); drops(s, 'M8.5 17.5l-1.5 4.5M12.5 17.5L11 22M16.5 17.5L15 22'); break;
      case 'snow':
        cloud(s, -2);
        [[8, 19], [12, 21.5], [16, 19]].forEach(function (p) { s.appendChild(sv('circle', { cx: p[0], cy: p[1], r: 1 }, 'd f')); });
        break;
      case 'storm':
        cloud(s, -2);
        s.appendChild(sv('path', { d: 'M13 13.5l-3.2 4.8h3L11.6 23l4.4-6.2h-3l1.4-3.3z' }, 'b f'));
        break;
    }
    holder.appendChild(s);
    return holder;
  }
  function pop(v) {
    var s = el('span', 'fc-pop' + (v != null && v >= 40 ? ' is-wet' : ''), v != null && v >= 10 ? v + '%' : '');
    if (v != null && v >= 10) s.title = 'Chance of precipitation';
    return s;
  }
  function dayName(date, i) {
    if (i === 0) return 'Today';
    if (i === 1) return 'Tomorrow';
    return new Date(date + 'T12:00:00Z').toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' });
  }
  function dayDate(date) {
    return new Date(date + 'T12:00:00Z').toLocaleDateString(undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' });
  }

  /* One day open at a time: opening a day closes every other one. */
  function toggleDay(list, li, btn, panel, d) {
    var open = btn.getAttribute('aria-expanded') !== 'true';
    Array.prototype.forEach.call(list.querySelectorAll('.fc-day[aria-expanded="true"]'), function (b) {
      b.setAttribute('aria-expanded', 'false');
      b.parentNode.classList.remove('is-open');
      b.parentNode.querySelector('.fc-panel').hidden = true;
    });
    if (!open) return;
    if (!panel.firstChild) panel.appendChild(hoursTable(d));
    btn.setAttribute('aria-expanded', 'true');
    li.classList.add('is-open');
    panel.hidden = false;
    // rows above may have just collapsed and shifted the page: keep the opened day on screen
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    li.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
  }

  function hoursTable(d) {
    var wrap = el('div', 'fc-table-wrap');
    if (!d.hours || !d.hours.length) { wrap.appendChild(el('p', 'empty', 'No hourly data for this day.')); return wrap; }
    var tbl = el('table', 'fc-table');
    tbl.appendChild(el('caption', 'fc-sr', 'Hour by hour for ' + dayName(d.date, 99) + ' ' + dayDate(d.date)));
    var head = el('tr');
    [['Time'], ['Weather'], ['Temp'], ['Rain'], ['Wind', data.speed_unit]].forEach(function (h) {
      var th = el('th', '', h[0]); th.scope = 'col';
      if (h[1]) th.appendChild(el('small', '', h[1]));      // the unit lives in the header so the cells stay narrow
      head.appendChild(th);
    });
    var thead = el('thead'); thead.appendChild(head); tbl.appendChild(thead);
    var tb = el('tbody');
    d.hours.forEach(function (h) {
      var tr = el('tr', h.pop != null && h.pop >= 40 ? 'is-wet' : '');
      var th = el('th', '', h.h); th.scope = 'row'; tr.appendChild(th);
      var w = el('td', 'fc-t-w'), wi = el('div', 'fc-t-wi');   // flex lives on an inner box: a flex <td> breaks table layout
      wi.appendChild(icon(h.code, h.icon)); wi.appendChild(el('span', '', h.label)); w.appendChild(wi); tr.appendChild(w);
      tr.appendChild(el('td', 'fc-t-temp', deg(h.temp)));
      var rain = el('td', 'fc-t-rain');
      rain.appendChild(el('span', '', h.pop == null ? '–' : h.pop + '%'));
      if (h.precip != null && h.precip > 0) rain.appendChild(el('small', '', h.precip + ' ' + data.precip_unit));
      tr.appendChild(rain);
      tr.appendChild(el('td', 'fc-t-wind', h.wind == null ? '–' : String(h.wind)));
      tb.appendChild(tr);
    });
    tbl.appendChild(tb);
    wrap.appendChild(tbl);
    return wrap;
  }

  function render() {
    body.textContent = '';

    body.appendChild(el('h3', 'fc-sub', 'Next 24 hours'));
    var hours = el('ul', 'fc-hours');
    data.hourly.forEach(function (h, i) {
      var li = el('li', 'fc-hour' + (i === 0 ? ' is-now' : ''));
      var time = i === 0 ? 'Now' : h.t.slice(11, 16);
      li.appendChild(el('span', 'fc-h-time', time));
      li.appendChild(icon(h.code, h.icon));
      li.appendChild(el('span', 'fc-h-temp', deg(h.temp)));
      li.appendChild(pop(h.pop));
      li.setAttribute('aria-label', time + ', ' + h.label + ', ' + deg(h.temp) + (h.pop != null && h.pop >= 10 ? ', ' + h.pop + '% chance of precipitation' : ''));
      li.title = h.label;
      hours.appendChild(li);
    });
    body.appendChild(hours);

    var n = data.daily.length;
    body.appendChild(el('h3', 'fc-sub', 'Next ' + n + ' days'));
    var lo = Infinity, hi = -Infinity;
    data.daily.forEach(function (d) { lo = Math.min(lo, d.tmin); hi = Math.max(hi, d.tmax); });
    var span = Math.max(1, hi - lo);
    var days = el('ul', 'fc-days');
    data.daily.forEach(function (d, i) {
      var li = el('li', 'fc-item' + (i >= 7 ? ' is-far' : ''));
      var panelId = 'fc-day-' + i;

      // the whole row is one button: tap a day to see its hours
      var btn = el('button', 'fc-day');
      btn.type = 'button';
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-controls', panelId);
      var name = el('span', 'fc-d-name', dayName(d.date, i));
      name.appendChild(el('small', '', dayDate(d.date)));
      btn.appendChild(name);
      btn.appendChild(icon(d.code, d.icon));
      btn.appendChild(el('span', 'fc-d-label', d.label));
      btn.appendChild(pop(d.pop));

      var range = el('span', 'fc-d-range');
      range.appendChild(el('span', 'lo', deg(d.tmin)));
      var track = el('span', 'fc-track'), fill = el('span', 'fc-fill');
      fill.style.left = ((d.tmin - lo) / span * 100).toFixed(1) + '%';
      fill.style.width = Math.max(4, (d.tmax - d.tmin) / span * 100).toFixed(1) + '%';
      track.appendChild(fill);
      range.appendChild(track);
      range.appendChild(el('span', 'hi', deg(d.tmax)));
      btn.appendChild(range);

      var meta = [];
      if (d.precip > 0) meta.push(d.precip + ' ' + data.precip_unit);
      if (d.wind > 0) meta.push('wind ' + d.wind + ' ' + data.speed_unit);
      // On a phone the label column is hidden, so the condition leads the detail line instead:
      // an icon is never the only thing saying what the weather is.
      var metaEl = el('span', 'fc-d-meta');
      metaEl.appendChild(el('span', 'fc-d-mlabel', d.label + (meta.length ? ' · ' : '')));
      metaEl.appendChild(document.createTextNode(meta.join(' · ')));
      btn.appendChild(metaEl);
      btn.appendChild(el('span', 'fc-chev'));
      btn.setAttribute('aria-label', dayName(d.date, i) + ', ' + d.label + ', low ' + deg(d.tmin) + ', high ' + deg(d.tmax) +
        (d.pop != null && d.pop >= 10 ? ', ' + d.pop + '% chance of precipitation' : '') + (i >= 7 ? ', outlook' : '') + '. Show hours.');
      li.appendChild(btn);

      var panel = el('div', 'fc-panel');
      panel.id = panelId;
      panel.hidden = true;
      li.appendChild(panel);
      btn.addEventListener('click', function () { toggleDay(days, li, btn, panel, d); });
      days.appendChild(li);
    });
    body.appendChild(days);
    if (n > 7) body.appendChild(el('p', 'fc-note', 'Beyond the first week the forecast is an outlook: less reliable the further ahead it looks.'));

    var t = new Date(data.fetched * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
    updated.textContent = data.stale ? 'Last known forecast, updated ' + t : 'Updated ' + t;
  }

  function load() {
    fetch(root.getAttribute('data-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (j) { data = j; lastLoad = Date.now(); render(); })
      .catch(function () {
        if (data) { updated.textContent = 'Update failed'; return; }
        body.textContent = '';
        body.appendChild(el('p', 'empty', 'The forecast is unavailable right now.'));
      });
  }

  load();
  setInterval(function () { if (document.visibilityState === 'visible') load(); }, 30 * 60 * 1000);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && Date.now() - lastLoad > 30 * 60 * 1000) load();
  });
})();
