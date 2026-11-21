import React, { useCallback, useEffect, useState } from 'react';
import { call } from '../api';
import { useT } from '../i18n';
import { when } from '../format';
import { btn, Card, Err } from '../ui';

type T = { id: number; name: string; website: string; plan: string; msg_limit: number; status: string; created_at: string; owner_email: string;
  team: number; sources: number; chats_30d: number; answers_month: number | null; tokens_month: number | null };
type V = { id: number; kind: string; ip: string; country: string; path: string; note: string; created_at: string };

// Platform console (James): every company with its usage, and Visitors (sign-ups + outside-USA blocks).
const Owner: React.FC = () => {
  const { t, lang } = useT();
  const [d, setD] = useState<{ tenants: T[]; visits: V[] } | null>(null);
  const [err, setErr] = useState('');
  const load = useCallback(() => call('owner.php').then(setD).catch(e => setErr(e.message)), []);
  useEffect(() => { load(); }, [load]);
  const toggle = async (x: T) => { try { await call(`owner.php?id=${x.id}&a=${x.status === 'active' ? 'pause' : 'activate'}`, 'POST'); load(); } catch (e) { setErr((e as Error).message); } };
  if (!d) return <main className="max-w-7xl mx-auto px-4">{err ? <Err msg={err} /> : <p className="text-slate-400">{t('common.loading')}</p>}</main>;
  const th = 'px-2 py-1.5 text-left font-bold whitespace-nowrap';
  return (
    <main className="max-w-7xl mx-auto px-4 pb-16 space-y-4">
      <Err msg={err} />
      <Card title={`${t('owner.tenants')} (${d.tenants.length})`}>
        <div className="overflow-x-auto"><table className="w-full min-w-[56rem] text-xs">
          <thead className="text-slate-400 uppercase"><tr>{['Company', 'Owner', 'Plan', 'Answers (month)', 'Tokens', 'Chats 30d', 'Sources', 'Team', 'Since', ''].map(h => <th key={h} className={th}>{h}</th>)}</tr></thead>
          <tbody>{d.tenants.map(x => (
            <tr key={x.id} className="border-t border-slate-100 dark:border-slate-800">
              <td className="px-2 py-1.5 font-semibold">{x.name}<div className="text-slate-400 font-normal">{x.website}</div></td>
              <td className="px-2 py-1.5">{x.owner_email}</td><td className="px-2 py-1.5">{x.plan}</td>
              <td className="px-2 py-1.5">{x.answers_month ?? 0} / {x.msg_limit}</td><td className="px-2 py-1.5">{(x.tokens_month ?? 0).toLocaleString()}</td>
              <td className="px-2 py-1.5">{x.chats_30d}</td><td className="px-2 py-1.5">{x.sources}</td><td className="px-2 py-1.5">{x.team}</td>
              <td className="px-2 py-1.5 whitespace-nowrap">{when(x.created_at, lang)}</td>
              <td className="px-2 py-1.5"><button className={btn.small} onClick={() => toggle(x)}>{x.status === 'active' ? t('owner.pause') : t('owner.activate')}</button></td>
            </tr>))}</tbody>
        </table></div>
      </Card>
      <Card title={t('owner.visits')}>
        <div className="overflow-x-auto"><table className="w-full min-w-[36rem] text-xs">
          <thead className="text-slate-400 uppercase"><tr>{['When', 'Kind', 'IP', 'Country', 'Note'].map(h => <th key={h} className={th}>{h}</th>)}</tr></thead>
          <tbody>{d.visits.map(v => (
            <tr key={v.id} className="border-t border-slate-100 dark:border-slate-800">
              <td className="px-2 py-1.5 whitespace-nowrap">{when(v.created_at, lang)}</td><td className="px-2 py-1.5">{v.kind}</td><td className="px-2 py-1.5">{v.ip}</td>
              <td className="px-2 py-1.5">{v.country}</td><td className="px-2 py-1.5">{v.note}</td>
            </tr>))}</tbody>
        </table></div>
      </Card>
    </main>
  );
};
export default Owner;
