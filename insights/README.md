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

## Running it as a SaaS

Clients never handle API keys. Gildana sets each platform up **once**, then every client connects with a click.

- **Admin → Platforms**: a checklist per platform (Meta, Google Ads, LinkedIn, Snapchat, TikTok) with the redirect URI to copy, fields for the app keys (stored encrypted in the database; `.env` still works as a fallback), plus email, Claude and PageSpeed keys. Switches for public sign-up, demo logins and auto-approving shared accounts.
- **Sign-up** (`/signup`, when enabled): a client creates a workspace (brand or agency), then lands in **Get started** (`/welcome`): add companies → connect platforms → put each ad account in a company → dashboard.
- **Invitations**: Admin → Clients → client → *Send invitation*. The person gets an email (or you copy the link) to set a password, valid 7 days, then the same wizard.
- **Share access instead of logging in**: clients give Gildana's Business Manager (Meta), manager account (Google), Business Center (TikTok) or member (LinkedIn/Snapchat) access, then request the account ID. Gildana's own login (connect it on Admin → Platforms; for Meta a Business Manager **system user** token is best, as it never expires) can see it, and an admin approves it in **Access requests**. Approval stops one client pulling in another's account; turn on auto-approve if you prefer.

## Users and roles

| Role | Who | Can do |
|---|---|---|
| Gildana admin | Gildana | Everything: all clients, platform keys, Gildana team, sync health |
| Account manager | Gildana | Only the clients assigned in Admin → Gildana team: their dashboards, settings and users |
| Owner | Client | Everything in their workspace, plus **Team** and **Settings** |
| Manager | Client | Connect ad accounts, companies, sites, reports |
| Viewer | Client | Read-only |

For each manager or viewer, Team → Edit sets **which sections they can open** (Overview, Spend, Performance, Campaigns, Audiences, Google, Websites, Reports, Ad accounts, Companies) and **which ad accounts they see**: all, whole companies (including accounts added later) and/or single ad accounts. Everything they open — reports, campaigns, breakdowns, websites, budgets, share links — follows that grant, and limited users can't reorganise accounts or companies. Under each section, single **features** can be switched off per user (each campaign tab, breakdowns, AI suggestions, creating split tests, pausing ad sets, Sync breakdowns now). Users can be deleted from Admin → Clients → client, Admin → Gildana team, or the client's own Team page. Nobody can delete or demote themselves, a workspace's last owner, or the last Gildana admin. Signing up creates an Owner.

## Meta interests: exact results, suggestions, split tests

