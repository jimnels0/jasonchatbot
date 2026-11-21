import React, { useEffect, useState } from 'react';
import { call, User } from '../../api';
import { useT } from '../../i18n';
import { when } from '../../format';
import { Bars, btn, Card, Err } from '../../ui';

type Stats = {
  chats_today: number; chats_7d: number; chats_30d: number; waiting: number; answers_month: number; msg_limit: number; answered_rate: number | null;
  topics: { label: string; n: number }[]; departments: { label: string; n: number }[]; places: { label: string; n: number }[];
  unanswered: { conv_id: number; created_at: string; question: string | null }[];
};

const Overview: React.FC<{ user: User }> = ({ user }) => {
  const { t, lang } = useT();
  const [s, setS] = useState<Stats | null>(null);
  const [err, setErr] = useState('');
  useEffect(() => { call<Stats>('stats.php').then(setS).catch(e => setErr(e.message)); }, []);
  if (err) return <Err msg={err} />;
  if (!s) return <p className="text-slate-400">{t('common.loading')}</p>;
  const stat = (lbl: string, v: React.ReactNode, href?: string) => (
    <a href={href} className={`block rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 ${href ? 'hover:ring-2 hover:ring-indigo-400' : ''}`}>
      <p className="text-xs text-slate-500">{lbl}</p><p className="text-2xl font-black mt-1">{v}</p></a>);
  const pct = s.msg_limit ? Math.min(100, Math.round(100 * s.answers_month / s.msg_limit)) : 0;
  const addAnswer = (q: string) => { try { sessionStorage.setItem('jcb_prefill_q', q); } catch { /* blocked */ } location.hash = '/app/answers'; };

  return (
    <div className="space-y-5">
      {s.chats_30d === 0 && user.role === 'owner' && (
        <Card title={t('ov.start.title')}>
          <ol className="list-decimal pl-5 text-sm space-y-1">
            <li><a className="underline" href="#/app/knowledge">{t('ov.start.1')}</a></li>
            <li><a className="underline" href="#/app/install">{t('ov.start.2')}</a></li>
            <li><a className="underline" href="#/app/install">{t('ov.start.3')}</a></li>
          </ol>
        </Card>)}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        {stat(t('ov.today'), s.chats_today, '#/app/chats')}
        {stat(t('ov.7d'), s.chats_7d)}
        {stat(t('ov.30d'), s.chats_30d)}
        {stat(t('ov.waiting'), <span className={s.waiting ? 'text-red-600' : ''}>{s.waiting}</span>, '#/app/chats')}
        {stat(t('ov.rate'), s.answered_rate === null ? '—' : `${s.answered_rate}%`)}
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
          <p className="text-xs text-slate-500">{t('ov.answers')}</p>
          <p className="text-2xl font-black mt-1">{s.answers_month}<span className="text-sm text-slate-400 font-semibold"> / {s.msg_limit.toLocaleString()}</span></p>
          <div className="h-1.5 rounded bg-slate-100 dark:bg-slate-800 mt-2"><div className={`h-1.5 rounded ${pct > 85 ? 'bg-red-500' : 'bg-indigo-500'}`} style={{ width: `${pct}%` }} /></div>
        </div>
      </div>
      <div className="grid md:grid-cols-3 gap-4">
        <Card title={t('ov.topics')}><Bars rows={s.topics} /></Card>
        <Card title={t('ov.depts')}><Bars rows={s.departments} /></Card>
        <Card title={t('ov.places')}><Bars rows={s.places} /></Card>
      </div>
      <Card title={t('ov.gaps')}>
        <p className="text-sm text-slate-500 mb-3">{t('ov.gaps.hint')}</p>
        {s.unanswered.length === 0 ? <p className="text-sm text-slate-400">{t('common.none')}</p> : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {s.unanswered.map((u, i) => (
              <li key={i} className="py-2 flex items-center gap-3 text-sm">
                <a className="flex-1 min-w-0 truncate hover:underline" href={`#/app/chats/${u.conv_id}`}>“{u.question || '—'}”</a>
                <span className="text-xs text-slate-400 whitespace-nowrap">{when(u.created_at, lang)}</span>
                {user.role === 'owner' && u.question && <button className={btn.small} onClick={() => addAnswer(u.question!)}>{t('ov.gaps.add')}</button>}
              </li>))}
          </ul>)}
      </Card>
    </div>
  );
};
export default Overview;
