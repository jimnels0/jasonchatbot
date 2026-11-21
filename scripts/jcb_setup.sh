#!/bin/bash
# jasonchatbot one-time setup on jasonvps. Run: sudo bash ~/penny-move/jcb_setup.sh
# Creates the CloudPanel PHP 8.5 site bot.pennybiddeals.com (site user jcbot), the MySQL database jcbot,
# a config file OUTSIDE the web root (/home/jcbot/jcb-config.php, 600), the Cloudflare origin cert, nginx rules,
# lets James's PC key log in as jcbot (deploys need no sudo), installs pdftotext, and adds the worker cron.
# Touches nothing else (trading jobs, other sites). Safe to re-run: finished steps are skipped.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run with sudo"; exit 1; }
M=/home/administrator/penny-move
D=$M/.jcb; mkdir -p "$D"
DOM=bot.pennybiddeals.com; SU=jcbot; DB=jcbot
ROOT=/home/$SU/htdocs/$DOM; CFG=/home/$SU/jcb-config.php
CERT=$M/cfg/ssl/pennybiddeals
pw() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32; }
step() { [ -f "$D/$1" ]; }
done_() { touch "$D/$1"; echo "  ok: $1"; }

if ! step site; then
  clpctl site:add:php --domainName="$DOM" --phpVersion=8.5 --vhostTemplate='Generic' --siteUser="$SU" --siteUserPassword="$(pw)"
  done_ site
fi

if ! step db; then
  dp=$(pw)
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB'@'localhost' IDENTIFIED BY '$dp';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DB'@'localhost';
FLUSH PRIVILEGES;
SQL
  cat >"$CFG" <<EOF
<?php
// jasonchatbot server config (jcb_setup.sh $(date -u +%F)). NOT in git, NOT in the web root.
\$db_host = 'localhost'; \$db_name = '$DB'; \$db_user = '$DB'; \$db_pass = '$dp';
EOF
  chown $SU:$SU "$CFG"; chmod 600 "$CFG"
  done_ db
fi

if ! step key; then
  KEY=$M/jcb_key.pub; [ -s "$KEY" ] || { echo "missing $KEY"; exit 1; }
  h=/home/$SU; install -d -m 700 -o $SU -g $SU "$h/.ssh"; touch "$h/.ssh/authorized_keys"
  grep -qxF "$(cat "$KEY")" "$h/.ssh/authorized_keys" || cat "$KEY" >>"$h/.ssh/authorized_keys"
  chown $SU:$SU "$h/.ssh/authorized_keys"; chmod 600 "$h/.ssh/authorized_keys"; rm -f "$KEY"
  done_ key
fi

if ! step vhost; then
  vh=$(grep -l "server_name.*$DOM" /etc/nginx/sites-enabled/* | head -1)
  [ -n "$vh" ] || { echo "  FAIL: no vhost for $DOM"; exit 1; }
  cp "$vh" "$D/vhost.bak"
  extra='    location ^~ /api/lib/ { return 404; }\n    location ~ ^/api/(worker|migrate)\\.php$ { return 404; }\n    location = /api/ { return 404; }'
  sed -i 's#try_files $uri $uri/ /index.php?$args;#try_files $uri $uri/ /index.html;#' "$vh"
  awk -v x="$extra" '!d && /^[ \t]*root[ \t]/ {print; gsub(/\\n/,"\n",x); print x; d=1; next} {print}' "$vh" >"$vh.new" && mv "$vh.new" "$vh"
  install -m 644 "$CERT/origin-cert.pem" "/etc/nginx/ssl-certificates/$DOM.crt"
  install -m 600 "$CERT/origin-key.pem"  "/etc/nginx/ssl-certificates/$DOM.key"
  if nginx -t; then done_ vhost; else echo "  FAIL: nginx -t, restoring old vhost"; cp "$D/vhost.bak" "$vh"; exit 1; fi
fi

if ! step pdf; then
  command -v pdftotext >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y --no-upgrade poppler-utils >/dev/null
  command -v pdftotext >/dev/null && done_ pdf || echo "  WARN: pdftotext not installed (PDF upload will be off)"
fi

F=/etc/cron.d/jasonchatbot-worker
[ -f "$F" ] || { echo "# jasonchatbot: crawl + PDF jobs, every minute" >"$F"; echo "* * * * * $SU php $ROOT/api/worker.php >/dev/null 2>&1" >>"$F"; echo "  ok: worker cron"; }

systemctl reload nginx
echo "== done. Site $DOM, user $SU, database $DB. Config: $CFG (password not printed)."
