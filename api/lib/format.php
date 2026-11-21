<?php
// api/lib/format.php -- server-side normalizers (James's rule: format on the server too, never trust the browser).
// Copied from jasonpenny api/format_lib.php; the browser twin is web/src/format.ts.

// Names / companies / addresses: first letter of every word capitalized; capitals the person typed survive (McDonald, LLC);
// ALL CAPS or all lowercase becomes Title Case.
function fmt_name($s): string {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    if ($s === '') return '';
    if (preg_match('/\p{L}/u', $s) && ($s === mb_strtoupper($s, 'UTF-8') || $s === mb_strtolower($s, 'UTF-8'))) $s = mb_strtolower($s, 'UTF-8');
    return preg_replace_callback('/(^|[\s\-\/(])(\p{L})/u', fn($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'), $s);
}

function fmt_email($s): string {
    $e = mb_strtolower(trim((string)$s), 'UTF-8');
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? mb_substr($e, 0, 190) : '';
}

// A website a customer typed ("acme.com", "https://www.acme.com/about") -> "https://acme.com/about" style URL, or ''.
function fmt_url($s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (!preg_match('#^https?://#i', $s)) $s = 'https://' . $s;
    $p = parse_url($s);
    if (!$p || empty($p['host']) || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $p['host'])) return '';
    return mb_substr($s, 0, 1000);
}

// "https://www.Acme.com/x" or "acme.com" -> "acme.com" (allowed-domain list entries).
function fmt_domain($s): string {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('#^https?://#', '', $s);
    $s = preg_replace('#[/:?].*$#', '', $s);
    $s = preg_replace('/^www\./', '', $s);
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $s) ? $s : '';
}

function fmt_color($s): string { $s = trim((string)$s); return preg_match('/^#[0-9a-fA-F]{6}$/', $s) ? strtolower($s) : '#4f46e5'; }
