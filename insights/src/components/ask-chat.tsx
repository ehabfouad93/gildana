"use client";

import { useRef, useState, useTransition } from "react";
import { askDataAction, type Turn } from "@/app/actions/ask";
import { Markdown } from "@/components/markdown";

const PERIODS: [string, string][] = [["7", "Last 7 days"], ["14", "Last 14 days"], ["30", "Last 30 days"], ["90", "Last 90 days"]];

/** Chat about the workspace's own numbers. Each question is answered from a data pack built on the server for this user. */
export function AskChat({ companies, examples, defaultDays }: { companies: { id: string; name: string }[]; examples: string[]; defaultDays: string }) {
  const [turns, setTurns] = useState<(Turn & { by?: string; error?: boolean })[]>([]);
  const [q, setQ] = useState("");
  const [days, setDays] = useState(defaultDays);
  const [company, setCompany] = useState("");
  const [pending, start] = useTransition();
  const end = useRef<HTMLDivElement>(null);

  const ask = (text: string) => {
    const question = text.trim();
    if (!question || pending) return;
    const history = turns.filter((t) => !t.error).map(({ role, text }) => ({ role, text }));
    setTurns((t) => [...t, { role: "user", text: question }]);
    setQ("");
    start(async () => {
      const r = await askDataAction(question, history, { days, company });
      setTurns((t) => [...t, { role: "assistant", text: r.text, by: r.by, error: !r.ok }]);
      setTimeout(() => end.current?.scrollIntoView({ behavior: "smooth" }), 50);
    });
  };

  return (
    <div className="mx-auto max-w-4xl">
      <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted">Answering about</span>
        <select className="input w-auto !py-1" value={days} onChange={(e) => setDays(e.target.value)} aria-label="Period">{PERIODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        {companies.length > 1 && (
          <select className="input w-auto !py-1" value={company} onChange={(e) => setCompany(e.target.value)} aria-label="Company"><option value="">All companies</option>{companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
        )}
        <span className="text-xs text-muted">(compared with the period before)</span>
        {turns.length > 0 && <button type="button" className="ms-auto text-xs text-muted hover:underline" onClick={() => setTurns([])}>New conversation</button>}
      </div>

      {!turns.length && (
        <div className="card mb-3">
          <p className="mb-2 text-sm text-muted">Ask in English or Arabic. The answer comes only from your own numbers for the chosen period. For example:</p>
          <div className="flex flex-wrap gap-2">{examples.map((e) => <button key={e} type="button" className="pill cursor-pointer bg-bg !px-3 !py-1.5 text-ink hover:bg-line" onClick={() => ask(e)}>{e}</button>)}</div>
        </div>
      )}

      <div className="space-y-3">
        {turns.map((t, i) => (
          <div key={i} className={t.role === "user" ? "ms-auto max-w-[85%] rounded-2xl bg-accent px-4 py-2 text-sm text-white" : `card text-sm ${t.error ? "border-red-200 text-bad" : ""}`} dir="auto">
            {t.role === "user" ? t.text : <><Markdown text={t.text} />{t.by && <div className="mt-2 text-[11px] text-muted">Written by {t.by} from your data · check important numbers on the report pages.</div>}</>}
          </div>
        ))}
        {pending && <div className="card text-sm text-muted">Reading your numbers…</div>}
        <div ref={end} />
      </div>

      <form className="sticky bottom-3 mt-4 flex gap-2 rounded-xl border border-line bg-panel p-2 shadow-sm" onSubmit={(e) => { e.preventDefault(); ask(q); }}>
        <input className="input border-0 !shadow-none" dir="auto" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ask about spend, leads, campaigns, creatives, audiences…" maxLength={1000} autoFocus />
        <button className="btn" disabled={pending || !q.trim()}>{pending ? "…" : "Ask"}</button>
      </form>
    </div>
  );
}
