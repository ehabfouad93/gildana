/**
 * Funnel stage of an ad set, read from who it targets:
 *   prospecting   — new people: interests, lookalikes, broad
 *   reengagement  — people who engaged with the page, Instagram, videos or lead forms
 *   retargeting   — website / app visitors and other custom audiences
 *   retention     — existing customers
 * A person can override the detected stage per ad set.
 */

export const STAGES = ["prospecting", "reengagement", "retargeting", "retention"] as const;
export type Stage = (typeof STAGES)[number];
export const STAGE_LABEL: Record<Stage, string> = { prospecting: "Prospecting", reengagement: "Re-engagement", retargeting: "Retargeting", retention: "Retention" };

type Item = { type: string; value: string; exclude?: boolean | null };

const LOOKALIKE = /look.?alike|\blal\b|مشابه/i;
const CUSTOMERS = /purchas|customer|buyer|client|order|subscriber|عملاء|مشتر/i;
const ENGAGED = /engag|video|view|watch|page|instagram|\big\b|facebook|lead ?form|form open|messag|تفاعل|فيديو/i;

/** Detects the stage from an ad set's included targeting. Pure; unit-tested. */
export function detectStage(items: Item[]): Stage {
  const audiences = items.filter((i) => !i.exclude && (i.type === "audience" || i.type === "lookalike"));
  const custom = audiences.filter((a) => a.type !== "lookalike" && !LOOKALIKE.test(a.value));
  if (!custom.length) return "prospecting";
  if (custom.some((a) => CUSTOMERS.test(a.value))) return "retention";
  if (custom.every((a) => ENGAGED.test(a.value))) return "reengagement";
  return "retargeting";
}

/** The stage shown for an ad set: the manual choice, else the detected one. */
export const stageOf = (s: { funnelStage: string; funnelStageManual: string }): Stage =>
  (STAGES as readonly string[]).includes(s.funnelStageManual) ? (s.funnelStageManual as Stage) : (STAGES as readonly string[]).includes(s.funnelStage) ? (s.funnelStage as Stage) : "prospecting";
