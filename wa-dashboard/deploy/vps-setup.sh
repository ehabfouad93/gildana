#!/usr/bin/env bash
#
# Revenect — one-shot VPS installer (Ubuntu 22.04/24.04).
#
# Installs nginx + PHP-FPM + MariaDB, creates the database and a dedicated DB user,
# writes config.php with freshly generated secrets, runs the migrations, sets
# permissions and installs the 1-minute worker cron.
#
# Safe to re-run: it never overwrites an existing config.php or database.
#
# Usage (as root):
#   bash deploy/vps-setup.sh app.yourdomain.com
#
set -euo pipefail

DOMAIN="${1:-}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

die()  { echo -e "\n❌ $*\n" >&2; exit 1; }
info() { echo -e "\n\033[1;35m▶ $*\033[0m"; }
ok()   { echo "   ✓ $*"; }

[[ $EUID -eq 0 ]] || die "Run as root:  sudo bash deploy/vps-setup.sh <domain>"
[[ -n "$DOMAIN"  ]] || die "Pass your domain:  bash deploy/vps-setup.sh app.yourdomain.com"
[[ -f "$APP_DIR/index.php" ]] || die "Run this from inside the app folder (index.php not found in $APP_DIR)."

# ── 0. Don't break whatever already owns :80 / :443 (n8n's proxy, usually) ─────────
info "Checking ports 80/443"
PORT_OWNER="$(ss -ltnp 2>/dev/null | grep -E ':(80|443)\s' || true)"
if [[ -n "$PORT_OWNER" ]] && ! echo "$PORT_OWNER" | grep -qi nginx; then
    echo "$PORT_OWNER"
    cat <<'WARN'

   ⚠  Something other than nginx is already serving 80/443 — on this server that is
      almost certainly the n8n container's reverse proxy (Traefik/Caddy).

      Installing nginx now would fight it for the port and can take n8n offline.

      Pick one:
        A) Put the dashboard behind the SAME proxy that already runs n8n
           (add it to that docker-compose as another service/route), or
        B) Free the port for nginx and proxy n8n through nginx instead.

      Re-run with  FORCE_NGINX=1  only if you know the port is free.
WARN
    [[ "${FORCE_NGINX:-0}" == "1" ]] || die "Stopped so n8n keeps working."
fi
ok "port check done"

# ── 1. Packages ───────────────────────────────────────────────────────────────────
info "Installing nginx, PHP-FPM and MariaDB"
if [[ "${SKIP_APT:-0}" == "1" ]]; then
    ok "SKIP_APT=1 — using the packages already installed"
else
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq nginx mariadb-server \
        php-fpm php-mysql php-curl php-mbstring php-gd php-xml php-zip \
        certbot python3-certbot-nginx unzip >/dev/null
fi
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
# `|| true` matters: pipefail would otherwise abort the script when no socket exists yet.
[[ -S "$PHP_SOCK" ]] || PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
if [[ ! -S "$PHP_SOCK" ]]; then
    [[ "${SKIP_APT:-0}" == "1" ]] || die "PHP-FPM socket not found — is php${PHP_VER}-fpm running?"
    PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"   # nginx step is skipped anyway
fi
systemctl enable --now mariadb >/dev/null 2>&1 || true
ok "PHP $PHP_VER · socket $PHP_SOCK"

# ── 2. Database ───────────────────────────────────────────────────────────────────
info "Creating the database"
DB_NAME="revenect"
DB_USER="revenect"
if [[ -f "$APP_DIR/config.php" ]]; then
    DB_PASS="$(php -r '$c=require "'"$APP_DIR"'/config.php"; echo $c["db"]["pass"] ?? "";')"
    ok "reusing the password already in config.php"
else
    DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 28 || true)"
fi
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "database '${DB_NAME}' + user '${DB_USER}' ready"

# ── 3. config.php (never overwritten — it holds the encryption key) ───────────────
info "Writing config.php"
if [[ -f "$APP_DIR/config.php" ]]; then
    ok "config.php already exists — left untouched"
