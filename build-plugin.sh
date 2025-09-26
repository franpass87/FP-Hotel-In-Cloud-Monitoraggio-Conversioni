#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="fp-hotel-in-cloud-monitoraggio-conversioni"
BUILD_DIR="build/${PLUGIN_SLUG}"
DIST_DIR="dist"

VERSION=$(php -r '$file=file_get_contents("FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php"); if(!preg_match("/^ \* Version:\\s*(.+)$/m", $file, $m)){fwrite(STDERR, "Unable to detect plugin version\\n"); exit(1);} echo trim($m[1]);')

ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"

echo "Cleaning and preparing build directory"
rm -rf build "$DIST_DIR"
mkdir -p "$BUILD_DIR" "$DIST_DIR"

# Copy plugin files excluding development and meta files
rsync -av \
  --exclude='.git*' \
  --exclude='.github/' \
  --exclude='tests/' \
  --exclude='docs/' \
  --exclude='phpunit.xml' \
  --exclude='phpstan.neon' \
  --exclude='phpcs.xml' \
  --exclude='phpmd.xml' \
  --exclude='phpstan-stubs/' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='qa-runner.php' \
  --exclude='demo-*' \
  --exclude='*.md' \
  --exclude='.phpunit.result.cache' \
  --exclude='build/' \
  --exclude='build-plugin.sh' \
  ./ "$BUILD_DIR/"

pushd build >/dev/null

echo "Creating ZIP archive: $ZIP_NAME"
zip -rq "$ZIP_NAME" "$PLUGIN_SLUG"

popd >/dev/null

mv "build/$ZIP_NAME" "$DIST_DIR/$ZIP_NAME"

pushd "$DIST_DIR" >/dev/null
sha256sum "$ZIP_NAME" > "$ZIP_NAME.sha256"
popd >/dev/null

echo "Build complete: $DIST_DIR/$ZIP_NAME"
echo "Checksum stored at $DIST_DIR/$ZIP_NAME.sha256"
