#!/usr/bin/env bash
#
# Real test of the "Push notification" block with the real WebPush module (github.com/altmenorg/freescout-webpush),
# in the disposable test containers left by:  KEEP=1 bash tests/integration/run.sh
#
#   bash tests/integration/webpush/run.sh [ref]     # ref: a tag (v1.2.0) or a branch (main, the default)
#
# A fake device (its own P-256 keys) is subscribed; a fake push service on 127.0.0.1:9999 (capture.php) receives what
# WebPush really sends; real.php decrypts it as a phone would and checks quiet hours and the module switched off.
#
set -Eeuo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
if (cd "$ROOT" && pwd -W) >/dev/null 2>&1; then
    export MSYS_NO_PATHCONV=1
    HERE="$(cd "$HERE" && pwd -W)"
    ROOT="$(cd "$ROOT" && pwd -W)"
fi
REF="${1:-main}"
APP="wp-test-app"
TMP="$(mktemp -d)"
(cd "$TMP" && pwd -W) >/dev/null 2>&1 && TMP="$(cd "$TMP" && pwd -W)"   # Windows: a path Docker and curl understand
trap 'rm -f "$TMP/webpush.zip"; rmdir "$TMP" 2>/dev/null || true' EXIT

curl -fsSL -o "$TMP/webpush.zip" "https://github.com/altmenorg/freescout-webpush/archive/$REF.zip"
docker cp "$TMP/webpush.zip" "$APP:/tmp/webpush.zip"
docker cp "$HERE/." "$APP:/tmp/wpreal/"
docker cp "$ROOT/tests/demo/seed_demo.php" "$APP:/tmp/seed_demo.php"
docker exec "$APP" sh -c '
    set -e
    cd /var/www/html
    rm -rf /tmp/wpsrc Modules/WebPush.test-stub && mkdir /tmp/wpsrc
    php -r "\$z = new ZipArchive; \$z->open(\"/tmp/webpush.zip\"); \$z->extractTo(\"/tmp/wpsrc\");"
    [ -d Modules/WebPush ] && mv Modules/WebPush Modules/WebPush.test-stub
    mv /tmp/wpsrc/* Modules/WebPush
    chown -R www-data:www-data Modules/WebPush /tmp/wpreal
    php /tmp/seed_demo.php admin@demo.test "Demo-pass-123" >/dev/null
    php artisan module:enable WebPush >/dev/null 2>&1 || true
    php artisan cache:clear >/dev/null
    rm -rf /tmp/push
    (php -S 127.0.0.1:9999 /tmp/wpreal/capture.php >/tmp/capture.log 2>&1 &)
    sleep 1
    su www-data -s /bin/sh -c "php /tmp/wpreal/real.php"
'
