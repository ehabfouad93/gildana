import Link from "next/link";
import { redirect } from "next/navigation";
import { setAudienceDefaultsAction } from "@/app/actions/audience-tools";
import { CustomerListForm, LookalikeForm, PresetsForm, RefreshAudiences } from "@/components/launcher-forms";
import { pickKey } from "@/components/not-measured";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { STAGES, STAGE_LABEL } from "@/lib/funnel";
import type { SP } from "@/lib/page";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { PRESETS } from "@/platforms/meta/audiences";

export const metadata = { title: "Ready-made audiences" };

const compact = (n: bigint | null) => (n == null ? "" : new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(Number(n)));

/** The audiences every account should have, by funnel stage — created in a few clicks, with default exclusions per stage. */
export default async function Launcher({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("audiences");
  if (!canFeature(v, "audiences.launcher")) redirect("/audiences");
  const accounts = await db.adAccount.findMany({ where: { platform: "META", enabled: true, connection: { clientId: v.clientId }, ...accessWhere(v.access) }, orderBy: { name: "asc" }, select: { id: true, name: true } });
  const account = accounts.find((a) => a.id === sp.account) ?? accounts[0];
  if (!account) return <><PageHeader title="Ready-made audiences" /><div className="card text-sm">Connect a Meta ad account first, on the <Link className="text-accent underline" href="/connections">Ad accounts</Link> page.</div></>;
  const saved = await db.savedAudience.findMany({ where: { accountId: account.id }, orderBy: [{ stage: "asc" }, { name: "asc" }] });
  const edit = canEdit(v);

  return (
    <>
      <PageHeader title="Ready-made audiences" sub="Website visitors, engagers, video viewers and lookalikes — created in a few clicks and ready for the mixer">
        <Link className="btn-ghost" href={`/audiences/mixer?account=${account.id}`}>Audience mixer</Link>
        <Link className="btn-ghost" href="/audiences">← Audiences</Link>
      </PageHeader>
      <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <form method="get" className="flex items-center gap-2">
          <span className="text-muted">Ad account</span>
          <select name="account" defaultValue={account.id} className="input w-auto">{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select>
          <button className="btn-ghost !py-1">Switch</button>
        </form>
        <div className="ms-auto"><RefreshAudiences accountId={account.id} /></div>
      </div>

      <div className="card mb-4">
        <h2 className="mb-1 font-semibold">This account&apos;s audiences ({saved.length})</h2>
        <p className="mb-3 text-xs text-muted">Set each audience&apos;s funnel stage, and tick the stages whose <b>new</b> ad sets should exclude it by default — e.g. exclude customers and recent visitors from prospecting. The mixer fills its exclusions from these.</p>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th>Audience</th><th>Type</th><th>Size</th><th>Stage · exclude by default from</th><th /></tr></thead>
            <tbody>
              {saved.map((a) => (
                <tr key={a.id}>
                  <td className="font-medium">{a.name}{a.createdHere && <span className="pill ms-2 bg-blue-100 text-blue-800">created here</span>}</td>
                  <td className="text-xs capitalize">{a.kind.replace(/_/g, " ")}</td>
                  <td className="text-xs">{a.sizeHigh ? `${compact(a.sizeLow)}–${compact(a.sizeHigh)}` : "—"}</td>
                  <td>
                    <form action={setAudienceDefaultsAction} className="flex flex-wrap items-center gap-2 text-xs">
                      <input type="hidden" name="accountId" value={account.id} /><input type="hidden" name="audienceId" value={a.id} />
                      <select name="stage" defaultValue={a.stage} className="rounded border border-line bg-panel px-1 py-0.5" disabled={!edit}>{STAGES.map((s) => <option key={s} value={s}>{STAGE_LABEL[s]}</option>)}</select>
                      <span className="text-muted">exclude from</span>
                      {STAGES.map((s) => <label key={s} className="flex items-center gap-1"><input type="checkbox" name="excludeFrom" value={s} defaultChecked={a.excludeFrom.includes(s)} disabled={!edit} />{STAGE_LABEL[s]}</label>)}
                      {edit && <button className="text-accent hover:underline">Save</button>}
                    </form>
                  </td>
                  <td><Link className="text-xs text-accent hover:underline" href={`/audiences/mixer?account=${account.id}&pick=${encodeURIComponent(pickKey({ type: a.kind === "lookalike" ? "lookalike" : "audience", externalId: a.externalId, value: a.name }))}`}>Use in mixer →</Link></td>
                </tr>
              ))}
              {!saved.length && <tr><td colSpan={5} className="text-muted">None listed yet. Press “Read audiences from Meta”, or create some below.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      {edit && (
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="card">
            <h2 className="mb-3 font-semibold">Create ready-made audiences</h2>
            <PresetsForm accountId={account.id} existing={saved.map((s) => s.name)} presets={PRESETS.map((p) => ({ key: p.key, label: p.label, stage: p.stage, kind: p.kind }))} />
          </div>
          <div className="card">
            <h2 className="mb-1 font-semibold">Create lookalikes</h2>
            <p className="mb-3 text-xs text-muted">New people who resemble one of your audiences — for prospecting. Meta needs the source audience to have at least 100 people from the country.</p>
            <LookalikeForm accountId={account.id} audiences={saved.filter((s) => s.kind !== "lookalike").map((s) => ({ id: s.id, name: s.name }))} />
          </div>
          <div className="card lg:col-span-2">
            <h2 className="mb-1 font-semibold">Audience from a customer list</h2>
            <p className="mb-3 text-xs text-muted">Your existing customers or leads as an audience — to exclude them from prospecting, sell to them again, or model lookalikes on them.</p>
            <CustomerListForm accountId={account.id} />
          </div>
        </div>
      )}
    </>
  );
}
