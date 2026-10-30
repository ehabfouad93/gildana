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

| Platform | Connector | App credentials (`.env`) · OAuth redirect `{APP_URL}/api/oauth/<slug>/callback` |
|---|---|---|
| Meta | `src/platforms/meta` — campaigns/ad sets/ads, daily insights at 4 levels, 8 breakdowns (age, gender, age×gender, country, region, device, placement, hour), ad set targeting (interests, behaviours, audiences, geo…), lead ads, live ad previews | `META_APP_ID`, `META_APP_SECRET`; scopes `ads_read`, `leads_retrieval`, `business_management` |
| Google Ads | `src/platforms/google` — GAQL; MCC-aware account discovery; campaigns/ad groups/ads, ad group + campaign criteria, daily stats, age/gender/country/device/network/hour, keywords with quality score, search terms, Display/YouTube/app placements; token refresh | `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN`, optional `GOOGLE_ADS_LOGIN_CUSTOMER_ID` |
| LinkedIn | `src/platforms/linkedin` — campaign groups/campaigns/creatives, targeting criteria (job titles, seniority, industry, company size, geo…), adAnalytics daily at 4 levels, job title/seniority/industry/company size/country pivots | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`; Marketing API access with `r_ads`, `r_ads_reporting` |
| Snapchat | `src/platforms/snapchat` — campaigns/ad squads/ads/creatives, targeting, daily stats at 4 levels, age/gender/country/OS | `SNAPCHAT_CLIENT_ID`, `SNAPCHAT_CLIENT_SECRET` |
| TikTok | `src/platforms/tiktok` — campaigns/ad groups/ads, targeting, BASIC reports at 4 levels, AUDIENCE reports (age, gender, country, platform, interest category) | `TIKTOK_APP_ID`, `TIKTOK_APP_SECRET` |

The connectors are written against each platform's current documentation and covered by unit tests on their normalisers. Meta is the first to run against live accounts; expect small field adjustments on the others when their API access is approved and the first real sync runs (errors show on `/admin/health`).

Connectors implement one interface (`src/platforms/types.ts`); `src/worker/sync.ts` maps any of them onto the warehouse.

## Sync

`npm run worker` claims jobs from the `SyncRun` table (`FOR UPDATE SKIP LOCKED`, so several workers can run). New accounts backfill `BACKFILL_DAYS` (default 90) newest-first in 31-day chunks; every account then re-syncs the last 28 days every 3 hours, which picks up the platforms' attribution restatements. Failures retry with backoff up to 5 times and show on `/admin/health`.

## Deploy

`docker compose up -d --build` runs Postgres, the web app (applies migrations on start) and the worker. On a host without Docker: `npm ci && npx prisma migrate deploy && npm run build`, then run `npm start` and `npm run worker` under a process manager (pm2/systemd). The web app alone can run on Vercel or cPanel's Node.js apps, but the worker needs a long-running process.

## Checks

```bash
npm run typecheck && npm test && npm run build
```
