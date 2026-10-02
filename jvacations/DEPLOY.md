# Deploying J Vacations to the VPS (jvacations.gildana.net)

This server already runs n8n behind Traefik, plus the Revenect stack from
`/opt/gildana`. J Vacations is deployed **beside** them, not inside them:

| | Revenect (existing) | J Vacations (new) |
|---|---|---|
| Code folder | `/opt/gildana` | `/opt/jvacations` (separate clone) |
| Compose project | `docker` | `jvacations` (pinned with `name:` in the compose file) |
| Containers | revenect, revenect-cron, revenect-db … | `jvacations`, `jvacations-db` |
| Database | revenect-db | its own `jvacations-db` + own volume |
| Traefik router | `revenect` | `jvacations` |
| Ports opened | none | none. Traefik routes the domain over its existing network |

Nothing in these steps runs `git` in `/opt/gildana`, restarts a Revenect
container, or touches Traefik's config. Running `docker compose up
--remove-orphans` in the Revenect folder also leaves J Vacations alone, because
the two stacks are separate compose projects.

---

## 0. Before you start (read-only checks)

```bash
# DNS: must print this server's IP. If not, add an A record "jvacations" → server IP and wait.
dig +short jvacations.gildana.net

# What is running now — keep this to compare after.
docker ps --format 'table {{.Names}}\t{{.Status}}'

# Memory: J Vacations needs ~120 MB at rest and is capped at ~640 MB.
free -h

# Traefik values, already confirmed for Revenect.
grep -E '^TRAEFIK_(NETWORK|CERTRESOLVER)=' /opt/gildana/wa-dashboard/deploy/docker/.env
```

## 1. Get the code into its own folder

```bash
git clone --branch claude/j-vacations-mini-erp-ahwwet --single-branch \
  "$(git -C /opt/gildana remote get-url origin)" /opt/jvacations
```

(Reusing `/opt/gildana`'s remote URL means it uses the same GitHub access that
already works there. `/opt/gildana` itself is not changed.)

## 2. Create the stack's .env

```bash
cd /opt/jvacations/jvacations/deploy/docker
{
  echo "JV_DOMAIN=jvacations.gildana.net"
  echo "JV_DB_PASS=$(openssl rand -hex 24)"
  grep -E '^TRAEFIK_(NETWORK|CERTRESOLVER)=' /opt/gildana/wa-dashboard/deploy/docker/.env
} > .env
chmod 600 .env
cat .env        # 4 lines; the two TRAEFIK_ values must not be empty
```

## 3. Check, then start

```bash
docker compose config --services     # must print exactly: jvacations-db, jvacations
docker compose up -d --build
docker ps --format 'table {{.Names}}\t{{.Status}}'   # old containers unchanged + 2 new
```

## 4. Create config.php and the tables

```bash
docker exec -e DB_PASS="$(grep '^JV_DB_PASS=' .env | cut -d= -f2-)" \
  jvacations php deploy/docker/make-config.php
```

Expected: `✓ config.php written`, `✓ applied 1 migration(s)`, `✓ tables: 10`.

## 5. Create the admin — straight away

Open **https://jvacations.gildana.net/setup.php** and create the admin account.
This page works only until the first admin exists, so do it before sharing the link.
(The certificate is issued on the first visit. If the browser warns, wait a minute and reload.)

Then: **Settings**, then **Projects** (at least one), then **Team** (one account per person).

## 6. Verify

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://jvacations.gildana.net/            # 302 (→ login)
curl -s -o /dev/null -w '%{http_code}\n' https://jvacations.gildana.net/config.php  # 403
curl -s -o /dev/null -w '%{http_code}\n' https://jvacations.gildana.net/deploy/docker/.env  # 403
docker ps --format 'table {{.Names}}\t{{.Status}}'   # Revenect/n8n still Up
```

---

## Updating later

```bash
cd /opt/jvacations && git pull
docker exec jvacations php deploy/docker/migrate.php
```

The code is mounted into the container, so there's no rebuild or restart, and
nothing else on the server is touched. `migrate.php` only applies new `.sql`
files, fixes the ownership of `storage/templates` (where uploaded contract
templates go), and is safe to run every time.

## Backup

```bash
cd /opt/jvacations/jvacations/deploy/docker
docker exec jvacations-db sh -c 'mariadb-dump -ujvacations -p"$MARIADB_PASSWORD" jvacations' \
  > /root/jvacations-$(date +%F).sql
```

## Stop / remove (only J Vacations)

```bash
cd /opt/jvacations/jvacations/deploy/docker
docker compose down          # stops and removes J Vacations containers; data is kept
```

**Never** run `docker compose down -v` here unless you mean to delete every
client, contract and payment. `-v` deletes the database volume.
