<?php
// api/sources.php -- what the bot learns from (owner only).
//   GET -> {sources, qa_count, chunks, ai_ready}
//   POST {kind:'site'|'url', url} (read by worker.php within a minute)   POST {kind:'text', title, text} (ready at once)
//   POST multipart file=PDF / Word (.docx) / text (.txt .md .csv) (worker reads it)   POST ?id=N&a=refresh (read again)   DELETE ?id=N
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/format.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/knowledge.php';
require __DIR__ . '/lib/ai.php';
api_headers();
$u = require_owner();
$tid = $u['tenant_id'];
const MAX_SOURCES = 60;
const MAX_PDF_BYTES = 15 * 1024 * 1024;
const MAX_TEXT = 100000;

function src_of(int $tid): array {
    $st = db()->prepare("SELECT * FROM sources WHERE id = ? AND tenant_id = ?");
    $st->execute([(int)($_GET['id'] ?? 0), $tid]);
    $s = $st->fetch();
    if (!$s) fail(404, 'Source not found.');
    return $s;
}

if (method() === 'GET') {
    $st = db()->prepare("SELECT s.id, s.kind, s.title, s.url, s.status, s.error, s.pages, s.chars, s.created_at, s.updated_at,
                         (SELECT COUNT(*) FROM chunks c WHERE c.source_id = s.id) AS chunks FROM sources s WHERE s.tenant_id = ? ORDER BY s.id DESC");
    $st->execute([$tid]);
    $n = fn($sql) => (int)db()->query($sql)->fetchColumn();
    out(['sources' => $st->fetchAll(), 'qa_count' => $n("SELECT COUNT(*) FROM qa WHERE tenant_id = $tid"),
         'chunks' => $n("SELECT COUNT(*) FROM chunks WHERE tenant_id = $tid"), 'ai_ready' => ai_configured()]);
}

if (method() === 'DELETE') {
    $s = src_of($tid);
    db()->prepare("DELETE FROM chunks WHERE source_id = ?")->execute([$s['id']]);
    db()->prepare("DELETE FROM sources WHERE id = ?")->execute([$s['id']]);
    if ($s['file'] !== '') @unlink(jcb_home() . '/uploads/' . basename($s['file']));
    out(['ok' => true]);
}

if (method() !== 'POST') fail(405, 'Method not allowed');

if (($_GET['a'] ?? '') === 'refresh') {
    $s = src_of($tid);
    if ($s['kind'] === 'text') fail(400, 'Pasted text does not need refreshing.');
    db()->prepare("UPDATE sources SET status = 'queued', error = '', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$s['id']]);
    out(['ok' => true]);
}

$n = db()->prepare("SELECT COUNT(*) FROM sources WHERE tenant_id = ?");
$n->execute([$tid]);
if ((int)$n->fetchColumn() >= MAX_SOURCES) fail(400, 'You have reached the limit of ' . MAX_SOURCES . ' sources. Remove one first.');

if (!empty($_FILES['file'])) {   // document upload: the file's real bytes decide the type, not its name
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) fail(400, 'The upload did not finish. Please try again.');
    if ($f['size'] > MAX_PDF_BYTES) fail(400, 'That file is too big (15 MB maximum).');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
    $real = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if ($head === '%PDF-') { $kind = 'pdf'; $ext = 'pdf'; }
    elseif ($ext === 'docx' && str_starts_with($head, "PK\x03\x04")) $kind = 'doc';
    elseif (in_array($ext, ['txt', 'md', 'csv'], true) && str_starts_with($real, 'text/')) $kind = 'doc';
    else fail(400, 'Upload a PDF, a Word file (.docx) or a text file (.txt, .md, .csv).');
    $dir = jcb_home() . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $name = "t{$tid}-" . rand_key(8) . ".$ext";
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail(500, 'Could not save the file.');
    $title = mb_substr(preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename((string)$f['name'])), 0, 250);
    db()->prepare("INSERT INTO sources (tenant_id, kind, title, file, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$tid, $kind, $title, $name]);
    out(['ok' => true]);
}

$b = body();
$kind = (string)($b['kind'] ?? '');
if ($kind === 'site' || $kind === 'url') {
    $url = fmt_url($b['url'] ?? '');
    if ($url === '') fail(400, 'Please enter a website address, for example yourcompany.com.');
    db()->prepare("INSERT INTO sources (tenant_id, kind, url, title, created_at, updated_at) VALUES (?,?,?,?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([$tid, $kind, $url, mb_substr((string)parse_url($url, PHP_URL_HOST) . (string)parse_url($url, PHP_URL_PATH), 0, 250)]);
    out(['ok' => true]);
}
if ($kind === 'text') {
    $title = str_in($b, 'title', 250);
    $text = mb_substr(trim((string)($b['text'] ?? '')), 0, MAX_TEXT);
    if ($title === '' || mb_strlen($text) < 20) fail(400, 'Give it a title and at least a sentence of text.');
    db()->prepare("INSERT INTO sources (tenant_id, kind, title, status, created_at, updated_at) VALUES (?, 'text', ?, 'ready', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$tid, $title]);
    $sid = (int)db()->lastInsertId();
    $chars = kb_store($tid, $sid, $title, '', $text);
    db()->prepare("UPDATE sources SET chars = ?, pages = 1 WHERE id = ?")->execute([$chars, $sid]);
    out(['ok' => true]);
}
fail(400, 'Unknown source type.');
