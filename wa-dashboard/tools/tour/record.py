#!/usr/bin/env python3
"""
Records the walkthrough video by driving the real app in a real browser.

    python3 tools/tour/record.py --cut tour  --lang ar
    python3 tools/tour/record.py --cut promo --lang en

Playwright drives Chromium and records the viewport to a .webm. Three things have to be
faked, because a recorded browser session does not look like a person using software:

  * THE CURSOR.  Playwright's mouse is invisible to the recording — clicks would land with
    no visible cause and the video would look like the page changing by itself. A synthetic
    cursor follows the real mouse events, and every click leaves a ripple.
  * MOVEMENT.  page.click() teleports. Every click here moves the pointer across the screen
    in steps first, so the eye can follow it to the thing being clicked.
  * WORDS.  Playwright records picture only, with no audio track. Captions carry the
    narration instead, in whichever language was asked for.

The overlay is injected with add_init_script, so it survives every navigation rather than
having to be re-added on each page.

Requires the local recording rig — the app on :8099 with the demo seed loaded. It checks and
says so plainly rather than recording three minutes of an error page.
"""
import argparse, base64, json, os, pathlib, shutil, subprocess, sys, time, urllib.request

HERE = pathlib.Path(__file__).resolve().parent
BASE = os.environ.get("TOUR_BASE", "http://127.0.0.1:8099")
CHROME = os.environ.get("TOUR_CHROME", "/opt/pw-browsers/chromium-1194/chrome-linux/chrome")
SIZE = {"width": 1280, "height": 720}

DEMO_USER = "layla@nileview.example"
DEMO_PASS = "tour-demo-2026"


# ── the overlay ────────────────────────────────────────────────────────────────────────────
def overlay_script(lang: str) -> str:
    """CSS + JS injected into every document: cursor, caption bar, chapter card.

    The Arabic face is embedded as base64 rather than linked. This container has no decent
    Arabic font of its own (fc-list :lang=ar gives DejaVu, whose shaping is poor), and a
    webfont fetched at record time would put the network in the middle of a take. Embedding
    makes the recording deterministic and keeps the captions in the brand's own typeface —
    IBM Plex Sans Arabic is the Arabic sibling of the IBM Plex Sans the site already uses.
    """
    faces = ""
    for weight, fn in ((400, "PlexArabic-400.ttf"), (600, "PlexArabic-600.ttf")):
        p = HERE / "fonts" / fn
        if not p.exists():
            sys.exit(f"Missing {p}. See tools/tour/README.md — the Arabic font is not optional "
                     f"for the Arabic cut; without it the captions render unshaped.")
        b64 = base64.b64encode(p.read_bytes()).decode()
        faces += ("@font-face{font-family:'PlexAr';font-style:normal;font-weight:%d;"
                  "src:url(data:font/ttf;base64,%s) format('truetype');}" % (weight, b64))

    rtl = "true" if lang == "ar" else "false"
    fam = ("'PlexAr','IBM Plex Sans',sans-serif" if lang == "ar"
           else "'IBM Plex Sans','Helvetica Neue',Helvetica,Arial,sans-serif")

    return r"""
(() => {
  const CSS = `%FACES%
  #tcur{position:fixed;z-index:2147483647;width:22px;height:22px;margin:-11px 0 0 -11px;
    border-radius:50%;pointer-events:none;left:-100px;top:-100px;
    background:rgba(20,26,40,.55);border:2px solid #fff;
    box-shadow:0 2px 10px rgba(0,0,0,.35);transition:transform .08s ease}
  #tcur.down{transform:scale(.8)}
  .trip{position:fixed;z-index:2147483646;width:14px;height:14px;margin:-7px 0 0 -7px;
    border-radius:50%;pointer-events:none;border:2px solid rgba(37,211,102,.9);
    animation:trip .55s ease-out forwards}
  @keyframes trip{to{transform:scale(4.5);opacity:0}}
  #tcap{position:fixed;z-index:2147483645;left:50%;transform:translateX(-50%);
    bottom:34px;max-width:min(900px,86vw);padding:13px 22px;border-radius:12px;
    background:rgba(16,20,32,.93);color:#fff;font:600 19px/1.5 %FAM%;
    text-align:center;pointer-events:none;opacity:0;transition:opacity .28s ease;
    box-shadow:0 10px 34px rgba(0,0,0,.32);direction:%DIR%;
    text-wrap:balance;-webkit-font-smoothing:antialiased}
  #tcap.on{opacity:1}
  #tchap{position:fixed;z-index:2147483644;inset:0;display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:10px;pointer-events:none;
    background:#0F1420;color:#fff;opacity:0;transition:opacity .4s ease;direction:%DIR%}
  #tchap.on{opacity:1}
  #tchap b{font:700 44px/1.15 %FAM%;letter-spacing:-.02em;text-align:center;max-width:78vw}
  #tchap span{font:400 19px/1.5 %FAM%;color:#9AA3B8;text-align:center;max-width:62vw}
  #tchap i{display:block;width:44px;height:3px;border-radius:2px;background:#25D366;margin-bottom:6px}
  `;

  function boot(){
    if (document.getElementById('tcur')) return;
    const st = document.createElement('style'); st.textContent = CSS;
    document.head.appendChild(st);
    const mk = (id, html) => { const e = document.createElement('div'); e.id = id;
                               if (html) e.innerHTML = html; document.body.appendChild(e); return e; };
    const cur  = mk('tcur');
    const cap  = mk('tcap');
    const chap = mk('tchap', '<i></i><b></b><span></span>');

    addEventListener('mousemove', e => { cur.style.left = e.clientX+'px'; cur.style.top = e.clientY+'px'; }, true);
    addEventListener('mousedown', e => {
      cur.classList.add('down');
      const r = document.createElement('div'); r.className = 'trip';
      r.style.left = e.clientX+'px'; r.style.top = e.clientY+'px';
      document.body.appendChild(r); setTimeout(() => r.remove(), 600);
    }, true);
    addEventListener('mouseup', () => cur.classList.remove('down'), true);

    window.__tour = {
      cap: t => { if (!t) { cap.classList.remove('on'); return; }
                  cap.textContent = t; cap.classList.add('on'); },
      chapter: (title, sub) => { chap.querySelector('b').textContent = title;
                                 chap.querySelector('span').textContent = sub || '';
                                 chap.classList.add('on'); },
      unchapter: () => chap.classList.remove('on'),
    };
  }
  if (document.body) boot(); else addEventListener('DOMContentLoaded', boot);
})();
""".replace("%FACES%", faces).replace("%FAM%", fam).replace("%DIR%", "rtl" if lang == "ar" else "ltr")


