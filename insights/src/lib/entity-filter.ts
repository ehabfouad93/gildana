/**
 * The campaign / ad set / ad filter of the analysis pages. Daily stats are
 * stored once per level (account, campaign, ad set, ad), each ad-set and ad
 * row carrying its parents' ids — so a question asked at one level can be
 * answered from another when a filter is on.
 */

export type StatLevel = "ACCOUNT" | "CAMPAIGN" | "ADSET" | "AD";
export type EntityLevel = Exclude<StatLevel, "ACCOUNT">;

export type EntityFilter = {
  level: EntityLevel;
  ids: string[];
  /** Campaigns the filtered items belong to (the ids themselves for a campaign filter). */
  campaignIds: string[];
  /** Ad sets the filtered items belong to (empty for a campaign filter). */
  adSetIds: string[];
};

type Col = "date" | "entityId" | "campaignId" | "adSetId";
const RANK: Record<StatLevel, number> = { ACCOUNT: 0, CAMPAIGN: 1, ADSET: 2, AD: 3 };
/** The column that holds a level's id on rows of a deeper level. */
const PARENT_COL: Record<EntityLevel, "campaignId" | "adSetId" | "entityId"> = { CAMPAIGN: "campaignId", ADSET: "adSetId", AD: "entityId" };

/**
 * Rewrites a stats query for the filter: which level to read, which columns
 * to group by, the caller's own conditions, and the filter's condition.
 *  - caller at the filter's level or deeper: same level, filtered by the parent column;
 *  - caller shallower (e.g. account totals with an ad-set filter): read the
 *    filter's level and regroup by the caller's own id column.
 */
export function applyEntity<W extends Record<string, unknown>>(e: EntityFilter | undefined, level: StatLevel, by: Col[], where: W): { level: StatLevel; by: Col[]; where: Record<string, unknown>; filter: Record<string, unknown> } {
  if (!e || !e.ids.length) return { level, by, where, filter: {} };
  if (RANK[level] >= RANK[e.level]) {
    const col = level === e.level ? "entityId" : PARENT_COL[e.level];
    return { level, by, where, filter: { [col]: { in: e.ids } } };
  }
  // The caller's "entityId" is a campaign or ad-set id; on the deeper rows that id lives in a parent column.
  const own = level === "ACCOUNT" ? null : PARENT_COL[level as EntityLevel];
  const { entityId, ...rest } = where as Record<string, unknown>;
  return {
    level: e.level,
    by: by.map((c) => (c === "entityId" && own ? own : c)),
    where: entityId !== undefined && own ? { ...rest, [own]: entityId } : rest,
    filter: { entityId: { in: e.ids } },
  };
}
