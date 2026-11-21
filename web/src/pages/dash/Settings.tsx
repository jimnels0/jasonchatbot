import React, { useEffect, useState } from 'react';
import { call } from '../../api';
import { useT } from '../../i18n';
import { titleCase, titleCaseList } from '../../format';
import { btn, Card, Err, input, label, useDialog } from '../../ui';

export type Tenant = { id: number; public_key: string; name: string; website: string; color: string; greeting_en: string; greeting_es: string; tone: string;
  departments: string; allowed_domains: string; ask_email: number; plan: string; msg_limit: number; max_pages: number; answers_this_month: number };

const Settings: React.FC = () => {
  const { t } = useT();
  const { toast } = useDialog();
  const [f, setF] = useState<Tenant | null>(null);
  const [err, setErr] = useState('');
  useEffect(() => { call<{ tenant: Tenant }>('tenant.php').then(r => setF(r.tenant)).catch(e => setErr(e.message)); }, []);
  if (!f) return err ? <Err msg={err} /> : <p className="text-slate-400">{t('common.loading')}</p>;
  const set = (k: keyof Tenant) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });

  const save = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('');
    try { const r = await call<{ tenant: Tenant }>('tenant.php', 'PUT', { ...f, name: titleCase(f.name), departments: titleCaseList(f.departments) }); setF(r.tenant); toast(t('common.saved')); }
    catch (x) { setErr((x as Error).message); }
  };

  return (
    <form onSubmit={save} className="grid lg:grid-cols-2 gap-4 items-start">
      <Card title={t('tab.settings')}>
        <div className="space-y-3">
          <div><label className={label} htmlFor="sn">{t('set.company')}</label><input id="sn" className={input} value={f.name} onChange={set('name')} onBlur={() => setF({ ...f, name: titleCase(f.name) })} required maxLength={120} /></div>
          <div><label className={label} htmlFor="sw">{t('set.website')}</label><input id="sw" className={input} value={f.website} onChange={set('website')} placeholder="https://yourcompany.com" maxLength={255} /></div>
          <div><label className={label} htmlFor="sd">{t('set.depts')}</label><input id="sd" className={input} value={f.departments} onChange={set('departments')} onBlur={() => setF({ ...f, departments: titleCaseList(f.departments) })} maxLength={500} /></div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!f.ask_email} onChange={e => setF({ ...f, ask_email: e.target.checked ? 1 : 0 })} />{t('set.askemail')}</label>
          <div className="flex items-center gap-3"><label className={label} htmlFor="sc">{t('set.color')}</label><input id="sc" type="color" value={f.color} onChange={set('color')} className="h-9 w-14 rounded" /></div>
        </div>
      </Card>
      <Card title={t('set.tone')}>
        <div className="space-y-3">
          <div><label className={label} htmlFor="ge">{t('set.greet.en')}</label><input id="ge" className={input} value={f.greeting_en} onChange={set('greeting_en')} maxLength={300} /></div>
          <div><label className={label} htmlFor="gs">{t('set.greet.es')}</label><input id="gs" className={input} value={f.greeting_es} onChange={set('greeting_es')} maxLength={300} /></div>
          <div><label className={label} htmlFor="st">{t('set.tone')}</label><textarea id="st" className={input} rows={4} value={f.tone} onChange={set('tone')} placeholder={t('set.tone.ph')} maxLength={600} /></div>
          <p className="text-sm text-slate-500">{t('set.plan')}: <b className="capitalize">{f.plan}</b> · {t('set.usage')}: <b>{f.answers_this_month}</b> / {f.msg_limit.toLocaleString()}</p>
        </div>
      </Card>
      <div className="lg:col-span-2 flex items-center gap-3"><button className={btn.primary}>{t('common.save')}</button><div className="flex-1"><Err msg={err} /></div></div>
    </form>
  );
};
export default Settings;
