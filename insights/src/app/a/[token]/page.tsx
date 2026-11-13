import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { db } from "@/lib/db";

export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "Approval", robots: { index: false, follow: false } };

const STATE: Record<string, { label: string; tone: string }> = {
  pending: { label: "Waiting for your answer", tone: "bg-amber-100 text-amber-800" }, approved: { label: "Approved", tone: "bg-green-100 text-good" },
  rejected: { label: "Rejected", tone: "bg-red-100 text-bad" }, changes: { label: "Changes requested", tone: "bg-blue-100 text-blue-800" },
};

/** What the agency wants to launch, for the client to approve. No login: the unguessable link is the access. */
export default async function ApprovalPage({ params, searchParams }: { params: Promise<{ token: string }>; searchParams: Promise<{ e?: string; done?: string }> }) {
  const { token } = await params;
  const { e, done } = await searchParams;
  const a = await db.approval.findUnique({ where: { token } });
  if (!a) notFound();
  const [client, account] = await Promise.all([
    db.client.findUnique({ where: { id: a.clientId }, select: { name: true, logoUrl: true } }),
    db.adAccount.findUnique({ where: { id: a.accountId }, select: { name: true } }),
  ]);
  const open = !a.launchedAt && (a.status === "pending" || a.status === "changes");
  const s = STATE[a.status] ?? STATE.pending;
  return (
    <main className="mx-auto max-w-2xl p-4 md:p-8">
      <div className="mb-4 flex items-center gap-3">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        {client?.logoUrl && <img src={client.logoUrl} alt="" className="h-10 max-w-40 object-contain" />}
        <div><div className="text-xs uppercase tracking-wider text-muted">{client?.name} · approval request</div><h1 className="text-xl font-semibold">{a.title}</h1></div>
      </div>
      <div className="card mb-4">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <span className={`pill ${s.tone}`}>{a.launchedAt ? "Launched" : s.label}</span>
          <span className="text-xs text-muted">sent {a.createdAt.toISOString().slice(0, 10)}{account ? ` · ${account.name}` : ""}</span>
        </div>
        <h2 className="mb-2 text-sm font-semibold">What will be created</h2>
        <ul className="list-disc space-y-1 ps-5 text-sm">{(a.summary as string[]).map((l, i) => <li key={i} dir="auto">{l}</li>)}</ul>
        <p className="mt-3 text-xs text-muted">Nothing is created or spent until you approve and the team launches it.</p>
      </div>
      {done && <div className="card mb-4 border-green-200 bg-green-50 text-sm text-good">Thank you — your answer was sent to the team.</div>}
      {e === "name" && <div className="card mb-4 border-red-200 bg-red-50 text-sm text-bad">Please enter your name.</div>}
      {e === "closed" && <div className="card mb-4 border-amber-200 bg-amber-50 text-sm">This request was already answered.</div>}
      {a.decidedAt && (
        <div className="card mb-4 text-sm">
          <b>{s.label}</b> by {a.contactName || "—"} on {a.decidedAt.toISOString().slice(0, 16).replace("T", " ")} UTC
          {a.comment && <p className="mt-1 whitespace-pre-line text-muted" dir="auto">{a.comment}</p>}
        </div>
      )}
      {open && (
        <form method="post" action={`/api/approve/${token}`} className="card space-y-3">
          <h2 className="text-sm font-semibold">Your answer</h2>
          <div><label className="label">Your name</label><input className="input" name="name" required maxLength={120} defaultValue={a.contactName} /></div>
          <div><label className="label">Comment (optional — what to change, if anything)</label><textarea className="input min-h-24" name="comment" maxLength={2000} dir="auto" /></div>
          <div className="flex flex-wrap gap-2">
            <button name="decision" value="approve" className="btn">Approve</button>
            <button name="decision" value="changes" className="btn-ghost">Request changes</button>
            <button name="decision" value="reject" className="btn-ghost !text-bad">Reject</button>
          </div>
        </form>
      )}
      <p className="mt-6 text-center text-xs text-muted">Gildana Insights</p>
    </main>
  );
}
