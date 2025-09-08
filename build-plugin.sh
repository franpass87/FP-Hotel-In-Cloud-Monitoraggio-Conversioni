#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="fp-hotel-in-cloud-monitoraggio-conversioni"
MAIN_FILE="FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php"
BUILD_DIR="build/${PLUGIN_SLUG}"

VERSION=$(grep "Version:" "$MAIN_FILE" | sed -E 's/.*Version:\s*([0-9.]+).*/\1/')

echo "Building plugin version $VERSION"

rm -rf build
mkdir -p "$BUILD_DIR"

composer install --no-dev --optimize-autoloader --prefer-dist --no-progress

php qa-runner.php

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
  ./ "$BUILD_DIR/"

cat > "$BUILD_DIR/README.txt" <<EOT
=== HIC GA4 + Brevo + Meta (bucket strategy) ===
Contributors: Francesco Passeri
Tags: analytics, conversion tracking, hotel bookings, ga4, brevo, meta
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: $VERSION
License: GPLv2 or later

WordPress plugin for tracking hotel booking conversions across GA4, Meta CAPI, and Brevo with bucket strategy.

== Description ==

This plugin tracks hotel booking conversions from Hotel in Cloud and sends them to:
- Google Analytics 4 (GA4) as purchase events
- Meta CAPI (Facebook/Instagram) as Purchase events
- Brevo for contact management and events

Features bucket strategy classification (gads/fbads/organic) based on tracking parameters.

== Installation ==

1. Upload the plugin files to '/wp-content/plugins/$PLUGIN_SLUG/'
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin through Settings > HIC Monitoring

== Changelog ==

= $VERSION =
* See GitHub repository for detailed changelog
EOT

cd build
ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
zip -r "$ZIP_NAME" "$PLUGIN_SLUG"

echo "Build complete: build/$ZIP_NAME"
