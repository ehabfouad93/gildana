import Link from "next/link";
import { redirect } from "next/navigation";
import { PageHeader } from "@/components/shell";
import { SplitCampaignForm, type PickOption, type SourceOption } from "@/components/split-campaign-form";
import { db } from "@/lib/db";
import type { SP } from "@/lib/page";
import { accessWhere, canEdit, requireModule } from "@/lib/tenancy";
import { WRITE_SCOPE } from "@/platforms/meta";

export const metadata = { title: "Split-test campaign" };

/** Turns ticked suggestions into a new campaign with one ad set per interest. */
export default async function SplitTestPage({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("audiences");
  if (!canEdit(v)) redirect("/audiences?v=suggest");
  const raw = (Array.isArray(sp.pick) ? sp.pick : sp.pick ? [sp.pick] : []).slice(0, 40);
  const picks: PickOption[] = [];
  for (const r of raw) {
    const [type, externalId, ...rest] = r.split("~");
    const value = rest.join("~").trim();
    if (value && (type === "interest" || type === "behavior") && !picks.some((p) => p.value.toLowerCase() === value.toLowerCase())) picks.push({ key: r, value, type, known: !!externalId });
  }
  const camp = typeof sp.camp === "string" ? sp.camp : "";

  // Ad sets that can be the template: Meta, with ads, in accounts this user may see.
  const sets = await db.adSet.findMany({
    where: { platform: "META", ads: { some: {} }, account: { connection: { clientId: v.clientId }, enabled: true, ...accessWhere(v.access) } },
    select: {
      id: true, name: true, campaignId: true, dailyBudget: true, updatedAt: true, _count: { select: { ads: true } },
      campaign: { select: { name: true } }, account: { select: { name: true, currency: true, connection: { select: { isDemo: true, scopes: true } } } },
      targeting: { where: { exclude: false, type: { in: ["interest", "behavior"] } }, select: { value: true } },
    },
    orderBy: { updatedAt: "desc" }, take: 300,
  });
  const names = new Set(picks.map((p) => p.value.toLowerCase()));
  const score = (s: (typeof sets)[number]) => (s.campaignId === camp ? 100 : 0) + s.targeting.filter((t) => names.has(t.value.toLowerCase())).length;
  const ranked = [...sets].sort((a, b) => score(b) - score(a));
  const sources: SourceOption[] = ranked.map((s) => ({
    id: s.id, label: `${s.name} · ${s.campaign.name} · ${s.account.name}`, currency: s.account.currency, dailyBudget: s.dailyBudget ? Number(s.dailyBudget) : null,
    ads: s._count.ads, demo: s.account.connection.isDemo, canWrite: s.account.connection.isDemo || s.account.connection.scopes.split(",").includes(WRITE_SCOPE),
  }));

  return (
    <>
      <PageHeader title="Create a split-test campaign" sub="One ad set per interest, same ads and settings, equal budgets — so each interest gets an exact result" />
      {!picks.length ? (
        <div className="card text-sm">No interests were ticked. Go back to <Link className="text-accent underline" href="/audiences?v=suggest">Suggestions</Link> and tick the ones to test.</div>
      ) : !sources.length ? (
        <div className="card text-sm">There is no Meta ad set with ads to copy from yet. Connect a Meta ad account on the <Link className="text-accent underline" href="/connections">Ad accounts</Link> page.</div>
      ) : (
        <SplitCampaignForm picks={picks} sources={sources} defaultSource={sources[0].id} defaultName={`Interest test – ${ranked[0].campaign.name}`.slice(0, 200)} />
      )}
    </>
  );
}
