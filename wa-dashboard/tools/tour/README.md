# The walkthrough video

Records a narrated tour of the app by driving it in a real browser. Four files come out:

| File | Length | For |
|---|---|---|
| `revenect-tour-en` / `-ar` | ~4 min | a customer who just got their account — Help → Getting started |
| `revenect-promo-en` / `-ar` | ~90 s | a prospect on the public landing page |

Each is produced as `.webm` and `.mp4`. **Use the MP4.** WebM is what Playwright writes, but
some older iPhones will not play it, and that is a real slice of the audience here.

## Why this exists as code

The video shows today's interface. The next redesign dates it, and re-recording should not
mean rebuilding the whole thing from memory. Everything that decides what the video contains
is data: `scenes.py` is the choreography, `captions.json` is every word spoken. Changing a
sentence means editing one line in one file, in both languages, and running it again.

## Recording

Needs the local test rig: the app on `:8099`, the three mock servers, and a MySQL database
with the schema migrated. `record.py` checks and refuses with a clear message rather than
recording three minutes of an error page.

```sh
php tools/tour/seed_demo.php                        # the demo agency (DESTRUCTIVE, see below)
python3 tools/tour/record.py --cut tour  --lang en
python3 tools/tour/record.py --cut tour  --lang ar
python3 tools/tour/record.py --cut promo --lang en
python3 tools/tour/record.py --cut promo --lang ar
```

Output lands in `tools/tour/out/`. A take is about 100 seconds of wall clock for the promo
and about four minutes for the tour, because it runs at reading speed on purpose.

Two environment variables, if your rig differs: `TOUR_BASE` (default
`http://127.0.0.1:8099`) and `TOUR_CHROME` (the Chromium binary to record with).

**Do not restart the app server while a take is running.** The browser is driving it; killing
it mid-take produces a video of a connection error, and the take has to be redone.

## The demo data

`seed_demo.php` builds a fictional real-estate agency — Nile View Properties — with forty
contacts, tagged by area and warmth, an already-sent campaign whose per-message rows add up to
exactly the counts on the report, four half-finished inbox conversations, an automation with
real per-node drop-off, and fourteen scored leads.

Everything in it is invented. The names are made up, the numbers are constructed from a fixed
prefix so no run can land on a real subscriber, and the WhatsApp token is the literal string
`DEMO-NOT-A-REAL-TOKEN`. The recording runs against the mock Graph API on `:8790`, so no
request reaches Meta and no real account is ever touched.

**It deletes every client, which cascades.** It refuses to run unless the database host is
loopback, because the one thing that must never happen is someone running it against
production from the wrong shell.

## What the recorder fakes, and why

A recorded browser session does not look like a person using software. Three things are added
on top, injected with `add_init_script` so they survive every navigation:

- **A cursor.** Playwright's mouse is invisible to the recording. Without a synthetic one,
  clicks land with no visible cause and the video looks like the page changing by itself.
- **Movement.** `page.click()` teleports. Every click here glides the pointer across the
  screen first, so the eye can follow it to the thing being clicked.
- **Words.** Playwright records picture only — there is no audio track and no way to add one
  from here. Captions carry the narration instead.

## The Arabic font

The Arabic captions use **IBM Plex Sans Arabic**, embedded in `fonts/` and inlined as base64
at record time.

This is not a preference. The container has no decent Arabic face of its own — `fc-list
:lang=ar` returns DejaVu and FreeSerif, whose Arabic shaping is poor enough to look amateur in
something a customer watches. Plex Arabic is the Arabic sibling of the IBM Plex Sans the
landing page already loads, so the captions match the brand, and embedding rather than linking
means a take never depends on the network mid-recording.

If you move this to another machine, keep `fonts/`. `record.py` fails loudly if it is missing
rather than quietly rendering unshaped Arabic.

## Encoding

Playwright writes VP8/WebM — its bundled ffmpeg is built with `--disable-everything` plus
libvpx, so it can produce nothing else. `record.py` then transcodes to H.264/`yuv420p` with
`+faststart` using the *system* ffmpeg, which has to be installed separately
(`apt-get install ffmpeg`). Without it you get WebM only, and the script says so.

## Publishing

**Admin → Help Content** has two slots, and both take an upload or a link:

- **Intro video for clients** — the floating play button on every signed-in page, the top of
  Help, and the link inside every *How to use* panel.
- **Demo video on the public site** — the section under the landing page headline. Switched
  off, the section does not render at all.

Uploads land in `wa-dashboard/assets/media/`, which is gitignored: the videos are per-install
and too large to carry in git forever. The folder keeps the four most recent files and prunes
the rest, so re-recording does not slowly fill the disk.

If an upload is refused for size, PHP's `upload_max_filesize` is the cap — `wa-dashboard/.user.ini`
raises it to 128M, but the web server in front of PHP has its own limit that this repo cannot
change (`client_max_body_size` on nginx, default 1m; `LimitRequestBody` on Apache). The admin
page shows the limit that actually applies rather than the one we would like to enforce.
