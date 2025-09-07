#!/usr/bin/env php
<?php
/**
 * Quality Assurance Runner for HIC Plugin
 * 
 * This script runs all quality assurance tools in sequence
 */

declare(strict_types=1);

$baseDir = __DIR__;
$colors = [
    'green' => "\033[32m",
    'red' => "\033[31m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'reset' => "\033[0m"
];

function output(string $message, string $color = 'reset'): void {
    global $colors;
    echo $colors[$color] . $message . $colors['reset'] . PHP_EOL;
}

function runCommand(string $command, string $description): bool {
    output("🔍 Running: $description", 'blue');
    output("Command: $command", 'yellow');
    
    $output = [];
    $returnCode = 0;
    exec($command . ' 2>&1', $output, $returnCode);
    
    if ($returnCode === 0) {
        output("✅ $description: PASSED", 'green');
        return true;
    } else {
        output("❌ $description: FAILED", 'red');
        foreach ($output as $line) {
            echo "  $line" . PHP_EOL;
        }
        return false;
    }
}

// Change to project directory
chdir($baseDir);

output('=== HIC Plugin Quality Assurance Runner ===', 'blue');
output('Starting comprehensive code quality checks...', 'blue');

$results = [];
$tools = [];

// 1. PHP Syntax Check (always available)
$tools['syntax'] = [
    'command' => 'find includes/ FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php -name "*.php" -exec php -l {} \;',
    'description' => 'PHP Syntax Check'
];

// 2. PHP_CodeSniffer (WordPress Standards)
if (file_exists('vendor/bin/phpcs')) {
    $tools['phpcs'] = [
        'command' => 'vendor/bin/phpcs --standard=phpcs.xml includes/ FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php',
        'description' => 'WordPress Coding Standards (PHPCS)'
    ];
}

// 3. Parallel Lint (if available)
if (file_exists('vendor/bin/parallel-lint')) {
    $tools['parallel-lint'] = [
        'command' => 'vendor/bin/parallel-lint includes/ FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php',
        'description' => 'Parallel PHP Syntax Check'
    ];
}

// 4. PHPStan Static Analysis
if (file_exists('vendor/bin/phpstan')) {
    $tools['phpstan'] = [
        'command' => 'vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress',
        'description' => 'PHPStan Static Analysis'
    ];
}

// 5. PHP Mess Detector
if (file_exists('vendor/bin/phpmd')) {
    $tools['phpmd'] = [
        'command' => 'vendor/bin/phpmd includes/,FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php text phpmd.xml',
        'description' => 'PHP Mess Detector'
    ];
}

// 6. PHPUnit Tests
if (file_exists('vendor/bin/phpunit')) {
    $tools['phpunit'] = [
        'command' => 'php -d auto_prepend_file=tests/preload.php vendor/bin/phpunit --no-coverage',
        'description' => 'PHPUnit Tests'
    ];
}

// Run all available tools
$passed = 0;
$failed = 0;

foreach ($tools as $toolName => $tool) {
    $result = runCommand($tool['command'], $tool['description']);
    $results[$toolName] = $result;
    
    if ($result) {
        $passed++;
    } else {
        $failed++;
    }
    
    output(''); // Empty line for readability
}

// Summary
output('=== Quality Assurance Summary ===', 'blue');
output("Tools run: " . count($tools), 'blue');
output("✅ Passed: $passed", 'green');

if ($failed > 0) {
    output("❌ Failed: $failed", 'red');
    output('Please fix the issues above before proceeding.', 'yellow');
    exit(1);
} else {
    output("🎉 All quality checks passed!", 'green');
    output('Code is ready for production!', 'green');
    exit(0);
}