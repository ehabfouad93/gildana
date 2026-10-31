import { decideClaimAction, refreshSharedAction } from "@/app/actions/saas";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { requireAdmin } from "@/lib/tenancy";

export const metadata = { title: "Access requests" };
export const dynamic = "force-dynamic";

/** Ad accounts clients shared with Gildana and asked to add. Approving creates them in the client's workspace. */
export default async function Access() {
  await requireAdmin();
  const claims = await db.accountClaim.findMany({ orderBy: [{ status: "asc" }, { createdAt: "desc" }], take: 300, include: { client: { select: { name: true } } } });
  const companies = new Map((await db.company.findMany({ where: { id: { in: claims.map((c) => c.companyId ?? "") } }, select: { id: true, name: true } })).map((c) => [c.id, c.name]));
  const pending = claims.filter((c) => c.status === "pending");
  const done = claims.filter((c) => c.status !== "pending");
  return (
    <>
      <PageHeader title="Access requests" sub="Ad accounts clients shared with Gildana instead of logging in">
        {PLATFORMS.map((p) => (
          <form key={p} action={refreshSharedAction}><input type="hidden" name="platform" value={p} /><button className="btn-ghost !py-1 text-xs">Re-check {PLATFORM_META[p].label}</button></form>
        ))}
      </PageHeader>
      <div className="card mb-4">
        <h2 className="mb-2 font-semibold">Waiting ({pending.length})</h2>
        <table className="tbl">
          <thead><tr><th>Client</th><th>Platform</th><th>Ad account</th><th>Company</th><th>Gildana can see it?</th><th /></tr></thead>
          <tbody>
            {pending.map((c) => (
              <tr key={c.id}>
                <td>{c.client.name}</td><td>{PLATFORM_META[c.platform].label}</td>
                <td><div className="font-medium">{c.name || "—"}</div><div className="text-xs text-muted">{c.externalId}</div></td>
                <td>{c.companyId ? companies.get(c.companyId) ?? "—" : "—"}</td>
                <td>{c.visible ? <span className="text-good">Yes</span> : <span className="text-amber-700">Not yet — not shared, or Gildana&apos;s login is missing</span>}</td>
                <td className="flex gap-2">
                  <form action={decideClaimAction}><input type="hidden" name="id" value={c.id} /><input type="hidden" name="decision" value="approve" /><button className="btn !py-1" disabled={!c.visible}>Approve</button></form>
                  <form action={decideClaimAction} className="flex gap-1"><input type="hidden" name="id" value={c.id} /><input type="hidden" name="decision" value="reject" /><input className="input !w-40 !py-1" name="note" placeholder="Reason (optional)" /><button className="btn-ghost !py-1">Reject</button></form>
                </td>
              </tr>
            ))}
            {!pending.length && <tr><td colSpan={6} className="text-muted">Nothing waiting.</td></tr>}
          </tbody>
        </table>
      </div>
      {done.length > 0 && (
        <div className="card">
          <h2 className="mb-2 font-semibold">Decided</h2>
          <table className="tbl"><tbody>
            {done.map((c) => (
              <tr key={c.id}><td>{c.client.name}</td><td>{PLATFORM_META[c.platform].label}</td><td>{c.name || c.externalId}</td>
                <td><span className={`pill ${c.status === "approved" ? "bg-green-100 text-good" : "bg-red-100 text-bad"}`}>{c.status}</span> {c.note}</td><td className="text-xs text-muted">{c.decidedAt?.toISOString().slice(0, 10)}</td></tr>
            ))}
          </tbody></table>
        </div>
      )}
    </>
  );
}
