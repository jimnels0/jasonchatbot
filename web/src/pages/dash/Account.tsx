import React, { useState } from 'react';
import { call, User } from '../../api';
import { useT } from '../../i18n';
import PasswordSuggest from '../../PasswordSuggest';
import { btn, Card, Err, input, label, useDialog } from '../../ui';

const Account: React.FC<{ user: User }> = ({ user }) => {
  const { t } = useT();
  const { toast } = useDialog();
  const [f, setF] = useState({ current: '', next: '', confirm: '' });
  const [err, setErr] = useState('');
  const save = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('');
    if (f.next !== f.confirm) { setErr(t('auth.mismatch')); return; }
    try { await call('auth.php?a=password', 'POST', { current: f.current, new: f.next }); setF({ current: '', next: '', confirm: '' }); toast(t('acct.changed')); }
    catch (x) { setErr((x as Error).message); }
  };
  return (
    <div className="max-w-md">
      <Card title={t('acct.pw')}>
        <p className="text-sm text-slate-500 mb-3">{user.name} · {user.email}</p>
        <form onSubmit={save} className="space-y-3">
          <div><label className={label} htmlFor="ac">{t('acct.current')}</label><input id="ac" type="password" className={input} value={f.current} onChange={e => setF({ ...f, current: e.target.value })} required autoComplete="current-password" /></div>
          <div><label className={label} htmlFor="an">{t('acct.new')}</label><input id="an" type="password" className={input} value={f.next} onChange={e => setF({ ...f, next: e.target.value })} required minLength={10} autoComplete="new-password" />
            <p className="text-xs text-slate-400 mt-1">{t('auth.password.hint')}</p></div>
          <div><label className={label} htmlFor="an2">{t('auth.confirm')}</label><input id="an2" type="password" className={input} value={f.confirm} onChange={e => setF({ ...f, confirm: e.target.value })} required autoComplete="new-password" /></div>
          <PasswordSuggest onPick={p => setF(v => ({ ...v, next: p, confirm: p }))} />
          <Err msg={err} />
          <button className={btn.primary}>{t('common.save')}</button>
        </form>
      </Card>
    </div>
  );
};
export default Account;
