# QA Baseline

- **Command:** `composer qa --no-ansi`
- **Date:** 2025-09-30T07:39:45Z

## Output

```
PHPUnit 11.5.41 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.12
Configuration: /workspace/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/phpunit.xml

...............................................................  63 / 231 ( 27%)
..............................................................S 126 / 231 ( 54%)
............................................................... 189 / 231 ( 81%)
..........................................                      231 / 231 (100%)

Time: 00:01.142, Memory: 18.00 MB

OK, but there were issues!
Tests: 231, Assertions: 1268, PHPUnit Deprecations: 4, Skipped: 1.
```

## Notes

- `vendor/bin/phpcs` produced no output (0 errors, 0 warnings).
- `vendor/bin/phpstan analyse` produced no output (0 errors).
- `vendor/bin/phpunit` reported 4 deprecations and 1 skipped test (pre-existing baseline).