# ── the driver the scenes talk to ──────────────────────────────────────────────────────────
class Tour:
    """Every verb a scene can use. Deliberately small — a scene should read as choreography."""

    def __init__(self, page, captions, lang, speed):
        self.pg, self.caps, self.lang, self.speed = page, captions, lang, speed

    # -- pacing -------------------------------------------------------------------------
    def hold(self, ms=900):
        """Wait, scaled by the cut's speed. The promo cut runs the same beats faster."""
        self.pg.wait_for_timeout(int(ms * self.speed))

    def text(self, key):
        c = self.caps.get(key)
        if c is None:
            raise KeyError(f"caption '{key}' is missing from captions.json")
        return c[self.lang]

    # -- narration ----------------------------------------------------------------------
    def say(self, key, ms=2600):
        """Show a caption and hold long enough to read it — longer if the line is long."""
        t = self.text(key)
        self.pg.evaluate("t => window.__tour && window.__tour.cap(t)", t)
        # ~13 characters a second, floored at the caller's minimum: a long line gets longer.
        self.hold(max(ms, len(t) * 78))

    def quiet(self):
        self.pg.evaluate("() => window.__tour && window.__tour.cap('')")

    def chapter(self, key, ms=2000):
        title, sub = self.text(key), self.caps[key].get("sub", {}).get(self.lang, "")
        self.quiet()
        self.pg.evaluate("a => window.__tour && window.__tour.chapter(a[0], a[1])", [title, sub])
        self.hold(ms)
        self.pg.evaluate("() => window.__tour && window.__tour.unchapter()")
        self.hold(500)

    # -- movement -----------------------------------------------------------------------
    def goto(self, path):
        self.pg.goto(BASE + path, wait_until="domcontentloaded")
        self.pg.wait_for_timeout(400)

    def show(self, sel, index=0):
        """Bring an element on screen WITHOUT scrolling the page sideways.

        scroll_into_view_if_needed() also scrolls horizontally, which on a wide page (the flow
        canvas) slid the whole layout left and cut the logo and the sidebar labels off the
        frame. inline:'nearest' keeps the horizontal position exactly where it was.
        """
        self.pg.locator(sel).nth(index).evaluate(
            "el => el.scrollIntoView({block:'center', inline:'nearest', behavior:'smooth'})")
        self.pg.evaluate("() => window.scrollTo({left: 0})")
        self.pg.wait_for_timeout(700)

    def move_to(self, sel, index=0):
        """Glide the pointer to an element so the eye can follow it there."""
        el = self.pg.locator(sel).nth(index)
        self.show(sel, index)
        self.pg.wait_for_timeout(220)
        box = el.bounding_box()
        if not box:
            raise RuntimeError(f"cannot see {sel!r} — the page changed under the tour")
        x, y = box["x"] + box["width"] / 2, box["y"] + box["height"] / 2
        self.pg.mouse.move(x, y, steps=26)
        self.hold(280)
        return x, y

    def click(self, sel, index=0, settle=650):
        x, y = self.move_to(sel, index)
        self.pg.mouse.down(); self.pg.wait_for_timeout(70); self.pg.mouse.up()
        self.hold(settle)

    def type(self, sel, value, delay=85):
        self.move_to(sel)
        self.pg.mouse.down(); self.pg.wait_for_timeout(60); self.pg.mouse.up()
        self.pg.locator(sel).first.type(value, delay=delay)
        self.hold(350)

    def choose(self, sel, contains):
        """Pick the option whose label contains `contains`.

        Not by index: the option order is whatever the query returned, so index 1 quietly
        selected the dullest template and a five-person list. Naming what we want keeps the
        video matching the story the caption is telling.
        """
        self.move_to(sel)
        val = self.pg.eval_on_selector_all(
            f"{sel} option",
            "(opts, want) => { const o = opts.find(o => o.textContent.includes(want));"
            "                  return o ? o.value : null; }", contains)
        if val is None:
            raise RuntimeError(f"no option matching {contains!r} in {sel}")
        self.pg.select_option(sel, value=val)
        self.hold(900)

    def scroll(self, y, ms=700):
        self.pg.mouse.wheel(0, y)
        self.hold(ms)

    def sign_in(self):
        self.goto("/login.php")
        self.pg.fill('input[name="email"]', DEMO_USER)
        self.pg.fill('input[name="password"]', DEMO_PASS)
        self.pg.click('button[type="submit"]')
        self.pg.wait_for_load_state("domcontentloaded")
        self.pg.wait_for_timeout(700)


