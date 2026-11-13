"use client";

import { useActionState, useState } from "react";
import { runAuditAction, saveRuleAction } from "@/app/actions/todo";

/** "Run audit now" on the To-do page. */
export function RunAuditButton() {
  const [res, action, pending] = useActionState(runAuditAction, null);
  return (
    <form action={action} className="flex items-center gap-2">
      {res && <span className={`text-xs ${res.ok ? "text-good" : "text-bad"}`}>{res.message}</span>}
      <button className="btn" disabled={pending}>{pending ? "Auditing…" : "↻ Run audit now"}</button>
    </form>
  );
}

const STAGE_OPTIONS: [string, string][] = [["prospecting", "Prospecting"], ["reengagement", "Re-engagement"], ["retargeting", "Retargeting"], ["retention", "Retention"]];

function Stages() {
  return (
    <div>
      <div className="label">Only these funnel stages (none ticked = all)</div>
      <div className="flex flex-wrap gap-3 text-sm">{STAGE_OPTIONS.map(([v, l]) => <label key={v} className="flex items-center gap-1"><input type="checkbox" name="stage" value={v} />{l}</label>)}</div>
    </div>
  );
}

/** Adds one of the ready-made tactics. */
export function PresetForm({ preset, name, about }: { preset: string; name: string; about: string }) {
  const [res, action, pending] = useActionState(saveRuleAction, null);
  return (
    <form action={action} className="rounded-lg border border-line p-3">
      <input type="hidden" name="preset" value={preset} />
      <div className="font-medium">{name}</div>
      <p className="mb-2 mt-1 text-xs text-muted">{about}</p>
      <Stages />
      <div className="mt-2 flex items-center gap-2"><button className="btn-ghost !py-1" disabled={pending}>{pending ? "Adding…" : "Add this rule"}</button>{res && <span className={`text-xs ${res.ok ? "text-good" : "text-bad"}`}>{res.ok ? "Added." : res.message}</span>}</div>
    </form>
  );
}

/** A custom rule: up to three conditions that must all hold, then one suggestion. */
export function CustomRuleForm({ metrics }: { metrics: [string, string][] }) {
  const [res, action, pending] = useActionState(saveRuleAction, null);
  const [kind, setKind] = useState("pause");
  return (
    <form action={action} className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-3">
        <div><label className="label">Rule name</label><input className="input" name="name" maxLength={80} required placeholder="e.g. Pause expensive ads" /></div>
        <div><label className="label">Look at</label><select className="input" name="level"><option value="ADSET">Ad sets</option><option value="AD">Ads</option></select></div>
        <div><label className="label">Over the last (days)</label><input className="input" name="windowDays" type="number" min="1" max="30" defaultValue="7" /></div>
      </div>
      <div>
        <div className="label">When all of these are true</div>
        <div className="space-y-2">
          {[0, 1, 2].map((i) => (
            <div key={i} className="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_2fr]">
              <select className="input" name={`metric${i}`} defaultValue={i === 0 ? "cpr" : ""}><option value="">{i === 0 ? "" : "— no more —"}</option>{metrics.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
              <select className="input" name={`op${i}`}><option value=">=">is at least</option><option value="<=">is at most</option><option value="=">equals</option></select>
              <input className="input" name={`value${i}`} type="number" step="any" placeholder="value" />
              <select className="input" name={`of${i}`}><option value="number">exactly this number</option><option value="avg">× the account&apos;s average</option></select>
            </div>
          ))}
        </div>
        <p className="mt-1 text-xs text-muted">“× the account&apos;s average” compares with the account&apos;s own usual value — e.g. cost per result is at least 1.3 × average. For spend it means multiples of the usual cost per result.</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        <div><label className="label">Then suggest</label>
          <select className="input" name="kind" value={kind} onChange={(e) => setKind(e.target.value)}><option value="pause">Pause it</option><option value="start">Switch it back on (paused items)</option><option value="budget">Change its daily budget</option></select></div>
        {kind === "budget" && <div><label className="label">Budget change % (−20 to +20)</label><input className="input" name="percent" type="number" min="-20" max="20" defaultValue="20" /></div>}
      </div>
      <Stages />
      <div className="flex items-center gap-2"><button className="btn" disabled={pending}>{pending ? "Saving…" : "Add rule"}</button>{res && <span className={`text-sm ${res.ok ? "text-good" : "text-bad"}`}>{res.message}</span>}</div>
    </form>
  );
}
