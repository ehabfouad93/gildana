import { notFound } from "next/navigation";
import { createUserAction, deleteClientAction, openWorkspaceAction, resetPasswordAction, toggleUserAction, updateClientAction } from "@/app/actions/admin";
import { PageHeader } from "@/components/shell";
import { InviteForm } from "./invite-form";
import { sendResetLinkAction } from "@/app/actions/password";
import { db } from "@/lib/db";
import { mailEnabled } from "@/lib/mail";
import { PLATFORM_META } from "@/lib/platforms";
import { requireAdmin } from "@/lib/tenancy";

export const metadata = { title: "Client" };

export default async function ClientAdmin({ params }: { params: Promise<{ id: string }> }) {
  await requireAdmin();
  const { id } = await params;
  const c = await db.client.findUnique({
    where: { id },
    include: { users: { orderBy: { email: "asc" } }, companies: { orderBy: { name: "asc" }, include: { accounts: { select: { platform: true } } } } },
  });
  if (!c) notFound();

  return (
    <>
      <PageHeader title={c.name} sub={c.isAgency ? "Agency client" : "Brand client"}>
        <form action={openWorkspaceAction}><input type="hidden" name="clientId" value={c.id} /><button className="btn">Open dashboard</button></form>
      </PageHeader>
      <div className="grid gap-4 lg:grid-cols-2">
        <form action={updateClientAction} className="card space-y-3">
          <h2 className="font-semibold">Settings</h2>
          <input type="hidden" name="id" value={c.id} />
          <div><label className="label">Name</label><input className="input" name="name" defaultValue={c.name} required /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Reporting currency</label><input className="input" name="currency" defaultValue={c.reportingCurrency} maxLength={3} /></div>
            <div><label className="label">Timezone</label><input className="input" name="timezone" defaultValue={c.timezone} /></div>
          </div>
          <div className="flex gap-4 text-sm">
            <label className="flex items-center gap-1"><input type="checkbox" name="isAgency" defaultChecked={c.isAgency} /> Agency (manages several companies)</label>
            <label className="flex items-center gap-1"><input type="checkbox" name="active" defaultChecked={c.active} /> Active</label>
          </div>
          <button className="btn">Save</button>
        </form>

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
          <h2 className="mb-2 font-semibold">Users</h2>
          <table className="tbl mb-4">
            <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Last login</th><th /></tr></thead>
            <tbody>
              {c.users.map((u) => (
                <tr key={u.id} className={u.active ? "" : "opacity-50"}>
                  <td>{u.email}</td><td>{u.name}</td><td>{u.role === "VIEWER" ? "Viewer (read-only)" : "Client"}</td>
                  <td className="text-xs">{u.lastLoginAt?.toISOString().slice(0, 16).replace("T", " ") ?? "never"}</td>
                  <td className="flex gap-2">
                    <form action={resetPasswordAction} className="flex gap-1">
                      <input type="hidden" name="userId" value={u.id} />
                      <input className="input !w-36 !py-1" name="password" type="password" placeholder="New password" minLength={8} />
                      <button className="btn-ghost !py-1">Reset</button>
                    </form>
                    {mailEnabled() && <form action={sendResetLinkAction}><input type="hidden" name="userId" value={u.id} /><button className="btn-ghost !py-1" title="Email this user a one-time link to choose a new password">Email reset link</button></form>}
                    <form action={toggleUserAction}><input type="hidden" name="userId" value={u.id} /><button className="btn-ghost !py-1">{u.active ? "Disable" : "Enable"}</button></form>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <h3 className="mb-2 mt-2 text-sm font-semibold">Invite someone</h3>
          <p className="mb-2 text-xs text-muted">They get an email (or you send them the link) to set their own password, then a short wizard to connect their ad accounts.</p>
          <InviteForm clientId={c.id} />
          <h3 className="mb-2 mt-5 text-sm font-semibold">Or create a user with a password</h3>
          <form action={createUserAction} className="grid items-end gap-2 md:grid-cols-5">
            <input type="hidden" name="clientId" value={c.id} />
            <div><label className="label">Email</label><input className="input" name="email" type="email" required /></div>
            <div><label className="label">Name</label><input className="input" name="name" /></div>
            <div><label className="label">Password</label><input className="input" name="password" type="password" minLength={8} required /></div>
            <div><label className="label">Role</label><select className="input" name="role"><option value="CLIENT">Client (can edit)</option><option value="VIEWER">Viewer (read-only)</option></select></div>
            <button className="btn">Add user</button>
          </form>
        </div>

        <form action={deleteClientAction} className="card border-red-200 lg:col-span-2">
          <h2 className="font-semibold text-bad">Delete client</h2>
          <p className="mb-2 text-sm text-muted">Removes the client, its companies, logins, ad accounts, all synced data and share links. Cannot be undone.</p>
          <input type="hidden" name="id" value={c.id} />
          <div className="flex gap-2"><input className="input max-w-48" name="confirm" placeholder="Type DELETE" /><button className="btn !bg-bad">Delete</button></div>
        </form>
      </div>
    </>
  );
}
