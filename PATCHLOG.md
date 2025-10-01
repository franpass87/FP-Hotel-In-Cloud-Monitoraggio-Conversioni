# Patch Log

## 2025-10-01

### Batch 1 — Fatal/activation blockers
- Issues: ISS-0001
- Commit: 99f622f
- Rationale: Reordered namespaces in the CLI demo to remove the fatal error reported during the audit.
- Commands: `php -l demo-without-enhanced.php`
- Notes: Syntax lint passes with namespaces now wrapped in explicit blocks.

### Batch 3 — Frontend broken flows
- Issues: ISS-0002
- Commit: 67f77f5
- Rationale: Replaced the CDN Chart.js dependency with a bundled asset and adjusted ignores so the file is versioned.
- Commands: `php -l includes/performance-analytics-dashboard.php`
- Notes: Verified script registration uses the local asset.

### Batch 5 — i18n and UX polish
- Issues: ISS-0003
- Commit: 7c03612
- Rationale: Localized AJAX responses, REST errors, and inline alerts tied to the admin flows highlighted in the audit.
- Commands: `php -l includes/admin/admin-settings.php`, `php -l includes/google-ads-enhanced.php`, `php -l includes/enterprise-management-suite.php`, `php -l includes/integrations/gtm.php`, `composer install`, `composer lint`
- Notes: Composer dependencies installed to run PHPCS; linting completed without errors.
