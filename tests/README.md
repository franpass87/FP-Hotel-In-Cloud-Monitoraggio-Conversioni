# HIC Plugin Tests

This directory contains automated tests for the HIC Plugin.

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