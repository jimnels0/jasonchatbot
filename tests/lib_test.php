<?php
// php tests/lib_test.php -- pure checks (no database, no network except DNS for the SSRF guard).
// Needs mbstring (run on the server: ssh jcb "php ~/tests/lib_test.php" after copying, or any PHP with mbstring).
require __DIR__ . '/../api/lib/format.php';
function db() { throw new RuntimeException('no database in tests'); }
require __DIR__ . '/../api/lib/knowledge.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what) { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $what\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n"; } }

// formatting (James's rule)
eq(fmt_name('jane doe'), 'Jane Doe', 'name lower');
eq(fmt_name('JANE DOE'), 'Jane Doe', 'name upper');
eq(fmt_name('Ronald McDonald LLC'), 'Ronald McDonald LLC', 'name keeps typed capitals');
eq(fmt_email(' Jane@Example.COM '), 'jane@example.com', 'email');
eq(fmt_email('nope'), '', 'bad email');
eq(fmt_url('acme.com/about'), 'https://acme.com/about', 'url adds https');
eq(fmt_url('javascript:alert(1)'), '', 'url rejects js');
eq(fmt_domain('https://www.Acme.com/x?y'), 'acme.com', 'domain');
eq(fmt_domain('not a domain'), '', 'bad domain');
eq(fmt_color('#AbCdEf'), '#abcdef', 'color');
eq(fmt_color('red'), '#4f46e5', 'bad color');

// links
eq(kb_abs('https://a.com/x/y.html', '../z'), 'https://a.com/z', 'abs ..');
eq(kb_abs('https://a.com/x/', 'p?q=1#f'), 'https://a.com/x/p?q=1', 'abs relative + query, drops #');
eq(kb_abs('https://a.com/x', 'mailto:me@a.com'), '', 'abs mailto');
eq(kb_abs('https://a.com/x', '//cdn.a.com/f'), 'https://cdn.a.com/f', 'abs protocol-relative');
eq(kb_same_site('https://www.a.com/x', 'https://a.com/'), true, 'same site www');
eq(kb_same_site('https://b.com', 'https://a.com'), false, 'other site');
eq(kb_skip('https://a.com/manual.pdf'), true, 'skip pdf link');
eq(kb_skip('https://a.com/wp-admin/x'), true, 'skip admin');
eq(kb_skip('https://a.com/pricing'), false, 'keep page');

// SSRF guard: never private/loopback/metadata addresses
eq(kb_public_ip('127.0.0.1'), '', 'loopback blocked');
eq(kb_public_ip('169.254.169.254'), '', 'metadata blocked');
eq(kb_public_ip('10.1.2.3'), '', 'private blocked');
eq(kb_public_ip('8.8.8.8'), '8.8.8.8', 'public ok');
eq(kb_fetch('http://127.0.0.1/'), null, 'fetch loopback refused');
eq(kb_fetch('file:///etc/passwd'), null, 'fetch file refused');
eq(kb_fetch('https://example.com:8443/'), null, 'fetch odd port refused');

// html -> text
[$t, $x] = kb_html_text('<html><head><title>Hours &amp; Info</title><style>.a{}</style></head><body><nav>Menu</nav><h1>Hours</h1><p>Open 9&nbsp;to 5.</p><script>evil()</script><footer>123 Main St</footer></body></html>');
eq($t, 'Hours & Info', 'title');
eq(str_contains($x, 'Open 9 to 5.') && str_contains($x, '123 Main St') && !str_contains($x, 'evil') && !str_contains($x, 'Menu'), true, 'body text kept, script/nav dropped');

// chunks
$long = str_repeat('Sentence number one is here. ', 200);
$ch = kb_chunk("Intro paragraph that is long enough.\n\n" . $long);
eq(count($ch) >= 4, true, 'long text split');
eq(max(array_map('mb_strlen', $ch)) <= KB_CHUNK, true, 'chunks within size');
eq(kb_chunk('tiny'), [], 'too-short text dropped');

eq(kb_query('How do I return a TV?'), 'how return', 'query keeps 3+ letter words');

echo $fail ? "$fail of $n FAILED\n" : "$n passed\n";
exit($fail ? 1 : 0);
