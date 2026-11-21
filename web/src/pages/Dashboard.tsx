import React, { useEffect, useRef, useState } from 'react';
import { call, User } from '../api';
import { APP_NAME, useT } from '../i18n';
import Overview from './dash/Overview';
import Live, { LiveData } from './dash/Live';
import Chats from './dash/Chats';
import Knowledge from './dash/Knowledge';
import Answers from './dash/Answers';
import Settings from './dash/Settings';
import Install from './dash/Install';
import Team from './dash/Team';
import Account from './dash/Account';

const OWNER_TABS = ['overview', 'live', 'chats', 'knowledge', 'answers', 'settings', 'install', 'team', 'account'] as const;
const AGENT_TABS = ['overview', 'live', 'chats', 'account'] as const;

/** One shared audio context. Browsers keep it muted until the first click/key on the page, so we unlock it then. */
let audioCtx: AudioContext | null = null;
function getCtx(): AudioContext | null {
  try {
    if (!audioCtx) {
      const A = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
      audioCtx = new A();
    }
    return audioCtx;
  } catch { return null; }
}
function unlockAudio() { const c = getCtx(); if (c && c.state === 'suspended') c.resume().catch(() => {}); }
/** Phone-style ring made in the browser (no sound file): three two-tone pulses, about 1.5 s. */
function ring() {
  const ctx = getCtx(); if (!ctx) return;
  if (ctx.state === 'suspended') ctx.resume().catch(() => {});
  for (let p = 0; p < 3; p++) {
    [880, 1320].forEach((f, i) => {
      const o = ctx.createOscillator(); const g = ctx.createGain();
      o.type = 'triangle'; o.frequency.value = f; o.connect(g); g.connect(ctx.destination);
      const t0 = ctx.currentTime + p * 0.5 + i * 0.18;
      g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.45, t0 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.16);
      o.start(t0); o.stop(t0 + 0.17);
    });
  }
}
const SOUND_KEY = 'jcb_sound';
const RING_REPEAT_MS = 30000;   // ring again every 30 s while someone is still waiting
function readSound(): boolean { try { return localStorage.getItem(SOUND_KEY) !== 'off'; } catch { return true; } }
type Tab = typeof OWNER_TABS[number];

