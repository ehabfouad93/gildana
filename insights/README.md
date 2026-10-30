# Gildana Insights

One dashboard for every client's paid media — **Meta, Google Ads, LinkedIn, Snapchat and TikTok** — with deep campaign drill-downs, client reports and live share links. Website analytics, heatmaps, SEO and GEO modules are built on the same base in later phases.

Next.js 15 (App Router) · TypeScript · PostgreSQL + Prisma · Auth.js · Tailwind · ECharts. A separate worker process runs the sync queue.

## Structure

```
Client (brand or agency)
 └ Company (one or many — an agency adds each brand it manages)
    └ Ad accounts, any number per platform, from any number of platform logins
```

Roles: **Admin** (Gildana — sees every client, opens any client's dashboard), **Client** (manages its companies, logins and links), **Viewer** (read-only).

## What's in it

| Area | Pages |
|---|---|
| Summary | `/overview` KPIs with period-over-period change, trend, platform/company split, top campaigns |
| Spend | `/spend` daily spend stacked by platform, by company/account/campaign, monthly budget |
| Performance | `/performance` metric explorer vs previous period, audience breakdowns, by account / campaign |
| Campaign drill-down | `/campaigns/[id]` ad sets · ads with creative preview · breakdowns (age, gender, age×gender heatmap, country, region, device, placement, hour, job title…) · targeting & interests ranked by results · leads with the ad set and interests they came from · Google keywords, search terms, placements |
| Audiences | `/audiences` every interest, audience, job title, keyword and placement ranked across all campaigns |
| Google | `/google` keyword (with quality score), search term and Display/YouTube placement performance |
| Reports | `/reports` build a report for the whole client, one company, one platform or one campaign; rolling or fixed range; optional password and expiry → live link `/s/[token]` with *Download PDF* |
| Setup | `/connections` connect logins (OAuth) and assign each ad account to a company · `/companies` |
| Admin | `/admin/clients`, `/admin/clients/[id]` (users, settings), `/admin/health` (sync jobs, expired tokens) |

Money is converted per account into the client's reporting currency (`src/lib/fx.ts`; rows in `FxRate` override the built-in rates).

**Interests and per-interest results:** Meta and TikTok report results per ad set, not per interest. The targeting views credit each option with the full results of the ad sets that use it — good for ranking, and the UI says so. For a true per-interest cost, run one interest per ad set.

## Run locally

```bash
cp .env.example .env              # set AUTH_SECRET and ENCRYPTION_KEY
docker compose up -d db           # or any PostgreSQL 14+
npm install
npx prisma migrate deploy
npm run db:seed                   # admin + "Demo Agency" with demo logins on every platform
npm run worker -- --once          # load the demo data (or `npm run worker` to keep running)
npm run dev                       # http://localhost:3000
```

Logins after seeding: `admin@gildana.net` / `change-me-now` (from `SEED_ADMIN_*`), and `client@demo.local` / `demo1234`.

## Demo mode and real APIs

With `DEMO_MODE=true`, every platform offers a **demo login** that generates realistic, deterministic data — so the whole product works before API approvals arrive. When a platform's app credentials are set in `.env`, its **Connect** button runs the real OAuth flow instead.

| Platform | Status | Needs |
|---|---|---|
| Meta | Live connector (`src/platforms/meta`) — structure, daily insights at 4 levels, 8 breakdowns, ad set targeting, lead ads, live ad previews | `META_APP_ID`, `META_APP_SECRET`; app with `ads_read`, `leads_retrieval`, `business_management`. OAuth redirect: `{APP_URL}/api/oauth/meta/callback` |
| Google Ads | Demo now; live connector next phase | developer token, OAuth client, MCC id |
| LinkedIn / Snapchat / TikTok | Demo now; live connectors next phase | each platform's marketing API app |

Connectors implement one interface (`src/platforms/types.ts`); `src/worker/sync.ts` maps any of them onto the warehouse.

## Sync

`npm run worker` claims jobs from the `SyncRun` table (`FOR UPDATE SKIP LOCKED`, so several workers can run). New accounts backfill `BACKFILL_DAYS` (default 90) newest-first in 31-day chunks; every account then re-syncs the last 28 days every 3 hours, which picks up the platforms' attribution restatements. Failures retry with backoff up to 5 times and show on `/admin/health`.

## Deploy

`docker compose up -d --build` runs Postgres, the web app (applies migrations on start) and the worker. On a host without Docker: `npm ci && npx prisma migrate deploy && npm run build`, then run `npm start` and `npm run worker` under a process manager (pm2/systemd). The web app alone can run on Vercel or cPanel's Node.js apps, but the worker needs a long-running process.

## Checks

```bash
npm run typecheck && npm test && npm run build
```
