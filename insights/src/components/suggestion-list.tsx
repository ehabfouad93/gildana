import { pickKey } from "@/components/not-measured";
import { ACTION_LABEL, type Action, type Suggestion } from "@/lib/interest-advice";
import { money } from "@/lib/format";

const TONE: Record<Action, string> = {
  scale: "bg-green-100 text-good", reuse: "bg-emerald-100 text-emerald-800", test: "bg-blue-100 text-blue-800", new: "bg-violet-100 text-violet-800",
  retest: "bg-amber-100 text-amber-800", stop: "bg-red-100 text-bad", keep: "bg-slate-100 text-slate-600",
};
const ORDER: Action[] = ["scale", "reuse", "test", "new", "retest", "stop", "keep"];

/**
 * One set of interest suggestions (from history, Claude or OpenAI). Ticked
 * rows go to the split-test page, which builds a campaign with one ad set per interest.
 */
export function SuggestionList({ items, currency, canCreate, campaignId, empty }: { items: Suggestion[]; currency: string; canCreate: boolean; campaignId?: string; empty: string }) {
  if (!items.length) return <p className="text-sm text-muted">{empty}</p>;
  const sorted = [...items].sort((a, b) => ORDER.indexOf(a.action) - ORDER.indexOf(b.action) || b.priority - a.priority);
  // Stopping needs no new campaign; everything else can be measured in one.
  const testable = (s: Suggestion) => canCreate && s.action !== "stop" && s.action !== "keep" && (s.type === "interest" || s.type === "behavior");
  return (
    <form method="get" action="/audiences/split-test">
      {campaignId && <input type="hidden" name="camp" value={campaignId} />}
      <div className="max-h-[32rem] overflow-y-auto">
        <table className="tbl">
          <thead><tr><th className="!w-8" /><th>Interest</th><th>Suggestion</th><th className="num">Exact results</th><th className="num">Cost each</th><th>Why</th></tr></thead>
          <tbody>
            {sorted.map((s, i) => (
              <tr key={`${s.type}|${s.value}|${i}`}>
                <td>{testable(s) && <input type="checkbox" name="pick" value={pickKey(s)} defaultChecked={s.action === "test" || s.action === "reuse" || s.action === "new"} aria-label={`Include ${s.value}`} />}</td>
                <td className="font-medium">{s.value}{s.type !== "interest" && <span className="ms-1 text-xs font-normal text-muted">{s.type}</span>}</td>
                <td><span className={`pill ${TONE[s.action]}`}>{ACTION_LABEL[s.action]}</span></td>
                <td className="num">{s.results ?? <span className="text-muted">—</span>}</td>
                <td className="num">{s.cpr != null ? money(s.cpr, currency) : <span className="text-muted">—</span>}</td>
                <td className="!whitespace-normal min-w-72 text-xs text-muted">{s.reason}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {sorted.some(testable) && <div className="mt-3"><button className="btn">Create campaign from ticked →</button> <span className="ms-2 text-xs text-muted">One ad set per interest, so each gets an exact result.</span></div>}
    </form>
  );
}
