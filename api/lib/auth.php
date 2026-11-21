<?php
// api/lib/auth.php -- dashboard sign-in. Random session token; only its SHA-256 is stored. Browser sends X-Auth-Token.
// Roles: owner (everything for their company), agent (chats only). is_platform_admin = James (Owner console, all companies).
// Every tenant-scoped query uses $u['tenant_id'] from here, never a tenant id sent by the browser.
const SESSION_DAYS = 30;

function auth_token(): string {
    $t = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
}

function auth_issue(int $userId): string {
    $t = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO sessions (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL " . SESSION_DAYS . " DAY)")
        ->execute([$userId, hash('sha256', $t)]);
    db()->prepare("DELETE FROM sessions WHERE user_id = ? AND expires_at < UTC_TIMESTAMP()")->execute([$userId]);
    return $t;
}

function auth_user(): ?array {
    static $done = false, $u = null;
    if ($done) return $u;
    $done = true;
    $t = auth_token();
    if ($t === '') return null;
    $st = db()->prepare("SELECT s.id sid, u.id, u.tenant_id, u.email, u.name, u.role, u.is_platform_admin, u.lang, u.status, t.status tenant_status
                         FROM sessions s JOIN users u ON u.id = s.user_id JOIN tenants t ON t.id = u.tenant_id
                         WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP()");
    $st->execute([hash('sha256', $t)]);
    $r = $st->fetch();
    if (!$r || $r['status'] !== 'active') return null;
    db()->prepare("UPDATE sessions SET expires_at = UTC_TIMESTAMP() + INTERVAL " . SESSION_DAYS . " DAY WHERE id = ?")->execute([$r['sid']]);
    foreach (['id', 'tenant_id', 'is_platform_admin'] as $k) $r[$k] = (int)$r[$k];
    unset($r['sid']);
    return $u = $r;
}

function require_user(): array { $u = auth_user(); if (!$u) fail(401, 'Please sign in again.', ['code' => 'auth_required']); return $u; }
function require_owner(): array { $u = require_user(); if ($u['role'] !== 'owner') fail(403, 'Only an admin can do that.'); return $u; }
function require_platform_admin(): array { $u = require_user(); if (!$u['is_platform_admin']) fail(403, 'Not allowed.'); return $u; }

function auth_logout(): void { $t = auth_token(); if ($t !== '') db()->prepare("DELETE FROM sessions WHERE token_hash = ?")->execute([hash('sha256', $t)]); }
function auth_logout_others(int $userId): void {
    db()->prepare("DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?")->execute([$userId, hash('sha256', auth_token())]);
}

/** Password rule shared by sign-up, change-password and new team members. '' = fine, else the reason. */
function password_problem(string $p): string {
    if (mb_strlen($p) < 10) return 'Use at least 10 characters.';
    if (mb_strlen($p) > 200) return 'That password is too long.';
    return '';
}
