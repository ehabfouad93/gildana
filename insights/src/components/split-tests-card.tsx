import Link from "next/link";
import { PauseLosersButton } from "@/components/pause-losers-button";
import { money } from "@/lib/format";
import type { SplitTestView } from "@/lib/queries";

const TONE = { winner: "bg-green-100 text-good", too_early: "bg-amber-100 text-amber-800", no_clear_winner: "bg-slate-100 text-slate-600" } as const;
const LABEL = { winner: "Winner found", too_early: "Too early", no_clear_winner: "No clear winner" } as const;

/** Each interest split test with its arms' exact results and the automatic verdict. */
export function SplitTestsCard({ tests, currency, canPause }: { tests: SplitTestView[]; currency: string; canPause: boolean }) {
  if (!tests.length) return null;
  return (
    <section className="card">
      <h2 className="mb-3 font-semibold">Interest split tests</h2>
      <div className="space-y-4">
        {tests.map((t) => {
          const losers = t.arms.filter((a) => t.verdict.loserIds.includes(a.id) && a.status !== "PAUSED");
          return (
            <div key={t.id} className="rounded-lg border border-line p-3">
              <div className="mb-2 flex flex-wrap items-center gap-2">
                <b>{t.name || "Split of one ad set"}</b>
                <span className={`pill ${TONE[t.verdict.state]}`}>{LABEL[t.verdict.state]}</span>
                <span className="text-xs text-muted">created {t.createdAt.toISOString().slice(0, 10)}{t.endsAt ? ` · ends ${t.endsAt.toISOString().slice(0, 10)}` : ""}{t.status === "created" ? " · created paused" : ""}</span>
                {t.campaignId && <Link className="ms-auto text-xs text-accent hover:underline" href={`/campaigns/${t.campaignId}?tab=targeting`}>Open campaign →</Link>}
              </div>
              <p className="mb-2 text-sm">{t.verdict.note}</p>
              <table className="tbl">
                <thead><tr><th>Interest</th><th>Ad set</th><th className="num">Spend</th><th className="num">Results</th><th className="num">Cost each</th></tr></thead>
                <tbody>
                  {t.arms.map((a) => (
                    <tr key={a.id} className={a.status === "PAUSED" ? "opacity-60" : ""}>
                      <td className="font-medium">{a.value}{a.id === t.verdict.winnerId && <span className="pill ms-2 bg-green-100 text-good">winner</span>}{t.verdict.loserIds.includes(a.id) && <span className="pill ms-2 bg-red-100 text-bad">losing</span>}</td>
                      <td className="text-xs text-muted">{a.status.toLowerCase().replace(/_/g, " ")}</td>
                      <td className="num">{money(a.spend, currency, 0)}</td>
                      <td className="num">{a.results}</td>
                      <td className="num">{a.cpr != null ? money(a.cpr, currency) : "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {canPause && losers.length > 0 && <div className="mt-2"><PauseLosersButton testId={t.id} names={losers.map((l) => l.value)} /></div>}
            </div>
          );
        })}
      </div>
    </section>
  );
}
