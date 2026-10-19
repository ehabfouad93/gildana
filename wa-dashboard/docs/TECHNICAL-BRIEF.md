# Revenect — Technical Brief

> Revenect by Gildana: a multi-tenant sales and messaging platform for WhatsApp, Messenger,
> Instagram (DMs and comments) and SMS, with an AI lead qualifier, an automation builder, an AI
> chat agent and a full sales CRM.
> This brief describes the system as it is on the `main` branch (October 2026): architecture, data, every
> module, integrations, and the UI and visual system. Live at **https://revenect.gildana.net**.

---

## 1. At a glance

| | |
|---|---|
| **Type** | Multi-tenant SaaS web app: one platform, many client workspaces |
| **Language / runtime** | PHP 8.3 (`declare(strict_types=1)`), no framework |
| **Database** | MariaDB 11 (MySQL-compatible), PDO with prepared statements |
| **Front end** | Server-rendered PHP pages + small vanilla-JS islands (no build step, no npm) |
| **Styling** | One hand-written stylesheet `assets/dashboard.css` (~2,000 lines) with CSS custom properties; light/dark; RTL |
| **Background work** | `cron/dispatch.php`, run every 60 s (Docker loop container) |
| **Inbound events** | Webhooks from Meta, SMS providers and the personal-WhatsApp gateway |
| **Hosting** | Docker on an Ubuntu VPS: `revenect` (Apache + PHP), `revenect-cron`, `revenect-db`, optional Evolution API gateway, Traefik for HTTPS |
| **Size** | ~46,000 lines of PHP · 66 SQL migrations · ~85 tables · 19 admin pages · 54 client pages · 60 include modules |
| **Languages** | English and Arabic (RTL), per user |
| **Repository** | `ehabfouad93/gildana` → folder `wa-dashboard/` (the repo also holds the Gildana marketing site, AI Studio and Listening apps) |

---

## 2. Architecture

```
            Browser (admin / client users, PWA on phones)
                          │  HTTPS (Traefik)
                          ▼
 ┌──────────────────────── revenect (Apache + PHP 8.3) ─────────────────────────┐
 │  /login.php  /setup.php           auth, first-run installer                  │
 │  /admin/*.php                     platform administration                     │
 │  /client/*.php                    client workspace (one per tenant)           │
 │  /api.php/v1/...                  REST API (API keys)                         │
 │  /webhook.php                     WhatsApp Cloud API events                   │
 │  /webhook_leads.php               Meta Page/Instagram: lead forms, DMs, comments│
 │  /webhook_sms.php                 SMS delivery reports                        │
 │  /webhook_personal.php            personal-WhatsApp gateway (Evolution API)   │
 │  /meta_oauth.php /google_oauth.php OAuth callbacks                            │
 │  /crm_hook.php                    incoming webhook → CRM lead                 │
 │  includes/*.php                   domain logic (never web-reachable)          │
 └──────────────────────────────────┬───────────────────────────────────────────┘
                                    │ PDO
               ┌────────────────────▼────────────────────┐
               │ revenect-db (MariaDB 11)                │
               └────────────────────▲────────────────────┘
                                    │
 ┌──────────────── revenect-cron: php cron/dispatch.php every 60 s ──────────────┐
 │ campaigns (WA / Messenger / IG) · SMS queue · automations & timers ·          │
 │ qualifier outreach / no-answer sweep / Inbox re-scoring · Facebook/Instagram  │
 │ polling sync · Meta lead-form poll · CRM timers (follow-ups, digests, SLA,    │
 │ reclaim) · push notifications · plan renewals · retention sweep               │
 └───────────────────────────────────────────────────────────────────────────────┘
        │ outbound HTTPS (curl, TLS verified; private networks refused)
        ▼
 Meta Graph API · WhatsApp Cloud API · SMS providers · Google APIs · Claude/OpenAI · Evolution API
```

