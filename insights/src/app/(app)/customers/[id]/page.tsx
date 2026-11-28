import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { deleteCustomerAction, unmergeAction } from "@/app/actions/customers";
import { ConfirmButton } from "@/components/confirm-button";
import { SOURCE_ICON, ScoreBar, StagePill, when } from "@/components/customer-bits";
import { PageHeader } from "@/components/shell";
import { canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { getCustomer } from "@/modules/customers/query";
import { timeline } from "@/modules/customers/service";

export const metadata = { title: "Customer" };

const SCORE = [
  { key: "intent", label: "Purchase intent", hint: "What they looked at and did in the last 30 days" },
  { key: "value", label: "Customer value", hint: "Budget against the prices of their projects, and past purchases" },
  { key: "identity", label: "Identity confidence", hint: "How sure we are that these records are one person" },
] as const;

export default async function Customer360({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<Record<string, string | undefined>> }) {
  const v = await requireModule("customers");
  const { id } = await params;
  const sp = await searchParams;
  const a = { clientId: v.clientId, companyIds: v.access ? v.companyIds : null, pii: canFeature(v, "customers.pii") };
  const c = await getCustomer(a, id);
  if (!c) notFound();
  if (c.id !== id) redirect(`/customers/${c.id}`); // merged: show the live profile
  const items = await timeline(c.id, 300);
  const review = canEdit(v) && canFeature(v, "customers.review");
  const touch = (t: Record<string, unknown> | null) => !t ? "—" : t.kind === "lead" ? [t.platform, t.campaign, t.adSet, t.ad].filter(Boolean).join(" › ") : [t.channel, t.source, t.campaign && `“${t.campaign}”`].filter(Boolean).join(" · ");

  return (
    <>
      <PageHeader title={c.name || "Unnamed customer"} sub={[c.phone, c.email, c.city].filter(Boolean).join(" · ") || "No contact details"}>
        <Link className="btn-ghost" href="/customers">← Customers</Link>
      </PageHeader>
      {sp.restored && <div className="card mb-4 border-emerald-200 text-sm">Merge undone — this profile has its identifiers and timeline back.</div>}

      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        <div className="card text-sm">
          <h2 className="mb-3 font-semibold">Profile</h2>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
            <dt className="text-muted">Stage</dt><dd><StagePill stage={c.stage} /></dd>
            <dt className="text-muted">Projects</dt><dd>{c.projects.map((p) => p.name).join(", ") || "—"}</dd>
            <dt className="text-muted">Budget</dt><dd>{c.budgetText || (c.budget ? c.budget.toLocaleString() : "—")}</dd>
            <dt className="text-muted">First touch</dt><dd className="text-xs">{touch(c.firstTouch)}</dd>
            <dt className="text-muted">Last touch</dt><dd className="text-xs">{touch(c.lastTouch)}</dd>
            <dt className="text-muted">First seen</dt><dd>{when(c.firstSeenAt)}</dd>
            <dt className="text-muted">Last seen</dt><dd>{when(c.lastSeenAt)}</dd>
          </dl>
          <h3 className="mb-1 mt-4 text-xs font-semibold uppercase tracking-wide text-muted">Consent</h3>
          {Object.keys(c.consent).length ? (
            <ul className="space-y-0.5 text-xs">{Object.entries(c.consent).map(([p, s]) => <li key={p}><span className={s.status === "granted" ? "text-good" : "text-bad"}>{s.status === "granted" ? "✓" : "✕"}</span> {p} <span className="text-muted">· {s.source}, {when(s.at)}</span></li>)}</ul>
          ) : <p className="text-xs text-muted">No consent recorded. Audiences only use people with ads consent where your settings require it.</p>}
        </div>

        <div className="card text-sm lg:col-span-2">
          <h2 className="mb-3 font-semibold">Scores <span className="text-xs font-normal text-muted">· rules v1, every point explained</span></h2>
          <div className="grid gap-4 md:grid-cols-3">
            {SCORE.map((s) => (
              <div key={s.key}>
                <div className="mb-1 flex items-center justify-between"><span className="font-medium">{s.label}</span><ScoreBar value={c.scores[s.key]} /></div>
                <p className="mb-2 text-xs text-muted">{s.hint}</p>
                <ul className="space-y-0.5 text-xs">
                  {(c.why[s.key] ?? []).map((w, i) => <li key={i} className="flex justify-between gap-2"><span>{w.label}</span><span className="tabular-nums text-muted">{w.points > 0 ? "+" : ""}{w.points}</span></li>)}
                  {!(c.why[s.key] ?? []).length && <li className="text-muted">Nothing yet.</li>}
                </ul>
              </div>
            ))}
          </div>
          <p className="mt-3 text-xs text-muted">Conversion probability appears once CRM outcomes (meetings, reservations, sales) are connected, measured from your own history.</p>
        </div>
      </div>

      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        <div className="card overflow-x-auto lg:col-span-2">
          <h2 className="mb-2 font-semibold">Identifiers <span className="text-xs font-normal text-muted">· how each record was matched</span></h2>
          <table className="tbl">
            <thead><tr><th>Type</th><th>Value</th><th>Source</th><th>Match</th><th>Confidence</th><th>First seen</th></tr></thead>
            <tbody>
              {c.identifiers.map((i) => (
                <tr key={i.id}>
                  <td>{i.label}{i.verified && <span className="ms-1 text-xs text-good" title="Verified by the source">✓</span>}</td>
                  <td className="font-mono text-xs">{i.value}</td>
                  <td className="text-xs">{i.source}</td>
                  <td className="text-xs">{i.match ? (i.match.method === "new profile" ? "Started this profile" : `Same ${String(i.match.method).replace("_", " ")}`) : "—"}</td>
                  <td className="text-xs tabular-nums">{i.match?.confidence ?? "—"}</td>
                  <td className="text-xs text-muted">{when(i.firstSeenAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="card text-sm">
          <h2 className="mb-2 font-semibold">Merges</h2>
          <ul className="space-y-2 text-xs">
            {c.merges.map((m) => (
              <li key={m.id} className="rounded-lg border border-line p-2">
                <div className="flex justify-between gap-2">
                  <span>{m.status === "pending" ? "Possible match — waiting for review" : m.status === "rejected" ? "Kept apart" : m.status === "reversed" ? "Merge undone" : m.survivor ? "Merged another profile into this one" : "Was merged into another profile"}</span>
                  <span className="text-muted">{m.confidence}</span>
                </div>
                <div className="text-muted">{[...((m.evidence?.matched as string[]) ?? []), m.evidence?.reason as string].filter(Boolean).join(" · ")} · {m.method} · {when(m.at)}</div>
                <div className="mt-1 flex gap-3">
                  {m.otherId && <Link className="hover:underline" href={`/customers/${m.otherId}`}>Open the other profile</Link>}
                  {review && m.survivor && (m.status === "auto" || m.status === "approved") && (
                    <form action={unmergeAction}><input type="hidden" name="id" value={m.id} /><ConfirmButton className="text-bad hover:underline" message="Undo this merge? What came from the other profile goes back to it, and the two won't be merged automatically again.">Undo merge</ConfirmButton></form>
                  )}
                </div>
              </li>
            ))}
            {!c.merges.length && <li className="text-muted">No merges — every record matched this profile directly.</li>}
          </ul>
        </div>
      </div>

      <div className="card mb-4">
        <h2 className="mb-3 font-semibold">Timeline <span className="text-xs font-normal text-muted">· {items.length} items</span></h2>
        <ol className="space-y-2">
          {items.map((t, i) => (
            <li key={i} className="flex gap-3 border-b border-line pb-2 text-sm last:border-0">
              <span className="w-5 shrink-0 text-center" title={t.source}>{SOURCE_ICON[t.source] ?? "•"}</span>
              <span className="w-32 shrink-0 text-xs tabular-nums text-muted">{when(t.at)}</span>
              <span className="min-w-0"><span className="font-medium">{t.title}</span>{t.detail && <span className="block truncate text-xs text-muted">{t.detail}</span>}</span>
            </li>
          ))}
          {!items.length && <li className="text-sm text-muted">Nothing on the timeline yet.</li>}
        </ol>
      </div>

      {canEdit(v) && canFeature(v, "customers.delete") && (
        <form action={deleteCustomerAction} className="card border-red-200 text-sm">
          <input type="hidden" name="id" value={c.id} />
          <h2 className="font-semibold text-bad">Delete this profile</h2>
          <p className="mb-2 text-muted">Removes the profile, its identifiers, timeline and consent records (for a deletion request). Its ad leads stay in the ad-account reports but won't form a profile again.</p>
          <ConfirmButton className="btn !bg-bad" message="Delete this customer profile? This can't be undone.">Delete profile</ConfirmButton>
        </form>
      )}
    </>
  );
}
