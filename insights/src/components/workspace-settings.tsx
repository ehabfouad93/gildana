"use client";

import { useActionState } from "react";
import { updateClientAction } from "@/app/actions/admin";
import { ResultNote } from "@/components/user-manager";
import { CURRENCIES } from "@/lib/currencies";

export type WorkspaceValues = {
  id: string; name: string; reportingCurrency: string; campaignCurrency: string; timezone: string; logoUrl: string;
  defaultDays: number; isAgency: boolean; active: boolean;
};

const RANGES = [7, 14, 30, 90, 180, 365];

/** Workspace settings form; `gildana` adds the switches only Gildana may change. */
export function WorkspaceSettings({ c, gildana }: { c: WorkspaceValues; gildana: boolean }) {
  const [msg, action, pending] = useActionState(updateClientAction, null);
  return (
    <form action={action} className="space-y-3">
      <input type="hidden" name="id" value={c.id} />
      <div><label className="label">Workspace name</label><input className="input" name="name" defaultValue={c.name} required maxLength={160} /></div>
      <div className="grid gap-3 sm:grid-cols-3">
        <div>
          <label className="label">Reporting currency</label>
          <select className="input" name="currency" defaultValue={c.reportingCurrency}>
            {!CURRENCIES.some((x) => x.code === c.reportingCurrency) && <option value={c.reportingCurrency}>{c.reportingCurrency}</option>}
            {CURRENCIES.map((x) => <option key={x.code} value={x.code}>{x.code} — {x.name}</option>)}
          </select>
        </div>
        <div><label className="label">Timezone</label><input className="input" name="timezone" defaultValue={c.timezone} list="tz-list" /></div>
        <div>
          <label className="label">Reports open on</label>
          <select className="input" name="defaultDays" defaultValue={String(RANGES.includes(c.defaultDays) ? c.defaultDays : 30)}>
            {RANGES.map((d) => <option key={d} value={d}>Last {d} days</option>)}
          </select>
        </div>
      </div>
      <div>
        <div className="label">Campaign pages show money in</div>
        <div className="space-y-1 text-sm">
          <label className="flex items-start gap-2"><input type="radio" name="campaignCurrency" value="account" defaultChecked={c.campaignCurrency !== "reporting"} className="mt-1" /><span>Each ad account&apos;s real currency <span className="text-muted">— exactly what the platform charged, matching Ads Manager</span></span></label>
          <label className="flex items-start gap-2"><input type="radio" name="campaignCurrency" value="reporting" defaultChecked={c.campaignCurrency === "reporting"} className="mt-1" /><span>The reporting currency <span className="text-muted">— converted at the daily exchange rate, so campaigns compare side by side</span></span></label>
        </div>
        <p className="mt-1 text-xs text-muted">Overview, spend, performance and reports always add accounts up in the reporting currency. Anyone can switch on a campaign page.</p>
      </div>
      <datalist id="tz-list">{["Africa/Cairo", "Asia/Riyadh", "Asia/Dubai", "Asia/Kuwait", "Asia/Qatar", "Europe/London", "Europe/Berlin", "America/New_York", "UTC"].map((t) => <option key={t} value={t} />)}</datalist>
      <div><label className="label">Logo URL (shown in the menu and on shared reports)</label><input className="input" name="logoUrl" type="url" defaultValue={c.logoUrl} placeholder="https://…/logo.png" /></div>
      <div className="flex flex-wrap gap-4 text-sm">
        <label className="flex items-center gap-1"><input type="checkbox" name="isAgency" defaultChecked={c.isAgency} /> Agency (manages several companies)</label>
        {gildana && <label className="flex items-center gap-1"><input type="checkbox" name="active" defaultChecked={c.active} /> Workspace active</label>}
      </div>
      <button className="btn" disabled={pending}>{pending ? "Saving…" : "Save settings"}</button>
      <ResultNote msg={msg} />
    </form>
  );
}
