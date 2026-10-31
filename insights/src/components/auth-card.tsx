/** The centred card used by the sign-in and password pages. */
export function AuthCard({ title, sub, children }: { title: string; sub?: string; children: React.ReactNode }) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-side px-4">
      <div className="w-full max-w-sm rounded-2xl bg-panel p-8 shadow-xl">
        <div className="mb-6">
          <div className="text-xs font-semibold uppercase tracking-[0.2em] text-accent">Gildana</div>
          <h1 className="text-2xl font-semibold">{title}</h1>
          {sub && <p className="mt-1 text-sm text-muted">{sub}</p>}
        </div>
        {children}
      </div>
    </main>
  );
}
