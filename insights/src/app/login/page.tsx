import Link from "next/link";
import { AuthCard } from "@/components/auth-card";
import { LoginForm } from "./form";

export const metadata = { title: "Sign in" };

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ reset?: string }> }) {
  const { reset } = await searchParams;
  return (
    <AuthCard title="Insights" sub="Ads, analytics, SEO and GEO for every client.">
      {reset && <p className="mb-4 rounded-lg bg-green-50 p-3 text-sm text-good">Password changed — sign in with your new password.</p>}
      <LoginForm />
      <p className="mt-4 text-center text-sm"><Link href="/forgot-password" className="text-accent hover:underline">Forgot password?</Link></p>
    </AuthCard>
  );
}
