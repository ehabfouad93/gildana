/* AI Studio — client interactions */
(function () {
  const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';

  window.showToast = function (msg, err) {
    const t = document.getElementById('toast');
    if (!t) { alert(msg); return; }
    t.textContent = msg;
    t.className = 'toast show' + (err ? ' err' : '');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3600);
  };

  window.copyText = function (btn) {
    const pre = btn.closest('.asset')?.querySelector('.asset-text');
    if (!pre) return;
    navigator.clipboard.writeText(pre.textContent).then(() => showToast('Copied to clipboard'));
  };

  window.testVendor = function (vendor, btn) {
    const out = btn.parentElement.querySelector('.test-result');
    out.textContent = 'Testing…'; out.className = 'test-result';
    fetch('keys.php?test=' + encodeURIComponent(vendor))
      .then(r => r.json())
      .then(d => {
        out.textContent = d.ok ? '✓ Connected' : ('✗ ' + (d.error || 'Failed'));
        out.className = 'test-result ' + (d.ok ? 'ok' : 'bad');
      })
      .catch(() => { out.textContent = '✗ Network error'; out.className = 'test-result bad'; });
  };

  window.publishAsset = function (assetId, module, btn) {
    const channel = module === 'meta_instagram' ? 'Instagram' : 'Facebook';
    const caption = prompt('Caption for ' + channel + ' (leave blank to auto-use your generated copy):', '');
    if (caption === null) return;
    btn.disabled = true; const label = btn.textContent; btn.textContent = 'Publishing…';
    const body = new URLSearchParams({ csrf_token: CSRF, asset_id: assetId, module: module, caption: caption });
    fetch('publish.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body })
      .then(r => r.json())
      .then(d => {
        btn.disabled = false; btn.textContent = label;
        if (d.ok) showToast('Published to ' + channel + '!');
        else showToast('Publish failed: ' + (d.error || 'error'), true);
      })
      .catch(() => { btn.disabled = false; btn.textContent = label; showToast('Network error', true); });
  };

  /* ── Campaign generation ── */
  const camp = document.getElementById('campaign');
  if (camp) {
    const cid = camp.dataset.id;
    let polling = false;

    document.querySelectorAll('.gen-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const stage = btn.dataset.stage;
        const grid = camp.querySelector('[data-stage-assets="' + stage + '"]');
        btn.disabled = true; const label = btn.textContent; btn.textContent = 'Generating…';
        const body = new URLSearchParams({ csrf_token: CSRF, campaign_id: cid, stage });
        fetch('generate.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body })
          .then(r => r.json())
          .then(d => {
            btn.disabled = false; btn.textContent = 'Generate again';
            if (!d.ok && !d.async) { showToast('Failed: ' + (d.error || 'error'), true); }
            if (d.card && grid) grid.insertAdjacentHTML('afterbegin', d.card);
            if (d.async || d.status === 'pending') { showToast('Video is rendering…'); startPolling(); }
            else if (d.ok) showToast('Generated!');
          })
          .catch(() => { btn.disabled = false; btn.textContent = label; showToast('Network error', true); });
      });
    });

    function startPolling() {
      if (polling) return; polling = true;
      const tick = () => {
        fetch('poll.php?campaign_id=' + cid)
          .then(r => r.json())
          .then(d => {
            (d.assets || []).forEach(u => {
              const el = camp.querySelector('.asset[data-asset-id="' + u.asset_id + '"]');
              if (el && el.dataset.status !== u.status) el.outerHTML = u.card;
            });
            if (d.pending > 0) setTimeout(tick, 6000);
            else { polling = false; if (d.assets) showToast('All assets ready'); }
          })
          .catch(() => { setTimeout(tick, 8000); });
      };
      setTimeout(tick, 5000);
    }

    if (camp.dataset.pending === '1') startPolling();
  }
})();
