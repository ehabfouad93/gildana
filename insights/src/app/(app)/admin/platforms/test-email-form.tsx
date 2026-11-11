"use client";

import { useActionState } from "react";
import { sendTestEmailAction } from "@/app/actions/saas";

export function TestEmailForm({ defaultTo }: { defaultTo: string }) {
  const [msg, action, pending] = useActionState(sendTestEmailAction, null);
  const [kind, text] = msg ? [msg.slice(0, msg.indexOf(":")), msg.slice(msg.indexOf(":") + 1)] : ["", ""];
  return (
    <div>
      <form action={action} className="flex flex-wrap gap-2">
        <input className="input max-w-xs" name="to" type="email" defaultValue={defaultTo} aria-label="Send test to" />
        <button className="btn-ghost" disabled={pending}>{pending ? "Sending…" : "Send test email"}</button>
      </form>
      {text && <p className={`mt-2 text-sm ${kind === "ok" ? "text-good" : "text-bad"}`}>{text}</p>}
    </div>
  );
}
