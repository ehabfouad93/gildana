import Link from "next/link";
import { redirect } from "next/navigation";
import { approveReviewAction, rejectReviewAction } from "@/app/actions/customers";
import { ScoreBar, StagePill, when } from "@/components/customer-bits";
import { PageHeader } from "@/components/shell";
import { canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { pendingReviews } from "@/modules/customers/query";

export const metadata = { title: "Identity review" };

type P = Awaited<ReturnType<typeof pendingReviews>>[number]["a"];

function Side({ c }: { c: P }) {
  return (
    <div className="min-w-0 flex-1 rounded-lg border border-line p-3 text-sm">
      <Link className="font-medium hover:underline" href={`/customers/${c.id}`}>{c.name || "Unnamed"}</Link>
      <div className="text-xs text-muted">{[c.phone, c.email, c.city].filter(Boolean).join(" · ")}</div>
      <div className="mt-2 flex flex-wrap items-center gap-3 text-xs"><StagePill stage={c.stage} /><span>intent <ScoreBar value={c.scores.intent} /></span></div>
      <div className="mt-1 text-xs text-muted">{c.sources.join(", ")} · first seen {when(c.firstSeenAt)}</div>
    </div>
  );
}

/**
 * Matches the rules wouldn't merge on their own (an email only, an unverified
 * phone shared with another profile, two CRM contacts on one number). Nothing
 * is merged until someone here says so; "Keep apart" stops the question for good.
 */
export default async function Review() {
  const v = await requireModule("customers");
  if (!canFeature(v, "customers.review")) redirect("/customers");
  const items = await pendingReviews({ clientId: v.clientId, companyIds: v.access ? v.companyIds : null, pii: canFeature(v, "customers.pii") }, 50);
  const edit = canEdit(v);
  return (
    <>
      <PageHeader title="Identity review" sub="Possible matches that need a person to decide — nothing is merged until you approve">
        <Link className="btn-ghost" href="/customers">← Customers</Link>
      </PageHeader>
      <div className="space-y-3">
        {items.map((r) => (
          <div key={r.id} className="card">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
              <span><span className="font-medium">{(r.evidence.matched ?? []).join(", ") || "Possible match"}</span> <span className="text-muted">· {r.evidence.reason} · confidence {r.confidence} · from {r.source} · {when(r.at)}</span></span>
              {edit && (
                <div className="flex gap-2">
                  <form action={approveReviewAction}><input type="hidden" name="id" value={r.id} /><button className="btn">Same person — merge</button></form>
                  <form action={rejectReviewAction}><input type="hidden" name="id" value={r.id} /><button className="btn-ghost">Different people</button></form>
                </div>
              )}
            </div>
            <div className="flex flex-col gap-3 md:flex-row"><Side c={r.a} /><Side c={r.b} /></div>
          </div>
        ))}
        {!items.length && <div className="card text-sm text-muted">Nothing to review. Strong matches (CRM ID, verified phone, WhatsApp, account ID) merge on their own; the doubtful ones appear here.</div>}
      </div>
    </>
  );
}
