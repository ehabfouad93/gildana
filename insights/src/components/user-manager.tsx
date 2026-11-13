"use client";

import { startTransition, useActionState, useEffect, useState } from "react";
import { addUserAction, deleteUserAction, sendPasswordLinkAction, setPasswordAction, updateUserAction, type Result } from "@/app/actions/admin";
import { CopyButton } from "@/components/copy";

export type ManagedUser = {
  id: string; email: string; name: string; role: string; active: boolean; lastLogin: string | null;
  /** Client users: whole companies, single ad accounts and sections (empty = all). */
  companyIds: string[]; accountIds: string[]; modules: string[]; featuresOff: string[];
  /** Account managers: assigned clients. */
  clientIds: string[];
};
export type Option = { value: string; label: string; hint?: string };
export type PickAccount = { id: string; name: string; platform: string; externalId: string };
export type PickCompany = { id: string; name: string; accounts: PickAccount[] };

type Props = {
  users: ManagedUser[];
  /** Client the users belong to; null for Gildana's own team. */
  clientId: string | null;
  roles: Option[];
  meId: string;
  /** Client team: companies with their ad accounts, accounts not in a company, and the sections. */
  companies?: PickCompany[];
  unassigned?: PickAccount[];
  modules?: { key: string; label: string }[];
  /** Sub-features that can be switched off per user, grouped by their section. */
  features?: { key: string; module: string; label: string }[];
  /** Gildana team: clients an account manager can be assigned. */
  clients?: { id: string; name: string }[];
};

/** Roles whose access can be limited (owners and admins always see everything). */
const LIMITABLE = ["CLIENT", "VIEWER"];

/** Shows an action's "ok:" / "error:" / "link:" result. */
export function ResultNote({ msg }: { msg: Result }) {
  if (!msg) return null;
  const i = msg.indexOf(":");
  const kind = msg.slice(0, i);
  const rest = msg.slice(i + 1);
  if (kind === "link") {
    const [link, note] = rest.split("|");
    return (
      <div className="mt-2 text-sm">
        <p className="text-muted">{note || "Send this link yourself:"}</p>
        <div className="mt-1 flex gap-2"><input className="input !py-1 font-mono text-xs" readOnly value={link} /><CopyButton text={link} /></div>
      </div>
    );
  }
  return <p className={`mt-2 text-sm ${kind === "ok" ? "text-good" : "text-bad"}`}>{rest}</p>;
}

/** Submits a form through an action without React resetting it, so a refused change keeps what was picked. */
function submitWith(action: (fd: FormData) => void) {
  return (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    startTransition(() => action(fd));
  };
}

// ───────── client users: ad accounts and sections ─────────

function AccountAccess({ p, u }: { p: Props; u?: ManagedUser }) {
  const companies = p.companies ?? [];
  const unassigned = p.unassigned ?? [];
  const [mode, setMode] = useState(u && (u.companyIds.length || u.accountIds.length) ? "some" : "all");
  const [cos, setCos] = useState(() => new Set(u?.companyIds ?? []));
  const [accs, setAccs] = useState(() => new Set(u?.accountIds ?? []));
  const [q, setQ] = useState("");
  const total = companies.reduce((n, c) => n + c.accounts.length, unassigned.length);
  const match = (a: PickAccount) => !q || `${a.name} ${a.externalId} ${a.platform}`.toLowerCase().includes(q.toLowerCase());
  const toggle = (set: Set<string>, id: string, on: boolean) => { const n = new Set(set); if (on) n.add(id); else n.delete(id); return n; };
  const seen = companies.reduce((n, c) => n + (cos.has(c.id) ? c.accounts.length : c.accounts.filter((a) => accs.has(a.id)).length), unassigned.filter((a) => accs.has(a.id)).length);

  const row = (a: PickAccount, covered: boolean) => (
    <label key={a.id} className={`flex items-center gap-2 py-0.5 ps-6 text-sm ${covered ? "text-muted" : ""}`}>
      <input type="checkbox" name="accounts" value={a.id} checked={covered || accs.has(a.id)} disabled={covered} onChange={(e) => setAccs(toggle(accs, a.id, e.target.checked))} />
      <span className="truncate">{a.name}</span><span className="shrink-0 text-xs text-muted">{a.platform} · {a.externalId}</span>
    </label>
  );

  return (
    <div>
      <input type="hidden" name="accessMode" value={mode} />
      <div className="flex flex-wrap gap-4 text-sm">
        <label className="flex items-center gap-1"><input type="radio" checked={mode === "all"} onChange={() => setMode("all")} /> All ad accounts ({total})</label>
        <label className="flex items-center gap-1"><input type="radio" checked={mode === "some"} onChange={() => setMode("some")} /> Only the ones I tick</label>
      </div>
      {mode === "some" && (
        <div className="mt-2 rounded-lg border border-line bg-panel p-2">
          <div className="mb-2 flex items-center gap-2">
            <input className="input !py-1" placeholder="Search ad accounts…" value={q} onChange={(e) => setQ(e.target.value)} />
            <span className="shrink-0 text-xs text-muted">{seen} of {total} selected</span>
          </div>
          <div className="max-h-72 overflow-y-auto">
            {companies.map((c) => {
              const list = c.accounts.filter(match);
              if (q && !list.length && !c.name.toLowerCase().includes(q.toLowerCase())) return null;
              const whole = cos.has(c.id);
              return (
                <div key={c.id} className="border-b border-line py-1 last:border-0">
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" name="access" value={c.id} checked={whole} onChange={(e) => setCos(toggle(cos, c.id, e.target.checked))} />
                    {c.name} <span className="text-xs font-normal text-muted">— whole company, including accounts added later</span>
                  </label>
                  {list.map((a) => row(a, whole))}
                  {!c.accounts.length && <div className="ps-6 text-xs text-muted">No ad accounts yet.</div>}
                </div>
              );
            })}
            {unassigned.filter(match).length > 0 && (
              <div className="py-1">
                <div className="text-sm font-medium">Not in a company</div>
                {unassigned.filter(match).map((a) => row(a, false))}
              </div>
            )}
            {!total && <p className="text-xs text-muted">No ad accounts connected yet.</p>}
          </div>
        </div>
      )}
    </div>
  );
}

