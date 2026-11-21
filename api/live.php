<?php
// api/live.php -- presence + the "Live now" board.
//   GET            heartbeat (marks me online for live chat) -> {available, waiting:[...], agents_online}
//   GET ?full=1    also -> now: visitors chatting right now (with their info), today: every chat today (time line)
//   POST {available: true|false}   my own "I can take live chats" switch
// The dashboard calls the plain GET every 10 s, even in a background tab, so a waiting visitor always rings.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
api_headers('GET, POST, OPTIONS');
$u = require_user();
$tid = $u['tenant_id'];
const ONLINE_SECONDS = 60;          // agent counts as online this long after their last heartbeat
const VISITOR_ONLINE_SECONDS = 45;  // widget polls every 4 s while the panel is open

if (method() === 'POST') {
    $on = !empty(body()['available']) ? 1 : 0;
    db()->prepare("UPDATE users SET available = ?, seen_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$on, $u['id']]);
    out(['available' => (bool)$on]);
}

db()->prepare("UPDATE users SET seen_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$u['id']]);
$q = function (string $sql, array $a) { $st = db()->prepare($sql); $st->execute($a); return $st->fetchAll(); };

/** "Chrome on Windows (desktop)"-style label from a user agent. shortcut: a few patterns, not a full parser. */
function device_label(string $ua): string {
    if ($ua === '') return '';
    $os = preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows/i', $ua) ? 'Windows'
        : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'Mac' : (preg_match('/CrOS/i', $ua) ? 'ChromeOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : '')))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet'
        : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : '')))));
    $kind = preg_match('/Mobi|iPhone|Android.+Mobile/i', $ua) ? 'phone' : (preg_match('/iPad|Tablet|Android/i', $ua) ? 'tablet' : 'computer');
    return trim(($br ?: 'Browser') . ($os ? " on $os" : '') . " ($kind)");
}

$me = $q("SELECT available FROM users WHERE id = ?", [$u['id']])[0];
$res = [
    'available' => (bool)$me['available'],
    'waiting' => $q("SELECT id, name, department, COALESCE(handoff_at, last_at) AS since FROM conversations
                     WHERE tenant_id = ? AND handoff = 1 AND status = 'open' ORDER BY since LIMIT 20", [$tid]),
    'agents_online' => $q("SELECT id, name FROM users WHERE tenant_id = ? AND status = 'active' AND available = 1
                           AND seen_at > UTC_TIMESTAMP() - INTERVAL " . ONLINE_SECONDS . " SECOND ORDER BY name", [$tid]),
];

if (!empty($_GET['full'])) {
    $cols = "c.id, c.name, c.email, c.department, c.topic, c.city, c.region, c.country, c.lang, c.mode, c.handoff, c.status, c.page,
             c.user_agent, c.created_at, c.last_at, c.visitor_seen_at, c.handoff_at, a.name AS agent_name,
             (SELECT COUNT(*) FROM messages m WHERE m.conv_id = c.id AND m.role = 'visitor') AS msgs,
             (c.visitor_seen_at > UTC_TIMESTAMP() - INTERVAL " . VISITOR_ONLINE_SECONDS . " SECOND) AS online";
    $rows = fn(array $r) => array_map(function ($c) { $c['device'] = device_label((string)$c['user_agent']); unset($c['user_agent']); $c['online'] = (bool)$c['online']; return $c; }, $r);
    $res['now'] = $rows($q("SELECT $cols FROM conversations c LEFT JOIN users a ON a.id = c.agent_id
        WHERE c.tenant_id = ? AND c.status = 'open'
          AND (c.visitor_seen_at > UTC_TIMESTAMP() - INTERVAL " . VISITOR_ONLINE_SECONDS . " SECOND OR c.last_at > UTC_TIMESTAMP() - INTERVAL 10 MINUTE OR c.handoff = 1)
        ORDER BY c.handoff DESC, c.last_at DESC LIMIT 100", [$tid]));
    $res['today'] = $rows($q("SELECT $cols FROM conversations c LEFT JOIN users a ON a.id = c.agent_id
        WHERE c.tenant_id = ? AND c.created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR ORDER BY c.created_at DESC LIMIT 300", [$tid]));
}
out($res);
