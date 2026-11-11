import { describe, expect, it } from "vitest";
import { throttleWait } from "@/lib/http";

const h = (o: Record<string, string> = {}) => new Headers(o);

describe("platform throttling", () => {
  it("recognises Meta's ad-account limit and waits as long as Meta says", () => {
    const body = { error: { code: 80004, message: "There have been too many calls to this ad-account. Wait a bit and try again." } };
    const buc = JSON.stringify({ "123": [{ type: "ads_insights", call_count: 100, estimated_time_to_regain_access: 23 }] });
    expect(throttleWait(body, h({ "x-business-use-case-usage": buc }))).toBe(23 * 60_000);
  });

  it("waits at least 5 minutes and at most an hour", () => {
    expect(throttleWait({ error: { code: 17, message: "User request limit reached" } }, h())).toBe(5 * 60_000);
    const buc = JSON.stringify({ x: [{ estimated_time_to_regain_access: 500 }] });
    expect(throttleWait({ error: { code: 4 } }, h({ "x-business-use-case-usage": buc }))).toBe(60 * 60_000);
  });

  it("leaves other errors alone", () => {
    expect(throttleWait({ error: { code: 100, message: "Invalid parameter" } }, h())).toBeNull();
    expect(throttleWait({ error: { code: 190, message: "Error validating access token" } }, h())).toBeNull();
    expect(throttleWait(null, h())).toBeNull();
  });
});
