import React, { useState } from 'react';
import { call, User } from '../api';
import { useT } from '../i18n';
import { titleCase } from '../format';
import PasswordSuggest from '../PasswordSuggest';
import { btn, card, Err, input, label } from '../ui';

const AuthPage: React.FC<{ mode: 'login' | 'signup'; onDone: (token: string, u: User) => void }> = ({ mode, onDone }) => {
  const { t } = useT();
  const [f, setF] = useState({ company: '', website: '', name: '', email: '', password: '', confirm: '' });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });
  const cap = (k: 'company' | 'name') => () => setF(v => ({ ...v, [k]: titleCase(v[k]) }));
  const signup = mode === 'signup';

  const submit = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('');
    if (signup && f.password !== f.confirm) { setErr(t('auth.mismatch')); return; }
    setBusy(true);
    try {
      const body = signup ? { company: titleCase(f.company), website: f.website, name: titleCase(f.name), email: f.email, password: f.password } : { email: f.email, password: f.password };
      const r = await call<{ token: string; user: User }>(`auth.php?a=${mode}`, 'POST', body);
      onDone(r.token, r.user);
    } catch (x) { setErr((x as Error).message); } finally { setBusy(false); }
  };

  return (
    <main className="max-w-md mx-auto px-4 py-10">
      <form onSubmit={submit} className={`${card} p-6 space-y-4`}>
        <h1 className="text-2xl font-black">{signup ? t('auth.signup.title') : t('auth.signin.title')}</h1>
        {signup && <>
          <div><label className={label} htmlFor="co">{t('auth.company')}</label><input id="co" className={input} value={f.company} onChange={set('company')} onBlur={cap('company')} required maxLength={120} autoComplete="organization" /></div>
          <div><label className={label} htmlFor="ws">{t('auth.website')}</label><input id="ws" className={input} value={f.website} onChange={set('website')} placeholder="yourcompany.com" maxLength={255} autoComplete="url" />
            <p className="text-xs text-slate-400 mt-1">{t('auth.website.hint')}</p></div>
          <div><label className={label} htmlFor="nm">{t('auth.name')}</label><input id="nm" className={input} value={f.name} onChange={set('name')} onBlur={cap('name')} required maxLength={100} autoComplete="name" /></div>
        </>}
        <div><label className={label} htmlFor="em">{t('auth.email')}</label><input id="em" type="email" className={input} value={f.email} onChange={set('email')} required maxLength={190} autoComplete="email" /></div>
        <div><label className={label} htmlFor="pw">{t('auth.password')}</label>
          <input id="pw" type="password" className={input} value={f.password} onChange={set('password')} required minLength={signup ? 10 : 1} autoComplete={signup ? 'new-password' : 'current-password'} />
          {signup && <p className="text-xs text-slate-400 mt-1">{t('auth.password.hint')}</p>}</div>
        {signup && <>
          <div><label className={label} htmlFor="pw2">{t('auth.confirm')}</label><input id="pw2" type="password" className={input} value={f.confirm} onChange={set('confirm')} required autoComplete="new-password" /></div>
          <PasswordSuggest onPick={p => setF(v => ({ ...v, password: p, confirm: p }))} />
        </>}
        <Err msg={err} />
        <button className={`${btn.primary} w-full`} disabled={busy}>{signup ? t('auth.go.signup') : t('auth.go.signin')}</button>
        {signup && <p className="text-xs text-slate-500">{t('auth.agree')} <a href="#/terms" className="underline">{t('footer.terms')}</a> · <a href="#/privacy" className="underline">{t('footer.privacy')}</a></p>}
        <p className="text-sm text-center">{signup ? t('auth.have') : t('auth.need')} <a className="font-bold text-indigo-600 dark:text-indigo-400 hover:underline" href={signup ? '#/login' : '#/signup'}>{signup ? t('nav.signin') : t('nav.signup')}</a></p>
      </form>
    </main>
  );
};
export default AuthPage;
