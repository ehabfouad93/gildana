"use client";

import { useActionState } from "react";
import { changePasswordAction } from "@/app/actions/password";

export function ChangePasswordForm() {
  const [msg, action, pending] = useActionState(changePasswordAction, "");
  const [kind, text] = msg ? [msg.slice(0, msg.indexOf(":")), msg.slice(msg.indexOf(":") + 1)] : ["", ""];
  return (
    <form action={action} className="space-y-3" key={kind === "ok" ? msg : undefined}>
      <div><label className="label" htmlFor="current">Current password</label><input className="input" id="current" name="current" type="password" autoComplete="current-password" required /></div>
      <div><label className="label" htmlFor="password">New password</label><input className="input" id="password" name="password" type="password" autoComplete="new-password" minLength={8} required /></div>
      <div><label className="label" htmlFor="confirm">Repeat new password</label><input className="input" id="confirm" name="confirm" type="password" autoComplete="new-password" minLength={8} required /></div>
      {text && <p className={`text-sm ${kind === "ok" ? "text-good" : "text-bad"}`}>{text}</p>}
      <button className="btn" disabled={pending}>{pending ? "Saving…" : "Change password"}</button>
    </form>
  );
}
