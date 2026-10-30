export { auth as middleware } from "@/auth";

export const config = {
  runtime: "nodejs",
  matcher: ["/((?!_next/static|_next/image|favicon.ico|t.js|.*\\.(?:png|svg|jpg|ico|css)$).*)"],
};
