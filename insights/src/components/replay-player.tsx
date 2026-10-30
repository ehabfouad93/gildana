"use client";

import { useEffect, useRef, useState } from "react";

const CSS = "/vendor/rrweb-player.css";
const JS = "/vendor/rrweb-player.js";

function load(src: string, css = false) {
  return new Promise<void>((ok, fail) => {
    if (document.querySelector(`[data-src="${src}"]`)) return ok();
    const el = css ? Object.assign(document.createElement("link"), { rel: "stylesheet", href: src }) : Object.assign(document.createElement("script"), { src, async: true });
    el.setAttribute("data-src", src);
    el.onload = () => ok();
    el.onerror = () => fail(new Error(`Could not load ${src}`));
    document.head.appendChild(el);
  });
}

/** Plays a recorded session with rrweb-player (self-hosted under /vendor), loaded on demand. */
export function ReplayPlayer({ url }: { url: string }) {
  const el = useRef<HTMLDivElement>(null);
  const [state, setState] = useState<"loading" | "empty" | "ready" | "error">("loading");
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [events] = await Promise.all([fetch(url).then((r) => r.json()), load(CSS, true), load(JS)]);
        if (cancelled) return;
        if (!Array.isArray(events) || events.length < 2) return setState("empty");
        const Player = (window as any).rrwebPlayer;
        el.current!.innerHTML = "";
        new Player({ target: el.current, props: { events, width: Math.min(1024, el.current!.clientWidth), height: 560, autoPlay: false, showController: true } });
        setState("ready");
      } catch { if (!cancelled) setState("error"); }
    })();
    return () => { cancelled = true; };
  }, [url]);
  return (
    <div>
      {state === "loading" && <p className="text-sm text-muted">Loading recording…</p>}
      {state === "empty" && <p className="text-sm text-muted">This session has no replay. Enable recording in the site setup (and re-copy the tracking code) to capture future sessions.</p>}
      {state === "error" && <p className="text-sm text-bad">The replay could not be loaded.</p>}
      <div ref={el} />
    </div>
  );
}
