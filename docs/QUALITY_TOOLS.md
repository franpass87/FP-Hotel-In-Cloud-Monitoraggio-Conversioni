# PHP Quality Assurance Tools

> **Versione plugin:** 3.3.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


This directory contains comprehensive PHP standards compliance tools for the HIC Plugin. Gli aggiornamenti per release sono documentati nel [CHANGELOG](../CHANGELOG.md) per contestualizzare le pratiche di qualità rispetto alle feature introdotte.

## Tools Included

### 1. PHPStan - Static Analysis
- **Configuration**: `phpstan.neon`
- **Purpose**: Type checking, bug detection, and code analysis
- **Level**: 5 (balanced between strictness and practicality)
- **WordPress Integration**: Uses `szepeviktor/phpstan-wordpress` for WordPress compatibility

### 2. PHP_CodeSniffer - Coding Standards
- **Configuration**: `phpcs.xml`
- **Purpose**: WordPress coding standards compliance
- **Standards**: WordPress, PSR-12 compatible

### 3. PHP Mess Detector - Code Quality
- **Configuration**: `phpmd.xml`
- **Purpose**: Code smell detection, complexity analysis
- **Rules**: Clean code, design patterns, naming conventions

### 4. Parallel Lint - Syntax Checking
- **Purpose**: Fast PHP syntax validation
- **Coverage**: All PHP files in includes/ and main plugin file

## Usage

### Quick Quality Check
```bash
# Run all available quality tools
./qa-runner.php
```

### Individual Tools
```bash
# Coding standards
composer lint

# Static analysis
composer analyse

# Mess detection
composer mess

# Syntax check
composer lint:syntax

# Tests
composer test
```

### Comprehensive Quality Suite
```bash
# Run everything
composer quality

# Fix auto-fixable issues
composer quality:fix
```

## Integration with Development Workflow

### Pre-commit Hook (Recommended)
Add to `.git/hooks/pre-commit`:
```bash
#!/bin/bash
./qa-runner.php
```

### CI/CD Integration
The quality tools can be integrated into GitHub Actions:
```yaml
- name: Quality Assurance
  run: ./qa-runner.php
```

## Configuration Guidelines

### PHPStan Configuration (`phpstan.neon`)
- Level 5 provides good balance between strictness and WordPress compatibility
- WordPress-specific ignores for common patterns
- Bootstrap includes constants.php for proper analysis

### PHPMD Configuration (`phpmd.xml`)
- WordPress-friendly rules (allows globals, underscores in function names)
- Focuses on real code smells rather than WordPress conventions
- Excludes overly restrictive rules for WordPress development

### PHPCS Configuration (`phpcs.xml`)
- WordPress coding standards
- Excludes vendor directory
- Covers all PHP files in the project

## Maintenance

### Updating Baselines
When adding new code that generates warnings:
```bash
# Update PHPStan baseline
composer analyse:baseline
```

### Adding New Rules
Edit the respective configuration files:
- `phpstan.neon` for static analysis rules
- `phpmd.xml` for mess detection rules
- `phpcs.xml` for coding standards

## Troubleshooting

### Common Issues

1. **WordPress function not found**: 
   - Ensure `php-stubs/wordpress-stubs` is installed
   - Check bootstrap configuration in `phpstan.neon`

2. **False positives in PHPMD**:
   - Add specific exclusions to `phpmd.xml`
   - WordPress patterns may need custom rules

3. **Memory issues with PHPStan**:
   - Increase PHP memory limit: `php -d memory_limit=1G vendor/bin/phpstan`

### Performance Tips

- Use `--no-progress` flag for CI environments
- Run tools in parallel when possible
- Use PHPStan result cache (enabled by default)

## Benefits

✅ **Early Bug Detection**: PHPStan catches type errors and logical issues  
✅ **Consistent Code Style**: PHPCS ensures WordPress coding standards  
✅ **Code Quality Metrics**: PHPMD identifies potential improvements  
✅ **Automated Workflow**: Composer scripts for easy execution  
✅ **CI/CD Ready**: Scripts work in automated environments  
✅ **WordPress Optimized**: All tools configured for WordPress development  

This setup ensures high code quality while maintaining WordPress compatibility and development efficiency.
