/* Gildana Listening — vanilla, no build step, no dependencies. */
(function () {
  'use strict';

  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  /* One fetch helper for every mutating call. The sibling apps repeat this
     block per action; keeping it in one place is what stops them drifting. */
  function api(url, data, btn) {
    var body = new URLSearchParams(data || {});
    body.set('csrf_token', CSRF);
    body.set('ajax', '1');

    var label = null;
    if (btn) { label = btn.innerHTML; btn.disabled = true; btn.classList.add('busy'); }

    return fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json().catch(function () { throw new Error('Bad response'); }); })
      .then(function (d) {
        if (!d.ok) { throw new Error(d.error || 'Something went wrong'); }
        return d;
      })
      .catch(function (err) { showToast(err.message || 'Network error', true); throw err; })
      .finally(function () {
        if (btn) { btn.disabled = false; btn.classList.remove('busy'); if (label !== null) btn.innerHTML = label; }
      });
  }

  function showToast(msg, isErr) {
    var el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.className = 'toast'; }, 3200);
  }

  /* Replace a mention card with the server-rendered version it sends back. */
  function swapCard(el, html) {
    if (!el || !html) return;
    el.outerHTML = html;
  }

  /* ── mention actions (feed + dashboard) ── */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-mention-action]');
    if (btn) {
      ev.preventDefault();
      var card = btn.closest('[data-mention-id]');
      if (!card) return;
      api('mention_action.php', {
        id: card.getAttribute('data-mention-id'),
        action: btn.getAttribute('data-mention-action'),
        value: btn.getAttribute('data-value') || ''
      }, btn).then(function (d) {
        if (d.card) swapCard(card, d.card);
        if (d.message) showToast(d.message);
      }).catch(function () {});
      return;
    }

    /* ── "Check now" on the sources page ── */
    var fetchBtn = ev.target.closest('[data-fetch-source]');
    if (fetchBtn) {
      ev.preventDefault();
      api('source_fetch.php', { id: fetchBtn.getAttribute('data-fetch-source') }, fetchBtn)
        .then(function (d) { showToast(d.message || 'Done'); if (d.reload) location.reload(); })
        .catch(function () {});
      return;
    }

    /* ── credential "Test connection" buttons ── */
    var testBtn = ev.target.closest('[data-test]');
    if (testBtn) {
      ev.preventDefault();
      var out = document.getElementById(testBtn.getAttribute('data-test-out') || '');
      api(testBtn.getAttribute('data-test-url') || '', { action: testBtn.getAttribute('data-test') }, testBtn)
        .then(function (d) {
          if (out) { out.textContent = d.message || 'OK'; out.className = 'pill green'; }
          showToast(d.message || 'Connected');
        })
        .catch(function (err) {
          if (out) { out.textContent = err.message; out.className = 'pill red'; }
        });
      return;
    }

    /* ── modal open / close ── */
    var opener = ev.target.closest('[data-modal]');
    if (opener) {
      ev.preventDefault();
      var m = document.getElementById(opener.getAttribute('data-modal'));
      if (m) {
        m.classList.add('open');
        // Prefill fields from the trigger's data-set-* attributes.
        Array.prototype.forEach.call(opener.attributes, function (a) {
          if (a.name.indexOf('data-set-') !== 0) return;
          var f = m.querySelector('[name="' + a.name.slice(9) + '"]');
          if (!f) return;
          if (f.type === 'checkbox') f.checked = a.value === '1';
          else f.value = a.value;
        });
        var title = opener.getAttribute('data-modal-title');
        if (title) { var h = m.querySelector('h2'); if (h) h.textContent = title; }
      }
      return;
    }
    if (ev.target.classList.contains('modal-back') || ev.target.classList.contains('modal-x')) {
      var back = ev.target.closest('.modal-back');
      if (back) back.classList.remove('open');
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var open = document.querySelector('.modal-back.open');
    if (open) open.classList.remove('open');
  });

  /* Auto-submit filter selects without needing an inline onchange on each one. */
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-autosubmit]')) ev.target.form.submit();
  });

  window.showToast = showToast;
  window.api = api;
})();
