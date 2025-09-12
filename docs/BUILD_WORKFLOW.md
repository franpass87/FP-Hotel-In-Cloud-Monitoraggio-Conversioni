# WordPress Plugin Build and Release Workflow

This document describes the automated GitHub Actions workflow for building and releasing the HIC WordPress plugin.

## Overview

The workflow automatically creates a production-ready ZIP file of the WordPress plugin that can be distributed and installed on WordPress sites.

## Workflow Files

- `.github/workflows/build-release.yml` - Main build and release workflow
- `.github/workflows/quality.yml` - Code quality assurance workflow (existing)

## Triggers

The build workflow is triggered by:

1. **Version Tags**: When you push a tag like `v3.1.0`, `v2.5.0`, etc.
2. **GitHub Releases**: When you create a release through GitHub UI
3. **Manual Trigger**: Can be manually triggered from the Actions tab

## Build Process

The workflow performs these steps:

1. **Quality Assurance**: Runs the `qa-runner.php` script to ensure code quality
2. **Dependency Installation**: Installs production dependencies only (`composer install --no-dev`)
3. **File Preparation**: Copies only production files, excluding:
   - Development files (tests/, docs/, phpunit.xml, etc.)
   - Git files (.git, .github/)
   - Configuration files (phpstan.neon, phpcs.xml, etc.)
   - Documentation files (*.md)
   - Development scripts (qa-runner.php, demo files)

4. **ZIP Creation**: Creates a distributable ZIP file named `fp-hotel-in-cloud-monitoraggio-conversioni-v{version}.zip`
5. **Artifact Upload**: Uploads the ZIP as a GitHub Actions artifact
6. **Release Attachment**: If triggered by a release, attaches the ZIP to the GitHub release

## Output

The workflow produces:

- **ZIP File**: `fp-hotel-in-cloud-monitoraggio-conversioni-v{version}.zip` (~136KB)
- **Contents**: Production-ready WordPress plugin with:
  - Main plugin file: `FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php`
  - All PHP includes and functionality
  - CSS and JavaScript assets
  - Composer autoloader (vendor/)
  - WordPress-standard README.txt file

## How to Create a Release

### Method 1: Using Git Tags

```bash
# Tag the current commit with a version
git tag v3.1.0
git push origin v3.1.0
```

### Method 2: Using GitHub Releases

1. Go to the repository on GitHub
2. Click "Releases" in the right sidebar
3. Click "Create a new release"
4. Choose or create a tag (e.g., `v3.1.0`)
5. Fill in release notes
6. Click "Publish release"

The workflow will automatically build and attach the plugin ZIP to the release.

## Manual Testing

You can test the build process locally:

```bash
# Install production dependencies
composer install --no-dev --optimize-autoloader

# Run quality checks
./qa-runner.php

# Create build directory
mkdir -p build/fp-hotel-in-cloud-monitoraggio-conversioni

# Copy files (excluding dev files)
rsync -av \
  --exclude='.git*' \
  --exclude='.github/' \
  --exclude='tests/' \
  --exclude='docs/' \
  --exclude='*.md' \
  --exclude='qa-runner.php' \
  ./ build/fp-hotel-in-cloud-monitoraggio-conversioni/

# Create ZIP
cd build
zip -r fp-hotel-in-cloud-monitoraggio-conversioni-v3.1.0.zip fp-hotel-in-cloud-monitoraggio-conversioni
```

## Version Management

The workflow automatically extracts the version from the main plugin file:

```php
* Version: 3.1.0
```

Make sure to update this version number in `FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php` before creating releases.

## Troubleshooting

- **Build Fails**: Check the Actions logs for quality check failures
- **Missing Files**: Verify the rsync exclude patterns in the workflow
- **Wrong Version**: Ensure the version in the main plugin file matches your tag
- **Large ZIP**: Check if unnecessary files are being included

## Integration with WordPress

The generated ZIP can be:

1. **Uploaded via WordPress Admin**: Plugins → Add New → Upload Plugin
2. **Extracted to wp-content/plugins/**: Manual installation
3. **Distributed via WordPress.org**: (requires additional plugin directory submission)

The plugin follows WordPress plugin standards and includes a proper README.txt file for compatibility.