import Link from "next/link";
import { redirect } from "next/navigation";
import { MixerForm, type Item, type Source } from "@/components/mixer-form";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import type { SP } from "@/lib/page";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";

export const metadata = { title: "Audience mixer" };

/** Build an audience from interests, behaviours and custom audiences, see its reach, launch it as an ad set. */
export default async function Mixer({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("audiences");
  if (!canFeature(v, "audiences.mixer")) redirect("/audiences");
  const accounts = await db.adAccount.findMany({ where: { platform: "META", enabled: true, connection: { clientId: v.clientId }, ...accessWhere(v.access) }, orderBy: { name: "asc" }, select: { id: true, name: true, currency: true } });
  const account = accounts.find((a) => a.id === sp.account) ?? accounts[0];
  if (!account) return <><PageHeader title="Audience mixer" /><div className="card text-sm">Connect a Meta ad account first, on the <Link className="text-accent underline" href="/connections">Ad accounts</Link> page.</div></>;

  const [targeting, saved, sets] = await Promise.all([
    db.targetingItem.findMany({ where: { exclude: false, type: { in: ["interest", "behavior"] }, externalId: { not: "" }, adSet: { accountId: account.id } }, distinct: ["type", "externalId"], select: { type: true, value: true, externalId: true }, take: 400 }),
    db.savedAudience.findMany({ where: { accountId: account.id }, orderBy: { name: "asc" } }),
    db.adSet.findMany({ where: { accountId: account.id, ads: { some: {} } }, orderBy: { updatedAt: "desc" }, take: 200, select: { id: true, name: true, campaign: { select: { name: true } }, _count: { select: { ads: true } } } }),
  ]);
  const audienceItem = (a: (typeof saved)[number]): Item => ({ type: a.kind === "lookalike" ? "lookalike" : "audience", value: a.name, externalId: a.externalId, note: a.kind.replace(/_/g, " ") });
  const available: Item[] = [...saved.map(audienceItem), ...targeting.map((t) => ({ type: t.type, value: t.value, externalId: t.externalId }))];
  const defaults: Record<string, Item[]> = {};
  for (const a of saved) for (const s of a.excludeFrom) (defaults[s] ??= []).push(audienceItem(a));
  const picks = (Array.isArray(sp.pick) ? sp.pick : sp.pick ? [sp.pick] : []).flatMap((r) => { const [type, externalId, ...rest] = r.split("~"); return externalId && rest.length ? [{ type, externalId, value: rest.join("~") }] : []; });
  const sources: Source[] = sets.map((s) => ({ id: s.id, label: `${s.name} · ${s.campaign.name}`, currency: account.currency, ads: s._count.ads }));

  return (
    <>
      <PageHeader title="Audience mixer" sub="Mix interests, behaviours and audiences, check the reach, and launch the result as an ad set">
        <Link className="btn-ghost" href="/audiences/launcher">Ready-made audiences</Link>
        <Link className="btn-ghost" href="/audiences">← Audiences</Link>
      </PageHeader>
      <form method="get" className="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted">Ad account</span>
        <select name="account" defaultValue={account.id} className="input w-auto">{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select>
        <button className="btn-ghost !py-1">Switch</button>
      </form>
      {!canEdit(v) ? <div className="card text-sm">Read-only users can&apos;t build audiences.</div>
        : !sources.length ? <div className="card text-sm">This account has no ad set with ads to copy from yet.</div>
        : <MixerForm key={account.id} accountId={account.id} available={available} sources={sources} initial={picks} defaultExclusions={defaults} canApprove={canFeature(v, "actions.approvals")} />}
    </>
  );
}
