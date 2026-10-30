import { notFound } from "next/navigation";
import { addPromptAction, checkPromptsAction, deletePromptAction, startGeoAction } from "@/app/actions/sites";
import { Markdown } from "@/components/markdown";
import { SiteNav } from "@/components/site-nav";
import { claudeEnabled } from "@/lib/claude";
import { db } from "@/lib/db";
import type { GeoCheck } from "@/lib/geo";
import { canEdit, requireWorkspace } from "@/lib/tenancy";
import { siteForClient, webBy } from "@/lib/web";
import { lastNDays } from "@/lib/dates";

export const metadata = { title: "GEO · AI search" };
export const dynamic = "force-dynamic";

const ICON = { pass: "✓", warn: "!", fail: "✗" } as const;
const TONE = { pass: "text-good", warn: "text-amber-600", fail: "text-bad" } as const;

export default async function Geo({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const v = await requireWorkspace();
  const site = await siteForClient(v.clientId, id);
  if (!site) notFound();
  const edit = canEdit(v);
  const [latest, done, prompts, aiTraffic] = await Promise.all([
    db.geoAudit.findFirst({ where: { siteId: id }, orderBy: { createdAt: "desc" } }),
    db.geoAudit.findFirst({ where: { siteId: id, status: "done" }, orderBy: { createdAt: "desc" } }),
    db.aiPrompt.findMany({ where: { siteId: id }, include: { results: { orderBy: { checkedAt: "desc" }, take: 8 } }, orderBy: { createdAt: "asc" } }),
    webBy(id, lastNDays(30), "referrer", 200),
  ]);
  const checks = (done?.checks ?? []) as GeoCheck[];
  const groups = [...new Set(checks.map((c) => c.group))];
  const busy = latest && (latest.status === "queued" || latest.status === "running");
  const ai = aiTraffic.filter((r) => /chatgpt|openai|perplexity|claude\.ai|gemini|copilot|you\.com|poe\.com|deepseek/.test(r.key));
  const aiSessions = ai.reduce((a, r) => a + r.sessions, 0);
  const aiConv = ai.reduce((a, r) => a + r.conversions, 0);

  return (
    <>
      <SiteNav id={id} active="/geo" name={site.name} domain={site.domain} />
      <div className="mb-4 grid gap-3 md:grid-cols-[200px_200px_1fr_auto]">
        <div className="card text-center">
          <div className="text-xs text-muted">AI-readiness score</div>
          <div className={`text-4xl font-bold ${(done?.score ?? 0) >= 75 ? "text-good" : (done?.score ?? 0) >= 50 ? "text-amber-600" : "text-bad"}`}>{done?.score ?? "—"}</div>
          <div className="text-xs text-muted">{done ? done.createdAt.toISOString().slice(0, 10) : "not audited yet"}</div>
        </div>
        <div className="card text-center">
          <div className="text-xs text-muted">Visits from AI assistants (30d)</div>
          <div className="text-4xl font-bold">{aiSessions}</div>
          <div className="text-xs text-muted">{aiConv} conversions · {ai.map((r) => r.key).join(", ") || "none yet"}</div>
        </div>
        <div className="card text-sm text-muted">
          GEO is how well ChatGPT, Claude, Perplexity, Gemini and Google AI Overviews can <b>reach</b>, <b>understand</b> and <b>trust</b> this site — and whether they actually <b>name the brand</b> when people ask. The audit checks the site; AI visibility (below) asks the assistants.
        </div>
        {edit && (
          <form action={startGeoAction} className="card flex items-center">
            <input type="hidden" name="id" value={id} />
            <button className="btn" disabled={!!busy}>{busy ? "Auditing…" : "Run GEO audit"}</button>
          </form>
        )}
      </div>
      {latest?.status === "failed" && <div className="card mb-4 text-sm text-bad">Last audit failed: {latest.error}</div>}

      {done && (
        <div className="grid gap-4 xl:grid-cols-[1.2fr_1fr]">
          <div className="space-y-4">
            {groups.map((g) => (
              <div key={g} className="card">
                <h2 className="mb-2 font-semibold">{g}</h2>
                <ul className="space-y-3">
                  {checks.filter((c) => c.group === g).map((c) => (
                    <li key={c.id} className="flex gap-3">
                      <span className={`mt-0.5 w-4 text-center font-bold ${TONE[c.status]}`}>{ICON[c.status]}</span>
                      <div className="min-w-0 text-sm">
                        <div className="font-medium">{c.label}</div>
                        <div className="text-muted">{c.detail}</div>
                        {c.status !== "pass" && c.fix && <div className="mt-1 rounded bg-bg p-2 text-xs">{c.fix}</div>}
                      </div>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
          <div className="card">
            <h2 className="mb-2 font-semibold">AI review</h2>
            {done.aiReview ? <Markdown text={done.aiReview} /> : <p className="text-sm text-muted">{claudeEnabled() ? "No review in this audit." : "Set ANTHROPIC_API_KEY on the server to add a Claude-written review: how assistants would describe the business, unanswered buyer questions, prioritised fixes and a draft llms.txt."}</p>}
          </div>
        </div>
      )}

      <div className="card mt-4">
        <div className="mb-2 flex flex-wrap items-center gap-2">
          <h2 className="font-semibold">AI visibility — are we the answer?</h2>
          <span className="text-sm text-muted">Questions buyers ask assistants. Each is asked weekly, with and without web search; we record whether the brand is named, its rank and whether the site is cited.</span>
          {edit && prompts.length > 0 && claudeEnabled() && <form action={checkPromptsAction} className="ms-auto"><input type="hidden" name="siteId" value={id} /><button className="btn-ghost">Check now</button></form>}
        </div>
        {!claudeEnabled() && <p className="mb-2 text-sm text-amber-700">Set ANTHROPIC_API_KEY on the server to run these checks.</p>}
        <div className="space-y-3">
          {prompts.map((p) => {
            const latestByEngine = new Map<string, (typeof p.results)[number]>();
            for (const r of p.results) if (!latestByEngine.has(r.engine)) latestByEngine.set(r.engine, r);
            return (
              <div key={p.id} className="rounded-lg border border-line p-3 text-sm">
                <div className="flex items-start gap-2">
                  <div className="flex-1"><b>“{p.prompt}”</b> <span className="text-muted">· brand: {p.brand}</span></div>
                  {edit && <form action={deletePromptAction}><input type="hidden" name="siteId" value={id} /><input type="hidden" name="id" value={p.id} /><button className="text-xs text-bad">Remove</button></form>}
                </div>
                <div className="mt-2 grid gap-2 md:grid-cols-2">
                  {[...latestByEngine.values()].map((r) => (
                    <details key={r.id} className="rounded bg-bg p-2">
                      <summary className="cursor-pointer">
                        <span className="font-medium">{r.engine}</span>{" "}
                        <span className={r.mentioned ? "text-good" : "text-bad"}>{r.mentioned ? `mentioned${r.position ? ` · #${r.position}` : ""}` : "not mentioned"}</span>
                        {r.cited && <span className="text-good"> · site cited</span>}
                        <span className="text-xs text-muted"> · {r.checkedAt.toISOString().slice(0, 10)}</span>
                        {r.competitors && <div className="text-xs text-muted">Also named: {r.competitors}</div>}
                      </summary>
                      <div className="mt-2 max-h-80 overflow-auto"><Markdown text={r.answer} /></div>
                    </details>
                  ))}
                  {!latestByEngine.size && <span className="text-xs text-muted">Not checked yet.</span>}
                </div>
              </div>
            );
          })}
        </div>
        {edit && (
          <form action={addPromptAction} className="mt-3 grid gap-2 md:grid-cols-[1fr_200px_auto]">
            <input type="hidden" name="siteId" value={id} />
            <input className="input" name="prompt" placeholder="e.g. Best compounds in New Cairo with installment plans?" required />
            <input className="input" name="brand" defaultValue={site.company.name.split(" ").slice(0, 2).join(" ")} required />
            <button className="btn-ghost">Track question</button>
          </form>
        )}
      </div>
    </>
  );
}
