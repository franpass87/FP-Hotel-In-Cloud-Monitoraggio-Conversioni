#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="fp-hotel-in-cloud-monitoraggio-conversioni"
BUILD_DIR="build/${PLUGIN_SLUG}"

echo "Cleaning and preparing build directory"
rm -rf build
mkdir -p "$BUILD_DIR"

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

cd build
ZIP_NAME="${PLUGIN_SLUG}.zip"

echo "Creating ZIP archive: $ZIP_NAME"
zip -r "$ZIP_NAME" "$PLUGIN_SLUG"

echo "Build complete: build/$ZIP_NAME"
