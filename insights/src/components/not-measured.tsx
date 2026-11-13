import type { NotMeasured } from "@/lib/queries";

/** Encodes one targeting option for the split-test page's `pick` parameter. */
export const pickKey = (x: { type: string; externalId: string; value: string }) => `${x.type}~${x.externalId}~${x.value}`;

/**
 * Interests that have only ever shared an ad set with others. Meta reports
 * per ad set, so there is no exact result for them — they are listed without
 * numbers, ready to be ticked for a split test (one interest per ad set).
 */
export function NotMeasuredList({ items, canTest, unit = "ad set" }: { items: NotMeasured[]; canTest: boolean; unit?: string }) {
  if (!items.length) return null;
  const testable = (x: NotMeasured) => canTest && x.platform === "META" && (x.type === "interest" || x.type === "behavior") && !!x.externalId;
  return (
    <form method="get" action="/audiences/split-test" className="card">
      <div className="mb-2 flex flex-wrap items-center gap-3">
        <h3 className="text-sm font-semibold">Not measured yet ({items.length})</h3>
        {items.some(testable) && <button className="btn ms-auto">Test ticked interests →</button>}
      </div>
      <p className="mb-3 text-xs text-muted">
        These only run together with other interests in the same {unit}. Meta reports results per {unit}, not per interest, so there is no exact number for them.
        Tick the ones you want to measure and create a split test: one {unit} per interest.
      </p>
      <div className="max-h-96 overflow-y-auto">
        <table className="tbl">
          <thead><tr><th className="!w-8" /><th>Option</th><th>Type</th><th>Used in</th></tr></thead>
          <tbody>
            {items.map((x) => (
              <tr key={`${x.type}|${x.value}`}>
                <td>{testable(x) && <input type="checkbox" name="pick" value={pickKey(x)} aria-label={`Test ${x.value}`} />}</td>
                <td className="font-medium">{x.value}</td>
                <td className="capitalize">{x.type.replace(/_/g, " ")}</td>
                <td className="!whitespace-normal text-xs text-muted">{x.adSets.length} {unit}{x.adSets.length > 1 ? "s" : ""}: {x.adSets.slice(0, 3).map((a) => a.name).join(" · ")}{x.adSets.length > 3 ? ` +${x.adSets.length - 3}` : ""}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </form>
  );
}
