<?php
// api/widget.php -- the public chat widget (web/public/widget.js) talks only to this file. Runs on customers' websites.
//   GET  ?a=config&k=KEY                     -> look + departments
//   POST ?a=start&k=KEY  {name,email,department,page}   -> {conv, token}
//   POST ?a=say&c=ID&t=TOKEN  {text}                    -> bot answer (or waiting for a person)
//   GET  ?a=poll&c=ID&t=TOKEN&after=MSGID               -> new bot/agent messages + mode
//   POST ?a=human&c=ID&t=TOKEN                          -> visitor asks for a person (live if someone is online, else a message)
//   POST ?a=email&c=ID&t=TOKEN {email, send}            -> save the visitor's email; send=1 also emails them this chat
//   POST ?a=upload&c=ID&t=TOKEN  multipart file=...     -> visitor sends a file (picture, PDF, Word, Excel, text; 10 MB)
//   POST ?a=end&c=ID&t=TOKEN                            -> visitor closed the chat (X or New chat): closed, transcript emailed
//   POST ?a=history&k=KEY {v, exclude}                  -> this browser's earlier chats with the company (last 3, 90 days)
// A closed chat (idle 30 min, ended by the visitor or by the team) refuses say/human/upload with 409 chat_closed and the
// widget starts a new chat. poll still answers, so a late team reply still shows up.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/format.php';
require __DIR__ . '/lib/answer.php';
require __DIR__ . '/lib/files.php';
api_headers('GET, POST, OPTIONS');

const W_NEW_CHATS_PER_IP_HOUR = 20;
const W_MSGS_PER_IP_HOUR = 60;
const W_HISTORY_CHATS = 3;

/** sha256 of the widget's random per-browser key, or '' if it is not a 32-hex key. The key lets a browser read its own past chats. */
function w_vhash(mixed $v): string { return is_string($v) && preg_match('/^[a-f0-9]{32}$/', $v) ? hash('sha256', $v) : ''; }

function w_open(array $c, bool $es): void {
    if ($c['status'] === 'closed') fail(409, $es ? 'Este chat terminó. Escriba para iniciar uno nuevo.' : 'This chat has ended. Type to start a new one.', ['code' => 'chat_closed']);
}

$a = (string)($_GET['a'] ?? '');
$es = req_lang() === 'es';

function w_tenant_by_key(string $k): array {
    $st = db()->prepare("SELECT * FROM tenants WHERE public_key = ? AND status = 'active'");
    $st->execute([$k]);
    $t = $st->fetch();
    if (!$t) fail(404, 'This chat is not available.');
    return $t;
}

/** The page's site must be on the company's allowed list (empty list = any site). Clear message, not a silent blank (Zoho lesson). */
function w_check_site(array $t): void {
    $allowed = array_filter(array_map('trim', explode(',', (string)$t['allowed_domains'])));
    if (!$allowed) return;
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($src === '') return;   // shortcut: a missing Origin/Referer is allowed (curl, privacy tools); the list only stops other websites
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($src, PHP_URL_HOST)));
    if ($host === strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) return;   // our own dashboard preview
    foreach ($allowed as $d) if ($host === $d || str_ends_with($host, '.' . $d)) return;
    fail(403, "This website ($host) is not on {$t['name']}'s allowed list. The owner can add it in Settings > Install.", ['code' => 'domain_not_allowed']);
}

function w_conv(): array {
    $st = db()->prepare("SELECT c.*, t.status AS tenant_status FROM conversations c JOIN tenants t ON t.id = c.tenant_id WHERE c.id = ?");
    $st->execute([(int)($_GET['c'] ?? 0)]);
    $c = $st->fetch();
    if (!$c || !hash_equals((string)$c['token'], (string)($_GET['t'] ?? '')) || $c['tenant_status'] !== 'active') fail(403, 'This chat has ended. Please start a new one.', ['code' => 'chat_ended']);
    return $c;
}

