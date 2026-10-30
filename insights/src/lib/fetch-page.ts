/** Fetches one page for the SEO/GEO crawlers, following up to 5 redirects by hand so they can be reported. */
export const CRAWLER_UA = "Mozilla/5.0 (compatible; GildanaInsightsBot/1.0; +https://gildana.net/bot)";

export async function fetchPage(url: string, timeoutMs = 15_000) {
  const started = Date.now();
  let current = url;
  let redirectTo = "";
  for (let hop = 0; hop < 5; hop++) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeoutMs);
    try {
      const res = await fetch(current, { redirect: "manual", signal: ctrl.signal, headers: { "user-agent": CRAWLER_UA, accept: "text/html,*/*" } });
      if (res.status >= 300 && res.status < 400 && res.headers.get("location")) {
        current = new URL(res.headers.get("location")!, current).toString();
        if (!redirectTo) redirectTo = current;
        continue;
      }
      const ctype = res.headers.get("content-type") ?? "";
      const body = /html|xml|text/.test(ctype) ? await res.text() : "";
      return { url, finalUrl: current, status: res.status, redirectTo, html: body, ctype, ms: Date.now() - started, bytes: body.length, headers: res.headers };
    } catch (e) {
      return { url, finalUrl: current, status: 0, redirectTo, html: "", ctype: "", ms: Date.now() - started, bytes: 0, headers: new Headers(), error: (e as Error).message };
    } finally {
      clearTimeout(t);
    }
  }
  return { url, finalUrl: current, status: 310, redirectTo, html: "", ctype: "", ms: Date.now() - started, bytes: 0, headers: new Headers(), error: "Too many redirects" };
}

export async function fetchText(url: string): Promise<{ status: number; text: string }> {
  const r = await fetchPage(url, 10_000);
  return { status: r.status, text: r.html };
}
