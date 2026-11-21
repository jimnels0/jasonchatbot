<?php
// api/tenant.php -- the company's settings: name, website, look, greeting, tone, departments, allowed sites.
//   GET (anyone on the team)   PUT (owner)   POST ?a=newkey (owner: new widget key; the old install line stops working)
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/format.php';
require __DIR__ . '/lib/auth.php';
api_headers();
$u = require_user();
$tid = $u['tenant_id'];

function tenant_row(int $tid): array {
    $st = db()->prepare("SELECT id, public_key, name, website, color, greeting_en, greeting_es, tone, departments, allowed_domains, ask_email, plan, msg_limit, max_pages, created_at FROM tenants WHERE id = ?");
    $st->execute([$tid]);
    $t = $st->fetch();
    $st = db()->prepare("SELECT answers FROM usage_monthly WHERE tenant_id = ? AND ym = ?");
    $st->execute([$tid, gmdate('Y-m')]);
    $t['answers_this_month'] = (int)$st->fetchColumn();
    foreach (['id', 'ask_email', 'msg_limit', 'max_pages'] as $k) $t[$k] = (int)$t[$k];
    return $t;
}

if (method() === 'GET') out(['tenant' => tenant_row($tid)]);
if ($u['role'] !== 'owner') fail(403, 'Only the account owner can change settings.');

if (method() === 'POST' && ($_GET['a'] ?? '') === 'newkey') {
    db()->prepare("UPDATE tenants SET public_key = ? WHERE id = ?")->execute([rand_key(12), $tid]);
    out(['tenant' => tenant_row($tid)]);
}

if (method() === 'PUT') {
    $b = body();
    $name = mb_substr(fmt_name($b['name'] ?? ''), 0, 120);
    if ($name === '') fail(400, 'Please enter the company name.');
    $site = fmt_url($b['website'] ?? '');
    if (trim((string)($b['website'] ?? '')) !== '' && $site === '') fail(400, 'That website address does not look right.');
    $depts = [];
    foreach (explode(',', (string)($b['departments'] ?? '')) as $d) { $d = mb_substr(fmt_name($d), 0, 40); if ($d !== '' && !in_array($d, $depts, true)) $depts[] = $d; }
    $domains = [];
    foreach (preg_split('/[\s,]+/', (string)($b['allowed_domains'] ?? '')) ?: [] as $d) {
        if (trim($d) === '') continue;
        $f = fmt_domain($d);
        if ($f === '') fail(400, "\"$d\" is not a website domain (example: yourcompany.com).");
        if (!in_array($f, $domains, true)) $domains[] = $f;
    }
    db()->prepare("UPDATE tenants SET name = ?, website = ?, color = ?, greeting_en = ?, greeting_es = ?, tone = ?, departments = ?, allowed_domains = ?, ask_email = ? WHERE id = ?")
        ->execute([$name, $site, fmt_color($b['color'] ?? ''), str_in($b, 'greeting_en', 300), str_in($b, 'greeting_es', 300), str_in($b, 'tone', 600),
                   implode(',', array_slice($depts, 0, 8)), implode(',', array_slice($domains, 0, 20)), !empty($b['ask_email']) ? 1 : 0, $tid]);
    out(['tenant' => tenant_row($tid)]);
}
fail(405, 'Method not allowed');