/** Is anyone on the team online and taking live chats right now? (dashboard heartbeat within 60 s, see live.php) */
function w_agents_online(int $tid): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status = 'active' AND available = 1 AND seen_at > UTC_TIMESTAMP() - INTERVAL 60 SECOND");
    $st->execute([$tid]);
    return (int)$st->fetchColumn() > 0;
}

function w_departments(array $t): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)$t['departments']))));
}

if ($a === 'config') {
    $t = w_tenant_by_key((string)($_GET['k'] ?? ''));
    w_check_site($t);
    out(['company' => $t['name'], 'color' => $t['color'], 'departments' => w_departments($t), 'ask_email' => (bool)$t['ask_email'],
         'greeting' => $es ? ($t['greeting_es'] ?: $t['greeting_en']) : $t['greeting_en'],
         'live_chat' => (bool)$t['live_chat'], 'online' => $t['live_chat'] && w_agents_online((int)$t['id'])]);
}

if (method() !== 'POST' && $a !== 'poll') fail(405, 'Method not allowed');

if ($a === 'start') {
    $t = w_tenant_by_key((string)($_GET['k'] ?? ''));
    w_check_site($t);
    $b = body();
    $name = mb_substr(fmt_name($b['name'] ?? ''), 0, 80);
    if ($name === '') fail(400, $es ? 'Escriba su nombre.' : 'Please enter your name.');
    $email = fmt_email($b['email'] ?? '');
    if (trim((string)($b['email'] ?? '')) !== '' && $email === '') fail(400, $es ? 'Ese correo no parece válido.' : 'That email address does not look right.');
    $depts = w_departments($t);
    $dept = in_array($b['department'] ?? '', $depts, true) ? $b['department'] : ($depts[0] ?? '');
    $ip = client_ip();
    if (!rate_ok("start:{$t['id']}:$ip", W_NEW_CHATS_PER_IP_HOUR, 3600)) fail(429, $es ? 'Demasiados chats. Inténtelo más tarde.' : 'Too many chats from here. Please try again later.');
    [$city, $region, $country] = geo($ip);
    $token = rand_key(16);
    db()->prepare("INSERT INTO conversations (tenant_id, token, name, email, department, city, region, country, ip, page, lang, user_agent, visitor_hash, created_at, last_at, visitor_seen_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([$t['id'], $token, $name, $email, $dept, $city, $region, $country, $ip, mb_substr(str_in($b, 'page', 500), 0, 500), req_lang(),
                   mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), w_vhash($b['v'] ?? null)]);
    out(['conv' => (int)db()->lastInsertId(), 'token' => $token, 'name' => $name]);
}