Meta reports results per ad set, never per interest inside an ad set that mixes several. So **Audiences → Exact results** shows a number for an interest only when it is exact (an ad set or split-test arm that targets it alone; other platforms' per-segment reports); everything else is listed as "Not measured yet" without numbers. Nothing is estimated.

**Audiences → Suggestions** reads all Meta history (ignoring the date filter) and gives three opinions: rules from the account's own exact results (`src/lib/interest-advice.ts`: Scale / Stop / Test alone / Use here / Test again), Claude, and OpenAI — the AI ones on request, when `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` (+ optional `OPENAI_MODEL`) are set in Admin → Platforms. Ticked suggestions become a **split-test campaign**: one ad set per interest, copied from a chosen ad set, equal budgets, Advantage+ audience off, created paused unless "Start now" is ticked (needs a Meta login connected with split tests). Tests are read automatically (winner / too early / no clear winner); "Pause losing ad sets" is a manual button.

## To-do, audit and automation rules

Every change to a live ad account is a **proposal** first (`Action`, `src/lib/actions-engine.ts`). Once a day the worker runs the **audit** (`src/lib/optimise.ts`: spend without results, clear losers, tired ads, winners held back by budget) and the workspace's **automation rules** (presets Stop Loss, Revive, Surf, Sunsetting, or custom conditions) over its Meta accounts and files what they find under **To-do**. Nothing is changed until a person presses Apply; applied pauses, starts and budget changes (capped at ±20%) keep the previous state and can be undone from History. Applying needs a Meta login connected with split tests.

**Creatives** counts one creative together wherever it runs, ranks headlines, texts, buttons and formats, and flags tiring ads (last 7 delivery days against the ad's own first 7). **Tags** group campaigns; each ad set gets a **funnel stage** (prospecting, re-engagement, retargeting, retention) detected from its audiences and overridable; both filter every report and have their own tables on Performance.

**Audience mixer** (`/audiences/mixer`) builds an ad set's audience from interests, behaviours and custom audiences — any item inside a group, every group required, minus exclusions — shows Meta's reach estimate and launches it as a new ad set copied from a source ad set (paused by default). **Ready-made audiences** (`/audiences/launcher`) creates website-visitor, page / Instagram-engager and video-viewer audiences and lookalikes in a few clicks, and stores per audience which funnel stages exclude it by default. **Ask AI** answers questions about the workspace's own numbers from a data pack built on the server.

**Creative split-test grid** (`/creatives/grid`) turns several headlines, texts, images and buttons into one ad per combination (max 30), copied from a source ad. **Approvals**: a split-test campaign, a mixed audience or an ad grid can be sent to the client as a link (`/a/<token>`, no account) to approve, reject or request changes; approved ones are launched from To-do → Approvals, and Settings can make approval mandatory. **Leads sync** (`/leads-sync`) posts every new lead as signed JSON (`X-Gildana-Signature: sha256=…`, HMAC of the body with the destination's secret) to a public https webhook — a CRM, Zapier/Make, or a Google Sheet through the Apps Script shown on the page — with retries after 2, 10, 30 and 120 minutes. Customer lists (emails / phones) are hashed on the server and uploaded as a Meta custom audience from Ready-made audiences.

## Filters

Every analysis page (Overview, Spend, Performance, Campaigns, Audiences, Google Search) filters by company, platform, ad accounts, date range and **campaigns, ad sets or ads** (`?camp=` / `?set=` / `?ad=`). Meta breakdowns are stored per ad set and per campaign; an ad filter shows its ad set's breakdown.

## Currencies

Each workspace picks a **reporting currency** (Settings). Overview, spend, performance and reports add every ad account up in it, converted at daily exchange rates the worker fetches (`FX_RATES_URL`, default open.er-api.com; built-in rates are used until the first fetch). **Campaign pages** show each ad account's real currency — exactly what the platform charged — or the converted amounts; the default is a workspace setting and anyone can switch on the page.

## Passwords

- **Forgot password** on the sign-in page emails a one-time link (valid 1 hour). It needs `SMTP_URL` in `.env`, e.g. `smtp://you%40gildana.net:PASSWORD@smtp.hostinger.com:587` (URL-encode `@` in the username as `%40`), and `MAIL_FROM`. Without it, the page tells people to ask an admin. `SMTP_URL=log://` prints the emails to the log, for testing.
- **My account** (click your email in the sidebar): change your password, name and language.
- **Admin → Clients → client**: set a user's password, or *Email reset link*.
- **Locked out from the server:** `npm run user:password -- admin@gildana.net 'NewPassword'` (add `--admin` to create an admin), then `pm2 restart insights-web` to clear the sign-in lock.

## Sync

`npm run worker` runs the queue (the `SyncRun` table) with `WORKER_CONCURRENCY` jobs in parallel (default 5; one account never runs two jobs at once).

- **Live refresh:** every account re-pulls today and yesterday every `LIVE_SYNC_MINUTES` (default 15). Ad platforms update their reporting every 15–30 minutes, so this is as current as the data gets.
- **Refresh on open:** when someone opens a report whose accounts are more than 5 minutes old, a live refresh starts at once; the page shows *Live · updated N min ago* and reloads itself when the new numbers land.
- **Full re-sync:** the last 28 days every 6 hours, which picks up late conversions and attribution changes.
- **New accounts:** every connected account syncs at once, with or without a company (unassigned ones report as “Unassigned”); the latest 30 days of every account come first, then history back to `BACKFILL_DAYS` (default 90). Accounts with no delivery in a window are skipped in one cheap call.
- **Sync now** (per account, or for ticked accounts) moves the account to the front of the queue. Failures retry with backoff up to 5 times and show on Admin → Sync health.

## Deploy

`docker compose up -d --build` runs Postgres, the web app (applies migrations on start) and the worker. On a host without Docker: `npm ci && npx prisma migrate deploy && npm run build`, then run `npm start` and `npm run worker` under a process manager (pm2/systemd). The web app alone can run on Vercel or cPanel's Node.js apps, but the worker needs a long-running process.

## Checks

```bash
npm run typecheck && npm test && npm run build
```
