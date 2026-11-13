import Link from "next/link";
import { SelectAll } from "@/components/auto-refresh";
import { PageHeader } from "@/components/shell";
import { TodoBulk, TodoButtons } from "@/components/todo-row";
import { db } from "@/lib/db";
import { money } from "@/lib/format";
import type { SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";

export const metadata = { title: "To-do" };

const KIND = { pause: "Pause", start: "Switch on", budget: "Change budget of" } as const;
const LEVEL: Record<string, string> = { CAMPAIGN: "campaign", ADSET: "ad set", AD: "ad" };
const STATUS_TONE: Record<string, string> = { applied: "bg-green-100 text-good", dismissed: "bg-slate-100 text-slate-600", failed: "bg-red-100 text-bad", undone: "bg-amber-100 text-amber-800" };

/** What an action does, in words. */
function describe(a: { kind: string; level: string; entityName: string; payload: unknown }, currency: string): string {
  const p = (a.payload ?? {}) as { from?: number; to?: number; percent?: number };
  const base = `${KIND[a.kind as keyof typeof KIND] ?? a.kind} ${LEVEL[a.level] ?? ""} “${a.entityName}”`;
  return a.kind === "budget" && p.to != null ? `${base}: ${money(p.from ?? 0, currency, 0)} → ${money(p.to, currency, 0)}/day (${p.percent && p.percent > 0 ? "+" : ""}${p.percent ?? 0}%)` : base;
}

/**
 * The to-do list: every change the audit and the rules suggest for the live
 * ad accounts. Nothing here has happened yet — a person applies or dismisses
 * each one. History shows what was applied, by whom, and can undo it.
 */
export default async function Actions({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("actions");
  const tab = sp.tab === "history" ? "history" : "open";
  const accounts = await db.adAccount.findMany({ where: { connection: { clientId: v.clientId }, ...accessWhere(v.access) }, select: { id: true, name: true, platform: true, currency: true } });
  const acc = new Map(accounts.map((a) => [a.id, a]));
  const where = { clientId: v.clientId, accountId: { in: accounts.map((a) => a.id) } };
  const [open, history, users] = await Promise.all([
    db.action.findMany({ where: { ...where, status: "proposed" }, orderBy: [{ priority: "desc" }, { createdAt: "desc" }], take: 300 }),
    tab === "history" ? db.action.findMany({ where: { ...where, status: { not: "proposed" } }, orderBy: { decidedAt: "desc" }, take: 200 }) : [],
    db.user.findMany({ where: { OR: [{ clientId: v.clientId }, { clientId: null }] }, select: { id: true, name: true, email: true } }),
  ]);
  const who = new Map(users.map((u) => [u.id, u.name || u.email]));
  const canApply = canEdit(v) && canFeature(v, "actions.apply");
  const byAccount = [...new Set(open.map((a) => a.accountId))];

  return (
    <>
      <PageHeader title="To-do" sub="Changes suggested for your ad accounts. Nothing happens until someone presses Apply.">
        {canFeature(v, "actions.rules") && <Link className="btn-ghost" href="/actions/rules">Automation rules</Link>}
      </PageHeader>
      <div className="mb-4 flex gap-4 border-b border-line">
        {([["open", `Open (${open.length})`], ["history", "History"]] as const).map(([k, label]) => (
          <Link key={k} href={k === "open" ? "/actions" : "/actions?tab=history"} className={`-mb-px border-b-2 px-1 pb-2 text-sm ${k === tab ? "border-accent font-semibold" : "border-transparent text-muted hover:text-ink"}`}>{label}</Link>
        ))}
      </div>

      {tab === "open" && (
        <>
          {!open.length && <div className="card text-sm text-muted">Nothing to do right now. The audit runs every day after the accounts sync, and your automation rules add their suggestions here.</div>}
          {open.length > 0 && <div className="mb-3 flex items-center gap-3 text-sm"><label className="flex items-center gap-1 text-muted"><SelectAll form="todo-bulk" /> all</label><TodoBulk canApply={canApply} /></div>}
          <div className="space-y-4">
            {byAccount.map((aid) => {
              const a = acc.get(aid)!;
              return (
                <section key={aid} className="card">
                  <h2 className="mb-2 flex items-center gap-2 font-semibold"><span className="h-2.5 w-2.5 rounded-full" style={{ background: PLATFORM_META[a.platform].color }} />{a.name}<span className="text-xs font-normal text-muted">{PLATFORM_META[a.platform].label} · {a.currency}</span></h2>
                  <table className="tbl">
                    <tbody>
                      {open.filter((x) => x.accountId === aid).map((x) => (
                        <tr key={x.id}>
                          <td className="!w-8">{canApply && <input type="checkbox" name="ids" value={x.id} form="todo-bulk" aria-label="Select" />}</td>
                          <td className="!whitespace-normal">
                            <div className="font-medium">{describe(x, a.currency)}</div>
                            <div className="text-xs text-muted">{x.reason}</div>
                          </td>
                          <td className="text-xs"><span className="pill bg-bg text-muted">{x.source.startsWith("rule:") ? "rule" : x.source}</span></td>
                          <td className="text-xs text-muted">{x.createdAt.toISOString().slice(0, 10)}</td>
                          <td><TodoButtons id={x.id} status={x.status} canApply={canApply} what={describe(x, a.currency)} /></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </section>
              );
            })}
          </div>
        </>
      )}

      {tab === "history" && (
        <div className="card overflow-x-auto">
          <table className="tbl">
            <thead><tr><th>When</th><th>Change</th><th>Account</th><th>Result</th><th>By</th><th /></tr></thead>
            <tbody>
              {history.map((x) => {
                const a = acc.get(x.accountId);
                return (
                  <tr key={x.id}>
                    <td className="text-xs">{x.decidedAt?.toISOString().slice(0, 16).replace("T", " ")}</td>
                    <td className="!whitespace-normal"><div>{describe(x, a?.currency ?? "USD")}</div><div className="text-xs text-muted">{x.error || x.reason}</div></td>
                    <td className="text-xs">{a?.name}</td>
                    <td><span className={`pill ${STATUS_TONE[x.status] ?? "bg-bg text-muted"}`}>{x.status}</span></td>
                    <td className="text-xs">{x.decidedById ? who.get(x.decidedById) ?? "—" : "—"}</td>
                    <td><TodoButtons id={x.id} status={x.status} canApply={canApply} what={describe(x, a?.currency ?? "USD")} /></td>
                  </tr>
                );
              })}
              {!history.length && <tr><td colSpan={6} className="text-muted">No changes yet.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
