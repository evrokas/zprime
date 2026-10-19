// ZPrime "System" block: status tiles with sparklines, and a detail chart (hover/touch readout,
// legend, table view). All text from the server goes in through textContent, never innerHTML.
(function () {
  'use strict';
  var root = document.querySelector('[data-sysmon]');
  if (!root) return;

  var NS = 'http://www.w3.org/2000/svg';
  var hostsEl = root.querySelector('[data-sys-hosts]');
  var updatedEl = root.querySelector('[data-sys-updated]');
  var dlg = document.getElementById('sys-dialog');
  var data = null;
  var timer = null;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function svg(tag, attrs) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }

  /* ---------- formatting ---------- */
  function num(v, d) {
    return v.toLocaleString(undefined, { maximumFractionDigits: d, minimumFractionDigits: 0 });
  }
  function scaled(v, steps, base) {
    var i = 0;
    while (Math.abs(v) >= base && i < steps.length - 1) { v /= base; i++; }
    return num(v, v >= 100 ? 0 : v >= 10 ? 1 : 2) + ' ' + steps[i];
  }
  function fmt(v, unit) {
    if (v == null || !isFinite(v)) return '–';
    switch (unit) {
      case '%': return num(v, v >= 10 ? 0 : 1) + '%';
      case '°C': return num(v, 1) + '°C';
      case 'B': return scaled(v, ['B', 'kB', 'MB', 'GB', 'TB'], 1024);
      case 'B/s': return scaled(v, ['B/s', 'kB/s', 'MB/s', 'GB/s'], 1024);
      case 'rpm': return num(v, 0) + ' rpm';
      case 's': return uptime(v);
      case '/s': return num(v, 2) + '/s';
      default: return num(v, 2);
    }
  }
  function uptime(s) {
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    return d ? d + 'd ' + h + 'h' : h ? h + 'h ' + m + 'm' : m + 'm';
  }
  function when(ts, spanSeconds) {
    var d = new Date(ts * 1000);
    var t = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
    if (spanSeconds <= 86400 * 1.5) return t;
    return d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + (spanSeconds <= 86400 * 8 ? ' ' + t : '');
  }

  /* ---------- tiles ---------- */
  // Thresholds [warn, critical]; flagged tiles say so in words and an icon, never by colour alone.
  var TILES = [
    { key: 'cpu', label: 'CPU', metrics: [['cpu.load', 'Load'], ['cpu.iowait', 'I/O wait']], th: [75, 90] },
    { key: 'mem', label: 'Memory', metrics: [['mem.used_pct', 'Used'], ['mem.available_bytes', 'Available'], ['swap.used_pct', 'Swap']], th: [80, 90] },
    { key: 'disk', label: 'Disk', metrics: [['disk.used_pct', 'Used'], ['disk.free_bytes', 'Free'], ['disk.read_bps', 'Read'], ['disk.write_bps', 'Write']], th: [85, 95] },
    { key: 'temp', label: 'Temperature', metrics: [['temp.c', 'Temperature']], th: [70, 85] },
    { key: 'net', label: 'Network', metrics: [['net.rx_bps', 'Receive'], ['net.tx_bps', 'Transmit'], ['net.errors_ps', 'Errors']] },
    { key: 'fan', label: 'Fans', metrics: [['fan.rpm', 'Speed']] }
  ];

  function itemsOf(h, metric) { return h.items.filter(function (i) { return i.metric === metric; }); }
  function maxItem(list) {
    return list.reduce(function (a, b) { return !a || b.value > a.value ? b : a; }, null);
  }
  function sumOf(list) { return list.reduce(function (a, b) { return a + b.value; }, 0); }

  function tileInfo(h, t) {
    var it;
    switch (t.key) {
      case 'cpu':
        it = itemsOf(h, 'cpu.load')[0];
        return it && { value: fmt(it.value, '%'), raw: it.value, sub: '24 h max ' + fmt(it.max24h, '%'), spark: 'cpu.load' };
      case 'mem':
        it = itemsOf(h, 'mem.used_pct')[0];
        var av = itemsOf(h, 'mem.available_bytes')[0];
        return it && { value: fmt(it.value, '%'), raw: it.value, sub: av ? fmt(av.value, 'B') + ' free' : '', spark: 'mem.used_pct' };
      case 'disk':
        it = maxItem(itemsOf(h, 'disk.used_pct'));
        if (!it) return null;
        var fr = h.items.filter(function (i) { return i.metric === 'disk.free_bytes' && i.instance === it.instance; })[0];
        return { value: fmt(it.value, '%'), raw: it.value, sub: it.instance + (fr ? ' · ' + fmt(fr.value, 'B') + ' free' : '') };
      case 'temp':
        it = maxItem(itemsOf(h, 'temp.c'));
        return it && { value: fmt(it.value, '°C'), raw: it.value, sub: it.instance };
      case 'net':
        var rx = itemsOf(h, 'net.rx_bps'), tx = itemsOf(h, 'net.tx_bps');
        if (!rx.length && !tx.length) return null;
        return { value: '↓ ' + fmt(sumOf(rx), 'B/s'), sub: '↑ ' + fmt(sumOf(tx), 'B/s'), compact: true };
      case 'fan':
        it = maxItem(itemsOf(h, 'fan.rpm'));
        return it && { value: fmt(it.value, 'rpm'), sub: it.instance };
    }
  }

  function sparkline(points) {
    var W = 120, H = 28, pad = 3;
    var s = svg('svg', { 'class': 'sys-spark', viewBox: '0 0 ' + W + ' ' + H, 'aria-hidden': 'true', preserveAspectRatio: 'none' });
    if (points.length < 2) return s;
    var t0 = points[0][0], t1 = points[points.length - 1][0] || t0 + 1;
    var d = '', x, y;
    points.forEach(function (p, i) {
      x = pad + (p[0] - t0) / Math.max(1, t1 - t0) * (W - 2 * pad);
      y = H - pad - Math.min(100, Math.max(0, p[1])) / 100 * (H - 2 * pad);   // fixed 0-100 % scale: honest, not auto-zoomed
      d += (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1);
    });
    s.appendChild(svg('line', { x1: pad, x2: W - pad, y1: H - pad, y2: H - pad }));
    s.appendChild(svg('path', { d: d }));
    s.appendChild(svg('circle', { cx: x.toFixed(1), cy: y.toFixed(1), r: 3 }));
    return s;
  }

  function tile(h, t) {
    var info = tileInfo(h, t);
    if (!info) return null;
    var b = el('button', 'sys-tile');
    b.type = 'button';
    b.appendChild(el('span', 'sys-tile-label', t.label));
    b.appendChild(el('span', 'sys-tile-value' + (info.compact ? ' is-compact' : ''), info.value));
    if (info.sub) { var sub = el('span', 'sys-tile-sub', info.sub); sub.title = info.sub; b.appendChild(sub); }
    var label = t.label + ', ' + info.value + (info.sub ? ', ' + info.sub : '');
    if (t.th && info.raw != null && info.raw >= t.th[0]) {
      var crit = info.raw >= t.th[1];
      b.appendChild(el('span', 'sys-flag ' + (crit ? 'is-crit' : 'is-warn'), (crit ? '✖ Critical' : '▲ High')));
      label += ', ' + (crit ? 'critical' : 'high');
    }
    if (info.spark && h.spark && h.spark[info.spark]) b.appendChild(sparkline(h.spark[info.spark]));
    b.setAttribute('aria-label', label + '. Open details.');
    b.addEventListener('click', function () { openDetail(h, t); });
    return b;
  }

  var STATES = {
    ok: ['Online', ''], silent: ['Collector silent', 'is-warn'], stale: ['Showing last known data', 'is-warn'], offline: ['Offline', 'is-bad']
  };

  function renderHosts() {
    hostsEl.textContent = '';
    data.hosts.forEach(function (h) {
      var box = el('div', 'sys-host');
      var head = el('div', 'sys-host-head');
      head.appendChild(el('h3', '', h.name));
      var st = STATES[h.state] || STATES.offline;
      head.appendChild(el('span', 'sys-state ' + st[1], st[0]));
      var up = itemsOf(h, 'uptime.s')[0];
      if (up) head.appendChild(el('span', 'sys-uptime', 'up ' + uptime(up.value)));
      box.appendChild(head);
      var grid = el('div', 'sys-tiles');
      TILES.forEach(function (t) { var n = tile(h, t); if (n) grid.appendChild(n); });
      if (!grid.children.length) grid = el('p', 'empty', h.state === 'offline' ? 'No data: this machine cannot be reached.' : 'No data yet.');
      box.appendChild(grid);
      hostsEl.appendChild(box);
    });
    var newest = Math.max.apply(null, data.hosts.map(function (h) { return h.fetched || 0; }));
    updatedEl.textContent = newest ? 'Updated ' + when(newest, 0) : '';
  }

  function load() {
    fetch(root.getAttribute('data-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (j) { data = j; renderHosts(); })
      .catch(function () {
        if (!data) { hostsEl.textContent = ''; hostsEl.appendChild(el('p', 'empty', 'System status is unavailable right now.')); }
        else updatedEl.textContent = 'Update failed';
      });
  }
  function schedule() {
    clearInterval(timer);
    timer = setInterval(function () { if (document.visibilityState === 'visible' && !dlg.open) load(); }, 60000);
  }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && !dlg.open) load(); });

  /* ---------- detail dialog ---------- */
  var RANGES = [['1h', '1 h'], ['6h', '6 h'], ['24h', '24 h'], ['7d', '7 days'], ['30d', '30 days'], ['90d', '90 days']];
  var cur = { host: null, tile: null, metric: null, range: '24h', table: false, result: null, seq: 0 };
  var chartEl = root.querySelector('[data-sys-chart]');
  var legendEl = root.querySelector('[data-sys-legend]');
  var noteEl = root.querySelector('[data-sys-note]');
  var tableBtn = root.querySelector('[data-sys-table-toggle]');

  function seg(container, items, active, onPick) {
    container.textContent = '';
    items.forEach(function (it) {
      var b = el('button', '', it[1]);
      b.type = 'button';
      b.setAttribute('aria-pressed', it[0] === active ? 'true' : 'false');
      b.addEventListener('click', function () { onPick(it[0]); });
      container.appendChild(b);
    });
  }

  function available(h, t) {
    return t.metrics.filter(function (m) { return itemsOf(h, m[0]).length; });
  }

  function openDetail(h, t) {
    cur.host = h; cur.tile = t; cur.table = false;
    var ms = available(h, t);
    cur.metric = ms.length ? ms[0][0] : t.metrics[0][0];
    root.querySelector('[data-sys-title]').textContent = h.name + ' · ' + t.label;
    tableBtn.setAttribute('aria-pressed', 'false');
    tableBtn.textContent = 'Show table';
    drawControls();
    if (!dlg.open) dlg.showModal();
    fetchSeries();
  }
  function drawControls() {
    var ms = available(cur.host, cur.tile);
    var mbox = root.querySelector('[data-sys-metrics]');
    mbox.hidden = ms.length < 2;
    seg(mbox, ms, cur.metric, function (m) { cur.metric = m; drawControls(); fetchSeries(); });
    seg(root.querySelector('[data-sys-ranges]'), RANGES, cur.range, function (r) { cur.range = r; drawControls(); fetchSeries(); });
  }

  function fetchSeries() {
    var seq = ++cur.seq;
    chartEl.classList.add('is-loading');
    noteEl.textContent = '';
    var url = root.getAttribute('data-series-base') + '/' + cur.host.id + '/series?metric=' + encodeURIComponent(cur.metric) + '&range=' + cur.range;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (j) { if (seq !== cur.seq) return; cur.result = j; draw(); })
      .catch(function () { if (seq !== cur.seq) return; noteEl.textContent = 'This history could not be loaded.'; })
      .then(function () { if (seq === cur.seq) chartEl.classList.remove('is-loading'); });
  }

  tableBtn.addEventListener('click', function () {
    cur.table = !cur.table;
    tableBtn.setAttribute('aria-pressed', cur.table ? 'true' : 'false');
    tableBtn.textContent = cur.table ? 'Show chart' : 'Show table';
    if (cur.result) draw();
  });

  var PCT = /^(cpu\.|mem\.used|swap\.|disk\.used)/;
  function niceMax(v) {
    if (v <= 0) return 1;
    var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
  }

  function draw() {
    var res = cur.result;
    var all = res.series.slice().sort(function (a, b) { return a.instance < b.instance ? -1 : 1; });   // colour follows the instance, not its rank
    var shown = all.slice(0, 8);
    legendEl.textContent = '';
    noteEl.textContent = (res.stale ? 'Showing the last data received; the machine did not answer just now. ' : '') +
      (all.length > shown.length ? (all.length - shown.length) + ' more instance(s) are only in the table. ' : '');
    if (!shown.length) { chartEl.textContent = ''; chartEl.appendChild(el('p', 'empty', 'No data for this period.')); return; }
    if (shown.length > 1) {
      shown.forEach(function (s, i) {
        var l = el('span', '', s.instance || res.metric);
        var k = el('i'); k.style.setProperty('--c', 'var(--series-' + (i + 1) + ')');
        l.insertBefore(k, l.firstChild);
        legendEl.appendChild(l);
      });
    }
    chartEl.textContent = '';
    if (cur.table) drawTable(all, res); else drawChart(shown, res);
  }

  function drawTable(all, res) {
    var ts = {};
    all.forEach(function (s) { s.points.forEach(function (p) { ts[p[0]] = true; }); });
    var times = Object.keys(ts).map(Number).sort(function (a, b) { return b - a; });
    var span = res.to - res.from;
    var wrap = el('div', 'sys-table'), tbl = el('table'), thead = el('thead'), tr = el('tr');
    tr.appendChild(el('th', '', 'Time'));
    all.forEach(function (s) { tr.appendChild(el('th', '', s.instance || res.metric)); });
    thead.appendChild(tr); tbl.appendChild(thead);
    var maps = all.map(function (s) { var m = {}; s.points.forEach(function (p) { m[p[0]] = p[1]; }); return m; });
    var body = el('tbody');
    times.forEach(function (t) {
      var r = el('tr');
      r.appendChild(el('td', '', when(t, span)));
      maps.forEach(function (m) { r.appendChild(el('td', '', t in m ? fmt(m[t], res.unit) : '–')); });
      body.appendChild(r);
    });
    tbl.appendChild(body); wrap.appendChild(tbl); chartEl.appendChild(wrap);
  }

  function drawChart(shown, res) {
    // Drawn at the real pixel width so text stays 11 px on a phone instead of shrinking with the SVG.
    var W = Math.max(280, chartEl.clientWidth || 720), H = Math.round(Math.max(210, Math.min(300, W * 0.45))), m = { l: 52, r: 14, t: 12, b: 28 };
    var xt = W < 480 ? 2 : 4;
    var iw = W - m.l - m.r, ih = H - m.t - m.b;
    var span = res.to - res.from;
    var lo = Infinity, hi = -Infinity, t0 = res.from, t1 = res.to;
    shown.forEach(function (s) { s.points.forEach(function (p) { lo = Math.min(lo, p[2]); hi = Math.max(hi, p[3]); }); });
    var y0, y1;
    if (PCT.test(res.metric)) { y0 = 0; y1 = 100; }
    else if (res.unit === '°C') { y0 = Math.floor(lo - 3); y1 = Math.ceil(hi + 3); }
    else { y0 = 0; y1 = niceMax(hi); }
    if (y1 <= y0) y1 = y0 + 1;
    var X = function (t) { return m.l + (t - t0) / (t1 - t0) * iw; };
    var Y = function (v) { return m.t + ih - (v - y0) / (y1 - y0) * ih; };

    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', tabindex: '0',
      'aria-label': cur.host.name + ' ' + cur.tile.label + ' history. Use left and right arrow keys to read values.' });
    var axis = svg('g', { 'class': 'sys-axis' });
    for (var i = 0; i <= 4; i++) {
      var v = y0 + (y1 - y0) * i / 4, y = Y(v);
      axis.appendChild(svg('line', { x1: m.l, x2: W - m.r, y1: y, y2: y }));
      var tx = svg('text', { x: m.l - 8, y: y + 4, 'text-anchor': 'end' }); tx.textContent = fmt(v, res.unit);
      axis.appendChild(tx);
    }
    for (var j = 0; j <= xt; j++) {
      var t = t0 + (t1 - t0) * j / xt;
      var lx = svg('text', { x: X(t), y: H - 8, 'text-anchor': j === 0 ? 'start' : j === xt ? 'end' : 'middle' });
      lx.textContent = when(t, span); axis.appendChild(lx);
    }
    s.appendChild(axis);

    var step = res.bucket || 60;   // a gap bigger than 3 buckets is missing data: break the line
    shown.forEach(function (ser, idx) {
      var d = '', prev = null;
      ser.points.forEach(function (p) {
        d += (prev === null || p[0] - prev > step * 3 ? 'M' : 'L') + X(p[0]).toFixed(1) + ' ' + Y(p[1]).toFixed(1);
        prev = p[0];
      });
      var path = svg('path', { d: d, 'class': 'sys-line' });
      path.style.setProperty('--c', 'var(--series-' + (idx + 1) + ')');
      s.appendChild(path);
    });

    var cross = svg('line', { 'class': 'sys-cross', y1: m.t, y2: m.t + ih, visibility: 'hidden' });
    var dots = shown.map(function (_, idx) {
      var c = svg('circle', { r: 4, 'class': 'sys-dot', visibility: 'hidden' });
      c.style.setProperty('--c', 'var(--series-' + (idx + 1) + ')');
      return c;
    });
    s.appendChild(cross); dots.forEach(function (c) { s.appendChild(c); });
    chartEl.appendChild(s);

    var tip = el('div', 'sys-tip'); tip.hidden = true; chartEl.appendChild(tip);
    var times = [];
    shown.forEach(function (ser) { ser.points.forEach(function (p) { if (times.indexOf(p[0]) < 0) times.push(p[0]); }); });
    times.sort(function (a, b) { return a - b; });
    var pos = -1;

    function show(i) {
      pos = Math.max(0, Math.min(times.length - 1, i));
      var t = times[pos], x = X(t);
      cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.setAttribute('visibility', 'visible');
      tip.textContent = ''; tip.hidden = false;
      tip.appendChild(el('div', 'sys-tip-time', new Date(t * 1000).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false })));
      shown.forEach(function (ser, idx) {
        var p = ser.points.filter(function (q) { return q[0] === t; })[0];
        var dot = dots[idx];
        if (!p) { dot.setAttribute('visibility', 'hidden'); return; }
        dot.setAttribute('cx', x); dot.setAttribute('cy', Y(p[1])); dot.setAttribute('visibility', 'visible');
        var row = el('div', 'sys-tip-row');
        var k = el('i'); k.style.setProperty('--c', 'var(--series-' + (idx + 1) + ')');
        row.appendChild(k);
        row.appendChild(el('b', '', fmt(p[1], res.unit)));
        if (shown.length > 1) row.appendChild(el('span', '', ser.instance));
        if (p[4] > 1 && shown.length === 1) row.appendChild(el('small', '', fmt(p[2], res.unit) + ' – ' + fmt(p[3], res.unit)));
        tip.appendChild(row);
      });
      tip.style.left = Math.max(4, Math.min(W - tip.offsetWidth - 4, x + 12)) + 'px';
      tip.style.top = '8px';
    }
    function hide() {
      cross.setAttribute('visibility', 'hidden');
      dots.forEach(function (c) { c.setAttribute('visibility', 'hidden'); });
      tip.hidden = true;
    }
    function nearest(ev) {
      var r = s.getBoundingClientRect(), vx = (ev.clientX - r.left) / r.width * W;   // viewBox = pixels, so this is ~1:1
      var best = 0, bd = Infinity;
      times.forEach(function (t, i) { var dd = Math.abs(X(t) - vx); if (dd < bd) { bd = dd; best = i; } });
      return best;
    }
    s.addEventListener('pointermove', function (ev) { if (times.length) show(nearest(ev)); });
    s.addEventListener('pointerdown', function (ev) { if (times.length) show(nearest(ev)); });
    s.addEventListener('pointerleave', function (ev) { if (ev.pointerType === 'mouse') hide(); });
    s.addEventListener('blur', hide);
    s.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowLeft') { show(pos < 0 ? times.length - 1 : pos - 1); ev.preventDefault(); }
      else if (ev.key === 'ArrowRight') { show(pos < 0 ? times.length - 1 : pos + 1); ev.preventDefault(); }
      else if (ev.key === 'Escape' && pos >= 0) { hide(); pos = -1; ev.stopPropagation(); }
    });
  }

  dlg.addEventListener('close', function () { cur.seq++; load(); });
  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () { if (dlg.open && cur.result && !cur.table) draw(); }, 150);
  });

  load();
  schedule();
})();
