// Every call to the PHP API (/api on the same host). The session token is kept in localStorage and sent as X-Auth-Token.
export const AUTH_EXPIRED = 'jcb-auth-expired';
const TOKEN = 'jcb_token';

export const getToken = (): string => { try { return localStorage.getItem(TOKEN) || ''; } catch { return ''; } };
export const setToken = (t: string) => { try { t ? localStorage.setItem(TOKEN, t) : localStorage.removeItem(TOKEN); } catch { /* storage blocked */ } };
const lang = () => { try { return localStorage.getItem('jcb_lang') === 'es' ? 'es' : 'en'; } catch { return 'en'; } };

export async function call<T = any>(path: string, method = 'GET', body?: unknown): Promise<T> {
  const sep = path.includes('?') ? '&' : '?';
  const isForm = body instanceof FormData;
  const res = await fetch(`/api/${path}${sep}lang=${lang()}`, {
    method,
    headers: { ...(isForm ? {} : { 'Content-Type': 'application/json' }), ...(getToken() ? { 'X-Auth-Token': getToken() } : {}) },
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && getToken()) { setToken(''); window.dispatchEvent(new Event(AUTH_EXPIRED)); }
  if (!res.ok) throw new Error(data.message || `Request failed (${res.status})`);
  return data as T;
}

export type User = { id: number; email: string; name: string; role: 'owner' | 'agent'; is_platform_admin: number; lang: string; tenant_id: number; company: string; public_key: string };
