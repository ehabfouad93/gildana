"use server";

import { revalidatePath } from "next/cache";
import { applyAction, dismissAction, undoAction, type ActionResult } from "@/lib/actions-engine";
import type { Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { STAGES } from "@/lib/funnel";
import { METRICS, PRESETS, type Condition, type RuleSpec } from "@/lib/optimise";
import { runAudit, runRules } from "@/lib/optimise-run";
import { canEdit, canFeature, requireModule } from "@/lib/tenancy";

/** Apply / dismiss / undo one suggestion from the to-do list. */
export async function decideAction(_: ActionResult | null, form: FormData): Promise<ActionResult> {
  const v = await requireModule("actions");
  const id = String(form.get("id"));
  const op = String(form.get("op"));
  // An unknown or missing choice does nothing — it must never fall through to a change.
  if (op !== "apply" && op !== "undo" && op !== "dismiss") return { ok: false, message: "Unknown action." };
  const r = op === "apply" ? await applyAction(v, id) : op === "undo" ? await undoAction(v, id) : await dismissAction(v, id);
  revalidatePath("/actions");
  return r;
}

/** Apply or dismiss every ticked suggestion, one after the other; stops reporting at the first failure but tries them all. */
export async function decideManyAction(_: ActionResult | null, form: FormData): Promise<ActionResult> {
  const v = await requireModule("actions");
  const ids = form.getAll("ids").map(String).slice(0, 100);
  if (!ids.length) return { ok: false, message: "Tick at least one suggestion." };
  const op = String(form.get("op"));
  if (op !== "apply" && op !== "dismiss") return { ok: false, message: "Unknown action." };
  const apply = op === "apply";
  let done = 0;
  const failed: string[] = [];
  for (const id of ids) {
    const r = apply ? await applyAction(v, id) : await dismissAction(v, id);
    if (r.ok) done++; else failed.push(r.message);
  }
  revalidatePath("/actions");
  return { ok: !failed.length, message: `${apply ? "Applied" : "Dismissed"} ${done} of ${ids.length}.${failed.length ? ` ${failed.length} failed: ${failed[0]}` : ""}` };
}

/** "Run audit now": the daily audit plus the rules, on demand. */
export async function runAuditAction(_: ActionResult | null): Promise<ActionResult> {
  const v = await requireModule("actions");
  if (!canEdit(v)) return { ok: false, message: "Read-only users cannot run the audit." };
  const n = (await runAudit(v.clientId)) + (await runRules(v.clientId));
  revalidatePath("/actions");
  return { ok: true, message: n ? `${n} new suggestion${n > 1 ? "s" : ""}.` : "Checked — nothing new to suggest." };
}

// ───────── automation rules ─────────

async function ruleEditor() {
  const v = await requireModule("actions");
  if (!canEdit(v) || !canFeature(v, "actions.rules")) throw new Error("You can't change automation rules.");
  return v;
}

const METRIC_KEYS = Object.keys(METRICS);

/** Adds a preset tactic or a custom rule. Rules only ever add suggestions to the to-do list. */
export async function saveRuleAction(_: ActionResult | null, form: FormData): Promise<ActionResult> {
  const v = await ruleEditor();
  const preset = String(form.get("preset") ?? "");
  let spec: RuleSpec;
  if (preset && PRESETS[preset]) {
    const { about: _about, ...rest } = PRESETS[preset];
    spec = rest;
  } else {
    const conditions: Condition[] = [];
    for (const i of [0, 1, 2]) {
      const metric = String(form.get(`metric${i}`) ?? "");
      const value = Number(form.get(`value${i}`));
      if (!METRIC_KEYS.includes(metric) || form.get(`value${i}`) === "" || !Number.isFinite(value)) continue;
      const op = String(form.get(`op${i}`));
      conditions.push({ metric: metric as Condition["metric"], op: op === "<=" ? "<=" : op === "=" ? "=" : ">=", value, of: form.get(`of${i}`) === "avg" ? "avg" : "number" });
    }
    if (!conditions.length) return { ok: false, message: "Add at least one condition." };
    const kind = String(form.get("kind"));
    const percent = Math.max(-20, Math.min(20, Number(form.get("percent")) || 20));
    spec = {
      name: String(form.get("name") ?? "").trim().slice(0, 80) || "My rule", level: form.get("level") === "AD" ? "AD" : "ADSET",
      windowDays: Math.min(30, Math.max(1, Math.round(Number(form.get("windowDays")) || 7))), conditions,
      action: kind === "start" ? { kind: "start" } : kind === "budget" ? { kind: "budget", percent } : { kind: "pause" },
    };
    if (spec.action.kind === "budget" && spec.level === "AD") return { ok: false, message: "Budgets belong to ad sets — choose “Ad sets” for a budget rule." };
  }
  const stages = form.getAll("stage").map(String).filter((s) => (STAGES as readonly string[]).includes(s));
  await db.rule.create({
    data: { clientId: v.clientId, name: spec.name, preset, level: spec.level, windowDays: spec.windowDays, conditions: spec.conditions as unknown as Prisma.InputJsonValue, action: spec.action as unknown as Prisma.InputJsonValue, scope: { stages } as Prisma.InputJsonValue, createdById: v.id },
  });
  revalidatePath("/actions/rules");
  return { ok: true, message: `Rule “${spec.name}” added. It runs once a day after the accounts sync and adds its suggestions to To-do.` };
}

export async function toggleRuleAction(form: FormData) {
  const v = await ruleEditor();
  const r = await db.rule.findFirst({ where: { id: String(form.get("id")), clientId: v.clientId } });
  if (r) await db.rule.update({ where: { id: r.id }, data: { enabled: !r.enabled } });
  revalidatePath("/actions/rules");
}

export async function deleteRuleAction(form: FormData) {
  const v = await ruleEditor();
  await db.rule.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId } });
  revalidatePath("/actions/rules");
}

export async function runRuleAction(form: FormData) {
  const v = await ruleEditor();
  await runRules(v.clientId, String(form.get("id")));
  revalidatePath("/actions/rules");
  revalidatePath("/actions");
}
