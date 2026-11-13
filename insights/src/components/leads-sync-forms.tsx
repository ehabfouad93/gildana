"use client";

import { useActionState } from "react";
import { addDestinationAction, testDestinationAction } from "@/app/actions/leads-sync";
import { CopyButton } from "@/components/copy";

export function AddDestination({ companies, mustPick }: { companies: { id: string; name: string }[]; mustPick: boolean }) {
  const [res, action, pending] = useActionState(addDestinationAction, null);
  return (
    <form action={action} className="space-y-3">
      <div className="grid gap-3 md:grid-cols-[1fr_2fr_1fr]">
        <div><label className="label">Name</label><input className="input" name="name" required maxLength={80} placeholder="e.g. CRM, Leads sheet" /></div>
        <div><label className="label">Address that receives the leads</label><input className="input" name="url" type="url" required placeholder="https://…" /></div>
        <div><label className="label">Leads of</label>
          <select className="input" name="companyId">{!mustPick && <option value="">All companies</option>}{companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></div>
      </div>
      <button className="btn" disabled={pending}>{pending ? "Adding…" : "Add destination"}</button>
      {res && !res.ok && <p className="text-sm text-bad">{res.message}</p>}
      {res?.ok && (
        <div className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm">
          <p className="text-good">✓ {res.message}</p>
          {res.secret && <><p className="mt-2">Signing secret — shown only now. Your receiver can use it to check that a request really comes from here (header <code>X-Gildana-Signature</code>).</p>
            <div className="mt-1 flex gap-2"><input className="input !py-1 font-mono text-xs" readOnly value={res.secret} /><CopyButton text={res.secret} /></div></>}
        </div>
      )}
    </form>
  );
}

export function TestDestination({ id }: { id: string }) {
  const [res, action, pending] = useActionState(testDestinationAction, null);
  return (
    <form action={action} className="inline-flex items-center gap-2">
      <input type="hidden" name="id" value={id} />
      <button className="text-accent hover:underline" disabled={pending}>{pending ? "Sending…" : "Send a test"}</button>
      {res && <span className={res.ok ? "text-good" : "text-bad"}>{res.message}</span>}
    </form>
  );
}
