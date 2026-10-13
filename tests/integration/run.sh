#!/usr/bin/env bash
#
# PHPUnit tests of WorkflowPacks inside a disposable FreeScout (Docker: PHP 8.2 + Apache, MariaDB 10.11).
#
#   bash tests/integration/run.sh                 # downloads FreeScout (branch dist) from GitHub
#   FREESCOUT_SRC=/path/to/freescout bash tests/integration/run.sh   # or FREESCOUT_REF=<tag or branch>
#
# The official Workflows module is paid and is NOT used here: tests/stubs/Workflows (test stub reproducing its public
# interface) is installed instead. A GreenMail container is the IMAP server of the "file into an e-mail folder" tests.
# Only test data is created (addresses in .test); the containers are removed at the end.
#
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# Git Bash on Windows: keep container paths as they are, give Docker Windows paths for the host side
if (cd "$ROOT" && pwd -W) >/dev/null 2>&1; then
    export MSYS_NO_PATHCONV=1
    ROOT="$(cd "$ROOT" && pwd -W)"
fi
IMAGE="wp-freescout-test"
NET="wptest"
DB="wp-test-db"
APP="wp-test-app"
MAIL="wp-test-mail"   # IMAP test server (GreenMail) for the "file into an e-mail folder" action
MAIL_IMAGE="greenmail/standalone:2.1.13"
PHPUNIT_URL="https://phar.phpunit.de/phpunit-11.phar"

log() { printf '\n== %s\n' "$*" >&2; }
cleanup() { docker rm -f "$APP" "$DB" "$MAIL" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap '[ -n "${KEEP:-}" ] || cleanup' EXIT   # KEEP=1: leave the containers running (debugging)

# On failure: state of the containers and the useful logs, so that the CI log shows the cause directly
debug() {
    trap - ERR
    log "Debug: containers"
    docker ps -a --filter "name=wp-test-" || true
    log "Debug: database container (last lines)"
    docker logs --tail 40 "$DB" 2>&1 || true
    log "Debug: FreeScout container (last lines)"
    docker logs --tail 40 "$APP" 2>&1 || true
    log "Debug: FreeScout setup and Laravel log"
    docker exec "$APP" sh -c 'cat /tmp/setup.log 2>/dev/null | tail -n 60; tail -n 80 /var/www/html/storage/logs/*.log 2>/dev/null' || true
}
trap debug ERR

FREESCOUT_REF="${FREESCOUT_REF:-dist}"

log "Image and containers"
docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" "$ROOT/tests/integration" >/dev/null
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" \
    -e MARIADB_ROOT_PASSWORD=test-root -e MARIADB_DATABASE=freescout -e MARIADB_USER=freescout -e MARIADB_PASSWORD=test-only \
    mariadb:10.11 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
docker run -d --name "$APP" --network "$NET" "$IMAGE" >/dev/null
docker run -d --name "$MAIL" --network "$NET" "$MAIL_IMAGE" >/dev/null

log "FreeScout + modules"
if [ -n "${FREESCOUT_SRC:-}" ]; then
    docker cp "$FREESCOUT_SRC/." "$APP:/var/www/html/"
else
    # downloaded inside the (Linux) container: a checkout on Windows can lose files of vendor/ (path length)
    docker exec "$APP" sh -c "curl -fsSL https://codeload.github.com/freescout-helpdesk/freescout/tar.gz/refs/heads/$FREESCOUT_REF | tar xz --strip-components=1 -C /var/www/html"
fi
docker cp "$ROOT/WorkflowPacks" "$APP:/var/www/html/Modules/WorkflowPacks"
docker cp "$ROOT/tests/stubs/Workflows" "$APP:/var/www/html/Modules/Workflows"
docker exec "$APP" sh -c 'rm -rf /var/www/html/.git && chown -R www-data:www-data /var/www/html'

for _ in $(seq 1 60); do
    docker exec "$DB" healthcheck.sh --connect >/dev/null 2>&1 && break
    sleep 1
done

docker exec -u www-data -w /var/www/html "$APP" bash -c '
set -e
exec >/tmp/setup.log 2>&1
cat > .env <<EOF
APP_URL=http://localhost
APP_ENV=production
APP_KEY=
DB_CONNECTION=mysql
DB_HOST=wp-test-db
DB_PORT=3306
DB_DATABASE=freescout
DB_USERNAME=freescout
DB_PASSWORD=test-only
EOF
php artisan key:generate --force
php artisan migrate --force
php artisan module:enable Workflows
php artisan module:enable WorkflowPacks
php artisan freescout:module-install workflows >/dev/null 2>&1 || true
php artisan freescout:module-install workflowpacks >/dev/null 2>&1 || true
php artisan migrate --force
php artisan config:clear
' || { docker exec "$APP" cat /tmp/setup.log >&2; exit 1; }

log "workflowpacks:check"
docker exec -u www-data -w /var/www/html "$APP" php artisan workflowpacks:check || true
docker exec -u www-data -w /var/www/html "$APP" php artisan workflowpacks:check --pack=Modules/WorkflowPacks/Resources/packs/tri-emails-multilingue.json | tail -n 2
log "workflowpacks:health"
docker exec -u www-data -w /var/www/html "$APP" php artisan workflowpacks:health

log "PHPUnit"
docker exec "$APP" sh -c "curl -sSL -o /tmp/phpunit.phar $PHPUNIT_URL"
set +e
docker exec -u www-data -w /var/www/html -e WP_TEST_IMAP_HOST="$MAIL" "$APP" php /tmp/phpunit.phar -c Modules/WorkflowPacks/Tests/phpunit.xml --do-not-cache-result --testdox "$@"
code=$?
set -e
[ "$code" -eq 0 ] || debug

log "Versions"
docker exec -w /var/www/html "$APP" sh -c 'php -r "echo \"PHP \".PHP_VERSION.PHP_EOL;"; php artisan --version; php /tmp/phpunit.phar --version | head -n 1'
docker exec "$DB" mariadb --version
exit $code
