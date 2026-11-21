import React, { useState } from 'react';
import { useT } from './i18n';

// "Suggest a strong password" (James's rule for every screen where someone chooses a password). Copied from jasonpenny:
// 16 random characters made in the browser (crypto.getRandomValues), no look-alikes, at least one lower/upper/digit/symbol.
// Never sent anywhere until the form is submitted.
const LOWER = 'abcdefghijkmnpqrstuvwxyz', UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ', DIGITS = '23456789', SYMBOLS = '!@#$%&*?';
const ALL = LOWER + UPPER + DIGITS + SYMBOLS;
const pick = (chars: string): string => {
  const buf = new Uint32Array(1);
  const limit = Math.floor(0x100000000 / chars.length) * chars.length;   // avoid modulo bias
  do { crypto.getRandomValues(buf); } while (buf[0] >= limit);
  return chars[buf[0] % chars.length];
};
export const suggestPassword = (len = 16): string => {
  const out = [pick(LOWER), pick(UPPER), pick(DIGITS), pick(SYMBOLS)];
  while (out.length < len) out.push(pick(ALL));
  for (let i = out.length - 1; i > 0; i--) {
    const j = Math.floor((crypto.getRandomValues(new Uint32Array(1))[0] / 0x100000000) * (i + 1));
    [out[i], out[j]] = [out[j], out[i]];
  }
  return out.join('');
};

const PasswordSuggest: React.FC<{ onPick: (pw: string) => void }> = ({ onPick }) => {
  const { t } = useT();
  const [pw, setPw] = useState('');
  const [copied, setCopied] = useState(false);
  const make = () => { const p = suggestPassword(); setPw(p); setCopied(false); onPick(p); };
  return (
    <div className="space-y-2">
      <button type="button" onClick={make} className="text-sm font-bold text-indigo-600 dark:text-indigo-400 hover:underline">{pw ? t('pw.another') : t('pw.suggest')}</button>
      {pw && (
        <div className="rounded-xl border border-green-300 dark:border-green-800 bg-green-50 dark:bg-green-950 px-3 py-2">
          <p className="text-[11px] font-semibold text-green-800 dark:text-green-300 mb-1">{t('pw.filled')}</p>
          <div className="flex items-center gap-2">
            <code className="flex-1 min-w-0 break-all font-mono text-sm select-all">{pw}</code>
            <button type="button" className="shrink-0 text-xs font-bold px-3 py-1 rounded-lg border border-green-400 dark:border-green-700"
              onClick={async () => { try { await navigator.clipboard.writeText(pw); setCopied(true); setTimeout(() => setCopied(false), 2000); } catch { /* blocked */ } }}>
              {copied ? t('pw.copied') : t('pw.copy')}
            </button>
          </div>
        </div>
      )}
    </div>
  );
};
export default PasswordSuggest;
