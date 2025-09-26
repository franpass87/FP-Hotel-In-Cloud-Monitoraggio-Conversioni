# Phase 1 — Discovery Report

## Summary
The FP HIC Monitor plugin is a feature-rich automation suite that orchestrates booking ingestion, multi-channel tracking, diagnostics, and reporting. The codebase spans 40+ PHP modules with extensive background processing (81 WordPress actions, 25 filters), adaptive cron schedules, and a sizeable admin UI footprint. Documentation produced in this phase (`docs/code-map.md`) maps the architecture, hooks, and storage model to support further remediation.

## Key Findings
### Security
* **Raw SQL without `$wpdb->prepare()`:** Modules such as `includes/database-optimizer.php` still perform direct `CREATE INDEX`/`SELECT COUNT(*)` statements via string interpolation (e.g., lines 73–131, 700–723). Although the inputs are currently sourced from internal arrays, hardening with prepared statements or `$wpdb->esc_like` would reduce risk for future refactors and comply with WP coding standards.
* **Capability mismatch on optimizer AJAX:** `ajax_optimize_database()` demands `manage_options` while other plugin endpoints consistently use the custom `hic_manage` capability (lines 744–772). This inconsistency may block delegated admins and should be standardised.
* **File download endpoints use raw `header()`/`exit`:** `hic_ajax_download_error_logs()` streams files manually (lines 1953–2002). It works today but bypasses WP_Filesystem abstractions and lacks chunked output/error handling, raising maintainability and potential header injection concerns if filenames ever become dynamic.

### Performance & Reliability
* **Aggressive cron cadence:** Multiple events run every 30 seconds (`hic_continuous_poll_event`, `hic_process_retry_queue`, etc.), and the poller performs watchdog checks on `init`, `wp`, and `shutdown`. We should ensure conditional scheduling is enforced and consider object caching/transients to minimise redundant work (see `includes/booking-poller.php` around lines 59–120 and `includes/circuit-breaker.php` lines 314–327).
* **Database optimizer heavy operations:** Index management, archive table creation, and optimization routines run synchronously during AJAX calls (`create_optimized_indexes()`, `create_archive_tables()`). Long-running operations can timeout on shared hosting and should move to background jobs or chunked processing.
* **Manual file I/O for logs and exports:** Logging helpers rely on `file_put_contents`, `fopen`, and `touch` (`includes/helpers-logging.php`, `includes/admin/diagnostics.php`). We should add checks for filesystem permissions and consider WP_Filesystem for portability.

### Compatibility & Architecture
* **Manual includes and mixed namespace/global functions:** The bootstrap file manually requires ~20 modules, and several helpers still declare global functions without namespaces. Later refactors should consolidate into autoloadable classes or service containers while preserving the existing API surface.
* **Large procedural helpers file:** `includes/functions.php` houses hundreds of helper functions, fallbacks, and compatibility shims. Splitting into focused components will simplify static analysis in later phases.
* **No documented release automation:** Build scripts exist (`build-plugin.sh`), but there was no changelog entry o release zip per la 3.3.x in `/dist`. Il lavoro della Phase 10 ha introdotto la release 3.4.0 pacchettizzata e documentata per chiudere il gap.

### Testing & Tooling
* PHPUnit configuration and numerous tests are present, but there is no continuous integration workflow. Future phases will need to wire GitHub Actions and ensure tests run against supported PHP/WP versions.
* Existing coding-standard config files (`phpcs.xml`, `phpstan.neon`, `phpmd.xml`) are present but linting has not been executed recently—Phase 2 will tackle automated linting and baseline fixes.

## Next Steps
1. Configure PHPCS and PHPStan runs, address autofixable violations, and capture baseline reports (`docs/audit/linters.txt`).
2. Introduce runtime logging instrumentation for notice collection (Phase 3) and resolve high-severity warnings.
3. Harden AJAX/REST handlers with consistent capability + nonce patterns and replace ad-hoc SQL/file operations with safer abstractions.
4. Profile cron-heavy sections for potential caching and batching opportunities to reduce load.
5. Prepare CI, testing, and release assets in later phases per playbook.

This report feeds into the iterative remediation roadmap captured in `.codex-state.json`.
