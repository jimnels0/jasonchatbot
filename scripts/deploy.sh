#!/bin/bash
# Deploy jasonchatbot to jasonvps (bot.pennybiddeals.com) as the site user over SSH (no sudo needed).
#   bash scripts/deploy.sh            build + upload + migrate + check
#   bash scripts/deploy.sh --skip-build
# Needs the `jcb` SSH host (~/.ssh/config: jcbot@108.181.184.22). The server config (/home/jcbot/jcb-config.php) is never touched.
set -euo pipefail
cd "$(dirname "$0")/.."
HOST=jcb; DOM=bot.pennybiddeals.com; ROOT=/home/jcbot/htdocs/$DOM
if [ "${1:-}" != "--skip-build" ]; then (cd web && npm run typecheck && npm run build); fi
for f in api/*.php api/lib/*.php; do php -l "$f" >/dev/null; done
STAGE=$(mktemp -d)
cp -r web/dist/. "$STAGE/"; mkdir -p "$STAGE/api"; cp -r api/. "$STAGE/api/"
BUILD=$(cd "$STAGE" && find . -type f -exec sha256sum {} + | sort | sha256sum | cut -c1-12)
echo "$BUILD" >"$STAGE/.build"
tar -C "$STAGE" -czf "$STAGE.tgz" .
scp -q "$STAGE.tgz" "$HOST:jcb_deploy.tgz"
ssh "$HOST" "set -e; rm -rf ~/jcb_stage; mkdir ~/jcb_stage; tar -xzf ~/jcb_deploy.tgz -C ~/jcb_stage;
  rsync -a --delete --exclude '.well-known' ~/jcb_stage/ $ROOT/; rm -rf ~/jcb_stage ~/jcb_deploy.tgz;
  php $ROOT/api/migrate.php"
rm -rf "$STAGE" "$STAGE.tgz"
code=$(curl -s -o /dev/null -w '%{http_code}' --resolve $DOM:443:108.181.184.22 -k "https://$DOM/api/gate.php")
echo "deployed build $BUILD -> https://$DOM (gate.php HTTP $code)"
