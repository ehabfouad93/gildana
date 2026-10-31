import Link from "next/link";
import { AuthCard } from "@/components/auth-card";
import { mailEnabled } from "@/lib/mail";
import { loadSettings } from "@/lib/settings";
import { ForgotForm } from "./form";

export const metadata = { title: "Forgot password" };
export const dynamic = "force-dynamic";

export default async function ForgotPassword() {
  await loadSettings();
  return (
    <AuthCard title="Forgot password" sub="We'll email you a link to choose a new one.">
      {mailEnabled() ? <ForgotForm /> : (
        <p className="text-sm text-muted">Password emails aren&apos;t set up on this server yet. Ask your Gildana admin to reset your password.</p>
      )}
      <p className="mt-4 text-center text-sm"><Link href="/login" className="text-accent hover:underline">Back to sign in</Link></p>
    </AuthCard>
  );
}
