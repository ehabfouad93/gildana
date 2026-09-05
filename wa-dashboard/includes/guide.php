<?php
declare(strict_types=1);

/**
 * "How to use this page" — a short, numbered walkthrough attached to every screen.
 *
 * These live in code rather than in the database, unlike the FAQ. The FAQ answers questions
 * about the product and is the operator's to reword; these describe the buttons on the page
 * they sit on, which only change when the code changes. Putting them in a table would mean
 * every interface change silently left an instruction lying about what is on screen.
 *
 * page_head() renders the button on its own, keyed on the nav item the page already declared
 * to layout_header(). So a screen gets its guide by existing, not by remembering to ask.
 *
 * Each entry: title · a one-line "what this page is for" · numbered steps · optional tips.
 * Steps are the first-run path — the shortest route from an empty screen to a working one.
 * They are not documentation of every feature; a person reads these once.
 */

/* The guide panel offers the walkthrough video when one is set up, and help.php is what
   knows whether there is one. It is loaded here rather than relied on: layout_footer() also
   requires it, but that runs AFTER the guide is rendered, so the link never appeared. */
require_once __DIR__ . '/help.php';

function guide_all(): array
{
    return [

    /* ── client ─────────────────────────────────────────────────────────────── */
    'dashboard' => [
        'title' => 'Your dashboard',
        'intro' => 'Everything at a glance, and the quickest route to whatever is unfinished.',
        'steps' => [
            ['Check you can send', 'The tiles at the top show your credits and whether your WhatsApp number is connected. If a warning sits above them, start there — nothing sends until it is cleared.'],
            ['Follow the checklist', 'Below the tiles, the getting-started list shows what is done and what is next. It ticks itself off as you go, and disappears once you are set up.'],
            ['Watch a campaign land', 'Recent campaigns show sent, delivered and failed counts as they happen. Click one for the full report.'],
        ],
        'tips' => ['One credit is one message that goes out. Replies you receive are free.'],
    ],

    'inbox' => [
        'title' => 'The inbox',
        'intro' => 'Every WhatsApp conversation in one place, on any device.',
        'steps' => [
            ['Pick a conversation', 'Threads are newest first, with unread ones marked. On a phone the list and the chat are separate screens — tap a thread to open it, and use the back arrow to return.'],
            ['Reply', 'Type and send. Within 24 hours of a customer’s last message you can send anything; after that WhatsApp only allows an approved template, and the box will tell you so.'],
            ['Pause the bot when you take over', 'If an automation is handling a conversation and you want to answer yourself, pause it for that contact. It picks back up when you release it.'],
        ],
        'tips' => ['Turn on notifications in Settings and your phone will tell you when someone replies.'],
    ],

    'contacts' => [
        'title' => 'Contacts',
        'intro' => 'Everyone you can message, and the tags that let you slice them.',
        'steps' => [
            ['Bring people in', 'Import CSV takes a file with a phone column — a name column and any others become personalisation fields. Or add one at a time with + Add Contact.'],
            ['Tag them', 'Tick the contacts you want, then Add tag. Tags are how you find a group again later: hot, new-cairo, november-event — whatever means something to you.'],
            ['Filter by a tag', 'The chips above the table filter it. Pick one and the table shows only those contacts.'],
            ['Act on a whole group', 'With rows ticked, the bar offers Add to list, Add tag, Remove tag, Opt out and Delete. When you have ticked the whole page and more match, it offers to select all of them — so you can act on four thousand as easily as four.'],
        ],
        'tips' => [
            'Your automations write tags too, so a contact the AI graded hot is tagged hot here.',
            'Anyone who replies STOP is opted out immediately and left out of every campaign after that. You do not have to do anything.',
        ],
    ],

    'lists' => [
        'title' => 'Lists',
        'intro' => 'A saved audience. Campaigns send to a list, so this is the step before sending.',
        'steps' => [
            ['Create a list', '+ New List. If you already tag your contacts, pick a tag and the list fills itself the moment it is created — leave the name blank and it takes the tag’s name.'],
            ['Or add people by hand', 'Open a list and search for contacts to add one at a time.'],
            ['Top it up later', 'Inside a list, every tag is a button that adds everyone carrying it. Press it again next month as more get tagged — anyone already in is skipped.'],
        ],
        'tips' => ['A campaign only messages opted-in members, so an opted-out contact sitting in a list is harmless.'],
    ],

    'templates' => [
        'title' => 'Templates',
        'intro' => 'The pre-approved messages WhatsApp requires before a customer has replied to you.',
        'steps' => [
            ['Write them in Meta', 'Templates are created and approved in your Meta account, not here. Approval usually takes minutes.'],
            ['Sync', 'Press Sync Templates and the approved ones appear here, ready for campaigns.'],
            ['Check what each one needs', 'Open a template to see its variables and whether it wants a header image. A template with an image header will fail without one, so it is worth looking before you build a campaign around it.'],
        ],
        'tips' => ['Sending from your own number instead of the API? You have no templates and need none — you write the message in the campaign itself.'],
    ],

    'campaigns' => [
        'title' => 'Campaigns',
        'intro' => 'One message to a whole list, sent now or scheduled.',
        'steps' => [
            ['Start one', '+ New Campaign. Name it something you will recognise in the report later.'],
            ['Choose the message', 'On the API, pick an approved template and fill its blanks — a fixed value, or the contact’s own name or details. On your own number, just write the message and attach an image if you want one.'],
            ['Choose who and when', 'Pick a list; the count of opted-in members and the credit cost appear as you do. Send now, or schedule it.'],
            ['Keep the conversation going', 'Under “After sending” you can hand everyone to an automation — when they reply, when it is delivered, or after a set wait with no reply.'],
            ['Watch it', 'The report shows sent, delivered, read and failed as they happen. Anything that failed for good lands on Needs attention with the reason.'],
        ],
        'tips' => ['Duplicate on any campaign opens a filled-in copy — the same message and settings, ready to review before you send it again.'],
    ],

    'automations' => [
        'title' => 'Automations',
        'intro' => 'A conversation that runs itself: someone messages you, and the flow answers.',
        'steps' => [
            ['Start from a ready-made one', 'The starter flows at the top are complete and working. Install one, read through it, change the words to yours. Much faster than an empty canvas.'],
            ['Or build your own', '+ New Automation, choose what starts it — a keyword, anyone’s first message, a default reply, or a new row in a Google Sheet.'],
            ['Add steps', 'On the canvas, press the + on a step to add the next one, already connected. On a phone, use the ordered list below the canvas instead — no dragging.'],
            ['Try it before anyone sees it', 'Preview this flow plays the whole conversation on screen and lets you answer as the customer. Nothing is sent and no credits are spent.'],
            ['Check, then switch it on', 'Check for problems finds dead ends, questions with no way out and messages the 24-hour rule would block. Fix those, then use the toggle to go live.'],
        ],
        'tips' => [
            'Your work saves itself. The toolbar says Saved when it has.',
            'Once it is running, each step shows how many people reached it and how many stopped there — that is where to look when a flow is losing people.',
        ],
    ],

    'qualifier' => [
        'title' => 'Lead Qualifier',
        'intro' => 'Leads from a sheet, contacted on WhatsApp, questioned by AI, and handed back graded.',
        'steps' => [
            ['Connect the sheet', 'Point it at the Google Sheet your ads or your team fill. Say which column is the phone and which is the name. New rows are picked up on their own.'],
            ['Write the first message', 'An approved template on the API, or your own words on your own number. This is what every new lead receives.'],
            ['Teach the AI', 'Paste your price list, project details and policies into the knowledge box, or upload a document. The AI answers from that and nothing else. Set the persona and the language or dialect you want.'],
            ['Ask your questions', 'List what your sales team would ask — budget, area, unit type, payment, timing — and what to capture from the answers.'],
            ['Say what a good lead looks like', 'Write the criterion the AI scores against, and set the hot and warm thresholds. Then switch it on.'],
            ['Read the results', 'Leads shows every lead with its score, its grade and the reason, next to the whole conversation.'],
        ],
        'tips' => ['Send now imports any new rows and reaches out immediately, rather than waiting for the next check.'],
    ],

    'agents' => [
        'title' => 'AI Chat Agent',
        'intro' => 'An assistant that answers customers who message you, using only what you taught it.',
        'steps' => [
            ['Create one', '+ New Agent, and choose whether it answers everyone or only after a keyword.'],
            ['Give it its knowledge', 'Prices, policies, opening hours, the questions you are asked all day. It will not invent anything outside this.'],
            ['Set the persona and the goals', 'Who it is, how it should sound, and what it should be trying to achieve in the conversation.'],
            ['Decide when a human takes over', 'Set the handover so it stops and calls you rather than guessing when it is out of its depth.'],
            ['Switch it on', 'Use the toggle. Chats shows every conversation it has had.'],
        ],
        'tips' => ['It only replies within WhatsApp’s 24-hour window, which is exactly when someone has just messaged you.'],
    ],

    'reports' => [
        'title' => 'Reports',
        'intro' => 'What was sent, what arrived, and what it cost.',
        'steps' => [
            ['Pick a period', 'The totals cover sends, deliveries, reads and failures across every campaign.'],
            ['Open a campaign', 'Its own report breaks the same numbers down per message, with the reason beside anything that failed.'],
        ],
        'tips' => ['Delivered and read only arrive on the official API — a personal number reports sent and no more.'],
    ],

    'billing' => [
        'title' => 'Billing',
        'intro' => 'Your plan, what you have used, and every credit accounted for.',
        'steps' => [
            ['Check the meters', 'Credits used this month against what your plan includes, and the same for AI if your plan includes it.'],
            ['Read the ledger', 'Every credit spent and every refund, with what it was for. Nothing moves without a line here.'],
        ],
        'tips' => ['A message that fails for good is refunded automatically — you will see it come back.'],
    ],

    'failed' => [
        'title' => 'Needs attention',
        'intro' => 'Messages that could not be delivered, and why.',
        'steps' => [
            ['Read the reason', 'WhatsApp’s own error sits next to each one. Usually it is a wrong number, a template problem, or a contact who blocked you.'],
            ['Fix and retry', 'Correct the cause, then retry the ones worth retrying.'],
        ],
        'tips' => ['A message marked for review was sent once but never confirmed. Check WhatsApp before retrying, or you may message someone twice.'],
    ],

    'settings' => [
        'title' => 'Settings',
        'intro' => 'Your number, your keys, and how the app behaves.',
        'steps' => [
            ['Connect your number', 'On your own number, scan the QR code with the phone — keep that phone online. On the official API your credentials are entered for you.'],
            ['Add your AI key', 'If you want the AI features and your plan does not include them, paste your own key here. It is encrypted and never shown again.'],
            ['Connect Google', 'One click, if you want automations to read from or write to your sheets.'],
            ['Turn on notifications', 'So your phone tells you when a customer replies.'],
        ],
        'tips' => ['Install the dashboard to your home screen from your browser’s menu and it behaves like an app.'],
    ],

    'leads' => [
        'title' => 'Leads',
        'intro' => 'Every lead a qualifier has spoken to, with its score.',
        'steps' => [
            ['Sort by grade', 'Hot first is usually what you want. The score and the reason the AI gave sit together.'],
            ['Open a conversation', 'The whole exchange is kept, so you can see what was actually said before you call.'],
        ],
    ],

    /* ── admin ──────────────────────────────────────────────────────────────── */
    'clients' => [
        'title' => 'Clients',
        'intro' => 'Every account on the platform.',
        'steps' => [
            ['Create the account', '+ New Client, with a login for them. They cannot sign up themselves.'],
            ['Connect their WhatsApp', 'For the official API, enter their Phone Number ID, WABA ID, access token and App Secret — all from their own Meta app. For their own number, set the channel to personal and they scan the QR themselves.'],
            ['Give them credits', 'Quick top-up. Nothing sends on an empty balance.'],
            ['Test it', 'Test connection proves the credentials work before they find out the hard way.'],
        ],
        'tips' => ['Open Workspace lets you use their dashboard as they see it, which is the fastest way to answer a support question.'],
    ],

    'plans' => [
        'title' => 'Plans & pricing',
        'intro' => 'What each tier includes, and how usage becomes money.',
        'steps' => [
            ['Set what a credit is worth', 'In your currency, plus the markup you add on top of cost. The markup only applies to clients sending on your WhatsApp account.'],
            ['Add your tiers', 'Price, included credits, limits, and whether AI is included or the client brings their own key.'],
            ['Assign them', 'A plan is attached to a client from Admin → that client.'],
        ],
        'tips' => ['A plan with clients on it cannot be deleted — move them first.'],
    ],

    'rates' => [
        'title' => 'Message rates',
        'intro' => 'What WhatsApp charges you, so you can charge it on.',
        'steps' => [
            ['Take the current figures from Meta', 'Their rates differ by country and category and they change, so nothing here is guessed for you.'],
            ['Add a rate per country and category', 'Country prefix without the +, or * for everywhere else. Leave service messages at zero — replies inside 24 hours are free.'],
            ['Add AI rates if you host AI', 'Per million tokens, per model.'],
        ],
        'tips' => ['These only price clients sending on YOUR WhatsApp account. A client on their own account is billed by Meta directly and never touches this table.'],
    ],

    'overview' => [
        'title' => 'Admin overview',
        'intro' => 'The whole platform in one screen: who is sending, what is failing, what is owed.',
        'steps' => [
            ['Scan the totals', 'Clients, messages sent and credits across every account. A number that has stopped moving usually means a client is stuck rather than quiet.'],
            ['Follow anything red', 'Failures and clients with no credit are the two things worth acting on today.'],
            ['Go to a client', 'From Clients you can open any account’s workspace and see exactly what they see.'],
        ],
    ],

    'team' => [
        'title' => 'Team',
        'intro' => 'The people who can sign in to the admin side.',
        'steps' => [
            ['Add someone', 'Give them their own login. Shared accounts make it impossible to tell who did what.'],
            ['Remove them the day they leave', 'Disabling keeps their history and stops them signing in.'],
        ],
    ],

    'help' => [
        'title' => 'Help content',
        'intro' => 'The FAQ your clients read, and the requests they send you.',
        'steps' => [
            ['Edit the questions', 'They appear both in the in-app Help centre and on your public site — one set, so they cannot disagree.'],
            ['Reorder or hide', 'Hiding removes a question from both places without deleting it.'],
            ['Answer the requests', 'Support requests and access requests from the site both land here.'],
        ],
    ],

    ];
}

