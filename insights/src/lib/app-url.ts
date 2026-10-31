/**
 * Absolute URL on the app's public address. Behind a proxy, `req.url` carries
 * the server's own origin (http://localhost:3000), so redirects built from it
 * send browsers to localhost. APP_URL wins; then the proxy's forwarded headers.
 */
export function publicUrl(path: string, req: Request): URL {
  const app = process.env.APP_URL?.trim();
  if (app) return new URL(path, app);
  const host = req.headers.get("x-forwarded-host")?.split(",")[0].trim();
  if (host) {
    const proto = req.headers.get("x-forwarded-proto")?.split(",")[0].trim() || "https";
    return new URL(path, `${proto}://${host}`);
  }
  return new URL(path, req.url);
}