if ($a === 'say') {
    $c = w_conv();
    w_open($c, $es);
    $text = mb_substr(trim((string)(body()['text'] ?? '')), 0, 1000);
    if ($text === '') fail(400, $es ? 'Escriba su pregunta.' : 'Please type a question.');
    if (!rate_ok('say:' . client_ip(), W_MSGS_PER_IP_HOUR, 3600)) fail(429, $es ? 'Demasiados mensajes. Inténtelo más tarde.' : 'Too many messages. Please try again later.');
    db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, created_at) VALUES (?,?, 'visitor', ?, UTC_TIMESTAMP())")->execute([$c['id'], $c['tenant_id'], $text]);
    $vid = (int)db()->lastInsertId();
    db()->prepare("UPDATE conversations SET unread = 1, emailed_at = NULL, last_at = UTC_TIMESTAMP(), visitor_seen_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]);
    // A person has the chat, or the visitor asked for one: the bot stays quiet and the team answers.
    if ($c['mode'] === 'human' || $c['handoff_at']) out(['id' => $vid, 'reply' => null, 'mode' => 'human']);

    $st = db()->prepare("SELECT * FROM tenants WHERE id = ?");
    $st->execute([$c['tenant_id']]);
    $r = answer_message($st->fetch(), $c);
    if ($r['reply'] === '') fail(400, $es ? 'Escriba su pregunta.' : 'Please type a question.');
    db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, sources, answered, via, created_at) VALUES (?,?, 'bot', ?, ?, ?, ?, UTC_TIMESTAMP())")
        ->execute([$c['id'], $c['tenant_id'], $r['reply'], $r['sources'] ? json_encode($r['sources'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $r['answered'] ? 1 : 0, $r['via']]);
    $bid = (int)db()->lastInsertId();
    $set = ['last_at = UTC_TIMESTAMP()']; $args = [];
    if ($r['handoff']) $set[] = 'handoff = 1';   // bot could not answer: flag it for the team, but keep answering
    if ($c['topic'] === '' && $r['topic'] !== '') { $set[] = 'topic = ?'; $args[] = $r['topic']; }
    $args[] = $c['id'];
    db()->prepare("UPDATE conversations SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
    out(['id' => $bid, 'visitor_id' => $vid, 'reply' => $r['reply'], 'sources' => $r['sources'], 'handoff' => $r['handoff'], 'mode' => 'bot']);
}

if ($a === 'poll') {
    $c = w_conv();
    db()->prepare("UPDATE conversations SET visitor_seen_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]);   // "chatting now" on the Live board
    $st = db()->prepare("SELECT id, role, body, sources, agent_name, file_id FROM messages WHERE conv_id = ? AND id > ? AND role IN ('bot','agent','system') ORDER BY id LIMIT 50");
    $st->execute([$c['id'], (int)($_GET['after'] ?? 0)]);
    $rows = array_map(fn($m) => ['id' => (int)$m['id'], 'role' => $m['role'], 'body' => $m['body'], 'agent' => $m['agent_name'], 'file_id' => $m['file_id'],
                                 'sources' => $m['sources'] ? json_decode($m['sources'], true) : []], $st->fetchAll());
    if ($rows) db()->prepare("UPDATE messages SET delivered = 1 WHERE conv_id = ? AND role = 'agent' AND id <= ?")->execute([$c['id'], end($rows)['id']]);   // seen here: no email copy needed
    out(['messages' => chat_attach_files($rows), 'mode' => $c['mode'], 'handoff' => (bool)$c['handoff'], 'closed' => $c['status'] === 'closed']);
}

if ($a === 'human') {
    $c = w_conv();
    w_open($c, $es);
    $st = db()->prepare("SELECT live_chat FROM tenants WHERE id = ?");
    $st->execute([$c['tenant_id']]);
    $online = (int)$st->fetchColumn() === 1 && w_agents_online((int)$c['tenant_id']);
    if ($online) $note = $es ? 'Le estoy conectando con una persona del equipo. Le responderá aquí mismo en un momento.'
                             : "I'm connecting you with a person on our team. They'll reply right here in a moment.";
    elseif ($c['email'] !== '') $note = $es ? "Nadie del equipo está disponible ahora. Le responderemos por correo a {$c['email']}. Escriba su mensaje aquí."
                                           : "No one on our team is available right now. We'll reply by email to {$c['email']}. Type your message here.";
    else $note = $es ? 'Nadie del equipo está disponible ahora. Déjenos su correo y su mensaje, y le responderemos.'
                     : "No one on our team is available right now. Leave your email and your message, and we'll get back to you.";
    db()->prepare("UPDATE conversations SET handoff = 1, unread = 1, handoff_at = COALESCE(handoff_at, UTC_TIMESTAMP()), alerted_at = NULL, last_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]);
    db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, created_at) VALUES (?,?, 'system', ?, UTC_TIMESTAMP())")->execute([$c['id'], $c['tenant_id'], $note]);
    out(['id' => (int)db()->lastInsertId(), 'reply' => $note, 'online' => $online, 'need_email' => $c['email'] === '']);
}

if ($a === 'email') {
    $c = w_conv();
    $b = body();
    $typed = trim((string)($b['email'] ?? ''));
    $email = $typed !== '' ? fmt_email($typed) : $c['email'];
    if ($email === '') fail(400, $es ? 'Ese correo no parece válido.' : 'That email address does not look right.');
    if (!rate_ok('email:' . $c['id'], 5, 3600)) fail(429, $es ? 'Demasiadas solicitudes. Inténtelo más tarde.' : 'Too many requests. Please try again later.');
    $send = !empty($b['send']);
    db()->prepare("UPDATE conversations SET email = ?" . ($send ? ", visitor_mail_at = UTC_TIMESTAMP()" : '') . " WHERE id = ?")->execute([$email, $c['id']]);
    out(['ok' => true, 'email' => $email,
         'reply' => $send ? ($es ? "Le enviaremos una copia de este chat a $email." : "We'll email a copy of this chat to $email.")
                          : ($es ? "Gracias. Le responderemos a $email." : "Thanks. We'll reply to $email.")]);
}

if ($a === 'upload') {
    $c = w_conv();
    w_open($c, $es);
    if (!rate_ok('upload:' . client_ip(), 20, 3600)) fail(429, $es ? 'Demasiados archivos. Inténtelo más tarde.' : 'Too many files. Please try again later.');
    if (empty($_FILES['file'])) fail(400, $es ? 'Elija un archivo.' : 'Choose a file.');
    $f = chat_file_save($_FILES['file'], (int)$c['tenant_id'], (int)$c['id']);
    db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, file_id, created_at) VALUES (?,?, 'visitor', ?, ?, UTC_TIMESTAMP())")
        ->execute([$c['id'], $c['tenant_id'], '📎 ' . $f['name'], $f['id']]);
    $mid = (int)db()->lastInsertId();
    db()->prepare("UPDATE conversations SET unread = 1, emailed_at = NULL, last_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]);
    out(['id' => $mid, 'file' => $f]);
}

if ($a === 'end') {
    $c = w_conv();
    // Closed until the visitor starts a new one. The worker emails the transcript (status closed) to the company.
    db()->prepare("UPDATE conversations SET status = 'closed' WHERE id = ? AND status = 'open'")->execute([$c['id']]);
    out(['ok' => true]);
}

if ($a === 'history') {
    $t = w_tenant_by_key((string)($_GET['k'] ?? ''));
    w_check_site($t);
    $b = body();
    $vh = w_vhash($b['v'] ?? null);
    if ($vh === '') out(['chats' => []]);
    if (!rate_ok('hist:' . client_ip(), 120, 3600)) fail(429, $es ? 'Demasiadas solicitudes.' : 'Too many requests.');
    // shortcut: no (tenant_id, visitor_hash) index; the tenant_id index is enough at beta volume. Add one if chats grow large.
    $st = db()->prepare("SELECT id, created_at FROM conversations WHERE tenant_id = ? AND visitor_hash = ? AND id <> ? AND created_at > UTC_TIMESTAMP() - INTERVAL 90 DAY
                         AND EXISTS (SELECT 1 FROM messages m WHERE m.conv_id = conversations.id AND m.role = 'visitor') ORDER BY id DESC LIMIT " . W_HISTORY_CHATS);
    $st->execute([$t['id'], $vh, (int)($b['exclude'] ?? 0)]);
    $chats = [];
    foreach (array_reverse($st->fetchAll()) as $cv) {
        $m = db()->prepare("SELECT * FROM (SELECT id, role, body, agent_name, file_id FROM messages WHERE conv_id = ? AND role IN ('visitor','bot','agent') ORDER BY id DESC LIMIT 60) x ORDER BY id");
        $m->execute([$cv['id']]);
        $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'role' => $r['role'], 'body' => $r['body'], 'agent' => $r['agent_name'], 'file_id' => $r['file_id']], $m->fetchAll());
        $chats[] = ['started' => $cv['created_at'], 'messages' => chat_attach_files($rows)];
    }
    out(['chats' => $chats]);
}

fail(400, 'Unknown action.');
