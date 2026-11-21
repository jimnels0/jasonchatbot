import React, { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { useT } from './i18n';

// Shared look. Dark mode follows the device (Tailwind darkMode: 'media').
export const card = 'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl';
export const input = 'w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';
export const label = 'block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1';
const btnBase = 'inline-flex items-center justify-center gap-1 rounded-xl px-4 py-2 text-sm font-bold transition disabled:opacity-50';
export const btn = {
  primary: `${btnBase} bg-indigo-600 text-white hover:bg-indigo-700`,
  ghost: `${btnBase} border border-slate-300 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800`,
  danger: `${btnBase} bg-red-600 text-white hover:bg-red-700`,
  small: 'inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-bold border border-slate-300 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-50',
};

export const Card: React.FC<{ title?: string; children: React.ReactNode; className?: string; right?: React.ReactNode }> = ({ title, children, className = '', right }) => (
  <section className={`${card} p-4 sm:p-5 ${className}`}>
    {(title || right) && <div className="flex items-center justify-between gap-2 mb-3"><h2 className="font-bold">{title}</h2>{right}</div>}
    {children}
  </section>
);

export const Err: React.FC<{ msg: string }> = ({ msg }) => msg ? <p className="rounded-xl bg-red-50 dark:bg-red-950 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-900 px-3 py-2 text-sm">{msg}</p> : null;

export const Copy: React.FC<{ text: string }> = ({ text }) => {
  const { t } = useT();
  const [ok, setOk] = useState(false);
  return <button type="button" className={btn.small} onClick={async () => { try { await navigator.clipboard.writeText(text); setOk(true); setTimeout(() => setOk(false), 1800); } catch { /* clipboard blocked */ } }}>{ok ? t('common.copied') : t('common.copy')}</button>;
};

// ---- Themed dialogs + toast (never the browser's alert/confirm: James's rule) ----
type Ask = { title: string; body?: string; danger?: boolean; okText?: string; alertOnly?: boolean };
const DialogCtx = createContext<{ confirm: (a: Ask) => Promise<boolean>; toast: (msg: string) => void }>({ confirm: async () => false, toast: () => {} });
export const useDialog = () => useContext(DialogCtx);

export const DialogProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { t } = useT();
  const [ask, setAsk] = useState<(Ask & { resolve: (v: boolean) => void }) | null>(null);
  const [toastMsg, setToastMsg] = useState('');
  const cancelRef = useRef<HTMLButtonElement>(null);
  const okRef = useRef<HTMLButtonElement>(null);
  const confirm = useCallback((a: Ask) => new Promise<boolean>(resolve => setAsk({ ...a, resolve })), []);
  const toast = useCallback((m: string) => { setToastMsg(m); setTimeout(() => setToastMsg(''), 2800); }, []);
  const close = (v: boolean) => { ask?.resolve(v); setAsk(null); };
  useEffect(() => {
    if (!ask) return;
    (ask.danger || !ask.alertOnly ? cancelRef.current ?? okRef.current : okRef.current)?.focus();   // destructive: focus starts on Cancel
    const k = (e: KeyboardEvent) => { if (e.key === 'Escape') close(false); };
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  });
  return (
    <DialogCtx.Provider value={{ confirm, toast }}>
      {children}
      {ask && (
        <div className="fixed inset-0 z-[100] bg-black/50 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={() => close(false)}>
          <div className={`${card} w-full max-w-sm p-5 shadow-xl`} onClick={e => e.stopPropagation()}>
            <h3 className="font-bold text-lg mb-2">{ask.title}</h3>
            {ask.body && <p className="text-sm text-slate-600 dark:text-slate-300 mb-4 whitespace-pre-wrap">{ask.body}</p>}
            <div className="flex justify-end gap-2">
              {!ask.alertOnly && <button ref={cancelRef} className={btn.ghost} onClick={() => close(false)}>{t('common.cancel')}</button>}
              <button ref={okRef} className={ask.danger ? btn.danger : btn.primary} onClick={() => close(true)}>{ask.okText || t('common.ok')}</button>
            </div>
          </div>
        </div>
      )}
      {toastMsg && <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-[110] rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 px-4 py-2 text-sm shadow-lg">{toastMsg}</div>}
    </DialogCtx.Provider>
  );
};

/** Little bar list for topics / departments / places. */
export const Bars: React.FC<{ rows: { label: string; n: number | string }[] }> = ({ rows }) => {
  const { t } = useT();
  if (!rows.length) return <p className="text-sm text-slate-400">{t('common.none')}</p>;
  const max = Math.max(...rows.map(r => Number(r.n)));
  return <ul className="space-y-1.5">{rows.map(r => (
    <li key={r.label} className="text-sm">
      <div className="flex justify-between gap-2"><span className="truncate">{r.label}</span><span className="text-slate-400">{r.n}</span></div>
      <div className="h-1.5 rounded bg-slate-100 dark:bg-slate-800"><div className="h-1.5 rounded bg-indigo-500" style={{ width: `${(Number(r.n) / max) * 100}%` }} /></div>
    </li>))}</ul>;
};
