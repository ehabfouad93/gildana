<?php
/**
 * The demo agency the walkthrough video is recorded against.
 *
 * Everything here is invented. The names are made up, the numbers are in Egypt's 010 range but
 * are not real subscribers, and the WhatsApp token is the string "DEMO" — the tour runs against
 * the local mock Graph API, never Meta and never a live account. Nothing a real customer typed
 * can reach a frame of the video, because no real customer's data is ever loaded.
 *
 * Why a real-estate agency: it is the market the Lead Qualifier is positioned for, so the demo
 * shows the product doing the job a viewer is actually trying to picture. Round numbers and
 * "Test Co" read as a sandbox; a named agency with a launch campaign and half-finished
 * conversations reads as a working account.
 *
 * Seeded against the real schema, the same way tests seed — no fixture format of its own, so a
 * migration that changes a column breaks this loudly instead of producing a video of a page
 * that no longer exists.
 *
 *   php tools/tour/seed_demo.php            # wipes clients and reseeds
 *
 * DESTRUCTIVE: it deletes every client, which cascades. Only ever run it against the local
 * recording rig. It refuses to run if the database looks like production.
 */
declare(strict_types=1);

chdir(dirname(__DIR__, 2));
require 'includes/config_loader.php';
require 'includes/helpers.php';
require 'includes/crypto.php';
require 'includes/db.php';

/* ── the guard ──
   This truncates the clients table. The one thing that must never happen is someone running it
   against the live database because they were in the wrong shell. A local rig talks to
   127.0.0.1; production does not. */
$host = (string) (config('db')['host'] ?? '');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run: the database host is '{$host}', which is not a local rig.\n"
                 . "This script deletes every client. It is only for the recording rig.\n");
    exit(1);
}

$DEMO_PASS = 'tour-demo-2026';
$now       = new DateTimeImmutable('now');
$ago       = fn(string $i) => $now->sub(new DateInterval($i))->format('Y-m-d H:i:s');

echo "Seeding the demo agency…\n";
db_run("DELETE FROM clients");          // cascades to everything below

/* ── the agency ──────────────────────────────────────────────────────────── */
$cid = db_insert(
    "INSERT INTO clients
       (name, sender_display, company, contact_person, contact_phone, contact_email, category,
        default_country, phone_number_id, waba_id, access_token_enc, waba_mode,
        credits_balance, low_credit_threshold, status, channel, created_at)
     VALUES
       ('Nile View Properties','Nile View','Nile View Properties','Layla Mansour','201000000001',
        'hello@nileview.example','Real estate','20','100000000000001','WABA-DEMO',?, 'byo',
        8450, 500, 'active', 'cloud', ?)",
    [encrypt_secret('DEMO-NOT-A-REAL-TOKEN'), $ago('P8M')]
);

db_insert("INSERT INTO users (client_id,email,name,password_hash,role,status,created_at)
           VALUES (?,'layla@nileview.example','Layla Mansour',?,'client','active',?)",
    [$cid, password_hash($DEMO_PASS, PASSWORD_DEFAULT), $ago('P8M')]);

/* ── contacts ────────────────────────────────────────────────────────────────
   Invented names, and numbers built from a fixed prefix plus an index so no run of this
   script can accidentally land on somebody's real line. Tags are the ones a property agency
   would really keep: where they are looking, and how warm they are. */
$first = ['Ahmed','Mona','Karim','Salma','Youssef','Nour','Tarek','Farida','Omar','Heba',
          'Sherif','Dina','Hassan','Rana','Amr','Yasmin','Khaled','Mariam','Ziad','Nada',
          'Mostafa','Aya','Bassem','Laila','Hany','Sara','Waleed','Reem','Ehab','Injy',
          'Sameh','Doaa','Fady','Menna','Rami','Habiba','Ayman','Malak','Sherine','Adel'];
$last  = ['Hassan','Fahmy','Zaki','Nabil','Shafik','Ramzy','Lotfy','Sabry','Helmy','Gaber'];

$areas = ['new-cairo','sheikh-zayed','north-coast','maadi'];
$warm  = ['hot','hot','warm','warm','warm','cold',''];