/** The guide for a page, or null if it has none. */
function guide_for(string $key): ?array
{
    /* The nav key and the page name are not always the same word. "Needs attention" is
       failed.php but sits in the nav as `attention`, so both spellings find the one guide. */
    static $alias = ['attention' => 'failed'];
    $key = $alias[$key] ?? $key;
    $all = guide_all();
    return $all[$key] ?? null;
}

/**
 * The button and its panel, rendered inline by page_head().
 *
 * A <details> element, so it opens and closes with no JavaScript at all — it works on the
 * first paint, before any script has run, and keeps working if one fails.
 */
function guide_html(string $key): string
{
    $g = guide_for($key);
    if (!$g) return '';

    $h = '<details class="guide"><summary class="guide-btn" title="A short walkthrough of this page">'
       . '<span aria-hidden="true">?</span> How to use</summary>'
       . '<div class="guide-panel">'
       . '<h3>' . e((string) $g['title']) . '</h3>'
       . '<p class="guide-intro">' . e((string) $g['intro']) . '</p>'
       . '<ol class="guide-steps">';
    foreach ((array) ($g['steps'] ?? []) as [$t, $c]) {
        $h .= '<li><strong>' . e($t) . '</strong><span>' . e($c) . '</span></li>';
    }
    $h .= '</ol>';
    if (!empty($g['tips'])) {
        $h .= '<div class="guide-tips">';
        foreach ((array) $g['tips'] as $tip) $h .= '<p>' . e($tip) . '</p>';
        $h .= '</div>';
    }
    /* If a walkthrough video has been set up, every guide points at it. The panel below is the
       written version of the same tour, so the two belong next to each other rather than the
       video hiding on one page nobody visits. */
    $tour = (function_exists('intro_video_on') && intro_video_on())
        ? '<a href="' . (basename(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))) === 'admin'
                         || basename(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))) === 'client'
                            ? '../help.php' : 'help.php')
          . '">Watch the walkthrough video</a> · '
        : '';

    $h .= '<p class="guide-more">' . $tour . 'Still stuck? The <strong>Help</strong> button on any page has the '
        . 'full FAQ and a way to reach a person.</p>'
        . '</div></details>';
    return $h;
}

