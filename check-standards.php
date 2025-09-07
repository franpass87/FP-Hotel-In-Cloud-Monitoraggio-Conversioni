#!/usr/bin/env php
<?php
/**
 * Basic PHP coding standards checker
 */

function checkFile($file) {
    $content = file_get_contents($file);
    $errors = [];
    
    // Check for PHP opening tags
    if (strpos($content, '<?php') !== 0 && strpos($content, '<?php declare(strict_types=1);') !== 0) {
        $errors[] = "File should start with <?php tag";
    }
    
    // Check for ABSPATH check in include files
    if (strpos($file, 'includes/') !== false && 
        strpos($content, "if (!defined('ABSPATH')) exit;") === false &&
        strpos($content, "if (!defined('ABSPATH')) {\n    exit;\n}") === false) {
        $errors[] = "Include files should have ABSPATH check";
    }
    
    // Check for trailing spaces (basic check)
    $lines = explode("\n", $content);
    foreach ($lines as $lineNum => $line) {
        if (preg_match('/\s+$/', $line) && trim($line) !== '') {
            $errors[] = "Line " . ($lineNum + 1) . " has trailing whitespace";
        }
    }
    
    // Check for consistent indentation (spaces vs tabs)
    if (strpos($content, "\t") !== false && strpos($content, "    ") !== false) {
        $errors[] = "Mixed tabs and spaces for indentation";
    }
    
    return $errors;
}

function main() {
    $files = [];
    
    // Get all PHP files
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('includes/'));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    
    // Add main plugin file
    $files[] = 'FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php';
    
    $totalErrors = 0;
    
    foreach ($files as $file) {
        if (!file_exists($file)) continue;
        
        $errors = checkFile($file);
        if (!empty($errors)) {
            echo "FILE: $file\n";
            foreach ($errors as $error) {
                echo "  - $error\n";
                $totalErrors++;
            }
            echo "\n";
        }
    }
    
    if ($totalErrors === 0) {
        echo "✅ No basic coding standard violations found!\n";
        exit(0);
    } else {
        echo "❌ Found $totalErrors coding standard violations\n";
        exit(1);
    }
}

main();