else
    ENC_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
    VERIFY_TOKEN="$(tr -dc 'a-z0-9' </dev/urandom | head -c 24 || true)"
    php -r '
      $p = $argv[1]."/config.sample.php";
      $s = file_get_contents($p);
      $r = [
        "'"'"'host'"'"'    => '"'"'127.0.0.1'"'"'," => "'"'"'host'"'"'    => '"'"'127.0.0.1'"'"',",
      ];
      $s = preg_replace("/'"'"'name'"'"'\s*=>\s*'"'"'[^'"'"']*'"'"'/", "'"'"'name'"'"'    => '"'"'".$argv[2]."'"'"'", $s, 1);
      $s = preg_replace("/'"'"'user'"'"'\s*=>\s*'"'"'[^'"'"']*'"'"'/", "'"'"'user'"'"'    => '"'"'".$argv[3]."'"'"'", $s, 1);
      $s = preg_replace("/'"'"'pass'"'"'\s*=>\s*'"'"'[^'"'"']*'"'"'/", "'"'"'pass'"'"'    => '"'"'".$argv[4]."'"'"'", $s, 1);
      $s = str_replace("CHANGE_ME_base64_32_bytes", $argv[5], $s);
      $s = str_replace("CHANGE_ME_pick_any_random_string", $argv[6], $s);
      $s = preg_replace("/'"'"'base_url'"'"'\s*=>\s*'"'"''"'"'/", "'"'"'base_url'"'"'      => '"'"'https://".$argv[7]."'"'"'", $s, 1);
      file_put_contents($argv[1]."/config.php", $s);
    ' "$APP_DIR" "$DB_NAME" "$DB_USER" "$DB_PASS" "$ENC_KEY" "$VERIFY_TOKEN" "$DOMAIN"
    ok "config.php created with a fresh encryption key"
fi

# ── 4. Migrations ────────────────────────────────────────────────────────────────
info "Running database migrations"
php -r '
  chdir($argv[1]);
  require "includes/config_loader.php"; require "includes/helpers.php";
  require "includes/crypto.php";        require "includes/db.php";
  $ran = migrate();
  echo "   ✓ applied: " . (count($ran) ? implode(", ", $ran) : "none (already up to date)") . "\n";
' "$APP_DIR"

# ── 5. Permissions ───────────────────────────────────────────────────────────────
info "Setting permissions"
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod 640 "$APP_DIR/config.php"              # secrets: not world-readable
mkdir -p "$APP_DIR/uploads" "$APP_DIR/assets/brand" "$APP_DIR/cron"
chmod 775 "$APP_DIR/uploads" "$APP_DIR/assets/brand" "$APP_DIR/cron"
ok "owner www-data, config.php 640"

# ── 6. nginx ─────────────────────────────────────────────────────────────────────
info "Configuring nginx for $DOMAIN"
if ! command -v nginx >/dev/null; then
    ok "nginx not installed — skipping (put the app behind your existing proxy instead)"
else
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__ROOT__|$APP_DIR|g" -e "s|__PHP_SOCK__|$PHP_SOCK|g" \
    "$APP_DIR/deploy/nginx-site.conf.template" > "/etc/nginx/sites-available/$DOMAIN"
ln -sf "/etc/nginx/sites-available/$DOMAIN" "/etc/nginx/sites-enabled/$DOMAIN"
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
ok "site enabled"
fi

# ── 7. Worker cron (1 minute — makes the personal-number pacing exact) ───────────
info "Installing the worker cron"
CRON_LINE="* * * * * php $APP_DIR/cron/dispatch.php >/dev/null 2>&1"
if command -v crontab >/dev/null; then
    ( crontab -l 2>/dev/null | grep -v "cron/dispatch.php" ; echo "$CRON_LINE" ) | crontab -
    ok "runs every minute"
else
    echo "   ⚠  crontab not found — add this line yourself, the worker will not run without it:"
    echo "      $CRON_LINE"
fi

cat <<DONE

────────────────────────────────────────────────────────────
 ✅ Installed.

 Database : ${DB_NAME}
 DB user  : ${DB_USER}
 DB pass  : ${DB_PASS}
              (already written into config.php — keep a copy)

 Next:
   1. Point ${DOMAIN} (A record) at this server's IP, then:
        certbot --nginx -d ${DOMAIN}
   2. Open  https://${DOMAIN}  and create the admin account.
   3. Meta webhook URL:  https://${DOMAIN}/webhook.php
      Verify token is in config.php ('webhook_verify_token').

 ⚠  Back up config.php. The encryption key inside it decrypts every stored
    WhatsApp/AI token — lose it and they must all be re-entered.
────────────────────────────────────────────────────────────
DONE
