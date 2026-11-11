import { describe, expect, it } from "vitest";
import { explainMailError } from "@/lib/mail";

describe("explainMailError", () => {
  it("names the likely fix", () => {
    expect(explainMailError({ message: "Invalid login: 535 Incorrect authentication data", code: "EAUTH", responseCode: 535 })).toMatch(/username or password/);
    expect(explainMailError({ message: "connect ECONNREFUSED 1.2.3.4:465", code: "ESOCKET" })).toMatch(/server name and port/);
    expect(explainMailError({ message: "Hostname/IP does not match certificate's altnames" })).toMatch(/certificate/);
    expect(explainMailError({ message: "553 Sender address rejected", responseCode: 553 })).toMatch(/sender address/);
  });
});
