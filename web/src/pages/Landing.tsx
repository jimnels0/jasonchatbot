import React, { useEffect } from 'react';
import { useT } from '../i18n';
import { btn, card } from '../ui';
import { loadWidget, removeWidget } from '../widgetLoader';

// Marketing page. The chat bubble here is the real product, answering about a demo company (gate.php returns its key).
const PLANS = [
  { name: 'Starter', price: 39, answers: '1,000', pages: '100', seats: '2' },
  { name: 'Growth', price: 99, answers: '5,000', pages: '500', seats: '5', hot: true },
  { name: 'Pro', price: 249, answers: '20,000', pages: '2,000', seats: '15' },
];

const Landing: React.FC<{ demoKey: string }> = ({ demoKey }) => {
  const { t, lang } = useT();
  useEffect(() => { loadWidget(demoKey, lang); return removeWidget; }, [demoKey, lang]);
  const feats = ['1', '2', '3', '4', '5', '6'] as const;
  return (
    <main className="max-w-6xl mx-auto px-4 pb-24">
      <section className="py-14 sm:py-20 text-center max-w-3xl mx-auto">
        <h1 className="text-4xl sm:text-5xl font-black tracking-tight leading-tight">{t('hero.title')}</h1>
        <p className="mt-5 text-lg text-slate-600 dark:text-slate-300">{t('hero.sub')}</p>
        <div className="mt-8 flex flex-wrap gap-3 justify-center">
          <a href="#/signup" className={`${btn.primary} text-base px-6 py-3`}>{t('hero.cta')}</a>
          {demoKey && <span className="self-center text-sm text-slate-500">↘ {t('hero.demo')}</span>}
        </div>
      </section>

      <section>
        <h2 className="text-2xl font-black mb-5 text-center">{t('feat.title')}</h2>
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {feats.map(n => (
            <div key={n} className={`${card} p-5`}>
              <h3 className="font-bold mb-1">{t(`feat.${n}.t` as 'feat.1.t')}</h3>
              <p className="text-sm text-slate-600 dark:text-slate-300">{t(`feat.${n}.b` as 'feat.1.b')}</p>
            </div>))}
        </div>
      </section>

      <section className="mt-16">
        <h2 className="text-2xl font-black mb-5 text-center">{t('how.title')}</h2>
        <ol className="grid sm:grid-cols-3 gap-4">
          {(['how.1', 'how.2', 'how.3'] as const).map((k, i) => (
            <li key={k} className={`${card} p-5`}><span className="inline-flex w-8 h-8 rounded-full bg-indigo-600 text-white font-black items-center justify-center mb-2">{i + 1}</span><p className="text-sm">{t(k)}</p></li>))}
        </ol>
      </section>

      <section className="mt-16">
        <h2 className="text-2xl font-black text-center">{t('price.title')}</h2>
        <p className="text-center text-sm text-slate-500 mb-5">{t('price.note')}</p>
        <div className="grid sm:grid-cols-3 gap-4">
          {PLANS.map(p => (
            <div key={p.name} className={`${card} p-6 ${p.hot ? 'ring-2 ring-indigo-500' : ''}`}>
              <h3 className="font-bold">{p.name}</h3>
              <p className="my-3"><span className="text-4xl font-black">${p.price}</span><span className="text-slate-500">{t('price.month')}</span></p>
              <ul className="text-sm space-y-1 text-slate-600 dark:text-slate-300">
                <li>✓ {p.answers} {t('price.answers')}</li><li>✓ {p.pages} {t('price.pages')}</li><li>✓ {p.seats} {t('price.seats')}</li>
              </ul>
              <a href="#/signup" className={`${p.hot ? btn.primary : btn.ghost} w-full mt-5`}>{t('hero.cta')}</a>
            </div>))}
        </div>
      </section>

      <footer className="mt-16 pt-6 border-t border-slate-200 dark:border-slate-800 text-sm text-slate-500 flex gap-4 justify-center">
        <a href="#/privacy" className="hover:underline">{t('footer.privacy')}</a><a href="#/terms" className="hover:underline">{t('footer.terms')}</a>
      </footer>
    </main>
  );
};
export default Landing;
