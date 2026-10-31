"use client";

import { useActionState } from "react";
import { forgotPasswordAction } from "@/app/actions/password";

export function ForgotForm() {
  const [msg, action, pending] = useActionState(forgotPasswordAction, null);
  if (msg) return <p className="rounded-lg bg-green-50 p-3 text-sm text-good">{msg}</p>;
  return (
    <form action={action} className="space-y-4">
      <div>
        <label className="label" htmlFor="email">Email</label>
        <input className="input" id="email" name="email" type="email" autoComplete="username" required />
      </div>
      <button className="btn w-full justify-center" disabled={pending}>{pending ? "Sending…" : "Send reset link"}</button>
    </form>
  );
}
