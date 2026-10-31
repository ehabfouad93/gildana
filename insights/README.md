# Gildana Insights

One dashboard for every client's paid media — **Meta, Google Ads, LinkedIn, Snapchat and TikTok** — with deep campaign drill-downs, client reports and live share links, plus each company's **website**: GA-style analytics, click/scroll heatmaps, session replay, SEO audits and GEO (AI-search) readiness.

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
| Websites | `/sites` → per site: **Setup** (tracking code, goals, replay) · **Analytics** (realtime, users/sessions/bounce/conversions vs previous period, channels incl. *AI assistants*, acquisition, pages, audience, events, funnels, **Ads → site**: ad spend joined to on-site behaviour by `utm_campaign`) · **Heatmaps** (clicks, rage and dead clicks, scroll reach, top elements; per device, over a live frame or a captured snapshot) · **Recordings** (journey timeline + rrweb replay) · **SEO** (crawler, issues, pages, Core Web Vitals, Search Console queries/pages) · **GEO** (AI-crawler access, llms.txt, schema, answer-ready content, trust; Claude review; weekly AI-visibility checks of buyer questions) |
| Setup | `/connections` connect logins (OAuth) and assign each ad account to a company · `/companies` |
| Admin | `/admin/clients`, `/admin/clients/[id]` (users, settings), `/admin/health` (sync jobs, expired tokens) |

Money is converted per account into the client's reporting currency (`src/lib/fx.ts`; rows in `FxRate` override the built-in rates).

**Per-interest and per-audience results.** Every number on the Targeting tab and `/audiences` is labelled with its source:

| Source | Where it comes from |
|---|---|
| Reported | The platform reports the segment itself — Google audience segments, topics and keywords; TikTok interest categories; LinkedIn job title, seniority, industry and company size; Snapchat interests where available. Stored as ad-set-level `seg_*` breakdowns. |
| Measured | An ad set that targets that one segment only. |
| Tested | An ad set created by an **interest split test** from the dashboard. |
| Estimated | Meta ad sets with several interests (Meta has no per-interest breakdown): split by a non-negative ridge regression fitted across all the account's ad sets (`src/lib/attribution.ts`). Shown only when the interest appears alone somewhere or in 3+ ad sets with different companions. |
| Ad-set ranking | Too little variation to separate it — the whole ad set's results, for ranking only. |

Leads are credited the same way: fully to a single-segment ad set's segment, otherwise shared by the model's weights.

**Interest split tests (Meta).** On a multi-interest Meta ad set, *Split test these interests* creates one ad set per chosen interest — same campaign, ads, optimisation, placements and other targeting, budget split evenly — all **paused**. It needs the `ads_management` permission: use *Connect with split tests* on the Ad accounts page (the normal connect stays read-only). Demo logins simulate it.

Meta also gets the closest splits it does offer: customer segment (`user_segment_key`: new / engaged / existing customers, Advantage+), frequency, and creative assets (headline, primary text, image, video, CTA — dynamic creative). Breakdowns an account doesn't support are skipped.

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

## Website tracking

Each site gets a snippet from its Setup tab:

```html
<script async src="https://YOUR-APP/t.js" data-key="SITE_KEY"></script>
```

`t.js` is 2.6 KB gzipped and sends `text/plain` beacons (no CORS preflight) to `/api/collect`, which accepts only the site's domain and subdomains, drops bots and rate-limits per IP. Session replay uses rrweb served from this app (`/vendor`, copied from `node_modules` by `npm run vendor`), records a configurable share of sessions and masks every input. `gildana("event", name)` and `gildana("conversion", name, value)` track custom events; goals (a page prefix or an event name) mark sessions as converted.

A demo landing page ships at `/demo-site` with 30 days of generated visits (seed) — visiting it records real data too.

## SEO and GEO

Crawls and audits are queued from the site pages and run in the worker. The crawler respects robots.txt, follows the sitemap and internal links, and records per-page facts and issues. Optional:

| Env | Enables |
|---|---|
| `CHROMIUM_PATH` | heatmap page snapshots and the GEO "readable without JavaScript" render check (any Chrome/Chromium binary; `npx playwright-core install chromium` also works) |
| `PAGESPEED_API_KEY` | higher PageSpeed Insights quota for Core Web Vitals (works without a key at low volume; public sites only) |
| `GSC_CLIENT_ID`, `GSC_CLIENT_SECRET` | Search Console connect (falls back to the Google Ads OAuth client); redirect URI `{APP_URL}/api/oauth/gsc/callback` |
| `ANTHROPIC_API_KEY` | Claude GEO review and AI-visibility checks (`CLAUDE_MODEL`, default `claude-opus-5-5`); refused requests use the API's server-side fallback |

AI visibility asks each tracked question with web search and from model knowledge, and records whether the brand is named, its rank in the list, whether the site is cited, and which competitors were named. It runs weekly for every tracked question, or on demand.

## Sync

`npm run worker` claims jobs from the `SyncRun` table (`FOR UPDATE SKIP LOCKED`, so several workers can run). New accounts backfill `BACKFILL_DAYS` (default 90) newest-first in 31-day chunks; every account then re-syncs the last 28 days every 3 hours, which picks up the platforms' attribution restatements. Failures retry with backoff up to 5 times and show on `/admin/health`.

## Deploy

`docker compose up -d --build` runs Postgres, the web app (applies migrations on start) and the worker. On a host without Docker: `npm ci && npx prisma migrate deploy && npm run build`, then run `npm start` and `npm run worker` under a process manager (pm2/systemd). The web app alone can run on Vercel or cPanel's Node.js apps, but the worker needs a long-running process.

## Checks

```bash
npm run typecheck && npm test && npm run build
```
