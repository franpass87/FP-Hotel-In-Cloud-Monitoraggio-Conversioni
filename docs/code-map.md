# FP Hotel in Cloud Monitoraggio Conversioni — Code Map

## Overview
FP HIC Monitor is a large WordPress plugin that ingests booking data from Hotel in Cloud, normalises it, and dispatches enriched events to GA4, Meta CAPI, Brevo and GTM. The plugin also exposes extensive diagnostics, reporting dashboards, and automated polling/cron orchestration to keep the booking pipeline in sync.

* **Main entry point:** `FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php` (namespace `FpHic`). It loads constants/utilities, registers the uninstall hook, initialises helper hooks and capability assignments, and ensures required subsystems are bootstrapped.
* **Autoloading:** prefers Composer (`vendor/autoload.php`) but falls back to manual `require_once` includes inside the main file.
* **Features:** intelligent polling engine, webhook ingestion, queue-based retry/circuit breaker, dashboards, email/Brevo sync, GA4 + Meta integrations, automated reports, performance/health monitoring, WP‑CLI commands.

## Directory Layout
| Path | Purpose |
| --- | --- |
| `includes/` | Core PHP modules grouped by responsibility (polling, integrations, admin, health, caching, etc.). |
| `includes/admin/` | Admin UI pages, diagnostics, AJAX controllers, capability helpers. |
| `includes/api/` | REST/webhook handlers, polling API bridge, rate limit controls. |
| `includes/integrations/` | Outbound integrations for Brevo, Facebook, GA4, GTM. |
| `assets/` | Admin/front-end CSS & JS bundles (diagnostics, dashboards, enhanced conversions). |
| `languages/` | Translation catalogue for `hotel-in-cloud` text domain. |
| `tests/` | PHPUnit integration/unit tests covering polling, webhooks, integrations. |
| `tools/` & `build-plugin.sh` | Packaging utilities. |
| `docs/` | Project documentation (build workflow, QA tools, audit outputs). |

## Bootstrap Sequence
1. Load localisation via `plugins_loaded`.
2. Require constants, helper libraries, logging, HTTP hardening, validators, caching, rate limiter, polling managers, database and reporting modules.
3. Instantiate helper hooks via `Helpers\hic_init_helper_hooks()` and register uninstall handler `hic_uninstall_plugin`.
4. On activation (`hic_activate`) ensure PHP/WP version compatibility, install DB tables, grant `hic_manage`/`hic_view_logs` capabilities, schedule clean-up hooks, and set up the logging directory.

## Hook Inventory
The plugin registers **81 actions** and **25 filters** (custom collection script, Phase 1). Highlights below focus on the primary entry points.

### WordPress Core Hooks
| Hook | Callback(s) | Purpose |
| --- | --- | --- |
| `plugins_loaded` | anonymous loader | Load translations. |
| `init` | Multiple: `DatabaseOptimizer::maybe_initialize_optimizer`, `HIC_Booking_Poller::ensure_scheduler_is_active`, `Health_Monitor::init_health_monitor`, `GoogleAdsEnhanced::initialize_enhanced_conversions`, etc. | Bootstraps subsystems once WordPress is ready. |
| `admin_menu` | `hic_add_admin_menu`, `CircuitBreaker::add_circuit_breaker_menu`, `Automated_Reporting::add_reports_menu`, `Realtime_Dashboard::add_dashboard_menu`, `EnterpriseManagementSuite::add_setup_wizard_menu` | Register plugin admin pages. |
| `admin_init` | Upgrade routines (`hic_upgrade_reservation_email_map`, `hic_upgrade_integration_retry_queue`), enhanced conversion settings handlers. |
| `admin_enqueue_scripts` | Module-specific enqueue methods (settings, diagnostics, dashboards, circuit breaker, reports). |
| `wp_ajax_*` | 30+ AJAX handlers across admin settings, diagnostics, reporting, dashboards, intelligent polling, circuit breaker, database optimizer. All require `hic_manage` or related capabilities. |
| `rest_api_init` | Webhook + health endpoints registration; GTM event endpoint. |
| `wp_dashboard_setup` | Realtime dashboard widgets and EMS health widget. |
| `wp`/`wp_loaded`/`shutdown` | Scheduler self-healing hooks, logging cleanup. |
| `heartbeat_received` | Booking poller watchdog integration. |

