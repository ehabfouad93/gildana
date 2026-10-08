<?php
declare(strict_types=1);

/**
 * The SMS gateway form and its actions, shared by Admin → SMS gateways (platform gateways) and a
 * client's own provider on SMS → Settings. Secrets are never printed back: a stored secret shows
 * as "set", and leaving the box empty keeps it.
 */

require_once __DIR__ . '/sms.php';

/**
 * Handle a gateway action posted from either page. $clientId = null for platform gateways.
 * Returns a flash message (and type) or null when the post was not a gateway action.
 */
function sms_gateway_handle(?int $clientId, array $post): ?array
{
    $act = (string) ($post['gw_action'] ?? '');
    if ($act === '') return null;
    $own = $clientId === null ? 'client_id IS NULL' : 'client_id=' . (int) $clientId;
    $id = (int) ($post['gw_id'] ?? 0);
    $gw = $id ? sms_gateway_load(db_row("SELECT * FROM sms_gateways WHERE id=? AND $own", [$id]) ?: null) : null;
    if ($id && !$gw) return ['That gateway was not found.', 'error'];

    if ($act === 'save') {
        if (($post['provider'] ?? '') === 'http' && !preg_match('~^https?://~i', (string) ($post['cfg']['url'] ?? ''))) return ['Enter the provider\'s send URL, starting with https://', 'error'];
        $new = sms_gateway_save($clientId, $post, $gw ? (int) $gw['id'] : null);
        return [$gw ? 'Gateway saved.' : 'Gateway added. Send a test message to check it.', 'success', $new];
    }
    if (!$gw) return ['Choose a gateway.', 'error'];
    if ($act === 'delete') {
        if (db_val("SELECT 1 FROM clients WHERE sms_gateway_id=? LIMIT 1", [(int) $gw['id']])) return ['Some clients send through this gateway — move them to another one first.', 'error'];
        db_run("DELETE FROM sms_gateways WHERE id=?", [(int) $gw['id']]);
        if ($gw['client_id'] === null && db_has_column('clients', 'sms_allowed_gateways')) {
            // Clients who chose it go back to their default; it leaves every "may choose" list.
            $gid = (string) (int) $gw['id'];
            db_run("UPDATE clients SET sms_choice=NULL WHERE sms_choice=?", [$gid]);
            foreach (db_all("SELECT id, sms_allowed_gateways a FROM clients WHERE FIND_IN_SET(?, sms_allowed_gateways)", [$gid]) as $c) {
                $left = array_diff(explode(',', (string) $c['a']), [$gid]);
                db_run("UPDATE clients SET sms_allowed_gateways=? WHERE id=?", [$left ? implode(',', $left) : null, (int) $c['id']]);
            }
        }
        return ['Gateway deleted.', 'success'];
    }
    if ($act === 'toggle') {
        db_run("UPDATE sms_gateways SET active=1-active WHERE id=?", [(int) $gw['id']]);
        return [$gw['active'] ? 'Gateway turned off.' : 'Gateway turned on.', 'success'];
    }
    if ($act === 'test') {
        $to = normalize_phone((string) ($post['test_to'] ?? ''), (string) ($post['test_cc'] ?? '20'));
        if ($to === '') return ['Enter the phone number to test with.', 'error'];
        $sender = trim((string) ($post['test_sender'] ?? '')) ?: (string) $gw['default_sender'];
        $text = trim((string) ($post['test_text'] ?? '')) ?: 'Revenect test message ✓';
        $p = sms_parts($text);
        $r = sms_provider_send($gw, $to, $text, $sender, 'test' . bin2hex(random_bytes(3)), $p['encoding'] === 'ucs2', sms_dlr_url($gw));
        db_run("UPDATE sms_gateways SET last_test_at=NOW(), last_test_ok=?, last_test_msg=? WHERE id=?",
               [$r['ok'] ? 1 : 0, mb_substr($r['ok'] ? 'Sent to +' . $to : (string) $r['error'], 0, 255), (int) $gw['id']]);
        return $r['ok'] ? ['Test sent to +' . $to . ($r['id'] ? ' (provider id ' . $r['id'] . ')' : '') . '.', 'success'] : ['Test failed: ' . $r['error'], 'error'];
    }
    if ($act === 'balance') {
        $b = sms_provider_balance($gw);
        return $b !== null ? ['Balance on ' . $gw['name'] . ': ' . $b, 'success'] : [sms_provider_label((string) $gw['provider']) . ' did not report a balance.', 'error'];
    }
    return null;
}

