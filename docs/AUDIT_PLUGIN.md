# Plugin Audit Report — FP HIC Monitor — 2025-01-16

## Summary
- Files scanned: 176/176
- Issues found: 3 (Critical: 0 | High: 1 | Medium: 1 | Low: 1)
- Key risks:
  - Log and export archives rely on Apache-only rules, leaving sensitive files exposed on Nginx/Apache 2.4 without mod_access_compat.
  - CSV exports read the full booking metrics table into memory, risking timeouts on large datasets.
  - Automated reporting AJAX responses skip localization, degrading UX and translation coverage.
- Recommended priorities: 1) Harden file storage directories, 2) Streamline booking metrics exports, 3) Localize automated reporting responses.

## Manifest mismatch
- Previous manifest hash: 55e3d936f9e3b25b6eb44f1fb2b28d047a81419fab2ab45f23e17b29fad696dc
- Rebuilt manifest hash: 85a5cf00282ec13df9d43147a348b31c6e1a1376d7babbe8464304a01a1a2e2e
- Added files (83): entire `tests/` suite (80 files) and CLI utilities under `tools/` (bump-version.php, make-pot.php, runtime-smoke-check.php).
- Removed files: none.

The previous manifest omitted automated tests and internal tooling; they are now scheduled for audit in subsequent batches.

## Issues
### [High] Sensitive log/export directories rely on Apache-specific protection
- ID: ISSUE-001
- File: includes/helpers-logging.php:80-101 / includes/automated-reporting.php:174-181
- Snippet:
  ```php
  $desiredHtaccess = "Order allow,deny\nDeny from all\n";
  // ...
  if (!file_exists($htaccess_path)) {
      $htaccess_content = "Order deny,allow\nDeny from all\n";
      if (@file_put_contents($htaccess_path, $htaccess_content) === false) {
          // ...
      }
  }
  ```

Diagnosis: Log (`wp-content/uploads/hic-logs`) and export (`wp-content/uploads/hic-exports`) directories are shielded only by legacy `.htaccess` directives. On Apache 2.4 without `mod_access_compat`, or on Nginx/LiteSpeed where `.htaccess` is ignored, these controls fail and the PII-rich `hic-log.txt`/CSV files become publicly readable.

Impact: Security/privacy. Attackers can download raw logs or exported customer data directly once they know the predictable paths.

Repro steps (se applicabile):
1. Install on an Nginx host.
2. Generate an export/log.
3. Fetch `https://example.com/wp-content/uploads/hic-logs/hic-log.txt` – file is served.

Proposed fix (concise):
- Write `.htaccess` files that also emit `Require all denied`.
- Always drop an `index.php` placeholder in both folders.
- Add a `template_redirect` (or `init`) guard that terminates front-end requests hitting those paths so Nginx users are covered.
- Consider storing sensitive artifacts outside the public uploads root when possible.

Side effects / Regression risk: Low – adding extra denial directives and runtime guards is isolated to file serving.

Est. effort: M

Tags: #security #files #privacy #nginx

### [Medium] Booking metrics export loads entire dataset into memory
- ID: ISSUE-002
- File: includes/automated-reporting.php:2183-2210
- Snippet:
  ```php
  $sql = "
      SELECT
          reservation_id,
          sid,
          channel,
          utm_source,
          utm_medium,
          utm_campaign,
          utm_content,
          utm_term,
          amount,
          currency,
          is_refund,
          status,
          created_at,
          updated_at
      FROM `{$table_name}`
      WHERE {$date_condition}
      ORDER BY created_at DESC
  ";

  return $wpdb->get_results($sql, ARRAY_A);
  ```

Diagnosis: `get_raw_data_for_period()` pulls the whole `hic_booking_metrics` result set into PHP arrays before writing CSV/XLSX. On busy hotels this table can exceed tens of thousands of rows, easily exhausting memory/timeouts on shared hosts.

Impact: Performance. Manual exports or scheduled reports can fatally crash PHP, leaving incomplete archives and blocking admin tasks.

Repro steps (se applicabile): Trigger a CSV export on a site with >50k records; observe memory spike and fatal error.

Proposed fix (concise):
- Fetch rows in chunks (`LIMIT ... OFFSET` or primary-key pagination) and stream them to the file handle.
- Alternatively leverage `wpdb->prepare()` with `found_rows` off and write incrementally to avoid holding all rows in memory.

Side effects / Regression risk: Medium – streaming logic needs testing across both CSV and XLSX outputs.

Est. effort: M

Tags: #performance #export #wpdb

### [Low] Automated reporting AJAX responses bypass translation functions
- ID: ISSUE-003
- File: includes/automated-reporting.php:1951-1972
- Snippet:
  ```php
  if (!check_ajax_referer('hic_reporting_nonce', 'nonce', false)) {
      wp_send_json_error('Invalid nonce');
  }
  // ...
  if (!in_array($submitted_report_type, $allowed_report_types, true)) {
      wp_send_json_error('Invalid report type');
  }
  ```

Diagnosis: Error/success strings returned by the reporting AJAX endpoints are hard-coded English strings without `__()`/`esc_html__()`. This breaks localization and mixes untranslated text in otherwise localized admin screens.

Impact: UX/i18n. Site owners using translated backends see inconsistent messaging.

Repro steps (se applicabile): Switch WordPress locale to Italian, trigger a validation error on “Manual Report” – response shows English text.

Proposed fix (concise):
- Wrap responses with translation helpers (`wp_send_json_error( __( 'Invalid nonce', 'hotel-in-cloud' ) );`).
- Ensure related notices/messages consistently use the plugin text-domain.

Side effects / Regression risk: Minimal – pure string adjustments.

Est. effort: S

Tags: #i18n #admin-ajax

## Conflicts & Duplicates
- `includes/api/webhook.php` and `src/Http/Controllers/WebhookController.php` both implement the conversion webhook. The legacy file is conditionally disabled via `HIC_S2S_DISABLE_LEGACY_WEBHOOK_ROUTE`, but keeping both increases maintenance cost. Recommendation: consolidate on the namespaced controller and remove the legacy route once backward compatibility is no longer needed.

## Deprecated & Compatibility
- `.htaccess` rules rely on `Order/Deny` directives deprecated in Apache 2.4; add `Require all denied` to maintain protection.
- No PHP 8.2/8.3 notices observed during static review, but ensure future string functions handle nullables defensively.

## Performance Hotspots
- `includes/automated-reporting.php:2183-2210` – paginate/stream booking metrics exports to avoid OOM on large datasets.

## i18n & A11y
- Automated reporting AJAX responses (see ISSUE-003) need localization wrappers.

## Test Coverage
- PHPUnit suite present but no targeted tests for export streaming or file-permission guards. Consider adding integration tests once fixes land.

## Next Steps (per fase di FIX)
- Ordine consigliato: ISSUE-001 → ISSUE-002 → ISSUE-003
- Safe-fix batch plan:
  1. Harden log/export storage (ISSUE-001).
  2. Stream booking metrics exports (ISSUE-002).
  3. Localize automated reporting responses (ISSUE-003).
