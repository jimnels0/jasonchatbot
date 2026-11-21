<?php
// api/migrate.php -- CLI only: create/upgrade tables (run by scripts/deploy.sh on every deploy).
//   php api/migrate.php                     tables
//   php api/migrate.php --admin EMAIL       also make EMAIL a platform admin (Owner console)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/schema.php';
foreach (SCHEMA as $sql) db()->exec($sql);
$has = db()->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
foreach (UPGRADES as [$tbl, $col, $def]) {
    $has->execute([$tbl, $col]);
    if ((int)$has->fetchColumn()) continue;
    db()->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
    echo "added $tbl.$col\n";
    // Chats that existed before email was built are treated as already emailed / delivered (no flood of old emails).
    if ("$tbl.$col" === 'conversations.emailed_at') db()->exec("UPDATE conversations SET emailed_at = UTC_TIMESTAMP(), alerted_at = UTC_TIMESTAMP()");
    if ("$tbl.$col" === 'messages.delivered') db()->exec("UPDATE messages SET delivered = 1");
}
// sources.kind gained 'doc' (Word / text uploads).
if (!str_contains((string)db()->query("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sources' AND column_name = 'kind'")->fetchColumn(), "'doc'")) {
    db()->exec("ALTER TABLE sources MODIFY kind ENUM('site','url','text','pdf','doc') NOT NULL");
    echo "sources.kind: added doc\n";
}
echo 'tables: ' . db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn() . "\n";
$i = array_search('--admin', $argv, true);
if ($i !== false && isset($argv[$i + 1])) {
    $st = db()->prepare("UPDATE users SET is_platform_admin = 1 WHERE email = ?");
    $st->execute([strtolower($argv[$i + 1])]);
    echo $st->rowCount() ? "platform admin: {$argv[$i + 1]}\n" : "no account with that email yet\n";
}
