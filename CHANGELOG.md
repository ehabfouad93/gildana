# Revenect — Release history

Newest first. Every entry is written from what actually shipped, including the fixes.

Version numbers are ours: a **minor** bump (1.3 → 1.4) is a release with new capability, a
**patch** (1.4.0 → 1.4.1) is corrections only.

---

## 1.4.0 — 25 August 2026 · *latest*

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

Run migration (`010`). Generate push keys once in Admin → Settings.

---

## 1.0.0 — 11 August 2026

First release: the WhatsApp campaign and automation platform.

- **Campaigns** to opted-in contact lists, with full template support — header media,
  variables and dynamic buttons.
- **Automations**: a visual flow canvas with keyword, welcome and default triggers.
- **Lead Qualifier**: import leads from a Google Sheet, message them, and let an AI qualify and
  score the conversation.
- **Inbox** with live human takeover.
- Multi-tenant: clients, credits, roles and reports.

### Fixed in this release

- Bulk sends of image templates failed for most recipients. The image is now uploaded once and
  reused, instead of being re-fetched for every message.
- Campaigns sent incomplete template payloads, so any template with a media header failed.
- Failed sends were retried even when Meta had dropped them deliberately (frequency caps,
  policy) — those are now explained on the report rather than retried into the same wall.

---

*Every release is verified by an automated suite that runs the real send, receive and billing
paths against local stand-ins for Meta, the WhatsApp gateway and Google. It stands at 402
checks.*