/** The add / edit form. $gw = existing gateway (decrypted) or null for a new one. */
function sms_gateway_form(?array $gw, bool $showDefault = true): string
{
    $cat = sms_provider_catalog();
    $prov = $gw['provider'] ?? 'smsmisr';
    $cfg = (array) ($gw['config'] ?? []);
    $h = '<form method="post" class="sms-gw-form">' . csrf_field()
       . '<input type="hidden" name="gw_action" value="save"><input type="hidden" name="gw_id" value="' . (int) ($gw['id'] ?? 0) . '">'
       . '<div class="grid3"><div class="field"><span class="lbl">Provider</span><select name="provider" class="sms-prov">';
    foreach ($cat as $k => $p) $h .= '<option value="' . $k . '"' . ($prov === $k ? ' selected' : '') . '>' . e($p['label']) . '</option>';
    $h .= '</select></div>'
        . '<div class="field"><span class="lbl">Name</span><input type="text" name="name" maxlength="80" value="' . e((string) ($gw['name'] ?? '')) . '" placeholder="e.g. SMS Misr — main"></div>'
        . '<div class="field"><span class="lbl">Messages per second</span><input type="number" name="rate_per_sec" min="1" max="200" value="' . (int) ($gw['rate_per_sec'] ?? 10) . '"></div></div>';
    foreach ($cat as $k => $p) {
        $h .= '<fieldset class="sms-prov-fields" data-prov="' . $k . '"' . ($prov === $k ? '' : ' hidden') . '><legend class="lbl">' . e($p['label']) . ' settings</legend><div class="grid2">';
        foreach ($p['fields'] as $f => [$label, $type, $opt]) {
            $val = $prov === $k ? (string) ($cfg[$f] ?? '') : '';
            $name = 'cfg[' . $f . ']';
            $dis = $prov === $k ? '' : ' disabled';
            $h .= '<div class="field' . ($type === 'textarea' ? ' sms-wide' : '') . '"><span class="lbl">' . e($label) . '</span>';
            if ($type === 'select') {
                $h .= '<select name="' . $name . '"' . $dis . '>';
                foreach ((array) $opt as $ov => $ol) $h .= '<option value="' . e((string) $ov) . '"' . ($val === (string) $ov ? ' selected' : '') . '>' . e($ol) . '</option>';
                $h .= '</select>';
            } elseif ($type === 'textarea') {
                $h .= '<textarea name="' . $name . '" rows="3"' . $dis . '>' . e($val) . '</textarea>';
            } elseif ($type === 'secret') {
                $h .= '<input type="password" name="' . $name . '" autocomplete="new-password"' . $dis . ' placeholder="' . ($val !== '' ? '••• set — leave empty to keep' : '') . '">';
            } else {
                $h .= '<input type="text" name="' . $name . '" value="' . e($val) . '"' . $dis . '>';
            }
            if (is_string($opt) && $opt !== '') $h .= '<span class="hint">' . e($opt) . '</span>';
            $h .= '</div>';
        }
        $h .= '</div>' . sms_provider_guide_html($k) . '</fieldset>';
    }
    $h .= '<div class="grid2"><div class="field"><span class="lbl">Default sender name</span><input type="text" name="default_sender" maxlength="40" value="' . e((string) ($gw['default_sender'] ?? '')) . '" placeholder="As approved by the provider"></div>'
        . '<div class="field"><span class="lbl">Other sender names on this account</span><input type="text" name="senders" maxlength="500" value="' . e((string) ($gw['senders'] ?? '')) . '" placeholder="Comma separated"></div></div>'
        . '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:6px 0 12px"><label class="mod-opt"><input type="checkbox" name="active" value="1"' . (!$gw || (int) $gw['active'] ? ' checked' : '') . '> On</label>'
        . ($showDefault ? '<label class="mod-opt"><input type="checkbox" name="is_default" value="1"' . (!empty($gw['is_default']) ? ' checked' : '') . '> Default gateway</label>' : '')
        . '</div><button class="btn btn-primary">' . ($gw ? 'Save gateway' : 'Add gateway') . '</button></form>'
        . '<script>(function(){var f=document.currentScript.previousElementSibling,s=f.querySelector(".sms-prov");'
        . 'function sync(){f.querySelectorAll(".sms-prov-fields").forEach(function(fs){var on=fs.dataset.prov===s.value;fs.hidden=!on;fs.querySelectorAll("input,select,textarea").forEach(function(i){i.disabled=!on;});});}'
        . 's.addEventListener("change",sync);sync();})();</script>';
    return $h;
}

