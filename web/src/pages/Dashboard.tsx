import React, { useEffect, useState } from 'react';
import { call, User } from '../api';
import { APP_NAME, useT } from '../i18n';
import Overview from './dash/Overview';
import Chats from './dash/Chats';
import Knowledge from './dash/Knowledge';
import Answers from './dash/Answers';
import Settings from './dash/Settings';
import Install from './dash/Install';
import Team from './dash/Team';
import Account from './dash/Account';

const OWNER_TABS = ['overview', 'chats', 'knowledge', 'answers', 'settings', 'install', 'team', 'account'] as const;
const AGENT_TABS = ['overview', 'chats', 'account'] as const;
type Tab = typeof OWNER_TABS[number];

const Dashboard: React.FC<{ user: User; route: string; onSignOut: () => void }> = ({ user, route, onSignOut }) => {
  const { t, lang, setLang } = useT();
  const tabs: readonly Tab[] = user.role === 'owner' ? OWNER_TABS : AGENT_TABS;
  const parts = route.split('/');   // /app/<tab>/<id?>
  const tab = (tabs as readonly string[]).includes(parts[2]) ? parts[2] as Tab : 'overview';
  const [waiting, setWaiting] = useState(0);
  useEffect(() => {   // badge on Chats: people waiting for a person
    const f = () => { if (document.visibilityState === 'visible') call<{ counts: { handoff: number } }>('chats.php?filter=handoff').then(r => setWaiting(r.counts.handoff)).catch(() => {}); };
    f(); const id = setInterval(f, 20000); return () => clearInterval(id);
  }, []);

  return (
    <div className="min-h-screen">
      <header className="border-b border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-950/80 backdrop-blur sticky top-0 z-40">
        <div className="max-w-7xl mx-auto px-4 py-3 flex items-center gap-3 flex-wrap">
          <a href="#/" className="font-black">💬 {APP_NAME}</a>
          <span className="text-sm text-slate-500 truncate mr-auto">{user.company}</span>
          <button className="text-sm font-semibold hover:underline" onClick={() => setLang(lang === 'en' ? 'es' : 'en')}>{t('lang.toggle')}</button>
          {!!user.is_platform_admin && <a href="#/owner" className="text-sm font-semibold hover:underline">{t('nav.owner')}</a>}
          <button className="text-sm font-semibold hover:underline" onClick={onSignOut}>{t('nav.signout')}</button>
        </div>
        <nav className="max-w-7xl mx-auto px-4 flex gap-1 overflow-x-auto pb-2" aria-label="Dashboard">
          {tabs.map(k => (
            <a key={k} href={`#/app/${k}`} className={`whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-bold ${tab === k ? 'bg-indigo-600 text-white' : 'hover:bg-slate-100 dark:hover:bg-slate-800'}`}>
              {t(`tab.${k}` as 'tab.overview')}{k === 'chats' && waiting > 0 && <span className="ml-1 inline-flex min-w-5 h-5 px-1 rounded-full bg-red-600 text-white text-xs items-center justify-center">{waiting}</span>}
            </a>))}
        </nav>
      </header>
      <main className="max-w-7xl mx-auto px-4 py-6">
        {tab === 'overview' && <Overview user={user} />}
        {tab === 'chats' && <Chats user={user} openId={Number(parts[3]) || 0} />}
        {tab === 'knowledge' && <Knowledge />}
        {tab === 'answers' && <Answers />}
        {tab === 'settings' && <Settings />}
        {tab === 'install' && <Install />}
        {tab === 'team' && <Team user={user} />}
        {tab === 'account' && <Account user={user} />}
      </main>
    </div>
  );
};
export default Dashboard;
