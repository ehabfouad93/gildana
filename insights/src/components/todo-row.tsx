"use client";

import { useActionState } from "react";
import { decideAction, decideManyAction } from "@/app/actions/todo";

/** Apply / Dismiss buttons for one suggestion; Undo for an applied one. */
export function TodoButtons({ id, status, canApply, what }: { id: string; status: string; canApply: boolean; what: string }) {
  const [res, action, pending] = useActionState(decideAction, null);
  if (res?.ok) return <span className="text-xs text-good">✓ {res.message}</span>;
  return (
    <form action={action} className="flex flex-wrap items-center justify-end gap-2">
      <input type="hidden" name="id" value={id} />
      {status === "proposed" && canApply && (
        <button name="op" value="apply" className="btn !py-1" disabled={pending}
          onClick={(e) => { if (!confirm(`${what}\n\nThis changes the live ad account. Continue?`)) e.preventDefault(); }}>{pending ? "…" : "Apply"}</button>
      )}
      {status === "proposed" && canApply && <button name="op" value="dismiss" className="btn-ghost !py-1" disabled={pending}>Dismiss</button>}
      {status === "applied" && canApply && (
        <button name="op" value="undo" className="btn-ghost !py-1" disabled={pending} onClick={(e) => { if (!confirm(`Undo: ${what}?`)) e.preventDefault(); }}>Undo</button>
      )}
      {res && !res.ok && <span className="basis-full text-end text-xs text-bad">{res.message}</span>}
    </form>
  );
}

/** "Apply ticked" / "Dismiss ticked" for the open list (checkboxes use form="todo-bulk"). */
export function TodoBulk({ canApply }: { canApply: boolean }) {
  const [res, action, pending] = useActionState(decideManyAction, null);
  if (!canApply) return null;
  return (
    <form id="todo-bulk" action={action} className="flex flex-wrap items-center gap-2">
      <button name="op" value="apply" className="btn-ghost !py-1" disabled={pending}
        onClick={(e) => { if (!confirm("Apply every ticked suggestion? This changes the live ad accounts.")) e.preventDefault(); }}>{pending ? "Working…" : "Apply ticked"}</button>
      <button name="op" value="dismiss" className="btn-ghost !py-1" disabled={pending}>Dismiss ticked</button>
      {res && <span className={`text-xs ${res.ok ? "text-good" : "text-bad"}`}>{res.message}</span>}
    </form>
  );
}
