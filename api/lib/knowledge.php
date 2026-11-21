<?php
// api/lib/knowledge.php -- what the bot knows about each company: website pages, pasted text, PDFs -> searchable chunks.
// Search is MySQL FULLTEXT per tenant.
// shortcut: keyword search, no embeddings; move to vector search (jasonchatbot.md) when answers miss on wording.
const KB_CHUNK = 1200;            // characters per chunk
const KB_MAX_PAGE_BYTES = 2000000;
const KB_MIN_PAGE_TEXT = 150;     // characters; less than this is a menu/shell page, not knowledge
const KB_UA = 'JasonChatbotCrawler/0.1 (+https://bot.pennybiddeals.com)';

function jcb_home(): string { return dirname(__DIR__, 4); }   // /home/jcbot on the server

/** Plain text -> chunks of about KB_CHUNK characters, split on paragraphs, then sentences, then hard. */
function kb_chunk(string $text): array {
    $text = trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $text)));
    $paras = preg_split("/\n\s*\n+|\f/u", $text) ?: [];
    $out = []; $cur = '';
    foreach ($paras as $p) {
        $p = trim(preg_replace("/\s*\n\s*/u", "\n", $p));
        if ($p === '') continue;
        while (mb_strlen($p) > KB_CHUNK) {   // one huge paragraph: cut at the last sentence end before the limit
            $cut = mb_substr($p, 0, KB_CHUNK);
            $pos = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, "\n") ?: 0);
            $at = $pos > KB_CHUNK / 2 ? $pos + 1 : KB_CHUNK;
            if ($cur !== '') { $out[] = $cur; $cur = ''; }
            $out[] = trim(mb_substr($p, 0, $at));
            $p = trim(mb_substr($p, $at));
        }
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($p) + 2 > KB_CHUNK) { $out[] = $cur; $cur = ''; }
        $cur = $cur === '' ? $p : "$cur\n\n$p";
    }
    if ($cur !== '') $out[] = $cur;
    return array_values(array_filter($out, fn($c) => mb_strlen($c) >= 20));
}

/** HTML -> [title, readable text]. Scripts, styles, menus and cookie banners are dropped; footers stay (addresses, hours). */
function kb_html_text(string $html): array {
    $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    // Drop <head> (the title is kept above) so a page's title isn't counted as its text. <noscript> is KEPT: on
    // JavaScript-built sites it is often the only readable text.
    $h = preg_replace('#<head\b.*?</head>#is', ' ', $html);
    $h = preg_replace('#<(script|style|svg|template|iframe|nav)\b.*?</\1>#is', ' ', $h);
    $h = preg_replace('#<!--.*?-->#s', ' ', $h);
    $h = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article|/header|/footer|/table|/ul|/ol|/blockquote)\b[^>]*>#i', "\n", $h);
    $h = preg_replace('#<(h[1-6])\b[^>]*>#i', "\n\n", $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
    $t = preg_replace("/ *\n */u", "\n", $t);
    $t = preg_replace("/\n{3,}/u", "\n\n", $t);
    return [mb_substr($title, 0, 250), trim($t)];
}

/** Only public internet addresses: the crawler must never reach the server itself or private networks (SSRF). */
function kb_public_ip(string $host): string {
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
    }
    return '';
}

/** GET a public http(s) URL, following up to 4 redirects, each one re-checked. [finalUrl, contentType, body] or null. */
function kb_fetch(string $url): ?array {
    for ($hop = 0; $hop < 5; $hop++) {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return null;
        $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) return null;
        $ip = kb_public_ip($p['host']);
        if ($ip === '') return null;
        $ch = curl_init($url);
        $headers = []; $size = 0;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => KB_UA, CURLOPT_RESOLVE => ["{$p['host']}:$port:$ip"], CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) { $headers[] = $line; return strlen($line); },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($c, $dlTotal, $dl) { return $dl > KB_MAX_PAGE_BYTES ? 1 : 0; },
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code >= 300 && $code < 400) {
            $loc = '';
            foreach ($headers as $hl) if (stripos($hl, 'location:') === 0) $loc = trim(substr($hl, 9));
            if ($loc === '') return null;
            $url = kb_abs($url, $loc);
            continue;
        }
        if ($code !== 200 || !is_string($body)) return null;
        return [$url, strtolower($type), $body];
    }
    return null;
}

