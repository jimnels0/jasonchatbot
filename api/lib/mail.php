<?php
// api/lib/mail.php -- outgoing email over SMTP (port 465 = TLS from the start, 587 = STARTTLS). Used only by worker.php,
// so a slow mail server never slows a chat. Config in /home/jcbot/jcb-config.php (scripts/setmail.sh writes it):
//   $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_from (address), $smtp_from_name
// No config = mail is off; mail_send() returns false and the job stays queued.

function mail_ready(): bool { return ($GLOBALS['smtp_host'] ?? '') !== '' && ($GLOBALS['smtp_user'] ?? '') !== '' && ($GLOBALS['smtp_pass'] ?? '') !== ''; }

/** Header-safe text (no line breaks: stops header injection). */
function mail_clean(string $s): string { return trim(preg_replace('/[\r\n]+/', ' ', $s)); }

function mail_hdr(string $s): string { return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }

/** Builds the full message (headers + multipart text/html body). Pure, so tests can check it. */
function mail_build(string $from, string $fromName, string $to, string $subject, string $text, string $html, string $replyTo = ''): string {
    $b = 'jcb' . bin2hex(random_bytes(8));
    $h = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: ' . mail_hdr(mail_clean($fromName)) . ' <' . mail_clean($from) . '>',
        'To: <' . mail_clean($to) . '>',
        'Subject: ' . mail_hdr(mail_clean($subject)),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$b\"",
    ];
    if ($replyTo !== '') $h[] = 'Reply-To: <' . mail_clean($replyTo) . '>';
    $part = fn(string $type, string $body) => "--$b\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n";
    return implode("\r\n", $h) . "\r\n\r\n" . $part('text/plain', $text) . $part('text/html', $html) . "--$b--\r\n";
}

/** Sends one email. true = the server accepted it. Never throws; logs the reason. */
function mail_send(string $to, string $subject, string $text, string $html, string $replyTo = '', string $fromName = ''): bool {
    if (!mail_ready() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $g = $GLOBALS;
    $port = (int)($g['smtp_port'] ?? 465);
    $from = $g['smtp_from'] ?? $g['smtp_user'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $g['smtp_host'] . ":$port", $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { error_log("mail: connect failed: $es"); return false; }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (($l[3] ?? '') === ' ') break; } return $r; };
    $cmd = function (string $c, string $want) use ($fp, $read): bool {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!str_starts_with($r, $want)) { error_log('mail: ' . (str_starts_with($c, 'AUTH') || strlen($c) > 60 ? '[auth]' : $c) . ' -> ' . trim($r)); return false; }
        return true;
    };
    $host = gethostname() ?: 'localhost';
    $ok = $cmd('', '220') && $cmd("EHLO $host", '250');
    if ($ok && $port !== 465) {
        $ok = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd("EHLO $host", '250');
    }
    $ok = $ok && $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($g['smtp_user']), '334') && $cmd(base64_encode($g['smtp_pass']), '235')
        && $cmd('MAIL FROM:<' . mail_clean($from) . '>', '250') && $cmd('RCPT TO:<' . mail_clean($to) . '>', '25') && $cmd('DATA', '354');
    if ($ok) {
        $msg = mail_build($from, $fromName !== '' ? $fromName : ($g['smtp_from_name'] ?? 'Chat'), $to, $subject, $text, $html, $replyTo);
        $msg = preg_replace('/^\./m', '..', $msg);   // dot-stuffing
        $ok = $cmd($msg . "\r\n.", '250');
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

/** Simple branded HTML wrapper. $bodyHtml must already be escaped. */
function mail_html(string $title, string $bodyHtml, string $color = '#4f46e5'): string {
    $t = htmlspecialchars($title, ENT_QUOTES);
    return "<!doctype html><html><body style=\"margin:0;background:#f6f7f9;font-family:Arial,Helvetica,sans-serif;color:#111827\">"
        . "<div style=\"max-width:640px;margin:0 auto;padding:16px\"><div style=\"background:$color;color:#fff;border-radius:12px 12px 0 0;padding:14px 18px;font-weight:bold;font-size:16px\">$t</div>"
        . "<div style=\"background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 12px 12px;padding:18px;font-size:14px;line-height:1.5\">$bodyHtml</div></div></body></html>";
}
