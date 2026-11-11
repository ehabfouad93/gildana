import { describe, expect, it } from "vitest";
import { CURRENCY_CODES } from "@/lib/currencies";
import { FALLBACK } from "@/lib/fx-rates";
import { convert, parseRates } from "@/lib/fx";

describe("exchange rates", () => {
  it("keeps only valid units-per-USD rates from the feed", () => {
    expect(parseRates({ result: "success", rates: { USD: 1, EGP: 48.9, BAD: -1, xx: 2, EUR: "0.9", AED: 3.6725 } })).toEqual({ USD: 1, EGP: 48.9, AED: 3.6725 });
    expect(parseRates(null)).toEqual({});
  });

  it("converts through USD and leaves same-currency amounts alone", () => {
    const t = { USD: 1, EGP: 50, SAR: 3.75 };
    expect(convert(500, "EGP", "USD", t)).toBe(10);
    expect(convert(10, "USD", "SAR", t)).toBeCloseTo(37.5);
    expect(convert(123, "EGP", "EGP", t)).toBe(123);
  });

  it("has a fallback rate for every currency offered in settings", () => {
    for (const c of CURRENCY_CODES) expect(FALLBACK[c], c).toBeGreaterThan(0);
  });
});
