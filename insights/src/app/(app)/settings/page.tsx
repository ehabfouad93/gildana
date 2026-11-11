import { redirect } from "next/navigation";
import { PageHeader } from "@/components/shell";
import { WorkspaceSettings } from "@/components/workspace-settings";
import { db } from "@/lib/db";
import { canManageTeam, requireWorkspace } from "@/lib/tenancy";

export const metadata = { title: "Settings" };

export default async function SettingsPage() {
  const v = await requireWorkspace();
  if (!canManageTeam(v)) redirect("/overview");
  const c = await db.client.findUniqueOrThrow({ where: { id: v.clientId } });
  const [users, companies, accounts] = await Promise.all([
    db.user.count({ where: { clientId: c.id } }),
    db.company.count({ where: { clientId: c.id } }),
    db.adAccount.count({ where: { connection: { clientId: c.id } } }),
  ]);
  return (
    <>
      <PageHeader title="Workspace settings" sub={`${users} users · ${companies} companies · ${accounts} ad accounts`} />
      <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <div className="card">
          <WorkspaceSettings gildana={v.isGildana} c={{ id: c.id, name: c.name, reportingCurrency: c.reportingCurrency, campaignCurrency: c.campaignCurrency, timezone: c.timezone, logoUrl: c.logoUrl, defaultDays: c.defaultDays, isAgency: c.isAgency, active: c.active }} />
        </div>
        <div className="card space-y-2 text-sm">
          <h2 className="font-semibold">Also here</h2>
          <p><a className="text-accent underline" href="/team">Team</a> — add people, change roles, limit them to some companies, remove them.</p>
          <p><a className="text-accent underline" href="/connections">Ad accounts</a> — connect platforms and put accounts in companies.</p>
          <p><a className="text-accent underline" href="/account">My account</a> — your own name, language and password.</p>
        </div>
      </div>
    </>
  );
}
