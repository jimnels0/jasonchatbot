import React, { useCallback, useEffect, useState } from 'react';
import { call, User } from '../../api';
import { useT } from '../../i18n';
import { titleCase, when } from '../../format';
import { btn, Card, Copy, Err, input, label, useDialog } from '../../ui';

type Member = { id: number; name: string; email: string; role: 'owner' | 'agent'; status: 'active' | 'disabled'; last_login_at: string | null };

const Team: React.FC<{ user: User }> = ({ user }) => {
  const { t, lang } = useT();
  const { confirm } = useDialog();
  const [rows, setRows] = useState<Member[] | null>(null);
  const [f, setF] = useState({ name: '', email: '' });
  const [pw, setPw] = useState<{ who: string; pw: string } | null>(null);
  const [err, setErr] = useState('');
  const load = useCallback(() => call<{ rows: Member[] }>('team.php').then(r => setRows(r.rows)).catch(e => setErr(e.message)), []);
  useEffect(() => { load(); }, [load]);

  const add = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('');
    try { const r = await call<{ password: string }>('team.php', 'POST', { name: titleCase(f.name), email: f.email }); setPw({ who: f.email, pw: r.password }); setF({ name: '', email: '' }); load(); }
    catch (x) { setErr((x as Error).message); }
  };
  const act = async (m: Member, a: 'reset' | 'toggle' | 'remove') => {
    setErr('');
    try {
      if (a === 'remove') {
        if (!await confirm({ title: t('common.delete'), body: `${m.name} <${m.email}>\n\n${t('team.remove.q')}`, danger: true, okText: t('common.delete') })) return;
        await call(`team.php?id=${m.id}`, 'DELETE');
      } else {
        const r = await call<{ password?: string }>(`team.php?id=${m.id}&a=${a}`, 'POST');
        if (r.password) setPw({ who: m.email, pw: r.password });
      }
      load();
    } catch (x) { setErr((x as Error).message); }
  };

  return (
    <div className="grid lg:grid-cols-[1fr_22rem] gap-4 items-start">
      <Card title={t('team.title')}>
        <p className="text-sm text-slate-500 mb-3">{t('team.hint')}</p>
        {pw && (
          <div className="mb-3 rounded-xl border border-green-300 bg-green-50 dark:bg-green-950 dark:border-green-800 px-3 py-2 text-sm">
            <p className="font-semibold">{pw.who}</p><p className="text-xs mb-1">{t('team.pw')}</p>
            <div className="flex items-center gap-2"><code className="font-mono select-all">{pw.pw}</code><Copy text={pw.pw} /><button className={btn.small} onClick={() => setPw(null)}>{t('common.close')}</button></div>
          </div>)}
        <Err msg={err} />
        {!rows ? <p className="text-slate-400 text-sm">{t('common.loading')}</p> : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map(m => (
            <li key={m.id} className="py-2.5 flex items-center gap-3 flex-wrap">
              <div className="flex-1 min-w-[12rem]">
                <p className="font-semibold text-sm">{m.name} <span className="text-xs text-slate-400">· {m.role === 'owner' ? t('team.owner') : t('team.agent')}{m.status === 'disabled' ? ` · ${t('team.disabled')}` : ''}</span></p>
                <p className="text-xs text-slate-500">{m.email} · {t('team.last')}: {when(m.last_login_at, lang)}</p>
              </div>
              {m.role !== 'owner' && m.id !== user.id && <div className="flex gap-1">
                <button className={btn.small} onClick={() => act(m, 'reset')}>{t('team.reset')}</button>
                <button className={btn.small} onClick={() => act(m, 'toggle')}>{m.status === 'active' ? t('team.off') : t('team.on')}</button>
                <button className={`${btn.small} text-red-600 border-red-300`} onClick={() => act(m, 'remove')}>{t('common.delete')}</button>
              </div>}
            </li>))}</ul>)}
      </Card>
      <Card title={t('team.add')}>
        <form onSubmit={add} className="space-y-3">
          <div><label className={label} htmlFor="tn">{t('team.name')}</label><input id="tn" className={input} value={f.name} onChange={e => setF({ ...f, name: e.target.value })} onBlur={() => setF(v => ({ ...v, name: titleCase(v.name) }))} required maxLength={100} /></div>
          <div><label className={label} htmlFor="te">{t('team.email')}</label><input id="te" type="email" className={input} value={f.email} onChange={e => setF({ ...f, email: e.target.value })} required maxLength={190} /></div>
          <button className={btn.primary}>{t('team.add')}</button>
        </form>
      </Card>
    </div>
  );
};
export default Team;
