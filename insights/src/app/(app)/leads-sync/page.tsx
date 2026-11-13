import Link from "next/link";
import { redirect } from "next/navigation";
import { deleteDestinationAction, toggleDestinationAction } from "@/app/actions/leads-sync";
import { AddDestination, TestDestination } from "@/components/leads-sync-forms";
import { PageHeader } from "@/components/shell";
import { decrypt } from "@/lib/crypto";
import { db } from "@/lib/db";
import { canEdit, canFeature, companyWhere, requireModule } from "@/lib/tenancy";

export const metadata = { title: "Leads sync" };

const SCRIPT = `function doPost(e) {
  var lead = JSON.parse(e.postData.contents);
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheets()[0];
  var f = lead.fields || {};
  sheet.appendRow([lead.received_at, lead.company, lead.platform, lead.campaign, lead.ad_set, lead.ad, lead.form,
    f.full_name || f.name || "", f.phone_number || f.phone || "", f.email || "", JSON.stringify(f)]);
  return ContentService.createTextOutput("ok");
}`;

/** Send every new lead, as it arrives, to a CRM, an automation tool or a Google Sheet. */
export default async function LeadsSync() {
  const v = await requireModule("companies");
  if (!canFeature(v, "companies.leads_sync")) redirect("/companies");
  const edit = canEdit(v);
  const [companies, dests] = await Promise.all([
    db.company.findMany({ where: { clientId: v.clientId, ...companyWhere(v) }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
    db.leadDestination.findMany({ where: { clientId: v.clientId, ...(v.access ? { companyId: { in: v.companyIds } } : {}) }, orderBy: { createdAt: "asc" } }),
  ]);
  const counts = await db.leadDelivery.groupBy({ by: ["destinationId", "status"], where: { destinationId: { in: dests.map((d) => d.id) } }, _count: true });
  const n = (id: string, status: string) => counts.find((c) => c.destinationId === id && c.status === status)?._count ?? 0;
  const host = (enc: string) => { try { const u = new URL(decrypt(enc)); return `${u.hostname}${u.pathname.length > 1 ? "/…" : ""}`; } catch { return "—"; } };

  return (
    <>
      <PageHeader title="Leads sync" sub="Every new lead is sent to your CRM or sheet within about a minute of arriving">
        <Link className="btn-ghost" href="/companies">← Companies</Link>
      </PageHeader>
      <div className="card mb-4 overflow-x-auto">
        <table className="tbl">
          <thead><tr><th>Destination</th><th>Leads of</th><th>Sends to</th><th className="num">Sent</th><th>Status</th><th /></tr></thead>
          <tbody>
            {dests.map((d) => (
              <tr key={d.id} className={d.enabled ? "" : "opacity-50"}>
                <td className="font-medium">{d.name}</td>
                <td>{d.companyId ? companies.find((c) => c.id === d.companyId)?.name ?? "—" : "All companies"}</td>
                <td className="font-mono text-xs">{host(d.urlEnc)}</td>
                <td className="num">{n(d.id, "sent")}</td>
                <td className="!whitespace-normal text-xs">
                  {!d.enabled ? <span className="text-muted">paused</span> : d.lastError ? <span className="text-bad">⚠ {d.lastError}{n(d.id, "failed") ? ` · ${n(d.id, "failed")} waiting to retry` : ""}</span> : <span className="text-good">● {d.lastSentAt ? `last sent ${d.lastSentAt.toISOString().slice(0, 16).replace("T", " ")} UTC` : "waiting for the first lead"}</span>}
                </td>
                <td>
                  {edit && (
                    <div className="flex flex-wrap justify-end gap-3 text-xs">
                      <TestDestination id={d.id} />
                      <form action={toggleDestinationAction}><input type="hidden" name="id" value={d.id} /><button className="text-muted hover:underline">{d.enabled ? "Pause" : "Resume"}</button></form>
                      <form action={deleteDestinationAction}><input type="hidden" name="id" value={d.id} /><button className="text-bad hover:underline">Delete</button></form>
                    </div>
                  )}
                </td>
              </tr>
            ))}
            {!dests.length && <tr><td colSpan={6} className="text-muted">No destination yet. Leads stay in the dashboard only.</td></tr>}
          </tbody>
        </table>
      </div>
      {edit && <div className="card mb-4"><h2 className="mb-3 font-semibold">Add a destination</h2><AddDestination companies={companies} mustPick={!!v.access} /></div>}
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="card text-sm">
          <h2 className="mb-2 font-semibold">What is sent</h2>
          <p className="mb-2 text-muted">One request per lead (JSON, POST), only for leads that arrive after the destination is added. If the destination is down it is retried after 2, 10, 30 and 120 minutes.</p>
          <pre className="overflow-x-auto rounded-lg bg-bg p-3 text-xs">{`{
  "id": "…", "received_at": "2026-11-15T09:30:00.000Z",
  "platform": "META", "account": "…", "company": "…",
  "campaign": "…", "ad_set": "…", "ad": "…", "form": "…",
  "fields": { "full_name": "…", "phone_number": "…", "email": "…" }
}`}</pre>
          <p className="mt-2 text-muted">Works with any CRM or tool that accepts a webhook: Zapier (“Webhooks by Zapier → Catch Hook”), Make (“Custom webhook”), HubSpot, Zoho, Odoo and others.</p>
        </div>
        <div className="card text-sm">
          <h2 className="mb-2 font-semibold">Into a Google Sheet</h2>
          <ol className="mb-2 list-decimal space-y-1 ps-5 text-muted">
            <li>Open the sheet → Extensions → Apps Script, and paste this.</li>
            <li>Deploy → New deployment → Web app → “Execute as: Me”, “Who has access: Anyone”.</li>
            <li>Copy the web app address (it ends with <code>/exec</code>) and add it above as a destination.</li>
          </ol>
          <pre className="overflow-x-auto rounded-lg bg-bg p-3 text-xs">{SCRIPT}</pre>
        </div>
      </div>
    </>
  );
}
