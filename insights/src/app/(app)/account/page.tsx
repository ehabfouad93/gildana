import { updateProfileAction } from "@/app/actions/password";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { requireViewer } from "@/lib/tenancy";
import { ChangePasswordForm } from "./form";

export const metadata = { title: "My account" };

export default async function Account() {
  const v = await requireViewer();
  const user = await db.user.findUniqueOrThrow({ where: { id: v.id }, select: { email: true, name: true, locale: true, role: true, lastLoginAt: true } });
  return (
    <>
      <PageHeader title="My account" sub={`${user.email} · ${user.role === "ADMIN" ? "Admin" : user.role === "VIEWER" ? "Viewer" : "Client"}`} />
      <div className="grid max-w-3xl gap-4 md:grid-cols-2">
        <div className="card">
          <h2 className="mb-3 font-semibold">Change password</h2>
          <ChangePasswordForm />
        </div>
        <form action={updateProfileAction} className="card space-y-3">
          <h2 className="font-semibold">Profile</h2>
          <div><label className="label" htmlFor="name">Name</label><input className="input" id="name" name="name" defaultValue={user.name} /></div>
          <div><label className="label" htmlFor="locale">Language</label>
            <select className="input" id="locale" name="locale" defaultValue={user.locale}><option value="en">English</option><option value="ar">العربية</option></select></div>
          <button className="btn">Save</button>
          {user.lastLoginAt && <p className="text-xs text-muted">Last sign-in {user.lastLoginAt.toISOString().replace("T", " ").slice(0, 16)} UTC</p>}
        </form>
      </div>
    </>
  );
}
