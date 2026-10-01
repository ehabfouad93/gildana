/* J Vacations — vanilla, no build step, no dependencies. */
(function () {
  'use strict';

  /* Filters that submit on change. */
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });

  /* Confirm before destructive buttons / forms. */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-confirm]');
    if (btn && !window.confirm(btn.getAttribute('data-confirm'))) ev.preventDefault();

    var tg = ev.target.closest('[data-toggle]');
    if (tg) {
      var row = document.getElementById(tg.getAttribute('data-toggle'));
      if (row) row.hidden = !row.hidden;
    }
  });
  document.querySelectorAll('[data-confirm-form]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm-form'))) ev.preventDefault();
    });
  });

  /* Payment form: the amount field only matters for a partial payment. */
  document.querySelectorAll('.pay-form').forEach(function (f) {
    var sel = f.querySelector('[data-pay-status]');
    var amt = f.querySelector('[data-partial-only]');
    function sync() { if (amt) amt.style.display = sel.value === 'partial' ? '' : 'none'; }
    if (sel) { sel.addEventListener('change', sync); sync(); }
  });

  /* Open the row an anchor points at (#i123 → its payment form). */
  if (location.hash && /^#i\d+$/.test(location.hash)) {
    var r = document.getElementById(location.hash.slice(1));
    if (r) r.classList.add('row-flash');
  }

  /* ── Contract form: live payment plan. Mirrors build_schedule() in PHP:
     down = round(total × pct), monthly = remaining ÷ months rounded down to a
     whole unit, the last instalment absorbs the difference. ── */
  var form = document.getElementById('contractForm');
  if (form) {
    var card = form.querySelector('.calc-card');
    var cur = card ? card.getAttribute('data-currency') : '';
    var $ = function (id) { return document.getElementById(id); };

    var fmt = function (cents) {
      var n = cents / 100;
      var s = n.toLocaleString('en-US', { minimumFractionDigits: cents % 100 ? 2 : 0, maximumFractionDigits: 2 });
      return s + ' ' + cur;
    };
    var addMonths = function (ymd, n) {
      var p = ymd.split('-').map(Number);
      var m0 = p[1] - 1 + n, y = p[0] + Math.floor(m0 / 12), m = m0 % 12;
      var last = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
      var d = Math.min(p[2], last);
      return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    };

    var calc = function () {
      var raw = (form.total_amount.value || '').replace(/[,\s]/g, '');
      var total = /^\d+(\.\d{1,2})?$/.test(raw) ? Math.round(parseFloat(raw) * 100) : 0;
      var pct = parseFloat(form.down_pct.value) || 0;
      var mEl = form.querySelector('input[name="months"]:checked');
      var months = mEl ? parseInt(mEl.value, 10) : 0;
      var first = form.first_due_date.value;

      $('cPct').textContent = pct;
      $('cTotal').textContent = total ? fmt(total) : '—';
      if (!total) { ['cDown', 'cRest', 'cMonthly', 'cMonths', 'cEnd', 'cFirst'].forEach(function (k) { $(k).textContent = '—'; }); $('cLastRow').hidden = true; return; }

      var down = Math.round(total * pct / 100);
      var rest = total - down;
      $('cDown').textContent = fmt(down);
      $('cRest').textContent = fmt(rest);
      $('cFirst').textContent = first || '—';
      if (!months) { $('cMonthly').textContent = '—'; $('cMonths').textContent = '—'; $('cEnd').textContent = '—'; $('cLastRow').hidden = true; return; }

      var base = Math.floor(rest / (months * 100)) * 100;
      if (base === 0) base = Math.floor(rest / months);
      var last = rest - base * (months - 1);
      $('cMonths').textContent = months;
      $('cMonthly').textContent = fmt(base);
      $('cLastRow').hidden = last === base;
      $('cLast').textContent = fmt(last);
      $('cEnd').textContent = first ? addMonths(first, months - 1) : '—';
    };
    form.addEventListener('input', calc);
    form.addEventListener('change', calc);
    calc();
  }
})();