$contactIds = [];
for ($i = 0; $i < 40; $i++) {
    $name  = $first[$i] . ' ' . $last[$i % count($last)];
    $phone = '2010' . str_pad((string) (55000000 + $i * 37), 8, '0', STR_PAD_LEFT);
    $tags  = array_values(array_filter([$areas[$i % 4], $warm[$i % 7]]));
    if ($i % 9 === 0) $tags[] = 'viewing-booked';

    $contactIds[] = db_insert(
        "INSERT INTO contacts (client_id, phone_e164, name, attributes, opt_in_status, tags,
                               source, last_inbound_at, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [$cid, $phone, $name,
         json_encode(['budget' => [3, 5, 7, 9][$i % 4] . 'M EGP', 'unit' => ['Apartment','Villa','Townhouse','Chalet'][$i % 4]]),
         $i % 17 === 0 ? 'opted_out' : 'opted_in',
         implode(',', $tags), $i % 3 === 0 ? 'sheet' : 'import',
         $i < 6 ? $ago('PT' . (3 + $i) . 'H') : null,
         $ago('P' . (7 + $i) . 'D')]);
}
echo "  40 contacts\n";

/* ── lists ── */
$listLaunch = db_insert("INSERT INTO contact_lists (client_id,name,created_at) VALUES (?,?,?)",
    [$cid, 'New Cairo — October launch', $ago('P21D')]);
$listVip    = db_insert("INSERT INTO contact_lists (client_id,name,created_at) VALUES (?,?,?)",
    [$cid, 'Booked a viewing', $ago('P14D')]);

foreach ($contactIds as $i => $id) {
    if ($i % 4 !== 3) db_run("INSERT IGNORE INTO contact_list_members (list_id,contact_id) VALUES (?,?)", [$listLaunch, $id]);
    if ($i % 9 === 0) db_run("INSERT IGNORE INTO contact_list_members (list_id,contact_id) VALUES (?,?)", [$listVip, $id]);
}

/* ── templates ───────────────────────────────────────────────────────────────
   Three approved templates in the shape Meta actually returns: a marketing one with a header
   image and two body variables, a utility reminder, and a plain follow-up. */
$tplLaunch = db_insert(
    "INSERT INTO templates (client_id,wa_name,language,category,status,components,body_text,variable_count,synced_at)
     VALUES (?,'new_cairo_launch','en','MARKETING','APPROVED',?,?,2,?)",
    [$cid, json_encode([
        ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['https://example.com/launch.jpg']]],
        ['type' => 'BODY',   'text' => "Hi {{1}}, our New Cairo release is open — 3 and 4 bedroom apartments from {{2}}. Reply and we'll send the floor plans."],
        ['type' => 'BUTTONS','buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Send floor plans'], ['type' => 'QUICK_REPLY', 'text' => 'Book a viewing']]],
     ]),
     "Hi {{1}}, our New Cairo release is open — 3 and 4 bedroom apartments from {{2}}. Reply and we'll send the floor plans.",
     $ago('P30D')]);

db_insert("INSERT INTO templates (client_id,wa_name,language,category,status,components,body_text,variable_count,synced_at)
           VALUES (?,'viewing_reminder','en','UTILITY','APPROVED',?,?,2,?)",
    [$cid, json_encode([['type' => 'BODY', 'text' => 'Hi {{1}}, a reminder about your viewing on {{2}}. Reply RESCHEDULE if the time no longer suits you.']]),
     'Hi {{1}}, a reminder about your viewing on {{2}}. Reply RESCHEDULE if the time no longer suits you.', $ago('P30D')]);

db_insert("INSERT INTO templates (client_id,wa_name,language,category,status,components,body_text,variable_count,synced_at)
           VALUES (?,'follow_up','en','MARKETING','APPROVED',?,?,1,?)",
    [$cid, json_encode([['type' => 'BODY', 'text' => 'Hi {{1}}, still looking? We have two units left in the release.']]),
     'Hi {{1}}, still looking? We have two units left in the release.', $ago('P16D')]);
echo "  3 templates\n";

/* ── a campaign that has already run ─────────────────────────────────────────
   The counts are not decoration: every one of them is backed by a campaign_messages row with
   the matching status and timestamps, so the report page adds up to exactly what the campaign
   row claims. A demo where the tiles and the table disagree is worse than no demo. */
