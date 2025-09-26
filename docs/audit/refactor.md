# Phase 7 – Refactoring Audit

## Overview
- Introduced a dedicated bootstrap layer under `includes/bootstrap/` with a `ModuleLoader` to centralise the plugin file loading strategy and a `Lifecycle` helper that encapsulates multisite-aware activation and capability management logic.
- Simplified `FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php` so it delegates lifecycle work to the new helpers, reducing duplicate `require` statements and ensuring init/admin modules are loaded predictably.
- Preserved public function signatures (`hic_activate`, `hic_for_each_site`, `hic_ensure_admin_capabilities`) by turning them into light-weight proxies, keeping backward compatibility with existing tests and integrations.

## Key Changes
- Collapsed the scattered `require_once` calls into `ModuleLoader::loadCore()`, `ModuleLoader::loadInit()`, and `ModuleLoader::loadAdmin()` groups to prevent future drift between runtime contexts.
- Hardened multisite provisioning by routing the `wpmu_new_blog` hook through `Lifecycle::registerNetworkProvisioningHook()` which safely aborts when switching blogs fails.
- Ensured init hooks now share the same loader instance, avoiding redundant file includes and guaranteeing the log/performance/health services bootstrap after their dependencies load.

## Follow-up Notes
- The new bootstrap layer makes it easier to move toward class-based service registration in a future iteration (e.g., dependency injection or service providers).
- Subsequent phases should update unit tests to cover the new lifecycle helpers directly and consider promoting them to a namespace-autoloaded `src/` directory.
