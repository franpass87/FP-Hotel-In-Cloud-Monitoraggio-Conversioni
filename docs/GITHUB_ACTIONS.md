# GitHub Actions Workflows

## Build WordPress Plugin ZIP

This repository includes a GitHub Action workflow that automatically builds a WordPress-ready ZIP file for the plugin.

### Workflow: `build-wordpress-zip.yml`

**Triggers:**
- **Releases**: Automatically runs when a new release is published
- **Tags**: Runs when new version tags (v*) are pushed
- **Manual**: Can be triggered manually via GitHub Actions UI

**What it does:**
1. Sets up PHP 8.3 environment with required extensions
2. Installs production Composer dependencies
3. Builds the WordPress plugin ZIP using the existing build system
4. Uploads the ZIP as a downloadable artifact
5. Attaches the ZIP to releases (when triggered by a release)

**Output:**
- **Artifact**: `wordpress-plugin-zip-v{version}` (retained for 90 days)
- **File**: `FP-Hotel-In-Cloud-Monitoraggio-Conversioni-{version}.zip` (~132KB)
- **Release attachment**: Automatically attached to GitHub releases

### How to Use

#### 1. Manual Build
1. Go to **Actions** tab in GitHub
2. Select **Build WordPress Plugin ZIP** workflow
3. Click **Run workflow**
4. Optionally specify a custom version number
5. Download the ZIP from the artifacts section

#### 2. Automatic on Release
1. Create a new release in GitHub (with or without tag)
2. The workflow runs automatically
3. The ZIP is attached to the release
4. Users can download directly from the release page

#### 3. Automatic on Tag
```bash
git tag v1.4.1
git push origin v1.4.1
```
The workflow runs automatically and creates an artifact.

### Installation Instructions

Once you have the ZIP file:

1. **Download** the ZIP from GitHub Actions artifacts or release
2. **Go to** WordPress Admin → Plugins → Add New → Upload Plugin
3. **Select** the ZIP file and click "Install Now"
4. **Activate** the plugin
5. **Configure** the plugin via Settings → HIC Monitoring

### Workflow Features

- ✅ **Production-ready**: Only includes necessary files, excludes dev dependencies
- ✅ **Version detection**: Automatically extracts version from plugin header
- ✅ **Quality assurance**: Validates composer.json before building
- ✅ **Error handling**: Verifies ZIP creation and provides detailed output
- ✅ **Multiple triggers**: Release, tag, and manual dispatch support
- ✅ **Artifact management**: 90-day retention with clear naming
- ✅ **Release integration**: Automatic attachment to GitHub releases

### Build Process

The workflow leverages the existing build system:

```bash
# Install production dependencies
composer install --no-dev --optimize-autoloader

# Build WordPress ZIP
composer build
```

This creates a clean, optimized ZIP containing:
- Plugin PHP files (`includes/`, main plugin file)
- Assets (CSS, JavaScript)
- Production Composer dependencies only
- Documentation (`README.md`)

**Excluded from ZIP:**
- Development dependencies
- Test files and test data
- Documentation files (except README.md)
- Build scripts and configuration
- Git files and GitHub workflows

### Troubleshooting

**ZIP not created?**
- Check the build step output for errors
- Verify composer.json is valid
- Ensure all required PHP files exist

**Wrong version number?**
- Version is extracted from the plugin header comment
- Ensure `* Version: X.Y.Z` is correctly formatted in the main plugin file
- Use manual trigger with custom version if needed

**Large ZIP file?**
- The ZIP should be ~132KB for this plugin
- If larger, check for included development files
- Review exclude patterns in `build-wordpress-zip.php`