export { auth as middleware } from "@/auth";

export const config = {
  runtime: "nodejs",
  matcher: ["/((?!_next/static|_next/image|favicon.ico|vendor|t.js|demo-site|api/collect|robots.txt|sitemap.xml|llms.txt|.*\\.(?:png|svg|jpg|ico|css)$).*)"],
};
