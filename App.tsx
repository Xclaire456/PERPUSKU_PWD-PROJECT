import { useCallback, useEffect, useState } from 'react';
import { api, ApiError, hasToken, setToken } from './api';

type User = { id: number; nama: string; email: string; role: 'admin' | 'pustakawan' | 'anggota' };
type Opt = { value: string | number; label: string };
type Field = { key: string; label: string; type?: string; options?: () => Promise<Opt[]> };
type Cfg = {
  path: string; title: string; cols: { key: string; label: string }[]; fields: Field[]; empty: string;
  create: (r: string) => boolean; edit: (r: string) => boolean; del: (r: string) => boolean;
  createLabel: string; extra?: (row: any, run: (fn: () => Promise<unknown>, msg: string) => void, role: string) => React.ReactNode;
};
type Notify = (m: string, err?: boolean) => void;

const isStaff = (r: string) => r !== 'anggota';
const isAdmin = (r: string) => r === 'admin';
const none = () => false;
const fromApi = (path: string, label: string) => async (): Promise<Opt[]> =>
  (await api<any[]>(`${path}?limit=100`)).data.map(x => ({ value: x.id, label: x[label] }));
const roles = async (): Promise<Opt[]> => ['admin', 'pustakawan', 'anggota'].map(v => ({ value: v, label: v }));

const CFG: Record<string, Cfg> = {
  books: {
    path: '/books', title: 'Buku', createLabel: 'Tambah buku', empty: 'Belum ada buku. Tambahkan buku pertama.',
    cols: [{ key: 'judul', label: 'Judul' }, { key: 'penulis', label: 'Penulis' }, { key: 'kategori', label: 'Kategori' }, { key: 'stok', label: 'Stok' }],
    fields: [{ key: 'judul', label: 'Judul' }, { key: 'penulis', label: 'Penulis' }, { key: 'penerbit', label: 'Penerbit' },
      { key: 'category_id', label: 'Kategori', type: 'select', options: fromApi('/categories', 'nama') },
      { key: 'tahun_terbit', label: 'Tahun terbit', type: 'number' }, { key: 'isbn', label: 'ISBN' }, { key: 'stok', label: 'Stok', type: 'number' }],
    create: isStaff, edit: isStaff, del: isStaff,
    extra: (row, run, role) => role === 'anggota' && (
      <button className="link" disabled={row.stok < 1} onClick={() => run(() => api('/loans', { method: 'POST', body: { book_id: row.id } }), 'Peminjaman dicatat, jatuh tempo 7 hari')}>Pinjam</button>),
  },
  categories: {
    path: '/categories', title: 'Kategori', createLabel: 'Tambah kategori', empty: 'Belum ada kategori.',
    cols: [{ key: 'nama', label: 'Nama' }, { key: 'deskripsi', label: 'Deskripsi' }],
    fields: [{ key: 'nama', label: 'Nama' }, { key: 'deskripsi', label: 'Deskripsi', type: 'textarea' }],
    create: isStaff, edit: isStaff, del: isStaff,
  },
  loans: {
    path: '/loans', title: 'Peminjaman', createLabel: 'Catat peminjaman', empty: 'Belum ada peminjaman.',
    cols: [{ key: 'peminjam', label: 'Peminjam' }, { key: 'judul', label: 'Buku' }, { key: 'tgl_jatuh_tempo', label: 'Jatuh tempo' }, { key: 'status', label: 'Status' }, { key: 'denda', label: 'Denda (Rp)' }],
    fields: [{ key: 'book_id', label: 'Buku', type: 'select', options: fromApi('/books', 'judul') }],
    create: r => r !== 'anggota', edit: none, del: isAdmin,
    extra: (row, run, role) => isStaff(role) && row.status !== 'dikembalikan' && (
      <button className="link" onClick={() => run(() => api(`/loans/${row.id}`, { method: 'PUT', body: {} }), 'Buku dikembalikan')}>Kembalikan</button>),
  },
  users: {
    path: '/users', title: 'Pengguna', createLabel: 'Tambah pengguna', empty: 'Belum ada pengguna.',
    cols: [{ key: 'nama', label: 'Nama' }, { key: 'email', label: 'Email' }, { key: 'role', label: 'Role' }],
    fields: [{ key: 'nama', label: 'Nama' }, { key: 'email', label: 'Email', type: 'email' }, { key: 'role', label: 'Role', type: 'select', options: roles },
      { key: 'password', label: 'Password (kosongkan saat ubah jika tidak diganti)', type: 'password' }],
    create: isAdmin, edit: isAdmin, del: isAdmin,
  },
};

const NAV: { id: string; label: string; show: (r: string) => boolean }[] = [
  { id: 'dashboard', label: 'Ringkasan', show: isStaff }, { id: 'books', label: 'Buku', show: () => true },
  { id: 'categories', label: 'Kategori', show: isStaff }, { id: 'loans', label: 'Peminjaman', show: () => true },
  { id: 'users', label: 'Pengguna', show: isAdmin },
];

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="backdrop" onClick={onClose}>
      <div className="modal" role="dialog" aria-label={title} onClick={e => e.stopPropagation()}>
        <h2>{title}</h2>{children}
      </div>
    </div>
  );
}

