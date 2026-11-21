#!/bin/bash
# Run from your PC:  ssh -t jcb "bash ~/setmail.sh"
# Asks for the email account the chatbot sends from (password typed hidden), writes it into /home/jcbot/jcb-config.php
# (backup kept), then sends one test email. The password is never printed.
set -euo pipefail
CFG=/home/jcbot/jcb-config.php
LIB=/home/jcbot/htdocs/bot.pennybiddeals.com/api/lib/mail.php
[ -f "$CFG" ] || { echo "no $CFG"; exit 1; }

read -r -p "SMTP server [gtxm1041.siteground.biz]: " HOST; HOST=${HOST:-gtxm1041.siteground.biz}
read -r -p "Port (465 or 587) [465]: " PORT; PORT=${PORT:-465}
read -r -p "Send from (mailbox login) [noreply@pennybiddeals.com]: " USERN; USERN=${USERN:-noreply@pennybiddeals.com}
read -r -s -p "Mailbox password: " PASS; echo
[ -n "$PASS" ] || { echo "no password, nothing changed"; exit 1; }
read -r -p "Sender name [Jason Chatbot]: " NAME; NAME=${NAME:-Jason Chatbot}
read -r -p "Send a test email to: " TEST

cp "$CFG" "$CFG.bak-$(date +%Y%m%d-%H%M%S)"
sed -i '/^\/\/ >>> setmail.sh/,/^\/\/ <<< setmail.sh/d' "$CFG"
q() { php -r 'echo var_export($argv[1], true);' "$1"; }
{
  echo "// >>> setmail.sh $(date -u +%Y-%m-%dT%H:%MZ)"
  echo "\$smtp_host = $(q "$HOST");"
  echo "\$smtp_port = $((PORT));"
  echo "\$smtp_user = $(q "$USERN");"
  echo "\$smtp_pass = $(q "$PASS");"
  echo "\$smtp_from = $(q "$USERN");"
  echo "\$smtp_from_name = $(q "$NAME");"
  echo "// <<< setmail.sh"
} >> "$CFG"
chmod 600 "$CFG"
php -l "$CFG" >/dev/null && echo "config syntax ok"

[ -n "$TEST" ] || { echo "saved (no test sent)"; exit 0; }
php -r 'include $argv[1]; require $argv[2];
  $ok = mail_send($argv[3], "Chatbot email test", "If you can read this, the chatbot can send email.", mail_html("Chatbot email test", "<p>If you can read this, the chatbot can send email.</p>"));
  echo $ok ? "test email sent to $argv[3]\n" : "SEND FAILED (see the line above, or the PHP error log)\n";' "$CFG" "$LIB" "$TEST" 2>&1
