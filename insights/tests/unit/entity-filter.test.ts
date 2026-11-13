import { describe, expect, it } from "vitest";
import { applyEntity, type EntityFilter } from "@/lib/entity-filter";
import { withCampaignRollup } from "@/platforms/meta/normalize";

const camp: EntityFilter = { level: "CAMPAIGN", ids: ["c1"], campaignIds: ["c1"], adSetIds: [] };
const set: EntityFilter = { level: "ADSET", ids: ["s1", "s2"], campaignIds: ["c1"], adSetIds: ["s1", "s2"] };
const ad: EntityFilter = { level: "AD", ids: ["a1"], campaignIds: ["c1"], adSetIds: ["s1"] };

describe("campaign / ad set / ad filter", () => {
  it("leaves queries alone without a filter", () => {
    expect(applyEntity(undefined, "ACCOUNT", ["date"], {})).toEqual({ level: "ACCOUNT", by: ["date"], where: {}, filter: {} });
  });

  it("answers account totals from the filter's own level", () => {
    expect(applyEntity(camp, "ACCOUNT", ["date"], {})).toMatchObject({ level: "CAMPAIGN", by: ["date"], filter: { entityId: { in: ["c1"] } } });
    expect(applyEntity(ad, "ACCOUNT", [], { platform: "META" })).toMatchObject({ level: "AD", where: { platform: "META" }, filter: { entityId: { in: ["a1"] } } });
  });

  it("filters deeper tables by the parent column", () => {
    expect(applyEntity(camp, "ADSET", ["entityId"], {})).toMatchObject({ level: "ADSET", by: ["entityId"], filter: { campaignId: { in: ["c1"] } } });
    expect(applyEntity(set, "AD", ["entityId"], {})).toMatchObject({ level: "AD", filter: { adSetId: { in: ["s1", "s2"] } } });
    expect(applyEntity(set, "ADSET", ["entityId"], {})).toMatchObject({ level: "ADSET", filter: { entityId: { in: ["s1", "s2"] } } });
  });

  it("regroups a shallower table (campaign rows under an ad-set or ad filter) by its own id column", () => {
    const x = applyEntity(set, "CAMPAIGN", ["entityId"], { entityId: { in: ["c1", "c2"] } });
    expect(x).toEqual({ level: "ADSET", by: ["campaignId"], where: { campaignId: { in: ["c1", "c2"] } }, filter: { entityId: { in: ["s1", "s2"] } } });
    const y = applyEntity(ad, "ADSET", ["entityId", "date"], { entityId: { in: ["s1"] } });
    expect(y).toEqual({ level: "AD", by: ["adSetId", "date"], where: { adSetId: { in: ["s1"] } }, filter: { entityId: { in: ["a1"] } } });
  });
});

describe("Meta breakdown roll-up", () => {
  it("keeps ad-set rows and sums them into campaign rows", () => {
    const row = (adset: string, bucket: string, spend: number, leads: number) => ({ externalId: adset, campaignExternalId: "c1", date: "2026-11-01", dimension: "age", bucket, spend, leads, impressions: spend * 100 });
    const out = withCampaignRollup([row("s1", "25-34", 10, 2), row("s2", "25-34", 5, 1), row("s1", "35-44", 4, 0)]);
    expect(out.filter((r) => r.level === "ADSET")).toHaveLength(3);
    const camps = out.filter((r) => r.level === "CAMPAIGN");
    expect(camps).toHaveLength(2);
    expect(camps.find((r) => r.bucket === "25-34")).toMatchObject({ externalId: "c1", spend: 15, leads: 3, impressions: 1500 });
    expect(out.every((r) => !("campaignExternalId" in r))).toBe(true);
  });
});
