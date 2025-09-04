<?php
/**
 * HIC Plugin System Health and Performance Checker
 * 
 * This file provides functions to check system health and performance
 * that can be used both in CLI and admin interface.
 */

if (!defined('ABSPATH')) {
    // If not in WordPress, define basic constants for CLI usage
    define('ABSPATH', dirname(__DIR__) . '/');
}

/**
 * Main system health and performance checker class
 */
class HIC_System_Checker {
    
    private $checks = [];
    private $performance_metrics = [];
    
    public function __construct() {
        $this->init_checks();
    }
    
    /**
     * Initialize all system checks
     */
    private function init_checks() {
        $this->checks = [
            'core_functions' => 'checkCoreFunctions',
            'configuration' => 'checkConfiguration',
            'integrations' => 'checkIntegrations',
            'performance' => 'checkPerformance',
            'security' => 'checkSecurity',
            'polling_system' => 'checkPollingSystem',
            'error_handling' => 'checkErrorHandling',
            'resource_usage' => 'checkResourceUsage'
        ];
    }
    
    /**
     * Run all system checks
     */
    public function runAllChecks($verbose = false) {
        $results = [];
        $overall_score = 0;
        $max_score = count($this->checks) * 100;
        
        if ($verbose) {
            echo "🔧 HIC Plugin System Health Check\n";
            echo "=================================\n\n";
        }
        
        foreach ($this->checks as $check_name => $method) {
            if ($verbose) {
                echo "🔍 Checking: " . ucwords(str_replace('_', ' ', $check_name)) . "...\n";
            }
            
            $start_time = microtime(true);
            $result = $this->$method();
            $check_time = microtime(true) - $start_time;
            
            $result['check_time'] = $check_time;
            $results[$check_name] = $result;
            $overall_score += $result['score'];
            
            if ($verbose) {
                $status = $result['status'] === 'pass' ? '✅' : ($result['status'] === 'warning' ? '⚠️' : '❌');
                echo "   {$status} {$result['message']} ({$result['score']}/100)\n";
                
                if (!empty($result['details'])) {
                    foreach ($result['details'] as $detail) {
                        echo "      • {$detail}\n";
                    }
                }
                echo "\n";
            }
        }
        
        $overall_percentage = round(($overall_score / $max_score) * 100);
        
        if ($verbose) {
            echo "📊 Overall System Health: {$overall_percentage}%\n";
            echo $this->getHealthRecommendation($overall_percentage) . "\n";
        }
        
        return [
            'overall_score' => $overall_percentage,
            'results' => $results,
            'recommendation' => $this->getHealthRecommendation($overall_percentage)
        ];
    }
    
