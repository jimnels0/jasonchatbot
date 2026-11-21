<?php
// api/auth.php -- dashboard accounts.
//   POST ?a=signup {company, website, name, email, password}  -> new company + its owner, signed in
//   POST ?a=login {email, password}   POST ?a=logout   GET ?a=me   POST ?a=password {current, new}   POST ?a=lang {lang}
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/format.php';
require __DIR__ . '/lib/auth.php';
api_headers();

$a = (string)($_GET['a'] ?? '');
$b = body();

function me_payload(int $uid): array {
    $st = db()->prepare("SELECT u.id, u.email, u.name, u.role, u.is_platform_admin, u.lang, t.id tenant_id, t.name company, t.public_key
                         FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    foreach (['id', 'tenant_id', 'is_platform_admin'] as $k) $r[$k] = (int)$r[$k];
    return $r;
}

if ($a === 'me') { $u = require_user(); out(['user' => me_payload($u['id'])]); }

if (method() !== 'POST') fail(405, 'Method not allowed');

if ($a === 'signup') {
    // Outside-USA visitors cannot open an account (the browser also shows them the under-construction page).
    $cc = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if ($cc !== '' && $cc !== 'US' && $cc !== 'XX') fail(403, 'Sign-ups are only open in the United States for now.');
    if (!rate_ok('signup:' . client_ip(), 5, 3600)) fail(429, 'Too many sign-ups from here. Please try again later.');
    $company = mb_substr(fmt_name($b['company'] ?? ''), 0, 120);
    $name = mb_substr(fmt_name($b['name'] ?? ''), 0, 100);
    $email = fmt_email($b['email'] ?? '');
    $site = fmt_url($b['website'] ?? '');
    $pw = (string)($b['password'] ?? '');
    if ($company === '' || $name === '') fail(400, 'Please enter your company and your name.');
    if ($email === '') fail(400, 'Please enter a valid email address.');
    if (trim((string)($b['website'] ?? '')) !== '' && $site === '') fail(400, 'That website address does not look right.');
    if ($p = password_problem($pw)) fail(400, $p);
    $pdo = db();
    $st = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
    $st->execute([$email]);
    if ($st->fetch()) fail(409, 'An account with that email already exists. Please sign in.');
    $domain = $site !== '' ? fmt_domain($site) : '';
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO tenants (public_key, name, website, allowed_domains, greeting_en, greeting_es, created_at) VALUES (?,?,?,?,?,?, UTC_TIMESTAMP())")
        ->execute([rand_key(12), $company, $site, $domain, "Hi! I'm the $company assistant. How can I help?", "¡Hola! Soy el asistente de $company. ¿En qué le puedo ayudar?"]);
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (tenant_id, email, name, pass_hash, role, lang, created_at, last_login_at) VALUES (?,?,?,?, 'owner', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([$tid, $email, $name, password_hash($pw, PASSWORD_DEFAULT), req_lang()]);
    $uid = (int)$pdo->lastInsertId();
    if ($site !== '') {   // start reading their website right away (worker.php)
        $pdo->prepare("INSERT INTO sources (tenant_id, kind, url, title, created_at, updated_at) VALUES (?, 'site', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$tid, $site, $domain]);
    }
    $pdo->commit();
    db()->prepare("INSERT INTO visits (kind, ip, country, path, note, created_at) VALUES ('signup', ?, ?, '/signup', ?, UTC_TIMESTAMP())")
        ->execute([client_ip(), $cc, mb_substr("$company <$email>", 0, 255)]);
    out(['token' => auth_issue($uid), 'user' => me_payload($uid)]);
}

if ($a === 'login') {
    $email = fmt_email($b['email'] ?? '');
    $ip = client_ip();
    // 5 wrong tries per email / 20 per address in 15 minutes, checked before the password (rate_hits only counts failures).
    $chk = function (string $k, int $lim) { $st = db()->prepare("SELECT COUNT(*) FROM rate_hits WHERE k = ? AND at > UTC_TIMESTAMP() - INTERVAL 900 SECOND"); $st->execute([$k]); return (int)$st->fetchColumn() < $lim; };
    if (!$chk("loginfail:e:$email", 5) || !$chk("loginfail:ip:$ip", 20)) fail(429, 'Too many tries. Please wait 15 minutes and try again.');
    $st = db()->prepare("SELECT u.id, u.pass_hash, u.status, t.status tstatus FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.email = ?");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($b['password'] ?? ''), $u['pass_hash'])) {
        $ins = db()->prepare("INSERT INTO rate_hits (k, at) VALUES (?, UTC_TIMESTAMP())");
        $ins->execute(["loginfail:e:$email"]); $ins->execute(["loginfail:ip:$ip"]);
        fail(401, 'Wrong email or password.');
    }
    if ($u['status'] !== 'active') fail(403, 'This account is turned off. Ask your company owner.');
    db()->prepare("DELETE FROM rate_hits WHERE k = ?")->execute(["loginfail:e:$email"]);
    db()->prepare("UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$u['id']]);
    out(['token' => auth_issue((int)$u['id']), 'user' => me_payload((int)$u['id'])]);
}

if ($a === 'logout') { auth_logout(); out(['ok' => true]); }

if ($a === 'password') {
    $u = require_user();
    $st = db()->prepare("SELECT pass_hash FROM users WHERE id = ?");
    $st->execute([$u['id']]);
    if (!password_verify((string)($b['current'] ?? ''), (string)$st->fetchColumn())) fail(400, 'Your current password is not right.');
    if ($p = password_problem((string)($b['new'] ?? ''))) fail(400, $p);
    db()->prepare("UPDATE users SET pass_hash = ? WHERE id = ?")->execute([password_hash((string)$b['new'], PASSWORD_DEFAULT), $u['id']]);
    auth_logout_others($u['id']);
    out(['ok' => true]);
}

if ($a === 'lang') {
    $u = require_user();
    db()->prepare("UPDATE users SET lang = ? WHERE id = ?")->execute([($b['lang'] ?? '') === 'es' ? 'es' : 'en', $u['id']]);
    out(['ok' => true]);
}

fail(400, 'Unknown action.');
