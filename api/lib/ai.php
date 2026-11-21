<?php
// api/lib/ai.php -- model providers, tried in order until one answers (lesson from Penny: never depend on one provider).
// Config (jcb-config.php): $ai_chain = [['anthropic','claude-opus-5-5'], ['groq','openai/gpt-oss-120b'], ...];
// keys: $anthropic_api_key, $groq_api_key, $cerebras_api_key, $openai_api_key. A provider without a key is skipped.
// Calls ported from jasonpenny api/ai_lib.php.
const AI_MAX_OUT = 900;
const AI_DEFAULT_CHAIN = [['anthropic', 'claude-opus-5-5'], ['groq', 'openai/gpt-oss-120b'], ['cerebras', 'gpt-oss-120b'], ['openai', 'gpt-5-mini']];
const AI_OPENAI_STYLE = [
    'groq' => 'https://api.groq.com/openai/v1/chat/completions',
    'cerebras' => 'https://api.cerebras.ai/v1/chat/completions',
    'openai' => 'https://api.openai.com/v1/chat/completions',
];

function ai_http(string $url, array $headers, string $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($resp) && $resp !== '' ? json_decode($resp, true) : null;
    return [$code, is_array($j) ? $j : null];
}

/** A model reply -> the JSON object inside it (models sometimes wrap it in ``` fences), or null. */
function ai_json(string $text): ?array {
    $j = json_decode(trim($text), true);
    if (is_array($j)) return $j;
    $a = strpos($text, '{'); $b = strrpos($text, '}');
    if ($a === false || $b === false || $b <= $a) return null;
    $j = json_decode(substr($text, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}

/** [httpCode, text, tokensIn, tokensOut] */
function ai_call(string $provider, string $key, string $model, string $system, array $turns): array {
    if ($provider === 'anthropic') {
        $msgs = array_map(fn($t) => ['role' => $t['role'] === 'user' ? 'user' : 'assistant', 'content' => $t['content']], $turns);
        [$code, $b] = ai_http('https://api.anthropic.com/v1/messages', ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            (string)json_encode(['model' => $model, 'max_tokens' => AI_MAX_OUT, 'system' => $system . "\n\nReply with ONE JSON object only, no other text.", 'messages' => $msgs]));
        $text = '';
        foreach (($b['content'] ?? []) as $p) if (($p['type'] ?? '') === 'text') $text .= (string)$p['text'];
        return [$code, $text, (int)($b['usage']['input_tokens'] ?? 0), (int)($b['usage']['output_tokens'] ?? 0)];
    }
    if (isset(AI_OPENAI_STYLE[$provider])) {
        $messages = array_merge([['role' => 'system', 'content' => $system]], array_map(fn($t) => ['role' => $t['role'], 'content' => $t['content']], $turns));
        [$code, $b] = ai_http(AI_OPENAI_STYLE[$provider], ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            (string)json_encode(['model' => $model, 'temperature' => 0.2, 'max_completion_tokens' => AI_MAX_OUT, 'messages' => $messages, 'response_format' => ['type' => 'json_object']]));
        return [$code, (string)($b['choices'][0]['message']['content'] ?? ''), (int)($b['usage']['prompt_tokens'] ?? 0), (int)($b['usage']['completion_tokens'] ?? 0)];
    }
    return [0, '', 0, 0];
}

/** First provider that returns valid JSON wins: [jsonArray|null, via, tokensIn, tokensOut]. */
function ai_ask(string $system, array $turns): array {
    $chain = is_array($GLOBALS['ai_chain'] ?? null) ? $GLOBALS['ai_chain'] : AI_DEFAULT_CHAIN;
    foreach ($chain as [$provider, $model]) {
        $key = (string)($GLOBALS[$provider . '_api_key'] ?? '');
        if ($key === '') continue;
        [$code, $text, $in, $outT] = ai_call($provider, $key, $model, $system, $turns);
        if ($code !== 200) { error_log("jcb ai: $provider/$model HTTP $code"); continue; }
        $j = ai_json($text);
        if (is_array($j) && isset($j['answer'])) return [$j, "$provider/$model", $in, $outT];
        error_log("jcb ai: $provider/$model returned no JSON");
    }
    return [null, 'none', 0, 0];
}

function ai_configured(): bool {
    $chain = is_array($GLOBALS['ai_chain'] ?? null) ? $GLOBALS['ai_chain'] : AI_DEFAULT_CHAIN;
    foreach ($chain as [$p]) if ((string)($GLOBALS[$p . '_api_key'] ?? '') !== '') return true;
    return false;
}
