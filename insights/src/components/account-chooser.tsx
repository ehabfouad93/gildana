"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState, useTransition } from "react";
import { addAccountByIdAction, saveChosenAccountsAction } from "@/app/actions/accounts";

export type ChoiceRow = { externalId: string; name: string; currency: string; status: string; source: string; added: boolean; reachable: boolean };

/** Tick the ad accounts this login should bring into the dashboard; untick to remove. */
export function AccountChooser({ connectionId, rows, initial, next, platformLabel }: { connectionId: string; rows: ChoiceRow[]; initial: string[]; next: string; platformLabel: string }) {
  const router = useRouter();
  const [ticked, setTicked] = useState(() => new Set(initial));
  const [q, setQ] = useState("");
  const [byId, setById] = useState("");
  const [msg, setMsg] = useState<{ ok: boolean; message: string } | null>(null);
  const [pending, go] = useTransition();
  const shown = rows.filter((r) => !q || `${r.name} ${r.externalId} ${r.source}`.toLowerCase().includes(q.toLowerCase()));
  const groups = useMemo(() => {
    const order = (s: string) => (s === "chosen" ? 0 : s === "in the dashboard" ? 2 : 1);
    return [...new Set(shown.map((r) => r.source))].sort((a, b) => order(a) - order(b) || a.localeCompare(b));
  }, [shown]);
  const removing = rows.filter((r) => r.added && !ticked.has(r.externalId));
  const adding = rows.filter((r) => !r.added && ticked.has(r.externalId));
  const flip = (ids: string[], on: boolean) => setTicked((s) => { const n = new Set(s); ids.forEach((id) => (on ? n.add(id) : n.delete(id))); return n; });
  const title = (s: string) => (s === "chosen" ? `Chosen in ${platformLabel}'s window (or your own accounts)` : s === "in the dashboard" ? "In the dashboard, added by number or no longer listed by this login" : `Through the business “${s}”`);

  return (
    <div className="space-y-4">
      <div className="card flex flex-wrap items-center gap-3">
        <input className="input max-w-sm" placeholder="Search by name or number…" value={q} onChange={(e) => setQ(e.target.value)} />
        <span className="text-sm text-muted">{ticked.size} of {rows.length} ticked{adding.length ? ` · ${adding.length} to add` : ""}{removing.length ? ` · ${removing.length} to remove` : ""}</span>
        <div className="ms-auto flex gap-2">
          <button type="button" className="btn" disabled={pending}
            onClick={() => {
              if (removing.length && !confirm(`Remove ${removing.length} account${removing.length > 1 ? "s" : ""} from the dashboard? Their synced numbers here are deleted (nothing changes on ${platformLabel}).\n\n${removing.slice(0, 10).map((r) => r.name).join("\n")}${removing.length > 10 ? "\n…" : ""}`)) return;
              go(async () => { const r = await saveChosenAccountsAction(connectionId, [...ticked]); setMsg(r); if (r.ok) router.push(next); });
            }}>{pending ? "Saving…" : "Save choice"}</button>
          <Link className="btn-ghost" href={next}>Cancel</Link>
        </div>
      </div>
      {msg && !msg.ok && <p className="text-sm text-bad">{msg.message}</p>}

      {groups.map((g) => {
        const list = shown.filter((r) => r.source === g);
        const allOn = list.every((r) => ticked.has(r.externalId));
        return (
          <section key={g} className="card">
            <div className="mb-2 flex flex-wrap items-center gap-3">
              <h2 className="font-semibold">{title(g)}</h2>
              <span className="text-xs text-muted">{list.length} account{list.length === 1 ? "" : "s"}</span>
              <button type="button" className="ms-auto text-xs text-accent hover:underline" onClick={() => flip(list.map((r) => r.externalId), !allOn)}>{allOn ? "Untick all" : "Tick all"}</button>
            </div>
            <div className="grid gap-1 sm:grid-cols-2 xl:grid-cols-3">
              {list.map((r) => (
                <label key={r.externalId} className={`flex cursor-pointer items-start gap-2 rounded-lg border p-2 text-sm ${ticked.has(r.externalId) ? "border-accent bg-accent/5" : "border-line"}`}>
                  <input type="checkbox" className="mt-1" checked={ticked.has(r.externalId)} onChange={(e) => flip([r.externalId], e.target.checked)} />
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{r.name}</span>
                    <span className="block text-xs text-muted">{r.externalId} · {r.currency}{r.status && r.status !== "active" ? <span className="text-amber-700"> · {r.status}</span> : null}{r.added ? <span className="text-good"> · in the dashboard</span> : null}</span>
                  </span>
                </label>
              ))}
            </div>
          </section>
        );
      })}
      {!rows.length && <div className="card text-sm text-muted">{platformLabel} returned no ad accounts for this login.</div>}

      <div className="card space-y-2">
        <h2 className="font-semibold">An account is missing?</h2>
        <p className="text-sm text-muted">The list shows every ad account the Facebook user of this login can reach. If one isn&apos;t here, that user has no access to it: add them to the ad account (Business Settings → Ad accounts → Add people), or reconnect and tick it in {platformLabel}&apos;s window. You can also try adding it by its number:</p>
        <div className="flex flex-wrap gap-2">
          <input className="input max-w-xs" placeholder="Ad account number, e.g. 1234567890" value={byId} onChange={(e) => setById(e.target.value)} />
          <button type="button" className="btn-ghost" disabled={pending || !byId.trim()} onClick={() => go(async () => { const r = await addAccountByIdAction(connectionId, byId); setMsg(r); if (r.ok) { setById(""); router.refresh(); } })}>Check and add</button>
        </div>
        {msg && <p className={`text-sm ${msg.ok ? "text-good" : "text-bad"}`}>{msg.message}</p>}
      </div>
    </div>
  );
}
