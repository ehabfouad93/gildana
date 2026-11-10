<?php
/**
 * Shared Inbox UI (WhatsApp-style two-pane live chat). Include after the page header.
 * Expects: $IB_ENDPOINT (string, e.g. 'inbox.php' or 'inbox.php?client=3').
 * Optional: $IB_UPLOAD — a media upload endpoint for the template picker. Left empty the
 *   Upload button is not shown and a link can still be pasted. Admin has no such endpoint
 *   (upload_media.php saves under the signed-in CLIENT's folder), so admin gets the paste box.
 * The including page must already have handled ?ajax via inbox_handle_ajax().
 */
$IB_ENDPOINT = $IB_ENDPOINT ?? 'inbox.php';
$IB_UPLOAD   = $IB_UPLOAD ?? '';
$IB_SEP = strpos($IB_ENDPOINT, '?') === false ? '?' : '&';
// The channels this account talks on, for the tabs over the list (only when there is more than one).
$IB_CHANNELS = isset($CLIENT) && function_exists('client_channels')
    ? array_values(array_intersect(['whatsapp', 'messenger', 'instagram'], client_channels($CLIENT))) : ['whatsapp'];
?>
<style>
  .ib-wrap{display:flex;gap:0;height:72vh;min-height:460px;border:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.10));border-radius:12px;overflow:hidden;background:var(--surface,#fff)}
  .ib-list{width:320px;min-width:260px;border-inline-end:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.10));display:flex;flex-direction:column;background:var(--surface,#fff)}
  .ib-search{padding:10px;border-bottom:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.10))}
  .ib-search input{width:100%;padding:8px 10px;border:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.10));border-radius:8px;font-size:13px}
  .ib-threads{overflow-y:auto;flex:1}
  .ib-th{display:flex;gap:10px;align-items:center;padding:11px 12px;cursor:pointer;border-bottom:1px solid var(--line,#f0eee9)}
  .ib-th:hover{background:var(--brand-tint,rgba(124,58,237,.06))} .ib-th.active{background:var(--brand-tint,rgba(124,58,237,.10))}
  .ib-av{width:38px;height:38px;border-radius:50%;background:var(--brand,#7C3AED);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;flex-shrink:0}
  .ib-th .nm{font-weight:600;font-size:13.5px} .ib-th .pv{color:var(--muted,rgba(var(--ink-rgb,13,19,33),.55));font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
  .ib-th .meta{margin-inline-start:auto;text-align: end;display:flex;flex-direction:column;gap:3px;align-items:flex-end}
  .ib-th .tm{color:var(--muted,rgba(var(--ink-rgb,13,19,33),.55));font-size:11px}
  .ib-list{position:relative}
  .ib-fcount{display:inline-block;min-width:16px;height:16px;line-height:16px;border-radius:8px;background:var(--brand,#7C3AED);color:#fff;font-size:10.5px;text-align:center;margin-inline-start:5px;padding:0 4px}
  .ib-chips{display:flex;flex-wrap:wrap;gap:5px;padding:2px 14px 8px}
  .ib-chip{display:inline-flex;align-items:center;gap:5px;max-width:100%;border:1px solid var(--line,rgba(13,19,33,.12));background:var(--paper,#f6f6f8);border-radius:999px;padding:2px 4px 2px 9px;font-size:12px}
  .ib-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:190px}
  .ib-chip button{border:0;background:none;cursor:pointer;color:inherit;opacity:.6;font-size:12px;line-height:1;padding:3px 5px;border-radius:50%}
  .ib-chip button:hover{opacity:1;background:rgba(0,0,0,.08)}
  .ib-chip i{width:7px;height:7px;border-radius:50%;display:inline-block;flex:0 0 auto}
  .ib-fpanel{position:absolute;inset-inline:8px;top:52px;z-index:20;background:var(--surface,#fff);border:1px solid var(--line,rgba(13,19,33,.14));border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.18);display:flex;flex-direction:column;max-height:calc(100% - 64px)}
  .ib-fpanel[hidden]{display:none}
  .ib-fhead{display:flex;gap:6px;padding:10px;border-bottom:1px solid var(--line,rgba(13,19,33,.10))}
  .ib-fhead input{flex:1;min-width:0}
  .ib-flist{overflow:auto;padding:4px 6px;flex:1 1 auto;min-height:60px}
  .ib-fsec{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#6b7280);padding:10px 8px 4px;display:flex;justify-content:space-between}
  .ib-fopt{display:flex;gap:9px;align-items:center;width:100%;text-align:start;border:0;background:none;color:inherit;font:inherit;padding:7px 8px;border-radius:8px;cursor:pointer}
  .ib-fopt:hover,.ib-fopt:focus-visible{background:var(--paper,#f3f3f6);outline:none}
  .ib-fopt.on{background:rgba(124,58,237,.10)}
  .ib-fopt b{font-weight:600;font-size:13px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ib-fopt small{display:block;font-size:11.5px;color:var(--muted,#6b7280);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ib-fopt .tk{margin-inline-start:auto;color:var(--brand,#7C3AED);font-weight:700;visibility:hidden}
  .ib-fopt.on .tk{visibility:visible}
  .ib-fopt i{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
  .ib-fmore{border:0;background:none;color:var(--brand,#7C3AED);cursor:pointer;font-size:12px;padding:4px 8px}
  .ib-fempty{padding:18px 10px;text-align:center;color:var(--muted,#6b7280);font-size:12.5px}
  .ib-ffoot{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:9px 10px;border-top:1px solid var(--line,rgba(13,19,33,.10));font-size:12.5px}
  .ib-ffoot label{display:flex;gap:6px;align-items:center}
  .ib-ffoot label.off{opacity:.45}
  .ib-tabs{display:flex;gap:4px;padding:8px 10px 0;flex-wrap:wrap}
  .ib-tabs button{border:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.12));background:var(--surface,#fff);border-radius:999px;padding:3px 10px;font-size:12px;cursor:pointer;color:inherit}
  .ib-tabs button.on{background:var(--brand,#7C3AED);border-color:var(--brand,#7C3AED);color:#fff}
  .ib-chb{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:16px;padding:0 4px;border-radius:5px;font-size:9.5px;font-weight:700;color:#fff;letter-spacing:.02em;vertical-align:1px}
  .ib-chb.sms{background:#64748B}.ib-chb.whatsapp{background:#25D366}.ib-chb.messenger{background:#0866FF}.ib-chb.instagram{background:linear-gradient(45deg,#F58529,#DD2A7B 55%,#8134AF)}
  .ib-chsel[hidden]{display:none!important}
  .ib-chsel{border:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.12));border-radius:8px;padding:6px 8px;font-size:12.5px;background:var(--surface,#fff);color:inherit;align-self:center}
  .ib-addlead{white-space:nowrap}
  .ib-badge{background:var(--brand,#7C3AED);color:#fff;border-radius:10px;font-size:11px;padding:1px 7px;font-weight:600}
  .ib-chat{flex:1;display:flex;flex-direction:column;position:relative;background:var(--chat-bg,#efe7dd)}
  .ib-chat-h{padding:12px 16px;background:var(--surface,#fff);border-bottom:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.10));display:flex;align-items:center;gap:10px}
  .ib-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:6px}
  .ib-b{max-width:74%;padding:7px 11px;border-radius:9px;font-size:13.5px;line-height:1.35;white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px .5px rgba(0,0,0,.08)}
  .ib-in{align-self:flex-start;background:var(--bubble-in,#fff)}
  .ib-out{align-self:flex-end;background:var(--bubble-out,#d9fdd3)}
  .ib-b .st{display:block;text-align: end;font-size:10.5px;color:#667;margin-top:2px}
  .ib-b .st.failed{color:#c0392b}
  /* The explanation under a failed send. Left-aligned and wrapping, unlike the timestamp it
     sits beneath, because it is a sentence to read rather than a status to glance at. */
  .ib-b .ib-err-code{opacity:.7;font-weight:400}
  .ib-b .ib-err-hint{display:block;text-align: start;font-size:11px;line-height:1.45;
    color:var(--tint-warn-ink,#8a6d3b);background:var(--tint-warn,#fdf6e3);border-radius:6px;padding:5px 7px;margin-top:4px;white-space:normal}
  .ib-b .ib-resend{display:inline-block;margin-top:6px;font-size:11.5px;font-weight:600;
    color:#fff;background:var(--brand,#6c4cf1);border:0;border-radius:6px;padding:5px 10px;cursor:pointer}
  .ib-b .ib-resend:hover{filter:brightness(1.08)}
  .ib-b .ib-resend[disabled]{opacity:.55;cursor:default}
  .ib-b .ib-later{background:transparent;color:var(--brand,#6c4cf1);border:1px solid currentColor;margin-inline-start:6px}
  .ib-b .ib-resend-err{color:var(--tint-red-ink,#8a1f11);background:var(--tint-red,#fdecea)}
  /* When a template is the only thing that can work, it stops being the secondary option. */
  .ib-b .ib-tpl-alt.only{background:var(--brand,#6c4cf1);color:#fff;border-color:transparent;margin-inline-start:0}
  .ib-b .ib-tpl-alt{display:inline-block;margin:6px 0 0 6px;font-size:11.5px;font-weight:600;
    color:var(--brand,#6c4cf1);background:transparent;border:1px solid currentColor;border-radius:6px;
    padding:4px 9px;cursor:pointer}
  .ib-note .ib-tpl-open{margin-inline-start:8px;font-size:12px;font-weight:600;color:#fff;
    background:var(--brand,#6c4cf1);border:0;border-radius:6px;padding:5px 10px;cursor:pointer}
  /* Template picker. Anchored over the thread rather than the page so the conversation the
     agent is answering stays visible behind it. */
  .ib-tpl-overlay{position:absolute;inset:0;background:rgba(var(--ink-rgb,13,19,33),.34);display:flex;
    align-items:center;justify-content:center;z-index:30;padding:16px}
  /* [hidden] is display:none in the UA sheet, which a class selector outranks — without this
     the closed picker stays laid out, invisible over the whole chat pane, and eats every
     click in the thread. */
  .ib-tpl-overlay[hidden]{display:none}
  .ib-tpl-card{background:var(--surface,#fff);border-radius:12px;width:min(520px,100%);
    max-height:82%;display:flex;flex-direction:column;box-shadow:0 18px 48px rgba(13,19,33,.24)}
  .ib-tpl-head{display:flex;justify-content:space-between;align-items:center;
    padding:12px 14px;border-bottom:1px solid var(--line,#e6e8ef)}
  .ib-tpl-x{background:0;border:0;font-size:20px;line-height:1;cursor:pointer;color:var(--muted,#667)}
  .ib-tpl-body{overflow:auto;padding:8px 14px 14px}
  .ib-tpl-foot{display:flex;justify-content:space-between;gap:8px;padding:12px 14px;
    border-top:1px solid var(--line,#e6e8ef)}
  .ib-tpl-row{width:100%;text-align: start;background:0;border:1px solid var(--line,#e6e8ef);
    border-radius:9px;padding:9px 11px;margin-top:8px;cursor:pointer;display:block}
  .ib-tpl-row:hover{border-color:var(--brand,#6c4cf1)}
  .ib-tpl-row b{display:block;font-size:13px}
  .ib-tpl-row span{display:block;font-size:11.5px;color:var(--muted,#667);margin-top:3px;
    white-space:pre-wrap;overflow:hidden;max-height:34px}
  .ib-tpl-row em{font-style:normal;font-size:11px;color:var(--muted,#667)}
  .ib-tpl-f{margin-top:10px}
  .ib-tpl-f label{display:block;font-size:11.5px;color:var(--muted,#667);margin-bottom:3px}
  .ib-tpl-f input{width:100%;padding:7px 9px;border:1px solid var(--line,#e6e8ef);border-radius:7px;font-size:13px}
  .ib-tpl-err{color:#c0392b;font-size:12px;margin-top:8px}
  .ib-tpl-tag{font-size:10px;font-weight:600;padding:1px 6px;border-radius:9px;vertical-align:middle}
  .ib-tpl-tag.warn{background:var(--tint-orange,#fdf0e3);color:var(--tint-orange-ink,#a9631a)}
  .ib-tpl-tag.ok{background:var(--tint-green,#e8f5ea);color:var(--tint-green-ink,#1e7a3c)}
  .ib-foot{padding:10px 12px;background:var(--surface,#fff);border-top:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.10))}
  .ib-foot form{display:flex;gap:8px;align-items:flex-end}
  .ib-foot textarea{flex:1;resize:none;border:1px solid var(--line,rgba(var(--ink-rgb,13,19,33),.10));border-radius:20px;padding:9px 14px;font-size:13.5px;max-height:120px}
  .ib-note{font-size:12px;color:var(--muted,rgba(var(--ink-rgb,13,19,33),.55));text-align:center;padding:8px}
  .ib-empty{margin:auto;color:var(--muted,rgba(var(--ink-rgb,13,19,33),.55));text-align:center;font-size:13px}
  .ib-bot{font-size:11.5px;padding:3px 9px;border-radius:11px;white-space:nowrap;font-weight:600}
  .ib-owner{width:auto;max-width:150px;font-size:12px;padding:4px 6px;margin-inline-end:6px}
  .ib-bot.on{background:var(--tint-green,#e8f5ea);color:var(--tint-green-ink,#1e7a3c)}
  .ib-bot.off{background:var(--tint-orange,#fdf0e3);color:var(--tint-orange-ink,#a9631a)}
  .ib-back{display:none;background:0;border:0;cursor:pointer;color:var(--ink,#2a221a);padding:2px 6px 2px 0;font-size:19px;line-height:1}
  /* Phones: one pane at a time — the thread list fills the screen, and opening a chat
     swaps to the conversation with a back arrow (the two-pane desktop view is unusable
     at this width). */
  @media(max-width:720px){
    .ib-wrap{height:calc(100vh - 190px);min-height:0;border-radius:10px}
    .ib-list{width:100%;min-width:0;border-inline-end:0}
    .ib-chat{display:none}
    .ib-th .pv{max-width:60vw}
    .ib-wrap.chatting .ib-list{display:none}
    .ib-wrap.chatting .ib-chat{display:flex}
    .ib-back{display:inline-block}
    /* Both of these are scoped rules that would otherwise beat the global 16px rule,
       and anything under 16px makes iOS Safari zoom the page on focus. */
    .ib-foot textarea, .ib-search input{font-size:16px}
  }
</style>

<div class="ib-wrap">
  <div class="ib-list">
    <?php if (count($IB_CHANNELS) > 1): ?>
    <div class="ib-tabs" id="ib-tabs" role="tablist" aria-label="Channel">
      <button type="button" class="on" data-ch="">All</button>
      <?php foreach ($IB_CHANNELS as $ch): ?><button type="button" data-ch="<?= e($ch) ?>"><?= e(['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram'][$ch]) ?></button><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
      /* The number filter: the working numbers this person sees — and, for an Admin, the ones that
         are disconnected or removed, whose conversations are otherwise gone from the Inbox. */
      $IB_LINES = []; $IB_OFF = [];
      if (isset($CLIENT) && function_exists('inbox_split') && inbox_split($CLIENT)) {
          $IB_OFF = inbox_offline_lines($CLIENT);
          $IB_LINES = array_diff_key(array_intersect_key(wa_lines($CLIENT), array_flip(inbox_visible_lines($CLIENT) ?? array_keys(wa_lines($CLIENT)))), $IB_OFF);
          if (!inbox_is_admin()) $IB_OFF = [];
      } ?>
    <?php /* From: the people of one campaign or one automation — and, ticked, only those who answered it. */
      $IB_FROM = isset($CLIENT) && function_exists('inbox_from_options') ? inbox_from_options((int) $CLIENT['id']) : ['campaigns' => [], 'flows' => []];
      $IB_FROM_NOW = preg_match('/^[cf]\d+$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
      $IB_HAS_LINES = count($IB_LINES) > 1 || $IB_OFF; $IB_HAS_FROM = $IB_FROM['campaigns'] || $IB_FROM['flows'];
      $IB_SYNC = isset($CLIENT) && function_exists('inbox_sync_lines') && inbox_sync_lines($CLIENT) && (!function_exists('can_write') || can_write()); ?>
    <?php /* The choices live in these two (hidden) selects; the Filter panel below is drawn from them. */ ?>
    <?php if ($IB_HAS_LINES): ?>
    <select id="ib-line" hidden aria-hidden="true">
      <option value="">All WhatsApp numbers</option>
      <?php foreach ($IB_LINES as $k => $l): ?><option value="<?= e($k) ?>" data-state="connected" data-sub="<?= e($l['kind'] === 'cloud' ? 'Business API' : 'Linked phone') ?>"><?= e($l['label'] . ($l['phone'] !== '' ? ' (' . $l['phone'] . ')' : '')) ?></option><?php endforeach; ?>
      <?php foreach ($IB_OFF as $k => $l): ?><option value="<?= e($k) ?>" data-state="<?= e($l['state']) ?>" data-sub="<?= $l['state'] === 'removed' ? 'Removed — Admins only' : 'Not connected — Admins only' ?>"><?= e($l['label'] . ($l['phone'] !== '' ? ' (' . $l['phone'] . ')' : '') . ($l['state'] === 'removed' ? ' — removed' : ' — not connected')) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($IB_HAS_FROM): ?>
    <select id="ib-from-src" hidden aria-hidden="true">
      <option value="">All conversations</option>
      <?php foreach ($IB_FROM['campaigns'] as $k => $l): ?><option value="<?= e($k) ?>" data-group="campaign" data-sub="<?= e($l['sub']) ?>" <?= $k === $IB_FROM_NOW ? 'selected' : '' ?>><?= e($l['label']) ?></option><?php endforeach; ?>
      <?php foreach ($IB_FROM['flows'] as $k => $l): ?><option value="<?= e($k) ?>" data-group="flow" data-sub="<?= e($l['sub']) ?>" <?= $k === $IB_FROM_NOW ? 'selected' : '' ?>><?= e($l['label']) ?></option><?php endforeach; ?>
    </select>
    <input type="checkbox" id="ib-replied" hidden <?= !empty($_GET['replied']) ? 'checked' : '' ?>>
    <?php endif; ?>
    <div class="ib-search" style="display:flex;gap:6px;padding-bottom:6px">
      <input type="text" id="ib-q" placeholder="Search name or number…" style="flex:1;min-width:0">
      <?php if ($IB_HAS_LINES || $IB_HAS_FROM): ?><button type="button" id="ib-fbtn" class="btn btn-ghost btn-sm" style="white-space:nowrap" aria-haspopup="dialog" aria-expanded="false" title="Filter by number, campaign or automation">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="vertical-align:-2px"><path d="M3 5h18M6 12h12M10 19h4"/></svg> Filter<span id="ib-fcount" class="ib-fcount" hidden></span></button><?php endif; ?>
      <?php if ($IB_SYNC): ?><button type="button" id="ib-sync" class="btn btn-ghost btn-sm" style="white-space:nowrap" title="Bring in your linked phone's chats again — past messages and the real numbers WhatsApp has since revealed">&#8635; Sync</button><?php endif; ?></div>
    <div id="ib-chips" class="ib-chips" hidden></div>
    <div id="ib-line-st" style="font-size:12px;padding:0 14px 6px" hidden></div>
    <div id="ib-sync-st" class="text-muted" style="font-size:12px;padding:0 14px 6px" hidden></div>
    <?php if ($IB_HAS_LINES || $IB_HAS_FROM): ?>
    <div id="ib-fpanel" class="ib-fpanel" role="dialog" aria-label="Filter conversations" hidden>
      <div class="ib-fhead"><input type="text" id="ib-fq" placeholder="Search numbers, campaigns, automations…" autocomplete="off">
        <button type="button" class="icon-btn" id="ib-fclose" title="Close" aria-label="Close">&#x2715;</button></div>
      <div id="ib-flist" class="ib-flist"></div>
      <div class="ib-ffoot">
        <label id="ib-freplied-wrap"><input type="checkbox" id="ib-freplied"> Only people who replied</label>
        <span style="margin-inline-start:auto;display:flex;gap:6px"><button type="button" class="btn btn-ghost btn-sm" id="ib-fclear">Clear all</button><button type="button" class="btn btn-primary btn-sm" id="ib-fdone">Done</button></span>
      </div>
    </div>
    <?php endif; ?>
    <div class="ib-threads" id="ib-threads"><div class="ib-empty" style="padding:20px">Loading…</div></div>
  </div>
  <div class="ib-chat">
    <div class="ib-chat-h" id="ib-head" style="display:none">
      <button type="button" class="ib-back" id="ib-back" aria-label="Back to conversations">&#8592;</button>
      <div class="ib-av" id="ib-hav"></div>
      <div><div class="nm" id="ib-hname"></div><div class="pv" id="ib-hphone"></div></div>
      <div style="margin-inline-start:auto;display:flex;align-items:center;gap:8px">
        <?php
          // The owner picker: Admins only, and only where the account has the CRM.
          $ibPeople = (function_exists('is_client_admin') && is_client_admin() && isset($CLIENT)
                       && function_exists('crm_enabled') && crm_enabled($CLIENT))
                    ? crm_assignable_users((int) $CLIENT['id']) : [];
        ?>
        <?php if ($ibPeople): ?>
          <select id="ib-owner" class="ib-owner" aria-label="Owner" title="Who owns this lead">
            <option value="none">Unassigned</option>
            <?php foreach ($ibPeople as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e((string) $u['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
        <button type="button" class="btn btn-ghost btn-sm ib-addlead" id="ib-addlead" hidden>+ Add to CRM as lead</button>
        <button type="button" class="btn btn-ghost btn-sm ib-addlead" id="ib-optin" hidden title="Meta's opt-in card: if they tap it, you can send them offers on Messenger later (Campaigns → Messenger / Instagram)">🔔 Ask to receive offers</button>
        <span class="ib-bot" id="ib-bot"></span>
        <button type="button" class="btn btn-ghost btn-sm" id="ib-bot-btn"></button>
      </div>
    </div>
    <div class="ib-body" id="ib-body"><div class="ib-empty">Select a conversation to view messages.</div></div>
    <div class="ib-foot" id="ib-foot" style="display:none">
      <select id="ib-from" class="ib-chsel" aria-label="Send from" title="Send from" hidden style="margin:0 0 6px auto;display:block;max-width:100%"></select>
      <form id="ib-form"><select id="ib-ch" class="ib-chsel" aria-label="Reply on" hidden></select><textarea id="ib-text" rows="1" placeholder="Type a reply…"></textarea><button class="btn btn-primary" type="submit">Send</button></form>
      <div class="ib-note" id="ib-nosend" hidden></div>
      <div class="ib-note" id="ib-closed" style="display:none">
        ⏱️ Outside the 24-hour window — only an approved template can reach this contact.
        <button class="ib-tpl-open" type="button">📄 Send a template</button>
      </div>
      <div class="ib-note" id="ib-closed-social" hidden></div>
      <div class="ib-tpl-overlay" id="ib-tpl" hidden>
        <div class="ib-tpl-card" role="dialog" aria-label="Send a template">
          <div class="ib-tpl-head">
            <strong>Send a template</strong>
            <button type="button" class="ib-tpl-x" aria-label="Close">×</button>
          </div>
          <div class="ib-tpl-body" id="ib-tpl-list"></div>
          <div class="ib-tpl-foot" id="ib-tpl-foot" hidden>
            <button type="button" class="btn btn-ghost btn-sm" id="ib-tpl-back">← Back</button>
            <button type="button" class="btn btn-primary btn-sm" id="ib-tpl-send">Send</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<dialog class="lead-dlg" id="ib-lead-dlg" aria-labelledby="ib-lead-title">
  <form method="dialog" id="ib-lead-form">
    <h2 id="ib-lead-title">Add to CRM as lead</h2>
    <p class="text-muted" style="font-size:13px;margin:-4px 0 10px">They go into the pipeline like any new lead and are given out by your assignment rules.</p>
    <div class="field"><span class="lbl">Their WhatsApp number <span class="text-muted">(optional)</span></span>
      <input type="tel" id="ib-lead-phone" placeholder="+20 100 123 4567" autocomplete="off">
      <div class="hint">If they already wrote on WhatsApp, the two conversations become one contact.</div></div>
    <div class="ib-note" id="ib-lead-err" hidden style="color:var(--danger)"></div>
    <div class="dlg-btns"><button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary" id="ib-lead-go">Add lead</button></div>
  </form>
</dialog>

<script src="../assets/tpl_preview.js?v=2"></script>
<style>
/* A sent template inside the thread: the preview's own bubble, without its backdrop. */
.ib-has-tpl{max-width:min(560px,92%)}
.ib-tpl .tp-wrap{background:transparent;padding:0}
.ib-tpl .tp-msg{max-width:300px}
.ib-tpl .tp-bub{box-shadow:none;border:1px solid rgba(0,0,0,.08)}
.ib-tpl .tp-cards{max-width:100%}
</style>
<script>
/* A picture, voice note, video or file someone sent: shown and played right in the thread.
   Fetched through media.php, which checks this person may see the conversation. */
function mediaHtml(m){
  if (!m.media) return '';
  const u = m.media.url, n = m.media.name || '';
  const fail = `onerror="this.closest('.ib-media').classList.add('gone')"`;
  if (m.media.gone) {
    const what = {audio:'voice note', image:'picture', sticker:'sticker', video:'video'}[m.media.kind] || 'file';
    return `<div class="ib-media gone"><span class="ib-media-gone">This ${what} arrived before files were kept, so it cannot be played here — it is still on the phone that received it.</span></div>`;
  }
  switch (m.media.kind) {
    case 'audio':   return `<div class="ib-media"><audio controls preload="none" src="${u}" ${fail}></audio><a class="ib-media-dl" href="${u}&download=1">Download</a><span class="ib-media-gone">This voice note is no longer available.</span></div>`;
    case 'image':
    case 'sticker': return `<div class="ib-media"><a href="${u}" target="_blank" rel="noopener"><img src="${u}" alt="Picture" loading="lazy" ${fail}></a><span class="ib-media-gone">This picture is no longer available.</span></div>`;
    case 'video':   return `<div class="ib-media"><video controls preload="metadata" src="${u}" ${fail}></video><span class="ib-media-gone">This video is no longer available.</span></div>`;
    default:        return `<div class="ib-media"><a class="ib-file" href="${u}&download=1">📎 ${n ? n.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])) : 'Download the file'}</a></div>`;
  }
}
const IB_URL = <?= json_encode($IB_ENDPOINT) ?>;
const IB_UPLOAD = <?= json_encode($IB_UPLOAD) ?>;
const IB_SEP = <?= json_encode($IB_SEP) ?>;
const IB_CSRF = <?= json_encode(csrf_token()) ?>;
const IB_MULTI = <?= count($IB_CHANNELS) > 1 ? 'true' : 'false' ?>;
let ibCur = 0, ibLast = 0, ibOpen = false, ibCh = '', ibChPicked = false, ibFilter = '', ibLine = null, ibLineFilter = '', ibSplit = false, ibFrom = null, ibFromPicked = false, ibFromList = [];
const IB_CH_SHORT = {whatsapp:'WA', messenger:'M', instagram:'IG', sms:'SMS'}, IB_CH_NAME = {whatsapp:'WhatsApp', messenger:'Messenger', instagram:'Instagram', sms:'SMS'};
const chBadge = c => `<span class="ib-chb ${c}" title="${IB_CH_NAME[c]||c}">${IB_CH_SHORT[c]||'?'}</span>`;
/* Who they are when there is no number yet: their Instagram handle, or the channel they wrote on. */
const whoName = t => t.name || (t.phone_e164 ? '+'+t.phone_e164 : (t.ig_username ? '@'+t.ig_username : (IB_CH_NAME[t.last_channel]||'Contact')+' user'));
const el = id => document.getElementById(id);
const esc = s => (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const initials = s => (s||'?').trim().slice(0,2).toUpperCase();
function tfmt(t){ if(!t) return ''; const d=new Date(t.replace(' ','T')); return isNaN(d)?'':d.toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
const tick = s => s==='read'?'✓✓':s==='delivered'?'✓✓':s==='sent'?'✓'
  :s==='failed'?'⚠ failed':s==='resent'?'↻ sent again — see below':'';

async function loadThreads(){
  const q = encodeURIComponent(el('ib-q').value||'');
  const fs = el('ib-from-src') ? el('ib-from-src').value : '';
  const fr = fs && el('ib-replied') && el('ib-replied').checked ? '&replied=1' : '';
  const r = await fetch(IB_URL+IB_SEP+'ajax=threads&q='+q+'&ch='+encodeURIComponent(ibFilter)+'&line='+encodeURIComponent(ibLineFilter)+(fs?'&from='+encodeURIComponent(fs):'')+fr); const d = await r.json();
  if(!d.ok) return;
  ibSplit = !!d.split; ibSyncShow(d.sync);
  const box = el('ib-threads');
  if(!d.threads.length){ box.innerHTML='<div class="ib-empty" style="padding:20px">'+(fs ? (fr ? 'Nobody has answered this yet.' : 'No conversations from this yet.') : 'No conversations yet.')+'</div>'; return; }
  box.innerHTML = d.threads.map(t=>{
    const pv = (t.last_dir==='out'?'↩ ':'') + esc((t.last_body||'').slice(0,40));
    const badge = t.unread>0 ? `<span class="ib-badge">${t.unread}</span>` : '';
    const chs = IB_MULTI ? chBadge(t.last_channel||'whatsapp') + ' ' : '';
    /* The name is the sender's own profile name: it goes in an escaped data attribute and is read
       back with dataset — never into an inline handler, where quotes and backslashes could break out. */
    /* Several numbers: one conversation per customer and number — the number is under the name. */
    const ln = t.line !== undefined && t.line !== null ? String(t.line) : null;
    const on = t.contact_id==ibCur && (ln===null || ln===ibLine);
    const via = ln !== null ? `<div class="pv ib-via" title="WhatsApp number">${ln ? '☎ ' : ''}${esc(t.line_label||'')}</div>` : '';
    return `<div class="ib-th ${on?'active':''}" data-id="${+t.contact_id}" data-line="${ln===null?'':esc(ln)}" data-split="${ln===null?0:1}" data-name="${esc(whoName(t))}" data-phone="${esc(t.phone_e164)}">
      <div class="ib-av">${esc(initials(whoName(t)))}</div>
      <div style="min-width:0"><div class="nm">${chs}${esc(whoName(t))}</div>${via}<div class="pv">${pv}</div></div>
      <div class="meta"><span class="tm">${tfmt(t.last_at)}</span>${badge}</div></div>`;
  }).join('');
  box.onclick = ev => { const th = ev.target.closest('.ib-th'); if (th) openThread(+th.dataset.id, th.dataset.name, th.dataset.phone, th.dataset.split==='1' ? th.dataset.line : null); };
}
function openThread(id,name,phone,line){
  ibCur=id; ibLine=(line===undefined?null:line); ibFrom=null; ibFromPicked=false; ibFromList=[]; ibLast=0; el('ib-body').innerHTML='';
  document.querySelector('.ib-wrap').classList.add('chatting');   // phones: show the chat pane
  el('ib-head').style.display='flex'; el('ib-foot').style.display='block';
  el('ib-hav').textContent=initials(name||phone); el('ib-hname').textContent=name||(phone?'+'+phone:''); el('ib-hphone').textContent=phone?'+'+phone:'';
  ibCh=''; ibChPicked=false; el('ib-addlead').hidden=true; el('ib-optin').hidden=true;
  document.querySelectorAll('.ib-th').forEach(e=>e.classList.remove('active'));
  pollThread(); loadThreads();
}
async function pollThread(){
  if(!ibCur) return;
  const r = await fetch(IB_URL+IB_SEP+'ajax=thread&contact='+ibCur+'&after='+ibLast+(ibLine!==null?'&line='+encodeURIComponent(ibLine):'')); const d = await r.json();
  if(!d.ok) return;
  if (d.line_label) el('ib-hphone').textContent = (d.phone ? '+'+d.phone+' · ' : (d.no_number ? 'Number hidden by WhatsApp · ' : '')) + 'on ' + d.line_label;
  else if (d.no_number) el('ib-hphone').textContent = 'Number hidden by WhatsApp';
  if (d.line_state && d.line_state !== 'connected') el('ib-hphone').textContent += d.line_state === 'removed' ? ' · removed number' : ' · not connected';
  const body = el('ib-body'); const atBottom = body.scrollHeight-body.scrollTop-body.clientHeight < 80;
  d.messages.forEach(m=>{
    ibLast=Math.max(ibLast,m.id);
    const div=document.createElement('div');
    div.className='ib-b '+(m.direction==='out'?'ib-out':'ib-in');
    let st=''; if(m.direction==='out'){ const t=tick(m.status); if(t) st=`<span class="st ${m.status==='failed'?'failed':''}">${t} ${tfmt(m.created_at)}</span>`; }
    else st=`<span class="st">${tfmt(m.created_at)}</span>`;
    if (m.channel==='sms') st = '<span class="st" title="Sent by SMS">SMS ·</span> ' + st;   // SMS sits in the same conversation, marked
    /* A failed send now says what went wrong in words the agent can act on, with Meta's own
       wording kept underneath so nothing is hidden. 'never' means a resend buys the same
       error, so the line says so rather than leaving them to try it three times. */
    let errLine = '';
    if (m.status==='failed' && (m.error_label || m.error_title)) {
      /* The friendly label, plus the raw code. Meta's full sentence is developer noise for a
         client, but the code is exactly what support asks for, so it stays visible. */
      const label = esc(m.error_label || m.error_title)
                  + (m.error_code ? ` <span class="ib-err-code">#${esc(m.error_code)}</span>` : '');
      const hint  = m.error_hint ? `<span class="ib-err-hint">${esc(m.error_hint)}</span>` : '';
      /* A resend button right here, because this is where the client notices the failure.
         It is offered only when the error can clear and we know which queued item to re-run;
         otherwise fall back to telling them where the fix lives. */
      const again = m.can_resend
        ? `<button class="ib-resend" data-msg="${m.id}" title="Send this message again now">↻ Send again</button>`
          + (m.resend_wait
             ? `<button class="ib-resend ib-later" data-msg="${m.id}" data-schedule="1"
                  title="Queue it and let it go out by itself once WhatsApp lifts the cap"
                >⏱ Or wait ~${m.resend_wait}h</button>` : '')
        : (m.error_action === 'never' ? ''
           : `<span class="ib-err-hint">Resend it from ${m.error_action === 'fix' ? 'Campaigns once fixed' : 'Needs attention or Lead Qualifier'}.</span>`);
      /* A different template is often the better move: when WhatsApp caps a marketing
         template, a utility one goes through, and resending the same one does not. */
      const alt = m.error_action === 'never' ? ''
                : `<button class="ib-tpl-alt${m.needs_template ? ' only' : ''}" type="button">📄 ${
                    m.needs_template ? 'Send a template' : 'Try another template'}</button>`;
      const where = again + alt;
      errLine = `<span class="st failed" style="display:block">⚠ ${label}</span>${hint}${where}`;
    }
    // A template with a picture, buttons or cards is drawn as the customer got it.
    const inner = (m.tpl && window.tplPreview) ? `<div class="ib-tpl">${tplPreview(m.tpl)}</div>` : mediaHtml(m)+(m.body?`<span class="ib-caption">${esc(m.body)}</span>`:'');
    if (m.tpl && window.tplPreview) div.classList.add('ib-has-tpl');
    div.innerHTML=inner+st+errLine;
    body.appendChild(div);
  });
  if(d.messages.length && atBottom) body.scrollTop=body.scrollHeight;
  // The channels they can be answered on; the reply goes where they last wrote, unless changed.
  const chans = d.channels || ['whatsapp'];
  if (!ibChPicked || !chans.includes(ibCh)) ibCh = d.reply_channel || chans[0] || 'whatsapp';
  const sel = el('ib-ch');
  sel.innerHTML = chans.map(c=>`<option value="${c}" ${c===ibCh?'selected':''}>${IB_CH_NAME[c]||c}</option>`).join('');
  sel.hidden = chans.length < 2;
  if (!d.phone && !d.no_number && chans.length) el('ib-hphone').textContent = chans.map(c=>IB_CH_NAME[c]).join(' · ') + (d.ig_username ? ' · @'+d.ig_username : '');
  el('ib-addlead').hidden = !d.can_add_lead;
  el('ib-optin').hidden = !d.can_ask_optin;
  ibOpen = d.windows && (ibCh in d.windows) ? !!d.windows[ibCh] : !!d.window_open;
  const social = ibCh === 'messenger' || ibCh === 'instagram';
  /* Send from: this person's WhatsApp numbers. A linked phone has no 24-hour window. */
  ibFromList = d.wa_from || [];
  const fs = el('ib-from');
  if (!ibFromPicked || !ibFromList.some(f => f.key === ibFrom)) ibFrom = d.wa_line || (ibFromList[0] && ibFromList[0].key) || null;
  fs.innerHTML = ibFromList.map(f => `<option value="${esc(f.key)}" ${f.key===ibFrom?'selected':''}>From ${esc(f.label)}${f.kind==='personal'?'':(f.open?'':' — 24h closed')}</option>`).join('');
  fs.hidden = social || ibFromList.length < 2;
  if (!social && ibFromList.length > 1) { const f = ibFromList.find(x => x.key === ibFrom); if (f) { ibOpen = !!f.open; d.wa_can_send = true; } }
  if (!social) { d.can_send = d.wa_can_send !== undefined ? d.wa_can_send : d.can_send; d.send_error = d.wa_send_error !== undefined ? d.wa_send_error : d.send_error; }
  else d.can_send = true;
  const own = el('ib-owner');
  if (own && document.activeElement !== own) own.value = d.owner == null ? 'none' : String(d.owner);
  /* Someone set up not to send — or whose own phone is not linked — sees why, instead of a
     reply box that would only fail when they press Send. */
  const blocked = d.can_send === false;
  el('ib-form').style.display = ibOpen && !blocked ? 'flex' : 'none';
  el('ib-closed').style.display = !ibOpen && !blocked && !social ? 'block' : 'none';
  const cs = el('ib-closed-social');
  cs.hidden = ibOpen || !social;
  cs.textContent = social ? '⏱️ More than 24 hours since they last wrote on ' + IB_CH_NAME[ibCh] + ' — Meta only allows a reply within 24 hours of their message. It opens again as soon as they write.' : '';
  const ns = el('ib-nosend');
  if (ns) { ns.hidden = !blocked; ns.textContent = blocked ? '\u26a0 ' + (d.send_error || 'You cannot send from this account.') : ''; }
  setBotState(!!d.bot_paused);
}
/* ── live takeover: while paused the bot won't reply to this contact ── */
let ibPaused=false;
function setBotState(paused){
  ibPaused=paused;
  const pill=el('ib-bot'), btn=el('ib-bot-btn');
  pill.className='ib-bot '+(paused?'off':'on');
  pill.textContent = paused ? "🙋 You're handling this" : '🤖 Bot active';
  btn.textContent  = paused ? 'Resume bot' : 'Take over';
  btn.title = paused ? 'Hand the conversation back to the bot'
                     : 'Pause the bot so you can reply by hand';
}
el('ib-bot-btn').addEventListener('click', async ()=>{
  if(!ibCur) return;
  const btn=el('ib-bot-btn'); btn.disabled=true;
  const fd=new FormData();
  fd.append('ajax', ibPaused?'resume':'takeover');
  fd.append('csrf_token',IB_CSRF); fd.append('contact',ibCur);
  try{
    const r=await fetch(IB_URL,{method:'POST',body:fd}); const d=await r.json();
    if(d.ok) setBotState(!!d.bot_paused);
  }catch(e){ /* leave the pill as-is */ }
  btn.disabled=false;
});
el('ib-form').addEventListener('submit', async e=>{
  e.preventDefault(); const ta=el('ib-text'); const body=ta.value.trim(); if(!body||!ibCur) return;
  const btn=e.target.querySelector('button'); btn.disabled=true;
  const fd=new FormData(); fd.append('ajax','send'); fd.append('csrf_token',IB_CSRF); fd.append('contact',ibCur); fd.append('body',body); fd.append('channel',ibCh||'whatsapp');
  if (ibFrom && ibCh !== 'messenger' && ibCh !== 'instagram') fd.append('line', ibFrom); else if (ibLine) fd.append('line', ibLine);
  const r=await fetch(IB_URL,{method:'POST',body:fd}); const d=await r.json();
  btn.disabled=false;
  if(d.ok){ ta.value='';
    // Several numbers: sent from another number (a phone) — it is in that number's conversation now.
    if (ibSplit && ibLine !== null && ibFrom && ibFrom !== ibLine) { ibLine = ibFrom; ibFromPicked = false; ibLast = 0; el('ib-body').innerHTML = ''; }
    pollThread(); loadThreads(); }
  else { alert(d.error||'Could not send.'); }
});
el('ib-from').addEventListener('change', e=>{ ibFrom=e.target.value; ibFromPicked=true; const f=ibFromList.find(x=>x.key===ibFrom);
  if (f) { ibOpen=!!f.open; el('ib-form').style.display = ibOpen ? 'flex' : 'none'; el('ib-closed').style.display = ibOpen ? 'none' : 'block'; } });
el('ib-ch').addEventListener('change', e=>{ ibCh=e.target.value; ibChPicked=true; ibLast=Math.max(0,ibLast); pollThread(); });
/* ── Filter: one panel for number, campaign and automation, with a search and chips for what is on.
   The choices themselves are the two hidden selects (#ib-line, #ib-from-src) and the #ib-replied box. ── */
const ibF = { open:false, more:{} };
const ibDot = s => ({connected:'#16a34a', off:'#dc2626', removed:'#9ca3af'}[s] || 'transparent');
function ibOpts(sel, group){
  if (!el(sel)) return [];
  return [...el(sel).options].filter(o => o.value && (!group || o.dataset.group === group))
    .map(o => ({k:o.value, label:o.textContent.trim(), sub:o.dataset.sub || '', state:o.dataset.state || ''}));
}
function ibFilterDraw(){
  const q = (el('ib-fq').value || '').trim().toLowerCase();
  const hit = o => !q || (o.label + ' ' + o.sub).toLowerCase().includes(q);
  const curLine = el('ib-line') ? el('ib-line').value : '', curFrom = el('ib-from-src') ? el('ib-from-src').value : '';
  const opt = (sel, o, cur) => `<button type="button" class="ib-fopt${o.k===cur?' on':''}" data-sel="${sel}" data-k="${esc(o.k)}">${o.state ? `<i style="background:${ibDot(o.state)}"></i>` : ''}
      <span style="min-width:0"><b>${esc(o.label)}</b>${o.sub ? `<small>${esc(o.sub)}</small>` : ''}</span><span class="tk">✓</span></button>`;
  const sect = (title, sel, list, cur, key) => {
    const m = list.filter(hit); if (!m.length) return '';
    // Long lists show the first few (and what is chosen); searching shows every match.
    const lim = q || ibF.more[key] ? m.length : 5;
    const shown = m.filter((o, i) => i < lim || o.k === cur);
    return `<div class="ib-fsec"><span>${title}</span><span>${m.length}</span></div>` + shown.map(o => opt(sel, o, cur)).join('')
         + (m.length > shown.length ? `<button type="button" class="ib-fmore" data-more="${key}">Show all ${m.length}</button>` : '');
  };
  const h = sect('WhatsApp number', 'ib-line', ibOpts('ib-line'), curLine, 'n')
          + sect('Campaign', 'ib-from-src', ibOpts('ib-from-src', 'campaign'), curFrom, 'c')
          + sect('Automation', 'ib-from-src', ibOpts('ib-from-src', 'flow'), curFrom, 'f');
  el('ib-flist').innerHTML = h || '<div class="ib-fempty">Nothing matches “' + esc(q) + '”.</div>';
  const rw = el('ib-freplied-wrap'); rw.hidden = !el('ib-from-src'); rw.classList.toggle('off', !curFrom);
  el('ib-freplied').disabled = !curFrom; el('ib-freplied').checked = !!curFrom && !!el('ib-replied') && el('ib-replied').checked;
}
function ibChips(){
  const chips = [];
  const lo = el('ib-line') && el('ib-line').selectedOptions[0], fo = el('ib-from-src') && el('ib-from-src').selectedOptions[0];
  if (lo && lo.value) chips.push(['line', `<i style="background:${ibDot(lo.dataset.state)}"></i><span>${esc(lo.textContent.trim())}</span>`]);
  if (fo && fo.value) chips.push(['from', `<span>${fo.dataset.group==='campaign' ? 'Campaign' : 'Automation'}: ${esc(fo.textContent.trim())}</span>`]);
  if (fo && fo.value && el('ib-replied').checked) chips.push(['replied', '<span>Replied</span>']);
  el('ib-chips').hidden = !chips.length;
  el('ib-chips').innerHTML = chips.map(([k, h]) => `<span class="ib-chip" data-chip="${k}">${h}<button type="button" title="Remove this filter" aria-label="Remove this filter">✕</button></span>`).join('');
  const c = el('ib-fcount'); if (c) { c.hidden = !chips.length; c.textContent = chips.length; }
}
function ibFilterApply(){
  ibLineFilter = el('ib-line') ? el('ib-line').value : '';
  if (el('ib-from-src') && !el('ib-from-src').value) el('ib-replied').checked = false;
  ibLineState(); ibChips(); if (ibF.open) ibFilterDraw(); loadThreads();
}
function ibFilterOpen(on){
  ibF.open = on; el('ib-fpanel').hidden = !on; el('ib-fbtn').setAttribute('aria-expanded', on ? 'true' : 'false');
  if (on) { el('ib-fq').value = ''; ibF.more = {}; ibFilterDraw(); el('ib-fq').focus(); }
}
if (el('ib-fbtn')) {
  el('ib-fbtn').addEventListener('click', () => ibFilterOpen(!ibF.open));
  el('ib-fclose').addEventListener('click', () => ibFilterOpen(false));
  el('ib-fdone').addEventListener('click', () => ibFilterOpen(false));
  el('ib-fq').addEventListener('input', ibFilterDraw);
  el('ib-fclear').addEventListener('click', () => { if (el('ib-line')) el('ib-line').value = ''; if (el('ib-from-src')) el('ib-from-src').value = ''; ibFilterApply(); });
  el('ib-freplied').addEventListener('change', e => { el('ib-replied').checked = e.target.checked; ibFilterApply(); });
  el('ib-flist').addEventListener('click', e => {
    const more = e.target.closest('[data-more]'); if (more) { ibF.more[more.dataset.more] = true; ibFilterDraw(); return; }
    const o = e.target.closest('.ib-fopt'); if (!o) return;
    const s = el(o.dataset.sel); s.value = s.value === o.dataset.k ? '' : o.dataset.k;      // click again to take it off
    ibFilterApply();
  });
  el('ib-chips').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    const k = b.parentElement.dataset.chip;
    if (k === 'line') el('ib-line').value = ''; else if (k === 'from') el('ib-from-src').value = ''; else el('ib-replied').checked = false;
    ibFilterApply();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ibF.open) ibFilterOpen(false); });
  document.addEventListener('mousedown', e => { if (ibF.open && !e.target.closest('#ib-fpanel') && !e.target.closest('#ib-fbtn')) ibFilterOpen(false); });
}
/* Picking a number says whether it is working: connected, not connected, or removed. */
function ibLineState(){
  const st = el('ib-line-st'); if (!st || !el('ib-line')) return;
  const o = el('ib-line').selectedOptions[0]; const s = o && o.value ? o.dataset.state : '';
  st.hidden = !(s === 'off' || s === 'removed');
  if (s === 'off') st.innerHTML = '<span style="color:var(--danger,#dc2626)">●</span> Not connected — only Admins see these conversations. Link it again in <a href="settings.php#numbers">Settings → WhatsApp numbers</a>.';
  else if (s === 'removed') st.innerHTML = '<span style="color:var(--muted,#6b7280)">●</span> Removed — only Admins see these conversations.';
}
if (el('ib-fbtn')) { ibLineFilter = el('ib-line') ? el('ib-line').value : ''; ibLineState(); ibChips(); }
document.querySelectorAll('#ib-tabs button').forEach(b=>b.addEventListener('click',()=>{
  document.querySelectorAll('#ib-tabs button').forEach(x=>x.classList.toggle('on',x===b)); ibFilter=b.dataset.ch; loadThreads();
}));
/* Messenger Marketing Messages opt-in request. */
el('ib-optin').addEventListener('click', async ()=>{
  const title=prompt('What will they receive? (shown on the card, up to 65 characters)','Offers and news');
  if (title===null) return;
  const fd=new FormData(); fd.append('ajax','optin_ask'); fd.append('csrf_token',IB_CSRF); fd.append('contact',ibCur); fd.append('title',title); fd.append('frequency','WEEKLY');
  const d=await (await fetch(IB_URL,{method:'POST',body:fd})).json().catch(()=>({ok:false}));
  if (typeof showToast==='function') showToast(d.ok?'Request sent — they can tap "Get messages".':(d.error||'Could not send the request.'), !d.ok);
  if (d.ok) { el('ib-optin').hidden=true; pollThread(); }
});
/* Add to CRM as lead — with their number, optionally, so a WhatsApp contact and this one become one. */
el('ib-addlead').addEventListener('click',()=>{ el('ib-lead-err').hidden=true; el('ib-lead-phone').value=''; el('ib-lead-dlg').showModal(); });
el('ib-lead-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const go=el('ib-lead-go'); go.disabled=true;
  const fd=new FormData(); fd.append('ajax','add_lead'); fd.append('csrf_token',IB_CSRF); fd.append('contact',ibCur); fd.append('phone',el('ib-lead-phone').value.trim());
  const d=await (await fetch(IB_URL,{method:'POST',body:fd})).json().catch(()=>({ok:false,error:'Could not add the lead.'}));
  go.disabled=false;
  if(!d.ok){ const er=el('ib-lead-err'); er.hidden=false; er.textContent=d.error||'Could not add the lead.'; return; }
  el('ib-lead-dlg').close();
  if (typeof showToast==='function') showToast('Added to the CRM.');
  if (d.contact && d.contact!==ibCur) { openThread(d.contact, el('ib-hname').textContent, ''); } else { el('ib-addlead').hidden=true; }
  loadThreads();
});
el('ib-text').addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); el('ib-form').requestSubmit(); }});

/* ── resend a failed message, and send a template by hand ──
   Both live on the thread because that is where a failure is noticed. Clicks are caught on the
   body rather than bound per bubble: pollThread() appends new bubbles continuously, so anything
   bound at render time would be missed by every message that arrived after it. */
el('ib-body').addEventListener('click', async e=>{
  const rs = e.target.closest('.ib-resend');
  if (rs) {
    rs.disabled = true; const was = rs.textContent;
    rs.textContent = rs.dataset.schedule ? 'Scheduling…' : 'Sending…';
    const fd = new FormData();
    fd.append('ajax','resend'); fd.append('csrf_token',IB_CSRF); fd.append('message',rs.dataset.msg);
    if (rs.dataset.schedule) fd.append('schedule','1');
    try {
      const r = await fetch(IB_URL,{method:'POST',body:fd}); const d = await r.json();
      if (d.ok) {
        rs.textContent = d.queued === 'later' ? `✓ Will send in ~${d.hours}h` : '✓ Queued to send';
        pollThread(); loadThreads();
      }
      else if (d.pick_template !== undefined) {
        /* The variables were never stored, so "the same template" needs the fields filled in
           rather than guessed. Open the picker on it instead of refusing. */
        rs.disabled = false; rs.textContent = was;
        openTpl(d.pick_template || 0);      // 0 = we could not identify it; show the list
      }
      else {
        rs.disabled = false; rs.textContent = was;
        showResendErr(rs, d.error || 'Could not resend.');
      }
    } catch (err) { rs.disabled = false; rs.textContent = was; showResendErr(rs, 'Could not resend.'); }
    return;
  }
  if (e.target.closest('.ib-tpl-alt')) openTpl();
});
/* An alert() is gone by the time anyone can read the code in it, and the code is the whole
   point — it is what tells a marketing cap apart from a bad token. Keep it on the bubble. */
function showResendErr(btn, msg){
  const b = btn.closest('.ib-b'); if (!b) { alert(msg); return; }
  let box = b.querySelector('.ib-resend-err');
  if (!box) { box = document.createElement('span'); box.className = 'ib-err-hint ib-resend-err'; b.appendChild(box); }
  box.textContent = '⚠ ' + msg;
}
document.querySelector('.ib-tpl-open')?.addEventListener('click', openTpl);

let ibTpls = null, ibPick = null;
const tplBack = () => el('ib-tpl');

function closeTpl(){ tplBack().hidden = true; ibPick = null; }
tplBack().addEventListener('click', e=>{ if(e.target === tplBack()) closeTpl(); });
document.querySelector('.ib-tpl-x').addEventListener('click', closeTpl);
el('ib-tpl-back').addEventListener('click', ()=> renderTplList());

async function openTpl(preselectId){
  if(!ibCur) return;
  tplBack().hidden = false;
  if (ibTpls === null) {
    el('ib-tpl-list').innerHTML = '<p class="text-muted" style="font-size:12.5px">Loading…</p>';
    try {
      const r = await fetch(IB_URL+IB_SEP+'ajax=templates'); const d = await r.json();
      ibTpls = d.ok ? (d.templates || []) : [];
    } catch(e){ ibTpls = []; }
  }
  const i = preselectId ? ibTpls.findIndex(t => t.id === preselectId) : -1;
  if (i >= 0) pickTpl(i); else renderTplList();
}

function renderTplList(){
  ibPick = null;
  el('ib-tpl-foot').hidden = true;
  const box = el('ib-tpl-list');
  if (!ibTpls.length) {
    box.innerHTML = '<p class="text-muted" style="font-size:12.5px">'
      + 'No approved templates yet. Templates are approved by WhatsApp before you can send them — '
      + 'add one on the Templates page.</p>';
    return;
  }
  /* Utility first. When a number has just been capped, only a utility template still gets
     through, so burying them under the marketing ones makes the picker repeat the very problem
     the client opened it to solve. */
  const order = ibTpls.map((t,i)=>({t,i})).sort((a,b)=> (a.t.capped_risk?1:0) - (b.t.capped_risk?1:0));
  box.innerHTML = order.map(({t,i})=>`
    <button type="button" class="ib-tpl-row" data-i="${i}">
      <b>${esc(t.name)} ${t.capped_risk
        ? '<span class="ib-tpl-tag warn">marketing · can be capped</span>'
        : '<span class="ib-tpl-tag ok">utility · not capped</span>'}</b>
      <span>${esc((t.preview||'').slice(0,160))}</span>
      <em>${esc(t.language)}${
        t.body_vars||t.header_vars?' · '+(t.body_vars+t.header_vars)+' field(s) to fill':''}${
        t.needs_media?' · needs a '+esc(t.needs_media)+' link':''}</em>
    </button>`).join('');
  box.querySelectorAll('.ib-tpl-row').forEach(b=>
    b.addEventListener('click',()=> pickTpl(parseInt(b.dataset.i,10))));
}

function pickTpl(i){
  ibPick = ibTpls[i];
  const f = [];
  for (let n=1; n<=ibPick.header_vars; n++)
    f.push(`<div class="ib-tpl-f"><label>Header field {{${n}}}</label><input data-h="${n}"></div>`);
  for (let n=1; n<=ibPick.body_vars; n++)
    f.push(`<div class="ib-tpl-f"><label>Field {{${n}}}</label><input data-v="${n}"></div>`);
  if (ibPick.needs_media) {
    /* Prefilled with the image this template was last sent with, because that is almost always
       the right one and the agent should not have to go and find it. Upload is there for the
       first send, or when they want a different one. */
    const cur = ibPick.last_media || '';
    f.push(`<div class="ib-tpl-f">
      <label>${esc(ibPick.needs_media)} — reusing the one from your campaign${cur?'':' (none found yet, upload or paste a link)'}</label>
      <div style="display:flex;gap:6px;align-items:center">
        <input data-m="1" value="${esc(cur)}" placeholder="https://…" style="flex:1">
        ${IB_UPLOAD ? `<button type="button" class="btn btn-ghost btn-sm" id="ib-tpl-up">Upload</button>
        <input type="file" id="ib-tpl-file" hidden accept="image/*,video/mp4,application/pdf">` : ''}
      </div>
      <span id="ib-tpl-upst" class="text-muted" style="font-size:11px"></span>
    </div>`);
  }
  el('ib-tpl-list').innerHTML =
    `<b style="font-size:13px">${esc(ibPick.name)}</b>
     <span style="display:block;font-size:11.5px;color:#667;white-space:pre-wrap;margin-top:4px">${esc(ibPick.preview||'')}</span>
     ${f.join('') || '<p class="text-muted" style="font-size:12.5px;margin-top:10px">Nothing to fill in — ready to send.</p>'}
     <div class="ib-tpl-err" id="ib-tpl-err" hidden></div>`;
  el('ib-tpl-foot').hidden = false;

  const up = el('ib-tpl-up'), file = el('ib-tpl-file'), st = el('ib-tpl-upst');
  if (up && file) {
    up.addEventListener('click', ()=> file.click());
    file.addEventListener('change', async ()=>{
      if (!file.files || !file.files[0]) return;
      st.textContent = 'Uploading…'; up.disabled = true;
      const fd = new FormData(); fd.append('csrf_token', IB_CSRF); fd.append('media', file.files[0]);
      try {
        const r = await fetch(IB_UPLOAD, {method:'POST', body:fd}); const d = await r.json();
        if (d.ok) {
          el('ib-tpl-list').querySelector('[data-m]').value = d.url;
          st.textContent = '✓ Uploaded';
        } else st.textContent = d.error || 'Upload failed.';
      } catch(e){ st.textContent = 'Upload failed.'; }
      up.disabled = false;
    });
  }
}

el('ib-tpl-send').addEventListener('click', async ()=>{
  if(!ibPick || !ibCur) return;
  const btn = el('ib-tpl-send'); btn.disabled = true; btn.textContent = 'Sending…';
  const fd = new FormData();
  fd.append('ajax','send_template'); fd.append('csrf_token',IB_CSRF);
  fd.append('contact',ibCur); fd.append('template',ibPick.id); if (ibLine) fd.append('line', ibLine); else if (ibFrom) fd.append('line', ibFrom);
  el('ib-tpl-list').querySelectorAll('[data-v]').forEach(i=> fd.append('vars[]', i.value));
  el('ib-tpl-list').querySelectorAll('[data-h]').forEach(i=> fd.append('header_vars[]', i.value));
  const med = el('ib-tpl-list').querySelector('[data-m]');
  if (med) fd.append('header_media', med.value);
  try {
    const r = await fetch(IB_URL,{method:'POST',body:fd}); const d = await r.json();
    if (d.ok) { closeTpl(); pollThread(); loadThreads(); }
    else {
      const err = el('ib-tpl-err');
      err.hidden = false; err.textContent = d.error || 'Could not send.';
    }
  } catch(e){
    const err = el('ib-tpl-err'); err.hidden = false; err.textContent = 'Could not send.';
  }
  btn.disabled = false; btn.textContent = 'Send';
});
el('ib-back').addEventListener('click',()=>{
  document.querySelector('.ib-wrap').classList.remove('chatting');
  ibCur=0; loadThreads();
});
el('ib-q').addEventListener('input',()=>{ clearTimeout(window._ibq); window._ibq=setTimeout(loadThreads,300); });
/* Sync: bring in the linked phone's chats again (the number picked in the filter, or all). */
function ibSyncShow(sy){
  const b = el('ib-sync'), st = el('ib-sync-st'); if (!b || !st) return;
  const on = !!(sy && sy.running);
  b.disabled = on; b.innerHTML = on ? '&#8635; Syncing…' : '&#8635; Sync';
  st.hidden = !on; if (on) st.textContent = 'Bringing in your phone\'s chats… ' + (sy.imported||0).toLocaleString() + ' messages so far.';
}
if (el('ib-sync')) el('ib-sync').addEventListener('click', async ()=>{
  const b = el('ib-sync'); b.disabled = true;
  const fd = new FormData(); fd.append('ajax','sync_history'); fd.append('csrf_token',IB_CSRF);
  const lf = ibLineFilter; if (lf) fd.append('line', lf);
  try {
    const d = await (await fetch(IB_URL, {method:'POST', body:fd})).json();
    if (!d.ok) { alert(d.error || 'Could not start the sync.'); b.disabled = false; return; }
    ibSyncShow(d.sync); const st = el('ib-sync-st'); st.hidden = false; st.textContent = d.message;
  } catch (e) { b.disabled = false; }
});
loadThreads(); setInterval(loadThreads,5000); setInterval(pollThread,3500);
el('ib-owner')?.addEventListener('change', async e => {
  if (!ibCur) return;
  const fd = new FormData(); fd.append('ajax','assign'); fd.append('csrf_token',IB_CSRF);
  fd.append('contact',ibCur); fd.append('user_id', e.target.value);
  const d = await (await fetch(IB_URL,{method:'POST',body:fd})).json().catch(()=>({ok:false}));
  if (!d.ok) alert(d.error || 'Could not reassign.');
  loadThreads();
});
/* Arriving from a link to one conversation (the CRM's "Open conversation"): open it straight away. */
const IB_OPEN = <?= json_encode($IB_OPEN ?? null) ?>;
if (IB_OPEN) openThread(IB_OPEN.id, IB_OPEN.name, IB_OPEN.phone);
</script>
