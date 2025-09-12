<?php declare(strict_types=1);

namespace FpHic\RealtimeDashboard;

if (!defined('ABSPATH')) exit;

/**
 * Real-Time Dashboard - Enterprise Grade
 * 
 * Provides real-time conversion rate monitoring, revenue tracking by channel,
 * heatmaps, and performance insights for hotel marketing teams.
 */

class RealtimeDashboard {
    
    /** @var int Dashboard refresh interval in seconds */
    private const REFRESH_INTERVAL = 30;
    
    /** @var array Color scheme for charts */
    private const COLOR_SCHEME = [
        'primary' => '#0073aa',
        'success' => '#46b450',
        'warning' => '#ffb900',
        'danger' => '#dc3232',
        'info' => '#00a0d2',
        'google' => '#4285f4',
        'facebook' => '#1877f2',
        'direct' => '#2e7d32',
        'organic' => '#f57c00'
    ];
    
    public function __construct() {
        add_action('init', [$this, 'initialize_dashboard'], 25);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_dashboard_assets']);
        
        // AJAX handlers for real-time data
        add_action('wp_ajax_hic_get_realtime_stats', [$this, 'ajax_get_realtime_stats']);
        add_action('wp_ajax_hic_get_conversion_funnel', [$this, 'ajax_get_conversion_funnel']);
        add_action('wp_ajax_hic_get_revenue_by_channel', [$this, 'ajax_get_revenue_by_channel']);
        add_action('wp_ajax_hic_get_booking_heatmap', [$this, 'ajax_get_booking_heatmap']);
        add_action('wp_ajax_hic_get_performance_metrics', [$this, 'ajax_get_performance_metrics']);
        
        // Add admin menu for full dashboard
        add_action('admin_menu', [$this, 'add_dashboard_menu']);
        
        // Auto-refresh mechanism
        add_action('wp_ajax_hic_dashboard_heartbeat', [$this, 'ajax_dashboard_heartbeat']);
    }
    
    /**
     * Initialize real-time dashboard
     */
    public function initialize_dashboard() {
        $this->log('Initializing Real-Time Dashboard');
        
        // Create dashboard cache table
        $this->create_dashboard_cache_table();
        
        // Schedule dashboard data refresh
        $this->schedule_dashboard_refresh();
        
        // Initialize booking heatmap data
        $this->initialize_heatmap_data();
    }
    
