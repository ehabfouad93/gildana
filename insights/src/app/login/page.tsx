import { LoginForm } from "./form";

export const metadata = { title: "Sign in" };

export default function LoginPage() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-side px-4">
      <div className="w-full max-w-sm rounded-2xl bg-panel p-8 shadow-xl">
        <div className="mb-6">
          <div className="text-xs font-semibold uppercase tracking-[0.2em] text-accent">Gildana</div>
          <h1 className="text-2xl font-semibold">Insights</h1>
          <p className="mt-1 text-sm text-muted">Ads, analytics, SEO and GEO for every client.</p>
        </div>
        <LoginForm />
      </div>
    </main>
  );
}
