"use client";

import { useActionState } from "react";
import { inviteUserAction } from "@/app/actions/saas";
import { CopyButton } from "@/components/copy";

/** Invite a client user by email; when email isn't set up, shows the link to send yourself (e.g. on WhatsApp). */
export function InviteForm({ clientId }: { clientId: string }) {
  const [msg, action, pending] = useActionState(inviteUserAction, null);
  const kind = msg ? msg.slice(0, msg.indexOf(":")) : "";
  const rest = msg ? msg.slice(msg.indexOf(":") + 1) : "";
  const [link, note] = kind === "link" ? rest.split("|") : ["", ""];
  return (
    <div>
      <form action={action} className="grid items-end gap-2 md:grid-cols-4">
        <input type="hidden" name="clientId" value={clientId} />
        <div><label className="label">Email</label><input className="input" name="email" type="email" required /></div>
        <div><label className="label">Name</label><input className="input" name="name" /></div>
        <div><label className="label">Role</label><select className="input" name="role"><option value="CLIENT">Client (can edit)</option><option value="VIEWER">Viewer (read-only)</option></select></div>
        <button className="btn" disabled={pending}>{pending ? "Inviting…" : "Send invitation"}</button>
      </form>
      {kind === "ok" && <p className="mt-2 text-sm text-good">{rest}</p>}
      {kind === "error" && <p className="mt-2 text-sm text-bad">{rest}</p>}
      {kind === "link" && (
        <div className="mt-2 text-sm">
          <p className="text-muted">{note || "Email isn't set up, so send this invitation link yourself (valid 7 days):"}</p>
          <div className="mt-1 flex gap-2"><input className="input !py-1 font-mono text-xs" readOnly value={link} /><CopyButton text={link} /></div>
        </div>
      )}
    </div>
  );
}
