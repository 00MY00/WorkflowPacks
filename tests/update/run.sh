#!/usr/bin/env bash
#
# End-to-end test of the update of Workflow Packs to the LATEST PUBLISHED RELEASE (GitHub), starting from older
# versions, in disposable Docker containers (FreeScout + MariaDB + test stub of Workflows).
#
#   bash tests/update/run.sh                    # all scenarios
#   bash tests/update/run.sh cli:v1.0.0 ...     # some scenarios
#
# Scenarios (start version = git commit or tag of this repository):
#   cli:<ref>        php artisan workflowpacks:update            (what the daily automatic update runs)
#   button:<ref>     "Update now" -> request -> php artisan schedule:run (what the button triggers)
#   install:<ref>    sudo bash install.sh (latest script of the release)
#   install-local:<ref>  sudo bash install.sh of this working copy (downloads the latest release, SHA-256 checked)
#
# Checks after each update: installed version = latest release, no rollback, workflowpacks:check not blocking,
# tables workflowpacks_resources (1.2) and workflowpacks_layouts (1.3) present, data of the previous version kept (packs, links, workflows).
# Results: tests/update/last-run.md
#
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if (cd "$ROOT" && pwd -W) >/dev/null 2>&1; then
    export MSYS_NO_PATHCONV=1
    ROOT="$(cd "$ROOT" && pwd -W)"
fi
IMAGE="wp-freescout-test"
NET="wpupd"
DB="wp-upd-db"
APP="wp-upd-app"
RESULTS="$ROOT/tests/update/last-run.md"
LATEST_URL="https://github.com/00MY00/WorkflowPacks/releases/latest/download"
SCENARIOS=("$@")
[ "${#SCENARIOS[@]}" -gt 0 ] || SCENARIOS=(cli:e875b74 cli:8cae637 button:v1.2.0 cli:v1.2.2 install:e875b74 install-local:8cae637)
PASS=0; FAILED=0
WORK="$(mktemp -d)"
# host path for "docker cp" (Git Bash on Windows: /tmp/... -> C:/Users/.../Temp/...)
host() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi; }

