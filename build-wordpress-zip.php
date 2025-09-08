<?php
/**
 * WordPress Plugin ZIP Builder
 * 
 * Creates a production-ready ZIP file for WordPress plugin installation
 * Excludes development files and includes only production dependencies
 * 
 * Usage: php build-wordpress-zip.php [output-directory]
 */

declare(strict_types=1);

// Configuration
$pluginName = 'FP-Hotel-In-Cloud-Monitoraggio-Conversioni';
$version = '1.4.0'; // Extract from main plugin file
$outputDir = $argv[1] ?? './dist';
$zipFilename = "{$pluginName}-{$version}.zip";

// Colors for console output
class Console {
    const GREEN = "\033[32m";
    const BLUE = "\033[34m";
    const YELLOW = "\033[33m";
    const RED = "\033[31m";
    const RESET = "\033[0m";
    
    public static function info(string $message): void {
        echo self::BLUE . "ℹ " . $message . self::RESET . PHP_EOL;
    }
    
    public static function success(string $message): void {
        echo self::GREEN . "✓ " . $message . self::RESET . PHP_EOL;
    }
    
    public static function warning(string $message): void {
        echo self::YELLOW . "⚠ " . $message . self::RESET . PHP_EOL;
    }
    
    public static function error(string $message): void {
        echo self::RED . "✗ " . $message . self::RESET . PHP_EOL;
    }
}

// Files and directories to include in the ZIP
$includePatterns = [
    'FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php',
    'README.md',
    'includes/',
    'assets/',
    'vendor/',
    'languages/' // If exists
];

// Files and directories to exclude from the ZIP
$excludePatterns = [
    '.git/',
    '.github/',
    'tests/',
    'docs/',
    'dist/',
    'phpstan-stubs/',
    '.gitignore',
    '.phpunit.result.cache',
    'composer.json',
    'composer.lock',
    'phpcs.xml',
    'phpmd.xml',
    'phpstan.neon',
    'phpunit.xml',
    'qa-runner.php',
    'demo-*.sh',
    'demo-*.html',
    '*.md', // Exclude all markdown except README.md
    'build-wordpress-zip.php'
];

// Exception for README.md - we want to keep it
$keepFiles = ['README.md'];

function extractVersionFromPlugin(string $pluginFile): string {
    if (!file_exists($pluginFile)) {
        return '1.0.0';
    }
    
    $content = file_get_contents($pluginFile);
    if (preg_match('/\* Version:\s*(.+)/', $content, $matches)) {
        return trim($matches[1]);
    }
    
    return '1.0.0';
}

function shouldExcludeFile(string $relativePath, array $excludePatterns, array $keepFiles): bool {
    // First check if it's a file we specifically want to keep
    $filename = basename($relativePath);
    if (in_array($filename, $keepFiles)) {
        return false;
    }
    
    foreach ($excludePatterns as $pattern) {
        // Handle directory patterns (ending with /)
        if (str_ends_with($pattern, '/')) {
            if (str_starts_with($relativePath, $pattern) || $relativePath === rtrim($pattern, '/')) {
                return true;
            }
        }
        // Handle file patterns
        elseif ($pattern === $relativePath || fnmatch($pattern, $relativePath)) {
            return true;
        }
    }
    
    return false;
}

function copyFiles(string $source, string $destination, array $includePatterns, array $excludePatterns, array $keepFiles): void {
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    
    foreach ($includePatterns as $pattern) {
        $sourcePath = $source . DIRECTORY_SEPARATOR . $pattern;
        
        if (is_file($sourcePath)) {
            // Single file
            if (!shouldExcludeFile($pattern, $excludePatterns, $keepFiles)) {
                copy($sourcePath, $destination . DIRECTORY_SEPARATOR . $pattern);
                Console::info("Including: {$pattern}");
            } else {
                Console::info("Excluding: {$pattern}");
            }
        } elseif (is_dir($sourcePath)) {
            // Directory
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $item) {
                $itemRelativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $item->getPathname());
                $itemRelativePath = str_replace('\\', '/', $itemRelativePath);
                
                if (shouldExcludeFile($itemRelativePath, $excludePatterns, $keepFiles)) {
                    continue;
                }
                
                $destPath = $destination . DIRECTORY_SEPARATOR . $itemRelativePath;
                
                if ($item->isDir()) {
                    if (!is_dir($destPath)) {
                        mkdir($destPath, 0755, true);
                    }
                } else {
                    $destDir = dirname($destPath);
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }
                    copy($item->getPathname(), $destPath);
                    Console::info("Including: {$itemRelativePath}");
                }
            }
        }
    }
}

function createZip(string $sourceDir, string $zipPath): bool {
    $zip = new ZipArchive();
    
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return false;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $item->getPathname());
        $relativePath = str_replace('\\', '/', $relativePath);
        
        if ($item->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($item->getPathname(), $relativePath);
        }
    }
    
    return $zip->close();
}

// Main execution
Console::info("🚀 Building WordPress Plugin ZIP for {$pluginName}");

// Extract version from plugin file
$version = extractVersionFromPlugin($pluginName . '.php');
$zipFilename = "{$pluginName}-{$version}.zip";

Console::info("Version detected: {$version}");

// Check if composer dependencies are installed
if (!file_exists('vendor/autoload.php')) {
    Console::warning("Composer dependencies not found. Installing production dependencies...");
    $composerCmd = 'composer install --no-dev --optimize-autoloader';
    exec($composerCmd, $output, $returnCode);
    
    if ($returnCode !== 0) {
        Console::error("Failed to install composer dependencies");
        exit(1);
    }
    Console::success("Composer dependencies installed");
}

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    Console::success("Created output directory: {$outputDir}");
}

// Create temporary build directory
$tempDir = $outputDir . '/temp-' . uniqid();
$pluginTempDir = $tempDir . '/' . $pluginName;

Console::info("Creating temporary build directory: {$tempDir}");
mkdir($tempDir, 0755, true);
mkdir($pluginTempDir, 0755, true);

try {
    // Copy files to temporary directory
    Console::info("Copying plugin files...");
    copyFiles('.', $pluginTempDir, $includePatterns, $excludePatterns, $keepFiles);
    
    // Create ZIP file
    $zipPath = $outputDir . '/' . $zipFilename;
    Console::info("Creating ZIP file: {$zipPath}");
    
    if (createZip($pluginTempDir, $zipPath)) {
        Console::success("✅ ZIP file created successfully: {$zipFilename}");
        Console::success("📁 Location: " . realpath($zipPath));
        Console::success("📏 Size: " . formatBytes(filesize($zipPath)));
    } else {
        Console::error("Failed to create ZIP file");
        exit(1);
    }
    
} finally {
    // Clean up temporary directory
    Console::info("Cleaning up temporary files...");
    removeDirectory($tempDir);
}

Console::success("🎉 Build completed successfully!");
Console::info("📦 WordPress-ready plugin ZIP: {$zipFilename}");
Console::info("🔧 Installation: Upload via WordPress Admin > Plugins > Add New > Upload Plugin");

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function removeDirectory(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    
    rmdir($dir);
}