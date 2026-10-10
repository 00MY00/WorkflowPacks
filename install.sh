#!/usr/bin/env bash
#
# Installation / mise à jour / désinstallation de Workflow Packs sur un FreeScout existant (Linux).
#
#   sudo bash install.sh                         installe ou met à jour (dernière version de la branche main)
#   sudo bash install.sh --path /var/www/html    dossier de FreeScout (détecté sinon)
#   sudo bash install.sh --source ./WorkflowPacks.zip   archive ou dossier local au lieu de GitHub
#   sudo bash install.sh --dry-run               affiche ce qui serait fait, ne change rien
#   sudo bash install.sh --uninstall             désactive et retire le module (les règles Workflows restent)
#   sudo bash install.sh --yes                   sans question
#
# Étapes : vérifications -> sauvegarde (dossier du module + tables workflowpacks_*) -> copie -> activation ->
# migrations -> php artisan workflowpacks:check. En cas d'erreur, l'ancienne version est remise en place.
# Aucun mot de passe n'est affiché ni écrit dans le journal.
#
set -Eeuo pipefail

SCRIPT_VERSION="1.0.0"   # same as WorkflowPacks/module.json (checked by tools/build-release.sh)
REPO_URL="${WP_REPO_URL:-https://github.com/00MY00/WorkflowPacks}"
MODULE="WorkflowPacks"
ALIAS="workflowpacks"
MIN_PHP="7.1.0"

FS_PATH=""
SOURCE=""
DRY_RUN=0
YES=0
UNINSTALL=0
WEB_USER=""
WORK=""
BACKUP=""
STEP=0
STEPS=7
LOG="/tmp/workflowpacks-install-$(date +%Y%m%d-%H%M%S).log"

if [ -t 1 ]; then C_RED=$'\e[31m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_BLUE=$'\e[34m'; C_RESET=$'\e[0m'; else C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_RESET=""; fi

log() { printf '%s %s\n' "$(date +%H:%M:%S)" "$*" >>"$LOG"; }
info() { printf '%s\n' "${C_BLUE}ℹ${C_RESET} $*"; log "INFO $*"; }
warn() { printf '%s\n' "${C_YELLOW}⚠ $*${C_RESET}" >&2; log "WARN $*"; }
ok() { printf '%s\n' "${C_GREEN}✔ $*${C_RESET}"; log "OK $*"; }
step() { STEP=$((STEP + 1)); printf '\n%s\n' "${C_BLUE}[${STEP}/${STEPS}]${C_RESET} $*"; log "STEP ${STEP} $*"; }
die() { printf '%s\n' "${C_RED}✖ $*${C_RESET}" >&2; log "ERREUR $*"; exit 1; }

run() {
    # run CMD... : exécute (ou affiche en --dry-run), sortie dans le journal
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) %s\n' "$*"; return 0; fi
    log "RUN $*"
    "$@" >>"$LOG" 2>&1
}

artisan() {
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) php artisan %s\n' "$*"; return 0; fi
    log "ARTISAN $*"
    (cd "$FS_PATH" && sudo -u "$WEB_USER" php artisan "$@") >>"$LOG" 2>&1
}

ask() {
    # ask "question" -> 0 si oui
    [ "$YES" -eq 1 ] && return 0
    local answer
    read -r -p "$1 [o/N] " answer || true
    [[ "$answer" =~ ^([oOyY]|oui|yes)$ ]]
}

version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n 1)" = "$2" ]; }

json_version() { { sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1" 2>/dev/null | head -n 1; } || true; }

usage() { sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --path) FS_PATH="${2:-}"; shift 2 ;;
            --source) SOURCE="${2:-}"; shift 2 ;;
            --dry-run) DRY_RUN=1; shift ;;
            --uninstall) UNINSTALL=1; shift ;;
            --yes|-y) YES=1; shift ;;
            -h|--help) usage ;;
            *) die "Option inconnue : $1 (voir --help)" ;;
        esac
    done
}

on_error() {
    local code=$?
    printf '%s\n' "${C_RED}✖ Erreur à l'étape ${STEP} (code ${code}). Journal : ${LOG}${C_RESET}" >&2
    if [ -n "$BACKUP" ] && [ -d "$BACKUP/module" ] && [ "$DRY_RUN" -eq 0 ]; then
        warn "Remise en place de la version précédente du module…"
        rm -rf "${FS_PATH}/Modules/${MODULE}"
        cp -a "$BACKUP/module" "${FS_PATH}/Modules/${MODULE}"
        (cd "$FS_PATH" && sudo -u "$WEB_USER" php artisan freescout:clear-cache) >>"$LOG" 2>&1 || true
        warn "Version précédente restaurée (sauvegarde : $BACKUP)."
    fi
    exit "$code"
}
trap on_error ERR
trap '[ -n "$WORK" ] && rm -rf "$WORK"' EXIT

