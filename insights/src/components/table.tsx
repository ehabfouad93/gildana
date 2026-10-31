"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

import type { Col, Fmt, Row } from "./table-types";

export type { Col, Fmt, Row };


const BADGE: Record<string, string> = {
  Reported: "bg-green-100 text-good", Tested: "bg-blue-100 text-blue-800", Measured: "bg-emerald-100 text-emerald-800",
  Estimated: "bg-amber-100 text-amber-800", "Ad-set ranking": "bg-slate-100 text-slate-600", Setting: "bg-slate-100 text-slate-600",
};

function f(v: Row[string], fmt: Fmt = "text", currency: string): string {
  if (v == null || v === "") return "—";
  if (typeof v === "string") return v;
  if (!Number.isFinite(v)) return "—";
  switch (fmt) {
    case "money": return new Intl.NumberFormat("en-US", { style: "currency", currency, maximumFractionDigits: 0 }).format(v);
    case "money2": return new Intl.NumberFormat("en-US", { style: "currency", currency, maximumFractionDigits: 2 }).format(v);
    case "compact": return new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(v);
    case "pct": return `${v.toFixed(2)}%`;
    case "ratio": return `${v.toFixed(2)}x`;
    case "dec": return v.toFixed(1);
    case "num": return new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 }).format(v);
    default: return String(v);
  }
}

/**
 * Sortable, searchable table. Rows arrive as plain data from the server;
 * a column with `href` links each cell to row[href].
 */
export function DataTable({ cols, rows, currency = "USD", initialSort, search = true, csvName, limit = 200 }: {
  cols: Col[]; rows: Row[]; currency?: string; initialSort?: string; search?: boolean; csvName?: string; limit?: number;
}) {
  const [sort, setSort] = useState<{ k: string; dir: 1 | -1 }>({ k: initialSort ?? "", dir: -1 });
  const [q, setQ] = useState("");
  const [showAll, setShowAll] = useState(false);

  const view = useMemo(() => {
    let r = rows;
    if (q) {
      const needle = q.toLowerCase();
      r = r.filter((row) => cols.some((c) => typeof row[c.key] === "string" && (row[c.key] as string).toLowerCase().includes(needle)));
    }
    if (sort.k) {
      r = [...r].sort((a, b) => {
        const x = a[sort.k], y = b[sort.k];
        if (x == null) return 1;
        if (y == null) return -1;
        return (typeof x === "number" && typeof y === "number" ? x - y : String(x).localeCompare(String(y))) * sort.dir;
      });
    }
    return r;
  }, [rows, q, sort, cols]);

  const csv = () => {
    const esc = (s: unknown) => `"${String(s ?? "").replace(/"/g, '""')}"`;
    const body = [cols.map((c) => esc(c.label)).join(","), ...view.map((r) => cols.map((c) => esc(r[c.key])).join(","))].join("\n");
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob(["﻿" + body], { type: "text/csv;charset=utf-8" }));
    a.download = `${csvName ?? "export"}.csv`;
    a.click();
  };

  const shown = showAll ? view : view.slice(0, limit);

  return (
    <div>
      {(search || csvName) && (
        <div className="no-print mb-2 flex items-center gap-2">
          {search && <input className="input max-w-xs" placeholder="Search…" value={q} onChange={(e) => setQ(e.target.value)} />}
          <span className="text-xs text-muted">{view.length} rows</span>
          {csvName && <button type="button" className="btn-ghost ms-auto" onClick={csv}>Export CSV</button>}
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="tbl">
          <thead>
            <tr>
              {cols.map((c) => (
                <th key={c.key} className={c.fmt && c.fmt !== "text" && c.fmt !== "badge" ? "num" : ""}>
                  <button type="button" className="uppercase" onClick={() => setSort((s) => ({ k: c.key, dir: s.k === c.key ? (s.dir === 1 ? -1 : 1) : -1 }))}>
                    {c.label}{sort.k === c.key ? (sort.dir === 1 ? " ↑" : " ↓") : ""}
                  </button>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {shown.map((r, i) => (
              <tr key={i}>
                {cols.map((c) => {
                  const val = f(r[c.key], c.fmt === "badge" ? "text" : c.fmt, currency);
                  const href = c.href ? (r[c.href] as string | undefined) : undefined;
                  return (
                    <td key={c.key} className={`${c.fmt && c.fmt !== "text" && c.fmt !== "badge" ? "num" : ""} ${c.wrap ? "!whitespace-normal min-w-56" : ""}`}>
                      {c.fmt === "badge" ? <span className={`pill ${BADGE[val] ?? "bg-bg text-muted"}`}>{val}</span>
                        : href ? <Link href={href} className="font-medium text-accent hover:underline">{val}</Link> : val}
                      {c.sub && r[c.sub] ? <div className="text-xs text-muted">{String(r[c.sub])}</div> : null}
                    </td>
                  );
                })}
              </tr>
            ))}
            {!shown.length && <tr><td colSpan={cols.length} className="py-8 text-center text-muted">No rows.</td></tr>}
          </tbody>
        </table>
      </div>
      {view.length > limit && !showAll && (
        <button type="button" className="btn-ghost mt-2" onClick={() => setShowAll(true)}>Show all {view.length}</button>
      )}
    </div>
  );
}
