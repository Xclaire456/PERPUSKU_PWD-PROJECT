const BASE = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api';
export class ApiError extends Error {
  constructor(public status: number, msg: string, public errors?: Record<string, string[]>) { super(msg); }
}
let token: string | null = sessionStorage.getItem('token');
export const hasToken = () => !!token;
export function setToken(t: string | null) {
  token = t;
  t ? sessionStorage.setItem('token', t) : sessionStorage.removeItem('token');
}
export async function api<T = any>(path: string, opt: { method?: string; body?: unknown } = {}): Promise<{ data: T; meta?: any; message?: string }> {
  let res: Response;
  try {
    res = await fetch(BASE + path, {
      method: opt.method ?? 'GET',
      headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: opt.body ? JSON.stringify(opt.body) : undefined,
    });
  } catch {
    throw new ApiError(0, 'Tidak dapat terhubung ke server. Periksa koneksi atau jalankan API.');
  }
  const j = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (res.status === 401 && token) { setToken(null); location.reload(); }
    throw new ApiError(res.status, j.message ?? 'Terjadi kesalahan', j.errors);
  }
  return j;
}
