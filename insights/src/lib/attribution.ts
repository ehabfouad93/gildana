/**
 * Per-interest results for platforms that only report per ad set (Meta).
 *
 * Model: an ad set's spend is shared evenly by its interests, and each
 * interest converts at its own rate per unit of spend:
 *
 *     conversions_i ≈ Σ_j  (spend_i / k_i) · present_ij · rate_j
 *
 * Fitted by ridge-regularised non-negative least squares (coordinate
 * descent), so rates are ≥ 0 and interests that always appear together are
 * shrunk rather than given arbitrary splits. An estimate is only reported
 * when the data can tell an interest apart from the others.
 */

export type AdSetObs = { id: string; spend: number; conversions: number; interests: string[] };

export type InterestEstimate = {
  interest: string;
  adSets: number;
  rate: number; // conversions per unit of spend
  spend: number; // spend attributed to the interest
  conversions: number;
  cpa: number | null;
  identifiable: boolean;
  reason: string;
};

export function nnlsRidge(X: number[][], y: number[], lambda: number, iters = 400): number[] {
  const n = X.length, p = X[0]?.length ?? 0;
  const b = new Array(p).fill(0);
  const r = y.slice(); // residual y - Xb
  const colSq = Array.from({ length: p }, (_, j) => X.reduce((a, row) => a + row[j] * row[j], 0));
  for (let it = 0; it < iters; it++) {
    let delta = 0;
    for (let j = 0; j < p; j++) {
      if (!colSq[j]) continue;
      let dot = 0;
      for (let i = 0; i < n; i++) dot += X[i][j] * (r[i] + X[i][j] * b[j]);
      const nb = Math.max(0, dot / (colSq[j] + lambda));
      const d = nb - b[j];
      if (d) {
        for (let i = 0; i < n; i++) r[i] -= X[i][j] * d;
        b[j] = nb;
        delta = Math.max(delta, Math.abs(d));
      }
    }
    if (delta < 1e-10) break;
  }
  return b;
}

export function estimateInterests(sets: AdSetObs[]): { estimates: InterestEstimate[]; weights: Map<string, Map<string, number>> } {
  const usable = sets.filter((s) => s.spend > 0 && s.interests.length);
  const names = [...new Set(usable.flatMap((s) => s.interests))].sort();
  const idx = new Map(names.map((n, i) => [n, i]));
  if (!names.length) return { estimates: [], weights: new Map() };

  // Scale spend so the ridge penalty is comparable across currencies and account sizes.
  const scale = usable.reduce((a, s) => a + s.spend, 0) / usable.length || 1;
  const X = usable.map((s) => {
    const row = new Array(names.length).fill(0);
    for (const n of s.interests) row[idx.get(n)!] = s.spend / scale / s.interests.length;
    return row;
  });
  const y = usable.map((s) => s.conversions);
  // A light penalty: enough to split interests that always appear together evenly, small enough not to bias the rest.
  const avgColSq = X[0].map((_, j) => X.reduce((a, row) => a + row[j] * row[j], 0)).reduce((a, b) => a + b, 0) / names.length;
  const rates = nnlsRidge(X, y, 1e-3 * avgColSq);

  // Lead / conversion shares within each ad set, by fitted contribution.
  const weights = new Map<string, Map<string, number>>();
  const attributed = new Array(names.length).fill(0);
  const spendBy = new Array(names.length).fill(0);
  usable.forEach((s, i) => {
    const contrib = s.interests.map((n) => X[i][idx.get(n)!] * rates[idx.get(n)!]);
    const sum = contrib.reduce((a, b) => a + b, 0);
    const w = new Map<string, number>();
    s.interests.forEach((n, k) => {
      const share = sum > 0 ? contrib[k] / sum : 1 / s.interests.length;
      w.set(n, share);
      attributed[idx.get(n)!] += s.conversions * share;
      spendBy[idx.get(n)!] += s.spend / s.interests.length;
    });
    weights.set(s.id, w);
  });

  const estimates = names.map((n, j) => {
    const mine = usable.filter((s) => s.interests.includes(n));
    const mixes = new Set(mine.map((s) => s.interests.filter((x) => x !== n).sort().join("|")));
    const alone = mine.some((s) => s.interests.length === 1);
    // Identifiable: seen alone, or in 3+ ad sets with at least two different sets of companions.
    const identifiable = alone || (mine.length >= 3 && mixes.size >= 2);
    return {
      interest: n, adSets: mine.length, rate: rates[j] / scale, spend: spendBy[j], conversions: attributed[j],
      cpa: attributed[j] > 0 ? spendBy[j] / attributed[j] : null, identifiable,
      reason: identifiable ? (alone ? "calibrated by its own single-interest ad set" : `from ${mine.length} ad sets with ${mixes.size} different interest mixes`) : mine.length < 3 ? `only in ${mine.length} ad set${mine.length > 1 ? "s" : ""}` : "always paired with the same interests",
    };
  });
  return { estimates, weights };
}
