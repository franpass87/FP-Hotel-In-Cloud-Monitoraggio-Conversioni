<?php declare(strict_types=1);

namespace FpHic\RealtimeDashboard;

use function FpHic\Helpers\hic_require_cap;

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
        add_action('hic_refresh_dashboard_data', [$this, 'refresh_dashboard_cache']);
        
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
        static $initialized = false;

        if ($initialized || did_action('hic_realtime_dashboard_initialized')) {
            return;
        }

        $initialized = true;

        $this->log('Initializing Real-Time Dashboard');

        // Create dashboard cache table
        $this->create_dashboard_cache_table();

        // Schedule dashboard data refresh
        $this->schedule_dashboard_refresh();

        // Initialize booking heatmap data
        $this->initialize_heatmap_data();

        do_action('hic_realtime_dashboard_initialized');
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
            wp_schedule_event(time(), 'hic_every_thirty_seconds', 'hic_refresh_dashboard_data');
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
        if (current_user_can('hic_manage')) {
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
        $allowed_hooks = [
            'index.php',
            'toplevel_page_hic-monitoring',
            'hic-monitoring_page_hic-monitoring'
        ];

        if (!in_array($hook, $allowed_hooks, true)) {
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
        $plugin_base_url = plugin_dir_url(dirname(__DIR__) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php');
        wp_enqueue_script(
            'hic-realtime-dashboard',
            $plugin_base_url . 'assets/js/realtime-dashboard.js',
            ['jquery', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );

        // Modular JS (caricati dopo lo script principale, non distruttivi)
        wp_enqueue_script(
            'hic-realtime-api',
            $plugin_base_url . 'assets/js/realtime-dashboard/modules/api.js',
            ['jquery', 'hic-realtime-dashboard'],
            HIC_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'hic-realtime-ui',
            $plugin_base_url . 'assets/js/realtime-dashboard/modules/ui.js',
            ['jquery', 'hic-realtime-dashboard'],
            HIC_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'hic-realtime-charts-realtime',
            $plugin_base_url . 'assets/js/realtime-dashboard/modules/charts-realtime.js',
            ['jquery', 'hic-realtime-dashboard', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'hic-realtime-charts-revenue',
            $plugin_base_url . 'assets/js/realtime-dashboard/modules/charts-revenue.js',
            ['jquery', 'hic-realtime-dashboard', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'hic-realtime-charts-heatmap',
            $plugin_base_url . 'assets/js/realtime-dashboard/modules/charts-heatmap.js',
            ['jquery', 'hic-realtime-dashboard', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'hic-realtime-charts-funnel',
            $plugin_base_url . 'assets/js/realtime-dashboard/modules/charts-funnel.js',
            ['jquery', 'hic-realtime-dashboard', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'hic-realtime-charts-timeline',
            $plugin_base_url . 'assets/js/realtime-dashboard/modules/charts-timeline.js',
            ['jquery', 'hic-realtime-dashboard', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );

        // Enqueue dashboard CSS
        wp_enqueue_style(
            'hic-admin-base',
            $plugin_base_url . 'assets/css/hic-admin.css',
            [],
            HIC_PLUGIN_VERSION
        );

        wp_enqueue_style(
            'hic-realtime-dashboard',
            $plugin_base_url . 'assets/css/realtime-dashboard.css',
            ['hic-admin-base'],
            HIC_PLUGIN_VERSION
        );
        // Utilities CSS (additivo)
        wp_enqueue_style(
            'hic-utilities',
            $plugin_base_url . 'assets/css/hic-utilities.css',
            ['hic-admin-base'],
            HIC_PLUGIN_VERSION
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
            'HIC Monitor',
            'HIC Monitor',
            'hic_manage',
            'hic-monitoring',
            [$this, 'render_full_dashboard'],
            'dashicons-chart-area',
            30
        );

        global $submenu;
        if (isset($submenu['hic-monitoring'][0])) {
            $submenu['hic-monitoring'][0][0] = \__('Dashboard', 'hotel-in-cloud');
        }
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
                <a href="<?php echo admin_url('admin.php?page=hic-monitoring'); ?>" class="button button-small">Vedi dettagli</a>
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
            <div class="hic-empty-state" data-empty-for="channel-stats">
                <?php esc_html_e('Nessun dato disponibile per il periodo selezionato.', 'hotel-in-cloud'); ?>
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
            <div class="hic-empty-state" data-empty-for="heatmap">
                <?php esc_html_e('Nessun dato disponibile per mostrare la heatmap delle prenotazioni.', 'hotel-in-cloud'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render full dashboard page
     */
    public function render_full_dashboard() {
        ?>
        <div class="wrap hic-admin-page hic-dashboard-page hic-dashboard">
            <div class="hic-page-hero">
                <div class="hic-page-header">
                    <div class="hic-page-header__content">
                        <h1 class="hic-page-header__title"><span>📈</span><?php esc_html_e('Dashboard Real-Time', 'hotel-in-cloud'); ?></h1>
                        <p class="hic-page-header__subtitle"><?php esc_html_e('Monitora conversioni, revenue e salute delle integrazioni con lo stesso linguaggio visivo delle altre aree del plugin.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-page-actions">
                        <span class="hic-inline-status">
                            <?php esc_html_e('Ultimo aggiornamento alle', 'hotel-in-cloud'); ?>
                            <strong id="hic-last-update">--:--</strong>
                        </span>
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url(admin_url('admin.php?page=hic-settings')); ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php esc_html_e('Vai alle Impostazioni', 'hotel-in-cloud'); ?>
                        </a>
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url(admin_url('admin.php?page=hic-diagnostics')); ?>">
                            <span class="dashicons dashicons-chart-line"></span>
                            <?php esc_html_e('Apri Diagnostica', 'hotel-in-cloud'); ?>
                        </a>
                    </div>
                </div>

                <div class="hic-page-meta">
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-active"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Conversioni ultime 24h', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><span id="hic-total-conversions">-</span></p>
                            <p class="hic-page-meta__description" id="hic-conversions-change"><?php esc_html_e('In aggiornamento...', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-active"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Revenue stimata', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><span id="hic-total-revenue">-</span></p>
                            <p class="hic-page-meta__description" id="hic-revenue-change"><?php esc_html_e('In aggiornamento...', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-active"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Tasso di conversione', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><span id="hic-conversion-rate-full">-</span></p>
                            <p class="hic-page-meta__description" id="hic-rate-change"><?php esc_html_e('In aggiornamento...', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-active"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Valore medio ordine (AOV)', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><span id="hic-aov">-</span></p>
                            <p class="hic-page-meta__description" id="hic-aov-change"><?php esc_html_e('In aggiornamento...', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hic-card hic-dashboard-toolbar">
                <div class="hic-toolbar-group">
                    <label for="hic-dashboard-period"><?php esc_html_e('Periodo', 'hotel-in-cloud'); ?></label>
                    <select id="hic-dashboard-period" class="hic-select">
                        <option value="today"><?php esc_html_e('Oggi', 'hotel-in-cloud'); ?></option>
                        <option value="yesterday"><?php esc_html_e('Ieri', 'hotel-in-cloud'); ?></option>
                        <option value="7days" selected><?php esc_html_e('Ultimi 7 giorni', 'hotel-in-cloud'); ?></option>
                        <option value="30days"><?php esc_html_e('Ultimi 30 giorni', 'hotel-in-cloud'); ?></option>
                    </select>
                </div>
                <div class="hic-toolbar-group">
                    <label class="hic-toggle" for="hic-auto-refresh">
                        <input type="checkbox" id="hic-auto-refresh" checked>
                        <span><?php esc_html_e('Auto-refresh', 'hotel-in-cloud'); ?></span>
                    </label>
                    <span class="hic-refresh-indicator" id="hic-refresh-indicator" aria-hidden="true"></span>
                </div>
                <button type="button" class="hic-button hic-button--primary" id="hic-refresh-dashboard">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Aggiorna Ora', 'hotel-in-cloud'); ?>
                </button>
            </div>

            <div class="hic-grid hic-grid--dashboard-primary">
                <div class="hic-card hic-card--chart">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title"><?php esc_html_e('Conversioni nel Tempo', 'hotel-in-cloud'); ?></h2>
                            <p class="hic-card__subtitle"><?php esc_html_e('Trend orario delle conversioni registrate', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <canvas id="hic-conversions-timeline"></canvas>
                    </div>
                </div>

                <div class="hic-card hic-card--chart">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title"><?php esc_html_e('Revenue per Canale', 'hotel-in-cloud'); ?></h2>
                            <p class="hic-card__subtitle"><?php esc_html_e('Distribuzione dei ricavi sui canali di vendita', 'hotel-in-cloud'); ?></p>
                        </div>
                        <div class="hic-page-actions">
                            <select id="hic-revenue-period" class="hic-select">
                                <option value="today"><?php esc_html_e('Oggi', 'hotel-in-cloud'); ?></option>
                                <option value="yesterday"><?php esc_html_e('Ieri', 'hotel-in-cloud'); ?></option>
                                <option value="7days" selected><?php esc_html_e('Ultimi 7 giorni', 'hotel-in-cloud'); ?></option>
                                <option value="30days"><?php esc_html_e('Ultimi 30 giorni', 'hotel-in-cloud'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <div class="hic-channel-stats" id="hic-channel-stats">
                            <div class="hic-loading"><?php esc_html_e('Caricamento...', 'hotel-in-cloud'); ?></div>
                        </div>
                        <canvas id="hic-revenue-chart"></canvas>
                        <div class="hic-empty-state" data-empty-for="channel-stats">
                            <?php esc_html_e('Nessun dato disponibile per il periodo selezionato.', 'hotel-in-cloud'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hic-grid hic-grid--dashboard-secondary">
                <div class="hic-card hic-card--chart">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title"><?php esc_html_e('Funnel di Conversione', 'hotel-in-cloud'); ?></h2>
                            <p class="hic-card__subtitle"><?php esc_html_e('Misura le tappe chiave dal traffico alla prenotazione', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <canvas id="hic-conversion-funnel"></canvas>
                        <div class="hic-empty-state" data-empty-for="funnel">
                            <?php esc_html_e('Nessun dato di conversione disponibile per questo intervallo.', 'hotel-in-cloud'); ?>
                        </div>
                    </div>
                </div>
                <div class="hic-card hic-card--chart">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title"><?php esc_html_e('Heatmap Prenotazioni', 'hotel-in-cloud'); ?></h2>
                            <p class="hic-card__subtitle"><?php esc_html_e('Individua i momenti con maggiore domanda', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <div class="hic-heatmap-container">
                            <canvas id="hic-booking-heatmap"></canvas>
                        </div>
                        <div class="hic-heatmap-legend">
                            <span class="hic-legend-label"><?php esc_html_e('Bassa attività', 'hotel-in-cloud'); ?></span>
                            <div class="hic-legend-gradient"></div>
                            <span class="hic-legend-label"><?php esc_html_e('Alta attività', 'hotel-in-cloud'); ?></span>
                        </div>
                        <div class="hic-empty-state" data-empty-for="heatmap">
                            <?php esc_html_e('Nessun dato disponibile per mostrare la heatmap delle prenotazioni.', 'hotel-in-cloud'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hic-grid hic-grid--dashboard-primary hic-grid--stacked">
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title"><?php esc_html_e('Metriche di Performance', 'hotel-in-cloud'); ?></h2>
                            <p class="hic-card__subtitle"><?php esc_html_e('Analizza tempi di risposta e volumi delle integrazioni attive', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-card__body" id="hic-performance-metrics">
                        <div class="hic-loading"><?php esc_html_e('Caricamento metriche...', 'hotel-in-cloud'); ?></div>
                    </div>
                </div>
                <div class="hic-card hic-card--alerts">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title"><?php esc_html_e('Alert e Anomalie', 'hotel-in-cloud'); ?></h2>
                            <p class="hic-card__subtitle"><?php esc_html_e('Ricevi segnalazioni proattive su criticità operative', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-card__body" id="hic-alerts-list">
                        <div class="hic-loading"><?php esc_html_e('Controllo anomalie...', 'hotel-in-cloud'); ?></div>
                    </div>
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

        $metrics_table  = $wpdb->prefix . 'hic_booking_metrics';
        $tracking_table = $wpdb->prefix . 'hic_gclids';

        if (!$this->table_exists($metrics_table)) {
            $stats = [
                'today'           => 0,
                'yesterday'       => 0,
                'last_hour'       => 0,
                'last_24h'        => 0,
                'conversion_rate' => 0,
                'hourly_data'     => [],
                'last_updated'    => time(),
            ];
            $this->set_dashboard_cache('realtime_stats', $stats, 60);
            return;
        }

        [$today_start, $today_end]         = $this->get_period_bounds('today');
        [$yesterday_start, $yesterday_end] = $this->get_period_bounds('yesterday');
        [$last_hour_start, $last_hour_end] = $this->get_period_bounds('last_hour');
        [$last_24h_start, $last_24h_end]   = $this->get_period_bounds('last_24h');

        $stats = [
            'today' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$metrics_table} WHERE is_refund = 0 AND created_at >= %s AND created_at < %s",
                $today_start,
                $today_end
            )),
            'yesterday' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$metrics_table} WHERE is_refund = 0 AND created_at >= %s AND created_at < %s",
                $yesterday_start,
                $yesterday_end
            )),
            'last_hour' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$metrics_table} WHERE is_refund = 0 AND created_at >= %s AND created_at < %s",
                $last_hour_start,
                $last_hour_end
            )),
            'last_24h' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$metrics_table} WHERE is_refund = 0 AND created_at >= %s AND created_at < %s",
                $last_24h_start,
                $last_24h_end
            )),
        ];

        $visits_today = 0;
        if ($this->table_exists($tracking_table)) {
            $visits_today = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT sid) FROM {$tracking_table} WHERE created_at >= %s AND created_at < %s",
                $today_start,
                $today_end
            ));
        }

        $stats['conversion_rate'] = $visits_today > 0
            ? round(($stats['today'] / $visits_today) * 100, 2)
            : 0.0;
        $stats['visits_today'] = $visits_today;

        $hourly_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                HOUR(created_at) AS hour,
                SUM(CASE WHEN is_refund = 1 THEN 0 ELSE 1 END) AS conversions
            FROM {$metrics_table}
            WHERE created_at >= %s AND created_at < %s
            GROUP BY HOUR(created_at)
            ORDER BY hour",
            $today_start,
            $today_end
        ), ARRAY_A);

        $hourly_data = [];
        if (is_array($hourly_rows)) {
            foreach ($hourly_rows as $row) {
                $hourly_data[] = [
                    'hour'        => isset($row['hour']) ? (int) $row['hour'] : 0,
                    'conversions' => isset($row['conversions']) ? (int) $row['conversions'] : 0,
                ];
            }
        }

        $stats['hourly_data']  = $hourly_data;
        $stats['last_updated'] = time();

        $this->set_dashboard_cache('realtime_stats', $stats, 60);
    }
    /**
     * Cache revenue by channel data
     */
    private function cache_revenue_by_channel() {
        global $wpdb;

        $metrics_table = $wpdb->prefix . 'hic_booking_metrics';

        if (!$this->table_exists($metrics_table)) {
            $this->set_dashboard_cache('revenue_by_channel', [], 300);
            return;
        }

        $periods = ['today', 'yesterday', '7days', '30days'];
        $revenue_data = [];

        foreach ($periods as $period) {
            [$start, $end] = $this->get_period_bounds($period);

            $channel_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    CASE
                        WHEN channel = '' OR channel IS NULL THEN 'Direct'
                        ELSE channel
                    END AS channel,
                    SUM(CASE WHEN is_refund = 1 THEN 0 ELSE 1 END) AS bookings,
                    COALESCE(SUM(amount), 0) AS total_revenue
                 FROM {$metrics_table}
                 WHERE created_at >= %s AND created_at < %s
                 GROUP BY channel
                 ORDER BY total_revenue DESC",
                $start,
                $end
            ), ARRAY_A);

            $formatted = [];
            if (is_array($channel_rows)) {
                foreach ($channel_rows as $row) {
                    $channel = isset($row['channel']) && $row['channel'] !== '' ? (string) $row['channel'] : 'Direct';
                    $bookings = isset($row['bookings']) ? (int) $row['bookings'] : 0;
                    $revenue  = round((float) ($row['total_revenue'] ?? 0), 2);

                    $formatted[] = [
                        'channel'            => $channel,
                        'bookings'           => $bookings,
                        'total_revenue'      => $revenue,
                        'estimated_revenue'  => $revenue,
                    ];
                }
            }

            $revenue_data[$period] = $formatted;
        }

        $this->set_dashboard_cache('revenue_by_channel', $revenue_data, 300);
    }
    /**
     * Update booking heatmap data
     */
    private function update_booking_heatmap() {
        global $wpdb;

        $metrics_table = $wpdb->prefix . 'hic_booking_metrics';
        $heatmap_table = $wpdb->prefix . 'hic_booking_heatmap';

        if (!$this->table_exists($metrics_table) || !$this->table_exists($heatmap_table)) {
            return;
        }

        [$start, $end] = $this->get_period_bounds('30days');

        $wpdb->query("UPDATE {$heatmap_table} SET booking_count = 0, revenue_total = 0");

        $heatmap_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                HOUR(created_at) AS hour_of_day,
                DAYOFWEEK(created_at) AS day_of_week,
                SUM(CASE WHEN is_refund = 1 THEN 0 ELSE 1 END) AS booking_count,
                COALESCE(SUM(amount), 0) AS revenue_total
             FROM {$metrics_table}
             WHERE created_at >= %s AND created_at < %s
             GROUP BY hour_of_day, day_of_week",
            $start,
            $end
        ), ARRAY_A);

        if (!is_array($heatmap_rows)) {
            return;
        }

        foreach ($heatmap_rows as $row) {
            $wpdb->replace($heatmap_table, [
                'hour_of_day'   => isset($row['hour_of_day']) ? (int) $row['hour_of_day'] : 0,
                'day_of_week'   => isset($row['day_of_week']) ? (int) $row['day_of_week'] : 0,
                'booking_count' => isset($row['booking_count']) ? (int) $row['booking_count'] : 0,
                'revenue_total' => round((float) ($row['revenue_total'] ?? 0), 2),
            ], ['%d', '%d', '%d', '%f']);
        }

        $this->log('Updated booking heatmap data');
    }
    /**
     * Cache conversion funnel data
     */
    private function cache_conversion_funnel() {
        global $wpdb;

        $metrics_table  = $wpdb->prefix . 'hic_booking_metrics';
        $tracking_table = $wpdb->prefix . 'hic_gclids';

        if (!$this->table_exists($metrics_table)) {
            $this->set_dashboard_cache('conversion_funnel', [], 600);
            return;
        }

        $funnel_data = [];
        $periods = ['7days', '30days'];

        foreach ($periods as $period) {
            [$start, $end] = $this->get_period_bounds($period);

            $total_sessions = 0;
            if ($this->table_exists($tracking_table)) {
                $total_sessions = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT sid) FROM {$tracking_table} WHERE created_at >= %s AND created_at < %s",
                    $start,
                    $end
                ));
            }

            $conversion_stats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN channel = 'Google Ads' AND is_refund = 0 THEN 1 ELSE 0 END) AS google_conversions,
                    SUM(CASE WHEN channel = 'Facebook Ads' AND is_refund = 0 THEN 1 ELSE 0 END) AS facebook_conversions,
                    SUM(CASE WHEN is_refund = 0 THEN 1 ELSE 0 END) AS total_conversions
                 FROM {$metrics_table}
                 WHERE created_at >= %s AND created_at < %s",
                $start,
                $end
            ), ARRAY_A);

            $funnel_data[$period] = [
                'total_sessions'       => $total_sessions,
                'google_conversions'   => (int) ($conversion_stats['google_conversions'] ?? 0),
                'facebook_conversions' => (int) ($conversion_stats['facebook_conversions'] ?? 0),
                'total_conversions'    => (int) ($conversion_stats['total_conversions'] ?? 0),
            ];
        }

        $this->set_dashboard_cache('conversion_funnel', $funnel_data, 600);
    }
    /**
     * Get normalized period bounds for SQL queries.
     */
    private function get_period_bounds($period) {
        $timezone = $this->get_timezone();
        $now      = new \DateTimeImmutable('now', $timezone);

        switch ($period) {
            case 'today':
                $start = $now->setTime(0, 0, 0);
                $end   = $start->modify('+1 day');
                break;
            case 'yesterday':
                $end   = $now->setTime(0, 0, 0);
                $start = $end->modify('-1 day');
                break;
            case '30days':
                $end   = $now;
                $start = $now->modify('-30 days');
                break;
            case 'last_hour':
                $end   = $now;
                $start = $now->modify('-1 hour');
                break;
            case 'last_24h':
                $end   = $now;
                $start = $now->modify('-24 hours');
                break;
            case '7days':
            default:
                $end   = $now;
                $start = $now->modify('-7 days');
                break;
        }

        return [
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ];
    }

    private function get_timezone(): \DateTimeZone {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezone_string = function_exists('get_option') ? get_option('timezone_string') : '';
        if (is_string($timezone_string) && $timezone_string !== '') {
            try {
                return new \DateTimeZone($timezone_string);
            } catch (\Exception $exception) {
                // Fallback to UTC below.
            }
        }

        return new \DateTimeZone('UTC');
    }

    private function table_exists($table) {
        global $wpdb;

        if (!isset($wpdb)) {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        return $result === $table;
    }

    private function set_dashboard_cache($key, $data, $expires_in) {
        global $wpdb;

        $cache_table = $wpdb->prefix . 'hic_dashboard_cache';
        $timestamp   = function_exists('current_time') ? current_time('timestamp', true) : time();
        $expires_at  = gmdate('Y-m-d H:i:s', $timestamp + (int) $expires_in);
        $encoded     = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);

        if ($encoded === false) {
            $encoded = json_encode($data);
        }

        $wpdb->replace(
            $cache_table,
            [
                'cache_key'  => $key,
                'cache_data' => $encoded,
                'expires_at' => $expires_at,
            ],
            ['%s', '%s', '%s']
        );
    }

    private function get_dashboard_cache($key) {
        global $wpdb;

        $cache_table = $wpdb->prefix . 'hic_dashboard_cache';

        $cached_data = $wpdb->get_var($wpdb->prepare(
            "SELECT cache_data FROM {$cache_table}
             WHERE cache_key = %s AND expires_at > UTC_TIMESTAMP()",
            $key
        ));

        return $cached_data ? json_decode($cached_data, true) : null;
    }

    /**
     * AJAX: Get real-time statistics
     */
    public function ajax_get_realtime_stats() {
        if ( ! check_ajax_referer( 'hic_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }

        hic_require_cap('hic_manage');
        
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
        if ( ! check_ajax_referer( 'hic_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }

        hic_require_cap('hic_manage');

        $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '7days';
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
        if ( ! check_ajax_referer( 'hic_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }

        hic_require_cap('hic_manage');
        
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
        if ( ! check_ajax_referer( 'hic_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }

        hic_require_cap('hic_manage');
        
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
        if ( ! check_ajax_referer( 'hic_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }

        hic_require_cap('hic_manage');
        
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
        if ( ! check_ajax_referer( 'hic_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }

        hic_require_cap('hic_manage');
        
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

