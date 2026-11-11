import Link from "next/link";
import { exitWorkspaceAction } from "@/app/actions/admin";
import { logoutAction, setLangAction } from "@/app/actions/auth";
import { db } from "@/lib/db";
import { getDict, getLang } from "@/lib/i18n";
import { ROLE_LABEL, canManageTeam, getViewer } from "@/lib/tenancy";
import { NavLink } from "./nav-link";

export async function Shell({ children }: { children: React.ReactNode }) {
  const v = await getViewer();
  const t = await getDict();
  const lang = await getLang();
  const client = v?.clientId ? await db.client.findUnique({ where: { id: v.clientId }, select: { name: true, onboardedAt: true, logoUrl: true } }) : null;

  return (
    <div className="flex min-h-screen">
      <aside className="no-print sticky top-0 hidden h-screen w-60 shrink-0 flex-col bg-side p-4 md:flex">
        <Link href="/" className="mb-6 block px-3">
          <div className="text-[10px] font-semibold uppercase tracking-[0.25em] text-accent">Gildana</div>
          <div className="text-lg font-semibold text-white">Insights</div>
        </Link>
        {client && (
          <nav className="space-y-0.5">
            <div className="flex items-center gap-2 px-3 pb-1 text-[11px] uppercase tracking-wider text-slate-500">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              {client.logoUrl && <img src={client.logoUrl} alt="" className="h-5 w-5 rounded bg-white object-contain" />}
              <span className="truncate">{client.name}</span>
            </div>
            {!client.onboardedAt && v?.role !== "VIEWER" && <NavLink href="/welcome">★ Get started</NavLink>}
            <NavLink href="/overview">{t.overview}</NavLink>
            <NavLink href="/spend">{t.spend}</NavLink>
            <NavLink href="/performance">{t.performance}</NavLink>
            <NavLink href="/campaigns">{t.campaigns}</NavLink>
            <NavLink href="/audiences">{t.audiences}</NavLink>
            <NavLink href="/google">{t.google}</NavLink>
            <NavLink href="/sites">{t.sites}</NavLink>
            <NavLink href="/reports">{t.reports}</NavLink>
            <NavLink href="/connections">{t.connections}</NavLink>
            <NavLink href="/companies">{t.companies}</NavLink>
            {v && canManageTeam(v) && <NavLink href="/team">Team</NavLink>}
            {v && canManageTeam(v) && <NavLink href="/settings">Settings</NavLink>}
          </nav>
        )}
        {v?.isGildana && (
          <nav className="mt-6 space-y-0.5">
            <div className="px-3 pb-1 text-[11px] uppercase tracking-wider text-slate-500">{t.admin}</div>
            <NavLink href="/admin/clients">{v.isAdmin ? t.clients : "My clients"}</NavLink>
            {v.isAdmin && <NavLink href="/admin/team">Gildana team</NavLink>}
            {v.isAdmin && <NavLink href="/admin/platforms">Platforms</NavLink>}
            {v.isAdmin && <NavLink href="/admin/access">Access requests</NavLink>}
            {v.isAdmin && <NavLink href="/admin/health">{t.health}</NavLink>}
            {client && <form action={exitWorkspaceAction}><button className="block w-full rounded-lg px-3 py-2 text-start text-sm text-side-ink hover:bg-white/5 hover:text-white">← Leave {client.name}</button></form>}
          </nav>
        )}
        <div className="mt-auto space-y-2 px-3 text-xs text-side-ink">
          <Link href="/account" className="block hover:text-white" title="My account">
            <span className="block truncate">{v?.email}</span>
            {v && <span className="text-[10px] uppercase tracking-wider text-slate-500">{ROLE_LABEL[v.role]}</span>}
          </Link>
          <div className="flex gap-3">
            <form action={setLangAction}>
              <input type="hidden" name="lang" value={lang === "ar" ? "en" : "ar"} />
              <button className="hover:text-white">{lang === "ar" ? "English" : "العربية"}</button>
            </form>
            <form action={logoutAction}><button className="hover:text-white">{t.logout}</button></form>
          </div>
        </div>
      </aside>
      <main className="min-w-0 flex-1 p-4 md:p-6">{children}</main>
    </div>
  );
}

export function PageHeader({ title, sub, children }: { title: string; sub?: string; children?: React.ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-xl font-semibold">{title}</h1>
        {sub && <p className="text-sm text-muted">{sub}</p>}
      </div>
      <div className="flex flex-wrap items-center gap-2">{children}</div>
    </div>
  );
}
