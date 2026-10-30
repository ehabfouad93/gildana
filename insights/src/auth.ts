import NextAuth, { type DefaultSession } from "next-auth";
import Credentials from "next-auth/providers/credentials";
import bcrypt from "bcryptjs";
import { z } from "zod";
import { db } from "@/lib/db";

declare module "next-auth" {
  interface Session {
    user: { id: string; role: "ADMIN" | "CLIENT" | "VIEWER"; clientId: string | null; locale: string } & DefaultSession["user"];
  }
}

// Failed-login throttle, per email, per process. Ten misses lock the address
// for fifteen minutes — enough to stop guessing without a lockout table.
const misses = new Map<string, { n: number; until: number }>();

const creds = z.object({ email: z.string().email(), password: z.string().min(1) });

export const { handlers, auth, signIn, signOut } = NextAuth({
  session: { strategy: "jwt", maxAge: 60 * 60 * 12 },
  pages: { signIn: "/login" },
  providers: [
    Credentials({
      credentials: { email: {}, password: {} },
      async authorize(raw) {
        const parsed = creds.safeParse(raw);
        if (!parsed.success) return null;
        const email = parsed.data.email.toLowerCase().trim();

        const m = misses.get(email);
        if (m && m.until > Date.now()) return null;

        const user = await db.user.findUnique({ where: { email } });
        const ok = !!user && user.active && (await bcrypt.compare(parsed.data.password, user.passwordHash));
        if (!ok || !user) {
          const n = (m?.n ?? 0) + 1;
          misses.set(email, { n, until: n >= 10 ? Date.now() + 15 * 60_000 : 0 });
          return null;
        }
        misses.delete(email);
        await db.user.update({ where: { id: user.id }, data: { lastLoginAt: new Date() } });
        return { id: user.id, email: user.email, name: user.name, role: user.role, clientId: user.clientId, locale: user.locale };
      },
    }),
  ],
  callbacks: {
    jwt({ token, user }) {
      if (user) {
        const u = user as unknown as { id: string; role: string; clientId: string | null; locale: string };
        token.uid = u.id;
        token.role = u.role;
        token.clientId = u.clientId;
        token.locale = u.locale;
      }
      return token;
    },
    session({ session, token }) {
      session.user.id = token.uid as string;
      session.user.role = token.role as "ADMIN" | "CLIENT" | "VIEWER";
      session.user.clientId = (token.clientId as string | null) ?? null;
      session.user.locale = (token.locale as string) ?? "en";
      return session;
    },
    authorized({ auth, request }) {
      const p = request.nextUrl.pathname;
      const open = p === "/login" || p.startsWith("/s/") || p.startsWith("/api/collect") || p.startsWith("/api/auth") || p.startsWith("/api/share");
      return open || !!auth;
    },
  },
});
