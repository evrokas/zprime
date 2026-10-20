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
  function icon(text) { var s = el('span', 'fc-icon', text); s.setAttribute('aria-hidden', 'true'); return s; }
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

  function render() {
    body.textContent = '';

    body.appendChild(el('h3', 'fc-sub', 'Next 24 hours'));
    var hours = el('ul', 'fc-hours');
    data.hourly.forEach(function (h, i) {
      var li = el('li', 'fc-hour' + (i === 0 ? ' is-now' : ''));
      var time = i === 0 ? 'Now' : h.t.slice(11, 16);
      li.appendChild(el('span', 'fc-h-time', time));
      li.appendChild(icon(h.icon));
      li.appendChild(el('span', 'fc-h-temp', deg(h.temp)));
      li.appendChild(pop(h.pop));
      li.setAttribute('aria-label', time + ', ' + h.label + ', ' + deg(h.temp) + (h.pop != null && h.pop >= 10 ? ', ' + h.pop + '% chance of precipitation' : ''));
      li.title = h.label;
      hours.appendChild(li);
    });
    body.appendChild(hours);

    body.appendChild(el('h3', 'fc-sub', 'Next 7 days'));
    var lo = Infinity, hi = -Infinity;
    data.daily.forEach(function (d) { lo = Math.min(lo, d.tmin); hi = Math.max(hi, d.tmax); });
    var span = Math.max(1, hi - lo);
    var days = el('ul', 'fc-days');
    data.daily.forEach(function (d, i) {
      var li = el('li', 'fc-day');
      var name = el('span', 'fc-d-name', dayName(d.date, i));
      name.appendChild(el('small', '', dayDate(d.date)));
      li.appendChild(name);
      li.appendChild(icon(d.icon));
      li.appendChild(el('span', 'fc-d-label', d.label));
      li.appendChild(pop(d.pop));

      var range = el('span', 'fc-d-range');
      range.appendChild(el('span', 'lo', deg(d.tmin)));
      var track = el('span', 'fc-track'), fill = el('span', 'fc-fill');
      fill.style.left = ((d.tmin - lo) / span * 100).toFixed(1) + '%';
      fill.style.width = Math.max(4, (d.tmax - d.tmin) / span * 100).toFixed(1) + '%';
      track.appendChild(fill);
      range.appendChild(track);
      range.appendChild(el('span', 'hi', deg(d.tmax)));
      li.appendChild(range);

      var meta = [];
      if (d.precip > 0) meta.push(d.precip + ' ' + data.precip_unit);
      if (d.wind > 0) meta.push('wind ' + d.wind + ' ' + data.speed_unit);
      // On a phone the label column is hidden, so the condition leads the detail line instead:
      // an icon is never the only thing saying what the weather is.
      var metaEl = el('span', 'fc-d-meta');
      metaEl.appendChild(el('span', 'fc-d-mlabel', d.label + (meta.length ? ' · ' : '')));
      metaEl.appendChild(document.createTextNode(meta.join(' · ')));
      li.appendChild(metaEl);
      li.setAttribute('aria-label', dayName(d.date, i) + ', ' + d.label + ', low ' + deg(d.tmin) + ', high ' + deg(d.tmax) +
        (d.pop != null && d.pop >= 10 ? ', ' + d.pop + '% chance of precipitation' : ''));
      days.appendChild(li);
    });
    body.appendChild(days);

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
