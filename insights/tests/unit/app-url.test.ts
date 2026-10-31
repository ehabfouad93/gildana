import { afterEach, describe, expect, it } from "vitest";
import { publicUrl } from "@/lib/app-url";

const req = (headers: Record<string, string> = {}) => new Request("http://localhost:3000/api/x", { headers });

describe("publicUrl", () => {
  afterEach(() => { delete process.env.APP_URL; });
  it("uses APP_URL first", () => {
    process.env.APP_URL = "https://insights.gildana.net";
    expect(publicUrl("/connections?ok=1", req({ "x-forwarded-host": "other.test" })).toString()).toBe("https://insights.gildana.net/connections?ok=1");
  });
  it("falls back to the proxy's forwarded host, then to the request", () => {
    expect(publicUrl("/a", req({ "x-forwarded-host": "insights.gildana.net", "x-forwarded-proto": "https" })).toString()).toBe("https://insights.gildana.net/a");
    expect(publicUrl("/a", req()).toString()).toBe("http://localhost:3000/a");
  });
});
