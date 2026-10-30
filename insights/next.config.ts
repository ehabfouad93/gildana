import type { NextConfig } from "next";

const config: NextConfig = {
  output: "standalone",
  poweredByHeader: false,
  serverExternalPackages: ["@prisma/client", "bcryptjs", "playwright-core"],
  // The bundled demo landing page (public/demo-site) is reachable at /demo-site.
  rewrites: async () => [{ source: "/demo-site", destination: "/demo-site/index.html" }],
};

export default config;
