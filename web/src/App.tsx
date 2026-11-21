import React, { useEffect, useMemo, useState } from 'react';
import { AUTH_EXPIRED, call, getToken, setToken, User } from './api';
import { APP_NAME, I18n, initialLang, Lang, STRINGS } from './i18n';
import { DialogProvider, btn } from './ui';
import CookieNotice from './CookieNotice';
import Landing from './pages/Landing';
import AuthPage from './pages/Auth';
import Legal from './pages/Legal';
import Dashboard from './pages/Dashboard';
import Owner from './pages/Owner';

// Hash routes: #/ landing, #/login, #/signup, #/privacy, #/terms, #/app/<tab>, #/owner.
const useHash = () => {
  const [h, setH] = useState(location.hash.replace(/^#/, '') || '/');
  useEffect(() => { const f = () => { setH(location.hash.replace(/^#/, '') || '/'); window.scrollTo(0, 0); }; window.addEventListener('hashchange', f); return () => window.removeEventListener('hashchange', f); }, []);
  return h;
};
export const go = (path: string) => { location.hash = path; };

const App: React.FC = () => {
  const [lang, setLangState] = useState<Lang>(initialLang);
  const i18n = useMemo(() => ({
    lang, t: (k: keyof typeof STRINGS.en) => STRINGS[lang][k],
    setLang: (l: Lang) => { try { localStorage.setItem('jcb_lang', l); } catch { /* blocked */ } setLangState(l); if (getToken()) call('auth.php?a=lang', 'POST', { lang: l }).catch(() => {}); },
  }), [lang]);
  const route = useHash();
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(!getToken());
  const [blocked, setBlocked] = useState(false);
  const [demoKey, setDemoKey] = useState('');

  useEffect(() => {
    if (getToken()) call<{ user: User }>('auth.php?a=me').then(r => setUser(r.user)).catch(() => setToken('')).finally(() => setReady(true));
    // Outside-USA gate, checked once per browser session (signed-in people are never blocked).
    let known: string | null = null;
    try { known = sessionStorage.getItem('jcb_gate'); } catch { /* blocked */ }
    call<{ blocked: boolean; demo_key?: string }>(`gate.php?p=${encodeURIComponent(location.hash || '/')}`).then(r => {
      if (r.demo_key) setDemoKey(r.demo_key);
      if (known === null) { setBlocked(r.blocked); try { sessionStorage.setItem('jcb_gate', r.blocked ? '1' : '0'); } catch { /* blocked */ } }
      else setBlocked(known === '1' && r.blocked);
    }).catch(() => {});
    const out = () => { setUser(null); go('/login'); };
    window.addEventListener(AUTH_EXPIRED, out);
    return () => window.removeEventListener(AUTH_EXPIRED, out);
  }, []);
  useEffect(() => { document.documentElement.lang = lang; }, [lang]);

  const signedIn = (tok: string, u: User) => { setToken(tok); setUser(u); go('/app/overview'); };
  const signOut = async () => { try { await call('auth.php?a=logout', 'POST'); } catch { /* already gone */ } setToken(''); setUser(null); go('/'); };

  let page: React.ReactNode;
  if (!ready) page = <div className="py-24 text-center text-slate-400">{i18n.t('common.loading')}</div>;
  else if (blocked && !user) page = (
    <div className="min-h-screen flex items-center justify-center p-6 text-center">
      <div><h1 className="text-3xl font-black mb-2">{i18n.t('blocked.title')}</h1><p className="text-slate-500">{i18n.t('blocked.body')}</p></div>
    </div>);
  else if (route.startsWith('/app')) page = user ? <Dashboard user={user} route={route} onSignOut={signOut} /> : (go('/login'), null);
  else if (route === '/owner') page = user?.is_platform_admin ? <Owner /> : (go(user ? '/app/overview' : '/login'), null);
  else if (route === '/login' || route === '/signup') page = <AuthPage mode={route === '/login' ? 'login' : 'signup'} onDone={signedIn} />;
  else if (route === '/privacy' || route === '/terms') page = <Legal which={route === '/privacy' ? 'privacy' : 'terms'} />;
  else page = <Landing demoKey={demoKey} />;

  const inApp = route.startsWith('/app');
  return (
    <I18n.Provider value={i18n}>
      <DialogProvider>
        {!inApp && !(blocked && !user) && (
          <header className="max-w-6xl mx-auto px-4 py-4 flex items-center gap-3">
            <a href="#/" className="font-black text-lg mr-auto">💬 {APP_NAME}</a>
            <button className="text-sm font-semibold hover:underline" onClick={() => i18n.setLang(lang === 'en' ? 'es' : 'en')}>{i18n.t('lang.toggle')}</button>
            {user ? <>
              {!!user.is_platform_admin && <a href="#/owner" className="text-sm font-semibold hover:underline">{i18n.t('nav.owner')}</a>}
              <a href="#/app/overview" className={btn.primary}>{i18n.t('nav.dashboard')}</a>
            </> : <>
              <a href="#/login" className="text-sm font-semibold hover:underline">{i18n.t('nav.signin')}</a>
              <a href="#/signup" className={btn.primary}>{i18n.t('nav.signup')}</a>
            </>}
          </header>)}
        {page}
        <CookieNotice />
      </DialogProvider>
    </I18n.Provider>
  );
};
export default App;