function SectionAccess({ p, u }: { p: Props; u?: ManagedUser }) {
  const mods = p.modules ?? [];
  const [mode, setMode] = useState(u?.modules.length ? "some" : "all");
  return (
    <div>
      <input type="hidden" name="modulesMode" value={mode} />
      <div className="flex flex-wrap gap-4 text-sm">
        <label className="flex items-center gap-1"><input type="radio" checked={mode === "all"} onChange={() => setMode("all")} /> All sections</label>
        <label className="flex items-center gap-1"><input type="radio" checked={mode === "some"} onChange={() => setMode("some")} /> Only the ones I tick</label>
      </div>
      {mode === "some" && (
        <div className="mt-2 grid gap-1 rounded-lg border border-line bg-panel p-2 text-sm sm:grid-cols-2">
          {mods.map((m) => (
            <label key={m.key} className="flex items-center gap-2"><input type="checkbox" name="modules" value={m.key} defaultChecked={!u?.modules.length || u.modules.includes(m.key)} />{m.label}</label>
          ))}
        </div>
      )}
    </div>
  );
}

/** On/off switches for the sub-features inside the sections (tabs, breakdowns, AI, creating tests…). */
function FeatureAccess({ p, u }: { p: Props; u?: ManagedUser }) {
  const feats = p.features ?? [];
  const [off, setOff] = useState(() => new Set(u?.featuresOff ?? []));
  const [open, setOpen] = useState((u?.featuresOff.length ?? 0) > 0);
  if (!feats.length) return null;
  const groups = [...new Set(feats.map((f) => f.module))];
  const title = (m: string) => (m === "general" ? "General" : p.modules?.find((x) => x.key === m)?.label ?? m);
  const flip = (key: string, on: boolean) => setOff((s) => { const n = new Set(s); if (on) n.delete(key); else n.add(key); return n; });
  return (
    <div>
      <input type="hidden" name="featuresShown" value="1" />
      {/* Submitted even while the list is collapsed. */}
      {feats.filter((f) => !off.has(f.key)).map((f) => <input key={f.key} type="hidden" name="featureOn" value={f.key} />)}
      <button type="button" className="text-sm text-accent hover:underline" onClick={() => setOpen(!open)}>
        {open ? "▾" : "▸"} Features inside the sections — {off.size ? `${off.size} switched off` : "all on"}
      </button>
      {open && (
        <div className="mt-2 grid gap-3 rounded-lg border border-line bg-panel p-3 text-sm sm:grid-cols-2">
          {groups.map((g) => (
            <div key={g}>
              <div className="mb-1 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
                {title(g)}
                <button type="button" className="font-normal normal-case text-accent hover:underline" onClick={() => setOff((s) => { const n = new Set(s); const mine = feats.filter((f) => f.module === g); const allOn = mine.every((f) => !n.has(f.key)); mine.forEach((f) => (allOn ? n.add(f.key) : n.delete(f.key))); return n; })}>
                  {feats.filter((f) => f.module === g).every((f) => !off.has(f.key)) ? "all off" : "all on"}
                </button>
              </div>
              {feats.filter((f) => f.module === g).map((f) => (
                <label key={f.key} className="flex items-center gap-2 py-0.5"><input type="checkbox" checked={!off.has(f.key)} onChange={(e) => flip(f.key, e.target.checked)} />{f.label}</label>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** Role-dependent access fields: sections + ad accounts for client users, clients for account managers. */
function AccessFields({ p, u, role }: { p: Props; u?: ManagedUser; role: string }) {
  if (p.clientId) {
    if (!LIMITABLE.includes(role)) return <p className="text-xs text-muted">Owners see every ad account and every section, and manage the team.</p>;
    return (
      <div className="space-y-4">
        <div><div className="label">Sections they can open</div><SectionAccess p={p} u={u} /></div>
        <FeatureAccess p={p} u={u} />
        <div><div className="label">Ad accounts they can see</div><AccountAccess p={p} u={u} /></div>
      </div>
    );
  }
  if (role !== "STAFF") return <p className="text-xs text-muted">Admins see every client.</p>;
  const clients = p.clients ?? [];
  return (
    <div>
      <div className="label">Clients they look after</div>
      {!clients.length && <p className="text-xs text-muted">No clients yet.</p>}
      <div className="flex max-h-40 flex-wrap gap-x-4 gap-y-1 overflow-y-auto text-sm">
        {clients.map((c) => <label key={c.id} className="flex items-center gap-1"><input type="checkbox" name="access" value={c.id} defaultChecked={u?.clientIds.includes(c.id)} />{c.name}</label>)}
      </div>
    </div>
  );
}

function short(names: (string | undefined)[], none: string) {
  const n = names.filter(Boolean) as string[];
  if (!n.length) return none;
  return n.length > 2 ? `${n.slice(0, 2).join(", ")} +${n.length - 2}` : n.join(", ");
}

function accessText(u: ManagedUser, p: Props) {
  if (!p.clientId) return u.role === "STAFF" ? short(u.clientIds.map((id) => p.clients?.find((c) => c.id === id)?.name), "No clients yet") : "All clients";
  if (!LIMITABLE.includes(u.role) || (!u.companyIds.length && !u.accountIds.length)) return "All ad accounts";
  const all = [...(p.companies ?? []).flatMap((c) => c.accounts), ...(p.unassigned ?? [])];
  return short([...u.companyIds.map((id) => p.companies?.find((c) => c.id === id)?.name), ...u.accountIds.map((id) => all.find((a) => a.id === id)?.name)], "None");
}

function sectionText(u: ManagedUser, p: Props) {
  if (!LIMITABLE.includes(u.role)) return "All";
  const sections = u.modules.length ? `${u.modules.length} of ${p.modules?.length ?? 0}` : "All";
  return u.featuresOff.length ? `${sections} · ${u.featuresOff.length} feature${u.featuresOff.length > 1 ? "s" : ""} off` : sections;
}

function UserRow({ u, p }: { u: ManagedUser; p: Props }) {
  const [open, setOpen] = useState(false);
  const [role, setRole] = useState(u.role);
  const [saveMsg, save, saving] = useActionState(updateUserAction, null);
  const [pwMsg, setPw, settingPw] = useActionState(setPasswordAction, null);
  const [linkMsg, sendLink, sending] = useActionState(sendPasswordLinkAction, null);
  const [delMsg, del, deleting] = useActionState(deleteUserAction, null);
  const me = u.id === p.meId;
  const cols = p.clientId ? 7 : 6;
  return (
    <>
      <tr className={u.active ? "" : "opacity-50"}>
        <td><div className="font-medium">{u.name || u.email}{me && <span className="ms-1 text-xs text-muted">(you)</span>}</div>{u.name && <div className="text-xs text-muted">{u.email}</div>}</td>
        <td>{p.roles.find((r) => r.value === u.role)?.label ?? u.role}</td>
        <td className="text-xs">{accessText(u, p)}</td>
        {p.clientId && <td className="text-xs">{sectionText(u, p)}</td>}
        <td className="text-xs">{u.lastLogin ?? <span className="text-muted">never</span>}</td>
        <td className="text-xs">{u.active ? <span className="text-good">active</span> : <span className="text-muted">disabled</span>}</td>
        <td>
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-ghost !py-1" onClick={() => setOpen(!open)}>{open ? "Close" : "Edit"}</button>
            {!me && (
              <form action={del} onSubmit={(e) => { if (!confirm(`Delete ${u.email}? They lose access right away. This can't be undone.`)) e.preventDefault(); }}>
                <input type="hidden" name="userId" value={u.id} />
                <button className="btn-ghost !py-1 !text-bad" disabled={deleting}>{deleting ? "Deleting…" : "Delete"}</button>
              </form>
            )}
          </div>
          <ResultNote msg={delMsg} />
        </td>
      </tr>
      {open && (
        <tr>
          <td colSpan={cols} className="bg-bg">
            <div className="grid gap-4 py-2 lg:grid-cols-[2fr_1fr]">
              <form className="space-y-4" onSubmit={submitWith(save)}>
                <input type="hidden" name="userId" value={u.id} />
                <input type="hidden" name="activeField" value="1" />
                <div className="grid gap-3 sm:grid-cols-2">
                  <div><label className="label">Name</label><input className="input" name="name" defaultValue={u.name} /></div>
                  <div>
                    <label className="label">Role</label>
                    <select className="input" name="role" value={role} onChange={(e) => setRole(e.target.value)} disabled={me}>
                      {p.roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                    </select>
                    {me && <input type="hidden" name="role" value={u.role} />}
                    <p className="mt-1 text-xs text-muted">{p.roles.find((r) => r.value === role)?.hint}</p>
                  </div>
                </div>
                <AccessFields p={p} u={u} role={role} />
                <label className="flex items-center gap-1 text-sm"><input type="checkbox" name="active" defaultChecked={u.active} disabled={me} /> Can sign in{me && <input type="hidden" name="active" value="on" />}</label>
                <button className="btn" disabled={saving}>{saving ? "Saving…" : "Save changes"}</button>
                <ResultNote msg={saveMsg} />
              </form>
              <div className="space-y-4">
                <form action={setPw}>
                  <input type="hidden" name="userId" value={u.id} />
                  <label className="label">Set a new password</label>
                  <div className="flex gap-2"><input className="input" name="password" type="password" minLength={8} required autoComplete="new-password" /><button className="btn-ghost" disabled={settingPw}>Set</button></div>
                  <ResultNote msg={pwMsg} />
                </form>
                <form action={sendLink}>
                  <input type="hidden" name="userId" value={u.id} />
                  <label className="label">Or let them choose one</label>
                  <button className="btn-ghost" disabled={sending}>{sending ? "Sending…" : u.lastLogin ? "Send password reset link" : "Resend invitation"}</button>
                  <ResultNote msg={linkMsg} />
                </form>
              </div>
            </div>
          </td>
        </tr>
      )}
    </>
  );
}

function AddUser({ p }: { p: Props }) {
  const [msg, action, pending] = useActionState(addUserAction, null);
  const [role, setRole] = useState(p.roles.find((r) => r.value === "CLIENT" || r.value === "STAFF")?.value ?? p.roles[0].value);
  // A fresh form (and fresh pickers) after each user is added.
  const [round, setRound] = useState(0);
  useEffect(() => { if (msg?.startsWith("ok:") || msg?.startsWith("link:")) setRound((r) => r + 1); }, [msg]);
  return (
    <form key={round} className="space-y-4" onSubmit={submitWith(action)}>
      {p.clientId && <input type="hidden" name="clientId" value={p.clientId} />}
      <div className="grid items-start gap-3 md:grid-cols-4">
        <div><label className="label">Email</label><input className="input" name="email" type="email" required /></div>
        <div><label className="label">Name</label><input className="input" name="name" /></div>
        <div>
          <label className="label">Role</label>
          <select className="input" name="role" value={role} onChange={(e) => setRole(e.target.value)}>
            {p.roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </select>
          <p className="mt-1 text-xs text-muted">{p.roles.find((r) => r.value === role)?.hint}</p>
        </div>
        <div><label className="label">Password (optional)</label><input className="input" name="password" type="password" minLength={8} autoComplete="new-password" placeholder="Leave empty to invite" /></div>
      </div>
      <AccessFields p={p} role={role} />
      <button className="btn" disabled={pending}>{pending ? "Adding…" : "Add user"}</button>
      <p className="text-xs text-muted">Without a password they get an email (or you get a link to send) to choose their own.</p>
      <ResultNote msg={msg} />
    </form>
  );
}

export function UserManager(p: Props) {
  return (
    <div>
      <div className="mb-4 overflow-x-auto">
        <table className="tbl">
          <thead><tr><th>User</th><th>Role</th><th>{p.clientId ? "Ad accounts" : "Clients"}</th>{p.clientId && <th>Sections</th>}<th>Last login</th><th>Status</th><th /></tr></thead>
          <tbody>
            {p.users.map((u) => <UserRow key={u.id} u={u} p={p} />)}
            {!p.users.length && <tr><td colSpan={7} className="text-muted">No users yet.</td></tr>}
          </tbody>
        </table>
      </div>
      <h3 className="mb-2 text-sm font-semibold">Add a user</h3>
      <AddUser p={p} />
    </div>
  );
}