    /**
     * Check core functions
     */
    private function checkCoreFunctions() {
        $score = 100;
        $details = [];
        $issues = [];
        
        // Check essential functions exist
        $required_functions = [
            'hic_get_measurement_id', 'hic_get_api_secret', 'hic_is_brevo_enabled',
            'hic_normalize_price', 'hic_is_valid_email', 'fp_normalize_bucket',
            'hic_is_ota_alias_email'
        ];
        
        foreach ($required_functions as $func) {
            if (!function_exists($func)) {
                $score -= 15;
                $issues[] = "Missing function: {$func}";
            } else {
                $details[] = "Function available: {$func}";
            }
        }
        
        // Test function performance
        if (function_exists('hic_normalize_price')) {
            $start = microtime(true);
            for ($i = 0; $i < 1000; $i++) {
                hic_normalize_price('1.234,56');
            }
            $time = microtime(true) - $start;
            
            if ($time > 0.1) {
                $score -= 10;
                $issues[] = "Price normalization performance slow: {$time}s for 1000 calls";
            } else {
                $details[] = "Price normalization performance good: {$time}s for 1000 calls";
            }
        }
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'All core functions working properly' : 'Some core functions have issues',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Check configuration
     */
    private function checkConfiguration() {
        $score = 100;
        $details = [];
        $issues = [];
        
        // Check constants
        $required_constants = [
            'HIC_CONTINUOUS_POLLING_INTERVAL', 'HIC_DEEP_CHECK_INTERVAL',
            'HIC_API_TIMEOUT', 'HIC_BUCKET_GADS', 'HIC_BUCKET_FBADS'
        ];
        
        foreach ($required_constants as $constant) {
            if (!defined($constant)) {
                $score -= 10;
                $issues[] = "Missing constant: {$constant}";
            } else {
                $details[] = "Constant defined: {$constant}";
            }
        }
        
        // Check configuration values are reasonable
        if (defined('HIC_CONTINUOUS_POLLING_INTERVAL') && HIC_CONTINUOUS_POLLING_INTERVAL < 30) {
            $score -= 5;
            $issues[] = "Polling interval too aggressive: " . HIC_CONTINUOUS_POLLING_INTERVAL . "s";
        }
        
        if (defined('HIC_API_TIMEOUT') && HIC_API_TIMEOUT < 10) {
            $score -= 5;
            $issues[] = "API timeout too short: " . HIC_API_TIMEOUT . "s";
        }
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'Configuration is optimal' : 'Configuration has some issues',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Check integrations
     */
    private function checkIntegrations() {
        $score = 100;
        $details = [];
        $issues = [];
        
        // Check GA4 configuration
        if (function_exists('hic_get_measurement_id')) {
            $measurement_id = hic_get_measurement_id();
            if (empty($measurement_id)) {
                $score -= 20;
                $issues[] = "GA4 Measurement ID not configured";
            } else {
                $details[] = "GA4 Measurement ID configured";
            }
        }
        
        // Check Brevo configuration
        if (function_exists('hic_is_brevo_enabled') && function_exists('hic_get_brevo_api_key')) {
            if (hic_is_brevo_enabled()) {
                $api_key = hic_get_brevo_api_key();
                if (empty($api_key)) {
                    $score -= 20;
                    $issues[] = "Brevo enabled but API key missing";
                } else {
                    $details[] = "Brevo configured and enabled";
                }
            } else {
                $details[] = "Brevo integration disabled";
            }
        }
        
        // Check Facebook configuration
        if (function_exists('hic_get_fb_pixel_id')) {
            $pixel_id = hic_get_fb_pixel_id();
            if (empty($pixel_id)) {
                $score -= 20;
                $issues[] = "Facebook Pixel ID not configured";
            } else {
                $details[] = "Facebook Pixel ID configured";
            }
        }
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'All integrations properly configured' : 'Some integrations need attention',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Check performance
     */
    private function checkPerformance() {
        $score = 100;
        $details = [];
        $issues = [];
        
        // Test data processing performance
        $start_memory = memory_get_usage(true);
        $start_time = microtime(true);
        
        // Simulate processing workload
        for ($i = 0; $i < 5000; $i++) {
            $email = "user{$i}@example.com";
            $price = rand(100, 1000);
            $gclid = rand(0, 1) ? "CL{$i}" : null;
            $fbclid = rand(0, 1) ? "FB{$i}" : null;
            
            if (function_exists('hic_is_valid_email')) {
                hic_is_valid_email($email);
            }
            if (function_exists('hic_normalize_price')) {
                hic_normalize_price($price);
            }
            if (function_exists('fp_normalize_bucket')) {
                fp_normalize_bucket($gclid, $fbclid);
            }
        }
        
        $processing_time = microtime(true) - $start_time;
        $memory_used = memory_get_usage(true) - $start_memory;
        
        // Evaluate performance
        if ($processing_time > 1.0) {
            $score -= 30;
            $issues[] = "Processing performance slow: {$processing_time}s for 5000 items";
        } else {
            $details[] = "Processing performance good: {$processing_time}s for 5000 items";
        }
        
        if ($memory_used > 10 * 1024 * 1024) { // 10MB
            $score -= 20;
            $issues[] = "High memory usage: " . round($memory_used / 1024 / 1024, 2) . "MB";
        } else {
            $details[] = "Memory usage optimal: " . round($memory_used / 1024 / 1024, 2) . "MB";
        }
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'Performance is excellent' : 'Performance could be improved',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Check security measures
     */
    private function checkSecurity() {
        $score = 100;
        $details = [];
        $issues = [];
        
        // Check if sanitization functions work
        if (function_exists('sanitize_text_field')) {
            $test_input = '<script>alert("test")</script>test';
            $sanitized = sanitize_text_field($test_input);
            if (strpos($sanitized, '<script>') !== false) {
                $score -= 30;
                $issues[] = "Text sanitization not working properly";
            } else {
                $details[] = "Text sanitization working";
            }
        }
        
        // Check email validation security
        if (function_exists('hic_is_valid_email')) {
            $malicious_emails = ['<script>@test.com', 'test@<script>.com', 'javascript:alert(1)'];
            foreach ($malicious_emails as $email) {
                if (hic_is_valid_email($email)) {
                    $score -= 10;
                    $issues[] = "Email validation allows malicious input: {$email}";
                }
            }
            if (empty($issues)) {
                $details[] = "Email validation security good";
            }
        }
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'Security measures are robust' : 'Some security issues found',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Check polling system
     */
    private function checkPollingSystem() {
        $score = 100;
        $details = [];
        $issues = [];
        
        // Check if polling classes exist
        if (!class_exists('HIC_Booking_Poller')) {
            $score -= 50;
            $issues[] = "Booking poller class not found";
        } else {
            $details[] = "Booking poller class available";
        }
        
        // Check polling functions
        $polling_functions = ['hic_acquire_polling_lock', 'hic_release_polling_lock'];
        foreach ($polling_functions as $func) {
            if (!function_exists($func)) {
                $score -= 15;
                $issues[] = "Missing polling function: {$func}";
            } else {
                $details[] = "Polling function available: {$func}";
            }
        }
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'Polling system ready' : 'Polling system has issues',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Check error handling
     */
    private function checkErrorHandling() {
        $score = 100;
        $details = [];
        $issues = [];
        
        // Test error handling with invalid inputs
        try {
            if (function_exists('hic_normalize_price')) {
                $result = hic_normalize_price(null);
                if ($result !== 0.0) {
                    $score -= 20;
                    $issues[] = "Price normalization doesn't handle null properly";
                } else {
                    $details[] = "Price normalization handles null correctly";
                }
            }
            
            if (function_exists('hic_is_valid_email')) {
                $result = hic_is_valid_email(null);
                if ($result !== false) {
                    $score -= 20;
                    $issues[] = "Email validation doesn't handle null properly";
                } else {
                    $details[] = "Email validation handles null correctly";
                }
            }
        } catch (Exception $e) {
            $score -= 30;
            $issues[] = "Error handling threw exception: " . $e->getMessage();
        }
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'Error handling is robust' : 'Error handling needs improvement',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Check resource usage
     */
    private function checkResourceUsage() {
        $score = 100;
        $details = [];
        $issues = [];
        
        $initial_memory = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        
        // Convert memory limit to bytes
        $memory_limit_bytes = $this->convertToBytes($memory_limit);
        
        if ($initial_memory > ($memory_limit_bytes * 0.5)) {
            $score -= 20;
            $issues[] = "High initial memory usage: " . round($initial_memory / 1024 / 1024, 2) . "MB";
        } else {
            $details[] = "Memory usage reasonable: " . round($initial_memory / 1024 / 1024, 2) . "MB";
        }
        
        // Check if there are any obviously inefficient patterns
        $details[] = "Memory limit: {$memory_limit}";
        
        return [
            'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
            'score' => $score,
            'message' => empty($issues) ? 'Resource usage is optimal' : 'Resource usage could be optimized',
            'details' => array_merge($details, $issues)
        ];
    }
    
    /**
     * Convert memory limit string to bytes
     */
    private function convertToBytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = intval($val);
        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }
    
    /**
     * Get health recommendation based on score
     */
    private function getHealthRecommendation($score) {
        if ($score >= 90) {
            return "🎉 Excellent! All systems are functioning optimally and performing well.";
        } elseif ($score >= 80) {
            return "✅ Good! Most systems are working well with minor improvements possible.";
        } elseif ($score >= 70) {
            return "⚠️  Fair! Some systems need attention to improve performance and reliability.";
        } elseif ($score >= 60) {
            return "❌ Poor! Multiple systems have issues that should be addressed.";
        } else {
            return "🚨 Critical! Major system issues detected that require immediate attention.";
        }
    }
}

// CLI usage
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    // Include necessary files for CLI
    require_once __DIR__ . '/bootstrap.php';
    
    $checker = new HIC_System_Checker();
    $results = $checker->runAllChecks(true);
    
    exit($results['overall_score'] >= 80 ? 0 : 1);
}