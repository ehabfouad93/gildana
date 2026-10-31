"use client";

import { useActionState } from "react";
import { signupAction } from "@/app/actions/saas";

export function SignupForm() {
  const [error, action, pending] = useActionState(signupAction, null);
  return (
    <form action={action} className="space-y-3">
      <div><label className="label" htmlFor="name">Your name</label><input className="input" id="name" name="name" autoComplete="name" required /></div>
      <div><label className="label" htmlFor="workspace">Business or agency name</label><input className="input" id="workspace" name="workspace" required /></div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="isAgency" /> I&apos;m an agency managing several brands</label>
      <div><label className="label" htmlFor="email">Work email</label><input className="input" id="email" name="email" type="email" autoComplete="username" required /></div>
      <div className="grid grid-cols-2 gap-2">
        <div><label className="label" htmlFor="password">Password</label><input className="input" id="password" name="password" type="password" autoComplete="new-password" minLength={8} required /></div>
        <div><label className="label" htmlFor="confirm">Repeat</label><input className="input" id="confirm" name="confirm" type="password" autoComplete="new-password" minLength={8} required /></div>
      </div>
      <div><label className="label" htmlFor="currency">Report currency</label>
        <select className="input" id="currency" name="currency" defaultValue="USD">{["USD", "EGP", "SAR", "AED", "KWD", "QAR", "EUR", "GBP"].map((c) => <option key={c}>{c}</option>)}</select></div>
      {error && <p className="text-sm text-bad">{error}</p>}
      <button className="btn w-full justify-center" disabled={pending}>{pending ? "Creating…" : "Create workspace"}</button>
    </form>
  );
}
