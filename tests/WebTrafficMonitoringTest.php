<?php declare(strict_types=1);
/**
 * Test Web Traffic Monitoring Functionality
 * 
 * This test validates that the web traffic based polling monitoring works correctly
 */

use PHPUnit\Framework\TestCase;

class WebTrafficMonitoringTest extends TestCase {

    private $poller;
    private $original_stats;

    protected function setUp(): void {
        parent::setUp();
        
        // Mock WordPress functions if needed
        if (!function_exists('wp_date')) {
            function wp_date($format, $timestamp = null) {
                return date($format, $timestamp ?: time());
            }
        }
        
        if (!function_exists('current_time')) {
            function current_time($type = 'mysql') {
                return time();
            }
        }
        
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                static $options = array();
                return isset($options[$option]) ? $options[$option] : $default;
            }
        }
        
        if (!function_exists('update_option')) {
            function update_option($option, $value, $autoload = null) {
                static $options = array();
                $options[$option] = $value;
                return true;
            }
        }
        
        if (!function_exists('delete_option')) {
            function delete_option($option) {
                static $options = array();
                unset($options[$option]);
                return true;
            }
        }
        
        if (!function_exists('get_transient')) {
            function get_transient($transient) {
                static $transients = array();
                return isset($transients[$transient]) ? $transients[$transient] : false;
            }
        }
        
        if (!function_exists('set_transient')) {
            function set_transient($transient, $value, $expiration) {
                static $transients = array();
                $transients[$transient] = $value;
                return true;
            }
        }
        
        if (!function_exists('delete_transient')) {
            function delete_transient($transient) {
                static $transients = array();
                unset($transients[$transient]);
                return true;
            }
        }
        
        if (!function_exists('hic_log')) {
            function hic_log($message, $level = 'info', $context = array()) {
                // Mock logging
                return true;
            }
        }
        
        if (!function_exists('is_admin')) {
            function is_admin() {
                return false;
            }
        }
        
        if (!function_exists('wp_doing_ajax')) {
            function wp_doing_ajax() {
                return false;
            }
        }
        
        if (!function_exists('sanitize_text_field')) {
            function sanitize_text_field($str) {
                return strip_tags($str);
            }
        }
        
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', false);
        }
        
        if (!defined('DOING_CRON')) {
            define('DOING_CRON', false);
        }
        
        // Initialize the booking poller
        require_once __DIR__ . '/../includes/constants.php';
        require_once __DIR__ . '/../includes/booking-poller.php';
        
        $this->poller = new \FpHic\HIC_Booking_Poller();
        
        // Store original stats to restore later
        $this->original_stats = get_option('hic_web_traffic_stats', array());
    }

    protected function tearDown(): void {
        // Restore original stats
        if (!empty($this->original_stats)) {
            update_option('hic_web_traffic_stats', $this->original_stats);
        } else {
            delete_option('hic_web_traffic_stats');
        }
        
        parent::tearDown();
    }

    public function testWebTrafficStatsInitialization() {
        // Reset stats
        delete_option('hic_web_traffic_stats');
        
        // Get stats should return defaults
        $stats = $this->poller->get_web_traffic_stats();
        
        $this->assertIsArray($stats);
        $this->assertEquals(0, $stats['total_checks']);
        $this->assertEquals(0, $stats['frontend_checks']);
        $this->assertEquals(0, $stats['admin_checks']);
        $this->assertEquals(0, $stats['recoveries_triggered']);
        $this->assertEquals('Never', $stats['last_frontend_check_formatted']);
        $this->assertEquals('Never', $stats['last_admin_check_formatted']);
    }

    public function testWebTrafficStatsReset() {
        // Set some stats first
        update_option('hic_web_traffic_stats', array(
            'total_checks' => 10,
            'frontend_checks' => 5,
            'recoveries_triggered' => 2
        ));
        
        // Reset stats
        $result = $this->poller->reset_web_traffic_stats();
        
        $this->assertTrue($result);
        
        // Verify stats are reset
        $stats = $this->poller->get_web_traffic_stats();
        $this->assertEquals(0, $stats['total_checks']);
        $this->assertEquals(0, $stats['frontend_checks']);
        $this->assertEquals(0, $stats['recoveries_triggered']);
    }

    public function testRequestContextDetection() {
        // Use reflection to test private method
        $reflection = new ReflectionClass($this->poller);
        $method = $reflection->getMethod('get_current_request_context');
        $method->setAccessible(true);
        
        $context = $method->invoke($this->poller);
        
        $this->assertIsArray($context);
        $this->assertArrayHasKey('type', $context);
        $this->assertArrayHasKey('path', $context);
        $this->assertArrayHasKey('is_admin', $context);
        $this->assertArrayHasKey('is_ajax', $context);
        $this->assertArrayHasKey('is_cron', $context);
        $this->assertArrayHasKey('timestamp', $context);
        
        // Should detect as frontend by default in test environment
        $this->assertEquals('frontend', $context['type']);
        $this->assertFalse($context['is_admin']);
        $this->assertFalse($context['is_ajax']);
        $this->assertFalse($context['is_cron']);
    }

    public function testWebTrafficStatsUpdate() {
        // Reset stats
        delete_option('hic_web_traffic_stats');
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($this->poller);
        $method = $reflection->getMethod('update_web_traffic_stats');
        $method->setAccessible(true);
        
        $context = array(
            'type' => 'frontend',
            'timestamp' => time()
        );
        $polling_lag = 600; // 10 minutes
        
        // Update stats
        $method->invoke($this->poller, $context, $polling_lag);
        
        // Verify stats were updated
        $stats = $this->poller->get_web_traffic_stats();
        $this->assertEquals(1, $stats['total_checks']);
        $this->assertEquals(1, $stats['frontend_checks']);
        $this->assertEquals(0, $stats['admin_checks']);
        $this->assertEquals($polling_lag, $stats['average_polling_lag']);
        $this->assertEquals($polling_lag, $stats['max_polling_lag']);
    }

    public function testWebTrafficRecoveryStatsUpdate() {
        // Reset stats
        delete_option('hic_web_traffic_stats');
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($this->poller);
        $method = $reflection->getMethod('update_web_traffic_recovery_stats');
        $method->setAccessible(true);
        
        $context = array(
            'type' => 'frontend',
            'timestamp' => time()
        );
        $polling_lag = 3600; // 1 hour
        
        // Update recovery stats
        $method->invoke($this->poller, $context, $polling_lag);
        
        // Verify recovery stats were updated
        $stats = $this->poller->get_web_traffic_stats();
        $this->assertEquals(1, $stats['recoveries_triggered']);
        $this->assertEquals('frontend', $stats['last_recovery_via']);
        $this->assertEquals($polling_lag, $stats['last_recovery_lag']);
        $this->assertEquals($context['timestamp'], $stats['last_recovery_time']);
    }

    public function testFormattedStatsDisplay() {
        // Set some test stats
        $test_time = time() - 3600; // 1 hour ago
        update_option('hic_web_traffic_stats', array(
            'total_checks' => 50,
            'frontend_checks' => 30,
            'admin_checks' => 20,
            'last_frontend_check' => $test_time,
            'last_admin_check' => $test_time - 1800, // 30 minutes before that
            'average_polling_lag' => 300, // 5 minutes
            'max_polling_lag' => 1800, // 30 minutes
            'recoveries_triggered' => 3,
            'last_recovery_time' => $test_time - 900 // 15 minutes ago
        ));
        
        $stats = $this->poller->get_web_traffic_stats();
        
        // Check formatted values
        $this->assertNotEquals('Never', $stats['last_frontend_check_formatted']);
        $this->assertNotEquals('Never', $stats['last_admin_check_formatted']);
        $this->assertNotEquals('Never', $stats['last_recovery_time_formatted']);
        $this->assertEquals('5.0 minutes', $stats['average_polling_lag_formatted']);
        $this->assertEquals('30.0 minutes', $stats['max_polling_lag_formatted']);
    }
}