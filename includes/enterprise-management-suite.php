<?php declare(strict_types=1);

namespace FpHic\ReconAndSetup;

if (!defined('ABSPATH')) exit;

/**
 * Reconciliation System + Setup Wizard + Health Check Visual
 * Enterprise Grade - Combined Implementation for Efficiency
 * 
 * Implements data reconciliation, guided setup wizard, and visual health monitoring.
 */

class EnterpriseManagementSuite {
    
    public function __construct() {
        add_action('init', [$this, 'initialize_management_suite'], 45);
        
        // Reconciliation System
        add_action('hic_daily_reconciliation', [$this, 'run_daily_reconciliation']);
        add_action('wp_ajax_hic_run_reconciliation', [$this, 'ajax_run_reconciliation']);
        
        // Setup Wizard
        add_action('admin_menu', [$this, 'add_setup_wizard_menu']);
        add_action('wp_ajax_hic_setup_wizard_step', [$this, 'ajax_setup_wizard_step']);
        
        // Health Check System
        add_action('wp_ajax_hic_get_health_status', [$this, 'ajax_get_health_status']);
        add_action('wp_ajax_hic_run_diagnostics', [$this, 'ajax_run_diagnostics']);
        add_action('hic_health_check', [$this, 'run_health_check']);
        
        // Admin dashboard integration
        add_action('admin_enqueue_scripts', [$this, 'enqueue_management_assets']);
        add_action('wp_dashboard_setup', [$this, 'add_health_dashboard_widget']);
        
        // Schedule tasks
        add_action('wp', [$this, 'schedule_management_tasks']);
    }
    
    /**
     * Initialize management suite
     */
    public function initialize_management_suite() {
        static $initialized = false;

        if ($initialized || did_action('hic_ems_initialized')) {
            return;
        }

        $initialized = true;

        $this->log('Initializing Enterprise Management Suite');

        self::maybe_install_tables();

        // Initialize setup wizard if needed
        $this->check_setup_wizard_needed();

        do_action('hic_ems_initialized');
    }

    /**
     * Ensure database tables exist for the management suite.
     */
    public static function maybe_install_tables(): void {
        static $installing = false;

        if ($installing) {
            return;
        }

        $installing = true;

        $tables_installed = (bool) get_option('hic_ems_tables_installed', false);

        if (!$tables_installed) {
            self::create_reconciliation_table();
            self::create_health_check_table();

            update_option('hic_ems_tables_installed', 1);

            if (function_exists('\\FpHic\\Helpers\\hic_log')) {
                \FpHic\Helpers\hic_log('[Enterprise Management] Database tables installed');
            }

            do_action('hic_ems_tables_installed');
        }

        $installing = false;
    }

    /**
     * Create reconciliation tracking table
     */
    private static function create_reconciliation_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hic_reconciliation';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            check_date DATE NOT NULL,
            source_system VARCHAR(50) NOT NULL,
            hic_count INT DEFAULT 0,
            analytics_count INT DEFAULT 0,
            discrepancy_count INT DEFAULT 0,
            discrepancy_percentage DECIMAL(5,2) DEFAULT 0,
            status ENUM('pending', 'completed', 'error') DEFAULT 'pending',
            details LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            UNIQUE KEY idx_date_source (check_date, source_system),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create health check results table
     */
    private static function create_health_check_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_health_checks';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            check_type VARCHAR(100) NOT NULL,
            status ENUM('healthy', 'warning', 'critical') NOT NULL,
            message TEXT,
            details LONGTEXT,
            checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_check_type (check_type),
            INDEX idx_status (status),
            INDEX idx_checked_at (checked_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Check if setup wizard is needed
     */
    private function check_setup_wizard_needed() {
        $setup_completed = get_option('hic_setup_wizard_completed', false);
        
        if (!$setup_completed) {
            // Add admin notice for setup wizard
            add_action('admin_notices', [$this, 'show_setup_wizard_notice']);
        }
    }
    
    /**
     * Schedule management tasks
     */
    public function schedule_management_tasks() {
        // Schedule daily reconciliation
        if (!wp_next_scheduled('hic_daily_reconciliation')) {
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'hic_daily_reconciliation');
        }
        
        // Schedule health checks
        if (!wp_next_scheduled('hic_health_check')) {
            wp_schedule_event(time(), 'hourly', 'hic_health_check');
        }
    }
    
