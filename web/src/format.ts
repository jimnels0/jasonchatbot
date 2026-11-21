// Browser twin of api/lib/format.php (copied from jasonpenny services/format.ts): names, companies and departments
// get Title Case on blur; capitals the person typed survive (McDonald, LLC); ALL CAPS or all lowercase becomes Title Case.
export const titleCase = (s: string): string => {
  let v = s.replace(/\s+/g, ' ').trim();
  if (!v) return v;
  if (/\p{L}/u.test(v) && (v === v.toUpperCase() || v === v.toLowerCase())) v = v.toLowerCase();
  return v.replace(/(^|[\s\-/(])(\p{L})/gu, (_m, a: string, b: string) => a + b.toUpperCase());
};

export const titleCaseList = (s: string): string => s.split(',').map(x => titleCase(x)).filter(Boolean).join(', ');

/** "2026-11-20 19:04:00" (UTC from the API) -> local date + time. */
export const when = (utc: string | null | undefined, lang: string): string => {
  if (!utc) return '—';
  const d = new Date(utc.replace(' ', 'T') + 'Z');
  return isNaN(d.getTime()) ? utc : d.toLocaleString(lang === 'es' ? 'es-US' : 'en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};
