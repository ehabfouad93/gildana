"use client";

import { useActionState, useState } from "react";
import { requestClaimAction } from "@/app/actions/saas";

export type ShareInfo = { platform: string; label: string; agencyId: string; idLabel: string; steps: string[]; accountIdHelp: string };

/** "Share access with Gildana instead": per-platform steps and a request form. */
export function ShareAccess({ platforms, companies, defaultCompanyId }: { platforms: ShareInfo[]; companies: { id: string; name: string }[]; defaultCompanyId?: string }) {
  const [platform, setPlatform] = useState(platforms[0]?.platform ?? "");
  const [msg, action, pending] = useActionState(requestClaimAction, null);
  const info = platforms.find((p) => p.platform === platform);
  const [kind, text] = msg ? [msg.slice(0, msg.indexOf(":")), msg.slice(msg.indexOf(":") + 1)] : ["", ""];
  if (!platforms.length) return <p className="text-sm text-muted">Sharing access isn&apos;t available yet — use the Connect buttons.</p>;
  return (
    <div className="space-y-3 text-sm">
      <div className="flex flex-wrap gap-1">
        {platforms.map((p) => (
          <button key={p.platform} type="button" onClick={() => setPlatform(p.platform)} className={`pill !px-3 !py-1.5 ${p.platform === platform ? "bg-ink text-white" : "bg-bg text-muted"}`}>{p.label}</button>
        ))}
      </div>
      {info && (
        <>
          <ol className="list-decimal space-y-1 ps-5 text-muted">
            {info.steps.map((s, i) => <li key={i}>{s.split("{id}").map((part, k, arr) => <span key={k}>{part}{k < arr.length - 1 && <b className="text-ink">{info.agencyId || "(ask Gildana)"}</b>}</span>)}</li>)}
          </ol>
          <form action={action} className="grid gap-2 md:grid-cols-[1fr_200px_auto]">
            <input type="hidden" name="platform" value={info.platform} />
            <input className="input" name="accountId" placeholder={info.accountIdHelp} required />
            <select className="input" name="companyId" defaultValue={defaultCompanyId ?? companies[0]?.id ?? ""}>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            <button className="btn" disabled={pending}>{pending ? "Checking…" : "Add shared account"}</button>
          </form>
          {text && <p className={kind === "ok" ? "text-good" : "text-bad"}>{text}</p>}
        </>
      )}
    </div>
  );
}