/** One gateway as a card: what it is, its delivery-report address, test send and actions. */
function sms_gateway_card(array $gw, string $editUrl): string
{
    $h = '<div class="sms-gw' . ((int) $gw['active'] ? '' : ' off') . '"><div class="sms-gw-head"><div><strong>' . e((string) $gw['name']) . '</strong> '
       . '<span class="pill gray">' . e(sms_provider_label((string) $gw['provider'])) . '</span>'
       . ((int) $gw['is_default'] ? ' <span class="pill gold">Default</span>' : '') . ((int) $gw['active'] ? '' : ' <span class="pill red">Off</span>')
       . '<div class="text-muted" style="font-size:12.5px;margin-top:3px">Sender: ' . e((string) ($gw['default_sender'] ?: '—')) . ($gw['senders'] ? ' · also ' . e((string) $gw['senders']) : '')
       . ' · ' . (int) $gw['rate_per_sec'] . '/sec</div>';
    if ($gw['last_test_at']) {
        $h .= '<div style="font-size:12.5px;margin-top:3px" class="' . ((int) $gw['last_test_ok'] ? 'text-success' : 'text-danger') . '">Last test ' . e(date('j M, H:i', strtotime((string) $gw['last_test_at']))) . ': ' . e((string) $gw['last_test_msg']) . '</div>';
    }
    $dlr = sms_dlr_url($gw);
    $h .= '</div><div class="sms-gw-actions"><a class="btn btn-ghost btn-sm" href="' . e($editUrl) . '">Edit</a>'
        . '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="gw_id" value="' . (int) $gw['id'] . '">'
        . (in_array($gw['provider'], ['twilio', 'mshastra'], true) ? '<button class="btn btn-ghost btn-sm" name="gw_action" value="balance">Balance</button>' : '')
        . '<button class="btn btn-ghost btn-sm" name="gw_action" value="toggle">' . ((int) $gw['active'] ? 'Turn off' : 'Turn on') . '</button>'
        . '<button class="btn btn-ghost btn-sm" name="gw_action" value="delete" onclick="return confirm(\'Delete this gateway?\')">Delete</button></form></div></div>'
        . ($dlr !== '' ? '<div class="sms-dlr"><span class="lbl">Delivery reports URL</span> <code>' . e($dlr) . '</code> <span class="hint">Paste it in the provider\'s panel (Twilio sets it by itself).</span></div>' : '')
        . '<form method="post" class="sms-test">' . csrf_field() . '<input type="hidden" name="gw_action" value="test"><input type="hidden" name="gw_id" value="' . (int) $gw['id'] . '">'
        . '<input type="tel" name="test_to" placeholder="Test number, e.g. 01001234567" required>'
        . '<input type="text" name="test_sender" placeholder="Sender (default: ' . e((string) ($gw['default_sender'] ?: '—')) . ')">'
        . '<input type="text" name="test_text" placeholder="Message (optional)">'
        . '<button class="btn btn-ghost btn-sm">Send test</button></form></div>';
    return $h;
}

