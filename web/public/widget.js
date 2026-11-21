/* Chat widget. A company pastes one line on its website:
 *   <script src="https://bot.pennybiddeals.com/widget.js" data-key="COMPANY_KEY" async></script>
 * Optional: data-lang="en|es" (default: the visitor's browser). Plain JS, no libraries, no cookies. The open chat is kept in
 * this browser tab only (sessionStorage). Everything is drawn inside a Shadow DOM so the host site's CSS can't break it. */
(function () {
  var me = document.currentScript;
  if (!me || window.__jcbLoaded) return;
  window.__jcbLoaded = true;
  var KEY = me.dataset.key || '';
  var API = new URL('/api/widget.php', me.src).href;
  var lang = (me.dataset.lang || navigator.language || 'en').slice(0, 2).toLowerCase() === 'es' ? 'es' : 'en';
  var S = {
    en: { chat: 'Chat with us', name: 'Your name', email: 'Email (optional)', dept: 'What is this about?', start: 'Start chat', ask: 'Type your message…',
      send: 'Send', typing: 'Typing…', human: 'Talk to a person', newChat: 'New chat', close: 'Close', err: 'Something went wrong. Please try again.',
      saved: 'This chat is saved so our team can help you.', sources: 'Source', waiting: 'A person will reply here.', hi: 'Hi ', help: '! How can we help?',
      powered: 'AI assistant. Answers can be wrong; ask for a person anytime.' },
    es: { chat: 'Chatee con nosotros', name: 'Su nombre', email: 'Correo (opcional)', dept: '¿Sobre qué es?', start: 'Iniciar chat', ask: 'Escriba su mensaje…',
      send: 'Enviar', typing: 'Escribiendo…', human: 'Hablar con una persona', newChat: 'Nuevo chat', close: 'Cerrar', err: 'Algo salió mal. Inténtelo de nuevo.',
      saved: 'Guardamos este chat para que nuestro equipo pueda ayudarle.', sources: 'Fuente', waiting: 'Una persona le responderá aquí.', hi: '¡Hola ', help: '! ¿En qué le podemos ayudar?',
      powered: 'Asistente de IA. Las respuestas pueden tener errores; pida una persona cuando quiera.' }
  }[lang];
  var STORE = 'jcb_chat_' + KEY;
  var get = function () { try { return JSON.parse(sessionStorage.getItem(STORE) || 'null'); } catch (e) { return null; } };
  var put = function (v) { try { v ? sessionStorage.setItem(STORE, JSON.stringify(v)) : sessionStorage.removeItem(STORE); } catch (e) {} };
  var chat = get();   // {conv, token, name, last, log:[{role, body, sources}]}
  var cfg = null, pollTimer = null;

  function req(method, q, body) {
    return fetch(API + '?' + q + '&lang=' + lang, { method: method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var e = new Error(j.message || S.err); e.code = j.code; throw e; } return j; }); });
  }
  function titleCase(s) {
    s = s.replace(/\s+/g, ' ').trim();
    if (s === s.toLowerCase() || s === s.toUpperCase()) s = s.toLowerCase();
    return s.replace(/(^|[\s\-\/(])(\S)/g, function (m, a, b) { return a + b.toUpperCase(); });
  }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  req('GET', 'a=config&k=' + encodeURIComponent(KEY)).then(function (c) { cfg = c; draw(); })
    .catch(function (e) { if (window.console) console.warn('[chat widget] ' + e.message); });   // e.g. this site is not on the allowed list

  function draw() {
    var host = el('div'); host.id = 'jcb-root';
    host.style.cssText = 'position:fixed;right:0;bottom:0;z-index:2147483000';
    document.body.appendChild(host);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var c = cfg.color || '#4f46e5';
    var css = el('style');
    css.textContent = ':host,*{box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
      + '.b{position:fixed;right:20px;bottom:20px;width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;background:' + c + ';color:#fff;font-size:28px;box-shadow:0 6px 20px rgba(0,0,0,.25)}'
      + '.p{position:fixed;right:20px;bottom:92px;width:360px;max-width:calc(100vw - 24px);height:540px;max-height:calc(100vh - 112px);display:none;flex-direction:column;background:#fff;color:#111827;border-radius:16px;overflow:hidden;box-shadow:0 14px 44px rgba(0,0,0,.3);font-size:14px;line-height:1.45}'
      + '@media (max-width:480px){.p{right:12px;bottom:84px}.b{right:12px;bottom:12px}}'
      + '.h{background:' + c + ';color:#fff;padding:12px 14px;display:flex;align-items:center;gap:8px}.h b{flex:1;font-size:15px}'
      + '.x{background:transparent;border:1px solid rgba(255,255,255,.55);color:#fff;border-radius:8px;padding:2px 8px;cursor:pointer;font-size:12px}.xx{border:0;font-size:22px;line-height:1;padding:0 4px}'
      + '.m{flex:1;overflow-y:auto;padding:12px;background:#f6f7f9}'
      + '.q{max-width:85%;margin:0 0 8px;padding:8px 11px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word}'
      + '.u{margin-left:auto;background:' + c + ';color:#fff}.a{background:#fff;border:1px solid #e5e7eb}.g{background:#ecfdf5;border:1px solid #a7f3d0}'
      + '.s{font-size:11px;color:#6b7280;margin:-4px 0 8px}.s a{color:#4b5563}.n{text-align:center;font-size:12px;color:#6b7280;margin:6px 0 10px}'
      + '.f{display:flex;gap:6px;padding:10px;border-top:1px solid #e5e7eb;background:#fff}'
      + 'input,select,textarea{border:1px solid #d1d5db;border-radius:10px;padding:9px;font-size:14px;width:100%;background:#fff;color:#111827}'
      + 'button.k{border:0;border-radius:10px;padding:9px 13px;font-weight:700;cursor:pointer;color:#fff;background:' + c + '}button:disabled{opacity:.5}'
      + '.i{display:flex;flex-direction:column;gap:8px}.e{color:#b91c1c;font-size:13px;margin:0}.t{font-size:11px;color:#9ca3af;text-align:center;padding:0 10px 8px;background:#fff}'
      + '.hm{background:transparent;border:0;color:' + c + ';font-weight:700;cursor:pointer;font-size:12px;padding:4px 0}';
    root.appendChild(css);
    var bubble = el('button', 'b', '💬'); bubble.setAttribute('aria-label', S.chat);
    var panel = el('div', 'p'); panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', cfg.company);
    var head = el('div', 'h'); var title = el('b', '', cfg.company);
    var newBtn = el('button', 'x', S.newChat); var closeBtn = el('button', 'x xx', '×'); closeBtn.setAttribute('aria-label', S.close);
    head.append(title, newBtn, closeBtn);
    var log = el('div', 'm'); log.setAttribute('aria-live', 'polite');
    var form = el('form', 'f'); var box = el('input'); box.placeholder = S.ask; box.maxLength = 1000; box.setAttribute('aria-label', S.ask);
    var send = el('button', 'k', S.send); form.append(box, send);
    var foot = el('div', 't'); var humanBtn = el('button', 'hm', S.human); foot.append(humanBtn, el('div', '', S.powered));
    panel.append(head, log, form, foot); root.append(bubble, panel);

    function line(role, text, sources) {
      if (role === 'system') { var n = el('p', 'n', text); log.append(n); log.scrollTop = log.scrollHeight; return n; }
      var b = el('div', 'q ' + (role === 'user' ? 'u' : role === 'agent' ? 'g' : 'a'), text);
      log.append(b);
      if (sources && sources.length) {
        var s = el('div', 's', S.sources + ': ');
        sources.forEach(function (x, i) { var a = el('a', '', x.title || x.url); a.href = x.url; a.target = '_blank'; a.rel = 'noopener noreferrer'; if (i) s.append(' · '); s.append(a); });
        log.append(s);
      }
      log.scrollTop = log.scrollHeight;
      return b;
    }
    function remember(role, body, sources, id) { chat.log.push({ role: role, body: body, sources: sources || [] }); if (id) chat.last = Math.max(chat.last || 0, id); put(chat); }

    function intro() {
      stopPoll(); log.textContent = ''; form.style.display = 'none'; foot.style.display = 'none'; newBtn.style.display = 'none';
      var f = el('form', 'i');
      f.append(el('p', '', cfg.greeting || S.chat));
      var nm = el('input'); nm.placeholder = S.name; nm.required = true; nm.maxLength = 80; nm.autocomplete = 'name'; nm.setAttribute('aria-label', S.name);
      nm.addEventListener('blur', function () { nm.value = titleCase(nm.value); });
      f.append(nm);
      var em = null;
      if (cfg.ask_email) { em = el('input'); em.type = 'email'; em.placeholder = S.email; em.maxLength = 190; em.autocomplete = 'email'; em.setAttribute('aria-label', S.email); f.append(em); }
      var dp = null;
      if (cfg.departments && cfg.departments.length > 1) {
        dp = el('select'); dp.setAttribute('aria-label', S.dept);
        cfg.departments.forEach(function (d) { var o = el('option', '', d); o.value = d; dp.append(o); });
        var lb = el('label', '', S.dept); lb.style.cssText = 'font-size:12px;color:#6b7280;margin-bottom:-4px'; f.append(lb, dp);
      }
      var go = el('button', 'k', S.start); var er = el('p', 'e'); f.append(go, el('p', 's', S.saved), er);
      f.addEventListener('submit', function (e) {
        e.preventDefault(); nm.value = titleCase(nm.value); go.disabled = true; er.textContent = '';
        req('POST', 'a=start&k=' + encodeURIComponent(KEY), { name: nm.value, email: em ? em.value : '', department: dp ? dp.value : (cfg.departments[0] || ''), page: location.href })
          .then(function (j) { chat = { conv: j.conv, token: j.token, name: j.name, last: 0, log: [] }; put(chat); talk(); })
          .catch(function (x) { er.textContent = x.message; go.disabled = false; });
      });
      log.append(f); nm.focus();
    }

    function talk() {
      log.textContent = ''; form.style.display = 'flex'; foot.style.display = 'block'; newBtn.style.display = '';
      line('bot', S.hi + chat.name + S.help);
      chat.log.forEach(function (m) { line(m.role, m.body, m.sources); });
      box.focus(); startPoll();
    }

    // A person may answer from the dashboard at any time: check for new messages while the panel is open.
    function poll() {
      if (!chat || document.visibilityState !== 'visible') return;
      req('GET', 'a=poll&c=' + chat.conv + '&t=' + chat.token + '&after=' + (chat.last || 0)).then(function (j) {
        j.messages.forEach(function (m) {
          if (m.id <= (chat.last || 0)) return;
          var role = m.role === 'agent' ? 'agent' : m.role === 'system' ? 'system' : 'bot';
          line(role, m.body, m.sources); remember(role, m.body, m.sources, m.id);
        });
      }).catch(function (x) { if (x.code === 'chat_ended') { put(null); chat = null; intro(); } });
    }
    function startPoll() { stopPoll(); pollTimer = setInterval(poll, 4000); }
    function stopPoll() { if (pollTimer) clearInterval(pollTimer); pollTimer = null; }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = box.value.trim(); if (!q || send.disabled) return;
      box.value = ''; send.disabled = true;
      line('user', q); remember('user', q);
      var wait = line('bot', S.typing);
      req('POST', 'a=say&c=' + chat.conv + '&t=' + chat.token, { text: q }).then(function (j) {
        if (j.reply === null) { wait.remove(); if (!chat.toldWaiting) { line('system', S.waiting); chat.toldWaiting = true; put(chat); } return; }
        wait.remove(); line('bot', j.reply, j.sources); remember('bot', j.reply, j.sources, j.id);
      }).catch(function (x) {
        wait.textContent = x.message;
        if (x.code === 'chat_ended') { put(null); chat = null; setTimeout(intro, 1500); }
      }).then(function () { send.disabled = false; box.focus(); });
    });
    humanBtn.addEventListener('click', function () {
      if (!chat) return;
      humanBtn.disabled = true;
      req('POST', 'a=human&c=' + chat.conv + '&t=' + chat.token).then(function (j) { line('system', j.reply); remember('system', j.reply, null, j.id); })
        .catch(function (x) { line('system', x.message); }).then(function () { humanBtn.disabled = false; });
    });
    newBtn.addEventListener('click', function () { put(null); chat = null; intro(); });
    closeBtn.addEventListener('click', function () { panel.style.display = 'none'; stopPoll(); bubble.focus(); });
    bubble.addEventListener('click', function () {
      var open = panel.style.display === 'flex';
      panel.style.display = open ? 'none' : 'flex';
      if (open) stopPoll(); else if (chat) talk(); else intro();
    });
  }
})();