### Custom `hic_*` Action Events & Cron Hooks
These drive background tasks. Core events are scheduled via WP‑Cron wrappers in `helpers-scheduling.php` and module constructors.

| Event | Default Schedule / Trigger | Callback Source |
| --- | --- | --- |
| `hic_continuous_poll_event` | every 30 s (custom interval) | `HIC_Booking_Poller::execute_continuous_polling`. |
| `hic_deep_check_event` | every 30 min | `HIC_Booking_Poller::execute_deep_check`. |
| `hic_fallback_poll_event` | 2 min fallback | `HIC_Booking_Poller::execute_fallback_polling`. |
| `hic_scheduler_restart` | on-demand | `HIC_Booking_Poller::ensure_scheduler_is_active`. |
| `hic_retry_failed_requests` / `hic_cleanup_failed_requests` | 15 min & daily | Retry queue helpers in `includes/functions.php`. |
| `hic_retry_failed_brevo_notifications` | queued when sync fails | `hic_retry_failed_brevo_notifications` helper. |
| `hic_self_healing_recovery` | triggered on watchdog | Poller self-healing routine. |
| `hic_daily_database_maintenance` / `hic_weekly_database_optimization` | daily / weekly | `DatabaseOptimizer` maintenance. |
| `hic_intelligent_poll_event` / `hic_cleanup_connection_pool` | adaptive intervals / hourly | `IntelligentPollingManager`. |
| `hic_performance_cleanup` | daily | `PerformanceMonitor::cleanup_old_metrics`. |
| `hic_process_retry_queue` / `hic_check_circuit_breaker_recovery` | 30 s custom interval | `CircuitBreakerManager`. |
| `hic_store_offline_booking` / `hic_sync_offline_bookings` | triggered by circuit breaker | Offline booking queue persistence. |
| `hic_daily_report`, `hic_weekly_report`, `hic_monthly_report`, `hic_cleanup_exports` | scheduled reporting | `Automated_Reporting`. |
| `hic_enhanced_conversions_batch_upload` | hourly | `GoogleAdsEnhanced::batch_upload_enhanced_conversions`. |
| `hic_daily_reconciliation` / `hic_health_check` | daily 02:00, hourly | `EnterpriseManagementSuite`. |
| `hic_refresh_dashboard_data` | scheduled in realtime dashboard | Cache refresh. |
| `hic_health_monitor_event` | scheduled when monitoring active | `Health_Monitor::run_scheduled_health_check`. |

### Filters
Key filters include `cron_schedules` extensions (`hic_add_failed_request_schedule`, `IntelligentPollingManager::add_intelligent_cron_intervals`), `hic_optimize_query`, `hic_live_log_refresh_interval`, plus numerous output sanitisation helpers (e.g., `hic_mask_sensitive_data` filter chains).

## REST API & Web Endpoints
| Namespace | Route | Methods | Callback | Notes |
| --- | --- | --- | --- | --- |
| `hic/v1` | `/conversion` | POST | `hic_webhook_handler` | Primary webhook ingestion for bookings with token + signature validation. |
| `hic/v1` | `/health` | GET | `Health_Monitor::rest_health_check` | Health diagnostics, level-limited for unauthenticated calls. |
| `hic/v1` | `/gtm-events` | POST | `Integrations\GTM::hic_receive_gtm_event` | Receives GTM event payloads (with request validation). |
| Public AJAX | `/wp-admin/admin-ajax.php?action=hic_health_check` | GET | `Health_Monitor::public_health_check` | Requires token parameter; used for uptime probes. |