$members  = db_all("SELECT contact_id FROM contact_list_members m JOIN contacts c ON c.id=m.contact_id
                     WHERE m.list_id=? AND c.opt_in_status='opted_in'", [$listLaunch]);
$total    = count($members);

$camp = db_insert(
    "INSERT INTO campaigns (client_id,name,template_id,list_id,variable_map,status,
                            total_count,sent_count,delivered_count,read_count,failed_count,
                            created_at,started_at,completed_at)
     VALUES (?,?,?,?,?,'completed',?,?,?,?,?,?,?,?)",
    [$cid, 'New Cairo launch — October', $tplLaunch, $listLaunch,
     json_encode(['1' => 'name', '2' => '4.2M EGP']),
     $total, $total, 0, 0, 0, $ago('P6D'), $ago('P6D'), $ago('P6D')]);

$delivered = $read = $failed = 0;
foreach ($members as $i => $m) {
    // A realistic mix: most delivered, over half read, one hard bounce.
    $st = 'sent';
    if ($i % 11 === 7)      { $st = 'failed'; }
    elseif ($i % 3 !== 2)   { $st = 'read'; }
    else                    { $st = 'delivered'; }

    $sentAt = $ago('P6DT' . (2 + ($i % 5)) . 'H');
    db_run(
        "INSERT INTO campaign_messages (campaign_id,client_id,contact_id,phone_e164,status,
                                        wa_message_id,error_code,error_title,
                                        sent_at,delivered_at,read_at,updated_at)
         SELECT ?,?,id,phone_e164,?,?,?,?,?,?,?,? FROM contacts WHERE id=?",
        [$camp, $cid, $st,
         $st === 'failed' ? null : 'wamid.DEMO' . $i,
         $st === 'failed' ? '131026' : null,
         $st === 'failed' ? 'Message undeliverable — the number is not on WhatsApp' : null,
         $sentAt,
         in_array($st, ['delivered', 'read'], true) ? $sentAt : null,
         $st === 'read' ? $sentAt : null,
         $sentAt, (int) $m['contact_id']]);

    if ($st === 'failed') $failed++;
    if (in_array($st, ['delivered', 'read'], true)) $delivered++;
    if ($st === 'read') $read++;
}
db_run("UPDATE campaigns SET sent_count=?, delivered_count=?, read_count=?, failed_count=? WHERE id=?",
    [$total - $failed, $delivered, $read, $failed, $camp]);
echo "  1 campaign — {$total} sent, {$delivered} delivered, {$read} read, {$failed} failed\n";

/* A second campaign sitting in draft, so the campaigns list is not one lonely row. */
db_insert("INSERT INTO campaigns (client_id,name,template_id,list_id,status,total_count,created_at)
           VALUES (?,'Booked viewings — reminder',?,?,'draft',0,?)",
    [$cid, $tplLaunch, $listVip, $ago('P2D')]);

/* ── inbox ───────────────────────────────────────────────────────────────────
   Four threads mid-conversation. The newest is unread and is the one the tour opens and
   replies to, so the reply lands somewhere that looks alive rather than in an empty pane. */
$threads = [
    [0, [['in',  'Yes please, send me the floor plans for the 3 bedroom'],
         ['out', 'Of course — sending them now. Are you looking to move in this year?'],
         ['in',  'Hopefully before December if the finishing is done']]],
    [1, [['in',  'What is the price for the 4 bedroom?'],
         ['out', 'The 4 bedroom starts at 6.8M EGP with a 10% down payment over 8 years.'],
         ['in',  'Can I see it on Friday morning?']]],
    [2, [['in',  'Is the North Coast project still open?'],
         ['out', 'It is — chalets from 4.2M. Would you like the brochure?']]],
    [3, [['in',  'Booked. See you Saturday at 11.']]],
];
foreach ($threads as [$idx, $msgs]) {
    $contactId = $contactIds[$idx];
    foreach ($msgs as $k => [$dir, $body]) {
        db_run("INSERT INTO messages (client_id,contact_id,direction,type,body,wa_message_id,status,source,created_at)
                VALUES (?,?,?,'text',?,?,?, 'wa', ?)",
            [$cid, $contactId, $dir, $body, 'wamid.THREAD' . $idx . $k,
             $dir === 'out' ? 'read' : 'received',
             $ago('PT' . (20 - $idx * 4 - $k) . 'H')]);
    }
    // Read up to the last outbound, so the threads with a trailing inbound show as unread.
    db_run("UPDATE contacts SET inbox_read_at=? WHERE id=?", [$ago('PT21H'), $contactId]);
}
echo "  4 inbox threads\n";

