# AI Studio — Creative Orchestrator

A **standalone** web app where you build a marketing campaign and, **for each creative
step, choose which AI does the work — and see why**. Pick ChatGPT or Claude for copy,
GPT Image / Stable Diffusion / Claude for design, Kling for video — then **generate
the assets and download them or publish straight to Facebook & Instagram**.

Stack: **PHP 8 + MySQL** (PDO, no framework), raw cURL to each provider, encrypted key
vault (AES-256-GCM), async video via a cron worker. Same deployment model as the
Gildana WhatsApp dashboard; fully independent of it (own login, own database).

---

## How it works

1. **Provider Keys** (admin) — paste your API keys once. They're encrypted at rest and
   never shown again. Each has a **Test connection** button.
2. **New Campaign** — fill a short brief (product, audience, goal, tone, CTA, language),
   then for each stage choose a module. Every option shows a **"why choose this"** blurb,
   what it's **best for**, and a model selector. Un-keyed providers are locked with a hint.
3. **Campaign workspace** — click **Generate** per stage. Copy/design/image return
   instantly; **video renders in the background** (Kling) and appears when ready.
4. **Download or Publish** — every finished asset has **Download**, and images/videos get
   **Post → Facebook** / **Post → Instagram** buttons (caption auto-filled from your
   generated copy, editable at publish time).
5. **Asset Library** — everything you've made, filterable by type.

### Stages & modules
| Stage | Modules | Notes |
|-------|---------|-------|
| Content / Copy | **ChatGPT** (OpenAI), **Claude** (Anthropic) | headlines, captions, hashtags, CTA |
| Design / Image | **GPT Image** (OpenAI), **Stable Diffusion** (Stability), **Claude Design** | Claude Design returns an *editable* HTML/SVG poster |
| Video | **Kling** (Kuaishou) | async text-to-video, finalized by the cron worker |
| Publish | **Facebook Page**, **Instagram** | Meta Graph API |

---

## Requirements
- PHP 8.0+ with `pdo_mysql`, `curl`, `openssl`, `mbstring`
- MySQL / MariaDB
- A cron job (or an uptime pinger) once a minute — **only needed if you use video**
- A **public HTTPS base URL** — required for Instagram publishing (Meta fetches the
  generated media by URL) and for building download links

## Install
1. Copy the `ai-studio/` folder onto your host (e.g. `studio.gildana.net`).
2. Create a database, then copy config:
   ```
   cp config.sample.php config.php
   ```
3. Edit **config.php**:
   - `db` → your MySQL host/name/user/pass
   - `encryption_key` → `php -r "echo base64_encode(random_bytes(32));"`
   - `base_url` → your public URL, e.g. `https://studio.gildana.net` (no trailing slash)
   - `worker_token` → any random string (guards the cron endpoint)
4. Open the site → it redirects to **`setup.php`**, runs the migrations and creates your
   first **admin** account.
5. Sign in → **Provider Keys** → add the keys for the providers you want → **Test connection**.

## The video worker (only if you use video)
Kling generation is asynchronous. Finalize jobs with a per-minute cron:
```
* * * * * php /path/to/ai-studio/cron/worker.php >/dev/null 2>&1
```
No CLI cron? Hit it from an uptime pinger:
```
https://YOUR-DOMAIN/ai-studio/cron/worker.php?token=<worker_token>
```
(The campaign screen also polls and finalizes its own videos while it's open, so the
cron is a safety net for when nobody's watching.)

## Provider keys — where to get them
- **OpenAI** — platform.openai.com → API keys (`sk-...`). Powers ChatGPT + GPT Image.
- **Anthropic** — console.anthropic.com (`sk-ant-...`). Powers Claude + Claude Design.
- **Stability** — platform.stability.ai (`sk-...`). Powers Stable Diffusion.
- **Kling** — the Kling AI open platform: an **Access Key** + **Secret Key** (used to
  sign a JWT per request) and the regional **API base URL**.
- **Meta** — a long-lived **Page access token** with `pages_manage_posts` +
  `instagram_content_publish`, plus your **Facebook Page ID** and **Instagram User ID**.

## Local development (XAMPP)
```
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE ai_studio"
C:\xampp\php\php.exe -S 127.0.0.1:8100    # from the ai-studio folder
```
Open http://127.0.0.1:8100 → setup → add keys → build a campaign.
Note: Instagram publishing won't work from `localhost` (Meta can't fetch the media);
set a public `base_url` for that.

## Layout
```
index.php / setup.php / logout.php     Login, first-run setup
app/       index, studio (wizard), campaign, library, keys, team, + generate/poll/publish/download endpoints
includes/  bootstrap, config, db, crypto, auth, helpers, view, render,
           providers.php (the module registry + "why"), adapters.php (real provider HTTP), generate.php (orchestrator)
cron/worker.php                        Finalizes async video jobs
migrations/001_init.sql                Schema
storage/                               Generated media (web-served, no code execution)
```

## Security notes
- Provider keys are encrypted with AES-256-GCM (`encryption_key`); the DB only holds ciphertext.
- `config.php`, `includes/`, `migrations/` are blocked from the web via `.htaccess`
  (add equivalent `location` denies on nginx).
- `storage/` serves media but has code execution disabled.
- Login is CSRF-protected and rate-limited (5 fails / 15 min lockout).