/** The provider's setup guide as a collapsible box under its fields — open while the gateway is new. */
function sms_provider_guide_html(string $provider, bool $open = false): string
{
    $g = sms_provider_guide($provider);
    if (!$g) return '';
    [$intro, $steps, $notes, $link] = $g;
    $h = '<details class="sms-guide"' . ($open ? ' open' : '') . '><summary>How to connect ' . e(sms_provider_catalog()[$provider]['label'] ?? $provider) . '</summary>'
       . '<p>' . e($intro) . '</p><ol>';
    foreach ($steps as $st) $h .= '<li>' . $st . '</li>';          // guide text is ours (sms_provider_guide), not user input
    $h .= '</ol>';
    if ($notes) { $h .= '<p class="sms-guide-h">Good to know</p><ul>'; foreach ($notes as $n) $h .= '<li>' . $n . '</li>'; $h .= '</ul>'; }
    if ($link !== '') $h .= '<p class="sms-guide-link"><a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . e(parse_url($link, PHP_URL_HOST) ?: $link) . ' ↗</a></p>';
    return $h . '</details>';
}

/* ───────────── a client's own SMS gateways + which one sends (Settings and SMS → Settings) ───────────── */

/**
 * Posts from the client's SMS gateway panel: their own gateways (add / edit / test / balance /
 * on-off / delete — only when the platform admin allows their own provider) and the choice of
 * gateway. Returns [message, type] or null when the post was not one of these.
 */
function sms_client_panel_handle(array $client, array $post, bool $admin): ?array
{
    $cid = (int) $client['id'];
    if (isset($post['gw_action'])) {
        if (!$admin) return ['Only an account Admin can change SMS gateways.', 'error'];
        if (($client['sms_mode'] ?? 'platform') !== 'own') return ['Using your own SMS provider needs approval — ask ' . (defined('BRAND_PARENT') ? BRAND_PARENT : 'us') . ' to allow it.', 'error'];
        $before = (int) db_val("SELECT COUNT(*) FROM sms_gateways WHERE client_id=?", [$cid]);
        $r = sms_gateway_handle($cid, $post);
        if (!$r) return null;
        $act = (string) $post['gw_action'];
        // Their first own gateway starts sending straight away.
        if ($act === 'save' && !empty($r[2]) && $before === 0) db_run("UPDATE clients SET sms_choice=? WHERE id=?", ['own:' . (int) $r[2], $cid]);
        // Deleting the one in use: back to the default until they choose again.
        if ($act === 'delete' && ($r[1] ?? '') === 'success') db_run("UPDATE clients SET sms_choice=NULL WHERE id=? AND sms_choice=?", [$cid, 'own:' . (int) ($post['gw_id'] ?? 0)]);
        return $r;
    }
    if (($post['action'] ?? '') === 'sms_choose') {
        if (!$admin) return ['Only an account Admin can choose the SMS gateway.', 'error'];
        $pick = (string) ($post['gateway'] ?? '');
        $opts = sms_client_options($client);
        if (!isset($opts[$pick])) return ['That gateway is not available to your account.', 'error'];
        if (!$opts[$pick]['ready']) return ['That gateway is turned off — turn it on first.', 'error'];
        db_run("UPDATE clients SET sms_choice=? WHERE id=?", [$pick, $cid]);
        return ['SMS now goes out through ' . $opts[$pick]['name'] . '.', 'success'];
    }
    return null;
}