    /**
     * Create dashboard-specific cache table
     */
    private function create_dashboard_cache_table() {
        global $wpdb;
        
        $cache_table = $wpdb->prefix . 'hic_dashboard_cache';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$cache_table} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(100) NOT NULL UNIQUE,
            cache_data LONGTEXT,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_cache_key (cache_key),
            INDEX idx_expires (expires_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Schedule dashboard data refresh
     */
    private function schedule_dashboard_refresh() {
        if (!wp_next_scheduled('hic_refresh_dashboard_data')) {
            wp_schedule_event(time(), 'hic_every_minute', 'hic_refresh_dashboard_data');
            add_action('hic_refresh_dashboard_data', [$this, 'refresh_dashboard_cache']);
        }
    }
    
    /**
     * Initialize booking heatmap data structure
     */
    private function initialize_heatmap_data() {
        global $wpdb;
        
        $heatmap_table = $wpdb->prefix . 'hic_booking_heatmap';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$heatmap_table} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            hour_of_day TINYINT NOT NULL,
            day_of_week TINYINT NOT NULL,
            booking_count INT DEFAULT 0,
            revenue_total DECIMAL(10,2) DEFAULT 0,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY idx_hour_day (hour_of_day, day_of_week),
            INDEX idx_last_updated (last_updated)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Initialize with default data if empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$heatmap_table}");
        if ($count == 0) {
            $this->populate_heatmap_defaults();
        }
    }
    
    /**
     * Populate heatmap with default data structure
     */
    private function populate_heatmap_defaults() {
        global $wpdb;
        
        $heatmap_table = $wpdb->prefix . 'hic_booking_heatmap';
        
        // Create entries for all hours (0-23) and days (1-7, Monday to Sunday)
        for ($day = 1; $day <= 7; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $wpdb->insert($heatmap_table, [
                    'hour_of_day' => $hour,
                    'day_of_week' => $day,
                    'booking_count' => 0,
                    'revenue_total' => 0
                ]);
            }
        }
        
        $this->log('Initialized booking heatmap data structure');
    }
    
    /**
     * Add WordPress dashboard widgets
     */
    public function add_dashboard_widgets() {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'hic_realtime_conversions',
                'FP HIC Monitor - Conversioni in Tempo Reale',
                [$this, 'render_realtime_widget']
            );
            
            wp_add_dashboard_widget(
                'hic_revenue_channels',
                'FP HIC Monitor - Revenue per Canale',
                [$this, 'render_revenue_widget']
            );
            
            wp_add_dashboard_widget(
                'hic_booking_heatmap',
                'FP HIC Monitor - Heatmap Prenotazioni',
                [$this, 'render_heatmap_widget']
            );
        }
    }
    
    /**
     * Enqueue dashboard assets
     */
    public function enqueue_dashboard_assets($hook) {
        if (!in_array($hook, ['index.php', 'toplevel_page_hic-realtime-dashboard'])) {
            return;
        }
        
        // Enqueue Chart.js
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            [],
            '3.9.1',
            true
        );
        
        // Enqueue dashboard JavaScript
        wp_enqueue_script(
            'hic-realtime-dashboard',
            plugins_url('assets/js/realtime-dashboard.js', dirname(__FILE__, 2)),
            ['jquery', 'chart-js'],
            '3.1.0',
            true
        );
        
        // Enqueue dashboard CSS
        wp_enqueue_style(
            'hic-realtime-dashboard',
            plugins_url('assets/css/realtime-dashboard.css', dirname(__FILE__, 2)),
            [],
            '3.1.0'
        );
        
        // Localize script with data and settings
        wp_localize_script('hic-realtime-dashboard', 'hicDashboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hic_dashboard_nonce'),
            'refreshInterval' => self::REFRESH_INTERVAL * 1000, // Convert to milliseconds
            'colors' => self::COLOR_SCHEME,
            'i18n' => [
                'loading' => __('Caricamento...', 'hotel-in-cloud'),
                'error' => __('Errore nel caricamento dei dati', 'hotel-in-cloud'),
                'noData' => __('Nessun dato disponibile', 'hotel-in-cloud'),
                'conversions' => __('Conversioni', 'hotel-in-cloud'),
                'revenue' => __('Revenue', 'hotel-in-cloud'),
                'bookings' => __('Prenotazioni', 'hotel-in-cloud')
            ]
        ]);
    }
    
    /**
     * Add dashboard menu page
     */
    public function add_dashboard_menu() {
        add_menu_page(
            'HIC Real-Time Dashboard',
            'HIC Dashboard',
            'manage_options',
            'hic-realtime-dashboard',
            [$this, 'render_full_dashboard'],
            'dashicons-chart-area',
            30
        );
    }
    
    /**
     * Render real-time conversions widget
     */
    public function render_realtime_widget() {
        ?>
        <div class="hic-widget hic-realtime-widget">
            <div class="hic-widget-stats">
                <div class="hic-stat-item">
                    <span class="hic-stat-label">Oggi</span>
                    <span class="hic-stat-value" id="hic-conversions-today">-</span>
                </div>
                <div class="hic-stat-item">
                    <span class="hic-stat-label">Ultima ora</span>
                    <span class="hic-stat-value" id="hic-conversions-hour">-</span>
                </div>
                <div class="hic-stat-item">
                    <span class="hic-stat-label">Tasso conversione</span>
                    <span class="hic-stat-value" id="hic-conversion-rate">-</span>
                </div>
            </div>
            <canvas id="hic-realtime-chart" width="400" height="200"></canvas>
            <div class="hic-widget-footer">
                <span class="hic-last-update">Ultimo aggiornamento: <span id="hic-last-update">-</span></span>
                <a href="<?php echo admin_url('admin.php?page=hic-realtime-dashboard'); ?>" class="button button-small">Vedi dettagli</a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render revenue by channel widget
     */
    public function render_revenue_widget() {
        ?>
        <div class="hic-widget hic-revenue-widget">
            <div class="hic-channel-stats" id="hic-channel-stats">
                <div class="hic-loading">Caricamento...</div>
            </div>
            <canvas id="hic-revenue-chart" width="400" height="300"></canvas>
            <div class="hic-widget-footer">
                <select id="hic-revenue-period" class="hic-period-selector">
                    <option value="today">Oggi</option>
                    <option value="yesterday">Ieri</option>
                    <option value="7days" selected>Ultimi 7 giorni</option>
                    <option value="30days">Ultimi 30 giorni</option>
                </select>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render booking heatmap widget
     */
    public function render_heatmap_widget() {
        ?>
        <div class="hic-widget hic-heatmap-widget">
            <div class="hic-heatmap-container">
                <canvas id="hic-booking-heatmap" width="400" height="200"></canvas>
            </div>
            <div class="hic-heatmap-legend">
                <span class="hic-legend-label">Bassa attività</span>
                <div class="hic-legend-gradient"></div>
                <span class="hic-legend-label">Alta attività</span>
            </div>
            <div class="hic-widget-footer">
                <span class="hic-heatmap-info">Prenotazioni per ora del giorno e giorno della settimana</span>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render full dashboard page
     */
    public function render_full_dashboard() {
        ?>
        <div class="wrap hic-dashboard">
            <h1>FP HIC Monitor - Dashboard Real-Time</h1>
            
            <div class="hic-dashboard-grid">
                <!-- Key Metrics Row -->
                <div class="hic-metrics-row">
                    <div class="hic-metric-card">
                        <h3>Conversioni Totali</h3>
                        <div class="hic-metric-value" id="hic-total-conversions">-</div>
                        <div class="hic-metric-change" id="hic-conversions-change">-</div>
                    </div>
                    <div class="hic-metric-card">
                        <h3>Revenue Totale</h3>
                        <div class="hic-metric-value" id="hic-total-revenue">-</div>
                        <div class="hic-metric-change" id="hic-revenue-change">-</div>
                    </div>
                    <div class="hic-metric-card">
                        <h3>Tasso Conversione</h3>
                        <div class="hic-metric-value" id="hic-conversion-rate-full">-</div>
                        <div class="hic-metric-change" id="hic-rate-change">-</div>
                    </div>
                    <div class="hic-metric-card">
                        <h3>AOV (Valore Medio)</h3>
                        <div class="hic-metric-value" id="hic-aov">-</div>
                        <div class="hic-metric-change" id="hic-aov-change">-</div>
                    </div>
                </div>
                
                <!-- Charts Row -->
                <div class="hic-charts-row">
                    <div class="hic-chart-container hic-chart-large">
                        <h3>Conversioni nel Tempo</h3>
                        <canvas id="hic-conversions-timeline"></canvas>
                    </div>
                    <div class="hic-chart-container hic-chart-medium">
                        <h3>Revenue per Canale</h3>
                        <canvas id="hic-revenue-breakdown"></canvas>
                    </div>
                </div>
                
                <!-- Analysis Row -->
                <div class="hic-analysis-row">
                    <div class="hic-analysis-container">
                        <h3>Funnel di Conversione</h3>
                        <canvas id="hic-conversion-funnel"></canvas>
                    </div>
                    <div class="hic-analysis-container">
                        <h3>Heatmap Prenotazioni</h3>
                        <canvas id="hic-booking-heatmap-full"></canvas>
                    </div>
                </div>
                
                <!-- Performance Row -->
                <div class="hic-performance-row">
                    <div class="hic-performance-container">
                        <h3>Metriche Performance</h3>
                        <div id="hic-performance-metrics">
                            <div class="hic-loading">Caricamento metriche...</div>
                        </div>
                    </div>
                    <div class="hic-alerts-container">
                        <h3>Alert e Anomalie</h3>
                        <div id="hic-alerts-list">
                            <div class="hic-loading">Controllo anomalie...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Controls -->
            <div class="hic-dashboard-controls">
                <div class="hic-control-group">
                    <label for="hic-dashboard-period">Periodo:</label>
                    <select id="hic-dashboard-period">
                        <option value="today">Oggi</option>
                        <option value="yesterday">Ieri</option>
                        <option value="7days" selected>Ultimi 7 giorni</option>
                        <option value="30days">Ultimi 30 giorni</option>
                    </select>
                </div>
                <div class="hic-control-group">
                    <label for="hic-auto-refresh">Auto-refresh:</label>
                    <input type="checkbox" id="hic-auto-refresh" checked>
                    <span class="hic-refresh-indicator" id="hic-refresh-indicator">●</span>
                </div>
                <div class="hic-control-group">
                    <button type="button" class="button button-primary" id="hic-refresh-dashboard">
                        Aggiorna Ora
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Refresh dashboard cache data
     */
    public function refresh_dashboard_cache() {
        $this->log('Refreshing dashboard cache');
        
        // Update real-time stats
        $this->cache_realtime_stats();
        
        // Update revenue by channel
        $this->cache_revenue_by_channel();
        
        // Update booking heatmap
        $this->update_booking_heatmap();
        
        // Update conversion funnel
        $this->cache_conversion_funnel();
        
        $this->log('Dashboard cache refreshed');
    }
    
    /**
     * Cache real-time statistics
     */
    private function cache_realtime_stats() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $current_time = current_time('mysql');
        
        // Get conversions for different time periods
        $stats = [
            'today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$main_table} WHERE DATE(created_at) = DATE(%s)",
                $current_time
            )),
            'yesterday' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$main_table} WHERE DATE(created_at) = DATE_SUB(DATE(%s), INTERVAL 1 DAY)",
                $current_time
            )),
            'last_hour' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$main_table} WHERE created_at >= DATE_SUB(%s, INTERVAL 1 HOUR)",
                $current_time
            )),
            'last_24h' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$main_table} WHERE created_at >= DATE_SUB(%s, INTERVAL 24 HOUR)",
                $current_time
            ))
        ];
        
        // Calculate conversion rate (simplified - you'd need traffic data for real rate)
        $stats['conversion_rate'] = $stats['today'] > 0 ? 
            round(($stats['today'] / max($stats['today'] * 10, 100)) * 100, 2) : 0;
        
        // Get hourly data for chart
        $hourly_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as conversions
            FROM {$main_table} 
            WHERE DATE(created_at) = DATE(%s)
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ", $current_time), ARRAY_A);
        
        $stats['hourly_data'] = $hourly_data;
        $stats['last_updated'] = time();
        
        $this->set_dashboard_cache('realtime_stats', $stats, 60); // Cache for 1 minute
    }
    
    /**
     * Cache revenue by channel data
     */
    private function cache_revenue_by_channel() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        
        $periods = ['today', 'yesterday', '7days', '30days'];
        $revenue_data = [];
        
        foreach ($periods as $period) {
            $date_condition = $this->get_date_condition($period);
            
            $channel_data = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    CASE 
                        WHEN utm_source = 'google' THEN 'Google Ads'
                        WHEN utm_source = 'facebook' THEN 'Facebook Ads'
                        WHEN utm_source = '' OR utm_source IS NULL THEN 'Direct'
                        ELSE CONCAT(UPPER(SUBSTRING(utm_source, 1, 1)), SUBSTRING(utm_source, 2))
                    END as channel,
                    COUNT(*) as bookings,
                    COUNT(*) * 150 as estimated_revenue -- Placeholder revenue calculation
                FROM {$main_table} 
                WHERE {$date_condition}
                GROUP BY channel
                ORDER BY bookings DESC
            "), ARRAY_A);
            
            $revenue_data[$period] = $channel_data;
        }
        
        $this->set_dashboard_cache('revenue_by_channel', $revenue_data, 300); // Cache for 5 minutes
    }
    
    /**
     * Update booking heatmap data
     */
    private function update_booking_heatmap() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $heatmap_table = $wpdb->prefix . 'hic_booking_heatmap';
        
        // Get booking counts by hour and day of week for last 30 days
        $heatmap_data = $wpdb->get_results("
            SELECT 
                HOUR(created_at) as hour_of_day,
                DAYOFWEEK(created_at) as day_of_week,
                COUNT(*) as booking_count
            FROM {$main_table} 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY hour_of_day, day_of_week
        ", ARRAY_A);
        
        // Update heatmap table
        foreach ($heatmap_data as $data) {
            $wpdb->replace($heatmap_table, [
                'hour_of_day' => $data['hour_of_day'],
                'day_of_week' => $data['day_of_week'],
                'booking_count' => $data['booking_count'],
                'revenue_total' => $data['booking_count'] * 150 // Placeholder calculation
            ]);
        }
        
        $this->log('Updated booking heatmap data');
    }
    
    /**
     * Cache conversion funnel data
     */
    private function cache_conversion_funnel() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        
        $funnel_data = [];
        $periods = ['7days', '30days'];
        
        foreach ($periods as $period) {
            $date_condition = $this->get_date_condition($period);
            
            $funnel_stats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(*) as total_sessions,
                    COUNT(DISTINCT CASE WHEN gclid IS NOT NULL AND gclid != '' THEN gclid END) as google_conversions,
                    COUNT(DISTINCT CASE WHEN fbclid IS NOT NULL AND fbclid != '' THEN fbclid END) as facebook_conversions,
                    COUNT(DISTINCT sid) as total_conversions
                FROM {$main_table} 
                WHERE {$date_condition}
            "), ARRAY_A);
            
            $funnel_data[$period] = $funnel_stats;
        }
        
        $this->set_dashboard_cache('conversion_funnel', $funnel_data, 600); // Cache for 10 minutes
    }
    
    /**
     * Get date condition for SQL queries
     */
    private function get_date_condition($period) {
        switch ($period) {
            case 'today':
                return "DATE(created_at) = CURDATE()";
            case 'yesterday':
                return "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            case '7days':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case '30days':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            default:
                return "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        }
    }
    
    /**
     * Set dashboard cache
     */
    private function set_dashboard_cache($key, $data, $expires_in) {
        global $wpdb;
        
        $cache_table = $wpdb->prefix . 'hic_dashboard_cache';
        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
        
        $wpdb->replace($cache_table, [
            'cache_key' => $key,
            'cache_data' => json_encode($data),
            'expires_at' => $expires_at
        ]);
    }
    
    /**
     * Get dashboard cache
     */
    private function get_dashboard_cache($key) {
        global $wpdb;
        
        $cache_table = $wpdb->prefix . 'hic_dashboard_cache';
        
        $cached_data = $wpdb->get_var($wpdb->prepare(
            "SELECT cache_data FROM {$cache_table} 
             WHERE cache_key = %s AND expires_at > NOW()",
            $key
        ));
        
        return $cached_data ? json_decode($cached_data, true) : null;
    }
    
    /**
     * AJAX: Get real-time statistics
     */
    public function ajax_get_realtime_stats() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $cached_stats = $this->get_dashboard_cache('realtime_stats');
        
        if (!$cached_stats) {
            $this->cache_realtime_stats();
            $cached_stats = $this->get_dashboard_cache('realtime_stats');
        }
        
        wp_send_json_success($cached_stats);
    }
    
    /**
     * AJAX: Get revenue by channel
     */
    public function ajax_get_revenue_by_channel() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $period = sanitize_text_field($_POST['period'] ?? '7days');
        $cached_data = $this->get_dashboard_cache('revenue_by_channel');
        
        if (!$cached_data) {
            $this->cache_revenue_by_channel();
            $cached_data = $this->get_dashboard_cache('revenue_by_channel');
        }
        
        wp_send_json_success($cached_data[$period] ?? []);
    }
    
    /**
     * AJAX: Get booking heatmap
     */
    public function ajax_get_booking_heatmap() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $heatmap_table = $wpdb->prefix . 'hic_booking_heatmap';
        
        $heatmap_data = $wpdb->get_results("
            SELECT hour_of_day, day_of_week, booking_count, revenue_total
            FROM {$heatmap_table}
            ORDER BY day_of_week, hour_of_day
        ", ARRAY_A);
        
        wp_send_json_success($heatmap_data);
    }
    
    /**
     * AJAX: Get conversion funnel
     */
    public function ajax_get_conversion_funnel() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $cached_data = $this->get_dashboard_cache('conversion_funnel');
        
        if (!$cached_data) {
            $this->cache_conversion_funnel();
            $cached_data = $this->get_dashboard_cache('conversion_funnel');
        }
        
        wp_send_json_success($cached_data);
    }
    
    /**
     * AJAX: Get performance metrics
     */
    public function ajax_get_performance_metrics() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $perf_table = $wpdb->prefix . 'hic_performance_metrics';
        
        $metrics = $wpdb->get_results($wpdb->prepare("
            SELECT 
                metric_type,
                AVG(metric_value) as avg_value,
                MAX(metric_value) as max_value,
                MIN(metric_value) as min_value,
                COUNT(*) as sample_count
            FROM {$perf_table} 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY metric_type
            ORDER BY metric_type
        "), ARRAY_A);
        
        wp_send_json_success($metrics);
    }
    
    /**
     * AJAX: Dashboard heartbeat for auto-refresh
     */
    public function ajax_dashboard_heartbeat() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $last_update = get_option('hic_dashboard_last_heartbeat', 0);
        $current_time = time();
        
        // Update heartbeat timestamp
        update_option('hic_dashboard_last_heartbeat', $current_time);
        
        // Check if cache needs refresh
        $cache_age = $current_time - $last_update;
        $needs_refresh = $cache_age > self::REFRESH_INTERVAL;
        
        if ($needs_refresh) {
            $this->refresh_dashboard_cache();
        }
        
        wp_send_json_success([
            'timestamp' => $current_time,
            'cache_refreshed' => $needs_refresh
        ]);
    }
    
    /**
     * Log messages with dashboard prefix
     */
    private function log($message) {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log("[Real-Time Dashboard] {$message}");
        }
    }
}

// Initialize the real-time dashboard
new RealtimeDashboard();