/* ── an automation ───────────────────────────────────────────────────────────
   A flow that answers a first-time enquiry, asks the two questions an agent always asks, tags
   the contact and hands over. Positions are set so the canvas opens laid out rather than with
   every node stacked at the origin. */
$flow = db_insert(
    "INSERT INTO flows (client_id,name,kind,status,trigger_type,trigger_config,runs_count,created_at,updated_at)
     VALUES (?,'Enquiry — greet and qualify','bot','active','welcome',?,?,?,?)",
    [$cid, json_encode(['keywords' => []]), 60, $ago('P40D'), $ago('P3D')]);

/* Positions start at x=300: the trigger block has no stored position of its own — the editor
   draws it at (60, 220) — so anything nearer than that sits on top of it. */
$mk = function (string $type, array $cfg, int $x, int $y, int $sort) use ($flow, $cid) {
    return db_insert("INSERT INTO flow_steps (flow_id,client_id,sort,pos_x,pos_y,type,config,created_at)
                      VALUES (?,?,?,?,?,?,?,NOW())",
        [$flow, $cid, $sort, $x, $y, $type, json_encode($cfg)]);
};

$sThanks = $mk('text',     ['body' => "Thanks {{name}} — one of our agents will call you today."], 1080, 330, 5);
$sTag    = $mk('tag',      ['tag' => 'hot'],                                                       1080, 150, 4);
$sBudget = $mk('question', ['body' => 'And roughly what budget are you working with?',
                            'save_as' => 'budget'],                                                820, 240, 3);
$sArea   = $mk('question', ['body' => 'Which area are you looking in — New Cairo, Sheikh Zayed or the North Coast?',
                            'save_as' => 'area'],                                                  560, 240, 2);
$sGreet  = $mk('text',     ['body' => "Hi {{name}}! Thanks for getting in touch with Nile View. I have a couple of quick questions so we can send you the right units."], 300, 240, 1);

db_run("UPDATE flow_steps SET next_step_id=? WHERE id=?", [$sArea,   $sGreet]);
db_run("UPDATE flow_steps SET next_step_id=? WHERE id=?", [$sBudget, $sArea]);
db_run("UPDATE flow_steps SET next_step_id=? WHERE id=?", [$sTag,    $sBudget]);
db_run("UPDATE flow_steps SET next_step_id=? WHERE id=?", [$sThanks, $sTag]);
db_run("UPDATE flows SET first_step_id=? WHERE id=?", [$sGreet, $flow]);

/* Per-node stats, so the canvas shows real drop-off instead of empty counters.
   The table is keyed (run_id, step_id) — one row per run per step, exactly what the engine
   writes at run time — so these have to be backed by real flow_runs rather than invented
   totals. Sixty people walked in; the budget question is where they stop answering, which is
   what it looks like in practice too, and is the whole reason the canvas shows this at all. */
$path = [$sGreet, $sArea, $sBudget, $sTag, $sThanks];
$reach = [60, 60, 52, 34, 34];          // how many got as far as each step

