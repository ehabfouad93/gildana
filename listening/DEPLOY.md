# Deploying Gildana Listening to cPanel

Same shape as the `wa-dashboard` deploy: a dedicated subdomain whose document
root is this app's folder, a ZIP upload, and one cron line. `config.php` is
never shipped.

---

## 1. Subdomain

In cPanel → **Domains** → *Create A New Domain*:

- Domain: `listen.gildana.net`
- Document Root: `/home/USER/listen.gildana.net`

Point it at the app folder itself, **not** inside the marketing site. Then run
AutoSSL so the subdomain has HTTPS before anyone logs in.

The `.htaccess` in this folder assumes it *is* the document root. If you ever
serve the app from a subfolder instead, re-check those rules — the directory
denies are written as `^/(.*/)?(includes|migrations|lang|tests)/` precisely so
they work in both cases.

## 2. Database

cPanel → **MySQL Databases**:

1. Create a database, e.g. `USER_listen`.
2. Create a user and give it **All Privileges** on that database.
3. Note the name, user and password — they go in `config.php`.

You do not need to import any SQL. The app creates its own tables on first run.

## 3. Upload

Zip the app folder **excluding `config.php`**, upload via cPanel File Manager,
and extract into the document root.

```bash
# from the repo root, on your machine
zip -r listening.zip listening -x 'listening/config.php' -x 'listening/storage/*' \
    -x 'listening/cron/.heartbeat'
```

## 4. Configure

In File Manager, copy `config.sample.php` to `config.php` and edit it:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'USER_listen',
    'user' => 'USER_listenuser',
    'pass' => '…',
],
'encryption_key' => '…',              // php -r "echo base64_encode(random_bytes(32));"
'session_name'   => 'gild_listen',    // keep this distinct from the other apps
'base_url'       => 'https://listen.gildana.net',
'worker_token'   => '…',              // php -r "echo bin2hex(random_bytes(16));"
'admin_email'    => 'info@gildana.net',
'mail_from'      => 'noreply@gildana.net',
```

**Back up `encryption_key` somewhere safe.** Every stored API key and Meta token
is encrypted with it. If it is lost, every client has to re-enter their
credentials — there is no recovery.

`session_name` must differ from `wa_dash` and `ai_studio` so a login to one app
does not collide with another on the same parent domain.

## 5. First run

Open `https://listen.gildana.net/setup.php`. It applies the migrations, reports
how many ran, and asks for the first administrator account. Once an admin
exists the page disables itself, so there is nothing to delete afterwards.

Then log in at `https://listen.gildana.net/`.

## 6. Cron

cPanel → **Cron Jobs**. Every five minutes:

```
*/5 * * * * /usr/local/bin/php /home/USER/listen.gildana.net/cron/worker.php >/dev/null 2>&1
```

The PHP binary path varies by host — it may be `/usr/bin/php` or an
`/opt/cpanel/ea-php82/root/usr/bin/php` path. Check cPanel → *Select PHP
Version* if the job silently does nothing.

**No CLI cron available?** Point an external uptime pinger at:

```
https://listen.gildana.net/cron/worker.php?token=<worker_token>
```

The worker refuses any HTTP request without the right token, and a MySQL
advisory lock means two overlapping runs cannot both work at once.

### Verifying the worker

Admin → **Settings** shows when the worker last ran. If it says it has never
run, or is more than 15 minutes stale, the cron is not firing. A red banner
also appears on the admin overview.

## 7. Post-deploy checks

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://listen.gildana.net/config.php          # 403
curl -s -o /dev/null -w '%{http_code}\n' https://listen.gildana.net/includes/db.php     # 403
curl -s -o /dev/null -w '%{http_code}\n' https://listen.gildana.net/migrations/001_init.sql  # 403
curl -s -o /dev/null -w '%{http_code}\n' "https://listen.gildana.net/cron/worker.php"   # 403 (no token)
```

Then, in the app: add a client, add one keyword, add a Google News source, press
**Check now**, and confirm mentions appear with sentiment attached.

LiteSpeed caches aggressively — hard-refresh after an upgrade. CSS and JS are
already cache-busted by file mtime.

## 8. Upgrading

1. Zip and upload as in step 3, overwriting the existing files.
2. `config.php` is not in the zip, so it survives untouched.
3. Visit **Admin → Settings**; if a migration is pending it says so and offers a
   button to run it.

Never edit a migration that has already been applied — add a new numbered file.

Migration `002` adds a normalized `search_text` column to `mentions`. It is
populated going forward at ingest, and existing rows are backfilled in batches
of 200 by the worker, so there is nothing to run by hand — search accuracy for
older mentions simply improves over the first few ticks after the upgrade.

### Server time zone

The app pins its MySQL session to UTC (`SET time_zone = '+00:00'` on connect)
and stores every timestamp in UTC. You do not need to change the server's zone,
and you should not: the app is self-consistent either way, and changing it
would misalign timestamps already stored.

---

## Email deliverability — read this before relying on alerts

Alerts and digests go out through PHP's `mail()`, which is what shared hosting
offers without Composer. Subject lines are MIME-encoded so Arabic arrives
intact.

The catch: mail sent from a subdomain **without an SPF record that authorises
the server** usually lands in spam, and an alert in spam is worse than no alert
at all. Before promising a client alerting:

1. Add or extend the SPF TXT record on `gildana.net` to include the hosting
   server (cPanel → *Email Deliverability* shows the exact record and will
   offer to fix it).
2. Enable DKIM on the same screen.
3. Send yourself a test alert and check where it lands.

If deliverability cannot be fixed, alerts are still recorded in the app and
visible on the client's Alerts page — the notification itself is never lost,
only its email delivery.

---

## Operational notes

- **Free sources are best-effort.** Google News RSS and Reddit's anonymous JSON
  are undocumented endpoints. They will break or throttle eventually. When a
  source fails five times in a row it is marked `error`, shown on Admin →
  Source Health, and emailed to `admin_email`. The paid aggregator exists as the
  reliable path for anything a client depends on commercially.
- **YouTube quota** is 10,000 units/day and a search costs 100, so roughly 100
  checks per key per day. The key is stored per client, so the quota is theirs,
  and the minimum interval is enforced at 60 minutes.
- **Meta tokens expire** after about 60 days. Admin → Settings flags any token
  older than 50 days.
- **Latency** is the cron interval plus the source's own check interval — up to
  about 35 minutes for a Google News source at its default. Not real-time, by
  design. Say so to clients rather than letting them assume otherwise.
- **Billable API spend** is auditable: Admin → Source Health totals SerpApi and
  YouTube units consumed this month.
