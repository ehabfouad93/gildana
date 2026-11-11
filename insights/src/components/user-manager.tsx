"use client";

import { startTransition, useActionState, useState } from "react";
import { addUserAction, deleteUserAction, sendPasswordLinkAction, setPasswordAction, updateUserAction, type Result } from "@/app/actions/admin";
import { CopyButton } from "@/components/copy";

export type ManagedUser = {
  id: string; email: string; name: string; role: string; active: boolean;
  lastLogin: string | null; access: string[];
};
export type Option = { value: string; label: string; hint?: string };

type Props = {
  users: ManagedUser[];
  /** Client the users belong to; null for Gildana's own team. */
  clientId: string | null;
  roles: Option[];
  /** Companies (client users) or clients (account managers) a user can be limited to. */
  scopes: { id: string; name: string }[];
  scopeNoun: string;
  /** Roles the access list applies to (e.g. only STAFF for Gildana's team). */
  scopedRoles: string[];
  meId: string;
};

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

function AccessPicker({ scopes, selected, noun }: { scopes: Props["scopes"]; selected: string[]; noun: string }) {
  if (!scopes.length) return <p className="text-xs text-muted">No {noun.toLowerCase()} yet.</p>;
  return (
    <div>
      <div className="mb-1 text-xs text-muted">Tick to limit access; leave all empty for every {noun.toLowerCase().replace(/ies$/, "y").replace(/s$/, "")}.</div>
      <div className="flex max-h-40 flex-wrap gap-x-4 gap-y-1 overflow-y-auto text-sm">
        {scopes.map((s) => (
          <label key={s.id} className="flex items-center gap-1"><input type="checkbox" name="access" value={s.id} defaultChecked={selected.includes(s.id)} />{s.name}</label>
        ))}
      </div>
    </div>
  );
}

function accessText(u: ManagedUser, p: Props) {
  if (!p.scopedRoles.includes(u.role)) return p.clientId ? "—" : "All clients";
  if (!u.access.length) return p.clientId ? `All ${p.scopeNoun.toLowerCase()}` : "No clients yet";
  const names = u.access.map((id) => p.scopes.find((s) => s.id === id)?.name).filter(Boolean);
  return names.length > 2 ? `${names.slice(0, 2).join(", ")} +${names.length - 2}` : names.join(", ");
}

function UserRow({ u, p }: { u: ManagedUser; p: Props }) {
  const [open, setOpen] = useState(false);
  const [role, setRole] = useState(u.role);
  const [saveMsg, save, saving] = useActionState(updateUserAction, null);
  const [pwMsg, setPw, settingPw] = useActionState(setPasswordAction, null);
  const [linkMsg, sendLink, sending] = useActionState(sendPasswordLinkAction, null);
  const [delMsg, del, deleting] = useActionState(deleteUserAction, null);
  const me = u.id === p.meId;
  const roleLabel = p.roles.find((r) => r.value === u.role)?.label ?? u.role;
  return (
    <>
      <tr className={u.active ? "" : "opacity-50"}>
        <td><div className="font-medium">{u.name || u.email}{me && <span className="ms-1 text-xs text-muted">(you)</span>}</div>{u.name && <div className="text-xs text-muted">{u.email}</div>}</td>
        <td>{roleLabel}</td>
        <td className="text-xs">{accessText(u, p)}</td>
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
          <td colSpan={6} className="bg-bg">
            <div className="grid gap-4 py-2 lg:grid-cols-[2fr_1fr]">
              {/* Submitted by hand so a refused change keeps what was picked instead of resetting the form. */}
              <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); const fd = new FormData(e.currentTarget); startTransition(() => save(fd)); }}>
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
                {p.scopedRoles.includes(role) && (
                  <div><label className="label">{p.scopeNoun}</label><AccessPicker scopes={p.scopes} selected={u.access} noun={p.scopeNoun} /></div>
                )}
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
  return (
    <form action={action} className="space-y-3">
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
      {p.scopedRoles.includes(role) && <div><label className="label">{p.scopeNoun}</label><AccessPicker scopes={p.scopes} selected={[]} noun={p.scopeNoun} /></div>}
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
          <thead><tr><th>User</th><th>Role</th><th>Access</th><th>Last login</th><th>Status</th><th /></tr></thead>
          <tbody>
            {p.users.map((u) => <UserRow key={u.id} u={u} p={p} />)}
            {!p.users.length && <tr><td colSpan={6} className="text-muted">No users yet.</td></tr>}
          </tbody>
        </table>
      </div>
      <h3 className="mb-2 text-sm font-semibold">Add a user</h3>
      <AddUser p={p} />
    </div>
  );
}
