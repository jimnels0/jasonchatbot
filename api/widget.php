<?php
// api/widget.php -- the public chat widget (web/public/widget.js) talks only to this file. Runs on customers' websites.
//   GET  ?a=config&k=KEY                     -> look + departments
//   POST ?a=start&k=KEY  {name,email,department,page}   -> {conv, token}
//   POST ?a=say&c=ID&t=TOKEN  {text}                    -> bot answer (or waiting for a person)
//   GET  ?a=poll&c=ID&t=TOKEN&after=MSGID               -> new bot/agent messages + mode
//   POST ?a=human&c=ID&t=TOKEN                          -> visitor asks for a person
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/format.php';
require __DIR__ . '/lib/answer.php';
api_headers('GET, POST, OPTIONS');

const W_NEW_CHATS_PER_IP_HOUR = 20;
const W_MSGS_PER_IP_HOUR = 60;

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

function w_departments(array $t): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)$t['departments']))));
}

if ($a === 'config') {
    $t = w_tenant_by_key((string)($_GET['k'] ?? ''));
    w_check_site($t);
    out(['company' => $t['name'], 'color' => $t['color'], 'departments' => w_departments($t), 'ask_email' => (bool)$t['ask_email'],
         'greeting' => $es ? ($t['greeting_es'] ?: $t['greeting_en']) : $t['greeting_en']]);
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
    db()->prepare("INSERT INTO conversations (tenant_id, token, name, email, department, city, region, country, ip, page, lang, created_at, last_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([$t['id'], $token, $name, $email, $dept, $city, $region, $country, $ip, mb_substr(str_in($b, 'page', 500), 0, 500), req_lang()]);
    out(['conv' => (int)db()->lastInsertId(), 'token' => $token, 'name' => $name]);
}

if ($a === 'say') {
    $c = w_conv();
    $text = mb_substr(trim((string)(body()['text'] ?? '')), 0, 1000);
    if ($text === '') fail(400, $es ? 'Escriba su pregunta.' : 'Please type a question.');
    if (!rate_ok('say:' . client_ip(), W_MSGS_PER_IP_HOUR, 3600)) fail(429, $es ? 'Demasiados mensajes. Inténtelo más tarde.' : 'Too many messages. Please try again later.');
    db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, created_at) VALUES (?,?, 'visitor', ?, UTC_TIMESTAMP())")->execute([$c['id'], $c['tenant_id'], $text]);
    $vid = (int)db()->lastInsertId();
    db()->prepare("UPDATE conversations SET unread = 1, status = 'open', last_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]);
    if ($c['mode'] === 'human') out(['id' => $vid, 'reply' => null, 'mode' => 'human']);   // a person is handling it: no bot reply

    $st = db()->prepare("SELECT * FROM tenants WHERE id = ?");
    $st->execute([$c['tenant_id']]);
    $r = answer_message($st->fetch(), $c);
    if ($r['reply'] === '') fail(400, $es ? 'Escriba su pregunta.' : 'Please type a question.');
    db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, sources, answered, via, created_at) VALUES (?,?, 'bot', ?, ?, ?, ?, UTC_TIMESTAMP())")
        ->execute([$c['id'], $c['tenant_id'], $r['reply'], $r['sources'] ? json_encode($r['sources'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $r['answered'] ? 1 : 0, $r['via']]);
    $bid = (int)db()->lastInsertId();
    $set = ['last_at = UTC_TIMESTAMP()']; $args = [];
    if ($r['handoff']) $set[] = 'handoff = 1';
    if ($c['topic'] === '' && $r['topic'] !== '') { $set[] = 'topic = ?'; $args[] = $r['topic']; }
    $args[] = $c['id'];
    db()->prepare("UPDATE conversations SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
    out(['id' => $bid, 'visitor_id' => $vid, 'reply' => $r['reply'], 'sources' => $r['sources'], 'handoff' => $r['handoff'], 'mode' => 'bot']);
}

if ($a === 'poll') {
    $c = w_conv();
    $st = db()->prepare("SELECT id, role, body, sources, agent_name FROM messages WHERE conv_id = ? AND id > ? AND role IN ('bot','agent','system') ORDER BY id LIMIT 50");
    $st->execute([$c['id'], (int)($_GET['after'] ?? 0)]);
    $rows = array_map(fn($m) => ['id' => (int)$m['id'], 'role' => $m['role'], 'body' => $m['body'], 'agent' => $m['agent_name'],
                                 'sources' => $m['sources'] ? json_decode($m['sources'], true) : []], $st->fetchAll());
    out(['messages' => $rows, 'mode' => $c['mode']]);
}

if ($a === 'human') {
    $c = w_conv();
    $note = $es ? 'Listo, avisé al equipo. Una persona le responderá aquí mismo.' : "Done, I've let the team know. A person will reply right here.";
    db()->prepare("UPDATE conversations SET handoff = 1, unread = 1, last_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]);
    db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, created_at) VALUES (?,?, 'system', ?, UTC_TIMESTAMP())")->execute([$c['id'], $c['tenant_id'], $note]);
    out(['id' => (int)db()->lastInsertId(), 'reply' => $note]);
}

fail(400, 'Unknown action.');
