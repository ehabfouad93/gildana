# Revenect — Release history

Newest first. Every entry is written from what actually shipped, including the fixes.

Version numbers are ours: a **minor** bump (1.3 → 1.4) is a release with new capability, a
**patch** (1.4.0 → 1.4.1) is corrections only.

**Scope.** This covers the **WhatsApp dashboard**. The same repository also holds two other
products that these releases do not touch — the **Gildana marketing site** and **AI Studio**, a
standalone creative orchestrator (pick ChatGPT, Claude, GPT Image, Stable Diffusion or Kling
per step, then publish to Facebook and Instagram). Both arrived with the import below and have
their own history.

---

## 1.5.0 — 28 August 2026 · *latest*

The release that takes this from something you run to something many clients run at once:
sending that cannot double-charge, billing that fits both ways of owning a WhatsApp number,
automations you can preview before they touch anybody, and a builder that works on a phone.

### Sending, made safe under load

- **A crash can no longer send or charge twice.** Every attempt is written down *before* the
  call to WhatsApp, so a worker that dies mid-send leaves evidence. On restart, a message with
  no recorded attempt goes back in the queue; one whose outcome was never confirmed is held for
  review instead of blindly resent — it may well have been delivered. One WhatsApp message id
  can now only ever belong to one row.
- **Failures retry on their own**, backing off between tries, and stop after four rather than
  hammering a number that will never accept. Everything that gave up lands in **Failed to
  send**, with the reason WhatsApp gave.
- **One slow client can no longer hold up the others.** Each client's sending is claimed
  separately, so a big campaign runs alongside everyone else's instead of in front of them.
- **Incoming webhooks answer immediately** and do the work afterwards. Meta was timing out and
  retrying while a worker was still busy.

### Two ways to own a WhatsApp number

- Clients can stay on **their own WhatsApp Business account** — Meta bills them directly and
  nothing changes — or be moved onto **the platform's**, where the real per-message cost is
  recorded and re-billed with your markup.
- **Plans** carry included credits, limits and an AI allowance. **Rates** are yours to
  maintain, by country and message category, because Meta's prices differ by country and
  change; none of them are baked into the code. Service replies inside the 24-hour window are
  free and are charged as such.
- **AI is metered in one place.** A client using their own key is not counted and costs you
  nothing. On a plan that includes AI, tokens are counted and, when the allowance runs out, the
  AI step falls back to its written reply and the client is told — never a surprise bill.
- New: **Admin → Plans**, **Admin → Rates**, **Billing** for the client.

### Automations

- **Preview a flow as a WhatsApp conversation** with the variables filled in, answering as the
  customer to walk further in. Nothing is sent and nothing is charged — it runs the real engine
  inside a transaction that is rolled back, so the preview cannot drift from the truth.
- **Check for problems** before going live: a step nothing leads to, a question with no way
  out, an AI step with no fallback, a deleted template, a message that would breach the 24-hour
  window.
- **Each step shows how many people reached it and how many stopped there**, so a flow that
  quietly loses everyone at step three says so.
- **Seven new kinds of step**: if/then, remember a value, split test, go to another automation,
  wait until a time or weekday, call a web service, and a menu list of up to ten options.
- **Five ready-made flows** to install in one click — welcome and qualify, abandoned cart,
  booking reminder, FAQ deflection, lead capture to a sheet.

### A campaign can continue into an automation

- After the message goes out, a campaign can hand each contact to an automation you have
  already built: **after sent, delivered, read, first reply, or a set time with no reply** —
  and to **everyone, only those who received it, only those who replied, or only those who did
  not**. Failed recipients are never enrolled, and a campaign that is retried enrols nobody
  twice.

### The flow builder

- A **+ on every free step** adds the next one already connected. **Changes save themselves**,
  with the state shown in the toolbar. **Undo and redo** cover everything. Shift-click gathers
  steps to **copy, paste, move or delete as a group**. Steps snap to a grid, **Tidy up** lays
  the flow out, and full screen gives the canvas the window.
- **It works on a phone.** The flow is also an **ordered list** — every step in the order a
  contact meets it, branches nested underneath — that you tap to edit, reorder with arrows and
  extend with a button. No dragging. The note telling people to go and find a computer is gone.

### Security

- **Each client's Meta callbacks are verified with that client's own app secret.** The field
  was being collected and never read, so any request could claim to be from Meta.
- **Media fetching and the new web-service step cannot be pointed at the server's own network**
  — HTTPS only, no redirects, private addresses refused, response size capped.
- **The webhook secret for a connected number can be rotated without the client noticing** —
  no disconnect, no reconnect, no gap in which a message would be rejected.
- Bad configuration now **fails closed** rather than running with a weak default, and the
  installer locks itself once a database exists.

### Data kept

- Client data is **never deleted**: messages, campaign history, contacts and payments stay.
  Only two debug logs are trimmed, and only after **90 days** — the raw webhook feed and the
  inbound decision log, both of which exist to diagnose the last few days.

### Upgrading

