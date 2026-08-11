# Gildana WhatsApp Dashboard — Project Handoff

> Paste this file into a new Claude chat to continue work with full context.
> Last updated: 2026-07-30.

## ⏱️ CURRENT STATE — read this first

Everything is deployed and **Health Check is all green** (WhatsApp, credits, AI=openai,
webhook, cron, chatbots, qualifiers). Lead import + outreach template send both work.

**The ONE open problem we're debugging right now:**
- Test flow: import a lead → the **"Hello World" template arrives** on the lead's WhatsApp ✅
  → the user **replies** to it → but **the AI does NOT respond** ❌.
- The engine + `webhook.php` code is verified correct (reply on a `template`-step run
  advances to `next_step_id` and sends the first question). So the failure is almost
  certainly that **the lead's inbound reply is not reaching `webhook.php`** — i.e. Meta is
  not delivering inbound `messages` to the webhook for that phone number.
- **Next diagnostic step (was in progress):** right after replying on WhatsApp, open
  **Health Check** and look at the **"Webhook receiving"** line:
  - If it changes to **"just now"** → the reply IS arriving; bug is deeper in handling
    (look at `flow_runs` for that contact, `automation_handle_inbound`, and whether
    `wa_send_text` for the first question failed / 24h window).
  - If it stays the **old stale time** → the inbound is NOT arriving → fix on Meta side:
    the WABA/phone number must be **subscribed to the `messages` webhook field**, and the
    app's webhook must be subscribed to that specific phone number. (Note: in WhatsApp
    Cloud API the single `messages` field covers BOTH inbound messages and delivery
    statuses — so if only statuses arrive, re-check the subscription.)