/** Resolve a link against the page it was found on. */
function kb_abs(string $base, string $href): string {
    $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
    if ($href === '' || $href[0] === '#') return '';
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) return preg_match('#^https?://#i', $href) ? preg_replace('/#.*$/', '', $href) : '';
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) return $b['scheme'] . ':' . preg_replace('/#.*$/', '', $href);
    if ($href[0] === '/') $path = $href;
    else { $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/'); $path = $dir . $href; }
    $path = preg_replace('/#.*$/', '', $path);
    $parts = [];   // collapse ./ and ../
    foreach (explode('/', (string)strtok($path, '?')) as $seg) {
        if ($seg === '..') array_pop($parts); elseif ($seg !== '.') $parts[] = $seg;
    }
    $q = strpos($path, '?') !== false ? substr($path, strpos($path, '?')) : '';
    return $root . implode('/', $parts) . $q;
}

function kb_same_site(string $a, string $b): bool {
    $h = fn($u) => preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
    return $h($a) !== '' && $h($a) === $h($b);
}

/** Pages to read for a whole website: its sitemap if it has one, else links found from the start page. */
function kb_site_urls(string $start, int $max): array {
    $p = parse_url($start);
    $origin = $p['scheme'] . '://' . $p['host'];
    $urls = [];
    $maps = [$origin . '/sitemap.xml'];
    if ($r = kb_fetch($origin . '/robots.txt')) {
        if (preg_match_all('/^\s*sitemap:\s*(\S+)/im', $r[2], $m)) $maps = array_merge($m[1], $maps);
    }
    foreach (array_slice(array_unique($maps), 0, 5) as $sm) {
        $r = kb_fetch($sm);
        if (!$r || !preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $r[2], $m)) continue;
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode($loc, ENT_QUOTES, 'UTF-8');
            if (preg_match('/\.xml(\?|$)/i', $loc) && count($maps) < 8) { $maps[] = $loc; continue; }   // sitemap index (one level)
            if (kb_same_site($loc, $start) && !kb_skip($loc)) $urls[$loc] = true;
            if (count($urls) >= $max) break 2;
        }
    }
    if (!$urls) {   // no sitemap: breadth-first from the start page
        $queue = [$start]; $seen = [$start => true];
        while ($queue && count($urls) < $max) {
            $u = array_shift($queue);
            $r = kb_fetch($u);
            if (!$r || !str_contains($r[1], 'html')) continue;
            $urls[$r[0]] = true;
            if (preg_match_all('#<a\b[^>]*href\s*=\s*["\']([^"\']+)#i', $r[2], $m)) {
                foreach ($m[1] as $href) {
                    $a = kb_abs($r[0], $href);
                    if ($a !== '' && !isset($seen[$a]) && kb_same_site($a, $start) && !kb_skip($a)) { $seen[$a] = true; $queue[] = $a; }
                }
            }
        }
    }
    return array_slice(array_keys($urls), 0, $max);
}

function kb_skip(string $u): bool {
    return (bool)preg_match('#\.(pdf|jpe?g|png|gif|webp|svg|zip|mp4|mp3|docx?|xlsx?|css|js|ico)(\?|$)|/(wp-admin|wp-login|cart|checkout|login|signin|account)\b#i', $u);
}

/** Save text as chunks for a source. Returns the number of characters kept. */
function kb_store(int $tid, int $sid, string $title, string $url, string $text): int {
    $ins = db()->prepare("INSERT INTO chunks (tenant_id, source_id, title, url, content) VALUES (?,?,?,?,?)");
    $n = 0;
    foreach (kb_chunk($text) as $c) { $ins->execute([$tid, $sid, mb_substr($title, 0, 250), mb_substr($url, 0, 1000), $c]); $n += mb_strlen($c); }
    return $n;
}

