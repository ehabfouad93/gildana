/** Server-side ECharts option builders. Plain data only — no functions — so they cross the RSC boundary. */

export const PALETTE = ["#c4973a", "#2563eb", "#16a34a", "#db2777", "#0e7490", "#ca8a04", "#7c3aed", "#ea580c", "#64748b"];

const AXIS = { axisLine: { lineStyle: { color: "#e5e7eb" } }, axisLabel: { color: "#6b7280", fontSize: 11 }, splitLine: { lineStyle: { color: "#f1f5f9" } } };

export function lineOption(dates: string[], series: { name: string; data: (number | null)[]; axis?: 0 | 1; type?: "line" | "bar"; color?: string }[], yNames: [string, string?] = [""]) {
  return {
    color: PALETTE,
    tooltip: { trigger: "axis" },
    legend: { top: 0, textStyle: { color: "#6b7280" } },
    grid: { left: 8, right: 8, top: 36, bottom: 8, containLabel: true },
    xAxis: { type: "category", data: dates.map((d) => d.slice(5)), ...AXIS, splitLine: { show: false } },
    yAxis: [
      { type: "value", name: yNames[0], ...AXIS },
      ...(yNames[1] !== undefined ? [{ type: "value", name: yNames[1], ...AXIS, splitLine: { show: false } }] : []),
    ],
    series: series.map((s) => ({
      name: s.name, type: s.type ?? "line", data: s.data.map((v) => (v == null ? null : Math.round(v * 100) / 100)),
      yAxisIndex: s.axis ?? 0, smooth: true, showSymbol: false, ...(s.color ? { itemStyle: { color: s.color } } : {}),
      ...(s.type === "bar" ? { barMaxWidth: 18, itemStyle: { color: s.color, borderRadius: [3, 3, 0, 0] } } : { areaStyle: s.axis ? undefined : { opacity: 0.08 } }),
    })),
  };
}

export function donutOption(items: { name: string; value: number; color?: string }[]) {
  return {
    tooltip: { trigger: "item", valueFormatter: undefined },
    legend: { bottom: 0, textStyle: { color: "#6b7280" } },
    series: [{
      type: "pie", radius: ["55%", "80%"], center: ["50%", "45%"], avoidLabelOverlap: true, label: { show: false },
      data: items.map((i, k) => ({ name: i.name, value: Math.round(i.value * 100) / 100, itemStyle: { color: i.color ?? PALETTE[k % PALETTE.length] } })),
    }],
  };
}

export function barOption(labels: string[], series: { name: string; data: (number | null)[]; color?: string }[], horizontal = false) {
  const cat = { type: "category", data: labels, ...AXIS, splitLine: { show: false } };
  const val = { type: "value", ...AXIS };
  return {
    color: PALETTE,
    tooltip: { trigger: "axis", axisPointer: { type: "shadow" } },
    legend: series.length > 1 ? { top: 0, textStyle: { color: "#6b7280" } } : undefined,
    grid: { left: 8, right: 16, top: series.length > 1 ? 32 : 12, bottom: 8, containLabel: true },
    xAxis: horizontal ? val : cat,
    yAxis: horizontal ? { ...cat, inverse: true } : val,
    series: series.map((s) => ({ name: s.name, type: "bar", data: s.data.map((v) => (v == null ? null : Math.round(v * 100) / 100)), barMaxWidth: 22, itemStyle: { color: s.color, borderRadius: 3 } })),
  };
}

export function heatOption(xs: string[], ys: string[], cells: [number, number, number][], name: string) {
  const max = Math.max(1, ...cells.map((c) => c[2]));
  return {
    tooltip: { position: "top" },
    grid: { left: 8, right: 8, top: 8, bottom: 48, containLabel: true },
    xAxis: { type: "category", data: xs, ...AXIS, splitArea: { show: true } },
    yAxis: { type: "category", data: ys, ...AXIS, splitArea: { show: true } },
    visualMap: { min: 0, max, calculable: true, orient: "horizontal", left: "center", bottom: 0, inRange: { color: ["#fdf6e9", "#c4973a", "#7c4a03"] }, textStyle: { color: "#6b7280" } },
    series: [{ name, type: "heatmap", data: cells.map(([x, y, v]) => [x, y, Math.round(v * 100) / 100]), label: { show: true, fontSize: 10 } }],
  };
}