detect_freescout() {
    if [ -z "$FS_PATH" ]; then
        for candidate in /var/www/html /var/www/freescout /var/www/html/freescout /srv/freescout; do
            if [ -f "$candidate/artisan" ] && grep -q "freescout" "$candidate/config/app.php" 2>/dev/null; then FS_PATH="$candidate"; break; fi
        done
    fi
    [ -n "$FS_PATH" ] && [ -f "$FS_PATH/artisan" ] || die "FreeScout introuvable : indiquez son dossier avec --path /chemin/vers/freescout"
    FS_PATH="$(cd "$FS_PATH" && pwd)"
    WEB_USER="$(stat -c '%U' "$FS_PATH/storage" 2>/dev/null || echo www-data)"
    [ "$WEB_USER" = "root" ] && WEB_USER="www-data"
    id "$WEB_USER" >/dev/null 2>&1 || die "Utilisateur du serveur web « $WEB_USER » introuvable."
}

checks() {
    step "Vérifications"
    [ "$(id -u)" -eq 0 ] || die "Lancez le script avec sudo (il agit au nom de l'utilisateur du serveur web)."
    command -v php >/dev/null || die "php introuvable."
    local php_version fs_version
    php_version="$(php -r 'echo PHP_VERSION;')"
    version_ge "$php_version" "$MIN_PHP" || die "PHP $php_version : FreeScout demande au moins $MIN_PHP."
    detect_freescout
    fs_version="$(sed -n "s/.*'version' => '\([^']*\)'.*/\1/p" "$FS_PATH/config/app.php" | head -n 1)"
    info "FreeScout ${fs_version:-?} dans $FS_PATH (utilisateur web : $WEB_USER), PHP $php_version"
    version_ge "${fs_version:-0}" "1.8.0" || warn "FreeScout ${fs_version} : version minimale testée 1.8.0."
    if [ ! -d "$SOURCE" ] || [ -z "$SOURCE" ]; then
        command -v unzip >/dev/null || die "unzip introuvable (apt install unzip)."
        [ -n "$SOURCE" ] || command -v curl >/dev/null || die "curl introuvable (apt install curl)."
    fi
    if [ -d "$FS_PATH/Modules/Workflows" ]; then
        ok "Module Workflows présent (version $(json_version "$FS_PATH/Modules/Workflows/module.json"))."
    else
        warn "Module officiel Workflows absent : Workflow Packs s'installera mais restera bloqué (message explicite) jusqu'à son installation."
        ask "Continuer quand même ?" || die "Installation annulée."
    fi
    if [ -d "$FS_PATH/Modules/Refresh" ]; then ok "Module Refresh présent : les pages utiliseront son style."; else info "Module Refresh absent : style FreeScout standard."; fi
    if [ -d "$FS_PATH/Modules/Tags" ]; then ok "Module Tags présent."; else info "Module Tags absent : les règles qui en dépendent seront bloquées."; fi
}

backup() {
    step "Sauvegarde"
    BACKUP="${FS_PATH}/storage/app/workflowpacks/backups/install-$(date +%Y%m%d-%H%M%S)"
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) sauvegarde dans %s\n' "$BACKUP"; return 0; fi
    mkdir -p "$BACKUP"
    if [ -d "${FS_PATH}/Modules/${MODULE}" ]; then
        cp -a "${FS_PATH}/Modules/${MODULE}" "$BACKUP/module"
        ok "Dossier du module sauvegardé (version $(json_version "$BACKUP/module/module.json"))."
    else
        info "Première installation : aucun dossier à sauvegarder."
    fi
    # tables du module (export JSON par FreeScout lui-même : pas de mot de passe sur la ligne de commande)
    (cd "$FS_PATH" && sudo -u "$WEB_USER" php -r '
        require "vendor/autoload.php"; $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $out = [];
        foreach (["workflowpacks_packs", "workflowpacks_links", "workflowpacks_imports"] as $t) {
            $out[$t] = Schema::hasTable($t) ? DB::table($t)->get() : null;
        }
        echo json_encode($out);
    ') >"$BACKUP/tables.json" 2>>"$LOG" || warn "Sauvegarde des tables impossible (base inaccessible ?) : voir le journal."
    chown -R "$WEB_USER" "$(dirname "$BACKUP")"
    ok "Sauvegarde : $BACKUP"
}