## Admin Pages & Assets
| Page | Slug | Capability | Assets |
| --- | --- | --- | --- |
| HIC Dashboard | `hic-monitoring` (top-level) | `hic_manage` | `assets/css/hic-admin.css`, `assets/js/admin-settings.js` (settings), `diagnostics.js`, dashboards, circuit breaker bundles. |
| Settings | `hic-monitoring-settings` | `hic_manage` | Localised scripts for API/email tests, health token generation. |
| Diagnostics | `hic-diagnostics` | `hic_manage` (`hic_view_logs` for log download) | AJAX-heavy diagnostics panel, log streaming, scheduler controls. |
| Circuit Breaker, Realtime Dashboard, Performance Analytics, Reports, EMS Wizard | Various menus from their respective classes using admin CSS/JS under `assets/`. |

## Options, Transients & Storage
The plugin stores configuration and runtime state under the `hic_` prefix. Discovery identified **62 unique `hic_*` options** (scripted scan) covering:

* **Configuration:** `measurement_id`, `api_secret`, `connection_type`, `api_url`, `api_email`, `api_password`, `property_id`, `gtm_container_id`, `tracking_mode`, `health_token`, `webhook_token`, `webhook_secret`, `brevo_*`, `fb_*`, `currency`, feature flags.
* **Runtime Metrics:** polling timestamps (`last_continuous_poll`, `last_deep_check`, `last_successful_poll`), scheduler diagnostics (`last_scheduler_status_message`), activity metrics, retry queues, enhanced conversion queues, dashboard heartbeat, performance averages.
* **Database/Internal State:** `db_version`, optimizer flags (`db_optimizer_initialized`, `index_analysis`), offline booking cache, EMS wizard progress, log metadata.
* **Transients:** `hic_polling_lock`, `hic_api_rate_limit`, `hic_health_check` etc. are defined in `includes/constants.php` and used for locking/caching.

Database tables managed by the plugin include custom tables for GCLIDs, realtime sync, performance metrics, retry queues, and EMS-specific tables (see `includes/database.php` and `enterprise-management-suite.php`).

## Assets & Front-End Integration
* Front-end script: `assets/js/frontend.js` handles data-layer pushes when GTM integration is enabled.
* Enhanced conversions admin assets: `assets/js/enhanced-conversions.js`, CSS `assets/css/admin-settings.css` for the settings UI.
* Dashboards: `performance-dashboard.js/css`, `realtime-dashboard.js/css` support admin widgets with chart rendering.

## WP-CLI Commands
Registered in `includes/cli.php` when WP‑CLI is available:

| Command | Description |
| --- | --- |
| `wp hic poll [--force]` | Trigger manual polling run, optionally bypassing locks. |
| `wp hic stats` | Output scheduler statistics. |
| `wp hic reset --confirm` | Reset polling locks/timestamps and clear scheduled cron. |
| `wp hic cleanup [--logs] [--gclids] [--booking-events] [--realtime-sync]` | Run targeted cleanup routines. |

## Custom Post Types, Taxonomies & Shortcodes
The discovery pass did not find any registered custom post types, taxonomies, or shortcodes in the plugin codebase.

## External Integrations
* **Hotel in Cloud API:** polled via `includes/api/polling.php` with retry/backoff management.
* **Brevo (Sendinblue):** event tracking and contact sync via `includes/integrations/brevo.php` and queue retries.
* **Google Ads Enhanced Conversions:** hashed payload batching and upload queue in `includes/google-ads-enhanced.php`.
* **Facebook Conversion API:** event dispatch from `includes/integrations/facebook.php`.
* **Google Tag Manager / GA4:** data layer injection and measurement protocol dispatch from `includes/integrations/gtm.php` and `includes/integrations/ga4.php`.

---
This document will be updated across phases as the audit and remediation progress.
