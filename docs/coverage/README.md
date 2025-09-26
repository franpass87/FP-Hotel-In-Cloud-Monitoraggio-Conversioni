# Test Coverage

The automated test suite can generate HTML coverage artifacts with a supported coverage driver (Xdebug, PCOV, or phpdbg). Run the following command from the project root once a driver is enabled:

```
php -d auto_prepend_file=tests/preload.php vendor/bin/phpunit --coverage-html docs/coverage/html
```

If no coverage driver is installed the command will exit with a `No code coverage driver available` warning, and no HTML reports will be produced. Install Xdebug or PCOV on the executing machine—or invoke PHPUnit through `phpdbg -qrr`—before retrying.
