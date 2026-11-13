"use server";

import { revalidatePath } from "next/cache";
import { applyAction, dismissAction, undoAction, type ActionResult } from "@/lib/actions-engine";
import { requireModule } from "@/lib/tenancy";

/** Apply / dismiss / undo one suggestion from the to-do list. */
export async function decideAction(_: ActionResult | null, form: FormData): Promise<ActionResult> {
  const v = await requireModule("actions");
  const id = String(form.get("id"));
  const op = String(form.get("op"));
  const r = op === "apply" ? await applyAction(v, id) : op === "undo" ? await undoAction(v, id) : await dismissAction(v, id);
  revalidatePath("/actions");
  return r;
}

/** Apply or dismiss every ticked suggestion, one after the other; stops reporting at the first failure but tries them all. */
export async function decideManyAction(_: ActionResult | null, form: FormData): Promise<ActionResult> {
  const v = await requireModule("actions");
  const ids = form.getAll("ids").map(String).slice(0, 100);
  if (!ids.length) return { ok: false, message: "Tick at least one suggestion." };
  const apply = form.get("op") === "apply";
  let done = 0;
  const failed: string[] = [];
  for (const id of ids) {
    const r = apply ? await applyAction(v, id) : await dismissAction(v, id);
    if (r.ok) done++; else failed.push(r.message);
  }
  revalidatePath("/actions");
  return { ok: !failed.length, message: `${apply ? "Applied" : "Dismissed"} ${done} of ${ids.length}.${failed.length ? ` ${failed.length} failed: ${failed[0]}` : ""}` };
}
