"use client";

import { useEffect, useRef, useState } from "react";

type Props = {
  points: { x: number; y: number }[]; // x in % of page width, y in px from the top at `pageW`
  pageW: number;
  pageH: number;
  reach: { depth: number; pct: number }[];
  mode: "click" | "scroll";
  background: { kind: "image" | "iframe"; src: string } | null;
  radius?: number;
};

/**
 * Click / scroll heatmap over the page itself. The page renders at its real
 * device width and is scaled down to fit, so points line up with what the
 * visitor actually saw.
 */
export function Heatmap({ points, pageW, pageH, reach, mode, background, radius = 28 }: Props) {
  const wrap = useRef<HTMLDivElement>(null);
  const canvas = useRef<HTMLCanvasElement>(null);
  const [scale, setScale] = useState(1);
  const [opacity, setOpacity] = useState(0.75);

  useEffect(() => {
    const el = wrap.current;
    if (!el) return;
    const fit = () => setScale(Math.min(1, el.clientWidth / pageW));
    fit();
    const ro = new ResizeObserver(fit);
    ro.observe(el);
    return () => ro.disconnect();
  }, [pageW]);

  useEffect(() => {
    const c = canvas.current;
    if (!c || mode !== "click") return;
    c.width = pageW;
    c.height = pageH;
    const ctx = c.getContext("2d")!;
    ctx.clearRect(0, 0, pageW, pageH);
    // 1) accumulate intensity as alpha
    const a = Math.max(0.04, Math.min(0.35, 30 / Math.sqrt(points.length + 1)));
    for (const p of points) {
      const x = (p.x / 100) * pageW, y = p.y;
      const g = ctx.createRadialGradient(x, y, 0, x, y, radius);
      g.addColorStop(0, `rgba(0,0,0,${a})`);
      g.addColorStop(1, "rgba(0,0,0,0)");
      ctx.fillStyle = g;
      ctx.fillRect(x - radius, y - radius, radius * 2, radius * 2);
    }
    // 2) map alpha → colour ramp (blue → green → yellow → red)
    const img = ctx.getImageData(0, 0, pageW, pageH);
    const ramp = makeRamp();
    for (let i = 3; i < img.data.length; i += 4) {
      const v = img.data[i];
      if (!v) continue;
      const o = Math.min(255, v) * 4;
      img.data[i - 3] = ramp[o]; img.data[i - 2] = ramp[o + 1]; img.data[i - 1] = ramp[o + 2]; img.data[i] = Math.min(255, 60 + v * 1.6);
    }
    ctx.putImageData(img, 0, 0);
  }, [points, pageW, pageH, mode, radius]);

  return (
    <div>
      <div className="no-print mb-2 flex items-center gap-3 text-xs text-muted">
        <label className="flex items-center gap-1">Overlay <input type="range" min={0.1} max={1} step={0.05} value={opacity} onChange={(e) => setOpacity(Number(e.target.value))} /></label>
        <span>{points.length.toLocaleString()} points · page {pageW}×{pageH}px · shown at {Math.round(scale * 100)}%</span>
      </div>
      <div ref={wrap} className="w-full overflow-hidden rounded-lg border border-line bg-white">
        <div style={{ width: pageW, height: pageH * scale, position: "relative" }}>
          <div style={{ width: pageW, height: pageH, transform: `scale(${scale})`, transformOrigin: "0 0", position: "absolute", inset: 0 }}>
            {background?.kind === "image" && <img src={background.src} alt="" style={{ width: pageW, display: "block" }} />}
            {background?.kind === "iframe" && <iframe src={background.src} title="Page" scrolling="no" style={{ width: pageW, height: pageH, border: 0, pointerEvents: "none" }} sandbox="allow-same-origin allow-scripts" />}
            {!background && <div className="flex h-full items-start justify-center bg-slate-50 pt-20 text-slate-400">No page preview — capture a snapshot</div>}
            {mode === "click" && <canvas ref={canvas} style={{ position: "absolute", inset: 0, width: pageW, height: pageH, opacity }} />}
            {mode === "scroll" && (
              <div style={{ position: "absolute", inset: 0, opacity }}>
                {reach.slice(0, -1).map((r, i) => (
                  <div key={r.depth} style={{ position: "absolute", left: 0, right: 0, top: (r.depth / 100) * pageH, height: (5 / 100) * pageH, background: scrollColor(r.pct) }}>
                    {i % 2 === 0 && <span className="absolute end-3 top-1 rounded bg-black/70 px-2 py-0.5 text-[22px] font-semibold text-white">{Math.round(r.pct)}% reached</span>}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function makeRamp(): Uint8ClampedArray {
  const c = document.createElement("canvas");
  c.width = 256; c.height = 1;
  const ctx = c.getContext("2d")!;
  const g = ctx.createLinearGradient(0, 0, 256, 0);
  g.addColorStop(0.0, "#3b82f6"); g.addColorStop(0.35, "#22c55e"); g.addColorStop(0.6, "#facc15"); g.addColorStop(0.85, "#f97316"); g.addColorStop(1, "#dc2626");
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 256, 1);
  return ctx.getImageData(0, 0, 256, 1).data;
}

function scrollColor(pct: number): string {
  // red where most visitors are, fading to blue where few reach
  const h = Math.round((pct / 100) * 0 + (1 - pct / 100) * 220);
  return `hsla(${h}, 85%, 50%, 0.45)`;
}
