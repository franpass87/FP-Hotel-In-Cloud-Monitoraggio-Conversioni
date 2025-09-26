#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

MAIN_FILE="$(grep -Rl --include="*.php" -m1 "^\\s*\\*\\s*Plugin Name:" "$ROOT_DIR" || true)"
if [[ -z "$MAIN_FILE" ]]; then
  echo "Errore: file principale del plugin non trovato." >&2
  exit 1
fi

PLUGIN_DIR="$(dirname "$MAIN_FILE")"
PLUGIN_BASENAME="$(basename "$PLUGIN_DIR")"

VERSION="$(grep -E "^[[:space:]]*\\*+[[:space:]]*Version:" "$MAIN_FILE" | head -1 | sed -E 's/.*Version:[[:space:]]*//')"
if [[ -z "$VERSION" ]]; then
  VERSION="0.0.0"
fi

SLUG="$(echo "$PLUGIN_BASENAME" | tr '[:upper:]' '[:lower:]')"

DIST_DIR="$ROOT_DIR/dist"
mkdir -p "$DIST_DIR"

ZIP_NAME="${SLUG}-v${VERSION}.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"

EXCLUDES=(
  "--exclude=${PLUGIN_BASENAME}/.git" "--exclude=${PLUGIN_BASENAME}/.git/*"
  "--exclude=${PLUGIN_BASENAME}/.github" "--exclude=${PLUGIN_BASENAME}/.github/*"
  "--exclude=${PLUGIN_BASENAME}/node_modules" "--exclude=${PLUGIN_BASENAME}/node_modules/*"
  "--exclude=${PLUGIN_BASENAME}/tests" "--exclude=${PLUGIN_BASENAME}/tests/*"
  "--exclude=${PLUGIN_BASENAME}/docs" "--exclude=${PLUGIN_BASENAME}/docs/*"
  "--exclude=${PLUGIN_BASENAME}/coverage" "--exclude=${PLUGIN_BASENAME}/coverage/*"
  "--exclude=${PLUGIN_BASENAME}/dist" "--exclude=${PLUGIN_BASENAME}/dist/*"
  "--exclude=${PLUGIN_BASENAME}/.vscode" "--exclude=${PLUGIN_BASENAME}/.idea"
  "--exclude=${PLUGIN_BASENAME}/composer.lock" "--exclude=${PLUGIN_BASENAME}/package-lock.json"
  "--exclude=${PLUGIN_BASENAME}/.phpunit.result.cache"
)

cd "$(dirname "$PLUGIN_DIR")"

rm -f "$ZIP_PATH"
zip -r "$ZIP_PATH" "$PLUGIN_BASENAME" "${EXCLUDES[@]}"

echo "OK: creato $ZIP_PATH"
