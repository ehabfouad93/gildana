import "server-only";
import type { Prisma } from "@prisma/client";
import { propose, type Proposal } from "@/lib/actions-engine";
import { db } from "@/lib/db";
import { money } from "@/lib/format";
import { detectStage, stageOf } from "@/lib/funnel";
import { audit, evaluateRule, type Ent, type Idea, type RuleSpec } from "@/lib/optimise";

/**
 * Runs the audit and the automation rules over a workspace's Meta accounts
 * (the only platform changes can be applied on) and files what they find in
 * the to-do list. Reads the warehouse only — no platform calls.
 */

type Loaded = { accountId: string; currency: string; adSets: (Ent & { stage: string })[]; ads: Ent[] };

async function loadAccount(account: { id: string; currency: string }, today: string): Promise<Loaded> {
  const since = new Date(Date.parse(today) - 30 * 86_400_000);
  const [sets, ads, stats] = await Promise.all([
    db.adSet.findMany({ where: { accountId: account.id }, select: { id: true, name: true, status: true, dailyBudget: true, campaignId: true, funnelStage: true, funnelStageManual: true, campaign: { select: { name: true, objective: true, status: true } } } }),
    db.ad.findMany({ where: { accountId: account.id }, select: { id: true, name: true, status: true, adSetId: true, campaignId: true, campaign: { select: { name: true, objective: true, status: true } }, adSet: { select: { status: true } } } }),
    db.statDaily.groupBy({
      by: ["level", "entityId", "date"], where: { accountId: account.id, level: { in: ["ADSET", "AD"] }, date: { gte: since } },
      _sum: { spend: true, impressions: true, reach: true, clicks: true, leads: true, conversions: true, value: true },
    }),
  ]);
  const raw = new Map<string, { date: string; spend: number; impressions: number; reach: number; clicks: number; leads: number; conversions: number; value: number }[]>();
  for (const g of stats) {
    const s = g._sum;
    const row = { date: g.date.toISOString().slice(0, 10), spend: Number(s.spend ?? 0), impressions: Number(s.impressions ?? 0), reach: Number(s.reach ?? 0), clicks: Number(s.clicks ?? 0), leads: Number(s.leads ?? 0), conversions: Number(s.conversions ?? 0), value: Number(s.value ?? 0) };
    raw.set(g.entityId, [...(raw.get(g.entityId) ?? []), row]);
  }
  // An entity's "result" is its leads when it has any, else its conversions — the same choice for every day.
  const days = (id: string) => {
    const list = (raw.get(id) ?? []).sort((a, b) => a.date.localeCompare(b.date));
    const useLeads = list.some((d) => d.leads > 0);
    return list.map((d) => ({ date: d.date, spend: d.spend, impressions: d.impressions, reach: d.reach, clicks: d.clicks, results: useLeads ? d.leads : d.conversions, value: d.value }));
  };
  // Something inside a paused campaign (or ad set) isn't running, whatever its own switch says.
  const eff = (own: string, ...parents: string[]) => (parents.some((p) => p !== "ACTIVE") && own === "ACTIVE" ? "PARENT_PAUSED" : own);
  return {
    accountId: account.id, currency: account.currency,
    adSets: sets.map((s) => ({
      id: s.id, name: s.name, level: "ADSET" as const, status: eff(s.status, s.campaign.status), dailyBudget: s.dailyBudget != null ? Number(s.dailyBudget) : null,
      campaignId: s.campaignId, campaignName: s.campaign.name, objective: s.campaign.objective, adSetId: s.id, days: days(s.id), stage: stageOf(s),
    })),
    ads: ads.map((a) => ({
      id: a.id, name: a.name, level: "AD" as const, status: eff(a.status, a.campaign.status, a.adSet.status), dailyBudget: null,
      campaignId: a.campaignId, campaignName: a.campaign.name, objective: a.campaign.objective, adSetId: a.adSetId, days: days(a.id),
    })),
  };
}

async function metaAccounts(clientId: string) {
  return db.adAccount.findMany({ where: { platform: "META", enabled: true, lastSyncAt: { not: null }, connection: { clientId, status: "active" } }, select: { id: true, currency: true } });
}

const today = () => new Date().toISOString().slice(0, 10);

