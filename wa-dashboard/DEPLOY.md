# Deploy & Test on a Real Host

> **On a VPS?** Use **[`deploy/VPS-SETUP.md`](deploy/VPS-SETUP.md)** instead — one script
> installs nginx + PHP + MariaDB, creates the database and runs every migration.
> The guide below is for cPanel / shared hosting.

A step-by-step for putting the WhatsApp dashboard live (cPanel / shared or VPS) and
running a real end-to-end test with WhatsApp. Times assume a cPanel host; adapt paths
for your setup.

---

## Part A — Host setup

### 1. Put the files on the server
Recommended: a dedicated subdomain, e.g. **`app.gildana.net`**, pointed at the
`wa-dashboard/` folder (NOT inside the marketing site).

- cPanel → **Domains → Create a New Domain** → `app.gildana.net`, document root e.g.
  `/home/USER/app.gildana.net`.
- Upload the contents of `wa-dashboard/` into that document root (File Manager or
  SFTP). You should end up with `app.gildana.net/index.php`, `/admin`, `/client`,
  `/cron`, `/includes`, `/webhook.php`, etc.
- Make sure **HTTPS** is on for the subdomain (cPanel → SSL/TLS Status → AutoSSL /
  Let's Encrypt). This is required.

### 2. Create the database
- cPanel → **MySQL Databases**:
  - Create a database (e.g. `USER_wadash`).
  - Create a DB user + strong password, **add the user to the database with ALL
    privileges**.
- You do **not** need to import any SQL — the app creates its tables automatically on
  first run.

### 3. Configure `config.php`
- Copy `config.sample.php` to `config.php` (File Manager → Copy, or `cp`).
- Edit `config.php`:
  - **db**: `host` usually `localhost`, then your `name` / `user` / `pass`.
  - **encryption_key**: generate one and paste it. In cPanel → Terminal (or SSH):
    ```
    php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
    ```
    ⚠️ Back this up. Losing it means every stored WhatsApp token must be re-entered.
  - **webhook_verify_token**: any random string you choose (you'll paste the same one
    into Meta later).
  - **app_secret**: your Meta App secret (fill this in Part B). Enables webhook
    signature verification.
  - **base_url**: `https://app.gildana.net`.
  - Leave `graph_version`, throttle, and `captcha_enabled` as-is.

### 4. Lock down secrets
- The included `.htaccess` files block `config.php`, `includes/`, and `migrations/`
  on **Apache/cPanel** — verify by visiting `https://app.gildana.net/config.php`
  (should be **403 Forbidden**, not show code).
- On **nginx**, add `location` denies for those paths (the `.htaccess` won't apply).
- Confirm `gd` is enabled (cPanel → **Select PHP Version → Extensions → gd**) so the
  login CAPTCHA works. If you can't enable it, set `captcha_enabled => false`.

### 5. First-run: create your admin
- Visit `https://app.gildana.net/` → it redirects to **setup.php**, runs the
  migrations, and asks you to create the first **admin** account. Do it, then sign in.

### 6. Schedule the send worker (required)
Campaigns only send when the cron runs.
- cPanel → **Cron Jobs** → add, every minute:
  ```
  * * * * * /usr/local/bin/php /home/USER/app.gildana.net/cron/dispatch.php >/dev/null 2>&1
  ```
  (Use the PHP binary path your host lists; some are `/usr/bin/php` or an `ea-php82`
  path.)
- No cron access? Use an external uptime pinger (e.g. cron-job.org) hitting:
  ```
  https://app.gildana.net/cron/dispatch.php?token=YOUR_webhook_verify_token
  ```
  every minute.

---

## Part B — WhatsApp / Meta setup

You need a Meta app to get API credentials and to receive webhooks.

### 7. Create the Meta app + WhatsApp product
- Go to **developers.facebook.com** → My Apps → **Create App** → type **Business**.
- Add the **WhatsApp** product. Meta gives you a **test phone number** and, under
  **API Setup**, shows:
  - **Phone number ID**
  - **WhatsApp Business Account ID (WABA ID)**
  - A **temporary access token** (24h) — fine for first tests. For production use a
    permanent **System User** token (Business Settings → Users → System Users → add →
    generate token with `whatsapp_business_messaging` + `whatsapp_business_management`).
- **App Secret**: App → **Settings → Basic → App Secret** → paste into `config.php`.

### 8. Configure the webhook
- In the app: **WhatsApp → Configuration → Webhooks → Edit**:
  - **Callback URL**: `https://app.gildana.net/webhook.php`
  - **Verify token**: the exact `webhook_verify_token` from your `config.php`.
  - Click **Verify and Save** (the app answers the handshake automatically).
- **Subscribe** the WABA to the **`messages`** field (this delivers status updates +
  inbound STOP messages).

### 9. Add test recipients (test number only)
- Meta's test number can only message numbers you've whitelisted. In **API Setup →
  “To”**, add your own WhatsApp number(s) and confirm the code. (With a real,
  registered production number this step goes away.)

---

## Part C — End-to-end test

### 10. Onboard a client in the dashboard
- Sign in as admin → **Clients → + New Client** (name + a client login).
- Open the client → **WhatsApp API Credentials**: paste **App ID**, **Phone Number
  ID**, **WABA ID**, **Access Token** (+ App Secret optional) → **Save**.
- Click **Test connection** → should say *“Connection OK — N templates, M approved.”*
  (If it errors, the token/IDs are wrong or expired.)
- Give the client some **credits** (Quick top-up → e.g. +1,000).

### 11. Prepare & send a campaign
Either log in as the client, or use **Open Workspace** from the client page.
- **Templates → Sync Templates** → the pre-approved `hello_world` appears.
- **Contacts** → add your whitelisted WhatsApp number (opted-in).
- **Lists** → create a list → add that contact (or “Add all opted-in”).
- **Campaigns → + New Campaign** → name it → pick `hello_world` → choose the list →
  **Send now** → Create.

### 12. Watch it send
- Wait up to 1 minute for the cron (or run it once manually via SSH:
  `php /home/USER/app.gildana.net/cron/dispatch.php`).
- ✅ The WhatsApp message should arrive on your phone.
- Open the campaign **Report** → status flips **sent → delivered → read** as the
  webhook fires. 1 credit is deducted per accepted send.

### 13. Test opt-out
- From your phone, reply **STOP** to the message.
- In **Contacts**, that number flips to **Opted out** and is excluded from future
  campaigns.

---

## Go-live checklist
- [ ] HTTPS enforced on the subdomain
- [ ] `config.php` returns 403 (not viewable); real `encryption_key` set + backed up
- [ ] `app_secret` set (webhook signature verification active)
- [ ] Cron running every minute (check a campaign actually sends)
- [ ] Per client: their real registered number's Phone Number ID / WABA ID / permanent
      token entered, and their WABA webhook subscribed to `messages`
- [ ] `gd` enabled (or CAPTCHA disabled)
- [ ] DB user is least-privilege; backups scheduled for the DB + `config.php`

## Common issues
- **Test connection fails / “Malformed token”** → token expired (temp tokens last 24h)
  or wrong WABA ID. Use a permanent System User token.
- **Message never arrives** → recipient not whitelisted on the test number, or contact
  is opted-out, or 0 credits, or cron not running (run `dispatch.php` by hand to see
  output).
- **Report stuck on “sent”, never “delivered”** → webhook not reaching the server:
  re-check the Callback URL, verify token, and that `messages` is subscribed.
- **Broken image on login** → `gd` not enabled; enable it or set
  `captcha_enabled => false`.
