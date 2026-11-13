import { describe, expect, it } from "vitest";
import { detectStage, stageOf } from "@/lib/funnel";

const a = (value: string, type = "audience", exclude = false) => ({ type, value, exclude });

describe("funnel stage", () => {
  it("reads the stage from who the ad set targets", () => {
    expect(detectStage([{ type: "interest", value: "Real estate" }])).toBe("prospecting");
    expect(detectStage([a("Lookalike (EG, 1%) - Leads")])).toBe("prospecting");
    expect(detectStage([a("LAL 2% buyers", "lookalike")])).toBe("prospecting");
    expect(detectStage([a("Website visitors 30d")])).toBe("retargeting");
    expect(detectStage([a("Video viewers 75%"), a("Instagram engagers 90d")])).toBe("reengagement");
    expect(detectStage([a("Customers list 2026")])).toBe("retention");
    // An excluded audience doesn't make it retargeting.
    expect(detectStage([{ type: "interest", value: "Golf" }, a("Website visitors 30d", "audience", true)])).toBe("prospecting");
  });

  it("lets a person override the detected stage", () => {
    expect(stageOf({ funnelStage: "prospecting", funnelStageManual: "retention" })).toBe("retention");
    expect(stageOf({ funnelStage: "retargeting", funnelStageManual: "" })).toBe("retargeting");
    expect(stageOf({ funnelStage: "", funnelStageManual: "" })).toBe("prospecting");
  });
});