/** Process one queued source (worker.php). Throws on failure; the caller records the error. */
function kb_process(array $s, int $maxPages): array {
    $tid = (int)$s['tenant_id']; $sid = (int)$s['id'];
    db()->prepare("DELETE FROM chunks WHERE source_id = ?")->execute([$sid]);
    $pages = 0; $chars = 0; $title = $s['title'];
    if ($s['kind'] === 'pdf') {
        $file = jcb_home() . '/uploads/' . basename($s['file']);
        if (!is_file($file)) throw new RuntimeException('The uploaded file is missing.');
        if (!trim((string)shell_exec('command -v pdftotext'))) throw new RuntimeException('PDF reading is not installed on the server yet.');
        $text = (string)shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($file) . ' - 2>/dev/null');
        if (trim($text) === '') throw new RuntimeException('No text found in this PDF (scanned pages are not supported yet).');
        $pages = max(1, substr_count($text, "\f"));
        $chars = kb_store($tid, $sid, $title, '', $text);
    } else {
        $urls = $s['kind'] === 'site' ? kb_site_urls($s['url'], $maxPages) : [$s['url']];
        if (!$urls) throw new RuntimeException('Could not read that website (no pages found).');
        $seen = []; $thin = 0;
        foreach ($urls as $u) {
            $r = kb_fetch($u);
            if (!$r || !str_contains($r[1], 'html')) continue;
            [$t, $text] = kb_html_text($r[2]);
            if (mb_strlen($text) < KB_MIN_PAGE_TEXT) { $thin++; continue; }
            $h = md5(preg_replace('/\s+/u', ' ', $text));   // many sites repeat the same shell on every address
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            $chars += kb_store($tid, $sid, $t !== '' ? $t : $r[0], $r[0], $text);
            $pages++;
            if ($title === '' && $t !== '') $title = $t;
        }
        // shortcut: no headless browser; JavaScript-built pages are reported, not rendered. Add rendering if many customers hit this.
        if ($pages === 0) throw new RuntimeException($thin
            ? 'These pages have almost no readable text. The website is probably built with JavaScript, so its text only appears in a browser. Paste your FAQ or About text, or upload a PDF, instead.'
            : 'Could not read any text from that address.');
    }
    return [$pages, $chars, $title];
}

/** Words worth searching for (FULLTEXT ignores words under 3 letters anyway). */
function kb_query(string $q): string {
    $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q, 'UTF-8')) ?: [];
    $w = array_values(array_unique(array_filter($w, fn($x) => mb_strlen($x) >= 3)));
    return implode(' ', array_slice($w, 0, 25));
}

function kb_search(int $tid, string $q, int $limit = 6): array {
    $q = kb_query($q);
    if ($q === '') return [];
    $st = db()->prepare("SELECT id, title, url, content, MATCH(title, content) AGAINST(? IN NATURAL LANGUAGE MODE) AS score
                         FROM chunks WHERE tenant_id = ? AND MATCH(title, content) AGAINST(? IN NATURAL LANGUAGE MODE)
                         ORDER BY score DESC LIMIT $limit");
    $st->execute([$q, $tid, $q]);
    return $st->fetchAll();
}

function qa_search(int $tid, string $q, int $limit = 4): array {
    $q = kb_query($q);
    if ($q === '') return [];
    $st = db()->prepare("SELECT id, question, answer FROM qa WHERE tenant_id = ? AND MATCH(question, answer) AGAINST(? IN NATURAL LANGUAGE MODE)
                         ORDER BY MATCH(question, answer) AGAINST(? IN NATURAL LANGUAGE MODE) DESC LIMIT $limit");
    $st->execute([$tid, $q, $q]);
    return $st->fetchAll();
}
