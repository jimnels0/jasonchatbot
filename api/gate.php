<?php
// api/gate.php -- outside-USA gate for the dashboard site (James's rule for every public site). GET -> {blocked}.
// Country comes from Cloudflare's CF-IPCountry header (no lookup needed). Fails OPEN when unknown. Search-engine bots
// and signed-in users are never blocked. Blocked visits are logged once per address per hour as "BLOCKED: outside USA"
// (Owner console > Visitors). The widget (customers' visitors) is NOT gated.
// Known limit: the browser check hides the pages; it does not stop direct API calls (auth.php also refuses non-US sign-ups).
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
api_headers('GET, OPTIONS');

if (!empty($GLOBALS['geo_block_disabled'])) out(['blocked' => false, 'demo_key' => (string)($GLOBALS['demo_tenant_key'] ?? '')]);
$cc = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
$bot = preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$blocked = $cc !== '' && $cc !== 'US' && $cc !== 'XX' && $cc !== 'T1' && !$bot && !auth_user();
if ($blocked) {
    $ip = client_ip();
    $st = db()->prepare("SELECT 1 FROM visits WHERE kind = 'blocked' AND ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
    $st->execute([$ip]);
    if (!$st->fetch()) {
        db()->prepare("INSERT INTO visits (kind, ip, country, path, note, created_at) VALUES ('blocked', ?, ?, ?, 'BLOCKED: outside USA', UTC_TIMESTAMP())")
            ->execute([$ip, $cc, mb_substr((string)($_GET['p'] ?? ''), 0, 255)]);
    }
}
// demo_key: the company whose bot answers on the landing page (jcb-config.php $demo_tenant_key; empty = no demo bubble).
out(['blocked' => $blocked, 'demo_key' => (string)($GLOBALS['demo_tenant_key'] ?? '')]);