# ── preflight ──────────────────────────────────────────────────────────────────────────────
def preflight():
    try:
        with urllib.request.urlopen(BASE + "/login.php", timeout=4) as r:
            if r.status >= 400:
                sys.exit(f"{BASE}/login.php returned {r.status}. Is the rig healthy?")
    except Exception as e:
        sys.exit(f"Cannot reach the app at {BASE} ({e}).\n"
                 f"The tour needs the local recording rig running. See tools/tour/README.md.")
    if not pathlib.Path(CHROME).exists():
        sys.exit(f"No Chromium at {CHROME}. Set TOUR_CHROME to the browser to record with.")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--cut",  choices=["tour", "promo"], required=True)
    ap.add_argument("--lang", choices=["en", "ar"], required=True)
    ap.add_argument("--out",  default=str(HERE / "out"))
    args = ap.parse_args()

    preflight()
    from playwright.sync_api import sync_playwright
    sys.path.insert(0, str(HERE))
    from scenes import SCENES, SPEED

    captions = json.loads((HERE / "captions.json").read_text(encoding="utf8"))
    outdir = pathlib.Path(args.out); outdir.mkdir(parents=True, exist_ok=True)
    raw = outdir / f".raw-{args.cut}-{args.lang}"
    if raw.exists():
        shutil.rmtree(raw)

    scenes = [s for s in SCENES if args.cut in s["cuts"]]
    print(f"recording {args.cut}/{args.lang}: {len(scenes)} scenes")

    started = time.time()
    with sync_playwright() as p:
        br = p.chromium.launch(executable_path=CHROME, args=["--hide-scrollbars"])
        ctx = br.new_context(viewport=SIZE, record_video_dir=str(raw), record_video_size=SIZE,
                             device_scale_factor=1, reduced_motion="no-preference")
        ctx.add_init_script(overlay_script(args.lang))
        pg = ctx.new_page()
        errs = []
        pg.on("pageerror", lambda e: errs.append(str(e)))

        t = Tour(pg, captions, args.lang, SPEED[args.cut])
        for s in scenes:
            print(f"  · {s['id']}")
            s["run"](t)

        t.quiet()
        t.hold(700)
        video = pg.video
        ctx.close()                      # the video is only written on close
        br.close()
        src = pathlib.Path(video.path())

    dest = outdir / f"revenect-{args.cut}-{args.lang}.webm"
    shutil.move(str(src), dest)
    shutil.rmtree(raw, ignore_errors=True)
    print(f"  → {dest}  ({dest.stat().st_size/1e6:.1f} MB, {time.time()-started:.0f}s wall)")
    if errs:
        print(f"  ! {len(errs)} page errors during the take: {errs[:3]}")

    # WebM alone is a problem on older iOS Safari, and this is a customer-facing video, so
    # transcode to H.264 as well when a full ffmpeg is available. Playwright's bundled one is
    # VP8-only, so this is deliberately the system binary.
    if shutil.which("ffmpeg"):
        mp4 = dest.with_suffix(".mp4")
        subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(dest),
                        "-c:v", "libx264", "-preset", "slow", "-crf", "23",
                        "-pix_fmt", "yuv420p", "-movflags", "+faststart", str(mp4)], check=True)
        print(f"  → {mp4}  ({mp4.stat().st_size/1e6:.1f} MB)")
    else:
        print("  ! ffmpeg not found — WebM only, which older iOS Safari will not play.")


if __name__ == "__main__":
    main()
