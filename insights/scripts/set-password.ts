/**
 * Set a user's password from the server shell — for when nobody can sign in.
 *
 *   npm run user:password -- admin@gildana.net 'NewStrongPassword'
 *   npm run user:password -- someone@example.com 'NewStrongPassword' --admin   (creates an admin if missing)
 */
import bcrypt from "bcryptjs";
import { db } from "../src/lib/db";
import { passwordProblem } from "../src/lib/password-reset";

async function main() {
  const [emailArg, password, flag] = process.argv.slice(2);
  const email = (emailArg ?? "").toLowerCase().trim();
  if (!email || !password) {
    console.error("Usage: npm run user:password -- <email> '<new password>' [--admin]");
    process.exit(1);
  }
  const problem = passwordProblem(password);
  if (problem) { console.error(problem); process.exit(1); }
  const passwordHash = await bcrypt.hash(password, 12);
  const existing = await db.user.findUnique({ where: { email } });
  if (existing) {
    await db.user.update({ where: { email }, data: { passwordHash, active: true } });
    await db.passwordReset.deleteMany({ where: { userId: existing.id, usedAt: null } });
    console.log(`Password changed for ${email}. Restart the web app to clear any sign-in lock: pm2 restart insights-web`);
  } else if (flag === "--admin") {
    await db.user.create({ data: { email, name: "Admin", role: "ADMIN", passwordHash } });
    console.log(`Admin ${email} created.`);
  } else {
    console.error(`No user ${email}. Add --admin to create it as an admin.`);
    process.exit(1);
  }
}

main().finally(() => db.$disconnect());