/** The client's SMS gateway panel. $self is the page it posts back to (e.g. settings.php). */
function sms_client_panel(array $client, string $self): string
{
    $cid = (int) $client['id'];
    $brand = defined('BRAND_PARENT') ? BRAND_PARENT : 'us';
    $allowOwn = ($client['sms_mode'] ?? 'platform') === 'own';
    $opts = sms_client_options($client);
    $cur = sms_client_gateway($client);
    $curKey = $cur ? ($cur['client_id'] !== null ? 'own:' . (int) $cur['id'] : (string) $cur['id']) : '';
    $sep = str_contains($self, '?') ? '&' : '?';
    $h = '<div class="sms-panel">';

    // 1. Which gateway sends.
    $h .= '<h3 class="sms-panel-h">Send SMS through</h3>';
    if (!$opts) {
        $h .= '<p class="text-muted">No SMS gateway yet.' . ($allowOwn ? ' Add your provider below.' : ' Ask ' . e($brand) . ' to connect one.') . '</p>';
    } elseif (count($opts) === 1) {
        $o = reset($opts);
        $h .= '<p>' . e($o['name']) . ' <span class="text-muted">— ' . e(sms_provider_label($o['provider'])) . ($o['kind'] === 'own' ? ' · your account' : ' · ' . e($brand)) . '</span>'
            . ($cur ? ' <span class="pill green">In use</span>' : '') . '</p>';
    } else {
        $h .= '<form method="post" action="' . e($self) . '">' . csrf_field() . '<input type="hidden" name="action" value="sms_choose">'
            . '<div class="sms-gw-pick" role="radiogroup" aria-label="SMS gateway">';
        foreach ($opts as $k => $o) {
            $on = $curKey === (string) $k;
            $h .= '<label class="sms-gw-opt' . ($on ? ' on' : '') . ($o['ready'] ? '' : ' off') . '">'
                . '<input type="radio" name="gateway" value="' . e((string) $k) . '"' . ($on ? ' checked' : '') . ($o['ready'] ? '' : ' disabled') . '>'
                . '<span><strong>' . e($o['name']) . '</strong><small class="text-muted">' . e(sms_provider_label($o['provider']))
                . ($o['kind'] === 'own' ? ' · your account' . ($o['ready'] ? '' : ' · turned off') : ' · ' . e($brand)) . '</small></span>'
                . ($on ? '<span class="pill green">In use</span>' : '') . '</label>';
        }
        $h .= '</div><button class="btn btn-primary btn-sm mt10">Use this gateway</button></form>';
    }

    // 2. Their own provider accounts.
    $h .= '<h3 class="sms-panel-h" id="own">Your SMS provider accounts</h3>';
    if (!$allowOwn) {
        return $h . '<p class="text-muted">To send through your own SMS provider account (SMS Misr, Victory Link, Twilio, mShastra or any provider with an HTTP API), ask '
            . e($brand) . ' to allow it for your account.</p></div>';
    }
    $own = array_map('sms_gateway_load', db_all("SELECT * FROM sms_gateways WHERE client_id=? ORDER BY id", [$cid]));
    $edit = (int) ($_GET['gw_edit'] ?? 0);
    foreach ($own as $g) {
        $h .= '<div class="sms-own-gw" id="gw' . (int) $g['id'] . '">' . sms_gateway_card($g, $self . $sep . 'gw_edit=' . (int) $g['id'] . '#gw-form');
        if ($edit === (int) $g['id']) $h .= '<div id="gw-form" class="sms-own-form"><h4>Edit ' . e((string) $g['name']) . '</h4>' . sms_gateway_form($g, false) . '</div>';
        $h .= '</div>';
    }
    if (!$own) $h .= '<p class="text-muted">No provider account yet — add one below. Choose the provider, fill in what it asks for (the guide under the fields shows where to find each one), save, then send a test.</p>';
    $adding = isset($_GET['gw_new']) || !$own;
    if ($adding) {
        $h .= '<div id="gw-form" class="sms-own-form"><h4>Add an SMS provider account</h4>' . str_replace('<details class="sms-guide">', '<details class="sms-guide" open>', sms_gateway_form(null, false)) . '</div>';
    } elseif (!$edit) {
        $h .= '<a class="btn btn-ghost btn-sm" href="' . e($self . $sep . 'gw_new=1#gw-form') . '">+ Add SMS provider account</a>';
    }
    return $h . '</div>';
}
