<?php
// api/lib/core.php -- loaded by every endpoint: config, database, JSON + CORS helpers, request input.
// Config lives OUTSIDE the web root: /home/jcbot/jcb-config.php on jasonvps (jcb_setup.sh), never in git.
// Override for local runs: env JCB_CONFIG=/path/to/config.php.
declare(strict_types=1);
date_default_timezone_set('UTC');

$cfgFile = getenv('JCB_CONFIG') ?: dirname(__DIR__, 4) . '/jcb-config.php';
if (!is_file($cfgFile)) { http_response_code(500); header('Content-Type: application/json'); echo '{"message":"Server not configured."}'; exit; }
require $cfgFile;

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO("mysql:host={$GLOBALS['db_host']};dbname={$GLOBALS['db_name']};charset=utf8mb4", $GLOBALS['db_user'], $GLOBALS['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** Dashboard endpoints: same-site only (the SPA is served from this host). The widget endpoint sets its own CORS. */
function api_headers(string $methods = 'GET, POST, PUT, DELETE, OPTIONS'): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { header("Access-Control-Allow-Methods: $methods"); http_response_code(204); exit; }
}

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(int $code, string $msg, array $extra = []): void { out(['message' => $msg] + $extra, $code); }

function body(): array {
    static $b = null;
    if ($b === null) { $b = json_decode((string)file_get_contents('php://input'), true); if (!is_array($b)) $b = []; }
    return $b;
}
function str_in(array $a, string $k, int $max = 255): string { return mb_substr(trim((string)($a[$k] ?? '')), 0, $max); }
function method(): string { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }
function req_lang(): string { return (($_GET['lang'] ?? '') === 'es') ? 'es' : 'en'; }

function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) { $c = trim(explode(',', (string)$_SERVER[$h])[0]); if (filter_var($c, FILTER_VALIDATE_IP)) return $c; }
    }
    return '';
}

/** [city, region, country] for an IP: cached in geo_cache, else one quick ip-api call (1 s), '' on failure. */
function geo(string $ip): array {
    if ($ip === '' || preg_match('/^(127\.|10\.|192\.168\.|::1|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) return ['', '', ''];
    $st = db()->prepare("SELECT city, region, country FROM geo_cache WHERE ip = ? AND at > UTC_TIMESTAMP() - INTERVAL 30 DAY");
    $st->execute([$ip]);
    if ($r = $st->fetch()) return [$r['city'], $r['region'], $r['country']];
    $ch = curl_init('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,city,regionName,country');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1]);
    $g = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (($g['status'] ?? '') !== 'success') return ['', '', ''];
    $row = [mb_substr((string)$g['city'], 0, 100), mb_substr((string)$g['regionName'], 0, 100), mb_substr((string)$g['country'], 0, 100)];
    db()->prepare("REPLACE INTO geo_cache (ip, city, region, country, at) VALUES (?,?,?,?, UTC_TIMESTAMP())")->execute(array_merge([$ip], $row));
    return $row;
}

/** Simple sliding-window limit kept in the database: true = allowed (and counted). */
function rate_ok(string $key, int $limit, int $seconds): bool {
    $pdo = db();
    $pdo->prepare("DELETE FROM rate_hits WHERE k = ? AND at < UTC_TIMESTAMP() - INTERVAL ? SECOND")->execute([$key, $seconds]);
    $st = $pdo->prepare("SELECT COUNT(*) FROM rate_hits WHERE k = ?");
    $st->execute([$key]);
    if ((int)$st->fetchColumn() >= $limit) return false;
    $pdo->prepare("INSERT INTO rate_hits (k, at) VALUES (?, UTC_TIMESTAMP())")->execute([$key]);
    return true;
}

function rand_key(int $bytes = 12): string { return bin2hex(random_bytes($bytes)); }
