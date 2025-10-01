# Fix Changelog

| ID | File | Line | Severity | Fix summary | Commit |
| --- | --- | --- | --- | --- | --- |
| ISSUE-001 | includes/helpers-logging.php | 150 | High | Hardened log/export directories with Apache 2.4 rules and runtime request guards. | 262e11c |
| ISSUE-002 | includes/automated-reporting.php | 2184 | Medium | Streamed booking metrics exports via chunked queries to avoid memory spikes. | 7fa63b4 |
| ISSUE-003 | includes/automated-reporting.php | 2027 | Low | Localized automated reporting AJAX responses and download errors. | 561dbfb |

## Summary

- Issues resolved: 3/3 (Critical: 0, High: 1, Medium: 1, Low: 1)
- Postponed issues: none
- Fix phase completed on 2025-10-01