Run migrations (`018` through `025`). Rates and plans start empty and are yours to fill in
before moving anyone onto the platform's WhatsApp account; existing clients stay on their own
and are unaffected. `deploy/preflight.php` reports what is still missing, and
`deploy/rotate-hook-secrets.php` rotates connected numbers' webhook secrets in place.

**Not code:** using the platform's own WhatsApp account for clients also needs Meta to approve
you as a Tech Provider. The schema and billing are ready for it; the approval is a commercial
process with Meta.

---

## 1.4.0 — 25 August 2026

Google Sheets both ways, a help centre you control, and a top bar that shows who is signed in.

### Google Sheets, connected properly

- **Connect your Google account** in one click, from Settings → Google Sheets. No script to
  paste into a spreadsheet, no service-account file, no Google project of your own.
- An automation can be **triggered by new rows in a sheet**, and can **write each finished
  lead back into one**. Private sheets work — nothing has to be published to the web.
- After choosing a sheet, its **own column headers** fill in the phone/name mapping, and the
  obvious ones are guessed for you.
- Operator setup is once, for the whole platform: Admin → Settings → Google.

> We ask Google only for `drive.file`, which reaches the files you pick and nothing else in
> your Drive. That is deliberate — full Drive access is a "sensitive" permission and would put
> the app in a Google review queue before any client could connect at all.

### Help centre

- Two buttons float on every page: **Help** and, when you switch it on, an **intro video**.
- Help has your FAQ and a **contact form for technical support**.
- Everything is yours to edit in **Admin → Help Content** — add, reorder, hide or delete
  questions, swap the video, and read and close the requests clients send. No deploy needed.
- The video takes the link straight from your browser's address bar (YouTube, youtu.be,
  Shorts, Vimeo, or a direct `.mp4`) and converts it for you.

### Top bar

- The **logo height is adjustable** (Admin → Branding). The bar grows to fit, so a tall or
  square logo is never cropped.
- Your **name and picture** replace the raw email address; without a picture you get your
  initials. Set both in Settings → Your Profile.
- A **notification bell** shows unread incoming messages — your own as a client, across all
  clients as an admin.

### Also

- The automation **image node has a proper uploader** instead of only accepting a pasted URL.

### Fixed

- Every link in the sidebar and top bar 404'd on the new Help page, because it sits at the app
  root while other pages sit a folder down. Positions are now worked out rather than assumed.
- Saving the intro video silently did nothing — the settings helper wasn't loaded on that page.
- The logo height setting was read through a helper most pages never load, so every logo stayed
  at the default however it was configured.

### Upgrading

Run migrations (`015`, `016`, `017`). `uploads/avatars/` must be writable.

---

## 1.3.0 — 24 August 2026

Making the personal-number channel actually reliable. Messages arrived but replies didn't
deliver, some inbound vanished, and the bot went quiet for minutes at a time — four separate
causes.

### Fixed

- **Replies to inbound chats never delivered.** WhatsApp has been moving conversations to LID
  addressing, where the sender arrives as an opaque id instead of a phone number. That id was
  being stored as the contact's phone, so every reply was addressed to a number that does not
  exist. The real number is now taken from the mapping WhatsApp sends, and when it sends none,
  the reply goes back to the exact address the message came from.
- **Automations and the Lead Qualifier ignored replies.** A reply from an unmapped address was
  filed against a *new* contact while the flow was waiting on the one you imported — so nothing
  was ever waiting where the message landed. The mapping is now learned from the gateway's echo
  of your own outgoing messages, so a reply lands on the right contact.
- **Some incoming messages arrived, some didn't.** A delivery carrying several messages — a
  phone coming back online, or three messages in a row — kept only the first and discarded the
  rest.
- **The bot answered the first message and ignored the next two.** The safety throttle (15
  messages, then a pause) was being applied to *replies*, not just to bulk sending. Replies are
  exempt now; campaigns and cold outreach are still paced exactly as before.
- **Slow replies looked like failures to the gateway.** The dashboard couldn't release the
  connection before doing the work, so the gateway waited for the whole AI round trip and timed
  out. It now answers immediately and works afterwards.
- **Incoming messages stopped after any reconnect.** The gateway only accepted the webhook
  configuration when creating a brand-new session; reconnecting silently discarded it. It is
  now registered every time, plus a **Resync connection** button for numbers already linked.

### New

