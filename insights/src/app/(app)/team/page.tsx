import { redirect } from "next/navigation";
import { PageHeader } from "@/components/shell";
import { UserManager } from "@/components/user-manager";
import { db } from "@/lib/db";
import { teamPickers, toManagedUsers } from "@/lib/team";
import { CLIENT_ROLE_OPTIONS, canManageTeam, requireWorkspace } from "@/lib/tenancy";

export const metadata = { title: "Team" };

/** The workspace's own users: owners (and Gildana staff looking after the client) manage them here. */
export default async function TeamPage() {
  const v = await requireWorkspace();
  if (!canManageTeam(v)) redirect("/overview");
  const [users, pickers] = await Promise.all([
    db.user.findMany({ where: { clientId: v.clientId }, orderBy: [{ role: "asc" }, { email: "asc" }] }),
    teamPickers(v.clientId),
  ]);
  return (
    <>
      <PageHeader title="Team" sub="Who can use this dashboard, which sections they open and which ad accounts they see." />
      <div className="card mb-4 grid gap-3 text-sm sm:grid-cols-3">
        {CLIENT_ROLE_OPTIONS.map((r) => (
          <div key={r.value}><div className="font-semibold">{r.label}</div><div className="text-xs text-muted">{r.hint}</div></div>
        ))}
      </div>
      <div className="card">
        <UserManager
          users={toManagedUsers(users)} clientId={v.clientId} meId={v.id} roles={CLIENT_ROLE_OPTIONS} {...pickers}
        />
      </div>
    </>
  );
}
