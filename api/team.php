<?php
// api/team.php -- the people who answer chats (owner only). No email is sent yet: a new member's first password is
// shown to the owner once, to pass on. shortcut: no invite email, add one when the mail sender exists.
// Roles: 'owner' = admin (full access, shown as Admin); the FIRST owner (lowest id) is the account owner and can't be changed here.
//   GET   POST {name, email, role} -> {password}   POST ?id=N&a=role (owner<->agent)   POST ?id=N&a=reset -> {password}   POST ?id=N&a=toggle   DELETE ?id=N
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/format.php';
require __DIR__ . '/lib/auth.php';
api_headers();
$u = require_owner();
$tid = $u['tenant_id'];

function temp_password(): string {
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 14; $i++) $p .= $chars[random_int(0, strlen($chars) - 1)];
    return $p;
}
function primary_owner(int $tid): int {
    $st = db()->prepare("SELECT MIN(id) FROM users WHERE tenant_id = ? AND role = 'owner'");
    $st->execute([$tid]);
    return (int)$st->fetchColumn();
}
function member(int $tid, int $self): array {
    $st = db()->prepare("SELECT id, role FROM users WHERE id = ? AND tenant_id = ?");
    $st->execute([(int)($_GET['id'] ?? 0), $tid]);
    $m = $st->fetch();
    if (!$m) fail(404, 'Team member not found.');
    if ((int)$m['id'] === $self) fail(400, 'You cannot change your own account here.');
    if ((int)$m['id'] === primary_owner($tid)) fail(400, 'You cannot change the account owner here.');
    return $m;
}

if (method() === 'GET') {
    $st = db()->prepare("SELECT id, name, email, role, status, created_at, last_login_at FROM users WHERE tenant_id = ? ORDER BY role = 'owner' DESC, id");
    $st->execute([$tid]);
    $po = primary_owner($tid);
    $rows = array_map(fn($r) => $r + ['primary' => (int)$r['id'] === $po], $st->fetchAll());
    out(['rows' => $rows]);
}
if (method() === 'DELETE') {
    $m = member($tid, $u['id']);
    db()->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$m['id']]);
    db()->prepare("DELETE FROM users WHERE id = ?")->execute([$m['id']]);
    out(['ok' => true]);
}
if (method() !== 'POST') fail(405, 'Method not allowed');

$a = (string)($_GET['a'] ?? '');
if ($a === 'role') {
    $m = member($tid, $u['id']);
    db()->prepare("UPDATE users SET role = IF(role = 'owner', 'agent', 'owner') WHERE id = ?")->execute([$m['id']]);
    db()->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$m['id']]);
    out(['ok' => true]);
}
if ($a === 'reset' || $a === 'toggle') {
    $m = member($tid, $u['id']);
    db()->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$m['id']]);
    if ($a === 'toggle') { db()->prepare("UPDATE users SET status = IF(status = 'active', 'disabled', 'active') WHERE id = ?")->execute([$m['id']]); out(['ok' => true]); }
    $p = temp_password();
    db()->prepare("UPDATE users SET pass_hash = ? WHERE id = ?")->execute([password_hash($p, PASSWORD_DEFAULT), $m['id']]);
    out(['password' => $p]);
}

$b = body();
$name = mb_substr(fmt_name($b['name'] ?? ''), 0, 100);
$email = fmt_email($b['email'] ?? '');
if ($name === '' || $email === '') fail(400, 'Please enter a name and a valid email address.');
$role = ($b['role'] ?? '') === 'admin' ? 'owner' : 'agent';
$st = db()->prepare("SELECT 1 FROM users WHERE email = ?");
$st->execute([$email]);
if ($st->fetch()) fail(409, 'That email already has an account.');
$n = db()->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ?");
$n->execute([$tid]);
if ((int)$n->fetchColumn() >= 25) fail(400, 'Your plan allows 25 team members.');
$p = temp_password();
db()->prepare("INSERT INTO users (tenant_id, email, name, pass_hash, role, created_at) VALUES (?,?,?,?,?, UTC_TIMESTAMP())")
    ->execute([$tid, $email, $name, password_hash($p, PASSWORD_DEFAULT), $role]);
out(['password' => $p]);
