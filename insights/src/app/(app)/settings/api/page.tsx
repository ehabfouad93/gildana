import Link from "next/link";
import { redirect } from "next/navigation";
import { revokeApiKeyAction } from "@/app/actions/customers";
import { ApiKeyForm } from "@/components/api-key-form";
import { ConfirmButton } from "@/components/confirm-button";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { canManageTeam, requireWorkspace } from "@/lib/tenancy";
import { SCOPES } from "@/modules/api/http";

export const metadata = { title: "API keys" };

/** Workspace API keys for /api/v1 (CRM, WhatsApp, website back-end, data warehouse). */
export default async function ApiKeys() {
  const v = await requireWorkspace();
  if (!canManageTeam(v)) redirect("/overview");
  const keys = await db.apiKey.findMany({ where: { clientId: v.clientId }, orderBy: { createdAt: "desc" } });
  return (
    <>
      <PageHeader title="API keys" sub="For your CRM, WhatsApp provider, website back-end or data team to send and read customer data">
        <a className="btn-ghost" href="/api/v1/docs" target="_blank" rel="noreferrer">API documentation</a>
        <Link className="btn-ghost" href="/settings">← Settings</Link>
      </PageHeader>
      <div className="card mb-4 overflow-x-auto">
        <table className="tbl">
          <thead><tr><th>Name</th><th>Key</th><th>Permissions</th><th>Last used</th><th>Created</th><th /></tr></thead>
          <tbody>
            {keys.map((k) => (
              <tr key={k.id} className={k.revokedAt ? "opacity-50" : ""}>
                <td className="font-medium">{k.name}</td>
                <td className="font-mono text-xs">{k.prefix}…</td>
                <td className="text-xs">{k.scopes.join(", ")}</td>
                <td className="text-xs text-muted">{k.lastUsedAt?.toISOString().slice(0, 16).replace("T", " ") ?? "never"}</td>
                <td className="text-xs text-muted">{k.createdAt.toISOString().slice(0, 10)}</td>
                <td>{k.revokedAt ? <span className="text-xs text-muted">revoked</span> : <form action={revokeApiKeyAction}><input type="hidden" name="id" value={k.id} /><ConfirmButton className="text-xs text-bad hover:underline" message={`Revoke ${k.name}? Anything using it stops working at once.`}>Revoke</ConfirmButton></form>}</td>
              </tr>
            ))}
            {!keys.length && <tr><td colSpan={6} className="text-muted">No keys yet.</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="card"><h2 className="mb-3 font-semibold">Create a key</h2><ApiKeyForm scopes={SCOPES.map((s) => ({ key: s.key, label: s.label }))} /></div>
    </>
  );
}