- **Why incoming messages were or weren't answered** — a panel in Diagnostics showing the last
  25 messages and what the bot decided for each: replied, no automation matched, bot paused,
  no text to match, or tried but nothing sent (with the gateway's own error).
- The gateway can call back over the **internal network** instead of the public URL, for
  container setups where the public route silently fails.
- **One command** syncs the gateway key from `.env` into the dashboard and proves it works,
  without the key ever being displayed.

### Security

- `.env` is now gitignored. It sits inside the repo on a server, so an untargeted `git add -A`
  there would have committed the gateway key and the database password.

### Upgrading

Run migrations (`013`, `014`). Contacts already saved under a LID correct themselves on the
next message; ones with no phone at all are best deleted and re-messaged.

---

## 1.2.0 — 23 August 2026

Send from a personal WhatsApp number, and run the whole platform on your own server.

### Personal number as a second channel

- A client can send from **their own WhatsApp number** instead of the Meta Cloud API, and use
  every module with it — Campaigns, Automations, Lead Qualifier and the Inbox.
- They link it **themselves by scanning a QR** (or an 8-character pairing code) in Settings.
  You never handle their phone, and they never see a URL or a token.
- **Paced sending is built in, not optional:** 15 messages, then a 3-minute pause, with one
  budget shared across every module so three of them can't each send a full batch.
- Campaigns on this channel send **plain text or an image** — no template approval, no 24-hour
  window. The Lead Qualifier takes a written outreach message the same way.
- Clients can **switch channel themselves** in Settings, with each direction offered only when
  it can actually send.

> Sending from a personal number uses a WhatsApp Web session, which is against WhatsApp's
> Terms; the number can be banned. The pacing reduces that risk — it does not remove it. The
> warning is shown before switching, not after.

### Self-hosting

- **One-command VPS installer** and an Arabic step-by-step guide.
- A **Docker + Traefik path** for servers already running something else on ports 80/443 — n8n
  keeps working untouched, and SSL is automatic.
- The send worker runs **as a container**, not a host cron entry — the VPS image ships without
  a cron daemon, so the crontab line was accepted and never ran.
- A **database migration guide** and a post-migration verifier.

### Fixed

- A setup guide destroyed the `.git` directory, making updates impossible without re-cloning.
- The gateway documentation pointed at an address that could never have worked from inside a
  container.

### Upgrading

Run migrations (`011`, `012`).

---

## 1.1.0 — 18 August 2026

The dashboard becomes a phone app, and the product gets its own identity.

- **Installable on iPhone and Android** (PWA) — works from the home screen, with an offline
  screen instead of a browser error.
- **Push notifications** when a customer replies, so you can take over from the Inbox.
- Rebranded to **REVENECT**, with a full palette pass.
- **Upload your own logo and app icon** — the icon is resized into every size the browser and
  phone need automatically.
- Responsive layout throughout: side drawer, bottom tab bar, and an Inbox that works on a
  phone. The flow canvas takes touch properly.

### Upgrading

Run migration `010`, then generate push keys once in Admin → Settings.

---

## 1.0.0 — 11 August 2026

The platform enters version control, and the send path gets three real fixes.

Everything listed under *Before version control* below arrived on this day as a single import.
What was **built** on the day is the fixes.

### Fixed

- **Bulk sends of image templates failed for most recipients.** The image was being re-fetched
  by Meta for every single message, which throttles under load. It is uploaded once now and
  reused for the whole campaign — this is what made large image sends reliable.
- **Any template with a media header failed outright.** Campaigns were sending incomplete
  template payloads, omitting the header, so Meta rejected them.
- **Failed sends were retried even when Meta had dropped them on purpose** — frequency caps,
  policy blocks. Resending hits the same wall, so those are now explained on the report
  instead.

### Also

- The AI conversation node, live human takeover, and a default-reply catch-all so a message
  matching nothing still gets an answer.

---

## Before version control

The platform arrived on 11 August already built, as one import — there is no commit history
for this period. What follows is reconstructed from the database migrations it came with,
which record the order it was built in.

### The campaign platform · migrations 001–004

- Multi-tenant core: **clients, users, roles and credits**, with per-client billing.
- **Contacts and lists**, with opt-in status tracked per contact.
- **Templates** synced from the client's WhatsApp Business account.
- **Campaigns** with per-message delivery state, and a worker that sends them in throttled
  parallel batches.
- Inbound **webhook** handling and delivery-status tracking.
- Clients could be onboarded **before** their WhatsApp number existed, and repeated failed
  logins were locked out.

### The automation engine · migrations 005–007

- **Flows, steps and runs** — the bot engine, with each conversation tracked as a run.
- A **visual canvas** for building flows, with node positions stored per flow.
- **Lead Qualifier**: leads imported from a Google Sheet, messaged, then qualified and
  **scored by an AI** against criteria the client writes.
- **AI Chat Agents** and lead **grading** — hot, warm, cold.
- **Collected data** per run, for reporting on what the conversation produced.

### The inbox · migration 008

- One **unified message log** across every module, so campaigns, automations and manual
  replies all appear in a single conversation thread per contact.

### Alongside it

- **AI Studio** — a standalone creative orchestrator: build a campaign, choose which AI does
  each step (ChatGPT or Claude for copy, GPT Image, Stable Diffusion or Claude for design,
  Kling for video), then download the assets or publish straight to Facebook and Instagram.
  Its own login, its own database, independent of the dashboard.
- The **Gildana marketing site**.

---

*Every release is verified by an automated suite that runs the real send, receive and billing
paths against local stand-ins for Meta, the WhatsApp gateway and Google. It stands at 402
checks.*