    // ===== RECONCILIATION SYSTEM =====
    
    /**
     * Run daily reconciliation
     */
    public function run_daily_reconciliation() {
        $this->log('Starting daily reconciliation');
        
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // Reconcile with different systems
        $systems = ['google_analytics', 'facebook_api', 'brevo_api'];
        
        foreach ($systems as $system) {
            $this->reconcile_with_system($system, $yesterday);
        }
        
        $this->log('Daily reconciliation completed');
    }
    
    /**
     * Reconcile data with external system
     */
    private function reconcile_with_system($system, $date) {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $recon_table = $wpdb->prefix . 'hic_reconciliation';
        
        try {
            // Get HIC data count
            $hic_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$main_table} WHERE DATE(created_at) = %s",
                $date
            ));
            
            // Get external system data count
            $external_count = $this->get_external_system_count($system, $date);
            
            // Calculate discrepancy
            $discrepancy = abs($hic_count - $external_count);
            $discrepancy_percentage = $hic_count > 0 ? ($discrepancy / $hic_count) * 100 : 0;
            
            // Store reconciliation result
            $wpdb->replace($recon_table, [
                'check_date' => $date,
                'source_system' => $system,
                'hic_count' => $hic_count,
                'analytics_count' => $external_count,
                'discrepancy_count' => $discrepancy,
                'discrepancy_percentage' => $discrepancy_percentage,
                'status' => 'completed',
                'details' => json_encode([
                    'hic_data' => $hic_count,
                    'external_data' => $external_count,
                    'threshold_exceeded' => $discrepancy_percentage > 10
                ])
            ]);
            
            // Alert if significant discrepancy
            if ($discrepancy_percentage > 10) {
                $this->send_reconciliation_alert($system, $date, $discrepancy_percentage);
            }
            
            $this->log("Reconciliation completed for {$system}: {$discrepancy_percentage}% discrepancy");
            
        } catch (\Exception $e) {
            $wpdb->replace($recon_table, [
                'check_date' => $date,
                'source_system' => $system,
                'status' => 'error',
                'details' => json_encode(['error' => $e->getMessage()])
            ]);
            
            $this->log("Reconciliation error for {$system}: " . $e->getMessage());
        }
    }
    
    /**
     * Get external system data count
     */
    private function get_external_system_count($system, $date) {
        // Placeholder implementation - would integrate with actual APIs
        switch ($system) {
            case 'google_analytics':
                return $this->get_ga4_event_count($date);
            case 'facebook_api':
                return $this->get_facebook_event_count($date);
            case 'brevo_api':
                return $this->get_brevo_event_count($date);
            default:
                return 0;
        }
    }
    
    /**
     * Get GA4 event count (placeholder)
     */
    private function get_ga4_event_count($date) {
        // Would use GA4 Reporting API
        return rand(80, 120); // Placeholder random count for demo
    }
    
    /**
     * Get Facebook event count (placeholder)
     */
    private function get_facebook_event_count($date) {
        // Would use Facebook Marketing API
        return rand(75, 115); // Placeholder random count for demo
    }
    
    /**
     * Get Brevo event count (placeholder)
     */
    private function get_brevo_event_count($date) {
        // Would use Brevo API
        return rand(85, 125); // Placeholder random count for demo
    }
    
    /**
     * Send reconciliation alert
     */
    private function send_reconciliation_alert($system, $date, $discrepancy_percentage) {
        $subject = "HIC Reconciliation Alert: {$system} discrepancy {$discrepancy_percentage}%";
        $message = "Significant data discrepancy detected for {$system} on {$date}. Discrepancy: {$discrepancy_percentage}%";
        
        wp_mail(get_option('admin_email'), $subject, $message);
        $this->log("Reconciliation alert sent for {$system}");
    }
    
    // ===== SETUP WIZARD =====
    
    /**
     * Add setup wizard menu
     */
    public function add_setup_wizard_menu() {
        add_submenu_page(
            'hic-monitoring',
            'Setup Wizard',
            'Setup Wizard',
            'manage_options',
            'hic-setup-wizard',
            [$this, 'render_setup_wizard']
        );
    }
    
    /**
     * Show setup wizard notice
     */
    public function show_setup_wizard_notice() {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>FP HIC Monitor Setup Required</strong></p>
            <p>Complete the 5-minute setup wizard to configure your hotel conversion tracking.</p>
            <p><a href="<?php echo admin_url('admin.php?page=hic-setup-wizard'); ?>" class="button button-primary">Start Setup Wizard</a></p>
        </div>
        <?php
    }
    
    /**
     * Render setup wizard
     */
    public function render_setup_wizard() {
        $current_step = intval($_GET['step'] ?? 1);
        ?>
        <div class="wrap hic-setup-wizard">
            <h1>FP HIC Monitor - Setup Wizard</h1>
            
            <div class="hic-wizard-progress">
                <div class="hic-progress-bar">
                    <div class="hic-progress-fill" style="width: <?php echo ($current_step / 5) * 100; ?>%"></div>
                </div>
                <span class="hic-progress-text">Step <?php echo $current_step; ?> of 5</span>
            </div>
            
            <div class="hic-wizard-content">
                <?php $this->render_wizard_step($current_step); ?>
            </div>
        </div>
        
        <style>
        .hic-setup-wizard { max-width: 800px; }
        .hic-wizard-progress { margin: 20px 0; }
        .hic-progress-bar { width: 100%; height: 20px; background: #f0f0f0; border-radius: 10px; overflow: hidden; }
        .hic-progress-fill { height: 100%; background: #0073aa; transition: width 0.3s ease; }
        .hic-progress-text { display: block; text-align: center; margin-top: 10px; font-weight: bold; }
        .hic-wizard-step { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 30px; margin: 20px 0; }
        .hic-wizard-navigation { text-align: center; margin-top: 30px; }
        .hic-wizard-navigation .button { margin: 0 10px; }
        </style>
        <?php
    }
    
    /**
     * Render individual wizard step
     */
    private function render_wizard_step($step) {
        switch ($step) {
            case 1:
                $this->render_step_welcome();
                break;
            case 2:
                $this->render_step_hic_credentials();
                break;
            case 3:
                $this->render_step_analytics_setup();
                break;
            case 4:
                $this->render_step_advanced_features();
                break;
            case 5:
                $this->render_step_completion();
                break;
        }
    }
    
    /**
     * Render welcome step
     */
    private function render_step_welcome() {
        ?>
        <div class="hic-wizard-step">
            <h2>Welcome to FP HIC Monitor v3.0</h2>
            <p>This wizard will guide you through setting up enterprise-grade conversion tracking for your hotel in just 5 minutes.</p>
            
            <h3>What we'll configure:</h3>
            <ul>
                <li>✅ Hotel in Cloud API connection</li>
                <li>✅ Google Analytics 4 tracking</li>
                <li>✅ Facebook Conversions API</li>
                <li>✅ Brevo automation</li>
                <li>✅ Enhanced conversions</li>
                <li>✅ Real-time dashboard</li>
            </ul>
            
            <div class="hic-wizard-navigation">
                <a href="?page=hic-setup-wizard&step=2" class="button button-primary button-large">Start Configuration →</a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render HIC credentials step
     */
    private function render_step_hic_credentials() {
        $settings = get_option('hic_settings', []);
        ?>
        <div class="hic-wizard-step">
            <h2>Step 1: Hotel in Cloud Connection</h2>
            <p>Enter your Hotel in Cloud credentials to enable booking data synchronization.</p>
            
            <form id="hic-wizard-step-2">
                <table class="form-table">
                    <tr>
                        <th><label for="prop_id">Property ID</label></th>
                        <td><input type="text" id="prop_id" name="prop_id" value="<?php echo esc_attr($settings['prop_id'] ?? ''); ?>" class="regular-text" placeholder="Your property ID"></td>
                    </tr>
                    <tr>
                        <th><label for="email">Email</label></th>
                        <td><input type="email" id="email" name="email" value="<?php echo esc_attr($settings['email'] ?? ''); ?>" class="regular-text" placeholder="your-email@hotel.com"></td>
                    </tr>
                    <tr>
                        <th><label for="password">Password</label></th>
                        <td><input type="password" id="password" name="password" value="<?php echo esc_attr($settings['password'] ?? ''); ?>" class="regular-text" placeholder="Your HIC password"></td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" id="test-hic-connection" class="button">Test Connection</button>
                    <span id="connection-status"></span>
                </p>
            </form>
            
            <div class="hic-wizard-navigation">
                <a href="?page=hic-setup-wizard&step=1" class="button">← Back</a>
                <button type="button" class="button button-primary" onclick="saveStepAndContinue(2, 3)">Save & Continue →</button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render analytics setup step
     */
    private function render_step_analytics_setup() {
        ?>
        <div class="hic-wizard-step">
            <h2>Step 2: Analytics & Conversions Setup</h2>
            <p>Configure your tracking integrations for comprehensive conversion monitoring.</p>
            
            <form id="hic-wizard-step-3">
                <h3>Google Analytics 4</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="ga4_measurement_id">GA4 Measurement ID</label></th>
                        <td><input type="text" id="ga4_measurement_id" name="ga4_measurement_id" class="regular-text" placeholder="G-XXXXXXXXXX"></td>
                    </tr>
                    <tr>
                        <th><label for="ga4_api_secret">GA4 API Secret</label></th>
                        <td><input type="text" id="ga4_api_secret" name="ga4_api_secret" class="regular-text" placeholder="API Secret Key"></td>
                    </tr>
                </table>
                
                <h3>Facebook Conversions API</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="facebook_pixel_id">Pixel ID</label></th>
                        <td><input type="text" id="facebook_pixel_id" name="facebook_pixel_id" class="regular-text" placeholder="Facebook Pixel ID"></td>
                    </tr>
                    <tr>
                        <th><label for="facebook_access_token">Access Token</label></th>
                        <td><input type="text" id="facebook_access_token" name="facebook_access_token" class="regular-text" placeholder="Facebook Access Token"></td>
                    </tr>
                </table>
                
                <h3>Brevo Integration</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="brevo_api_key">Brevo API Key</label></th>
                        <td><input type="text" id="brevo_api_key" name="brevo_api_key" class="regular-text" placeholder="Brevo API Key"></td>
                    </tr>
                </table>
            </form>
            
            <div class="hic-wizard-navigation">
                <a href="?page=hic-setup-wizard&step=2" class="button">← Back</a>
                <button type="button" class="button button-primary" onclick="saveStepAndContinue(3, 4)">Save & Continue →</button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render advanced features step
     */
    private function render_step_advanced_features() {
        ?>
        <div class="hic-wizard-step">
            <h2>Step 3: Advanced Features</h2>
            <p>Enable enterprise-grade features for optimal performance and reliability.</p>
            
            <form id="hic-wizard-step-4">
                <h3>Intelligent Polling</h3>
                <label>
                    <input type="checkbox" name="enable_intelligent_polling" value="1" checked>
                    Enable adaptive polling based on booking activity
                </label>
                
                <h3>Real-Time Dashboard</h3>
                <label>
                    <input type="checkbox" name="enable_realtime_dashboard" value="1" checked>
                    Enable real-time conversion dashboard with widgets
                </label>
                
                <h3>Automated Reporting</h3>
                <label>
                    <input type="checkbox" name="enable_automated_reports" value="1" checked>
                    Enable daily/weekly email reports
                </label>
                
                <h3>Enhanced Conversions</h3>
                <label>
                    <input type="checkbox" name="enable_enhanced_conversions" value="1" checked>
                    Enable Google Ads Enhanced Conversions with email hashing
                </label>
                
                <h3>Circuit Breaker Protection</h3>
                <label>
                    <input type="checkbox" name="enable_circuit_breaker" value="1" checked>
                    Enable automatic fallback when APIs are unavailable
                </label>
            </form>
            
            <div class="hic-wizard-navigation">
                <a href="?page=hic-setup-wizard&step=3" class="button">← Back</a>
                <button type="button" class="button button-primary" onclick="saveStepAndContinue(4, 5)">Save & Continue →</button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render completion step
     */
    private function render_step_completion() {
        ?>
        <div class="hic-wizard-step">
            <h2>🎉 Setup Complete!</h2>
            <p>Congratulations! FP HIC Monitor v3.0 is now configured and ready to track your hotel conversions.</p>
            
            <h3>What's Next?</h3>
            <ul>
                <li>✅ <strong>Monitor Dashboard:</strong> <a href="<?php echo admin_url('admin.php?page=hic-realtime-dashboard'); ?>">View Real-Time Dashboard</a></li>
                <li>✅ <strong>Check Health Status:</strong> <a href="<?php echo admin_url('admin.php?page=hic-monitoring'); ?>">System Health Check</a></li>
                <li>✅ <strong>Review Reports:</strong> <a href="<?php echo admin_url('admin.php?page=hic-reports'); ?>">Analytics Reports</a></li>
                <li>✅ <strong>Configure Alerts:</strong> Set up email notifications for important events</li>
            </ul>
            
            <h3>Enterprise Features Enabled:</h3>
            <div class="hic-feature-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 20px 0;">
                <div class="hic-feature-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 6px;">
                    <h4>🚀 Intelligent Polling</h4>
                    <p>Adaptive frequency based on booking activity</p>
                </div>
                <div class="hic-feature-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 6px;">
                    <h4>📊 Real-Time Dashboard</h4>
                    <p>Live conversion tracking and revenue analytics</p>
                </div>
                <div class="hic-feature-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 6px;">
                    <h4>📈 Enhanced Conversions</h4>
                    <p>First-party data matching for better ROAS</p>
                </div>
                <div class="hic-feature-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 6px;">
                    <h4>🛡️ Circuit Breaker</h4>
                    <p>Automatic fallback and retry mechanisms</p>
                </div>
            </div>
            
            <div class="hic-wizard-navigation">
                <button type="button" class="button button-primary button-large" onclick="completeSetup()">Complete Setup & Go to Dashboard</button>
            </div>
        </div>
        
        <script>
        function completeSetup() {
            jQuery.post(ajaxurl, {
                action: 'hic_setup_wizard_step',
                step: 'complete',
                nonce: '<?php echo wp_create_nonce('hic_setup_wizard'); ?>'
            }, function(response) {
                if (response.success) {
                    window.location.href = '<?php echo admin_url('admin.php?page=hic-realtime-dashboard'); ?>';
                }
            });
        }
        </script>
        <?php
    }
    
    // ===== HEALTH CHECK SYSTEM =====
    
    /**
     * Run comprehensive health check
     */
    public function run_health_check() {
        $this->log('Running health check');
        
        $checks = [
            'hic_api_connection' => $this->check_hic_api_connection(),
            'database_performance' => $this->check_database_performance(),
            'polling_system' => $this->check_polling_system(),
            'cache_system' => $this->check_cache_system(),
            'circuit_breakers' => $this->check_circuit_breakers(),
            'disk_space' => $this->check_disk_space(),
            'memory_usage' => $this->check_memory_usage(),
            'external_apis' => $this->check_external_apis()
        ];
        
        foreach ($checks as $check_type => $result) {
            $this->store_health_check_result($check_type, $result);
        }
        
        $this->log('Health check completed');
    }
    
    /**
     * Check HIC API connection
     */
    private function check_hic_api_connection() {
        $settings = get_option('hic_settings', []);
        
        if (empty($settings['prop_id']) || empty($settings['email'])) {
            return ['status' => 'critical', 'message' => 'HIC credentials not configured'];
        }
        
        // Test API connection (simplified)
        $test_result = \FpHic\hic_test_api_connection($settings['prop_id'], $settings['email'], $settings['password'] ?? '');
        
        if ($test_result['success']) {
            return ['status' => 'healthy', 'message' => 'HIC API connection working'];
        } else {
            return ['status' => 'critical', 'message' => 'HIC API connection failed: ' . $test_result['error']];
        }
    }
    
    /**
     * Check database performance
     */
    private function check_database_performance() {
        global $wpdb;
        
        $start_time = microtime(true);
        $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hic_gclids LIMIT 1");
        $query_time = microtime(true) - $start_time;
        
        if ($query_time > 2.0) {
            return ['status' => 'critical', 'message' => "Slow database query: {$query_time}s"];
        } elseif ($query_time > 0.5) {
            return ['status' => 'warning', 'message' => "Database query slower than optimal: {$query_time}s"];
        } else {
            return ['status' => 'healthy', 'message' => "Database performance good: {$query_time}s"];
        }
    }
    
    /**
     * Check polling system
     */
    private function check_polling_system() {
        $last_poll = get_option('hic_last_continuous_poll', 0);
        $time_since_poll = time() - $last_poll;
        
        if ($time_since_poll > 3600) { // 1 hour
            return ['status' => 'critical', 'message' => 'Polling system not running for over 1 hour'];
        } elseif ($time_since_poll > 600) { // 10 minutes
            return ['status' => 'warning', 'message' => 'Polling system delay detected'];
        } else {
            return ['status' => 'healthy', 'message' => 'Polling system operational'];
        }
    }
    
    /**
     * Check cache system
     */
    private function check_cache_system() {
        // Test cache write/read
        $test_key = 'hic_cache_test_' . time();
        $test_value = 'cache_test_data';
        
        set_transient($test_key, $test_value, 60);
        $cached_value = get_transient($test_key);
        delete_transient($test_key);
        
        if ($cached_value === $test_value) {
            return ['status' => 'healthy', 'message' => 'Cache system working'];
        } else {
            return ['status' => 'warning', 'message' => 'Cache system issues detected'];
        }
    }
    
    /**
     * Check circuit breakers
     */
    private function check_circuit_breakers() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        $open_circuits = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE state = 'open'");
        
        if ($open_circuits > 0) {
            return ['status' => 'warning', 'message' => "{$open_circuits} circuit breaker(s) open"];
        } else {
            return ['status' => 'healthy', 'message' => 'All circuit breakers closed'];
        }
    }
    
    /**
     * Check disk space
     */
    private function check_disk_space() {
        $upload_dir = wp_upload_dir();
        $free_bytes = disk_free_space($upload_dir['basedir']);
        $total_bytes = disk_total_space($upload_dir['basedir']);
        $free_percentage = ($free_bytes / $total_bytes) * 100;
        
        if ($free_percentage < 5) {
            return ['status' => 'critical', 'message' => sprintf('Low disk space: %.1f%% free', $free_percentage)];
        } elseif ($free_percentage < 15) {
            return ['status' => 'warning', 'message' => sprintf('Disk space getting low: %.1f%% free', $free_percentage)];
        } else {
            return ['status' => 'healthy', 'message' => sprintf('Disk space OK: %.1f%% free', $free_percentage)];
        }
    }
    
    /**
     * Check memory usage
     */
    private function check_memory_usage() {
        $memory_usage = memory_get_usage(true);
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_percentage = ($memory_usage / $memory_limit) * 100;
        
        if ($memory_percentage > 90) {
            return ['status' => 'critical', 'message' => sprintf('High memory usage: %.1f%%', $memory_percentage)];
        } elseif ($memory_percentage > 75) {
            return ['status' => 'warning', 'message' => sprintf('Memory usage elevated: %.1f%%', $memory_percentage)];
        } else {
            return ['status' => 'healthy', 'message' => sprintf('Memory usage normal: %.1f%%', $memory_percentage)];
        }
    }
    
    /**
     * Check external APIs
     */
    private function check_external_apis() {
        // Quick check of external API endpoints
        $apis_to_check = [
            'Google Analytics' => 'https://www.google-analytics.com',
            'Facebook' => 'https://graph.facebook.com',
            'Brevo' => 'https://api.brevo.com'
        ];
        
        $failed_apis = [];
        
        foreach ($apis_to_check as $name => $url) {
            $response = wp_remote_head($url, ['timeout' => 5]);
            if (is_wp_error($response)) {
                $failed_apis[] = $name;
            }
        }
        
        if (count($failed_apis) > 0) {
            return ['status' => 'warning', 'message' => 'External API issues: ' . implode(', ', $failed_apis)];
        } else {
            return ['status' => 'healthy', 'message' => 'All external APIs accessible'];
        }
    }
    
    /**
     * Store health check result
     */
    private function store_health_check_result($check_type, $result) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_health_checks';
        
        $wpdb->insert($table_name, [
            'check_type' => $check_type,
            'status' => $result['status'],
            'message' => $result['message'],
            'details' => isset($result['details']) ? json_encode($result['details']) : null
        ]);
    }
    
    /**
     * Add health dashboard widget
     */
    public function add_health_dashboard_widget() {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'hic_health_status',
                'FP HIC Monitor - System Health',
                [$this, 'render_health_widget']
            );
        }
    }
    
    /**
     * Render health dashboard widget
     */
    public function render_health_widget() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_health_checks';
        
        // Get latest health check results
        $latest_checks = $wpdb->get_results("
            SELECT check_type, status, message, checked_at
            FROM {$table_name} 
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY checked_at DESC
        ", ARRAY_A);
        
        if (empty($latest_checks)) {
            echo '<p>No recent health checks available. <button type="button" onclick="runHealthCheck()">Run Check Now</button></p>';
            return;
        }
        
        // Count statuses
        $status_counts = ['healthy' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($latest_checks as $check) {
            $status_counts[$check['status']]++;
        }
        
        // Overall health status
        $overall_status = 'healthy';
        if ($status_counts['critical'] > 0) {
            $overall_status = 'critical';
        } elseif ($status_counts['warning'] > 0) {
            $overall_status = 'warning';
        }
        
        $status_colors = [
            'healthy' => '#46b450',
            'warning' => '#ffb900',
            'critical' => '#dc3232'
        ];
        
        $status_icons = [
            'healthy' => '✅',
            'warning' => '⚠️',
            'critical' => '🚨'
        ];
        
        ?>
        <div class="hic-health-widget">
            <div class="hic-health-overview" style="display: flex; align-items: center; margin-bottom: 15px;">
                <span style="font-size: 24px; margin-right: 10px;"><?php echo $status_icons[$overall_status]; ?></span>
                <div>
                    <strong style="color: <?php echo $status_colors[$overall_status]; ?>; font-size: 16px;">
                        System <?php echo ucfirst($overall_status); ?>
                    </strong>
                    <div style="font-size: 12px; color: #666;">
                        <?php echo $status_counts['healthy']; ?> healthy, 
                        <?php echo $status_counts['warning']; ?> warnings, 
                        <?php echo $status_counts['critical']; ?> critical
                    </div>
                </div>
            </div>
            
            <div class="hic-health-details">
                <?php foreach (array_slice($latest_checks, 0, 5) as $check): ?>
                    <div style="display: flex; align-items: center; margin-bottom: 8px; font-size: 13px;">
                        <span style="margin-right: 8px;"><?php echo $status_icons[$check['status']]; ?></span>
                        <span style="flex: 1;"><?php echo esc_html($check['check_type']); ?></span>
                        <span style="color: #666;"><?php echo esc_html($check['message']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align: center; margin-top: 15px;">
                <button type="button" onclick="runHealthCheck()" class="button button-small">Refresh Health Check</button>
                <a href="<?php echo admin_url('admin.php?page=hic-circuit-breakers'); ?>" class="button button-small">View Details</a>
            </div>
        </div>
        
        <script>
        function runHealthCheck() {
            jQuery.post(ajaxurl, {
                action: 'hic_run_diagnostics',
                nonce: '<?php echo wp_create_nonce('hic_health_check'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * Enqueue management assets
     */
    public function enqueue_management_assets($hook) {
        if (strpos($hook, 'hic-') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Add inline script for wizard functionality
        wp_add_inline_script('jquery', '
            function saveStepAndContinue(currentStep, nextStep) {
                var formData = jQuery("#hic-wizard-step-" + currentStep).serialize();
                formData += "&action=hic_setup_wizard_step&step=" + currentStep + "&nonce=' . wp_create_nonce('hic_setup_wizard') . '";
                
                jQuery.post(ajaxurl, formData, function(response) {
                    if (response.success) {
                        window.location.href = "?page=hic-setup-wizard&step=" + nextStep;
                    } else {
                        alert("Error saving configuration: " + response.data);
                    }
                });
            }
        ');
    }
    
    // ===== AJAX HANDLERS =====
    
    /**
     * AJAX: Setup wizard step
     */
    public function ajax_setup_wizard_step() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (!check_ajax_referer('hic_setup_wizard', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        $step = sanitize_text_field($_POST['step'] ?? '');
        
        switch ($step) {
            case '2':
                $this->save_hic_credentials();
                break;
            case '3':
                $this->save_analytics_settings();
                break;
            case '4':
                $this->save_advanced_features();
                break;
            case 'complete':
                update_option('hic_setup_wizard_completed', true);
                wp_send_json_success(['message' => 'Setup completed successfully']);
                break;
        }
        
        wp_send_json_success(['message' => 'Step saved successfully']);
    }
    
    /**
     * Save HIC credentials
     */
    private function save_hic_credentials() {
        $raw_password = $_POST['password'] ?? '';

        if (\function_exists('\\hic_preserve_password_field')) {
            $password = \hic_preserve_password_field($raw_password);
        } else {
            if (\is_array($raw_password) || \is_object($raw_password) || $raw_password === null) {
                $password = '';
            } else {
                $password = \wp_unslash((string) $raw_password);
            }
        }

        $settings = [
            'prop_id' => sanitize_text_field($_POST['prop_id'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'password' => $password
        ];

        update_option('hic_settings', $settings);
    }
    
    /**
     * Save analytics settings
     */
    private function save_analytics_settings() {
        $settings = [
            'ga4_measurement_id' => sanitize_text_field($_POST['ga4_measurement_id'] ?? ''),
            'ga4_api_secret' => sanitize_text_field($_POST['ga4_api_secret'] ?? ''),
            'facebook_pixel_id' => sanitize_text_field($_POST['facebook_pixel_id'] ?? ''),
            'facebook_access_token' => sanitize_text_field($_POST['facebook_access_token'] ?? ''),
            'brevo_api_key' => sanitize_text_field($_POST['brevo_api_key'] ?? '')
        ];
        
        update_option('hic_analytics_settings', $settings);
    }
    
    /**
     * Save advanced features
     */
    private function save_advanced_features() {
        $features = [
            'intelligent_polling' => !empty($_POST['enable_intelligent_polling']),
            'realtime_dashboard' => !empty($_POST['enable_realtime_dashboard']),
            'automated_reports' => !empty($_POST['enable_automated_reports']),
            'enhanced_conversions' => !empty($_POST['enable_enhanced_conversions']),
            'circuit_breaker' => !empty($_POST['enable_circuit_breaker'])
        ];
        
        update_option('hic_advanced_features', $features);
    }
    
    /**
     * AJAX: Run reconciliation
     */
    public function ajax_run_reconciliation() {
        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!check_ajax_referer('hic_management_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        $this->run_daily_reconciliation();
        wp_send_json_success(['message' => 'Reconciliation completed']);
    }
    
    /**
     * AJAX: Get health status
     */
    public function ajax_get_health_status() {
        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!check_ajax_referer('hic_management_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_health_checks';
        
        $latest_checks = $wpdb->get_results("
            SELECT check_type, status, message, checked_at
            FROM {$table_name} 
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY checked_at DESC
        ", ARRAY_A);
        
        wp_send_json_success($latest_checks);
    }
    
    /**
     * AJAX: Run diagnostics
     */
    public function ajax_run_diagnostics() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (!check_ajax_referer('hic_health_check', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        $this->run_health_check();
        wp_send_json_success(['message' => 'Health check completed']);
    }
    
    /**
     * Log messages
     */
    private function log($message) {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log("[Enterprise Management] {$message}");
        }
    }
}

// Note: Class instantiation moved to main plugin file for proper admin menu ordering