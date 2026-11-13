"use client";

import { startTransition, useActionState, useRef } from "react";
import { decideAction, decideManyAction } from "@/app/actions/todo";

/** Apply / Dismiss buttons for one suggestion; Undo for an applied one. */
export function TodoButtons({ id, status, canApply, what }: { id: string; status: string; canApply: boolean; what: string }) {
  const [res, action, pending] = useActionState(decideAction, null);
  // The choice is sent explicitly — never inferred from which button submitted a form.
  const send = (op: "apply" | "dismiss" | "undo", ask?: string) => {
    if (ask && !confirm(ask)) return;
    const fd = new FormData();
    fd.set("id", id);
    fd.set("op", op);
    startTransition(() => action(fd));
  };
  if (res?.ok) return <span className="text-xs text-good">✓ {res.message}</span>;
  if (!canApply) return null;
  return (
    <div className="flex flex-wrap items-center justify-end gap-2">
      {status === "proposed" && <button type="button" className="btn !py-1" disabled={pending} onClick={() => send("apply", `${what}\n\nThis changes the live ad account. Continue?`)}>{pending ? "…" : "Apply"}</button>}
      {status === "proposed" && <button type="button" className="btn-ghost !py-1" disabled={pending} onClick={() => send("dismiss")}>Dismiss</button>}
      {status === "applied" && <button type="button" className="btn-ghost !py-1" disabled={pending} onClick={() => send("undo", `Undo: ${what}?`)}>Undo</button>}
      {res && !res.ok && <span className="basis-full text-end text-xs text-bad">{res.message}</span>}
    </div>
  );
}

/** "Apply ticked" / "Dismiss ticked" for the open list (row checkboxes carry form="todo-bulk"). */
export function TodoBulk({ canApply }: { canApply: boolean }) {
  const [res, action, pending] = useActionState(decideManyAction, null);
  const form = useRef<HTMLFormElement>(null);
  if (!canApply) return null;
  const send = (op: "apply" | "dismiss", ask?: string) => {
    if (!form.current) return;
    const fd = new FormData(form.current);
    if (!fd.getAll("ids").length) { alert("Tick at least one suggestion first."); return; }
    if (ask && !confirm(ask)) return;
    fd.set("op", op);
    startTransition(() => action(fd));
  };
  return (
    <form id="todo-bulk" ref={form} onSubmit={(e) => e.preventDefault()} className="flex flex-wrap items-center gap-2">
      <button type="button" className="btn-ghost !py-1" disabled={pending} onClick={() => send("apply", "Apply every ticked suggestion? This changes the live ad accounts.")}>{pending ? "Working…" : "Apply ticked"}</button>
      <button type="button" className="btn-ghost !py-1" disabled={pending} onClick={() => send("dismiss")}>Dismiss ticked</button>
      {res && <span className={`text-xs ${res.ok ? "text-good" : "text-bad"}`}>{res.message}</span>}
    </form>
  );
}
