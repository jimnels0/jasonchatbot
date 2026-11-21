<?php
// api/files.php -- downloads a chat file: GET ?f=ID&k=KEY (the 32-character key is in the link; without it, 404).
// Pictures show in the browser; everything else downloads. nosniff + sandbox so a file can never run as a page here.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/files.php';
$st = db()->prepare("SELECT f.*, t.status FROM files f JOIN tenants t ON t.id = f.tenant_id WHERE f.id = ?");
$st->execute([(int)($_GET['f'] ?? 0)]);
$f = $st->fetch();
$path = $f ? chat_files_dir() . '/' . basename($f['path']) : '';
if (!$f || !hash_equals($f['dl_key'], (string)($_GET['k'] ?? '')) || $f['status'] !== 'active' || !is_file($path)) {
    http_response_code(404); header('Content-Type: text/plain'); echo 'File not found.'; exit;
}
$inline = str_starts_with($f['mime'], 'image/');
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . preg_replace('/[^\x20-\x7e]|"/', '_', $f['name']) . "\"; filename*=UTF-8''" . rawurlencode($f['name']));
readfile($path);
