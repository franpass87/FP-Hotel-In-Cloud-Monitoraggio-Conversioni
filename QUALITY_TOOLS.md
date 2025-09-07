# Code Quality Tools for HIC Plugin

This document describes the code quality tools and standards implemented for the FP-Hotel-In-Cloud-Monitoraggio-Conversioni plugin.

## Available Tools

### PHPStan - Static Analysis
- **File**: `phpstan.phar`
- **Configuration**: `phpstan.neon`
- **Level**: 4 (balanced between strictness and WordPress compatibility)
- **Run**: `php phpstan.phar analyse` or `composer phpstan`

### Coding Standards Checker
- **File**: `check-standards.php`
- **Checks**: 
  - Proper PHP opening tags
  - ABSPATH security checks
  - Trailing whitespace
  - Consistent indentation
- **Run**: `php check-standards.php` or `composer phpcs`

## Quick Commands

```bash
# Run all quality checks
composer quality

# Run only static analysis
composer phpstan

# Run only coding standards
composer phpcs

# Fix coding standard violations (whitespace)
composer fix-cs

# Run tests
composer test
```

## Standards Compliance

The plugin now follows these standards:

✅ **PHP Standards**
- No syntax errors
- PHPStan level 4 compliance with WordPress-specific ignores
- Proper type declarations where possible
- Consistent coding style

✅ **WordPress Standards**
- ABSPATH security checks in all include files
- Proper escaping and sanitization
- WordPress function usage patterns

✅ **Security Standards**
- Input validation and sanitization
- Output escaping
- Secure file access patterns

## Configuration Files

- `phpstan.neon` - PHPStan configuration
- `phpcs.xml` - PHP_CodeSniffer configuration (if using full PHPCS)
- `check-standards.php` - Custom basic standards checker

## CI/CD Integration

These tools can be easily integrated into continuous integration pipelines:

```yaml
# Example GitHub Actions step
- name: Run Quality Checks
  run: |
    composer phpcs
    composer phpstan
```

The goal is to maintain high code quality while being practical for WordPress plugin development.