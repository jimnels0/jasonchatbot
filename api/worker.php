<?php
// api/worker.php -- background jobs, run every minute by cron (/etc/cron.d/jasonchatbot-worker). CLI only (nginx also
// refuses it). Reads queued websites and PDFs into the knowledge base. One worker at a time (MySQL lock).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/knowledge.php';
set_time_limit(0);

if ((int)db()->query("SELECT GET_LOCK('jcb_worker', 0)")->fetchColumn() !== 1) exit(0);
$started = time();
db()->exec("UPDATE sources SET status = 'queued' WHERE status = 'working' AND updated_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE");   // a crashed run
while (time() - $started < 50) {
    $s = db()->query("SELECT s.*, t.max_pages FROM sources s JOIN tenants t ON t.id = s.tenant_id WHERE s.status = 'queued' AND t.status = 'active' ORDER BY s.id LIMIT 1")->fetch();
    if (!$s) break;
    db()->prepare("UPDATE sources SET status = 'working', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$s['id']]);
    try {
        [$pages, $chars, $title] = kb_process($s, (int)$s['max_pages']);
        db()->prepare("UPDATE sources SET status = 'ready', error = '', pages = ?, chars = ?, title = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$pages, $chars, mb_substr($title, 0, 250), $s['id']]);
        echo "source {$s['id']}: $pages pages, $chars chars\n";
    } catch (Throwable $e) {
        db()->prepare("UPDATE sources SET status = 'error', error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), $s['id']]);
        echo "source {$s['id']}: " . $e->getMessage() . "\n";
    }
}
// Housekeeping: old rate-limit rows.
db()->exec("DELETE FROM rate_hits WHERE at < UTC_TIMESTAMP() - INTERVAL 1 DAY");
