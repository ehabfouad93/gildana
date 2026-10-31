import Link from "next/link";
import { AuthCard } from "@/components/auth-card";
import { flag, loadSettings } from "@/lib/settings";
import { LoginForm } from "./form";

export const metadata = { title: "Sign in" };
export const dynamic = "force-dynamic";

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ reset?: string }> }) {
  const { reset } = await searchParams;
  await loadSettings();
  return (
    <AuthCard title="Insights" sub="Ads, analytics, SEO and GEO for every client.">
      {reset && <p className="mb-4 rounded-lg bg-green-50 p-3 text-sm text-good">Password changed — sign in with your new password.</p>}
      <LoginForm />
      <p className="mt-4 text-center text-sm"><Link href="/forgot-password" className="text-accent hover:underline">Forgot password?</Link></p>
      {flag("SIGNUP_ENABLED", false) && <p className="mt-2 text-center text-sm text-muted">New here? <Link href="/signup" className="text-accent hover:underline">Create a workspace</Link></p>}
    </AuthCard>
  );
}
