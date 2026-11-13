import Link from "next/link";
import { redirect } from "next/navigation";
import { CreativeGridForm } from "@/components/creative-grid-form";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import type { SP } from "@/lib/page";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { CTAS, MAX_ADS } from "@/platforms/meta/creative-grid";

export const metadata = { title: "Creative grid" };

/** Test headlines, texts, images and buttons: every combination becomes its own ad. */
export default async function Grid({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("creatives");
  if (!canFeature(v, "creatives.grid") || !canEdit(v)) redirect("/creatives");
  const ads = await db.ad.findMany({
    where: { platform: "META", account: { enabled: true, connection: { clientId: v.clientId }, ...accessWhere(v.access) } },
    orderBy: { updatedAt: "desc" }, take: 300,
    select: { id: true, name: true, headline: true, body: true, cta: true, format: true, adSetId: true, accountId: true, adSet: { select: { name: true } }, campaign: { select: { name: true } } },
  });
  const initial = ads.find((a) => a.id === sp.ad) ?? ads[0];
  const sets = initial ? await db.adSet.findMany({ where: { accountId: initial.accountId }, orderBy: { updatedAt: "desc" }, take: 200, select: { id: true, name: true, campaign: { select: { name: true } } } }) : [];
  return (
    <>
      <PageHeader title="Creative split-test grid" sub="Several headlines, texts, images and buttons — every combination becomes one ad, so each element gets its own result">
        <Link className="btn-ghost" href="/creatives">← Creatives</Link>
      </PageHeader>
      {!initial ? <div className="card text-sm">There is no Meta ad to copy yet.</div> : (
        <CreativeGridForm initialAd={initial.id} max={MAX_ADS} ctas={[...CTAS]} canApprove={canFeature(v, "actions.approvals")}
          ads={ads.map((a) => ({ id: a.id, label: `${a.name} · ${a.campaign.name} › ${a.adSet.name}`, headline: a.headline, body: a.body, cta: a.cta, adSetId: a.adSetId, format: a.format }))}
          adSets={sets.map((s) => ({ id: s.id, label: `${s.name} · ${s.campaign.name}` }))} />
      )}
    </>
  );
}