const toProposals = (clientId: string, accountId: string, ideas: Idea[], source: string): Proposal[] =>
  ideas.map((i) => ({ clientId, accountId, kind: i.kind, level: i.level, entityId: i.entityId, entityName: i.entityName, payload: i.payload, reason: i.reason, source, priority: i.priority }));

/** The daily audit for one workspace. Returns how many new suggestions were filed. */
export async function runAudit(clientId: string): Promise<number> {
  let added = 0;
  for (const acc of await metaAccounts(clientId)) {
    const d = await loadAccount(acc, today());
    added += await propose(toProposals(clientId, acc.id, audit(d.adSets, d.ads, today(), (n) => money(n, acc.currency)), "audit"));
  }
  await db.client.update({ where: { id: clientId }, data: { lastAuditAt: new Date() } });
  return added;
}

const specOf = (r: { name: string; level: string; windowDays: number; conditions: Prisma.JsonValue; action: Prisma.JsonValue; scope: Prisma.JsonValue }): RuleSpec => ({
  name: r.name, level: r.level === "AD" ? "AD" : "ADSET", windowDays: r.windowDays,
  conditions: (r.conditions ?? []) as unknown as RuleSpec["conditions"], action: (r.action ?? { kind: "pause" }) as unknown as RuleSpec["action"], scope: (r.scope ?? {}) as RuleSpec["scope"],
});

function inScope(spec: RuleSpec, d: Loaded): Ent[] {
  const s = spec.scope ?? {};
  if (s.accountIds?.length && !s.accountIds.includes(d.accountId)) return [];
  const stageOfSet = new Map(d.adSets.map((x) => [x.id, x.stage]));
  return [...d.adSets, ...d.ads].filter((e) => (!s.campaignIds?.length || s.campaignIds.includes(e.campaignId)) && (!s.stages?.length || s.stages.includes(stageOfSet.get(e.adSetId) ?? "")));
}

/** What one rule would suggest right now, without filing anything. */
export async function previewRule(clientId: string, spec: RuleSpec): Promise<{ accountId: string; ideas: Idea[] }[]> {
  const out: { accountId: string; ideas: Idea[] }[] = [];
  for (const acc of await metaAccounts(clientId)) {
    const d = await loadAccount(acc, today());
    const ideas = evaluateRule(spec, inScope(spec, d), today(), (n) => money(n, acc.currency));
    if (ideas.length) out.push({ accountId: acc.id, ideas });
  }
  return out;
}

/** Evaluates the workspace's enabled rules (or one) and files their suggestions. */
export async function runRules(clientId: string, ruleId?: string): Promise<number> {
  const rules = await db.rule.findMany({ where: { clientId, enabled: true, ...(ruleId ? { id: ruleId } : {}) } });
  if (!rules.length) return 0;
  let added = 0;
  for (const acc of await metaAccounts(clientId)) {
    const d = await loadAccount(acc, today());
    for (const r of rules) {
      const spec = specOf(r);
      added += await propose(toProposals(clientId, acc.id, evaluateRule(spec, inScope(spec, d), today(), (n) => money(n, acc.currency)), `rule:${r.id}`));
    }
  }
  await db.rule.updateMany({ where: { id: { in: rules.map((r) => r.id) } }, data: { lastRunAt: new Date() } });
  return added;
}

/** Ad sets synced before funnel stages existed get theirs from their stored targeting. */
export async function backfillStages(): Promise<number> {
  const sets = await db.adSet.findMany({ where: { funnelStage: "" }, select: { id: true, targeting: { select: { type: true, value: true, exclude: true } } }, take: 2000 });
  for (const s of sets) await db.adSet.update({ where: { id: s.id }, data: { funnelStage: detectStage(s.targeting) } });
  return sets.length;
}

/** Workspaces whose daily audit is due (worker). */
export async function auditDue(): Promise<string[]> {
  const rows = await db.client.findMany({
    where: { active: true, OR: [{ lastAuditAt: null }, { lastAuditAt: { lt: new Date(Date.now() - 20 * 60 * 60_000) } }], connections: { some: { platform: "META", status: "active", accounts: { some: { enabled: true, lastSyncAt: { not: null } } } } } },
    select: { id: true }, take: 20,
  });
  return rows.map((r) => r.id);
}
