/* Charts: crosshair + tooltip on trends, tooltips on bars/cells, arrow keys on a focused chart. */
(function () {
  if (window.__viz) return; window.__viz = true;
  var tip = document.createElement('div');
  tip.className = 'viz-tip'; tip.setAttribute('role', 'tooltip');
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(tip); init(); });

  function fmt(v, f) {
    if (v === null || v === undefined) return '—';
    if (f === 'pct') return (Math.abs(v) < 10 && v % 1 ? v.toFixed(1) : Math.round(v).toLocaleString()) + '%';
    if (f === 'dur') {
      v = Math.round(v);
      if (v < 60) return v + 's';
      if (v < 3600) return Math.round(v / 60) + 'm';
      if (v < 86400) return Math.floor(v / 3600) + 'h ' + Math.floor((v % 3600) / 60) + 'm';
      return Math.floor(v / 86400) + 'd ' + Math.floor((v % 86400) / 3600) + 'h';
    }
    if (f === 'dec') return v.toFixed(1);
    return Math.round(v).toLocaleString();
  }
  function place(x, y) {
    tip.style.display = 'block';
    var w = tip.offsetWidth, h = tip.offsetHeight;
    var left = x + 14; if (left + w > innerWidth - 8) left = x - w - 14; if (left < 8) left = 8;
    var top = y + 14; if (top + h > innerHeight - 8) top = y - h - 14;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }
  function hide() { tip.style.display = 'none'; }

  function init() {
    document.querySelectorAll('[data-tip]').forEach(function (el) {
      el.addEventListener('mousemove', function (e) { tip.textContent = el.dataset.tip; place(e.clientX, e.clientY); });
      el.addEventListener('mouseleave', hide);
    });
    document.querySelectorAll('.viz-chart[data-viz]').forEach(trend);
  }

  function trend(root) {
    var d; try { d = JSON.parse(root.dataset.viz); } catch (e) { return; }
    var plot = root.querySelector('.viz-plot'), cross = root.querySelector('.viz-cross');
    var n = d.labels.length, cols = d.type === 'columns', cur = -1;
    var dots = d.series.map(function (s) {
      if (cols) return null;
      var dot = document.createElement('span'); dot.className = 'viz-dot'; dot.style.setProperty('--c', 'var(--viz-' + s.slot + ')');
      plot.appendChild(dot); return dot;
    });
    function top() { return d.top || 1; }
    function show(i, ev) {
      if (i < 0 || i >= n) return;
      cur = i;
      var x = cols ? 100 * (i + 0.5) / n : (n > 1 ? 100 * i / (n - 1) : 50);
      cross.style.left = x + '%'; cross.style.display = 'block';
      if (cols) {
        cross.style.width = (100 / n) + '%'; cross.classList.add('band');
        root.querySelectorAll('.viz-col').forEach(function (c, k) { c.classList.toggle('on', k === i); });
      } else {
        dots.forEach(function (dot, k) {
          var v = d.series[k].values[i] || 0;
          dot.style.left = x + '%'; dot.style.bottom = (100 * v / top()) + '%'; dot.style.display = 'block';
        });
      }
      var lines = [d.labels[i]];
      var total = 0;
      d.series.forEach(function (s) { total += s.values[i] || 0; lines.push(s.label + ': ' + fmt(s.values[i] || 0, d.fmt)); });
      if (d.stack && d.series.length > 1) lines.push('Total: ' + fmt(total, d.fmt));
      tip.innerHTML = '';
      lines.forEach(function (l, k) { var p = document.createElement(k ? 'div' : 'strong'); p.textContent = l; if (k && d.series[k - 1]) { var key = document.createElement('i'); key.className = 'viz-key'; key.style.setProperty('--c', 'var(--viz-' + d.series[k - 1].slot + ')'); p.prepend(key); } tip.appendChild(p); });
      if (ev) place(ev.clientX, ev.clientY);
      else { var r = plot.getBoundingClientRect(); place(r.left + r.width * x / 100, r.top + 10); }
    }
    function clear() {
      cur = -1; hide(); cross.style.display = 'none';
      dots.forEach(function (dot) { if (dot) dot.style.display = 'none'; });
      root.querySelectorAll('.viz-col.on').forEach(function (c) { c.classList.remove('on'); });
    }
    plot.addEventListener('mousemove', function (e) {
      var r = plot.getBoundingClientRect(), fx = (e.clientX - r.left) / r.width;
      var i = cols ? Math.floor(fx * n) : Math.round(fx * (n - 1));
      show(Math.max(0, Math.min(n - 1, i)), e);
    });
    plot.addEventListener('mouseleave', clear);
    plot.addEventListener('blur', clear);
    plot.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        show(cur < 0 ? (e.key === 'ArrowRight' ? 0 : n - 1) : Math.max(0, Math.min(n - 1, cur + (e.key === 'ArrowRight' ? 1 : -1))));
      } else if (e.key === 'Escape') clear();
    });
    plot.addEventListener('focus', function () { show(n - 1); });
  }
})();
