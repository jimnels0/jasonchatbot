<?php
// api/lib/transcript.php -- a chat as an email (text + HTML). Used by worker.php (automatic transcripts, handoff alerts,
// "email me this chat", replies to visitors who left) and by chats.php ("Email this chat" in the dashboard).
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/files.php';

function tr_place(array $c): string { return implode(', ', array_filter([$c['city'] ?? '', $c['region'] ?? '', $c['country'] ?? ''])) ?: '—'; }

function tr_messages(int $convId): array {
    $st = db()->prepare("SELECT id, role, body, agent_name, file_id, created_at FROM messages WHERE conv_id = ? ORDER BY id");
    $st->execute([$convId]);
    return chat_attach_files($st->fetchAll());
}

function tr_dashboard_link(int $convId): string {
    return 'https://' . ($GLOBALS['site_host'] ?? 'bot.pennybiddeals.com') . "/#/app/chats/$convId";
}

/**
 * Builds [subject, text, html] for a chat. $forVisitor hides the company-side details (IP, page, internal notes).
 * Every value is escaped for the HTML part.
 */
function tr_build(array $t, array $c, array $msgs, bool $forVisitor, string $subject): array {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $who = fn($m) => match ($m['role']) { 'visitor' => $c['name'], 'bot' => $t['name'] . ' (AI)', 'agent' => $m['agent_name'] ?: $t['name'], default => '' };
    $info = $forVisitor ? [] : [
        'Name' => $c['name'], 'Email' => $c['email'] ?: '—', 'Location' => tr_place($c), 'Department' => $c['department'] ?: '—',
        'Topic' => $c['topic'] ?: '—', 'Started' => $c['created_at'] . ' UTC', 'Page' => $c['page'] ?: '—',
    ];
    $text = ''; $html = '';
    foreach ($info as $k => $v) { $text .= "$k: $v\n"; $html .= '<tr><td style="color:#6b7280;padding:2px 12px 2px 0">' . $e($k) . '</td><td><b>' . $e($v) . '</b></td></tr>'; }
    if ($html !== '') { $html = '<table style="font-size:13px;margin-bottom:14px">' . $html . '</table>'; $text .= "\n"; }
    foreach ($msgs as $m) {
        $time = substr((string)$m['created_at'], 11, 5);
        $file = $m['file'] ?? null;
        if ($m['role'] === 'system') {
            $text .= "[$time] -- {$m['body']}\n";
            $html .= '<p style="text-align:center;color:#6b7280;font-size:12px;margin:6px 0">' . $e($m['body']) . '</p>';
            continue;
        }
        $body = $file ? '📎 ' . $file['name'] : $m['body'];
        $text .= "[$time] " . $who($m) . ": $body" . ($file ? " ({$file['url']})" : '') . "\n";
        $bg = $m['role'] === 'visitor' ? '#f3f4f6' : ($m['role'] === 'agent' ? '#ecfdf5' : '#eef2ff');
        $html .= '<div style="margin:6px 0;padding:8px 11px;border-radius:10px;background:' . $bg . '"><div style="font-size:11px;color:#6b7280">'
            . $e($who($m)) . ' · ' . $e($time) . '</div><div style="white-space:pre-wrap">'
            . ($file ? '📎 <a href="' . $e($file['url']) . '">' . $e($file['name']) . '</a>' : $e($m['body'])) . '</div></div>';
    }
    if (!$forVisitor) {
        $link = tr_dashboard_link((int)$c['id']);
        $text .= "\nOpen in the dashboard: $link\n";
        $html .= '<p style="margin-top:16px"><a href="' . $e($link) . '" style="background:' . $e($t['color']) . ';color:#fff;padding:9px 14px;border-radius:8px;text-decoration:none;font-weight:bold">Open in the dashboard</a></p>';
    }
    return [$subject, $text, mail_html($subject, $html, $t['color'] ?: '#4f46e5')];
}

/** Where company-side emails go: the company's list, else the owner. */
function tr_company_recipients(array $t): array {
    $list = array_values(array_filter(array_map('trim', explode(',', (string)$t['notify_emails'])), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
    if ($list) return $list;
    $st = db()->prepare("SELECT email FROM users WHERE tenant_id = ? AND role = 'owner' AND status = 'active'");
    $st->execute([$t['id']]);
    return array_column($st->fetchAll(), 'email');
}