const Dashboard: React.FC<{ user: User; route: string; onSignOut: () => void }> = ({ user, route, onSignOut }) => {
  const { t, lang, setLang } = useT();
  const tabs: readonly Tab[] = user.role === 'owner' ? OWNER_TABS : AGENT_TABS;
  const parts = route.split('/');   // /app/<tab>/<id?>
  const tab = (tabs as readonly string[]).includes(parts[2]) ? parts[2] as Tab : 'overview';
  const [waiting, setWaiting] = useState(0);
  const [available, setAvailable] = useState(true);
  const [perm, setPerm] = useState(typeof Notification === 'undefined' ? 'denied' : Notification.permission);
  const [sound, setSound] = useState(readSound);
  const [locked, setLocked] = useState(() => getCtx()?.state === 'suspended');
  const soundRef = useRef(sound); soundRef.current = sound;
  const seen = useRef<Set<number> | null>(null);
  const lastRing = useRef(0);
  // The first click or key anywhere on the page unlocks sound (browser rule).
  useEffect(() => {
    const u = () => { unlockAudio(); setTimeout(() => setLocked(getCtx()?.state === 'suspended'), 50); };
    window.addEventListener('pointerdown', u); window.addEventListener('keydown', u);
    return () => { window.removeEventListener('pointerdown', u); window.removeEventListener('keydown', u); };
  }, []);
  // Heartbeat every 10 s, even in a background tab: keeps me "online" for live chat and rings when a visitor asks for a person.
  useEffect(() => {
    const f = () => call<LiveData>('live.php').then(r => {
      setWaiting(r.waiting.length); setAvailable(r.available);
      const ids = new Set(r.waiting.map(w => w.id));
      const fresh = seen.current ? r.waiting.filter(w => !seen.current!.has(w.id)) : [];
      seen.current = ids;
      const now = Date.now();
      // Ring for a new request, and again every 30 s while anyone is still waiting for a person.
      if (r.available && soundRef.current && (fresh.length || (r.waiting.length && now - lastRing.current >= RING_REPEAT_MS))) {
        ring(); lastRing.current = now;
      }
      if (fresh.length && r.available) {
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
          fresh.forEach(w => { const n = new Notification(t('alert.title'), { body: `${w.name} ${t('alert.body')}`, tag: `jcb-${w.id}` });
            n.onclick = () => { window.focus(); location.hash = `/app/chats/${w.id}`; n.close(); }; });
        }
      }
      document.title = (r.waiting.length ? `(${r.waiting.length}) ` : '') + APP_NAME;
    }).catch(() => {});
    f(); const id = setInterval(f, 10000); return () => clearInterval(id);
  }, [t]);
  const toggleAvailable = () => call<{ available: boolean }>('live.php', 'POST', { available: !available }).then(r => setAvailable(r.available)).catch(() => {});
  const askAlerts = () => { if (typeof Notification !== 'undefined') Notification.requestPermission().then(setPerm); unlockAudio(); };
  const toggleSound = () => {
    const on = !sound; setSound(on); unlockAudio(); setLocked(false);
    try { localStorage.setItem(SOUND_KEY, on ? 'on' : 'off'); } catch { /* private window */ }
    if (on) ring();   // a sample, so you know the volume
  };

  return (
    <div className="min-h-screen">
      <header className="border-b border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-950/80 backdrop-blur sticky top-0 z-40">
        <div className="max-w-7xl mx-auto px-4 py-3 flex items-center gap-3 flex-wrap">
          <a href="#/" className="font-black">💬 {APP_NAME}</a>
          <span className="text-sm text-slate-500 truncate mr-auto">{user.company}</span>
          <button onClick={toggleAvailable} aria-pressed={available} className={`text-xs font-bold rounded-full px-2.5 py-1 border ${available ? 'border-emerald-400 text-emerald-700 dark:text-emerald-300' : 'border-slate-300 text-slate-500'}`}>
            <span className={`inline-block w-2 h-2 rounded-full mr-1.5 ${available ? 'bg-emerald-500' : 'bg-slate-400'}`} />{available ? t('avail.on') : t('avail.off')}</button>
          <button onClick={toggleSound} aria-pressed={sound} title={t('sound.test')} className={`text-xs font-bold rounded-full px-2.5 py-1 border ${sound && !locked ? 'border-indigo-400 text-indigo-700 dark:text-indigo-300' : 'border-amber-400 text-amber-700 dark:text-amber-300'}`}>
            {!sound ? `🔕 ${t('sound.off')}` : locked ? `🔔 ${t('sound.unlock')}` : `🔔 ${t('sound.on')}`}</button>
          {perm === 'default' && <button className="text-xs font-semibold underline" onClick={askAlerts}>{t('alerts.on')}</button>}
          <button className="text-sm font-semibold hover:underline" onClick={() => setLang(lang === 'en' ? 'es' : 'en')}>{t('lang.toggle')}</button>
          {!!user.is_platform_admin && <a href="#/owner" className="text-sm font-semibold hover:underline">{t('nav.owner')}</a>}
          <button className="text-sm font-semibold hover:underline" onClick={onSignOut}>{t('nav.signout')}</button>
        </div>
        <nav className="max-w-7xl mx-auto px-4 flex gap-1 overflow-x-auto pb-2" aria-label="Dashboard">
          {tabs.map(k => (
            <a key={k} href={`#/app/${k}`} className={`whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-bold ${tab === k ? 'bg-indigo-600 text-white' : 'hover:bg-slate-100 dark:hover:bg-slate-800'}`}>
              {t(`tab.${k}` as 'tab.overview')}{(k === 'chats' || k === 'live') && waiting > 0 && <span className="ml-1 inline-flex min-w-5 h-5 px-1 rounded-full bg-red-600 text-white text-xs items-center justify-center">{waiting}</span>}
            </a>))}
        </nav>
      </header>
      <main className="max-w-7xl mx-auto px-4 py-6">
        {tab === 'overview' && <Overview user={user} />}
        {tab === 'live' && <Live />}
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
