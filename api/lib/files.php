<?php
// api/lib/files.php -- files sent inside a chat (visitor or team). Stored OUTSIDE the web root in /home/jcbot/chatfiles,
// served only by api/files.php with an unguessable key. Type is checked from the file's real bytes, not its name.
const CHAT_FILE_MAX = 10 * 1024 * 1024;
const CHAT_FILE_TYPES = [   // real type (finfo) => allowed extensions
    'image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/gif' => ['gif'], 'image/webp' => ['webp'],
    'application/pdf' => ['pdf'], 'text/plain' => ['txt', 'csv', 'log', 'md'], 'text/csv' => ['csv'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    'application/zip' => ['docx', 'xlsx'],   // some finfo builds report Office files as plain zip
    'application/msword' => ['doc'], 'application/vnd.ms-excel' => ['xls'], 'application/CDFV2' => ['doc', 'xls'],
];

function chat_files_dir(): string { return dirname(__DIR__, 4) . '/chatfiles'; }

/** '' = allowed, else the reason. Pure apart from reading the file's first bytes. */
function chat_file_problem(string $tmp, string $name, int $size, string $realType): string {
    if ($size <= 0) return 'That file is empty.';
    if ($size > CHAT_FILE_MAX) return 'That file is too big (10 MB maximum).';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ok = CHAT_FILE_TYPES[$realType] ?? null;
    if (!$ok || !in_array($ext, $ok, true)) return 'That kind of file cannot be sent. Use a picture, PDF, Word, Excel or text file.';
    return '';
}

/** Clean display name: no path, no control characters, at most 200 characters. */
function chat_file_name(string $name): string {
    $n = preg_replace('/[\x00-\x1f\x7f"\\\\\/]+/u', '_', basename(str_replace('\\', '/', $name)));
    return mb_substr(trim($n) !== '' ? trim($n) : 'file', 0, 200);
}

/** Saves an uploaded $_FILES entry for a chat. Returns the files row (id, name, mime, size, url). Calls fail() on a bad file. */
function chat_file_save(array $f, int $tid, int $convId): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail(400, 'The upload did not finish. Please try again.');
    $real = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $name = chat_file_name((string)$f['name']);
    $why = chat_file_problem($f['tmp_name'], $name, (int)$f['size'], $real);
    if ($why !== '') fail(400, $why);
    $dir = chat_files_dir();
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $path = "t{$tid}-" . bin2hex(random_bytes(10));
    if (!move_uploaded_file($f['tmp_name'], "$dir/$path")) fail(500, 'Could not save the file.');
    $key = bin2hex(random_bytes(16));
    db()->prepare("INSERT INTO files (tenant_id, conv_id, dl_key, name, mime, size, path, created_at) VALUES (?,?,?,?,?,?,?, UTC_TIMESTAMP())")
        ->execute([$tid, $convId, $key, $name, $real, (int)$f['size'], $path]);
    $id = (int)db()->lastInsertId();
    return ['id' => $id, 'name' => $name, 'mime' => $real, 'size' => (int)$f['size'], 'url' => chat_file_url($id, $key)];
}

function chat_file_url(int $id, string $key): string {
    $host = $_SERVER['HTTP_HOST'] ?? ($GLOBALS['site_host'] ?? 'bot.pennybiddeals.com');
    return "https://$host/api/files.php?f=$id&k=$key";
}

/** Attaches file info to message rows that have file_id (one query). */
function chat_attach_files(array $msgs): array {
    $ids = array_values(array_filter(array_map(fn($m) => (int)($m['file_id'] ?? 0), $msgs)));
    $by = [];
    if ($ids) {
        $st = db()->prepare("SELECT id, dl_key, name, mime, size FROM files WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $by[(int)$r['id']] = ['name' => $r['name'], 'mime' => $r['mime'], 'size' => (int)$r['size'], 'url' => chat_file_url((int)$r['id'], $r['dl_key'])];
    }
    foreach ($msgs as &$m) { $m['file'] = $by[(int)($m['file_id'] ?? 0)] ?? null; unset($m['file_id']); }
    return $msgs;
}
