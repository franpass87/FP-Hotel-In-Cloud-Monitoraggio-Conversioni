# Phase 9 · Upgrade & Migrations Audit

## Objectives
- Persist the active plugin version per site to support deterministic upgrade flows.
- Ensure database and capability installers rerun automatically when upgrading from legacy releases.
- Flush runtime caches (object cache and OPcache) whenever schema or bootstrap code changes are deployed.

## Changes Implemented
- Added a dedicated `UpgradeManager` bootstrapper that records the installed plugin version, runs versioned migration callbacks, and flushes cache layers after updates.
- Hooked upgrade execution into both `plugins_loaded` and the core upgrader pipeline so multisite and manual deployments run migrations only once per request.
- Extended lifecycle activation and multisite provisioning routines to record the current plugin version for every site.

## Validation
- Manual inspection via `php -l includes/bootstrap/upgrade-manager.php` to confirm syntax integrity.
- Triggered the upgrade routine in a test environment to verify option writes, cache flushes, and database migrations execute without notices.

## Follow-up Work
- Phase 10 will focus on documentation and release packaging, including generating the distribution ZIP and updating the changelog.
