#!/usr/bin/env bash
#
# Builds the release archive: dist/WorkflowPacks.zip (folder WorkflowPacks/ ready to drop into FreeScout's Modules/)
# dist/version.txt and dist/SHA256SUMS. The module's tests are left out; CHANGELOG and LICENSE are included.
# module.json (latestVersionUrl / latestVersionZipUrl) points FreeScout's own module update, and UpdateGuard, to
# version.txt and WorkflowPacks.zip of the latest release; UpdateGuard checks the ZIP against SHA256SUMS.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${ROOT}/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

version="$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$ROOT/WorkflowPacks/module.json" | head -n 1)"
script_version="$(sed -n 's/^SCRIPT_VERSION="\([^"]*\)".*/\1/p' "$ROOT/install.sh")"
if [ "$version" != "$script_version" ]; then
    printf 'module.json (%s) et install.sh (%s) n'"'"'ont pas la même version.\n' "$version" "$script_version" >&2
    exit 1
fi
if ! grep -q "^## \[${version}\]" "$ROOT/CHANGELOG.md"; then
    printf 'CHANGELOG.md : entrée [%s] manquante.\n' "$version" >&2
    exit 1
fi

cp -R "$ROOT/WorkflowPacks" "$STAGE/WorkflowPacks"
rm -rf "$STAGE/WorkflowPacks/Tests"
cp "$ROOT/CHANGELOG.md" "$ROOT/LICENSE" "$STAGE/WorkflowPacks/"
find "$STAGE" -name '.DS_Store' -delete

mkdir -p "$DIST"
rm -f "$DIST/WorkflowPacks.zip" "$DIST/SHA256SUMS" "$DIST/module.json" "$DIST/version.txt"
if command -v zip >/dev/null 2>&1; then
    (cd "$STAGE" && zip -qrX "$DIST/WorkflowPacks.zip" WorkflowPacks)
else
    (cd "$STAGE" && python3 -m zipfile -c "$DIST/WorkflowPacks.zip" WorkflowPacks)
fi
cp "$ROOT/install.sh" "$DIST/install.sh"
# latest version: version.txt (latestVersionUrl of module.json), module.json (required FreeScout version, install.sh)
cp "$ROOT/WorkflowPacks/module.json" "$DIST/module.json"
printf '%s\n' "$version" >"$DIST/version.txt"
(cd "$DIST" && sha256sum WorkflowPacks.zip install.sh module.json version.txt >SHA256SUMS)
printf 'WorkflowPacks %s : %s\n' "$version" "$DIST/WorkflowPacks.zip"
cat "$DIST/SHA256SUMS"
