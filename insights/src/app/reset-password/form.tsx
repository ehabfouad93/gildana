"use client";

import { useActionState } from "react";
import { resetPasswordWithTokenAction } from "@/app/actions/password";

export function ResetForm({ token }: { token: string }) {
  const [error, action, pending] = useActionState(resetPasswordWithTokenAction, null);
  return (
    <form action={action} className="space-y-4">
      <input type="hidden" name="token" value={token} />
      <div>
        <label className="label" htmlFor="password">New password</label>
        <input className="input" id="password" name="password" type="password" autoComplete="new-password" minLength={8} required />
      </div>
      <div>
        <label className="label" htmlFor="confirm">Repeat new password</label>
        <input className="input" id="confirm" name="confirm" type="password" autoComplete="new-password" minLength={8} required />
      </div>
      {error && <p className="text-sm text-bad">{error}</p>}
      <button className="btn w-full justify-center" disabled={pending}>{pending ? "Saving…" : "Save new password"}</button>
    </form>
  );
}