function Form({ cfg, init, onClose, onSaved, notify }: { cfg: Cfg; init: any; onClose: () => void; onSaved: () => void; notify: Notify }) {
  const [v, setV] = useState<any>(init);
  const [opts, setOpts] = useState<Record<string, Opt[]>>({});
  const [errs, setErrs] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => { cfg.fields.forEach(f => f.options?.().then(o => setOpts(p => ({ ...p, [f.key]: o })))); }, [cfg]);
  const submit = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy(true); setErrs({});
    try {
      await api(init.id ? `${cfg.path}/${init.id}` : cfg.path, { method: init.id ? 'PUT' : 'POST', body: v });
      notify('Data tersimpan'); onSaved();
    } catch (x) { const er = x as ApiError; setErrs(er.errors ?? {}); notify(er.message, true); }
    finally { setBusy(false); }
  };
  return (
    <form onSubmit={submit}>
      {cfg.fields.map(f => (
        <label key={f.key}>{f.label}
          {f.type === 'select' ? (
            <select value={v[f.key] ?? ''} onChange={e => setV({ ...v, [f.key]: e.target.value })}>
              <option value="">{opts[f.key] ? 'Pilih…' : 'Memuat…'}</option>
              {opts[f.key]?.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          ) : f.type === 'textarea' ? (
            <textarea value={v[f.key] ?? ''} onChange={e => setV({ ...v, [f.key]: e.target.value })} />
          ) : (
            <input type={f.type ?? 'text'} value={v[f.key] ?? ''} onChange={e => setV({ ...v, [f.key]: e.target.value })} />
          )}
          {errs[f.key] && <small className="err">{errs[f.key].join(', ')}</small>}
        </label>
      ))}
      <div className="actions">
        <button type="button" className="ghost" onClick={onClose}>Batal</button>
        <button disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan'}</button>
      </div>
    </form>
  );
}

