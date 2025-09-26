# HIC Plugin Tests

> **Versione plugin:** 3.4.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


This directory contains automated tests for the HIC Plugin. Le innovazioni per release sono documentate nel [CHANGELOG](../CHANGELOG.md) così da collegare i test alle funzionalità introdotte.

## Running Tests

### Basic Function Tests
```bash
php tests/test-functions.php
```

This will run tests for:
- Bucket normalization logic
- Email validation 
- OTA email detection
- Configuration helpers

### GTM Integration Validation
```bash
php tests/validate-gtm.php
```

This script validates the GTM integration logic and ensures the DataLayer events are structured correctly.

## Test Structure

- `bootstrap.php` - Test environment setup and WordPress function mocks
- `test-functions.php` - Core function tests

## Adding New Tests

To add new tests, create new test files following the pattern:
1. Include `bootstrap.php`
2. Create test class with methods starting with `test`
3. Use assertions to validate expected behavior
4. Add a `runAll()` method to execute all tests

## Future Improvements

- Add PHPUnit integration
- Create integration tests for API calls
- Add performance benchmarks
- Mock external services for isolated testing
