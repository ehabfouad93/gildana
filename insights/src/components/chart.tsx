"use client";

import { useEffect, useRef } from "react";
import * as echarts from "echarts";

/** ECharts host. Options are built on the server (src/components/charts.ts) and must be plain JSON. */
export function Chart({ option, height = 280, className = "" }: { option: echarts.EChartsCoreOption; height?: number; className?: string }) {
  const el = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!el.current) return;
    const c = echarts.init(el.current, undefined, { renderer: "svg" });
    c.setOption(option);
    const ro = new ResizeObserver(() => c.resize());
    ro.observe(el.current);
    return () => { ro.disconnect(); c.dispose(); };
  }, [option]);
  return <div ref={el} style={{ height }} className={`w-full ${className}`} />;
}
