import React, { useEffect, useState } from 'react';
import { call } from '../../api';
import { useT } from '../../i18n';
import { btn, Card, Copy, Err, input, label, useDialog } from '../../ui';
import { loadWidget, removeWidget } from '../../widgetLoader';
import type { Tenant } from './Settings';

// The one line to paste, which websites may use it, and a live test of the bot on this page.
const Install: React.FC = () => {
  const { t, lang } = useT();
  const { confirm, toast } = useDialog();
  const [f, setF] = useState<Tenant | null>(null);
  const [domains, setDomains] = useState('');
  const [err, setErr] = useState('');
  const apply = (tn: Tenant) => { setF(tn); setDomains(tn.allowed_domains.split(',').filter(Boolean).join('\n')); };
  useEffect(() => { call<{ tenant: Tenant }>('tenant.php').then(r => apply(r.tenant)).catch(e => setErr(e.message)); }, []);
  useEffect(() => { if (f?.public_key) loadWidget(f.public_key, lang); return removeWidget; }, [f?.public_key, lang]);
  if (!f) return err ? <Err msg={err} /> : <p className="text-slate-400">{t('common.loading')}</p>;
  const snippet = `<script src="${location.origin}/widget.js" data-key="${f.public_key}" async></script>`;

  const saveDomains = async () => {
    setErr('');
    try { const r = await call<{ tenant: Tenant }>('tenant.php', 'PUT', { ...f, allowed_domains: domains }); apply(r.tenant); toast(t('common.saved')); }
    catch (x) { setErr((x as Error).message); }
  };
  const newKey = async () => {
    if (!await confirm({ title: t('inst.newkey'), body: t('inst.newkey.q'), danger: true, okText: t('inst.newkey') })) return;
    try { const r = await call<{ tenant: Tenant }>('tenant.php?a=newkey', 'POST'); apply(r.tenant); } catch (x) { setErr((x as Error).message); }
  };

  return (
    <div className="grid lg:grid-cols-2 gap-4 items-start">
      <Card title={t('inst.title')}>
        <p className="text-sm text-slate-500 mb-2">{t('inst.hint')}</p>
        <pre className="rounded-xl bg-slate-900 text-slate-100 text-xs p-3 whitespace-pre-wrap break-all">{snippet}</pre>
        <div className="flex gap-2 mt-2"><Copy text={snippet} /><button className={`${btn.small} text-red-600 border-red-300`} onClick={newKey}>{t('inst.newkey')}</button></div>
        <div className="mt-5">
          <label className={label} htmlFor="ad">{t('inst.domains')}</label>
          <textarea id="ad" className={input} rows={4} value={domains} onChange={e => setDomains(e.target.value)} placeholder="yourcompany.com" />
          <p className="text-xs text-slate-500 mt-1">{t('inst.domains.hint')}</p>
          <button className={`${btn.primary} mt-2`} onClick={saveDomains}>{t('common.save')}</button>
        </div>
        <div className="mt-3"><Err msg={err} /></div>
      </Card>
      <Card title={t('inst.test')}>
        <p className="text-sm text-slate-500">{t('inst.test.hint')}</p>
        <p className="text-5xl text-right mt-6" aria-hidden>↘</p>
      </Card>
    </div>
  );
};
export default Install;
