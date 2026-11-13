import Link from "next/link";
import { redirect } from "next/navigation";
import { deleteRuleAction, runRuleAction, toggleRuleAction } from "@/app/actions/todo";
import { CustomRuleForm, PresetForm } from "@/components/rule-forms";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { STAGE_LABEL, type Stage } from "@/lib/funnel";
import { METRICS, PRESETS, type Condition } from "@/lib/optimise";
import { canEdit, canFeature, requireModule } from "@/lib/tenancy";

export const metadata = { title: "Automation rules" };

const OP = { ">=": "≥", "<=": "≤", "=": "=" } as const;
const say = (c: Condition) => `${METRICS[c.metric]} ${OP[c.op]} ${c.value}${c.of === "avg" ? " × average" : ""}`;

/** Rules that watch the accounts every day. They only add suggestions to To-do — a person applies them. */
export default async function Rules() {
  const v = await requireModule("actions");
  if (!canFeature(v, "actions.rules")) redirect("/actions");
  const edit = canEdit(v);
  const rules = await db.rule.findMany({ where: { clientId: v.clientId }, orderBy: { createdAt: "asc" } });
  const open = await db.action.groupBy({ by: ["source"], where: { clientId: v.clientId, status: "proposed", source: { startsWith: "rule:" } }, _count: true });
  const waiting = new Map(open.map((o) => [o.source.slice(5), o._count]));

  return (
    <>
      <PageHeader title="Automation rules" sub="Rules check your Meta accounts once a day and add their suggestions to To-do. They never change an account by themselves.">
        <Link className="btn-ghost" href="/actions">← To-do</Link>
      </PageHeader>
      <div className="card mb-4 overflow-x-auto">
        <table className="tbl">
          <thead><tr><th>Rule</th><th>When</th><th>Suggests</th><th>Stages</th><th>Last run</th><th>Waiting</th><th /></tr></thead>
          <tbody>
            {rules.map((r) => {
              const action = r.action as { kind: string; percent?: number };
              const stages = ((r.scope as { stages?: string[] })?.stages ?? []) as Stage[];
              return (
                <tr key={r.id} className={r.enabled ? "" : "opacity-50"}>
                  <td className="font-medium">{r.name}</td>
                  <td className="!whitespace-normal text-xs">{r.level === "AD" ? "Ads" : "Ad sets"}, last {r.windowDays} days: {(r.conditions as unknown as Condition[]).map(say).join(" and ")}</td>
                  <td className="text-xs">{action.kind === "budget" ? `Budget ${action.percent && action.percent > 0 ? "+" : ""}${action.percent ?? 20}%` : action.kind === "start" ? "Switch on" : "Pause"}</td>
                  <td className="text-xs">{stages.length ? stages.map((s) => STAGE_LABEL[s]).join(", ") : "All"}</td>
                  <td className="text-xs">{r.lastRunAt?.toISOString().slice(0, 16).replace("T", " ") ?? "not yet"}</td>
                  <td className="text-xs">{waiting.get(r.id) ? <Link className="text-accent underline" href="/actions">{waiting.get(r.id)} in To-do</Link> : "—"}</td>
                  <td>
                    {edit && (
                      <div className="flex justify-end gap-2 text-xs">
                        <form action={runRuleAction}><input type="hidden" name="id" value={r.id} /><button className="text-accent hover:underline">Run now</button></form>
                        <form action={toggleRuleAction}><input type="hidden" name="id" value={r.id} /><button className="text-muted hover:underline">{r.enabled ? "Switch off" : "Switch on"}</button></form>
                        <form action={deleteRuleAction}><input type="hidden" name="id" value={r.id} /><button className="text-bad hover:underline">Delete</button></form>
                      </div>
                    )}
                  </td>
                </tr>
              );
            })}
            {!rules.length && <tr><td colSpan={7} className="text-muted">No rules yet. Add a ready-made tactic below, or write your own.</td></tr>}
          </tbody>
        </table>
      </div>
      {edit && (
        <>
          <div className="card mb-4">
            <h2 className="mb-3 font-semibold">Ready-made tactics</h2>
            <div className="grid gap-3 md:grid-cols-2">
              {Object.entries(PRESETS).map(([k, p]) => <PresetForm key={k} preset={k} name={p.name} about={p.about} />)}
            </div>
          </div>
          <div className="card">
            <h2 className="mb-3 font-semibold">Your own rule</h2>
            <CustomRuleForm metrics={Object.entries(METRICS)} />
          </div>
        </>
      )}
    </>
  );
}
