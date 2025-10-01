#!/bin/sh
set -eu

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR_BASENAME="$(basename "$PROJECT_ROOT")"
SLUG="$(printf '%s' "$PLUGIN_DIR_BASENAME" | tr 'A-Z' 'a-z')"
BUILD_DIR="$PROJECT_ROOT/build"
STAGING_DIR="$BUILD_DIR/$SLUG"

BUMP_ACTION=""
SET_VERSION=""
ZIP_NAME=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --set-version=*)
            SET_VERSION="${1#*=}"
            ;;
        --set-version)
            if [ "$#" -lt 2 ]; then
                echo "Missing value for --set-version" >&2
                exit 1
            fi
            shift
            SET_VERSION="$1"
            ;;
        --bump=*)
            BUMP_ACTION="${1#*=}"
            ;;
        --bump)
            BUMP_ACTION="patch"
            ;;
        --zip-name=*)
            ZIP_NAME="${1#*=}"
            ;;
        --zip-name)
            if [ "$#" -lt 2 ]; then
                echo "Missing value for --zip-name" >&2
                exit 1
            fi
            shift
            ZIP_NAME="$1"
            ;;
        --help|-h)
            cat <<'EOF'
Usage: build.sh [options]

Options:
  --set-version=X.Y.Z   Set an explicit version before building.
  --bump=TYPE           Bump the version (patch, minor, major). Default: patch.
  --zip-name=NAME       Override the default zip name.
  -h, --help            Show this help message.
EOF
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            exit 1
            ;;
    esac
    shift
done

if [ -n "$SET_VERSION" ] && [ -n "$BUMP_ACTION" ]; then
    echo "Cannot use --set-version together with --bump" >&2
    exit 1
fi

if [ -n "$SET_VERSION" ]; then
    php "$PROJECT_ROOT/tools/bump-version.php" --set="$SET_VERSION"
elif [ -n "$BUMP_ACTION" ]; then
    case "$BUMP_ACTION" in
        major|minor|patch)
            php "$PROJECT_ROOT/tools/bump-version.php" --"$BUMP_ACTION"
            ;;
        *)
            echo "Invalid bump type: $BUMP_ACTION" >&2
            exit 1
            ;;
    esac
fi

cd "$PROJECT_ROOT"

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer dump-autoload -o --classmap-authoritative

rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR"

rsync -a \
    --exclude=.git \
    --exclude=.github \
    --exclude=tests \
    --exclude=docs \
    --exclude=node_modules \
    --exclude='*.md' \
    --exclude=.idea \
    --exclude=.vscode \
    --exclude=build \
    --exclude=.gitattributes \
    --exclude=.gitignore \
    --exclude=.codex-state.json \
    --exclude=.phpunit.result.cache \
    --exclude=build.sh \
    --exclude=README-BUILD.md \
    --exclude=build-plugin.sh \
    --exclude=scripts \
    --exclude='demo-*' \
    --exclude=qa-runner.php \
    --exclude='validate-*.php' \
    ./ "$STAGING_DIR/"

TIMESTAMP="$(date +%Y%m%d%H%M)"
DEFAULT_ZIP_NAME="$SLUG-$TIMESTAMP.zip"
FINAL_ZIP_NAME="${ZIP_NAME:-$DEFAULT_ZIP_NAME}"
FINAL_ZIP_PATH="$BUILD_DIR/$FINAL_ZIP_NAME"

rm -f "$FINAL_ZIP_PATH"

(
    cd "$BUILD_DIR"
    zip -r "$FINAL_ZIP_PATH" "$SLUG" >/dev/null
)

FINAL_VERSION="$(php -r 'preg_match("/(?:^|\\n)\\s*\\*\\s*Version:\\s*([^\\r\\n]+)/", file_get_contents($argv[1]), $m) ? print $m[1] : exit(1);' "$PROJECT_ROOT/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php")"

echo "Build completed"
printf 'Version: %s\n' "$FINAL_VERSION"
printf 'Zip: %s\n' "$FINAL_ZIP_PATH"
