import React, { useCallback, useEffect, useState } from 'react';
import { call } from '../../api';
import { useT } from '../../i18n';
import { when } from '../../format';
import { btn, Card, Err, input, useDialog } from '../../ui';

type Src = { id: number; kind: 'site' | 'url' | 'text' | 'pdf' | 'doc'; title: string; url: string; status: 'queued' | 'working' | 'ready' | 'error'; error: string; pages: number; chars: number; chunks: number; updated_at: string };
const BADGE = { queued: 'bg-slate-200 dark:bg-slate-700', working: 'bg-amber-200 text-amber-900', ready: 'bg-emerald-200 text-emerald-900', error: 'bg-red-200 text-red-900' };

const Knowledge: React.FC = () => {
  const { t, lang } = useT();
  const { confirm, toast } = useDialog();
  const [data, setData] = useState<{ sources: Src[]; chunks: number; qa_count: number; ai_ready: boolean } | null>(null);
  const [mode, setMode] = useState<'site' | 'url' | 'text' | 'pdf'>('site');
  const [url, setUrl] = useState('');
  const [title, setTitle] = useState('');
  const [text, setText] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => call('sources.php').then(setData).catch(e => setErr(e.message)), []);
  useEffect(() => { load(); }, [load]);
  const busyReading = !!data?.sources.some(s => s.status === 'queued' || s.status === 'working');
  useEffect(() => { if (!busyReading) return; const id = setInterval(load, 8000); return () => clearInterval(id); }, [busyReading, load]);

  const add = async (e: React.FormEvent) => {
    e.preventDefault(); setErr(''); setBusy(true);
    try {
      if (mode === 'pdf') { if (!file) return; const fd = new FormData(); fd.append('file', file); await call('sources.php', 'POST', fd); setFile(null); }
      else if (mode === 'text') { await call('sources.php', 'POST', { kind: 'text', title, text }); setTitle(''); setText(''); }
      else { await call('sources.php', 'POST', { kind: mode, url }); setUrl(''); }
      toast(mode === 'text' ? t('common.saved') : t('kb.added'));
      load();
    } catch (x) { setErr((x as Error).message); } finally { setBusy(false); }
  };
  const del = async (s: Src) => {
    if (!await confirm({ title: t('common.delete'), body: `${s.title || s.url}\n\n${t('kb.delete.q')}`, danger: true, okText: t('common.delete') })) return;
    try { await call(`sources.php?id=${s.id}`, 'DELETE'); load(); } catch (x) { setErr((x as Error).message); }
  };
  const refresh = async (s: Src) => { try { await call(`sources.php?id=${s.id}&a=refresh`, 'POST'); load(); } catch (x) { setErr((x as Error).message); } };

  const modes = [['site', t('kb.site')], ['url', t('kb.url')], ['text', t('kb.text')], ['pdf', t('kb.doc')]] as const;
  return (
    <div className="space-y-4">
      {data && !data.ai_ready && <p className="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-950 dark:border-amber-800 px-3 py-2 text-sm">{t('kb.noai')}</p>}
      <Card title={t('kb.title')} right={data && <span className="text-xs text-slate-500">{data.chunks.toLocaleString()} {t('kb.total')}</span>}>
        <div className="flex gap-1 flex-wrap mb-3" role="tablist">{modes.map(([k, l]) => (
          <button key={k} role="tab" aria-selected={mode === k} onClick={() => setMode(k)} className={`rounded-full px-3 py-1 text-sm font-bold ${mode === k ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800'}`}>{l}</button>))}</div>
        <form onSubmit={add} className="space-y-2">
          {(mode === 'site' || mode === 'url') && <>
            <div className="flex gap-2"><input className={input} value={url} onChange={e => setUrl(e.target.value)} placeholder={t('kb.url.ph')} required aria-label={t('kb.url.ph')} />
              <button className={btn.primary} disabled={busy}>{mode === 'site' ? t('kb.addsite') : t('kb.addurl')}</button></div>
            {mode === 'site' && <p className="text-xs text-slate-500">{t('kb.site.hint')}</p>}
          </>}
          {mode === 'text' && <>
            <input className={input} value={title} onChange={e => setTitle(e.target.value)} placeholder={t('kb.text.title')} required maxLength={250} aria-label={t('kb.text.title')} />
            <textarea className={input} rows={8} value={text} onChange={e => setText(e.target.value)} placeholder={t('kb.text.body')} required aria-label={t('kb.text.body')} />
            <button className={btn.primary} disabled={busy}>{t('kb.addtext')}</button>
          </>}
          {mode === 'pdf' && <>
            <input type="file" accept=".pdf,.docx,.txt,.md,.csv,application/pdf" onChange={e => setFile(e.target.files?.[0] || null)} className="text-sm" aria-label={t('kb.doc')} />
            <p className="text-xs text-slate-500">{t('kb.doc.hint')}</p>
            <button className={btn.primary} disabled={busy || !file}>{t('kb.doc')}</button>
          </>}
        </form>
        <div className="mt-3"><Err msg={err} /></div>
      </Card>

      <Card title={t('kb.list')} right={<button className={btn.small} onClick={load}>{t('common.refresh')}</button>}>
        {!data ? <p className="text-slate-400 text-sm">{t('common.loading')}</p> : data.sources.length === 0 ? <p className="text-slate-400 text-sm">{t('common.none')}</p> : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {data.sources.map(s => (
              <li key={s.id} className="py-2.5 flex items-center gap-3 flex-wrap">
                <span className="text-lg" aria-hidden>{{ site: '🌐', url: '📄', text: '📝', pdf: '📕', doc: '📘' }[s.kind]}</span>
                <div className="flex-1 min-w-[12rem]">
                  <p className="font-semibold text-sm truncate">{s.title || s.url}</p>
                  <p className="text-xs text-slate-500 truncate">{s.url && <a className="underline mr-2" href={s.url} target="_blank" rel="noreferrer noopener">{s.url}</a>}
                    {s.status === 'ready' && `${s.pages} ${t('kb.pages')} · ${s.chars.toLocaleString()} ${t('kb.chars')} · `}{when(s.updated_at, lang)}</p>
                  {s.status === 'error' && <p className="text-xs text-red-600">{s.error}</p>}
                </div>
                <span className={`text-[11px] font-bold rounded-full px-2 py-0.5 ${BADGE[s.status]}`}>{t(`kb.status.${s.status}` as 'kb.status.ready')}</span>
                {s.kind !== 'text' && <button className={btn.small} onClick={() => refresh(s)} disabled={s.status === 'queued' || s.status === 'working'}>{t('kb.refresh')}</button>}
                <button className={`${btn.small} text-red-600 border-red-300`} onClick={() => del(s)}>{t('common.delete')}</button>
              </li>))}
          </ul>)}
      </Card>
    </div>
  );
};
export default Knowledge;
