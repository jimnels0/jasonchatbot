<?php
// api/worker.php -- background jobs, run every minute by cron (/etc/cron.d/jasonchatbot-worker). CLI only (nginx also
// refuses it). Reads queued websites and documents into the knowledge base, then sends the emails (see mail_jobs()).
// One worker at a time (MySQL lock).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/knowledge.php';
require __DIR__ . '/lib/transcript.php';
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
// Chats idle for CHAT_IDLE_MINUTES close by themselves (the visitor's next message starts a new chat); the transcript job
// below then emails them to the company.
const CHAT_IDLE_MINUTES = 30;
$n = db()->exec("UPDATE conversations SET status = 'closed' WHERE status = 'open' AND last_at < UTC_TIMESTAMP() - INTERVAL " . CHAT_IDLE_MINUTES . " MINUTE");
if ($n) echo "closed idle chats: $n\n";
mail_jobs();

/**
 * Every email the product sends, retried each minute until the server accepts it (each job gives up after a day):
 *  1. alert the team when a visitor asked for a person and nobody picked it up within a minute
 *  2. the visitor asked "email me this chat"
 *  3. a team reply the visitor never saw (they left): emailed to them
 *  4. the full transcript of every finished chat (idle 30 min or closed) to the company, if the company turned it on
 */
function mail_jobs(): void {
    if (!mail_ready()) return;
    $tenant = function (int $id): array { static $c = []; if (!isset($c[$id])) { $st = db()->prepare("SELECT * FROM tenants WHERE id = ?"); $st->execute([$id]); $c[$id] = $st->fetch(); } return $c[$id]; };
    $sent = 0;

    // 1. A person was asked for.
    foreach (db()->query("SELECT c.* FROM conversations c JOIN tenants t ON t.id = c.tenant_id
        WHERE c.handoff_at IS NOT NULL AND c.alerted_at IS NULL AND c.mode = 'bot' AND c.status = 'open' AND t.status = 'active' AND t.email_alerts = 1
          AND c.handoff_at < UTC_TIMESTAMP() - INTERVAL 1 MINUTE AND c.handoff_at > UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 20")->fetchAll() as $c) {
        $t = $tenant((int)$c['tenant_id']);
        [$sub, $txt, $html] = tr_build($t, $c, tr_messages((int)$c['id']), false, "{$c['name']} is waiting for a person ({$t['name']} chat)");
        $ok = false;
        foreach (tr_company_recipients($t) as $to) $ok = mail_send($to, $sub, $txt, $html, $c['email'], $t['name'] . ' Chat') || $ok;
        if ($ok) { db()->prepare("UPDATE conversations SET alerted_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]); $sent++; }
    }

    // 2. "Email me this chat".
    foreach (db()->query("SELECT * FROM conversations WHERE visitor_mail_at IS NOT NULL AND email <> '' AND visitor_mail_at > UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 20")->fetchAll() as $c) {
        $t = $tenant((int)$c['tenant_id']);
        $es = $c['lang'] === 'es';
        [$sub, $txt, $html] = tr_build($t, $c, tr_messages((int)$c['id']), true, $es ? "Su chat con {$t['name']}" : "Your chat with {$t['name']}");
        if (mail_send($c['email'], $sub, $txt, $html, tr_company_recipients($t)[0] ?? '', $t['name'])) {
            db()->prepare("UPDATE conversations SET visitor_mail_at = NULL WHERE id = ?")->execute([$c['id']]); $sent++;
        }
    }

    // 3. Team replies the visitor did not see (widget closed for 2+ minutes).
    foreach (db()->query("SELECT DISTINCT m.conv_id FROM messages m WHERE m.role = 'agent' AND m.delivered = 0
        AND m.created_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE AND m.created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 20")->fetchAll() as $r) {
        $st = db()->prepare("SELECT * FROM conversations WHERE id = ?");
        $st->execute([$r['conv_id']]);
        $c = $st->fetch();
        $st = db()->prepare("SELECT id, role, body, agent_name, file_id, created_at FROM messages WHERE conv_id = ? AND role = 'agent' AND delivered = 0 ORDER BY id");
        $st->execute([$r['conv_id']]);
        $msgs = chat_attach_files($st->fetchAll());
        $last = (int)end($msgs)['id'];
        $mark = fn() => db()->prepare("UPDATE messages SET delivered = 1 WHERE conv_id = ? AND role = 'agent' AND id <= ?")->execute([$r['conv_id'], $last]);
        if (!$c || $c['email'] === '') { $mark(); continue; }   // no address: nothing to do
        $t = $tenant((int)$c['tenant_id']);
        $es = $c['lang'] === 'es';
        [$sub, $txt, $html] = tr_build($t, $c, $msgs, true, $es ? "Respuesta de {$t['name']}" : "A reply from {$t['name']}");
        if (mail_send($c['email'], $sub, $txt, $html, tr_company_recipients($t)[0] ?? '', $t['name'])) { $mark(); $sent++; }
    }

    // 4. Transcripts of finished chats.
    foreach (db()->query("SELECT c.* FROM conversations c JOIN tenants t ON t.id = c.tenant_id
        WHERE c.emailed_at IS NULL AND t.email_transcripts = 1 AND t.status = 'active' AND c.last_at > UTC_TIMESTAMP() - INTERVAL 1 DAY
          AND c.status = 'closed'
          AND EXISTS (SELECT 1 FROM messages m WHERE m.conv_id = c.id AND m.role = 'visitor') LIMIT 30")->fetchAll() as $c) {
        $t = $tenant((int)$c['tenant_id']);
        [$sub, $txt, $html] = tr_build($t, $c, tr_messages((int)$c['id']), false, "Chat transcript: {$c['name']}" . ($c['topic'] ? " ({$c['topic']})" : ''));
        $ok = false;
        foreach (tr_company_recipients($t) as $to) $ok = mail_send($to, $sub, $txt, $html, $c['email'], $t['name'] . ' Chat') || $ok;
        if ($ok) { db()->prepare("UPDATE conversations SET emailed_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$c['id']]); $sent++; }
    }
    if ($sent) echo "emails sent: $sent\n";
}

// Housekeeping: old rate-limit rows.
db()->exec("DELETE FROM rate_hits WHERE at < UTC_TIMESTAMP() - INTERVAL 1 DAY");