function DataPage({ cfg, role, notify }: { cfg: Cfg; role: string; notify: Notify }) {
  const [rows, setRows] = useState<any[]>([]);
  const [meta, setMeta] = useState<any>({ page: 1, total_pages: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [q, setQ] = useState(''); const [dq, setDq] = useState('');
  const [state, setState] = useState<'loading' | 'error' | 'ok'>('loading'); const [err, setErr] = useState('');
  const [form, setForm] = useState<any | null>(null);
  useEffect(() => { const t = setTimeout(() => { setDq(q); setPage(1); }, 400); return () => clearTimeout(t); }, [q]);
  const load = useCallback(async () => {
    setState('loading');
    try {
      const r = await api<any[]>(`${cfg.path}?page=${page}&limit=8&search=${encodeURIComponent(dq)}`);
      setRows(r.data); setMeta(r.meta); setState('ok');
    } catch (e) { setErr((e as Error).message); setState('error'); }
  }, [cfg.path, page, dq]);
  useEffect(() => { load(); }, [load]);
  const run = async (fn: () => Promise<unknown>, msg: string) => {
    try { await fn(); notify(msg); load(); } catch (e) { notify((e as Error).message, true); }
  };
  const remove = (row: any) => { if (confirm('Hapus data ini? Tindakan tidak dapat dibatalkan.')) run(() => api(`${cfg.path}/${row.id}`, { method: 'DELETE' }), 'Data dihapus'); };
  return (
    <section>
      <header className="bar">
        <h1>{cfg.title}</h1>
        <input className="search" placeholder="Cari…" value={q} onChange={e => setQ(e.target.value)} aria-label="Cari" />
        {cfg.create(role) && <button onClick={() => setForm({})}>{cfg.createLabel}</button>}
      </header>
      {state === 'error' && <div className="state err">{err} <button className="ghost" onClick={load}>Coba lagi</button></div>}
      {state === 'loading' && <div className="state">Memuat data…</div>}
      {state === 'ok' && (rows.length === 0 ? <div className="state">{dq ? `Tidak ada hasil untuk “${dq}”.` : cfg.empty}</div> : (
        <div className="scroll"><table>
          <thead><tr>{cfg.cols.map(c => <th key={c.key}>{c.label}</th>)}<th /></tr></thead>
          <tbody>{rows.map(r => (
            <tr key={r.id} className={r.status ?? ''}>
              {cfg.cols.map(c => <td key={c.key}>{String(r[c.key] ?? '–')}</td>)}
              <td className="row-actions">
                {cfg.extra?.(r, run, role)}
                {cfg.edit(role) && <button className="link" onClick={() => setForm({ ...r, password: '' })}>Ubah</button>}
                {cfg.del(role) && <button className="link danger" onClick={() => remove(r)}>Hapus</button>}
              </td>
            </tr>))}
          </tbody></table></div>))}
      <footer className="pager">
        <span>{meta.total} data</span>
        <button className="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>Sebelumnya</button>
        <span>{meta.page} / {meta.total_pages}</span>
        <button className="ghost" disabled={page >= meta.total_pages} onClick={() => setPage(page + 1)}>Berikutnya</button>
      </footer>
      {form && <Modal title={form.id ? `Ubah ${cfg.title.toLowerCase()}` : cfg.createLabel} onClose={() => setForm(null)}>
        <Form cfg={cfg} init={form} onClose={() => setForm(null)} onSaved={() => { setForm(null); load(); }} notify={notify} />
      </Modal>}
    </section>
  );
}

function Dashboard() {
  const [s, setS] = useState<any>(null); const [err, setErr] = useState('');
  useEffect(() => { api('/dashboard/summary').then(r => setS(r.data)).catch(e => setErr(e.message)); }, []);
  if (err) return <div className="state err">{err}</div>;
  if (!s) return <div className="state">Memuat ringkasan…</div>;
  const items: [string, string][] = [['total_buku', 'Judul buku'], ['total_stok', 'Eksemplar tersedia'], ['dipinjam', 'Sedang dipinjam'], ['terlambat', 'Terlambat'], ['total_anggota', 'Anggota']];
  return (<section><h1>Ringkasan</h1><div className="stats">{items.map(([k, l]) => <div key={k} className={`stat ${k}`}><b>{s[k]}</b><span>{l}</span></div>)}</div></section>);
}

function Login({ onLogin, notify }: { onLogin: (u: User) => void; notify: Notify }) {
  const [reg, setReg] = useState(false); const [v, setV] = useState<any>({}); const [busy, setBusy] = useState(false);
  const [errs, setErrs] = useState<Record<string, string[]>>({});
  const submit = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy(true); setErrs({});
    try {
      if (reg) { await api('/auth/register', { method: 'POST', body: v }); notify('Registrasi berhasil, silakan masuk'); setReg(false); }
      else { const r = await api<any>('/auth/login', { method: 'POST', body: v }); setToken(r.data.token); onLogin(r.data.user); }
    } catch (x) { const er = x as ApiError; setErrs(er.errors ?? {}); notify(er.message, true); } finally { setBusy(false); }
  };
  const spines = ['#264b7a', '#2f7d6d', '#7a3b52', '#d9a441', '#40506b', '#9b4a2f', '#1f6f8b'];
  return (
    <main className="login">
      <div className="shelf" aria-hidden>{spines.map((c, i) => <i key={i} style={{ background: c, height: `${62 + ((i * 37) % 38)}%` }} />)}</div>
      <form onSubmit={submit}>
        <h1>PerpusKu</h1><p>Katalog dan peminjaman buku perpustakaan kampus.</p>
        {reg && <label>Nama<input value={v.nama ?? ''} onChange={e => setV({ ...v, nama: e.target.value })} />{errs.nama && <small className="err">{errs.nama[0]}</small>}</label>}
        <label>Email<input type="email" value={v.email ?? ''} onChange={e => setV({ ...v, email: e.target.value })} />{errs.email && <small className="err">{errs.email[0]}</small>}</label>
        <label>Password<input type="password" value={v.password ?? ''} onChange={e => setV({ ...v, password: e.target.value })} />{errs.password && <small className="err">{errs.password[0]}</small>}</label>
        <button disabled={busy}>{busy ? 'Memproses…' : reg ? 'Daftar' : 'Masuk'}</button>
        <button type="button" className="link" onClick={() => setReg(!reg)}>{reg ? 'Sudah punya akun? Masuk' : 'Belum punya akun? Daftar'}</button>
      </form>
    </main>
  );
}

export default function App() {
  const [user, setUser] = useState<User | null>(null);
  const [booting, setBooting] = useState(hasToken());
  const [tab, setTab] = useState('books');
  const [toast, setToast] = useState<{ m: string; err: boolean } | null>(null);
  const notify: Notify = (m, err = false) => { setToast({ m, err }); setTimeout(() => setToast(null), 3500); };
  useEffect(() => {
    if (hasToken()) api<User>('/auth/me').then(r => setUser(r.data)).catch(() => setToken(null)).finally(() => setBooting(false));
  }, []);
  const toastEl = toast && <div className={`toast ${toast.err ? 'err' : ''}`} role="status">{toast.m}</div>;
  if (booting) return <div className="state">Memuat…</div>;
  if (!user) return <>{<Login onLogin={u => { setUser(u); setTab(isStaff(u.role) ? 'dashboard' : 'books'); }} notify={notify} />}{toastEl}</>;
  return (
    <div className="app">
      <aside>
        <h2>PerpusKu</h2>
        <nav>{NAV.filter(n => n.show(user.role)).map(n => <button key={n.id} className={tab === n.id ? 'on' : ''} onClick={() => setTab(n.id)}>{n.label}</button>)}</nav>
        <div className="me"><b>{user.nama}</b><span>{user.role}</span>
          <button className="link" onClick={() => { setToken(null); setUser(null); }}>Keluar</button></div>
      </aside>
      <main>{tab === 'dashboard' ? <Dashboard /> : <DataPage key={tab} cfg={CFG[tab]} role={user.role} notify={notify} />}</main>
      {toastEl}
    </div>
  );
}