fetch_module() {
    step "Récupération du module"
    WORK="$(mktemp -d)"
    local src="$SOURCE"
    if [ -z "$src" ]; then
        src="$WORK/main.zip"
        info "Téléchargement de ${REPO_URL} (branche main)…"
        if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) téléchargement\n'; return 0; fi
        curl -fsSL "${REPO_URL}/archive/refs/heads/main.zip" -o "$src" || die "Téléchargement impossible."
    fi
    if [ -d "$src" ]; then
        if [ -f "$src/module.json" ]; then cp -a "$src" "$WORK/$MODULE"; else cp -a "$src/$MODULE" "$WORK/$MODULE"; fi
    else
        [ -f "$src" ] || die "Source introuvable : $src"
        unzip -q "$src" -d "$WORK/x"
        local found
        found="$(find "$WORK/x" -maxdepth 3 -type f -name module.json -path "*/${MODULE}/module.json" | head -n 1)"
        [ -n "$found" ] || die "L'archive ne contient pas ${MODULE}/module.json."
        cp -a "$(dirname "$found")" "$WORK/$MODULE"
    fi
    grep -q "\"alias\": \"${ALIAS}\"" "$WORK/$MODULE/module.json" || die "module.json inattendu dans la source."
    rm -rf "$WORK/$MODULE/Tests"
    ok "Workflow Packs $(json_version "$WORK/$MODULE/module.json") prêt."
}

install_module() {
    step "Copie des fichiers"
    local current new
    current="$(json_version "${FS_PATH}/Modules/${MODULE}/module.json")"
    new="$(json_version "$WORK/$MODULE/module.json" 2>/dev/null || true)"
    if [ -n "$current" ]; then info "Mise à jour ${current} → ${new:-?}"; fi
    run rm -rf "${FS_PATH}/Modules/${MODULE}"
    run cp -a "$WORK/$MODULE" "${FS_PATH}/Modules/${MODULE}"
    run chown -R "$WEB_USER":"$WEB_USER" "${FS_PATH}/Modules/${MODULE}"
    ok "Fichiers en place."

    step "Activation"
    artisan cache:clear   # the list of modules is cached: a new folder is not seen without this
    artisan module:enable "$MODULE"
    # crée le lien public/modules/workflowpacks, lance les migrations, vide les caches (comme Gérer › Modules)
    artisan freescout:module-install "$ALIAS"
    ok "Module activé."

    step "Migrations"
    artisan migrate --force
    artisan freescout:clear-cache
    ok "Base à jour."
}

verify() {
    step "Vérification"
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) php artisan workflowpacks:check\n'; return 0; fi
    local code=0
    (cd "$FS_PATH" && sudo -u "$WEB_USER" php artisan workflowpacks:check) || code=$?
    if [ "$code" -eq 2 ]; then
        warn "État BLOQUANT : voir les lignes BLOCKING ci-dessus (Workflows absent ou incompatible). Rien n'a été modifié dans Workflows."
    elif [ "$code" -ne 0 ]; then
        die "workflowpacks:check a échoué (code $code)."
    else
        ok "Workflow Packs est opérationnel : menu Gérer › Workflow Packs."
    fi
}

uninstall() {
    STEPS=3
    step "Vérifications"
    [ "$(id -u)" -eq 0 ] || die "Lancez le script avec sudo."
    detect_freescout
    [ -d "${FS_PATH}/Modules/${MODULE}" ] || die "Workflow Packs n'est pas installé dans $FS_PATH."
    warn "Les règles créées dans Workflows restent en place ; seules les informations de suivi des packs sont supprimées."
    ask "Désinstaller Workflow Packs ?" || die "Annulé."
    backup
    step "Retrait"
    artisan migrate:rollback --path="Modules/${MODULE}/Database/Migrations" --force || warn "Tables non supprimées (voir le journal)."
    artisan module:disable "$MODULE" || true
    run rm -rf "${FS_PATH}/Modules/${MODULE}" "${FS_PATH}/public/modules/${ALIAS}"
    # caches that still name the module's provider (module list + configuration + cache store): rebuilt below
    run rm -f "${FS_PATH}/bootstrap/cache/workflow_packs_module.php" "${FS_PATH}/bootstrap/cache/config.php"
    run find "${FS_PATH}/storage/framework/cache/data" -mindepth 1 -delete
    artisan freescout:clear-cache || true
    ok "Workflow Packs retiré (sauvegarde : $BACKUP)."
}

main() {
    parse_args "$@"
    : >"$LOG"
    chmod 600 "$LOG"
    printf '%s\n' "Workflow Packs — script ${SCRIPT_VERSION} (journal : $LOG)"
    [ "$DRY_RUN" -eq 1 ] && info "Mode --dry-run : aucune modification."
    if [ "$UNINSTALL" -eq 1 ]; then uninstall; exit 0; fi
    checks
    backup
    fetch_module
    install_module
    verify
    printf '\n%s\n' "Terminé. Mises à jour : relancer ce script, ou Gérer › Workflow Packs › Diagnostic (mise à jour avec retour arrière automatique)."
}

main "$@"
