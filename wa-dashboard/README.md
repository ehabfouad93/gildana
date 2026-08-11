# Gildana — WhatsApp Campaign Dashboard

A **standalone** multi-tenant dashboard where your clients self-serve bulk WhatsApp
campaigns through the **official WhatsApp Cloud API (Meta)**. Each client sends from
**their own number**; you onboard them by pasting their API credentials. It is
independent of the Gildana marketing site (own login, own database, own code).

Stack: **PHP 8 + MySQL** (PDO), no framework. Sending is a **cron-driven throttled
queue**; delivery status + opt-out come from a **Meta webhook**.

---

## 1. Requirements
- PHP 8.0+ with `pdo_mysql`, `curl`, `openssl`, `mbstring`, and `gd` (for the login CAPTCHA)
  - If `gd` is unavailable the login CAPTCHA auto-disables (login still works). Enable it in `php.ini` (`extension=gd`) to use it, or set `captcha_enabled => false` in `config.php`.
- MySQL / MariaDB
- Ability to run a **cron job** (or a scheduled URL hit) once per minute
- A public HTTPS URL for the webhook

## 2. Install
1. Copy the `wa-dashboard/` folder onto your host (e.g. a subdomain like
   `app.gildana.net` pointed at this folder).
2. Create a database, then copy config:
   ```
   cp config.sample.php config.php
   ```
3. Edit **config.php**:
   - `db` → your MySQL host/name/user/pass
   - `encryption_key` → run `php -r "echo base64_encode(random_bytes(32));"` and paste it
   - `webhook_verify_token` → any random string (you'll paste it into Meta later)
   - `app_secret` → your Meta App's secret (enables webhook signature checks; optional but recommended)
   - `admin_email`, `base_url`
4. Visit the site in a browser. It redirects to **`setup.php`**, which runs the
   database migrations and creates your first **admin** account. Done.

> Security: `config.php`, `includes/`, and `migrations/` are blocked from web access
> via `.htaccess` (Apache). On nginx, add equivalent `location` denies.

## 3. Onboarding a client (you, as admin)
1. **Clients → + New Client**: set name, the client's **login email + password**,
   optional default country code (e.g. `20` for Egypt), starting credits.
2. On the client page, fill **WhatsApp API Credentials**:
   - **App ID**, **Phone Number ID**, **WABA ID**, **Access Token**
     (use a permanent *System User* token), optional **App Secret**.
   - Click **Test connection** — it calls the Graph API and confirms the credentials
     load the client's templates.
3. In **Meta**, point that client's WABA **webhook** at:
   `https://YOUR-DOMAIN/wa-dashboard/webhook.php`
   with your `webhook_verify_token`, subscribed to the **messages** field.
4. Give the client their login. They can now sync templates, import contacts, and
   send campaigns.

## 4. The send worker (required)
Sending happens in `cron/dispatch.php`. Add a cron entry:
```
* * * * * php /path/to/wa-dashboard/cron/dispatch.php >/dev/null 2>&1
```
No CLI cron? Hit it from an external uptime pinger every minute:
```
https://YOUR-DOMAIN/wa-dashboard/cron/dispatch.php?token=<webhook_verify_token>
```
Throughput is capped by `send_batch_per_run` (per client) and `send_batch_global`
in config — keep these conservative until the client's quality tier is known.

## 5. How it works
- Campaigns use **approved templates only** (Meta rule). Clients sync templates from
  their WABA, then build a campaign, map `{{1}}`, `{{2}}` variables (fixed value or
  per-contact name/attribute), pick a list, and send now or schedule.
- Each recipient becomes a row in `campaign_messages` (the queue + delivery log).
- The cron worker claims a throttled batch, **debits 1 credit** per accepted send,
  calls the Graph API, and records the `wa_message_id`. Failed sends are refunded.
- The **webhook** updates each message to delivered/read/failed and flips any contact
  who replies **STOP** to opted-out (excluded from future campaigns).

## 6. Local development
XAMPP works out of the box:
```
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE wa_dashboard"
C:\xampp\php\php.exe -S 127.0.0.1:8099   # from the wa-dashboard folder
```
Open http://127.0.0.1:8099 → setup → create admin. To test real sends, onboard a
client using a **Meta test number** and run `php cron/dispatch.php` manually.

## Layout
```
index.php / setup.php / logout.php   Login, first-run setup
admin/                               Super-admin: clients, credentials, credits
client/                              Client: contacts, lists, templates, campaigns, reports, settings
cron/dispatch.php                    Throttled send worker (cron)
webhook.php                          Meta status + opt-out receiver
includes/                            db, auth, crypto, whatsapp, credits, campaign, view helpers
migrations/                          SQL schema
```
