import React, { useCallback, useEffect, useRef, useState } from 'react';
import { call, User } from '../../api';
import { useT } from '../../i18n';
import { when } from '../../format';
import { btn, card, Err, input, useDialog } from '../../ui';

type Row = { id: number; name: string; email: string; department: string; topic: string; city: string; region: string; country: string; lang: string;
  mode: 'bot' | 'human'; handoff: number; unread: number; status: 'open' | 'closed'; page: string; created_at: string; last_at: string; msgs: number; last_msg: string };
type Msg = { id: number; role: 'visitor' | 'bot' | 'agent' | 'system'; body: string; sources: { title: string; url: string }[]; answered: number; via: string; agent_name: string; created_at: string;
  file: { name: string; mime: string; size: number; url: string } | null };
type Full = { chat: Row & { online?: boolean; user_agent?: string }; messages: Msg[]; mail_ready: boolean };
const place = (r: Pick<Row, 'city' | 'region' | 'country'>) => [r.city, r.region, r.country].filter(Boolean).join(', ') || '—';

// The inbox: who is talking (name, location, department, topic), the transcript, and a reply box so a person can take over.
const Chats: React.FC<{ user: User; openId: number }> = ({ user, openId }) => {
  const { t, lang } = useT();
  const { confirm, toast } = useDialog();
  const fileRef = useRef<HTMLInputElement>(null);
  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<Row[] | null>(null);
  const [counts, setCounts] = useState({ handoff: 0, unread: 0 });
  const [chat, setChat] = useState<Full | null>(null);
  const [reply, setReply] = useState('');
  const [err, setErr] = useState('');
  const endRef = useRef<HTMLDivElement>(null);

  const loadList = useCallback(() => call<{ rows: Row[]; counts: { handoff: number; unread: number } }>(`chats.php?filter=${filter}&q=${encodeURIComponent(q)}`)
    .then(r => { setRows(r.rows); setCounts(r.counts); }).catch(e => setErr(e.message)), [filter, q]);
  const loadChat = useCallback((id: number) => id ? call<Full>(`chats.php?id=${id}`).then(setChat).catch(e => setErr(e.message)) : Promise.resolve(setChat(null)), []);

  useEffect(() => { const h = setTimeout(loadList, 250); return () => clearTimeout(h); }, [loadList]);
  useEffect(() => { loadChat(openId); }, [openId, loadChat]);
  useEffect(() => {   // live-ish: list every 10 s, open chat every 4 s, only while the tab is visible
    const a = setInterval(() => { if (document.visibilityState === 'visible') loadList(); }, 10000);
    const b = setInterval(() => { if (document.visibilityState === 'visible' && openId) loadChat(openId); }, 4000);
    return () => { clearInterval(a); clearInterval(b); };
  }, [loadList, loadChat, openId]);
  useEffect(() => { endRef.current?.scrollIntoView({ block: 'end' }); }, [chat?.messages.length]);

  const act = async (a: string, body?: unknown) => { try { await call(`chats.php?id=${openId}&a=${a}`, 'POST', body); await loadChat(openId); loadList(); } catch (e) { setErr((e as Error).message); } };
  const send = async (e: React.FormEvent) => { e.preventDefault(); if (!reply.trim()) return; await act('reply', { text: reply }); setReply(''); };
  const upload = async (f: File | undefined) => {
    if (!f) return;
    const fd = new FormData(); fd.append('file', f);
    await act('upload', fd);
    if (fileRef.current) fileRef.current.value = '';
  };
  const mail = async () => {
    if (!chat?.mail_ready) { toast(t('chats.mail.off')); return; }
    // shortcut: always sent to the signed-in person's own email (no address box yet); forward it from there if needed.
    try { const r = await call<{ to: string }>(`chats.php?id=${openId}&a=email`, 'POST', {}); toast(`${t('chats.mail.sent')} ${r.to}`); } catch (e) { setErr((e as Error).message); }
  };
  const del = async () => {
    if (!await confirm({ title: t('common.delete'), body: t('chats.delete.q'), danger: true, okText: t('common.delete') })) return;
    try { await call(`chats.php?id=${openId}`, 'DELETE'); location.hash = '/app/chats'; loadList(); } catch (e) { setErr((e as Error).message); }
  };

  const filters: [string, string, number?][] = [['all', t('chats.all')], ['handoff', t('chats.waiting'), counts.handoff], ['unread', t('chats.unread'), counts.unread], ['open', t('chats.open')]];
  const c = chat?.chat;
  return (
    <div className="space-y-3">
      <Err msg={err} />
      <div className="grid lg:grid-cols-[22rem_1fr] gap-4 items-start">
        <section className={`${card} overflow-hidden ${openId ? 'hidden lg:block' : ''}`}>
          <div className="p-3 border-b border-slate-200 dark:border-slate-800 space-y-2">
            <div className="flex gap-1 flex-wrap">{filters.map(([k, l, n]) => (
              <button key={k} onClick={() => setFilter(k)} className={`rounded-full px-2.5 py-1 text-xs font-bold ${filter === k ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800'}`}>{l}{n ? ` (${n})` : ''}</button>))}</div>
            <input className={input} placeholder={t('chats.search')} value={q} onChange={e => setQ(e.target.value)} aria-label={t('chats.search')} />
          </div>
          {!rows ? <p className="p-4 text-slate-400 text-sm">{t('common.loading')}</p> : rows.length === 0 ? <p className="p-4 text-slate-400 text-sm">{t('chats.empty')}</p> : (
            <ul className="max-h-[70vh] overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
              {rows.map(r => (
                <li key={r.id}>
                  <a href={`#/app/chats/${r.id}`} className={`block px-3 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800 ${openId === r.id ? 'bg-indigo-50 dark:bg-indigo-950' : ''}`}>
                    <div className="flex items-center gap-2">
                      {r.unread ? <span className="w-2 h-2 rounded-full bg-indigo-500 shrink-0" aria-label={t('chats.unread')} /> : null}
                      <span className={`truncate ${r.unread ? 'font-black' : 'font-semibold'}`}>{r.name}</span>
                      {r.handoff && r.status === 'open' ? <span className="text-[10px] font-bold rounded bg-red-600 text-white px-1.5">{t('chats.waiting')}</span> : null}
                      <span className="ml-auto text-[11px] text-slate-400 whitespace-nowrap">{when(r.last_at, lang)}</span>
                    </div>
                    <div className="text-xs text-slate-500 truncate">{place(r)} · {r.department || '—'}{r.topic ? ` · ${r.topic}` : ''}</div>
                    <div className="text-xs text-slate-400 truncate">{r.last_msg}</div>
                  </a>
                </li>))}
            </ul>)}
        </section>

        <section className={`${card} ${openId ? '' : 'hidden lg:block'}`}>
          {!c ? <p className="p-6 text-slate-400 text-sm">{openId ? t('common.loading') : t('chats.pick')}</p> : (<>
            <div className="p-4 border-b border-slate-200 dark:border-slate-800">
              <a href="#/app/chats" className="lg:hidden text-sm font-semibold text-indigo-600 dark:text-indigo-400">{t('chats.back')}</a>
              <div className="flex items-start gap-3 flex-wrap mt-1">
                <div className="mr-auto">
                  <h2 className="text-lg font-black flex items-center gap-2"><span className={`w-2.5 h-2.5 rounded-full ${c.online ? 'bg-emerald-500' : 'bg-slate-300'}`} title={c.online ? t('chats.online') : t('chats.offline')} />{c.name}</h2>
                  <p className="text-xs text-slate-500">{c.status === 'closed' ? t('chats.closed') : c.mode === 'human' ? t('chats.human') : t('chats.bot')}</p>
                </div>
                <div className="flex gap-1 flex-wrap">
                  <button className={btn.small} onClick={mail} title={t('chats.mail')}>✉ {t('chats.mail')}</button>
                  {c.mode === 'human' && c.status === 'open' && <button className={btn.small} onClick={() => act('bot')}>{t('chats.handback')}</button>}
                  {c.status === 'open' ? <button className={btn.small} onClick={() => act('close')}>{t('chats.close')}</button> : <button className={btn.small} onClick={() => act('reopen')}>{t('chats.reopen')}</button>}
                  {user.role === 'owner' && <button className={`${btn.small} text-red-600 border-red-300`} onClick={del}>{t('common.delete')}</button>}
                </div>
              </div>
              <dl className="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 mt-3 text-xs">
                {([[t('chats.from'), place(c)], [t('chats.dept'), c.department || '—'], [t('chats.topic'), c.topic || '—'], [t('chats.lang'), c.lang.toUpperCase()],
                  [t('chats.email'), c.email || '—'], [t('chats.started'), when(c.created_at, lang)]] as [string, string][]).map(([k, v]) => (
                  <div key={k}><dt className="text-slate-400">{k}</dt><dd className="font-semibold truncate" title={v}>{v}</dd></div>))}
                <div className="col-span-2"><dt className="text-slate-400">{t('chats.page')}</dt><dd className="truncate">{c.page ? <a className="underline" href={c.page} target="_blank" rel="noreferrer noopener">{c.page}</a> : '—'}</dd></div>
              </dl>
            </div>
            <div className="p-4 space-y-2 max-h-[55vh] overflow-y-auto bg-slate-50 dark:bg-slate-950">
              {chat!.messages.map(m => {
                const mine = m.role === 'visitor';
                if (m.role === 'system') return <p key={m.id} className="text-center text-xs text-slate-400">{t('chats.role.system')}: {m.body}</p>;
                return (
                  <div key={m.id} className={`max-w-[85%] ${mine ? '' : 'ml-auto'}`}>
                    <div className={`rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap break-words ${mine ? 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700' : m.role === 'agent' ? 'bg-emerald-600 text-white' : 'bg-indigo-600 text-white'}`}>
                      {m.file ? <a className="underline" href={m.file.url} target="_blank" rel="noreferrer noopener">📎 {m.file.name} <span className="opacity-70 text-xs">({Math.max(1, Math.round(m.file.size / 1024))} KB)</span>
                        {m.file.mime.startsWith('image/') && <img src={m.file.url} alt={m.file.name} loading="lazy" className="mt-1 max-h-48 rounded-lg" />}</a> : m.body}</div>
                    {m.sources?.length > 0 && <p className="text-[11px] text-slate-500 mt-0.5">{t('chats.sources')}: {m.sources.map((s, i) => <a key={i} className="underline mr-1" href={s.url} target="_blank" rel="noreferrer noopener">{s.title || s.url}</a>)}</p>}
                    <p className={`text-[10px] text-slate-400 mt-0.5 ${mine ? '' : 'text-right'}`}>
                      {m.role === 'agent' ? m.agent_name : t(`chats.role.${m.role}` as 'chats.role.bot')} · {when(m.created_at, lang)}
                      {m.role === 'bot' && !m.answered ? <span className="text-amber-600"> · {t('chats.notanswered')}</span> : null}
                    </p>
                  </div>);
              })}
              <div ref={endRef} />
            </div>
            <form onSubmit={send} className="p-3 border-t border-slate-200 dark:border-slate-800 flex gap-2">
              <input ref={fileRef} type="file" className="hidden" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv" onChange={e => upload(e.target.files?.[0])} />
              <button type="button" className={btn.ghost} onClick={() => fileRef.current?.click()} title={t('chats.attach')} aria-label={t('chats.attach')}>📎</button>
              <textarea className={`${input} resize-none`} rows={2} value={reply} onChange={e => setReply(e.target.value)} placeholder={t('chats.reply')} aria-label={t('chats.reply')} maxLength={2000}
                onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); (e.currentTarget.form as HTMLFormElement).requestSubmit(); } }} />
              <button className={btn.primary} disabled={!reply.trim()}>{t('chats.send')}</button>
            </form>
          </>)}
        </section>
      </div>
    </div>
  );
};
export default Chats;