for ($r = 0; $r < 60; $r++) {
    $contactId = $contactIds[$r % count($contactIds)];
    $runId = db_insert(
        "INSERT INTO flow_runs (flow_id,client_id,contact_id,enrol_key,status,score,context,created_at,updated_at)
         VALUES (?,?,?,?,?,0,'{}',?,?)",
        [$flow, $cid, $contactId, 'demo-auto-' . $flow . '-' . $r,
         $r < 34 ? 'completed' : 'stopped',
         $ago('P' . (1 + $r % 25) . 'D'), $ago('P' . (1 + $r % 25) . 'D')]);

    foreach ($path as $k => $sid) {
        if ($r >= $reach[$k]) break;                       // this run never got here
        $advanced = isset($reach[$k + 1]) ? $r < $reach[$k + 1] : true;
        db_run("INSERT INTO flow_step_events (flow_id,step_id,run_id,client_id,outcome,reason,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?)",
            [$flow, $sid, $runId, $cid, $advanced ? 'advanced' : 'stalled',
             null,      // no reason: people simply stopped replying, which is not a fault
             $ago('P' . (1 + $r % 25) . 'D'), $ago('P' . (1 + $r % 25) . 'D')]);
    }
}
db_run("UPDATE flows SET runs_count=60 WHERE id=?", [$flow]);
echo "  1 automation with 5 steps\n";

/* ── the lead qualifier ──────────────────────────────────────────────────────
   Graded leads, because the whole point of the section is the grade. The transcript is stored
   the way a real run stores it, so opening one in the video shows a genuine conversation. */
$qual = db_insert(
    "INSERT INTO flows (client_id,name,kind,status,trigger_type,hot_min,warm_min,runs_count,created_at,updated_at)
     VALUES (?,'Property enquiries — October','qualifier','active','google_sheet',70,40,?,?,?)",
    [$cid, 38, $ago('P25D'), $ago('P1D')]);

$grades = [['hot', 92], ['hot', 84], ['warm', 61], ['hot', 78], ['cold', 22], ['warm', 55],
           ['hot', 88], ['warm', 47], ['cold', 18], ['hot', 95], ['warm', 52], ['cold', 31]];
foreach ($grades as $i => [$g, $score]) {
    $contactId = $contactIds[$i + 4];
    $ctx = ['fields' => ['area' => $areas[$i % 4], 'budget' => [3, 5, 7, 9][$i % 4] . 'M EGP',
                         'timeline' => ['this month', 'within 3 months', 'just looking'][$i % 3]],
            'transcript' => [
                ['role' => 'assistant', 'text' => 'Hi! You asked about our New Cairo release. Which unit size are you after?'],
                ['role' => 'user', 'text' => ['3 bedroom', '4 bedroom', 'A villa if the price is right'][$i % 3]],
                ['role' => 'assistant', 'text' => 'And when are you hoping to buy?'],
                ['role' => 'user', 'text' => ['This month', 'Within three months', 'Just looking for now'][$i % 3]],
            ]];
    db_insert("INSERT INTO flow_runs (flow_id,client_id,contact_id,enrol_key,status,score,grade,context,created_at,updated_at)
               VALUES (?,?,?,?, 'completed', ?,?,?,?,?)",
        [$qual, $cid, $contactId, 'demo-' . $qual . '-' . $contactId, $score, $g,
         json_encode($ctx, JSON_UNESCAPED_UNICODE), $ago('P' . (2 + $i) . 'D'), $ago('P' . (2 + $i) . 'D')]);
}
// A couple still mid-conversation, so "Chatting" is not zero.
foreach ([16, 17] as $i) {
    db_insert("INSERT INTO flow_runs (flow_id,client_id,contact_id,enrol_key,status,score,grade,context,created_at,updated_at)
               VALUES (?,?,?,?, 'waiting_input', 0, NULL, '{}', ?, ?)",
        [$qual, $cid, $contactIds[$i], 'demo-' . $qual . '-' . $contactIds[$i], $ago('PT5H'), $ago('PT2H')]);
}
echo "  14 qualified leads\n";

/* ── credits ledger ──────────────────────────────────────────────────────────
   Billing shows a ledger, so give it entries to show. */
db_run("INSERT INTO credit_transactions (client_id,delta,balance_after,reason,created_at)
        VALUES (?, 10000, 10000, 'Monthly plan allowance', ?)", [$cid, $ago('P30D')]);
db_run("INSERT INTO credit_transactions (client_id,delta,balance_after,reason,campaign_id,created_at)
        VALUES (?, ?, ?, 'Campaign: New Cairo launch', ?, ?)",
    [$cid, -$total, 10000 - $total, $camp, $ago('P6D')]);

echo "\nDone.\n  Sign in at /login.php as layla@nileview.example / {$DEMO_PASS}\n";
