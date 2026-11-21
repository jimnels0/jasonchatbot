import React, { useCallback, useEffect, useState } from 'react';
import { call } from '../../api';
import { useT } from '../../i18n';
import { when } from '../../format';
import { btn, Card, Err } from '../../ui';

export type LiveRow = {
  id: number; name: string; email: string; department: string; topic: string; city: string; region: string; country: string; lang: string;
  mode: 'bot' | 'human'; handoff: number; status: 'open' | 'closed'; page: string; device: string; created_at: string; last_at: string;
  visitor_seen_at: string | null; handoff_at: string | null; agent_name: string | null; msgs: number; online: boolean;
};
export type LiveData = { available: boolean; waiting: { id: number; name: string; department: string; since: string }[]; agents_online: { id: number; name: string }[];
  now?: LiveRow[]; today?: LiveRow[] };

const place = (r: LiveRow) => [r.city, r.region, r.country].filter(Boolean).join(', ') || '—';
const clock = (utc: string, lang: string) => new Date(utc.replace(' ', 'T') + 'Z').toLocaleTimeString(lang === 'es' ? 'es-US' : 'en-US', { hour: 'numeric', minute: '2-digit' });
const mins = (utc: string | null) => utc ? Math.max(0, Math.round((Date.now() - new Date(utc.replace(' ', 'T') + 'Z').getTime()) / 60000)) : 0;

// "Live now": who is chatting at this moment and everything we know about them, plus the last 24 hours as a time line.
const Live: React.FC = () => {
  const { t, lang } = useT();
  const [d, setD] = useState<LiveData | null>(null);
  const [err, setErr] = useState('');
  const load = useCallback(() => call<LiveData>('live.php?full=1').then(x => { setD(x); setErr(''); }).catch(e => setErr(e.message)), []);
  useEffect(() => { load(); const id = setInterval(() => { if (document.visibilityState === 'visible') load(); }, 5000); return () => clearInterval(id); }, [load]);
  if (!d) return err ? <Err msg={err} /> : <p className="text-slate-400">{t('common.loading')}</p>;

  const state = (r: LiveRow) => r.status === 'closed' ? <span className="text-slate-400">{t('live.closed')}</span>
    : r.mode === 'human' ? <span className="text-emerald-600 font-bold">{t('live.withagent')} {r.agent_name || '—'}</span>
    : r.handoff && r.handoff_at ? <span className="text-red-600 font-bold">{t('live.waiting')} · {mins(r.handoff_at)} min</span>
    : <span className="text-indigo-600">{t('live.withbot')}</span>;

  return (
    <div className="space-y-4">
      <Err msg={err} />
      <div className={`rounded-xl border px-3 py-2 text-sm ${d.agents_online.length ? 'border-emerald-300 bg-emerald-50 dark:bg-emerald-950 dark:border-emerald-800' : 'border-amber-300 bg-amber-50 dark:bg-amber-950 dark:border-amber-800'}`}>
        {d.agents_online.length ? <><b>{t('live.agents')}:</b> {d.agents_online.map(a => a.name).join(', ')}</> : t('live.noagents')}
      </div>

      <Card title={`${t('live.title')} (${d.now?.length || 0})`}>
        {!d.now?.length ? <p className="text-sm text-slate-400">{t('live.none')}</p> : (
          <div className="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
            {d.now.map(r => (
              <div key={r.id} className={`rounded-2xl border p-3 text-sm ${r.handoff && r.handoff_at && r.mode === 'bot' ? 'border-red-400 ring-2 ring-red-300' : 'border-slate-200 dark:border-slate-800'}`}>
                <div className="flex items-center gap-2">
                  <span className={`w-2.5 h-2.5 rounded-full shrink-0 ${r.online ? 'bg-emerald-500' : 'bg-slate-300'}`} title={r.online ? t('live.online') : t('live.away')} />
                  <b className="truncate text-base">{r.name}</b>
                  <span className="ml-auto text-xs whitespace-nowrap">{state(r)}</span>
                </div>
                <dl className="grid grid-cols-[6.5rem_1fr] gap-x-2 gap-y-0.5 mt-2 text-xs">
                  <dt className="text-slate-400">{t('chats.email')}</dt><dd className="truncate">{r.email || '—'}</dd>
                  <dt className="text-slate-400">{t('chats.from')}</dt><dd className="truncate">{place(r)}</dd>
                  <dt className="text-slate-400">{t('chats.dept')}</dt><dd className="truncate">{r.department || '—'}</dd>
                  <dt className="text-slate-400">{t('chats.topic')}</dt><dd className="truncate">{r.topic || '—'}</dd>
                  <dt className="text-slate-400">{t('live.started')}</dt><dd>{clock(r.created_at, lang)} ({mins(r.created_at)} min)</dd>
                  <dt className="text-slate-400">{t('live.last')}</dt><dd>{when(r.last_at, lang)} · {r.msgs} {t('chats.msgs')}</dd>
                  <dt className="text-slate-400">{t('live.device')}</dt><dd className="truncate">{r.device || '—'} · {r.lang.toUpperCase()}</dd>
                  <dt className="text-slate-400">{t('chats.page')}</dt><dd className="truncate">{r.page ? <a className="underline" href={r.page} target="_blank" rel="noreferrer noopener" title={r.page}>{r.page.replace(/^https?:\/\//, '')}</a> : '—'}</dd>
                </dl>
                <a href={`#/app/chats/${r.id}`} className={`${r.handoff && r.mode === 'bot' ? btn.primary : btn.ghost} w-full mt-3`}>{r.handoff && r.mode === 'bot' ? t('live.join') : t('live.open')}</a>
              </div>))}
          </div>)}
      </Card>

      <Card title={t('live.today')}>
        {!d.today?.length ? <p className="text-sm text-slate-400">{t('common.none')}</p> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="text-left text-xs text-slate-500 border-b border-slate-200 dark:border-slate-800">
                <th className="py-1.5 pr-3">{t('live.started')}</th><th className="pr-3">{t('team.name')}</th><th className="pr-3">{t('chats.from')}</th><th className="pr-3">{t('chats.dept')}</th>
                <th className="pr-3">{t('chats.topic')}</th><th className="pr-3">{t('live.msgs')}</th><th className="pr-3">{t('live.device')}</th><th></th></tr></thead>
              <tbody>{d.today.map(r => (
                <tr key={r.id} className="border-b border-slate-100 dark:border-slate-800">
                  <td className="py-1.5 pr-3 whitespace-nowrap">{clock(r.created_at, lang)}</td>
                  <td className="pr-3"><span className={`inline-block w-2 h-2 rounded-full mr-1.5 ${r.online ? 'bg-emerald-500' : 'bg-slate-300'}`} />{r.name}{r.email ? <span className="text-slate-400"> · {r.email}</span> : null}</td>
                  <td className="pr-3">{place(r)}</td><td className="pr-3">{r.department || '—'}</td><td className="pr-3">{r.topic || '—'}</td>
                  <td className="pr-3">{r.msgs}</td><td className="pr-3 text-xs text-slate-500">{r.device || '—'}</td>
                  <td><a className={btn.small} href={`#/app/chats/${r.id}`}>{t('live.open')}</a></td>
                </tr>))}</tbody>
            </table>
          </div>)}
      </Card>
    </div>
  );
};
export default Live;
