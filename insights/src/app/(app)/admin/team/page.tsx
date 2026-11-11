import { PageHeader } from "@/components/shell";
import { UserManager } from "@/components/user-manager";
import { db } from "@/lib/db";
import { toManagedUsers } from "@/lib/team";
import { GILDANA_ROLE_OPTIONS, requireAdmin } from "@/lib/tenancy";

export const metadata = { title: "Gildana team" };

/** Gildana's own people: admins see everything; account managers only their assigned clients. */
export default async function StaffPage() {
  const v = await requireAdmin();
  const [users, clients] = await Promise.all([
    db.user.findMany({ where: { clientId: null }, orderBy: [{ role: "asc" }, { email: "asc" }] }),
    db.client.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
  ]);
  return (
    <>
      <PageHeader title="Gildana team" sub="Admins and account managers. Account managers see only the clients you assign them." />
      <div className="card">
        <UserManager
          users={toManagedUsers(users)} clientId={null} meId={v.id} roles={GILDANA_ROLE_OPTIONS} clients={clients}
        />
      </div>
    </>
  );
}
