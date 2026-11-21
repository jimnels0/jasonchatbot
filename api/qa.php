<?php
// api/qa.php -- approved answers: exact wording the company wants the bot to use (owner only).
//   GET   POST {question, answer}   PUT ?id=N {question, answer}   DELETE ?id=N
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
api_headers();
$u = require_owner();
$tid = $u['tenant_id'];

if (method() === 'GET') {
    $st = db()->prepare("SELECT id, question, answer, created_at FROM qa WHERE tenant_id = ? ORDER BY id DESC");
    $st->execute([$tid]);
    out(['rows' => $st->fetchAll()]);
}
if (method() === 'DELETE') {
    db()->prepare("DELETE FROM qa WHERE id = ? AND tenant_id = ?")->execute([(int)($_GET['id'] ?? 0), $tid]);
    out(['ok' => true]);
}
$b = body();
$q = str_in($b, 'question', 500);
$ans = mb_substr(trim((string)($b['answer'] ?? '')), 0, 4000);
if ($q === '' || $ans === '') fail(400, 'Please fill in both the question and the answer.');
if (method() === 'POST') {
    $n = db()->prepare("SELECT COUNT(*) FROM qa WHERE tenant_id = ?");
    $n->execute([$tid]);
    if ((int)$n->fetchColumn() >= 1000) fail(400, 'You have reached 1,000 approved answers.');
    db()->prepare("INSERT INTO qa (tenant_id, question, answer, created_at) VALUES (?,?,?, UTC_TIMESTAMP())")->execute([$tid, $q, $ans]);
    out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}
if (method() === 'PUT') {
    db()->prepare("UPDATE qa SET question = ?, answer = ? WHERE id = ? AND tenant_id = ?")->execute([$q, $ans, (int)($_GET['id'] ?? 0), $tid]);
    out(['ok' => true]);
}
fail(405, 'Method not allowed');
