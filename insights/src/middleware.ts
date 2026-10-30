export { auth as middleware } from "@/auth";

export const config = {
  runtime: "nodejs",
  matcher: ["/((?!_next/static|_next/image|favicon.ico|vendor|t.js|demo-site|api/collect|.*\\.(?:png|svg|jpg|ico|css)$).*)"],
};
