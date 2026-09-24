#!/usr/bin/env bash
# Boot a real WordPress with Signa in it and run tools/wp-qa/smoke.py.
#
#   tools/wp-qa/run.sh [php-version] [wp-version]
#   tools/wp-qa/run.sh 7.4 6.1     # the oldest pair the plugin supports
#   tools/wp-qa/run.sh 8.5 7.1.2   # the newest
#   SIGNA_QA_WOO=1 tools/wp-qa/run.sh 8.3 7.1.2   # with WooCommerce (HPOS on)
#
# Needs node, python3, curl and unzip. WordPress comes from GitHub (the
# WordPress/WordPress mirror), so wordpress.org does not have to be reachable;
# PHP comes from WordPress Playground (PHP compiled to WebAssembly) — no local
# PHP or database is needed. Nothing is written inside the repository except
# through the plugin's own mount.
set -euo pipefail

PHP_VERSION="${1:-8.3}"
WP_VERSION="${2:-7.1.2}"
PORT="${SIGNA_QA_PORT:-9400}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HERE/../../signa" && pwd)"
WORK="${SIGNA_QA_DIR:-${TMPDIR:-/tmp}/signa-wp-qa}"
SITE="$WORK/wp-$WP_VERSION-php$PHP_VERSION"

mkdir -p "$WORK"

if [ ! -x "$WORK/node_modules/.bin/wp-playground-cli" ]; then
	echo "installing WordPress Playground CLI into $WORK"
	(cd "$WORK" && npm install --no-save --no-audit --no-fund @wp-playground/cli >/dev/null)
fi

if [ ! -f "$WORK/wordpress-$WP_VERSION.zip" ]; then
	echo "downloading WordPress $WP_VERSION"
	curl -fsSL -o "$WORK/wordpress-$WP_VERSION.zip" "https://codeload.github.com/WordPress/WordPress/zip/refs/tags/$WP_VERSION"
fi

# A fresh site every run: no settings, tables or users left from the last one.
rm -rf "$SITE"
mkdir -p "$SITE"
unzip -q "$WORK/wordpress-$WP_VERSION.zip" -d "$SITE"
WP_DIR="$SITE/WordPress-$WP_VERSION"
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cp "$HERE/signa-qa.php" "$WP_DIR/wp-content/mu-plugins/signa-qa.php"

if [ "${SIGNA_QA_WOO:-0}" = "1" ]; then
	if [ ! -f "$WORK/woocommerce.zip" ]; then
		echo "downloading WooCommerce"
		curl -fsSL -o "$WORK/woocommerce.zip" "https://github.com/woocommerce/woocommerce/releases/latest/download/woocommerce.zip"
	fi
	unzip -q "$WORK/woocommerce.zip" -d "$WP_DIR/wp-content/plugins"
fi

# The blueprint's own PHP preference wins over --php, so it is written per run.
BLUEPRINT="$SITE/blueprint.json"
sed "s/\"__PHP__\"/\"$PHP_VERSION\"/" "$HERE/blueprint.json" >"$BLUEPRINT"
if [ "${SIGNA_QA_WOO:-0}" = "1" ]; then
	# WooCommerce first, so Signa boots with it present, as on a shop.
	python3 - "$BLUEPRINT" <<'PY'
import json, sys
path = sys.argv[1]
blueprint = json.load(open(path))
blueprint['steps'].insert(0, {'step': 'activatePlugin', 'pluginPath': 'woocommerce/woocommerce.php'})
json.dump(blueprint, open(path, 'w'))
PY
fi

LOG="$SITE/server.log"
"$WORK/node_modules/.bin/wp-playground-cli" server \
	--port="$PORT" \
	--php="$PHP_VERSION" \
	--site-url="http://127.0.0.1:$PORT" \
	--mount-before-install="$WP_DIR:/wordpress" \
	--wordpress-install-mode=install-from-existing-files \
	--mount="$PLUGIN:/wordpress/wp-content/plugins/signa" \
	--blueprint="$BLUEPRINT" \
	--define-bool WP_DEBUG true >"$LOG" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT

annotate() {
	# On GitHub Actions, surface the end of the server log as an annotation.
	if [ -n "${GITHUB_ACTIONS:-}" ]; then
		printf '::error title=%s::%s\n' "$1" "$(tail -c 3000 "$LOG" | sed 's/%/%25/g' | sed ':a;N;$!ba;s/\n/%0A/g')"
	fi
}

for _ in $(seq 1 180); do
	if grep -q "Ready!" "$LOG"; then
		break
	fi
	if ! kill -0 "$SERVER" 2>/dev/null; then
		cat "$LOG"
		annotate "WordPress exited"
		exit 1
	fi
	sleep 1
done
grep -q "Ready!" "$LOG" || { cat "$LOG"; echo "WordPress did not start"; annotate "WordPress did not start"; exit 1; }

python3 "$HERE/smoke.py" "http://127.0.0.1:$PORT" "$WP_DIR"