### Request lifecycle
1. `includes/bootstrap.php` loads config, helpers, crypto, DB; starts a hardened session; sends security headers (`X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, HSTS on HTTPS, CSP in report-only).
2. `admin/_init.php` → `require_admin()`; `client/_init.php` → `require_client()`, resolves `$CLIENT` (or the impersonated client), applies module permissions (`perm_modules()`), blocks writes for viewers.
3. The page handles POSTs first (`verify_csrf()`, then action, `flash()`, `redirect()` — Post/Redirect/Get), or AJAX (`json_out()`), then renders with `client_header()` / `layout_header()` … `layout_footer()`.
4. Output passes through the i18n layer: in Arabic, exact dictionary phrases are swapped and `dir="rtl"` is set.

### Source layout (`wa-dashboard/`)
| Path | Contents |
|---|---|
| `admin/` | Platform admin pages (clients, plans, rates, SMS gateways, reports, SEO, settings…) |
| `client/` | Client workspace pages (inbox, CRM, campaigns, automations, SMS…) |
| `includes/` | Domain modules (see §4); denied over HTTP |
| `migrations/` | `NNN_name.sql`, applied once in order by `migrate()` / `deploy/docker/migrate.php` |
| `cron/` | `dispatch.php` (the worker), `automation.php`; CLI only, denied over HTTP |
| `assets/` | `dashboard.css`, `site.css` (public pages), `viz.js`, icons, brand uploads |
| `deploy/` | Docker compose files, Dockerfile, nginx template, VPS scripts, guides |
| `tools/` | Demo seeder and walkthrough-video recorder (CLI only, denied over HTTP) |
| `uploads/` | Client media (`uploads/<client_id>/`); `inbound/` and `materials/` served only via authenticated scripts |

---

## 3. Data model (main tables)

| Area | Tables |
|---|---|
| Tenancy & users | `clients`, `users`, `user_channels`, `login_attempts`, `plans`, `usage_periods` |
| Credits & billing | `credit_transactions`, `message_charges`, `wa_rates`, `ai_rates`, `ai_usage` |
| Contacts & messaging | `contacts`, `contact_lists`, `contact_list_members`, `messages`, `templates`, `media_cache`, `webhook_events`, `inbound_log` |
| Campaigns | `campaigns`, `campaign_messages` (queue + delivery log), `send_attempts`, `wa_ads` |
| Automations / qualifier / agent | `flows` (`kind`: bot, qualifier, agent), `flow_steps`, `flow_triggers`, `flow_runs`, `flow_step_events`, `flow_messages`, `flow_collected`, `flow_exports`, `flow_templates` |
| CRM | `crm_stages`, `crm_substatuses`, `crm_projects`, `crm_options`, `crm_fields`, `crm_events`, `crm_notes`, `crm_visits`, `crm_rules`, `crm_teams`, `crm_targets`, `crm_transfers`, `crm_requests`, `crm_views`, `crm_settings`, `crm_audit`, `crm_imports`, `crm_import_items`, `crm_materials`, `crm_notices`, `crm_stage_msgs`, `crm_sequences`, `crm_seq_steps`, `crm_seq_runs`, `crm_msg_queue`, `sales_events`, `sales_event_guests` |
| CRM integrations | `crm_api_keys` (hashed, scopes `crm,sms`), `crm_webhooks`, `crm_webhook_deliveries`, `crm_inbound_hooks`, `crm_capi_events` |
| Meta | `meta_pages` (tokens encrypted; switches `leads_on`, `msg_on`, `ig_msg_on`, `comments_on`, `ig_comments_on`; `social_synced_at`, `last_event_at`), `meta_forms`, `meta_lead_log`, `meta_oauth_state`, `meta_ad_accounts`, `meta_ad_spend`, `social_comments`, `social_rules`, `social_optins` |
| SMS | `sms_gateways` (platform or client-owned, config encrypted), `sms_messages`; client columns `sms_mode`, `sms_gateway_id`, `sms_allowed_gateways`, `sms_choice`, `sms_senders`, `sms_rate` |
| Platform | `app_settings` (key/value), `push_subscriptions`, `push_outbox`, `push_outbox_user`, `access_requests`, `support_tickets`, `faq_items`, `google_oauth_state` |

Conventions: every tenant row carries `client_id` (FK `ON DELETE CASCADE`); every query in client code is scoped by it. Columns added by later migrations are written only when present (`db_has_column()`), so a deploy is safe between `git pull` and `migrate`.

---

## 4. Modules

Modules are switched per client by the platform admin (`perm_modules()` in `includes/permissions.php`): `inbox`, `crm`, `contacts`, `lists`, `templates`, `campaigns`, `automations`, `qualifier`, `agents`, `sms`, `reports`, `billing`, plus always-on `team` and `settings`. Channels are separate (`perm_channels()`): `whatsapp`, `messenger`, `instagram`, `fb_comments`, `ig_comments`, `social_mm`.

### 4.1 Dashboard — `client/index.php`
Period-based KPIs vs the previous period (messages, read rate, reply time, credits, campaigns, automations, contacts, leads, SMS), trend charts, channel/source shares, hour × weekday heat map. Data from `includes/stats.php`, rendered with the chart kit (§6.6).

### 4.2 Inbox — `client/inbox.php`, `includes/inbox.php`, `includes/inbox_view.php`, `includes/inbox_media.php`
- Thread list across WhatsApp, Messenger, Instagram (+ SMS lines); channel tabs; search; unread counts; live polling over AJAX.
- Reply on the conversation's channel; 24-hour-window rules per channel; template picker for WhatsApp outside the window.
- Media (images, voice notes, video, files) fetched and cached server-side, served only through `client/media.php` to signed-in users of that account.
- Failure explanation ladder `wa_error_explain()` (`includes/whatsapp.php`) → label, hint, action (`later` = resendable, `fix`, `never`); "Send again" / "Try another template".
- Human takeover pauses bots (`contacts.bot_paused_until`); "Add to CRM".
- Facebook & Instagram connect banner (`social_connect_banner()`).

### 4.3 Comments — `client/comments.php`, `includes/social.php`
Facebook Page and Instagram comments grouped by post; filters; public reply, one private reply, hide, delete, add to CRM. Rules (`social_rules`): **moderate** (phone / link / keywords / AI → hide or delete) and **reply** (keywords → public reply and/or DM). `social_comment_event()` is the single entry for webhook and polling.

### 4.4 Facebook & Instagram connection — `client/social.php`, `includes/social_connect.php`, `includes/social_sync.php`, `includes/meta_leads.php`
- OAuth (`meta_auth_url()` → `meta_oauth.php` → `meta_finish_connect()`): long-lived user token → Page tokens (encrypted), linked Instagram account, granted permissions recorded (`meta_perms_<client>`).
- Three steps: connect → choose your Page (`social_use_page`) → answer from the Inbox. Missing permissions listed in words; a refused switch reverts and shows "Not working".
- `social_page_apply()` subscribes the Page's webhook fields; `meta_explain_error()` turns Meta errors into one sentence.
- **Sync** (`social_sync_page()`): on choosing a Page, imports the last 14 days of conversations (both sides), Page posts + comments, Instagram media + comments/replies, with original timestamps; history is stored quietly (no automations/alerts). `social_sync_due()` re-reads Pages in use every 120 s as a webhook safety net; Meta ids de-duplicate. `last_event_at` shows whether real-time webhooks arrive.
- Admin: status on the client page, "Open their Facebook & Instagram page", one-hour single-use connect link.

### 4.5 Contacts & Lists — `client/contacts.php`, `client/lists.php`, `includes/contacts.php`, `includes/import.php`
Contacts (phone in E.164 via `normalize_phone()`, Messenger PSID / Instagram SID, attributes, tags, opt-in, SMS opt-out). STOP / إلغاء / توقف → opted out. Lists with search-and-add and CSV/Excel import.

### 4.6 Templates — `client/templates.php`, `includes/whatsapp.php`
Sync from the WABA; parse variables, header media, buttons (`wa_template_spec()`); only `APPROVED` templates are sendable.

### 4.7 Campaigns — `client/campaigns.php`, `campaign_new.php`, `campaign_social.php`, `report.php`, `failed.php`, `includes/campaign.php`, `includes/social_campaigns.php`
- WhatsApp: template + variable map + audience (list, CRM filter, file) → one `campaign_messages` row per recipient; schedule; credit check.
- Messenger/Instagram: 24-hour audience or Marketing Messages opt-ins.
- Worker: throttled claim (`send_batch_per_run`, `send_batch_global`, `send_parallel`, media concurrency), credit debit per accepted send, refunds on failure, retries with backoff, `campaign_refresh_counts()`.
- Report: funnel, read curve, replies (3-day window), failure causes with hints (`wa_error_explain` / `sms_error_explain` for SMS), per-recipient table and export.
- Needs attention (`failed.php`): resendable WhatsApp failures only (SMS excluded).

### 4.8 SMS — `client/sms.php`, `admin/sms.php`, `includes/sms.php`, `includes/sms_providers.php`, `includes/sms_forms.php`, `webhook_sms.php`
- Providers behind one call `sms_provider_send()` → `[ok, id, code, error, retry]`: **SMS Misr**, **Victory Link**, **Twilio**, **mShastra** (`ShowError=C`, codes 000–013 mapped by `sms_mshastra_error()`, IP-whitelist message), **Any HTTP provider** (configurable URL, method, body format, auth, field names, success rule, id path). Balance where supported.
- `sms_parts()`: GSM-7 vs UCS-2, 160/153 and 70/67 chars per part.
- `sms_queue()` / `sms_send_now()` / `sms_dispatch()`: opt-out and duplicate skipping, credits = parts × rate, refunds, retries on transient errors, rate per second, logs into the conversation (`messages.channel='sms'`), delivery reports, signed client callbacks + `sms.status` webhook event.
- Gateway choice: platform default (`sms_gateway_id`), gateways the admin allows (`sms_allowed_gateways`), the client Admin's pick (`sms_choice`: platform id or `own:<id>`), client-owned gateways when `sms_mode='own'`. `sms_client_gateway()` resolves with fallbacks. Shared panel `sms_client_panel()` on Settings and SMS → Settings.
- Per-provider setup guides (`sms_provider_guide()`), shown under the gateway form.

### 4.9 Automations — `client/automations.php`, `automation_edit.php`, `automation_report.php`, `automation_data.php`, `includes/automation.php`, `includes/automation_channels.php`
- Visual canvas (vanilla JS) saved as a graph into `flow_steps` (`next_step_id` + per-output config). Autosave.
- Main trigger (`flows.trigger_type`): WhatsApp keyword / welcome / default / ad, Google Sheet, or `channel` = any kind from `auto_trigger_kinds()` stored as the first `flow_triggers` row (Messenger, Instagram incl. story reply/mention, FB/IG comments, Meta lead form, CRM stage). Extra triggers: "Also start this automation when…".
- Steps: text, image, template, buttons, list menu, SMS, comment reply, private reply, question, AI conversation, AI branch, AI score, condition, split test, wait, wait until, jump, set field, add points, tag, add to list, notify, collect, write to Sheet, export (Sheet / CRM / webhook / email), HTTP web service.
- Channel routing per step with a "Not reachable" exit; engine `automation_run_steps()`; runs in `flow_runs` (`status`, `context` JSON, `score`, `grade`).
- `automation_validate()` (dead ends, missing templates, competing live flows, 24-hour risks, missing channel trigger); preview without sending; per-step stats.

### 4.10 Lead Qualifier — `client/qualifiers.php`, `qualifier_edit.php`, `leads.php`
- A `flows` row with `kind='qualifier'`: outreach template → questions → AI conversation → AI score → collect. Sources: Google Sheet, CSV upload, manual.
- Grades: `hot_min` / `warm_min` thresholds → hot / warm / cold; `not_interested`; `no_answer` after `no_answer_hours` with no reply.
- `automation_rescore_from_inbox()`: scores leads from their real Inbox conversation (late replies, human takeover), quietly, 20 per minute; `auto_revive_no_answer()` restarts on a late reply.
- Leads page: live tiles (JSON endpoint polled every 30 s), failure causes with resend, edit, export.

### 4.11 AI Chat Agent — `client/agents.php`, `agent_edit.php`, `agent_chats.php`, `includes/ai.php`
Knowledge-grounded assistant (`ai_chat_reply()`), persona, instructions, goals and captures; WhatsApp/Messenger/Instagram; hands over to people. Providers: Anthropic Claude or OpenAI (client's key, encrypted); usage metered in `ai_usage` and charged by `ai_rates`.

### 4.12 CRM — `client/crm*.php`, `includes/crm*.php`
- **Leads**: pipeline list/cards with chosen columns, saved views (`crm_views`), filters (`crm_list_where()`), bulk actions; lead codes; custom fields; phone masking (`crm_phone_hidden()`); recycle bin; field history (`crm_audit`).
- **Lead page**: activity timeline, conversation, follow-ups, visits, AI summary/fields/reply (`includes/crm_ai.php`), Send WhatsApp / SMS, origin (campaign / ad set / ad).
- **Assignment** (`crm_rules`, `crm_assign_next()`): by project/source/form/unit; round-robin/weighted; SLA timers, reclaim, stale rules; team leaders; transfers with history-hiding options.
- **Automatic messages**: stage messages and sequences with **Send by** WhatsApp / SMS / WhatsApp-then-SMS (`crm_queue()`, `crm_queue_tick()`), CRM variables (`crm_vars`).
- **Salesperson alerts**: in-app bell, push, WhatsApp and/or SMS to the salesperson's own phone (`includes/crm_notify.php`).
- **Calendar**: site visits and online meetings; **Events** with invitations/RSVP; **Materials**; **Import** with mapping, undo and update-from-Excel.
- **Results**: CRM dashboard and a library of 30+ reports (`includes/crm_reports.php`, `crm_dashboard.php`), targets, forecast, ad spend/ROI from Meta Ads (`includes/meta_ads.php`), Conversions API feedback to Meta (`includes/crm_capi.php`).
- **Meta lead forms** (`client/meta_leads.php`): form picker, question → field mapping, routing, poller safety net, lead log.
- **Integrations** (`client/crm_integrations.php`): API keys, outgoing webhooks, incoming webhook URLs (§5).

### 4.13 Reports — `client/reports.php`, `admin/reports.php`, `includes/stats.php`
Tabbed: messaging, campaigns, templates, failures, automations, ad clicks, SMS. Admin: per-client breakdown and platform totals. Platform aggregates exclude deleted clients.

### 4.14 Billing & credits — `client/billing.php`, `includes/credits.php`, `includes/billing.php`
Ledger `credit_transactions`; `credits_adjust()`, `credits_reserve()`/`credits_release()`; plans with monthly included credits (`billing_renew()` in the worker); per-category WhatsApp rates; low-credit alerts.

### 4.15 Team & permissions — `client/team.php`, `includes/permissions.php`
Roles `admin`, `sales`, `viewer` (+ team leader via `crm_teams`); per-user module lists; `can_use()`, `can_write()`, `is_client_admin()`, `crm_scope()` for row-level visibility.

### 4.16 Settings — `client/settings.php`, `client/profile.php`
Sending channel (Cloud API or personal number via QR/pairing code, `includes/personal_wa.php`), WhatsApp credentials with test, CRM switches, AI engine, SMS gateway panel, push notifications, Google Sheets connection. Profile: name, avatar, password, language, theme.

### 4.17 Platform administration — `admin/*.php`
Overview, clients (credentials, modules, channels, Facebook status, SMS card, sending channel, credits/plan, logins, open workspace), campaigns/contacts/templates/inbox views, plans, rates, SMS gateways (+ server public IP for whitelisting), team, access requests (5/hour/IP), help content, SEO (titles, sitemap, robots, llms.txt), settings (branding, Meta app, Google app, personal-WhatsApp gateway, push VAPID keys).

### 4.18 Public pages
`index.php` (landing), `request.php` ("Get started"), `help-center.php`, `robots.php`, `sitemap.php`, `llms.php`; styled by `assets/site.css`.

---

## 5. Integrations & interfaces

### 5.1 Inbound endpoints
| Endpoint | Source | Authentication |
|---|---|---|
| `webhook.php` | WhatsApp Cloud API (statuses, messages, referrals) | `hub_verify_token` handshake; `X-Hub-Signature-256` HMAC with the client's App Secret (or platform `app_secret`); unsigned optionally refused per client |
| `webhook_leads.php` | Meta Page (`leadgen`, `messages`, `messaging_*`, `feed`) and Instagram (`messages`, `comments`) | verify token; HMAC with the platform Meta App Secret |
| `webhook_sms.php?g=&t=` | SMS delivery reports | per-gateway secret token |
| `webhook_personal.php?k=` | Evolution API (personal WhatsApp) | per-client secret (rotatable, previous accepted during rotation) |
| `crm_hook.php?t=` | Any external form/system → CRM lead | token (48 hex), stored hashed |
| `meta_oauth.php`, `google_oauth.php` | OAuth callbacks | single-use `state` (1 hour) |

### 5.2 REST API — `api.php/v1`
`Authorization: Bearer <key>` or `X-Api-Key`; keys hashed (`crm_api_keys`), access `read`/`write`, scopes `crm`, `sms`; per-key rate limit (`api_rate_per_minute`). JSON `{data, meta}` / `{error:{status,message}}`.

| Method & path | Purpose |
|---|---|
| `GET /me` | Account and key |
| `GET /stages`, `/users`, `/projects`, `/fields` | Reference data |
| `GET /leads`, `POST /leads` | List (filters, paging ≤ 500) / create (auto-assigned) |
| `GET|PATCH|PUT /leads/{id \| code \| ext:<id>}` | Read / update |
| `GET|POST /leads/{ref}/activities` | Activities |
| `POST /sms` | Send to 1–1000 numbers; `sender`, `schedule_at`, `reference` (idempotent 24 h), `callback_url` |
| `GET /sms`, `GET /sms/{id}` | List / status |
| `GET /sms/balance`, `GET /sms/senders` | Credits & rate / allowed senders |

### 5.3 Outgoing webhooks
Events: `lead.created`, `lead.updated`, `lead.stage_changed`, `lead.won`, `lead.lost`, `lead.assigned`, `lead.deleted`, `activity.logged`, `visit.booked`, `sms.status`. Signed `X-Revenect-Signature` (HMAC-SHA256 of the body); retries logged in `crm_webhook_deliveries`; private-network targets refused (`safe_http_request()`).

### 5.4 External services
Meta Graph API (version `graph_version`), WhatsApp Cloud API, Meta Marketing API (ad spend), Conversions API, Google Sheets/Drive, Anthropic / OpenAI, SMS providers, Evolution API, Web Push (VAPID).

---

## 6. UI and visual system

### 6.1 Principles
- Server-rendered pages, progressive enhancement: everything works with plain forms (Post/Redirect/Get); JS adds live updates (Inbox polling, autosave, live tiles), dialogs and the automation canvas.
- No front-end framework or bundler; each page carries its own small `<script>`; shared code in `assets/viz.js` and inline helpers.
- Plain-language copy written from the user's side ("Not arriving yet", "Choose your Page", "Facebook refused: …"), errors that say what to do.
- One stylesheet, one set of tokens, every component reads tokens — light/dark and RTL come for free.

### 6.2 Layout shell (`includes/view.php`)
- **Top bar** (58 px, `--topbar-h`): brand logo (`brand_logo()`, `includes/brand.php`), CLIENT/ADMIN badge, credits pill, notifications bell (unread count), language toggle (ع/EN), theme toggle, avatar + name, Logout.
- **Sidebar** (232 px, `--sidebar-w`): "Search the menu" with `Ctrl K`, favourites, module items with icons (`nav_items()`, `nav_icon()`), collapsible CRM group (Leads & work / Results / Administration), attention counts. On phones it becomes an off-canvas drawer plus a **bottom tab bar** (Dashboard, Inbox, CRM, Campaigns).
- **Page head** (`page_head($title, $actions)`): H1, ☆ favourite, "How to use" help button (guides + videos, `includes/guide.php`), action buttons on the right.
- **Impersonation bar** when an admin manages a client ("Managing GILDANA as admin… Exit to admin").
- Flash messages (`flash()` / `take_flashes()`), PWA install prompt, help floating button.

### 6.3 Design tokens (`:root` in `assets/dashboard.css`)

| Token | Light | Dark | Use |
|---|---|---|---|
| `--brand` | `#7C3AED` (violet) | `#7C3AED` | Primary actions, active state, links |
| `--brand-soft` | `#9366F2` | — | Hover |
| `--brand-tint` | `rgba(124,58,237,.10)` | `rgba(147,102,242,.22)` | Active/hover washes |
| `--brand-2` | `#2563EB` (blue) | — | Secondary |
| `--accent` | `#06B6D4` (cyan) | — | Highlights (the "E" in the logo) |
| `--brand-grad` | violet → blue → cyan, 135° | — | Brand moments |
| `--ink` | `#0D1321` | `#EEF1F7` | Headings, dark surfaces |
| `--text` | `#1F2937` | `#D6DCE6` | Body copy |
| `--slate` | `#475569` | `#A7B1C2` | Secondary copy |
| `--bg` | `#F3F4F6` | `#0E131C` | Page background |
| `--surface` / `--surface-2` | `#FFFFFF` / `#F6F7FA` | `#171D29` / `#1D2432` | Cards / inset areas |
| `--line` / `--line-strong` | ink at 10% / 18% | same formula on dark ink | Borders |
| `--muted` | ink at 55% | — | Hints, meta text |
| `--chip` | `#EEF0F5` | `#232B3A` | Pills, chips |
| `--chat-bg`, `--bubble-in`, `--bubble-out` | `#F4EFE9`, `#FFF`, `#D9FDD3` | `#10151E`, `#1F2735`, `#134D37` | WhatsApp-style chat |
| `--danger` / `--success` / `--info` (+ `-bg`) | `#B91C1C` / `#059669` / `#2563EB` | `#F87171` / `#34D399` / `#60A5FA` | Status |
| `--tint-warn/red/orange/blue/green` (+ `-ink`) | soft tints | dark tints | Alerts, pills |
| `--wa` | `#25D366` | — | Means WhatsApp only |
| `--radius` | `12px` | — | Cards, inputs |
| `--sans` | "Helvetica Neue", Helvetica, Arial | — | UI font |

- **Typography**: system Helvetica/Arial stack (fast, no web-font download); Arabic uses "Noto Sans Arabic", "Segoe UI", Tahoma. Monospace `ui-monospace, SFMono-Regular, Menlo, Consolas` for codes, phones and keys; tabular numbers in tables and KPIs. Labels and table headers are small uppercase with letter-spacing.
- **Theming**: light by default; dark via `prefers-color-scheme` when the user's theme is Automatic, or forced with `data-theme="dark"/"light"` (Profile or the top-bar toggle). Only tokens change between themes; components never hard-code colours.
- **RTL**: `dir="rtl"` from `ui_rtl()`; layout uses logical properties (`margin-inline-start`, `inset-inline-end`, `text-align: end`) so the whole UI mirrors.

### 6.4 Components (class names)
| Component | Classes | Notes |
|---|---|---|
| Buttons | `.btn`, `.btn-primary`, `.btn-ghost`, `.btn-dark`, `.btn-sm`, `.btn-link` | Primary = violet fill; ghost = outline |
| Cards | `.card`, `.card-flush` | White surface, 1px line, 12px radius |
| Stat tiles | `.stat-tile` (`.lbl`, `.val`, `.sub`, `.on`) | Clickable tiles act as filters |
| KPI tiles | `.viz-kpi` | Value, delta vs previous period, sparkline |
| Pills | `.pill` + `green`/`red`/`gold`/`blue`/`gray` | Status, grades, channels |
| Alerts | `.alert` + `success`/`error`/`info` | Flash and inline notices |
| Tables | `table.data` inside `.table-wrap` | Horizontal scroll on small screens |
| Forms | `.field`, `.lbl`, `.hint`, `.grid2`, `.grid3`, `.mod-grid`, `.mod-opt` | Grids collapse to one column under 720 px |
| Tabs | `.dv-tabs` | Page sections (SMS, Reports, Comments) |
| Modals | `.modal-back` + `.modal`, native `<dialog>` | Add lead, upload, resend |
| Steps | `.sc-steps` | Numbered progress (Facebook & Instagram setup) |
| Banners | `.social-banner` | Next-action strip on Inbox/Comments |
| Chat | `.ib-wrap`, `.ib-list`, `.ib-th`, `.ib-b ib-in/ib-out`, `.ib-media` | WhatsApp-like bubbles |
| Choice cards | `.sms-gw-pick`, `.sms-gw-opt` | Radio cards (SMS gateway) |
| Guides | `details.sms-guide`, `.sc-live-help` | Collapsible how-to |
| Empty states | `.empty` | Always say what to do next |

### 6.5 Responsive behaviour
Breakpoints at 900, 760, 720 and 520 px: sidebar → drawer + bottom bar; grids → one column; tables scroll inside `.table-wrap`; Inbox switches between list and chat panes; dialogs go full-width. 16 px side gutter; no horizontal page scroll (checked in UI tests).

### 6.6 Charts kit (`includes/charts.php`, `assets/viz.js`)
- Server-rendered SVG/HTML: `viz_kpi`, `viz_trend` (lines or columns, stacked), `viz_bars`, `viz_funnel`, `viz_share` (100% bar), `viz_heat` (hour × day), `viz_card`, period bar `viz_period_bar`.
- Every chart has a "Show as a table" view; `viz.js` adds tooltips, crosshair and keyboard navigation.
- Categorical palette `--viz-1…8` (blue, orange, green, amber, pink, dark green, indigo, red), validated for colour-vision deficiency, with separate dark-mode steps; `--viz-bad` for failures; sequential ramps for heat maps and funnels. One y-axis per chart.

### 6.7 Accessibility
Visible focus states, `aria-pressed` on toggle buttons, `aria-label` on icon buttons, radio groups for choice cards, `role="status"` for live banners, reduced-motion respected, contrast-checked tokens in both themes.

### 6.8 PWA
`manifest.webmanifest`, icons (32–512, maskable), `sw.js` (static assets cache-first, HTML never cached, offline page), Web Push for new replies and new leads.

---

## 7. Background worker — `cron/dispatch.php`
Runs every minute (`revenect-cron` loop, or system cron), CLI only, with DB locks so runs never overlap:
1. Promote scheduled campaigns; claim and send WhatsApp batches; social campaigns; refresh counts; completion/low-credit notices.
2. SMS: promote scheduled, `sms_dispatch()`.
3. Automations (`wa_automation` lock): `automation_tick()` (timers), Google Sheet ingestion, campaign follow-ups, qualifier outreach, no-answer sweep, Inbox re-scoring, Facebook/Instagram sync, Meta lead-form poll, CRM timers (`crm_auto_tick()`), push dispatch.
4. Personal-number pacing slots; plan renewals; retention sweep (only `webhook_events`, `inbound_log` — client data is never deleted).
5. Heartbeat file `cron/.heartbeat`.

---

## 8. Configuration — `config.php` (never committed, denied over HTTP)
`db`, `encryption_key` (AES key for every stored secret — never change), `webhook_verify_token`, `app_secret`, `graph_version`, `send_batch_per_run`, `send_batch_global`, `send_parallel`, `send_parallel_media`, `slot_gap_min_ms`, `slot_gap_max_ms`, `no_answer_hours`, `rescore_per_pass`, `social_sync_seconds`, `api_rate_per_minute`, `captcha_enabled`, `app_name`, `session_name`, `admin_email`, `base_url`; test/rig overrides `graph_host`, `fb_dialog_host`, `google_base`, `ai_*_base`, `webhook_allow_private`. Platform settings editable in Admin live in `app_settings` (Meta app, Google app, gateway, VAPID, branding, `sms_public_ip`). Docker secrets in `deploy/docker/.env`.

---

## 9. Security model
- Tenant isolation by `client_id` on every query; role and module checks on every page and API call; viewers blocked from all POSTs.
- Passwords bcrypt; login lockout + CAPTCHA; session cookie HttpOnly, SameSite=Lax, Secure on HTTPS; session id regenerated on login.
- CSRF token on every state-changing form; all SQL parameterised; output escaped (`e()` in PHP, escaping helpers in JS; user-supplied names never placed in inline handlers).
- Secrets encrypted at rest (`encrypt_secret()`); API keys, webhook tokens and connect tokens stored as hashes; secrets never echoed back ("••• set").
- Webhooks verified by HMAC or per-endpoint secrets; OAuth `state` single-use.
- Outbound requests through `safe_http_request()` (no private networks), TLS verified.
- `.htaccess` / nginx denies for `config.php`, `includes/`, `migrations/`, `deploy/`, `tools/`, `cron/`, dotfiles; uploads cannot execute scripts; inbound media and materials served only via authenticated scripts.
- Audit: phone reveals, exports, field changes (`crm_audit`).

---

## 10. Deployment & operations
```bash
# update (server follows main)
cd /opt/gildana && git pull origin main
docker exec revenect php deploy/docker/migrate.php

# health
docker ps --format 'table {{.Names}}\t{{.Status}}'
ls -l /opt/gildana/wa-dashboard/cron/.heartbeat; date
docker logs --tail 50 revenect-cron
```
Migrations are idempotent and recorded; every feature tolerates its migration not being applied yet. Testing: PHP and Playwright (browser) test scripts run against a local copy of the stack with a mock Meta Graph / SMS / AI server — 70+ suites covering campaigns, inbox, CRM, automations, qualifier, SMS, social and security. The suites are kept outside this repository.

---

## 11. Glossary
**WABA** WhatsApp Business Account · **Template** pre-approved WhatsApp message · **24-hour window** free-reply period after a customer's last message · **Credits** prepaid units · **SMS part** 160 GSM / 70 Unicode characters · **Flow / run** an automation and one person's pass through it · **Grade** qualifier verdict · **PSID / IGSID** a person's Messenger / Instagram id for a Page · **Verify token** shared secret for Meta webhook setup.
