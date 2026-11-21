<?php
// api/chats.php -- the company's inbox: who is talking to the bot (name, location, department, topic) and every transcript.
//   GET ?filter=all|open|handoff|unread&q=   -> list        GET ?id=N -> conversation + messages (marks it read)
//   POST ?id=N&a=reply {text}  (a person answers; the bot stops)   POST ?id=N&a=bot (hand back)   ?a=close / ?a=reopen
//   DELETE ?id=N  (owner: removes the chat for good, e.g. a privacy request)
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
api_headers();
$u = require_user();
$tid = $u['tenant_id'];

function chat_of(int $tid): array {
    $st = db()->prepare("SELECT * FROM conversations WHERE id = ? AND tenant_id = ?");
    $st->execute([(int)($_GET['id'] ?? 0), $tid]);
    $c = $st->fetch();
    if (!$c) fail(404, 'Chat not found.');
    unset($c['token']);
    return $c;
}

if (method() === 'GET' && isset($_GET['id'])) {
    $c = chat_of($tid);
    db()->prepare("UPDATE conversations SET unread = 0 WHERE id = ?")->execute([$c['id']]);
    $st = db()->prepare("SELECT id, role, body, sources, answered, via, agent_name, created_at FROM messages WHERE conv_id = ? ORDER BY id");
    $st->execute([$c['id']]);
    $msgs = array_map(fn($m) => ['sources' => $m['sources'] ? json_decode($m['sources'], true) : []] + $m, $st->fetchAll());
    out(['chat' => $c, 'messages' => $msgs]);
}

if (method() === 'GET') {
    $where = ['c.tenant_id = ?']; $args = [$tid];
    $f = (string)($_GET['filter'] ?? 'all');
    if ($f === 'open') $where[] = "c.status = 'open'";
    elseif ($f === 'handoff') $where[] = "c.handoff = 1 AND c.status = 'open'";
    elseif ($f === 'unread') $where[] = 'c.unread = 1';
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.topic LIKE ? OR c.department LIKE ? OR c.city LIKE ?)'; array_push($args, ...array_fill(0, 5, '%' . $q . '%')); }
    $st = db()->prepare("SELECT c.id, c.name, c.email, c.department, c.topic, c.city, c.region, c.country, c.lang, c.mode, c.handoff, c.unread, c.status, c.page, c.created_at, c.last_at,
        (SELECT COUNT(*) FROM messages m WHERE m.conv_id = c.id AND m.role = 'visitor') AS msgs,
        (SELECT body FROM messages m WHERE m.conv_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_msg
        FROM conversations c WHERE " . implode(' AND ', $where) . " ORDER BY c.last_at DESC LIMIT 300");
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['last_msg'] = mb_substr((string)$r['last_msg'], 0, 120);
    $n = fn($sql) => (int)db()->query($sql)->fetchColumn();
    out(['rows' => $rows, 'counts' => [
        'handoff' => $n("SELECT COUNT(*) FROM conversations WHERE tenant_id = $tid AND handoff = 1 AND status = 'open'"),
        'unread' => $n("SELECT COUNT(*) FROM conversations WHERE tenant_id = $tid AND unread = 1"),
    ]]);
}

if (method() === 'POST') {
    $c = chat_of($tid);
    $a = (string)($_GET['a'] ?? '');
    if ($a === 'reply') {
        $text = mb_substr(trim((string)(body()['text'] ?? '')), 0, 2000);
        if ($text === '') fail(400, 'Type a reply first.');
        db()->prepare("INSERT INTO messages (conv_id, tenant_id, role, body, agent_name, created_at) VALUES (?,?, 'agent', ?, ?, UTC_TIMESTAMP())")
            ->execute([$c['id'], $tid, $text, $u['name']]);
        db()->prepare("UPDATE conversations SET mode = 'human', handoff = 0, unread = 0, agent_id = ?, status = 'open', last_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$u['id'], $c['id']]);
        out(['ok' => true]);
    }
    if ($a === 'bot') { db()->prepare("UPDATE conversations SET mode = 'bot', handoff = 0 WHERE id = ?")->execute([$c['id']]); out(['ok' => true]); }
    if ($a === 'close') { db()->prepare("UPDATE conversations SET status = 'closed', handoff = 0, unread = 0 WHERE id = ?")->execute([$c['id']]); out(['ok' => true]); }
    if ($a === 'reopen') { db()->prepare("UPDATE conversations SET status = 'open' WHERE id = ?")->execute([$c['id']]); out(['ok' => true]); }
    fail(400, 'Unknown action.');
}

if (method() === 'DELETE') {
    if ($u['role'] !== 'owner') fail(403, 'Only the account owner can delete chats.');
    $c = chat_of($tid);
    db()->prepare("DELETE FROM messages WHERE conv_id = ?")->execute([$c['id']]);
    db()->prepare("DELETE FROM conversations WHERE id = ?")->execute([$c['id']]);
    out(['ok' => true]);
}
fail(405, 'Method not allowed');
