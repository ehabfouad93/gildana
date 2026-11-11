import { notFound, redirect } from "next/navigation";
import { deleteClientAction, openWorkspaceAction } from "@/app/actions/admin";
import { PageHeader } from "@/components/shell";
import { UserManager } from "@/components/user-manager";
import { WorkspaceSettings } from "@/components/workspace-settings";
import { db } from "@/lib/db";
import { PLATFORM_META } from "@/lib/platforms";
import { CLIENT_ROLE_OPTIONS, canManageClient, requireGildana } from "@/lib/tenancy";
import { teamPickers, toManagedUsers } from "@/lib/team";

export const metadata = { title: "Client" };

export default async function ClientAdmin({ params }: { params: Promise<{ id: string }> }) {
  const v = await requireGildana();
  const { id } = await params;
  if (!canManageClient(v, id)) redirect("/admin/clients");
  const c = await db.client.findUnique({
    where: { id },
    include: { users: { orderBy: [{ role: "asc" }, { email: "asc" }] }, companies: { orderBy: { name: "asc" }, include: { accounts: { select: { platform: true } } } } },
  });
  if (!c) notFound();
  const pickers = await teamPickers(c.id);
  const managers = await db.user.findMany({ where: { role: "STAFF", clientIds: { has: c.id } }, select: { name: true, email: true } });

  return (
    <>
      <PageHeader title={c.name} sub={`${c.isAgency ? "Agency client" : "Brand client"}${managers.length ? ` · account manager: ${managers.map((m) => m.name || m.email).join(", ")}` : ""}`}>
        <form action={openWorkspaceAction}><input type="hidden" name="clientId" value={c.id} /><button className="btn">Open dashboard</button></form>
      </PageHeader>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="card">
          <h2 className="mb-3 font-semibold">Settings</h2>
          <WorkspaceSettings gildana c={{ id: c.id, name: c.name, reportingCurrency: c.reportingCurrency, timezone: c.timezone, logoUrl: c.logoUrl, defaultDays: c.defaultDays, isAgency: c.isAgency, active: c.active }} />
        </div>

        <div className="card">
          <h2 className="mb-2 font-semibold">Companies</h2>
          <ul className="space-y-1 text-sm">
            {c.companies.map((co) => (
              <li key={co.id} className="flex justify-between border-b border-line py-1">
                <span>{co.name}</span>
                <span className="text-xs text-muted">{[...new Set(co.accounts.map((a) => PLATFORM_META[a.platform].label))].join(", ") || "no accounts"} · {co.accounts.length} accounts</span>
              </li>
            ))}
            {!c.companies.length && <li className="text-muted">None yet — open the dashboard and add them on the Companies page.</li>}
          </ul>
        </div>

        <div className="card lg:col-span-2">
          <h2 className="mb-1 font-semibold">Users</h2>
          <p className="mb-3 text-xs text-muted">Owners manage the team and settings; managers can change things; viewers only look. Under Edit, choose the sections and ad accounts each manager or viewer gets.</p>
          <UserManager
            users={toManagedUsers(c.users)} clientId={c.id} meId={v.id} roles={CLIENT_ROLE_OPTIONS} {...pickers}
          />
        </div>

        {v.isAdmin && (
          <form action={deleteClientAction} className="card border-red-200 lg:col-span-2">
            <h2 className="font-semibold text-bad">Delete client</h2>
            <p className="mb-2 text-sm text-muted">Removes the client, its users, companies, logins, ad accounts, all synced data and share links. Cannot be undone.</p>
            <input type="hidden" name="id" value={c.id} />
            <div className="flex gap-2"><input className="input max-w-48" name="confirm" placeholder="Type DELETE" /><button className="btn !bg-bad">Delete</button></div>
          </form>
        )}
      </div>
    </>
  );
}