/**
 * The guide button on its own, for the handful of pages that build their page-head by hand
 * instead of calling page_head(). Same button, same panel — just placed by the page.
 */
function guide_button(?string $key = null): string
{
    return guide_html($key ?? (function_exists('layout_active') ? layout_active() : ''));
}

/**
 * The first-run checklist for a client, with each item already answered from real data.
 *
 * Only worth showing while something is still undone — a checklist of five ticks is clutter,
 * so the dashboard drops it once everything is complete.
 *
 * @return array{done:int, total:int, items: array<int, array{done:bool,label:string,help:string,url:string,cta:string}>}
 */
function guide_checklist(array $client): array
{
    $cid = (int) $client['id'];
    $n = function (string $sql) use ($cid): int {
        try { return (int) db_val($sql, [$cid]); } catch (Throwable $e) { return 0; }
    };

    $personal = function_exists('channel_is_personal') && channel_is_personal($client);
    $ready    = function_exists('client_ready') ? client_ready($client) : true;

    $items = [];
    $items[] = [
        'done'  => (bool) $ready,
        'label' => 'Connect your WhatsApp number',
        'help'  => $personal
            ? 'Scan the QR code with the phone you want to send from, and keep it online.'
            : 'Your API credentials are entered by ' . (defined('BRAND_PARENT') ? BRAND_PARENT : 'us') . '. Ask if this is still open.',
        'url'   => 'settings.php', 'cta' => 'Open Settings',
    ];
    $items[] = [
        'done'  => $n("SELECT COUNT(*) FROM contacts WHERE client_id=? AND opt_in_status='in'") > 0,
        'label' => 'Add your contacts',
        'help'  => 'Import a CSV, paste a list, or add them one at a time.',
        'url'   => 'contacts.php', 'cta' => 'Add contacts',
    ];
    $items[] = [
        'done'  => $n("SELECT COUNT(*) FROM contact_lists WHERE client_id=?") > 0,
        'label' => 'Make a list to send to',
        'help'  => 'A campaign goes to a list. Build one from a tag in one click, or add people by hand.',
        'url'   => 'lists.php', 'cta' => 'Create a list',
    ];
    if (!$personal) {
        $items[] = [
            'done'  => $n("SELECT COUNT(*) FROM templates WHERE client_id=? AND status='APPROVED'") > 0,
            'label' => 'Sync your approved templates',
            'help'  => 'WhatsApp needs an approved template for any message you send first.',
            'url'   => 'templates.php', 'cta' => 'Sync templates',
        ];
    }
    $items[] = [
        'done'  => $n("SELECT COUNT(*) FROM campaigns WHERE client_id=?") > 0,
        'label' => 'Send your first campaign',
        'help'  => 'Pick the message, pick the list, send now or schedule it.',
        'url'   => 'campaign_new.php', 'cta' => 'New campaign',
    ];
    $items[] = [
        'done'  => $n("SELECT COUNT(*) FROM flows WHERE client_id=? AND status='active'") > 0,
        'label' => 'Switch on an automation',
        'help'  => 'So a reply gets answered even when nobody is at a desk. Start from a ready-made flow.',
        'url'   => 'automations.php', 'cta' => 'Browse automations',
    ];

    $done = count(array_filter($items, fn($i) => $i['done']));
    return ['done' => $done, 'total' => count($items), 'items' => $items];
}
