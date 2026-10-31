"use client";

import { useActionState } from "react";
import { connectMetaSystemUserAction } from "@/app/actions/saas";

/** Gildana's Business Manager system-user token: a login that never expires and never depends on one person. */
export function SystemUserForm() {
  const [msg, action, pending] = useActionState(connectMetaSystemUserAction, null);
  const [kind, text] = msg ? [msg.slice(0, msg.indexOf(":")), msg.slice(msg.indexOf(":") + 1)] : ["", ""];
  return (
    <details className="mt-3 text-sm">
      <summary className="cursor-pointer text-accent">Recommended: use a Business Manager system user instead (token never expires)</summary>
      <ol className="mt-2 list-decimal space-y-1 ps-5 text-xs text-muted">
        <li>Business Settings → Users → System users → Add → role Admin.</li>
        <li>Assign assets: all ad accounts (including partner-shared ones), with “View performance”.</li>
        <li>Generate new token → choose this app → tick ads_read, business_management, leads_retrieval → never expires → copy it here.</li>
      </ol>
      <form action={action} className="mt-2 flex gap-2">
        <input className="input" name="token" type="password" placeholder="System user access token" autoComplete="off" required />
        <button className="btn" disabled={pending}>{pending ? "Checking…" : "Connect"}</button>
      </form>
      {text && <p className={`mt-1 text-xs ${kind === "ok" ? "text-good" : "text-bad"}`}>{text}</p>}
    </details>
  );
}
