<?php
// api/owner.php -- platform console for James (users.is_platform_admin = 1): every company, its usage, and Visitors.
//   GET -> {tenants, visits}   POST ?id=N&a=pause|activate   PUT ?id=N {plan, msg_limit, max_pages}
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
api_headers();
require_platform_admin();

if (method() === 'GET') {
    $ym = gmdate('Y-m');
    $tenants = db()->query("SELECT t.id, t.name, t.website, t.plan, t.msg_limit, t.max_pages, t.status, t.created_at,
        (SELECT email FROM users u WHERE u.tenant_id = t.id AND u.role = 'owner' ORDER BY u.id LIMIT 1) AS owner_email,
        (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS team,
        (SELECT COUNT(*) FROM sources s WHERE s.tenant_id = t.id) AS sources,
        (SELECT COUNT(*) FROM conversations c WHERE c.tenant_id = t.id AND c.created_at >= UTC_DATE() - INTERVAL 29 DAY) AS chats_30d,
        (SELECT answers FROM usage_monthly m WHERE m.tenant_id = t.id AND m.ym = " . db()->quote($ym) . ") AS answers_month,
        (SELECT tokens_in + tokens_out FROM usage_monthly m WHERE m.tenant_id = t.id AND m.ym = " . db()->quote($ym) . ") AS tokens_month
        FROM tenants t ORDER BY t.id DESC LIMIT 1000")->fetchAll();
    $visits = db()->query("SELECT id, kind, ip, country, path, note, created_at FROM visits ORDER BY id DESC LIMIT 300")->fetchAll();
    out(['tenants' => $tenants, 'visits' => $visits]);
}
$id = (int)($_GET['id'] ?? 0);
if (method() === 'POST') {
    $a = (string)($_GET['a'] ?? '');
    if ($a !== 'pause' && $a !== 'activate') fail(400, 'Unknown action.');
    db()->prepare("UPDATE tenants SET status = ? WHERE id = ?")->execute([$a === 'pause' ? 'paused' : 'active', $id]);
    out(['ok' => true]);
}
if (method() === 'PUT') {
    $b = body();
    db()->prepare("UPDATE tenants SET plan = ?, msg_limit = ?, max_pages = ? WHERE id = ?")
        ->execute([str_in($b, 'plan', 20) ?: 'trial', max(0, (int)($b['msg_limit'] ?? 500)), max(1, min(2000, (int)($b['max_pages'] ?? 50))), $id]);
    out(['ok' => true]);
}
fail(405, 'Method not allowed');
