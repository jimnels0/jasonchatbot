<?php
// api/stats.php -- the company's overview: chat counts, answers used this month, people waiting, top topics,
// departments, and the questions the bot could NOT answer (what to add to the knowledge next).
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
api_headers('GET, OPTIONS');
$u = require_user();
$tid = $u['tenant_id'];
$one = function (string $sql, array $a = []) { $st = db()->prepare($sql); $st->execute($a); return (int)$st->fetchColumn(); };
$all = function (string $sql, array $a = []) { $st = db()->prepare($sql); $st->execute($a); return $st->fetchAll(); };

$st = db()->prepare("SELECT msg_limit FROM tenants WHERE id = ?");
$st->execute([$tid]);
out([
    'chats_today' => $one("SELECT COUNT(*) FROM conversations WHERE tenant_id = ? AND created_at >= UTC_DATE()", [$tid]),
    'chats_7d' => $one("SELECT COUNT(*) FROM conversations WHERE tenant_id = ? AND created_at >= UTC_DATE() - INTERVAL 6 DAY", [$tid]),
    'chats_30d' => $one("SELECT COUNT(*) FROM conversations WHERE tenant_id = ? AND created_at >= UTC_DATE() - INTERVAL 29 DAY", [$tid]),
    'waiting' => $one("SELECT COUNT(*) FROM conversations WHERE tenant_id = ? AND handoff = 1 AND status = 'open'", [$tid]),
    'answers_month' => $one("SELECT answers FROM usage_monthly WHERE tenant_id = ? AND ym = ?", [$tid, gmdate('Y-m')]),
    'msg_limit' => (int)$st->fetchColumn(),
    'answered_rate' => (function () use ($one, $tid) {
        $tot = $one("SELECT COUNT(*) FROM messages WHERE tenant_id = ? AND role = 'bot' AND created_at >= UTC_DATE() - INTERVAL 29 DAY", [$tid]);
        return $tot ? round(100 * $one("SELECT COUNT(*) FROM messages WHERE tenant_id = ? AND role = 'bot' AND answered = 1 AND created_at >= UTC_DATE() - INTERVAL 29 DAY", [$tid]) / $tot) : null;
    })(),
    'topics' => $all("SELECT topic AS label, COUNT(*) AS n FROM conversations WHERE tenant_id = ? AND topic <> '' AND created_at >= UTC_DATE() - INTERVAL 29 DAY GROUP BY topic ORDER BY n DESC LIMIT 8", [$tid]),
    'departments' => $all("SELECT department AS label, COUNT(*) AS n FROM conversations WHERE tenant_id = ? AND department <> '' AND created_at >= UTC_DATE() - INTERVAL 29 DAY GROUP BY department ORDER BY n DESC", [$tid]),
    'places' => $all("SELECT CONCAT_WS(', ', NULLIF(region, ''), NULLIF(country, '')) AS label, COUNT(*) AS n FROM conversations WHERE tenant_id = ? AND country <> '' AND created_at >= UTC_DATE() - INTERVAL 29 DAY GROUP BY label ORDER BY n DESC LIMIT 8", [$tid]),
    // The visitor question just before each answer the bot could not give.
    'unanswered' => $all("SELECT b.conv_id, b.created_at,
          (SELECT v.body FROM messages v WHERE v.conv_id = b.conv_id AND v.role = 'visitor' AND v.id < b.id ORDER BY v.id DESC LIMIT 1) AS question
        FROM messages b WHERE b.tenant_id = ? AND b.role = 'bot' AND b.answered = 0 AND b.via NOT IN ('limit') ORDER BY b.id DESC LIMIT 25", [$tid]),
]);
