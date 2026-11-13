"use client";

import { useActionState, useState, useTransition } from "react";
import { launchApprovalAction, sendForApprovalAction, type ApprovalResult } from "@/app/actions/approvals";
import { CopyButton } from "@/components/copy";

/** "Send for approval" next to a create button: stores the launch and shows the link for the client. */
export function SendForApproval({ kind, payload, disabled }: { kind: "split_campaign" | "mixer" | "grid"; payload: () => Record<string, unknown>; disabled?: boolean }) {
  const [res, setRes] = useState<ApprovalResult | null>(null);
  const [pending, go] = useTransition();
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <button type="button" className="btn-ghost" disabled={pending || disabled} onClick={() => go(async () => setRes(await sendForApprovalAction(kind, payload())))}>{pending ? "Saving…" : "Send for client approval"}</button>
      {res && !res.ok && <span className="text-sm text-bad">{res.message}</span>}
      {res?.ok && res.link && (
        <span className="flex basis-full flex-wrap items-center gap-2 text-sm">
          <span className="text-good">✓ {res.message}</span>
          <input className="input !w-96 !py-1 font-mono text-xs" readOnly value={res.link} /><CopyButton text={res.link} />
        </span>
      )}
    </span>
  );
}

/** "Launch now" for an approval the client accepted. */
export function LaunchApproval({ id, title }: { id: string; title: string }) {
  const [res, action, pending] = useActionState(launchApprovalAction, null);
  if (res?.ok) return <span className="text-xs text-good">✓ {res.message}</span>;
  return (
    <form action={action} onSubmit={(e) => { if (!confirm(`Launch “${title}” now, exactly as the client approved it?`)) e.preventDefault(); }} className="flex flex-wrap items-center justify-end gap-2">
      <input type="hidden" name="id" value={id} />
      <button className="btn !py-1" disabled={pending}>{pending ? "Launching…" : "Launch now"}</button>
      {res && !res.ok && <span className="basis-full text-end text-xs text-bad">{res.message}</span>}
    </form>
  );
}
