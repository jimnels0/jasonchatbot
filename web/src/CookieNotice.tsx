import React, { useState } from 'react';
import { useT } from './i18n';
import { btn, card } from './ui';

// First-visit cookie notice (James's rule for every public site). Essential storage only; remembered in localStorage.
const KEY = 'jcb_cookie_notice_ok';
const CookieNotice: React.FC = () => {
  const { t } = useT();
  const [show, setShow] = useState(() => { try { return localStorage.getItem(KEY) !== '1'; } catch { return true; } });
  if (!show) return null;
  return (
    <div className={`${card} fixed bottom-4 left-4 right-4 sm:right-auto sm:max-w-sm z-[90] p-4 shadow-xl text-sm`} role="region" aria-label="Cookies">
      <p>{t('cookie.text')} <a href="#/privacy" className="underline">{t('cookie.more')}</a></p>
      <div className="mt-3 text-right">
        <button className={btn.primary} onClick={() => { try { localStorage.setItem(KEY, '1'); } catch { /* blocked */ } setShow(false); }}>{t('cookie.ok')}</button>
      </div>
    </div>
  );
};
export default CookieNotice;
