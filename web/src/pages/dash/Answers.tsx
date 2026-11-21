import React, { useCallback, useEffect, useState } from 'react';
import { call } from '../../api';
import { useT } from '../../i18n';
import { btn, Card, Err, input, label, useDialog } from '../../ui';

type QA = { id: number; question: string; answer: string };

// Approved answers: the company's own wording, used first by the bot.
const Answers: React.FC = () => {
  const { t } = useT();
  const { confirm, toast } = useDialog();
  const [rows, setRows] = useState<QA[] | null>(null);
  const [edit, setEdit] = useState<QA>(() => {
    let q = ''; try { q = sessionStorage.getItem('jcb_prefill_q') || ''; sessionStorage.removeItem('jcb_prefill_q'); } catch { /* blocked */ }
    return { id: 0, question: q, answer: '' };
  });
  const [err, setErr] = useState('');
  const load = useCallback(() => call<{ rows: QA[] }>('qa.php').then(r => setRows(r.rows)).catch(e => setErr(e.message)), []);
  useEffect(() => { load(); }, [load]);

  const save = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('');
    try {
      await call(edit.id ? `qa.php?id=${edit.id}` : 'qa.php', edit.id ? 'PUT' : 'POST', { question: edit.question, answer: edit.answer });
      setEdit({ id: 0, question: '', answer: '' }); toast(t('common.saved')); load();
    } catch (x) { setErr((x as Error).message); }
  };
  const del = async (r: QA) => {
    if (!await confirm({ title: t('common.delete'), body: `“${r.question}”\n\n${t('qa.delete.q')}`, danger: true, okText: t('common.delete') })) return;
    try { await call(`qa.php?id=${r.id}`, 'DELETE'); load(); } catch (x) { setErr((x as Error).message); }
  };

  return (
    <div className="grid lg:grid-cols-2 gap-4 items-start">
      <Card title={t('qa.title')}>
        <p className="text-sm text-slate-500 mb-3">{t('qa.hint')}</p>
        <form onSubmit={save} className="space-y-3">
          <div><label className={label} htmlFor="qq">{t('qa.q')}</label><input id="qq" className={input} value={edit.question} onChange={e => setEdit({ ...edit, question: e.target.value })} required maxLength={500} /></div>
          <div><label className={label} htmlFor="qaa">{t('qa.a')}</label><textarea id="qaa" className={input} rows={5} value={edit.answer} onChange={e => setEdit({ ...edit, answer: e.target.value })} required maxLength={4000} /></div>
          <Err msg={err} />
          <div className="flex gap-2"><button className={btn.primary}>{edit.id ? t('common.save') : t('common.add')}</button>
            {edit.id > 0 && <button type="button" className={btn.ghost} onClick={() => setEdit({ id: 0, question: '', answer: '' })}>{t('common.cancel')}</button>}</div>
        </form>
      </Card>
      <Card title={`${t('qa.title')}${rows ? ` (${rows.length})` : ''}`}>
        {!rows ? <p className="text-slate-400 text-sm">{t('common.loading')}</p> : rows.length === 0 ? <p className="text-slate-400 text-sm">{t('common.none')}</p> : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map(r => (
            <li key={r.id} className="py-2.5">
              <p className="font-semibold text-sm">{r.question}</p>
              <p className="text-sm text-slate-600 dark:text-slate-300 whitespace-pre-wrap">{r.answer}</p>
              <div className="flex gap-1 mt-1"><button className={btn.small} onClick={() => setEdit(r)}>{t('common.edit')}</button>
                <button className={`${btn.small} text-red-600 border-red-300`} onClick={() => del(r)}>{t('common.delete')}</button></div>
            </li>))}</ul>)}
      </Card>
    </div>
  );
};
export default Answers;