- The user said "اصبر هجرب تانى" (wait, I'll try again) — they were re-testing when this
  file was requested.

**Root causes already fixed this session (see §6):** Google Sheet `/edit` URL not being a
CSV (auto-converted now), private sheet (clear error now), and phone numbers mangled by a
wrong **Default Country Code** (`2` instead of `20`).

---

## 1. What this is

A **standalone, multi-tenant WhatsApp campaign + automation dashboard** for **Gildana**
(a marketing agency). Gildana's clients log in and self-serve:

- **Bulk WhatsApp campaigns** via the official **Meta Cloud API** (template sends).
- **Automations / chatbots** (ManyChat-style): reply to inbound messages with a
  drag-and-drop flow (text / image / buttons / AI branch / questions / tags…).
- **Lead Qualifier** (separate module): pull leads from a **Google Sheet (CSV)**, send an
  approved outreach **template**, then an **AI** asks qualifying questions and scores each
  lead **hot / warm / cold**, exportable to CSV/Excel.

It replaces the manual n8n / ManyChat workflows Gildana ran by hand. The **admin/super-admin**
is fully separate from the public Gildana marketing site.

- **Live host:** cPanel shared hosting, domain **`app.gildana.net`**.
- **Local dev:** XAMPP on Windows (`C:\xampp`), PHP at `C:/xampp/php/php.exe`,
  MySQL at `C:/xampp/mysql/bin`, DB name **`wa_dashboard`** (root, no password).
- **Deploy flow:** the user uploads a **ZIP** to cPanel (they explicitly want to keep the
  ZIP flow — **no git**). `config.php` is NEVER shipped (it holds live secrets).

## 2. Project location

- Repo/app root (local): `C:\Users\BS\Downloads\gildana2\Gildana (1)\`
- The app itself lives in: **`wa-dashboard/`**
- This repo is **not** a git repository.

## 3. Tech stack & conventions

- **PHP 8 target**, but shared host may run **PHP 7.4** → keep polyfills for
  `str_starts_with` / `str_ends_with` / `str_contains` (in `includes/helpers.php`, guarded
  with `function_exists`). Don't use PHP 8-only syntax that 7.4 can't parse.
- **MySQL/MariaDB via PDO**. File-based migration runner; applied migrations tracked in
  `schema_migrations`. Migrations live in `wa-dashboard/migrations/*.sql`, run via the
  installer/`setup.php` or a migrate script.
- **AES-256-GCM** encryption (`includes/crypto.php`) for all secrets (WhatsApp tokens,
  AI keys). `encrypt_secret()` / `decrypt_secret()` use `app_secret` from `config.php`.
- **Per-page CSRF** (`csrf_field()`, `verify_csrf()`, `csrf_token()`), hardened sessions.
- **CAPTCHA** on login (toggle `captcha_enabled` in config).
- **Cache-busting**: CSS links use `?v=<filemtime>` (set in `includes/view.php`,
  `index.php`, `setup.php`). If styling looks stale on live, it's LiteSpeed/browser cache →
  hard refresh / flush.
- **WhatsApp Cloud API**: Meta Graph **v21.0**. Template send, interactive buttons,
  text/image. Webhook handles delivery statuses + inbound. **24h customer-service window**:
  free-form (text/image/buttons) only within 24h of the contact's last inbound; cold
  outreach must be a **template**.
- **AI abstraction** (`includes/ai.php`): Claude Messages API (`x-api-key`,
  `anthropic-version: 2023-06-01`) and OpenAI chat/completions (`Bearer`), raw cURL,
  per-client encrypted key. Defaults: `AI_DEFAULT_CLAUDE_MODEL='claude-opus-4-8'`,
  `AI_DEFAULT_OPENAI_MODEL='gpt-4o-mini'`.

## 4. Key files (in `wa-dashboard/`)

- `config.php` — LOCAL ONLY, secrets, **not shipped**. `config.sample.php` is the template.
  Keys: DB creds, `app_secret`, `captcha_enabled`, `send_batch_per_run=300`,
  `send_batch_global=1000`, `send_parallel=30`.
- `includes/helpers.php` — polyfills; `trigger_worker()` (fire-and-forget ~400ms curl to
  `cron/dispatch.php` for instant sending); `csv_cell()`; `normalize_phone()`.
- `includes/crypto.php` — AES-256-GCM secret encryption.
- `includes/whatsapp.php` — `wa_request()`, `wa_send_template()`,
  `wa_send_template_batch()` (parallel `curl_multi`), `wa_send_text/image/buttons`,
  `wa_fetch_templates()`.
- `includes/ai.php` — `ai_config`, `ai_complete`, `ai_classify`, `ai_score_reply`,
  `ai_test_key`.
- `includes/automation.php` — **the automation/qualifier engine**. Key functions:
  - `automation_ingest_flow(array $client, array $flow, int $maxRows=500): array`
    → `['imported','skipped','rows','error']`. Fetches the sheet CSV via `auto_fetch_url`,
    updates `source_config.last_fetched_at`, parses, dedupes on existing contact, inserts
    contact (source `'sheet'`), calls `automation_enqueue_lead`.
  - `automation_ingest_sheets(int $maxRowsPerFlow=500): int` — cron loop over active
    google_sheet/qualifier flows, respects interval, calls `automation_ingest_flow`.
  - `automation_run_steps()`, `automation_handle_inbound()`, `automation_tick()`,
    `auto_start()`, `auto_collect()`.
  - A `template` step sets the run to `waiting_input` (pauses for the lead's reply after
    cold outreach — the AI convo begins only when they reply).
- `includes/view.php` — layout, client nav: dashboard, contacts, lists, templates,
  campaigns, **automations** (icon `bot`), **Lead Qualifier** (icon `target`), reports,
  settings. CSS cache-bust here.
- `webhook.php` — Meta webhook. GET verify (verify token) + POST (statuses + inbound →
  `automation_handle_inbound`). Logs to `webhook_events`.
- `cron/dispatch.php` — **unified worker**. Reclaims stale `'sending'` >5min → `queued`;
  parallel campaign send in chunks of `send_parallel`; reserves/refunds credits; then runs
  automations under MySQL `GET_LOCK('wa_automation')`; `@touch(cron/.heartbeat)`. Web mode
  uses `ignore_user_abort(true); @set_time_limit(0)`.
- `client/campaigns.php`, `client/campaign_new.php` — campaign create/send; call
  `trigger_worker()` on send-now.
- `client/automations.php` + `client/automation_edit.php` — chatbot list + **drag-and-drop
  canvas builder** (`kind='bot'`). Canvas: nodes with `pos_x/pos_y`, input/output ports,
  SVG bezier edges, config side-panel, Start(trigger) node; serialize to JSON → server
  rebuilds `flow_steps` resolving tempid→db id.
- `client/qualifiers.php` + `client/qualifier_edit.php` — **Lead Qualifier module**
  (`kind='qualifier'`). Simple dedicated form (Sheet URL + column map + questions +
  thresholds) that compiles a linear flow (template outreach → questions → ai_score →
  collect).
- `client/leads.php` — Lead Qualifier leads dashboard: stats (hot/warm/cold/chatting),
  filterable table, transcript viewer, CSV export, and a **"Import now"** manual trigger
  (see §6).
- `client/diagnostics.php` — **Health Check** page. 8 checks (WhatsApp creds, credits, AI
  key, webhook receiving, cron heartbeat, chatbot validity, qualifier validity, recent
  activity) + live AJAX Test WhatsApp / Test AI buttons.
- Admin side: super-admin can add/edit clients, credentials, credits, on/off toggle,
  multiple/editable logins, delete, per-account usage, credit packages; Team (admin users),
  Settings, "Open Workspace" impersonation, global cross-client views.

## 5. Data model (main tables)

- `clients` — WhatsApp creds (`access_token_enc`, `phone_number_id` nullable+unique,
  `waba_id`, `app_id`), `credits_balance`, `ai_provider`, `ai_api_key_enc`, `ai_model`,
  `status` (on/off), etc.
- `contacts` — `phone_e164`, `name`, `source`, `last_inbound_at`, `tags`.
- `flows` — `kind` (`bot` | `qualifier`), `status` (draft|active|paused), `trigger_type`,
  `source_config` JSON (csv_url, column_map, fetch_interval_min, last_fetched_at),
  `first_step_id`, `hot_min`, `warm_min`.
- `flow_steps` — `type`, `config` JSON, `next_step_id`, `pos_x`, `pos_y` (canvas).
- `flow_runs` — per contact per flow; `status`
  (active|waiting_input|waiting_timer|completed|blocked|stopped), `score`, `grade`,
  `context` JSON (fields + transcript).
- `flow_messages` — send/delivery log. `flow_collected` — exportable lead rows.
- `campaigns`, `campaign_messages`, `webhook_events`, `schema_migrations`.
- Migrations of note: `005_automations.sql`, `006_flow_canvas_kind.sql`
  (adds `flows.kind`, `flow_steps.pos_x/pos_y`; migrates google_sheet→qualifier).

## 6. Most recent work (this session)

**Added a manual "Import now" button to the Lead Qualifier** (`client/leads.php`), because
the user reported leads "not importing / not starting chat" on live and asked for a
trigger/refresh button.

- Refactored `automation.php` to extract `automation_ingest_flow()` (single-flow ingest
  returning counts) out of `automation_ingest_sheets()`.
- `client/leads.php`: added requires (ai/notify/automation), an `import_now` POST handler
  that calls `automation_ingest_flow($CLIENT, $flow)` and flashes
  `Imported N new lead(s) · M skipped … of R rows. Outreach is sending now.`, and an
  "Import now" button in the page actions.
- **Verified locally**: 2 imported / 1 skipped (invalid phone) / 3 rows; re-run dedupes to
  0 new / 3 skipped; runs created. All PHP lints clean.
  (Runs show `blocked` locally only because there are no real WhatsApp creds in dev — that's
  expected; on live with creds they send.)

### 6b. Fixes shipped in the 2026-07-30 debugging session

All in `includes/automation.php` (repackaged into `wa-dashboard-update-20260729.zip`):

1. **Google Sheet URL auto-conversion** — new `auto_normalize_sheet_url()`. Users kept
   pasting the `…/spreadsheets/d/<ID>/edit?usp=sharing` link, which returns HTML not CSV.
   Now any Sheets link is converted to `…/export?format=csv&gid=<gid>` before fetching.
   Publish-to-web (`/d/e/…`) and existing `output=csv`/`format=csv` links are left as-is.
2. **Private-sheet detection** — if Google returns an HTML page (sheet not shared), the
   ingest now returns a clear error: *"The sheet isn't shared publicly. In Google Sheets →
   Share → set 'Anyone with the link' to Viewer…"* instead of a confusing "0 imported".
   (Regex checks for a leading `<!doctype/html/?xml`, BOM-tolerant.)
3. **Phone normalization root cause** — leads imported as `+1203143103` / `+21022627976`
   because the client's **Default Country Code** was empty or set to `2`. `normalize_phone`
   already supports a default country; the fix was **operational**: the field
   (Admin → client → **Default Country Code**) must be exactly **`20`** for Egypt (not `2`,
   not `+20` works too but `2` breaks it). Canonical sheet format that works even with an
   empty country: `20` + 10-digit number, e.g. `201022627976`.

**Verified locally**: normalizer converts edit→csv correctly; HTML detection fires on the
real (404, private) Google response and does NOT false-positive on real CSV; the
country-code table shows `2`→broken, `20`→correct. All lints clean.

## 7. Known open issues / user-side pending

1. **Webhook not "green" on live.** The user's Meta webhook was failing because:
   (a) they pasted the URL into the **Verify token** field by mistake, and
   (b) the Callback URL had a `//` double slash.
   Correct config:
   - **Callback URL:** `https://app.gildana.net/webhook.php` (single slash)
   - **Verify token:** `gildana_wa_verify_2026`
   - Must **subscribe to the `messages` field**, and a **real inbound message** must arrive
     before Health Check turns the webhook check green.
   👉 **The AI conversation ("start chat") only begins when a lead REPLIES, which REQUIRES
   the webhook working.** Import + outreach template send work without it; the AI Q&A does not.
2. **Cron** is set to every **5 minutes** (the user's host minimum). That's fine — real-time
   paths are webhook + `trigger_worker()` driven; the cron is the fallback for sheet ingest
   and timer waits.
3. **AI key**: user set provider `openai`; Health Check later showed AI OK (likely done).
4. **Cosmetic**: `diagnostics.php` webhook fix-hint can print `//webhook.php` (double slash)
   via `dirname(dirname($_SERVER['SCRIPT_NAME']))`. Harmless; not yet fixed.

## 8. How to run / test locally (XAMPP, Windows)

```bash
# start MySQL if down
powershell.exe -Command "Start-Process 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden"

# serve the app
cd "C:/Users/BS/Downloads/gildana2/Gildana (1)/wa-dashboard"
"C:/xampp/php/php.exe" -d extension=gd -S 127.0.0.1:8099

# lint a file
"C:/xampp/php/php.exe" -l includes/automation.php
```

- Local test client login: `acme@example.com` / `acmepass123`.
- MySQL is idle-stopped often; restart it before DB work.
- PHP's built-in server is **single-threaded** — a page that fetches its own URL (e.g. the
  qualifier fetching a CSV served by the same server) will deadlock. Serve test CSVs from a
  **second** `php -S` on a different port when testing ingest locally.

## 9. Packaging an update ZIP for the client

Zip the `wa-dashboard/` contents **excluding `config.php`** (and dev artifacts). The client
uploads it to cPanel over the existing install; `config.php` stays untouched. Then run any
new migrations via the setup/migrate path. Remind them to hard-refresh (LiteSpeed cache).

## 10. User preferences / working style (important)

- Admin must be **separate**, not linked to the Gildana marketing site.
- Wants the **canvas drag-and-drop** builder (overrode an earlier step-list design).
- Wants Lead Qualifier as its **own module** with a **simpler dedicated form**.
- **Keep the ZIP deploy flow** — declined git.
- Accepts the **5-minute cron** (host limit).
- Campaigns must send **fast / real-time** (done via parallel curl_multi + instant trigger).
- The user communicates in a mix of English and Arabic; keep replies clear and practical.
- Verify changes end-to-end (curl + DB round-trips) before declaring done.

## 11. Suggested next steps

1. Get the user's **webhook green** (subscribe `messages`, send a real inbound test).
2. Confirm the AI key test passes on live.
3. Ship the current update ZIP (adds "Import now" + the ingest refactor).
4. Optional: fix the cosmetic `//webhook.php` double-slash in `diagnostics.php`.
