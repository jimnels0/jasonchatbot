// Put the real chat widget on this page (landing demo, Install > Test). Same one line a customer pastes.
export const loadWidget = (key: string, lang: string) => {
  if (!key) return;
  document.getElementById('jcb-widget-script')?.remove();
  document.getElementById('jcb-root')?.remove();
  (window as unknown as { __jcbLoaded?: boolean }).__jcbLoaded = false;
  const s = document.createElement('script');
  s.id = 'jcb-widget-script';
  s.src = `/widget.js?v=${Date.now()}`;
  s.async = true;
  s.dataset.key = key;
  s.dataset.lang = lang;
  document.body.appendChild(s);
};
export const removeWidget = () => {
  document.getElementById('jcb-widget-script')?.remove();
  document.getElementById('jcb-root')?.remove();
  (window as unknown as { __jcbLoaded?: boolean }).__jcbLoaded = false;
};
