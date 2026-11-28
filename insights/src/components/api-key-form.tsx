"use client";

import { useActionState } from "react";
import { createApiKeyAction, type KeyResult } from "@/app/actions/customers";

export function ApiKeyForm({ scopes }: { scopes: { key: string; label: string }[] }) {
  const [state, action, pending] = useActionState<KeyResult | null, FormData>(createApiKeyAction, null);
  return (
    <form action={action} className="space-y-3 text-sm">
      <label className="block max-w-sm"><span className="mb-1 block text-xs text-muted">Name</span><input className="input" name="name" placeholder="Revenect CRM" required /></label>
      <div className="flex flex-wrap gap-4">
        {scopes.map((s) => <label key={s.key} className="flex items-center gap-2"><input type="checkbox" name={`scope_${s.key}`} defaultChecked={s.key !== "pii"} /> {s.label}</label>)}
      </div>
      <button className="btn" disabled={pending}>{pending ? "Creating…" : "Create key"}</button>
      {state && <p className={state.ok ? "text-good" : "text-bad"}>{state.message}</p>}
      {state?.key && <pre className="select-all overflow-x-auto rounded-lg bg-bg p-3 font-mono text-xs">{state.key}</pre>}
    </form>
  );
}
