import Link from "next/link";
import { AuthCard } from "@/components/auth-card";
import { flag, loadSettings } from "@/lib/settings";
import { SignupForm } from "./form";

export const metadata = { title: "Create your workspace" };
export const dynamic = "force-dynamic";

export default async function Signup() {
  await loadSettings();
  const open = flag("SIGNUP_ENABLED", false);
  return (
    <AuthCard title="Create your workspace" sub="All your ads, website analytics, SEO and AI-search visibility in one dashboard.">
      {open ? <SignupForm /> : <p className="text-sm text-muted">Sign-up is by invitation. Ask Gildana for access.</p>}
      <p className="mt-4 text-center text-sm">Already have an account? <Link href="/login" className="text-accent hover:underline">Sign in</Link></p>
    </AuthCard>
  );
}
