import Link from "next/link";
import { redirect } from "next/navigation";
import { NewCampaignWizard } from "@/components/new-campaign-wizard";
import { PageHeader } from "@/components/shell";
import { claudeEnabled } from "@/lib/claude";
import { db } from "@/lib/db";
import { openaiEnabled } from "@/lib/openai";
import type { SP } from "@/lib/page";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { specFromExisting } from "@/platforms/meta/new-campaign";

export const metadata = { title: "New campaign" };

/** Create a Meta campaign from scratch, or start from an existing one (?from=<campaignId>). */
export default async function NewCampaign({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("campaigns");
  if (!canFeature(v, "campaigns.create") || !canEdit(v)) redirect("/campaigns");
  const accounts = await db.adAccount.findMany({ where: { platform: "META", enabled: true, connection: { clientId: v.clientId }, ...accessWhere(v.access) }, orderBy: { name: "asc" }, select: { id: true, name: true, currency: true } });
  if (!accounts.length) return <><PageHeader title="New campaign" /><div className="card text-sm">Connect a Meta ad account first, on the <Link className="text-accent underline" href="/connections">Ad accounts</Link> page.</div></>;

  // Starting from an existing campaign: its biggest ad set's settings and its ads.
  let prefill = null, initial = accounts.find((a) => a.id === sp.account)?.id ?? accounts[0].id, fromName = "";
  if (typeof sp.from === "string") {
    const c = await db.campaign.findFirst({
      where: { id: sp.from, platform: "META", accountId: { in: accounts.map((a) => a.id) } },
      include: { adSets: { orderBy: { dailyBudget: "desc" }, take: 1 }, ads: { select: { name: true, creativeExternalId: true, headline: true }, take: 20 } },
    });
    if (c) {
      const s = c.adSets[0];
      prefill = specFromExisting({
        name: c.name, objective: c.objective, dailyBudget: c.dailyBudget != null ? Number(c.dailyBudget) : null, totalBudget: c.totalBudget != null ? Number(c.totalBudget) : null,
        adSet: s ? { optimizationGoal: s.optimizationGoal, dailyBudget: s.dailyBudget != null ? Number(s.dailyBudget) : null, targetingRaw: (s.targetingRaw ?? null) as Record<string, unknown> | null } : null,
        ads: c.ads,
      });
      initial = c.accountId; fromName = c.name;
    }
  }
  const ads = await db.ad.findMany({
    where: { accountId: { in: accounts.map((a) => a.id) }, creativeExternalId: { not: "" } }, orderBy: { updatedAt: "desc" }, take: 400,
    select: { accountId: true, name: true, headline: true, creativeExternalId: true, campaign: { select: { name: true } } },
  });
  const existingAds: Record<string, { creativeId: string; name: string; headline: string; campaign: string }[]> = {};
  for (const a of ads) (existingAds[a.accountId] ??= []).push({ creativeId: a.creativeExternalId, name: a.name, headline: a.headline, campaign: a.campaign.name });
  const client = await db.client.findUniqueOrThrow({ where: { id: v.clientId }, select: { requireApproval: true } });

  return (
    <>
      <PageHeader title="New campaign" sub={fromName ? `Starting from “${fromName}” — change what should be different` : "Meta (Facebook and Instagram) — created paused unless you choose to start it"}>
        <Link className="btn-ghost" href="/campaigns">← Campaigns</Link>
      </PageHeader>
      <NewCampaignWizard accounts={accounts} initialAccount={initial} prefill={prefill} existingAds={existingAds}
        aiOn={(claudeEnabled() || openaiEnabled()) && canFeature(v, "general.ai_chat")} canApprove={canFeature(v, "actions.approvals")} approvalRequired={client.requireApproval} />
    </>
  );
}
