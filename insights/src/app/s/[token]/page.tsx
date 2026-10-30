import type { Metadata } from "next";
import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import { PrintButton } from "@/components/copy";
import { ReportBody } from "@/components/report/body";
import { db } from "@/lib/db";
import { getDict } from "@/lib/i18n";
import { shareCookie, shareSpec, unlockProof } from "@/lib/share";

export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "Report", robots: { index: false, follow: false } };

/** Public, read-only live report. No login: the unguessable token is the access. */
export default async function SharedReport({ params, searchParams }: { params: Promise<{ token: string }>; searchParams: Promise<{ e?: string }> }) {
  const { token } = await params;
  const link = await db.shareLink.findUnique({ where: { token } });
  if (!link) notFound();
  if (link.expiresAt && link.expiresAt < new Date()) {
    return <Notice title="This link has expired" body="Ask the sender for a new one." />;
  }
  if (link.passwordHash) {
    const ok = (await cookies()).get(shareCookie(token))?.value === unlockProof(link);
    if (!ok) {
      const { e } = await searchParams;
      return (
        <main className="flex min-h-screen items-center justify-center bg-side p-4">
          <form method="post" action={`/api/share/${token}`} className="w-full max-w-sm space-y-3 rounded-2xl bg-panel p-6">
            <h1 className="text-lg font-semibold">{link.title || "Report"}</h1>
            <p className="text-sm text-muted">This report is password protected.</p>
            <input className="input" type="password" name="password" autoFocus required />
            {e && <p className="text-sm text-bad">Wrong password.</p>}
            <button className="btn w-full justify-center">Open report</button>
          </form>
        </main>
      );
    }
  }
  await db.shareLink.update({ where: { id: link.id }, data: { views: { increment: 1 } } });

  return (
    <main className="mx-auto max-w-7xl p-4 md:p-6">
      <div className="no-print mb-3 flex justify-end"><PrintButton /></div>
      <ReportBody spec={shareSpec(link)} t={await getDict()} />
      <p className="mt-6 text-center text-xs text-muted">Live report · Gildana Insights</p>
    </main>
  );
}

function Notice({ title, body }: { title: string; body: string }) {
  return <main className="flex min-h-screen items-center justify-center p-4"><div className="card max-w-sm text-center"><h1 className="font-semibold">{title}</h1><p className="text-sm text-muted">{body}</p></div></main>;
}
