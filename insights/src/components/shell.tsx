import Link from "next/link";
import { exitWorkspaceAction } from "@/app/actions/admin";
import { logoutAction, setLangAction } from "@/app/actions/auth";
import { db } from "@/lib/db";
import { getDict, getLang } from "@/lib/i18n";
import { MODULES, type ModuleKey } from "@/lib/modules";
import { ROLE_LABEL, canManageTeam, canUse, getViewer } from "@/lib/tenancy";
import { NavLink } from "./nav-link";

type Dict = Awaited<ReturnType<typeof getDict>>;
const NAV_LABEL: Record<ModuleKey, (t: Dict) => string> = {
  overview: (t) => t.overview, actions: () => "To-do", creatives: () => "Creatives", spend: (t) => t.spend, performance: (t) => t.performance, campaigns: (t) => t.campaigns,
  audiences: (t) => t.audiences, google: (t) => t.google, sites: (t) => t.sites, reports: (t) => t.reports,
  connections: (t) => t.connections, companies: (t) => t.companies,
};

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
            {!client.onboardedAt && v && v.role !== "VIEWER" && !v.access && !v.modules.length && <NavLink href="/welcome">★ Get started</NavLink>}
            {/* Only the sections this user was given. */}
            {MODULES.filter((m) => !v || canUse(v, m.key)).map((m) => <NavLink key={m.key} href={m.path}>{NAV_LABEL[m.key](t)}</NavLink>)}
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
