# Gildana Listening

Brand monitoring and social listening. Tracks a brand and its related keywords
across news, the web and social media, classifies every mention as positive /
negative / neutral, and alerts when something turns.

Third app in the Gildana platform, alongside `wa-dashboard/` and `ai-studio/`,
built to the same rules: plain PHP 8 (parses on 7.4), MySQL via PDO, raw cURL.
**No Composer, no npm, no build step, no framework.**

---

## What it does

- **Collects mentions** from eight connectors across three tiers:

  | Tier | Connector | Notes |
  |---|---|---|
  | Free | Google News RSS, Bing News RSS, any RSS/Atom feed, Reddit, YouTube | No cost. YouTube needs a free API key. |
  | Paid | SerpApi | Reliable fallback when a free source breaks. Costs a credit per check. |
  | Official | Facebook Page, Instagram | **Only pages and accounts the client owns.** |

- **Classifies sentiment** with a hybrid engine: an Arabic (including Egyptian
  dialect) + English lexicon scores everything first, and only the mentions it
  is not confident about go to an AI model. Cheap by default, accurate where it
  matters. Anyone can override a classification by hand, and the engine will
  never overwrite that.

- **Shows it** in a bilingual EN/AR dashboard with full RTL: volume over time,
  sentiment split, top sources and keywords, a filterable mentions feed, CSV
  export and a printable report.

- **Alerts** on any negative mention, a spike in negatives measured against the
  client's own baseline, a negative from a large account, or any hit on a
  chosen keyword. Plus optional daily/weekly email digests.

### What it cannot do

X/Twitter, TikTok, and *public* Facebook/Instagram posts cannot be searched —
no API sells that access at this tier. The Meta connectors only see assets the
client owns. This is stated on the Sources screen so clients do not expect
otherwise.

---

## Layout

```
listening/
├── index.php  setup.php  logout.php  set_lang.php   entry points
├── admin/     agency: clients, cross-client feed, source health, team, settings
├── client/    one brand: dashboard, mentions, keywords, sources, alerts, reports
├── includes/  the whole application — see below
├── includes/sources/   one file per connector
├── lang/      en.php, ar.php
├── cron/      worker.php — the only scheduled entry point
├── migrations/  001_init.sql
├── tests/     run.php + fixtures (no DB, no API keys needed)
└── assets/    listen.css, listen.js
```

Key modules:

| File | Responsibility |
|---|---|
| `includes/auth.php` | admin/client roles, workspace impersonation, login throttle |
| `includes/connectors.php` | the connector registry — add a source type here |
| `includes/http.php` | cURL with retry/429 backoff, conditional GET, feed parsing |
| `includes/ingest.php` | schedule → fetch → match → dedupe → store |
| `includes/sentiment.php` | the hybrid engine and the manual-override guard |
| `includes/lexicon_ar.php` / `_en.php` | the sentiment word lists — tune these |
| `includes/alerts.php` | rule evaluation and firing |
| `includes/metrics.php` | the dashboard aggregates |
| `migrations/` | numbered, applied in order, never edited once applied |
| `includes/chart.php` | inline-SVG charts, no library |

---

## Install

1. **Database** — create an empty MySQL database, e.g. `gildana_listening`
   (on cPanel it will be prefixed, e.g. `USER_listen`).

2. **Config** — copy `config.sample.php` to `config.php` and fill it in:

   ```bash
   cp config.sample.php config.php
   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # → encryption_key
   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"          # → worker_token
   ```

   **Back up `encryption_key`.** Every stored API key and token is encrypted
   with it; lose it and they all have to be re-entered.

3. **Run setup** — open `/setup.php` in a browser. It applies the migrations and
   creates the first administrator, then disables itself.

4. **Cron** — every 5 minutes:

   ```
   */5 * * * * php /path/to/listening/cron/worker.php >/dev/null 2>&1
   ```

   No CLI cron? Point an uptime pinger at
   `https://your-domain/cron/worker.php?token=<worker_token>` instead.

See `DEPLOY.md` for the cPanel specifics.

---

## Using it

**As the agency (admin):** add a client, which creates their login in the same
step. Open their workspace to set things up on their behalf — the bar at the
top of the screen makes it obvious you are inside a client account.

**Per client:**

1. **Keywords** — the brand name first, in Arabic *and* English if both are
   used. Add competitors to get share-of-voice. Use "exclude if it contains"
   to cut noise; a common word will otherwise pull in everything. Hit
   **Preview matches** before saving — it tests the term against the mentions
   already collected and tells you how many of the last 300 it would match,
   with examples. That is the fastest way to catch a term that is too broad.
2. **Sources** — enable the connectors you want. Google News and an RSS feed
   or two are enough to see results immediately.
3. **Settings** — optionally add an AI key (sentiment works without one, just
   less accurately on hard cases), a SerpApi key, a YouTube key, or Meta
   credentials.
4. **Alerts** — start with one "any negative mention" rule. The *Test against
   recent data* button shows how often it would have fired before you save it.

Mentions appear after the next worker run, or immediately via **Check now** on
the Sources page.

---

## Tuning sentiment

The lexicons are meant to be edited — `includes/lexicon_ar.php` is where
Egyptian dialect accuracy actually comes from. Terms are written in their
natural spelling; normalization is applied automatically, so you never need to
hand-fold hamza or ta-marbuta.

To see exactly how a sentence scores:

```bash
php tests/run.php --explain "الخدمة مش وحشة بس التأخير فظيع"
```

It prints the normalized text, every matched term with its weight, whether the
term was negated, and whether the result would be escalated to the AI.

The most valuable thing you can do for accuracy is to sit with the first ~200
real mentions and correct them by hand. Those corrections are permanent, and
the terms that keep coming up are the ones to add to the lexicon.

---

## Tests

```bash
php tests/run.php
```

77 checks covering feed parsing (RSS 2.0, Atom, RDF), the JSON connector
shapes, Arabic normalization, negation (including the Egyptian ما...ش
circumfix), the AI escalation threshold, keyword matching, dedupe hashing, and
the mentions INSERT staying in step with the schema. No database and no API
keys required.

### A note on searching Arabic

Mention text is stored twice: as written, and normalized into `search_text`
(diacritics stripped, hamza and ta-marbuta folded). The feed searches the
normalized copy and normalizes the query the same way, so "جيلدانا" also finds
"جِيلدانا" and "الجيلدانا". Searching the raw text would silently miss most of
them. Rows created before migration 002 are backfilled by the worker.

Lint everything:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Testing connectors without hitting live APIs

Set `fixtures_dir` in `config.php` to `/path/to/listening/tests/fixtures` and
every outbound HTTP call is served from the recorded responses on disk instead
of the network. Leave it `''` in production.
