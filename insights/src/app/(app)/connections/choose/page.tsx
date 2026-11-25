import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { AccountChooser, type ChoiceRow } from "@/components/account-chooser";
import { PageHeader } from "@/components/shell";
import { ACCOUNT_STATUS, reachableAccounts } from "@/lib/account-choice";
import { db } from "@/lib/db";
import type { SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { canEdit, requireModule } from "@/lib/tenancy";
import { safeNext } from "@/lib/oauth";

export const metadata = { title: "Choose ad accounts" };
export const dynamic = "force-dynamic";

/** Which of a login's ad accounts are in the dashboard — read live from the platform each time. */
export default async function ChooseAccounts({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("connections");
  if (!canEdit(v) || v.access) redirect("/connections");
  const conn = await db.adConnection.findFirst({ where: { id: String(sp.conn ?? ""), clientId: v.clientId }, include: { accounts: { select: { externalId: true, name: true, currency: true } } } });
  if (!conn) notFound();
  const isNew = sp.new === "1";
  const next = safeNext(typeof sp.next === "string" ? sp.next : undefined, "/connections");
  const label = PLATFORM_META[conn.platform].label;

  let error = "";
  let reach: Awaited<ReturnType<typeof reachableAccounts>> = [];
  try { reach = await reachableAccounts(conn, true); } catch (e) { error = (e as Error).message; }
  const have = new Map(conn.accounts.map((a) => [a.externalId, a]));
  const rows: ChoiceRow[] = [
    ...reach.map((a) => ({ externalId: a.externalId, name: a.name, currency: a.currency, status: ACCOUNT_STATUS[a.status ?? ""] ?? "", source: a.source || "chosen", added: have.has(a.externalId), reachable: true })),
    ...conn.accounts.filter((a) => !reach.some((r) => r.externalId === a.externalId)).map((a) => ({ externalId: a.externalId, name: a.name, currency: a.currency, status: "", source: "in the dashboard", added: true, reachable: false })),
  ];
  // A new login starts with what was picked in the platform's window; afterwards, with what is already in the dashboard.
  const initial = isNew && !conn.accounts.length ? reach.filter((a) => (a.source ?? "chosen") === "chosen").map((a) => a.externalId) : conn.accounts.map((a) => a.externalId);

  return (
    <>
      <PageHeader title="Choose ad accounts" sub={`${label} · ${conn.label || conn.externalUser || "login"} — tick the accounts to bring into the dashboard`}>
        <Link className="btn-ghost" href="/connections">← Ad accounts</Link>
      </PageHeader>
      {isNew && <div className="card mb-4 border-green-200 bg-green-50 text-sm">Connected. The accounts you picked in {label}&apos;s window are ticked; accounts reachable through your businesses are listed below — tick any you also want. Only ticked accounts are added and synced.</div>}
      {error && <div className="card mb-4 border-red-200 bg-red-50 text-sm text-bad">{label} refused to list the accounts: {error}</div>}
      <AccountChooser connectionId={conn.id} rows={rows} initial={initial} next={next} platformLabel={label} />
    </>
  );
}
