<?php
// api/migrate.php -- CLI only: create/upgrade tables (run by scripts/deploy.sh on every deploy).
//   php api/migrate.php                     tables
//   php api/migrate.php --admin EMAIL       also make EMAIL a platform admin (Owner console)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/schema.php';
foreach (SCHEMA as $sql) db()->exec($sql);
echo 'tables: ' . db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn() . "\n";
$i = array_search('--admin', $argv, true);
if ($i !== false && isset($argv[$i + 1])) {
    $st = db()->prepare("UPDATE users SET is_platform_admin = 1 WHERE email = ?");
    $st->execute([strtolower($argv[$i + 1])]);
    echo $st->rowCount() ? "platform admin: {$argv[$i + 1]}\n" : "no account with that email yet\n";
}