log() { printf '\n== %s\n' "$*" >&2; }
record() {
    printf '| %s | %s | %s | %s |\n' "$1" "$2" "$3" "${4:-}" >>"$RESULTS"
    if [ "$3" = "PASS" ]; then PASS=$((PASS + 1)); else FAILED=$((FAILED + 1)); fi
    printf '  [%s] %s — %s %s\n' "$3" "$1" "$2" "${4:-}" >&2
}
# expect <scenario> <check> <expected> <actual> [detail]
expect() {
    if [ "$3" = "$4" ]; then record "$1" "$2" PASS "${5:-$4}"; else record "$1" "$2" FAIL "${5:-$4}"; fi
}
cleanup() { docker rm -f "$APP" "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap 'cleanup; rm -rf "$WORK"' EXIT
www() { docker exec -u www-data -w /var/www/html "$APP" bash -c "$1"; }
php_eval() {
    # php_eval '<php code using $app booted>' -> output
    www "php -r 'require \"vendor/autoload.php\"; \$app = require \"bootstrap/app.php\"; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $1'"
}

latest="$(curl -fsSL "$LATEST_URL/module.json" | sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
[ -n "$latest" ] || { echo "cannot read the latest release" >&2; exit 1; }
docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" "$ROOT/tests/integration" >/dev/null

{
    echo "# Mise à jour vers la dernière version publiée — $(date '+%Y-%m-%d %H:%M')"
    echo
    echo "Dernière release GitHub : **$latest**. FreeScout (branche dist) + MariaDB 10.11 + bouchon Workflows."
    echo
    echo "| Scénario | Vérification | Résultat | Détail |"
    echo "|---|---|---|---|"
} >"$RESULTS"

start_instance() {
    cleanup
    docker network create "$NET" >/dev/null
    docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=test-root -e MARIADB_DATABASE=freescout \
        -e MARIADB_USER=freescout -e MARIADB_PASSWORD=test-only mariadb:10.11 >/dev/null
    docker run -d --name "$APP" --network "$NET" "$IMAGE" >/dev/null
    docker exec "$APP" sh -c "curl -fsSL https://codeload.github.com/freescout-helpdesk/freescout/tar.gz/refs/heads/dist | tar xz --strip-components=1 -C /var/www/html"
    docker cp "$ROOT/tests/stubs/Workflows" "$APP:/var/www/html/Modules/Workflows"
    for _ in $(seq 1 60); do docker exec "$DB" healthcheck.sh --connect >/dev/null 2>&1 && break; sleep 1; done
}

# installs the version of this repository at <ref> (git archive), then FreeScout, then demo data
install_old() {
    local ref=$1
    rm -rf "$WORK/old" && mkdir -p "$WORK/old"
    git -C "$ROOT" archive "$ref" WorkflowPacks tests/demo/seed_demo.php | tar -x -C "$WORK/old"
    docker cp "$(host "$WORK/old/WorkflowPacks")" "$APP:/var/www/html/Modules/WorkflowPacks"
    docker cp "$(host "$WORK/old/tests/demo/seed_demo.php")" "$APP:/tmp/seed_demo.php"
    docker exec "$APP" sh -c 'chown -R www-data:www-data /var/www/html'
    www '
set -e
printf "APP_URL=http://localhost\nAPP_ENV=production\nAPP_KEY=\nDB_CONNECTION=mysql\nDB_HOST=wp-upd-db\nDB_PORT=3306\nDB_DATABASE=freescout\nDB_USERNAME=freescout\nDB_PASSWORD=test-only\n" > .env
php artisan key:generate --force >/dev/null
php artisan migrate --force >/dev/null
php artisan module:enable Workflows >/dev/null
php artisan module:enable WorkflowPacks >/dev/null
php artisan freescout:module-install workflows >/dev/null 2>&1 || true
php artisan freescout:module-install workflowpacks >/dev/null 2>&1 || true
php artisan migrate --force >/dev/null
php /tmp/seed_demo.php admin@demo.test demo-password-123 >/dev/null
'
}

counts() { php_eval 'echo DB::table("workflowpacks_packs")->count()."/".DB::table("workflowpacks_links")->count()."/".DB::table("workflows")->count();'; }
installed() { www 'sed -n "s/^[[:space:]]*\"version\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" Modules/WorkflowPacks/module.json | head -n 1'; }

verify() {
    local name=$1 before=$2 output=$3
    local version state after resources
    version="$(installed)"
    expect "$name" "version installée = $latest" "$latest" "$version"
    if printf '%s' "$output" | grep -qiE "Rolled back to|rolled back:|Previous version restored|Version précédente restaurée|Putting the previous version back"; then record "$name" "aucun retour arrière" FAIL "$(printf '%s' "$output" | tail -n 3 | tr '\n' ' ')"; else record "$name" "aucun retour arrière" PASS; fi
    state="$(www 'php artisan workflowpacks:check --json' | sed -n 's/.*"state": "\([a-z]*\)".*/\1/p' | head -n 1)"
    expect "$name" "workflowpacks:check" "ok" "${state:-?}"
    resources="$(php_eval 'echo Schema::hasTable("workflowpacks_resources") ? "yes" : "no";')"
    expect "$name" "migration 1.2 (workflowpacks_resources)" "yes" "$resources"
    expect "$name" "migration 1.3 (workflowpacks_layouts)" "yes" "$(php_eval 'echo Schema::hasTable("workflowpacks_layouts") ? "yes" : "no";')"
    after="$(counts)"
    expect "$name" "données conservées (packs/liens/règles)" "$before" "$after" "$before → $after"
    expect "$name" "FreeScout répond (HTTP 200)" "200" "$(www 'curl -s -o /dev/null -w "%{http_code}" http://localhost/login')"
}

for scenario in "${SCENARIOS[@]}"; do
    mode="${scenario%%:*}"; ref="${scenario#*:}"
    log "$scenario"
    start_instance
    install_old "$ref"
    from="$(installed)"
    before="$(counts)"
    name="$mode depuis $from ($ref)"
    record "$name" "version de départ installée" PASS "$from, données $before"
    case "$mode" in
        cli)
            out="$(www 'php artisan workflowpacks:update 2>&1' || true)"
            ;;
        button)
            # what "Update now" does: a request in the options, picked up by the scheduler within a minute
            php_eval 'Modules\WorkflowPacks\Services\Update\Updater::requestUpdate(); echo "requested";' >/dev/null
            # like cron: a first run starts FreeScout's queue worker (it never ends, as on a real server); the
            # next minute's run skips it (withoutOverlapping) and runs the requested update
            docker exec -d -u www-data -w /var/www/html "$APP" php artisan schedule:run
            sleep 8
            out="$(www 'timeout 900 php artisan schedule:run 2>&1; cat storage/app/workflowpacks/update-status.json' || true)"
            pending="$(php_eval 'echo Modules\WorkflowPacks\Services\Update\Updater::pendingRequest() === "" ? "cleared" : "pending";' || true)"
            expect "$name" "demande traitée par le planificateur" "cleared" "$pending"
            ;;
        install)
            docker exec "$APP" sh -c "curl -fsSL -o /tmp/install.sh $LATEST_URL/install.sh"
            out="$(docker exec "$APP" bash /tmp/install.sh --path /var/www/html --yes 2>&1 || true)"
            ;;
        install-local)
            # install.sh of this working copy (not published yet), which downloads the latest release
            docker cp "$ROOT/install.sh" "$APP:/tmp/install.sh"
            out="$(docker exec "$APP" bash /tmp/install.sh --path /var/www/html --yes 2>&1 || true)"
            if printf '%s' "$out" | grep -q "SHA-256 checked\|Empreinte SHA-256 vérifiée"; then record "$scenario" "archive de la release vérifiée (SHA-256)" PASS; else record "$scenario" "archive de la release vérifiée (SHA-256)" FAIL; fi
            ;;
    esac
    printf '%s\n' "$out" | tail -n 5 >&2
    verify "$name" "$before" "$out"
done

{
    echo
    echo "**$PASS vérifications réussies, $FAILED en échec.**"
} >>"$RESULTS"
log "$PASS passed, $FAILED failed — $RESULTS"
[ "$FAILED" -eq 0 ]
