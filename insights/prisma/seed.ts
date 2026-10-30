/**
 * Creates the first admin, and — unless SEED_DEMO=false — a demo agency
 * client with two companies and a demo login on every platform, so the
 * dashboards have data straight away. Safe to run more than once.
 */
import bcrypt from "bcryptjs";
import { PLATFORMS } from "../src/lib/platforms";
import { db } from "../src/lib/db";
import { enqueueInitialSync } from "../src/lib/jobs";
import { demoAccounts } from "../src/platforms/demo";
import { generateDemoTraffic } from "../src/lib/demo-traffic";

async function main() {
  const email = (process.env.SEED_ADMIN_EMAIL || "admin@gildana.net").toLowerCase();
  const password = process.env.SEED_ADMIN_PASSWORD || "change-me-now";
  await db.user.upsert({
    where: { email },
    create: { email, name: "Gildana Admin", role: "ADMIN", passwordHash: await bcrypt.hash(password, 12) },
    update: {},
  });
  console.log(`admin: ${email}`);

  if (process.env.SEED_DEMO === "false") return;
  if (await db.client.findFirst({ where: { name: "Demo Agency" } })) {
    console.log("demo client already exists");
    await seedDemoSite();
    return;
  }

  const client = await db.client.create({ data: { name: "Demo Agency", isAgency: true, reportingCurrency: "USD" } });
  const companies = await Promise.all(
    ["Nile Heights Developments", "Cairo Fit Clubs"].map((name, i) =>
      db.company.create({ data: { clientId: client.id, name, website: i ? "https://cairofit.example" : "https://nileheights.example", industry: i ? "Fitness" : "Real estate" } })),
  );
  await db.user.create({
    data: { email: "client@demo.local", name: "Demo Client", role: "CLIENT", clientId: client.id, passwordHash: await bcrypt.hash("demo1234", 12) },
  });

  for (const platform of PLATFORMS) {
    const conn = await db.adConnection.create({
      data: { clientId: client.id, platform, label: `${platform} demo login`, isDemo: true, externalUser: "demo" },
    });
    const accounts = demoAccounts(platform, `demo-token-${platform}`);
    for (const [i, a] of accounts.entries()) {
      const acc = await db.adAccount.create({
        data: { connectionId: conn.id, platform, externalId: a.externalId, name: a.name, currency: a.currency, timezone: a.timezone, companyId: companies[i % 2].id },
      });
      await enqueueInitialSync(acc.id);
    }
  }
  await seedDemoSite();
  console.log("demo agency created — run `npm run worker -- --once` to load its data");
  console.log("client login: client@demo.local / demo1234");
}

/**
 * The bundled landing page at /demo-site, registered as a website of the demo
 * agency's first company, with 30 days of generated visits.
 */
async function seedDemoSite() {
  const key = "demo-site-key-000001";
  if (await db.site.findUnique({ where: { trackingKey: key } })) return;
  const company = await db.company.findFirst({ where: { client: { name: "Demo Agency" } }, orderBy: { name: "desc" } });
  if (!company) return;
  const host = new URL(process.env.APP_URL || "http://localhost:3000").hostname;
  const site = await db.site.create({ data: { companyId: company.id, name: "Nile Heights website", domain: host, trackingKey: key, isDemo: true, replay: true, replayRate: 100 } });
  await generateDemoTraffic(site.id, 30);
  console.log(`demo site: ${host}/demo-site`);
}

main().finally(() => db.$disconnect());
