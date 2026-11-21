#!/bin/bash
# Run from your PC:  ssh -t jcb "bash ~/setai.sh"
# Asks for AI keys (input hidden), writes them into /home/jcbot/jcb-config.php (backup kept), and tests each key
# with a tiny request. Leave a key blank to keep/skip it. Keys are never printed.
set -euo pipefail
CFG=/home/jcbot/jcb-config.php
[ -f "$CFG" ] || { echo "no $CFG"; exit 1; }

ask() { local v; read -r -s -p "$1 (blank = skip): " v; echo >&2; printf '%s' "$v"; }
GROQ=$(ask "Groq API key")
CEREBRAS=$(ask "Cerebras API key")
ANTHROPIC=$(ask "Anthropic API key")
OPENAI=$(ask "OpenAI API key")

cp "$CFG" "$CFG.bak-$(date +%Y%m%d-%H%M%S)"
# Drop an earlier block written by this script, then append a fresh one.
sed -i '/^\/\/ >>> setai.sh/,/^\/\/ <<< setai.sh/d' "$CFG"
{
  echo "// >>> setai.sh $(date -u +%Y-%m-%dT%H:%MZ)"
  for pair in "groq_api_key:$GROQ" "cerebras_api_key:$CEREBRAS" "anthropic_api_key:$ANTHROPIC" "openai_api_key:$OPENAI"; do
    name=${pair%%:*}; val=${pair#*:}
    [ -n "$val" ] && printf "\$%s = %s;\n" "$name" "$(php -r 'echo var_export($argv[1], true);' "$val")"
  done
  echo "// <<< setai.sh"
} >> "$CFG"
chmod 600 "$CFG"
php -l "$CFG" >/dev/null && echo "config.php syntax ok"

# Test each provider that has a key (one short call each; no key is printed).
php -r '
include $argv[1];
$tests = [
  "groq" => ["https://api.groq.com/openai/v1/chat/completions", $groq_api_key ?? "", "openai/gpt-oss-120b"],
  "cerebras" => ["https://api.cerebras.ai/v1/chat/completions", $cerebras_api_key ?? "", "gpt-oss-120b"],
  "openai" => ["https://api.openai.com/v1/chat/completions", $openai_api_key ?? "", "gpt-5-mini"],
];
foreach ($tests as $n => [$url, $key, $model]) {
  if ($key === "") { echo "$n: no key\n"; continue; }
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>20,
    CURLOPT_HTTPHEADER=>["Content-Type: application/json", "Authorization: Bearer $key"],
    CURLOPT_POSTFIELDS=>json_encode(["model"=>$model, "messages"=>[["role"=>"user","content"=>"Say OK"]], "max_completion_tokens"=>20])]);
  curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  echo "$n: " . ($c === 200 ? "key OK" : "FAILED (HTTP $c)") . "\n";
}
$k = $anthropic_api_key ?? "";
if ($k === "") { echo "anthropic: no key\n"; } else {
  $ch = curl_init("https://api.anthropic.com/v1/messages");
  curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>20,
    CURLOPT_HTTPHEADER=>["Content-Type: application/json", "x-api-key: $k", "anthropic-version: 2023-06-01"],
    CURLOPT_POSTFIELDS=>json_encode(["model"=>"claude-haiku-5-5", "max_tokens"=>10, "messages"=>[["role"=>"user","content"=>"Say OK"]]])]);
  curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  echo "anthropic: " . ($c === 200 ? "key OK" : "FAILED (HTTP $c)") . "\n";
}' "$CFG"
echo done
