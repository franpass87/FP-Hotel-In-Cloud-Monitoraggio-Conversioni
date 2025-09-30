<?php declare(strict_types=1);

namespace FpHic\ReconAndSetup;

use function FpHic\Helpers\hic_require_cap;

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
        add_action('admin_menu', [$this, 'add_setup_wizard_menu'], 50);
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
            $hic_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$main_table} WHERE DATE(created_at) = %s",
                $date
            ));

            // Get external system data count
            $external_result = $this->get_external_system_count($system, $date);
            $status = $external_result['status'] ?? 'error';

            if ($status === 'pending') {
                $reason = isset($external_result['reason']) ? (string) $external_result['reason'] : 'Metrics unavailable';
                $details = [
                    'hic_data' => $hic_count,
                    'external_status' => 'pending',
                    'reason' => $reason,
                ];

                if (!empty($external_result['provider'])) {
                    $details['provider'] = $external_result['provider'];
                }

                $wpdb->replace($recon_table, [
                    'check_date' => $date,
                    'source_system' => $system,
                    'hic_count' => $hic_count,
                    'analytics_count' => null,
                    'discrepancy_count' => null,
                    'discrepancy_percentage' => null,
                    'status' => 'pending',
                    'details' => wp_json_encode($details),
                ]);

                $this->log(
                    sprintf('Reconciliation pending for %s: %s', $this->format_system_label($system), $reason),
                    'info',
                    [
                        'system' => $system,
                        'date' => $date,
                        'reason' => $reason,
                    ]
                );

                return;
            }

            if ($status === 'error') {
                $message = isset($external_result['reason']) ? (string) $external_result['reason'] : 'Unable to fetch metrics';
                throw new \RuntimeException($message);
            }

            $external_count = isset($external_result['count']) ? (int) $external_result['count'] : 0;

            // Calculate discrepancy
            $discrepancy = abs($hic_count - $external_count);
            $discrepancy_percentage = $hic_count > 0 ? ($discrepancy / $hic_count) * 100 : 0;

            $details = [
                'hic_data' => $hic_count,
                'external_data' => $external_count,
                'threshold_exceeded' => $discrepancy_percentage > 10,
            ];

            if (!empty($external_result['provider'])) {
                $details['provider'] = $external_result['provider'];
            }

            // Store reconciliation result
            $wpdb->replace($recon_table, [
                'check_date' => $date,
                'source_system' => $system,
                'hic_count' => $hic_count,
                'analytics_count' => $external_count,
                'discrepancy_count' => $discrepancy,
                'discrepancy_percentage' => $discrepancy_percentage,
                'status' => 'completed',
                'details' => wp_json_encode($details),
            ]);

            // Alert if significant discrepancy
            if ($discrepancy_percentage > 10) {
                $this->send_reconciliation_alert($system, $date, $discrepancy_percentage);
            }

            $this->log(
                sprintf(
                    'Reconciliation completed for %s: %.2f%% discrepancy',
                    $this->format_system_label($system),
                    $discrepancy_percentage
                ),
                'info',
                [
                    'system' => $system,
                    'date' => $date,
                    'hic_count' => $hic_count,
                    'external_count' => $external_count,
                    'discrepancy' => $discrepancy,
                    'discrepancy_percentage' => $discrepancy_percentage,
                ]
            );

        } catch (\Exception $e) {
            $wpdb->replace($recon_table, [
                'check_date' => $date,
                'source_system' => $system,
                'status' => 'error',
                'details' => wp_json_encode(['error' => $e->getMessage()]),
            ]);

            $this->log(
                sprintf('Reconciliation error for %s: %s', $this->format_system_label($system), $e->getMessage()),
                'warning',
                [
                    'system' => $system,
                    'date' => $date,
                ]
            );
        }
    }

    /**
     * Get external system data count
     */
    private function get_external_system_count($system, $date) {
        switch ($system) {
            case 'google_analytics':
                return $this->get_ga4_event_count($date);
            case 'facebook_api':
                return $this->get_facebook_event_count($date);
            case 'brevo_api':
                return $this->get_brevo_event_count($date);
            default:
                return $this->create_metric_result('pending', [
                    'reason' => 'Unknown reconciliation target',
                ]);
        }
    }

    /**
     * Get GA4 event count
     */
    private function get_ga4_event_count($date) {
        $measurement_id = \FpHic\Helpers\hic_get_measurement_id();
        $api_secret = \FpHic\Helpers\hic_get_api_secret();

        if (empty($measurement_id) || empty($api_secret)) {
            return $this->create_metric_result('pending', [
                'reason' => 'GA4 measurement ID or API secret not configured',
            ]);
        }

        $filter = 'hic_reconciliation_ga4_event_count';
        $count = apply_filters($filter, null, $date);

        if (is_array($count) && isset($count['status'])) {
            return $count;
        }

        if (is_wp_error($count)) {
            return $this->create_metric_result('error', [
                'reason' => $count->get_error_message(),
            ]);
        }

        if ($count === null) {
            $reason = has_filter($filter)
                ? 'GA4 metrics provider returned no data'
                : 'No GA4 metrics provider connected';

            return $this->create_metric_result('pending', [
                'reason' => $reason,
            ]);
        }

        if (!is_numeric($count)) {
            return $this->create_metric_result('error', [
                'reason' => 'Invalid GA4 event count value',
            ]);
        }

        return $this->create_metric_result('ok', [
            'count' => (int) $count,
            'provider' => $filter,
        ]);
    }

    /**
     * Get Facebook event count
     */
    private function get_facebook_event_count($date) {
        $pixel_id = \FpHic\Helpers\hic_get_fb_pixel_id();
        $access_token = \FpHic\Helpers\hic_get_fb_access_token();

        if (empty($pixel_id) || empty($access_token)) {
            return $this->create_metric_result('pending', [
                'reason' => 'Facebook Pixel ID or access token not configured',
            ]);
        }

        $filter = 'hic_reconciliation_facebook_event_count';
        $count = apply_filters($filter, null, $date);

        if (is_array($count) && isset($count['status'])) {
            return $count;
        }

        if (is_wp_error($count)) {
            return $this->create_metric_result('error', [
                'reason' => $count->get_error_message(),
            ]);
        }

        if ($count === null) {
            $reason = has_filter($filter)
                ? 'Facebook metrics provider returned no data'
                : 'No Facebook metrics provider connected';

            return $this->create_metric_result('pending', [
                'reason' => $reason,
            ]);
        }

        if (!is_numeric($count)) {
            return $this->create_metric_result('error', [
                'reason' => 'Invalid Facebook event count value',
            ]);
        }

        return $this->create_metric_result('ok', [
            'count' => (int) $count,
            'provider' => $filter,
        ]);
    }

    /**
     * Get Brevo event count
     */
    private function get_brevo_event_count($date) {
        $brevo_enabled = \FpHic\Helpers\hic_is_brevo_enabled();
        $api_key = \FpHic\Helpers\hic_get_brevo_api_key();

        if (!$brevo_enabled || empty($api_key)) {
            return $this->create_metric_result('pending', [
                'reason' => 'Brevo integration disabled or API key missing',
            ]);
        }

        $filter = 'hic_reconciliation_brevo_event_count';
        $count = apply_filters($filter, null, $date);

        if (is_array($count) && isset($count['status'])) {
            return $count;
        }

        if (is_wp_error($count)) {
            return $this->create_metric_result('error', [
                'reason' => $count->get_error_message(),
            ]);
        }

        if ($count === null) {
            $reason = has_filter($filter)
                ? 'Brevo metrics provider returned no data'
                : 'No Brevo metrics provider connected';

            return $this->create_metric_result('pending', [
                'reason' => $reason,
            ]);
        }

        if (!is_numeric($count)) {
            return $this->create_metric_result('error', [
                'reason' => 'Invalid Brevo event count value',
            ]);
        }

        return $this->create_metric_result('ok', [
            'count' => (int) $count,
            'provider' => $filter,
        ]);
    }

    /**
     * Create a standardized metric result payload.
     *
     * @param string $status
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function create_metric_result(string $status, array $data = []): array {
        return array_merge(['status' => $status], $data);
    }

    /**
     * Get a human readable label for a reconciliation system key.
     */
    private function format_system_label(string $system): string {
        switch ($system) {
            case 'google_analytics':
                return 'Google Analytics 4';
            case 'facebook_api':
                return 'Meta/Facebook';
            case 'brevo_api':
                return 'Brevo';
            default:
                return ucwords(str_replace('_', ' ', $system));
        }
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
            'hic_manage',
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
            <p><?php esc_html_e('Completa la configurazione guidata di 5 minuti per impostare il monitoraggio conversioni del tuo hotel.', 'hotel-in-cloud'); ?></p>
            <p><a href="<?php echo admin_url('admin.php?page=hic-setup-wizard'); ?>" class="button button-primary"><?php esc_html_e('Avvia la configurazione guidata', 'hotel-in-cloud'); ?></a></p>
        </div>
        <?php
    }
    
    /**
     * Render setup wizard
     */
    public function render_setup_wizard() {
        $current_step = max(1, min(5, intval($_GET['step'] ?? 1)));

        $hero_overview = [
            [
                'label' => __('Avanzamento', 'hotel-in-cloud'),
                'value' => sprintf(__('Step %1$d di %2$d', 'hotel-in-cloud'), $current_step, 5),
                'description' => __('Completa il percorso guidato per configurare tutte le integrazioni chiave.', 'hotel-in-cloud'),
                'state' => 'is-active',
            ],
            [
                'label' => __('Tempo stimato', 'hotel-in-cloud'),
                'value' => __('≈ 5 minuti', 'hotel-in-cloud'),
                'description' => __('Puoi sospendere e riprendere in qualsiasi momento.', 'hotel-in-cloud'),
                'state' => 'is-active',
            ],
            [
                'label' => __('Funzionalità incluse', 'hotel-in-cloud'),
                'value' => __('6 moduli', 'hotel-in-cloud'),
                'description' => __('API HIC, Analytics, Facebook, Brevo, Enhanced, Dashboard.', 'hotel-in-cloud'),
                'state' => 'is-active',
            ],
        ];

        ?>
        <div class="wrap hic-admin-page hic-setup-page">
            <div class="hic-page-hero">
                <div class="hic-page-header">
                    <div class="hic-page-header__content">
                        <h1 class="hic-page-header__title">🧭 <?php esc_html_e('Setup guidato FP HIC Monitor', 'hotel-in-cloud'); ?></h1>
                        <p class="hic-page-header__subtitle"><?php esc_html_e('Allinea la configurazione iniziale del monitoraggio con la nuova esperienza grafica del plugin.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-page-actions">
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url(admin_url('admin.php?page=hic-monitoring')); ?>">
                            <span class="dashicons dashicons-chart-line"></span>
                            <?php esc_html_e('Apri dashboard', 'hotel-in-cloud'); ?>
                        </a>
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url('https://support.francopasseri.it/hic-setup'); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-sos"></span>
                            <?php esc_html_e('Supporto rapido', 'hotel-in-cloud'); ?>
                        </a>
                    </div>
                </div>

                <div class="hic-page-meta">
                    <?php foreach ($hero_overview as $overview_item): ?>
                        <div class="hic-page-meta__item">
                            <span class="hic-page-meta__status <?php echo esc_attr($overview_item['state']); ?>"></span>
                            <div class="hic-page-meta__content">
                                <p class="hic-page-meta__label"><?php echo esc_html($overview_item['label']); ?></p>
                                <p class="hic-page-meta__value"><?php echo esc_html($overview_item['value']); ?></p>
                                <?php if (!empty($overview_item['description'])): ?>
                                    <p class="hic-page-meta__description"><?php echo esc_html($overview_item['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="hic-wizard">
                <div class="hic-wizard__progress" role="progressbar" aria-valuemin="1" aria-valuemax="5" aria-valuenow="<?php echo $current_step; ?>">
                    <div class="hic-progress-bar">
                        <div class="hic-progress-fill" style="width: <?php echo ($current_step / 5) * 100; ?>%"></div>
                    </div>
                    <span class="hic-progress-text"><?php echo esc_html(sprintf(__('Passo %1$d di %2$d', 'hotel-in-cloud'), $current_step, 5)); ?></span>
                </div>

                <div class="hic-wizard__content">
                    <?php $this->render_wizard_step($current_step); ?>
                </div>
            </div>
        </div>
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
        <div class="hic-card hic-wizard-step">
            <div class="hic-card__header">
                <div>
                    <h2 class="hic-card__title"><?php esc_html_e('Benvenuto in FP HIC Monitor v3.0', 'hotel-in-cloud'); ?></h2>
                    <p class="hic-card__subtitle"><?php esc_html_e('Imposta il monitoraggio completo delle conversioni in pochi minuti con un percorso guidato.', 'hotel-in-cloud'); ?></p>
                </div>
            </div>
            <div class="hic-card__body">
                <div class="hic-wizard-checklist">
                    <h3><?php esc_html_e('Cosa configureremo', 'hotel-in-cloud'); ?></h3>
                    <ul>
                        <li>✅ <?php esc_html_e('Connessione API Hotel in Cloud', 'hotel-in-cloud'); ?></li>
                        <li>✅ <?php esc_html_e('Google Analytics 4', 'hotel-in-cloud'); ?></li>
                        <li>✅ <?php esc_html_e('Facebook Conversions API', 'hotel-in-cloud'); ?></li>
                        <li>✅ <?php esc_html_e('Automazioni Brevo', 'hotel-in-cloud'); ?></li>
                        <li>✅ <?php esc_html_e('Enhanced Conversions Google Ads', 'hotel-in-cloud'); ?></li>
                        <li>✅ <?php esc_html_e('Dashboard real-time e reporting', 'hotel-in-cloud'); ?></li>
                    </ul>
                </div>
                <div class="hic-wizard-actions">
                    <a href="?page=hic-setup-wizard&step=2" class="hic-button hic-button--primary hic-button--large">
                        <?php esc_html_e('Inizia la configurazione →', 'hotel-in-cloud'); ?>
                    </a>
                </div>
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
        <div class="hic-card hic-wizard-step">
            <div class="hic-card__header">
                <div>
                    <h2 class="hic-card__title"><?php esc_html_e('Passo 1: Connessione API Hotel in Cloud', 'hotel-in-cloud'); ?></h2>
                    <p class="hic-card__subtitle"><?php esc_html_e('Inserisci le credenziali per abilitare la comunicazione sicura con il PMS.', 'hotel-in-cloud'); ?></p>
                </div>
            </div>
            <div class="hic-card__body">
                <form id="hic-wizard-step-2" class="hic-form" novalidate>
                    <div class="hic-field-grid">
                        <div class="hic-field-row">
                            <label class="hic-field-label" for="prop_id"><?php esc_html_e('Property ID', 'hotel-in-cloud'); ?></label>
                            <div class="hic-field-control">
                                <input type="text" id="prop_id" name="prop_id" value="<?php echo esc_attr($settings['prop_id'] ?? ''); ?>" placeholder="HIC-1234">
                                <p class="description"><?php esc_html_e('Identificativo struttura fornito da Hotel in Cloud.', 'hotel-in-cloud'); ?></p>
                            </div>
                        </div>
                        <div class="hic-field-row">
                            <label class="hic-field-label" for="email"><?php esc_html_e('Email', 'hotel-in-cloud'); ?></label>
                            <div class="hic-field-control">
                                <input type="email" id="email" name="email" value="<?php echo esc_attr($settings['email'] ?? ''); ?>" placeholder="gestione@hotel.com">
                            </div>
                        </div>
                        <div class="hic-field-row">
                            <label class="hic-field-label" for="password"><?php esc_html_e('Password', 'hotel-in-cloud'); ?></label>
                            <div class="hic-field-control">
                                <input type="password" id="password" name="password" value="<?php echo esc_attr($settings['password'] ?? ''); ?>" placeholder="••••••">
                                <p class="description"><?php esc_html_e('Le credenziali sono salvate in modo cifrato nel database.', 'hotel-in-cloud'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="hic-form-actions">
                        <button type="button" id="test-hic-connection" class="hic-button hic-button--secondary">
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php esc_html_e('Test connessione', 'hotel-in-cloud'); ?>
                        </button>
                        <span id="connection-status" class="hic-inline-status"></span>
                    </div>
                </form>
            </div>
            <div class="hic-wizard-actions">
                <a href="?page=hic-setup-wizard&step=1" class="hic-button hic-button--ghost">
                    <?php esc_html_e('← Torna indietro', 'hotel-in-cloud'); ?>
                </a>
                <button type="button" class="hic-button hic-button--primary" onclick="saveStepAndContinue(2, 3)">
                    <?php esc_html_e('Salva e continua →', 'hotel-in-cloud'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render analytics setup step
     */
    private function render_step_analytics_setup() {
        ?>
        <div class="hic-card hic-wizard-step">
            <div class="hic-card__header">
                <div>
                    <h2 class="hic-card__title"><?php esc_html_e('Passo 2: Analytics &amp; Conversioni', 'hotel-in-cloud'); ?></h2>
                    <p class="hic-card__subtitle"><?php esc_html_e('Configura gli ID di tracciamento per alimentare Google Analytics e Facebook.', 'hotel-in-cloud'); ?></p>
                </div>
            </div>
            <div class="hic-card__body">
                <form id="hic-wizard-step-3" class="hic-form" novalidate>
                    <div class="hic-wizard-section">
                        <h3><?php esc_html_e('Google Analytics 4', 'hotel-in-cloud'); ?></h3>
                        <div class="hic-field-grid">
                            <div class="hic-field-row">
                                <label class="hic-field-label" for="ga4_measurement_id"><?php esc_html_e('Measurement ID', 'hotel-in-cloud'); ?></label>
                                <div class="hic-field-control">
                                    <input type="text" id="ga4_measurement_id" name="ga4_measurement_id" placeholder="G-XXXXXXXXXX">
                                </div>
                            </div>
                            <div class="hic-field-row">
                                <label class="hic-field-label" for="ga4_api_secret"><?php esc_html_e('API Secret', 'hotel-in-cloud'); ?></label>
                                <div class="hic-field-control">
                                    <input type="text" id="ga4_api_secret" name="ga4_api_secret" placeholder="API Secret">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hic-wizard-section">
                        <h3><?php esc_html_e('Facebook Conversions API', 'hotel-in-cloud'); ?></h3>
                        <div class="hic-field-grid">
                            <div class="hic-field-row">
                                <label class="hic-field-label" for="facebook_pixel_id"><?php esc_html_e('Pixel ID', 'hotel-in-cloud'); ?></label>
                                <div class="hic-field-control">
                                    <input type="text" id="facebook_pixel_id" name="facebook_pixel_id" placeholder="1234567890">
                                </div>
                            </div>
                            <div class="hic-field-row">
                                <label class="hic-field-label" for="facebook_access_token"><?php esc_html_e('Access Token', 'hotel-in-cloud'); ?></label>
                                <div class="hic-field-control">
                                    <input type="text" id="facebook_access_token" name="facebook_access_token" placeholder="EAABsbCS1iHgBA...">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hic-wizard-section">
                        <h3><?php esc_html_e('Integrazione Brevo', 'hotel-in-cloud'); ?></h3>
                        <div class="hic-field-grid">
                            <div class="hic-field-row">
                                <label class="hic-field-label" for="brevo_api_key"><?php esc_html_e('API Key', 'hotel-in-cloud'); ?></label>
                                <div class="hic-field-control">
                                    <input type="text" id="brevo_api_key" name="brevo_api_key" placeholder="xkeysib-...">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="hic-wizard-actions">
                <a href="?page=hic-setup-wizard&step=2" class="hic-button hic-button--ghost">
                    <?php esc_html_e('← Torna indietro', 'hotel-in-cloud'); ?>
                </a>
                <button type="button" class="hic-button hic-button--primary" onclick="saveStepAndContinue(3, 4)">
                    <?php esc_html_e('Salva e continua →', 'hotel-in-cloud'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render advanced features step
     */
    private function render_step_advanced_features() {
        ?>
        <div class="hic-card hic-wizard-step">
            <div class="hic-card__header">
                <div>
                    <h2 class="hic-card__title"><?php esc_html_e('Passo 3: Funzionalità avanzate', 'hotel-in-cloud'); ?></h2>
                    <p class="hic-card__subtitle"><?php esc_html_e('Attiva gli automatismi consigliati per performance e affidabilità.', 'hotel-in-cloud'); ?></p>
                </div>
            </div>
            <div class="hic-card__body">
                <form id="hic-wizard-step-4" class="hic-form" novalidate>
                    <div class="hic-field-grid">
                        <div class="hic-field-row">
                            <div class="hic-field-label"><?php esc_html_e('Intelligent Polling', 'hotel-in-cloud'); ?></div>
                            <div class="hic-field-control">
                                <label class="hic-toggle">
                                    <input type="checkbox" name="enable_intelligent_polling" value="1" checked>
                                    <span><?php esc_html_e('Adatta la frequenza di polling in base al volume di prenotazioni.', 'hotel-in-cloud'); ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="hic-field-row">
                            <div class="hic-field-label"><?php esc_html_e('Dashboard Real-Time', 'hotel-in-cloud'); ?></div>
                            <div class="hic-field-control">
                                <label class="hic-toggle">
                                    <input type="checkbox" name="enable_realtime_dashboard" value="1" checked>
                                    <span><?php esc_html_e('Abilita widget e grafici in tempo reale.', 'hotel-in-cloud'); ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="hic-field-row">
                            <div class="hic-field-label"><?php esc_html_e('Reportistica automatica', 'hotel-in-cloud'); ?></div>
                            <div class="hic-field-control">
                                <label class="hic-toggle">
                                    <input type="checkbox" name="enable_automated_reports" value="1" checked>
                                    <span><?php esc_html_e('Invia report periodici via email.', 'hotel-in-cloud'); ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="hic-field-row">
                            <div class="hic-field-label"><?php esc_html_e('Enhanced Conversions', 'hotel-in-cloud'); ?></div>
                            <div class="hic-field-control">
                                <label class="hic-toggle">
                                    <input type="checkbox" name="enable_enhanced_conversions" value="1" checked>
                                    <span><?php esc_html_e('Abilita l\'upload dei dati arricchiti verso Google Ads.', 'hotel-in-cloud'); ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="hic-field-row">
                            <div class="hic-field-label"><?php esc_html_e('Circuit Breaker', 'hotel-in-cloud'); ?></div>
                            <div class="hic-field-control">
                                <label class="hic-toggle">
                                    <input type="checkbox" name="enable_circuit_breaker" value="1" checked>
                                    <span><?php esc_html_e('Abilita il fallback automatico in caso di API non disponibili.', 'hotel-in-cloud'); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="hic-wizard-actions">
                <a href="?page=hic-setup-wizard&step=3" class="hic-button hic-button--ghost">
                    <?php esc_html_e('← Torna indietro', 'hotel-in-cloud'); ?>
                </a>
                <button type="button" class="hic-button hic-button--primary" onclick="saveStepAndContinue(4, 5)">
                    <?php esc_html_e('Salva e continua →', 'hotel-in-cloud'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render completion step
     */
    private function render_step_completion() {
        ?>
        <div class="hic-card hic-wizard-step">
            <div class="hic-card__header">
                <div>
                    <h2 class="hic-card__title">🎉 <?php esc_html_e('Setup completato!', 'hotel-in-cloud'); ?></h2>
                    <p class="hic-card__subtitle"><?php esc_html_e('FP HIC Monitor è pronto per tracciare le conversioni del tuo hotel con la nuova esperienza grafica.', 'hotel-in-cloud'); ?></p>
                </div>
            </div>
            <div class="hic-card__body">
                <div class="hic-wizard-next">
                    <h3><?php esc_html_e('E adesso?', 'hotel-in-cloud'); ?></h3>
                    <ul>
                        <li>✅ <strong><?php esc_html_e('Monitor Dashboard:', 'hotel-in-cloud'); ?></strong> <a href="<?php echo esc_url(admin_url('admin.php?page=hic-monitoring')); ?>"><?php esc_html_e('Apri la dashboard real-time', 'hotel-in-cloud'); ?></a></li>
                        <li>✅ <strong><?php esc_html_e('Controllo stato:', 'hotel-in-cloud'); ?></strong> <a href="<?php echo esc_url(admin_url('admin.php?page=hic-monitoring-settings')); ?>"><?php esc_html_e('Verifica salute sistema', 'hotel-in-cloud'); ?></a></li>
                        <li>✅ <strong><?php esc_html_e('Reportistica:', 'hotel-in-cloud'); ?></strong> <a href="<?php echo esc_url(admin_url('admin.php?page=hic-reports')); ?>"><?php esc_html_e('Consulta i report', 'hotel-in-cloud'); ?></a></li>
                        <li>✅ <strong><?php esc_html_e('Avvisi automatici:', 'hotel-in-cloud'); ?></strong> <?php esc_html_e('Configura notifiche email per gli eventi critici.', 'hotel-in-cloud'); ?></li>
                    </ul>
                </div>

                <div class="hic-feature-grid">
                    <div class="hic-feature-card">
                        <h4>🚀 <?php esc_html_e('Intelligent Polling', 'hotel-in-cloud'); ?></h4>
                        <p><?php esc_html_e('Frequenza adattiva in base alle prenotazioni.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-feature-card">
                        <h4>📊 <?php esc_html_e('Dashboard Real-Time', 'hotel-in-cloud'); ?></h4>
                        <p><?php esc_html_e('Tracciamento live di conversioni e ricavi.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-feature-card">
                        <h4>📈 <?php esc_html_e('Enhanced Conversions', 'hotel-in-cloud'); ?></h4>
                        <p><?php esc_html_e('Dati arricchiti per migliorare il ROAS.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-feature-card">
                        <h4>🛡️ <?php esc_html_e('Circuit Breaker', 'hotel-in-cloud'); ?></h4>
                        <p><?php esc_html_e('Fallback automatico e retry intelligenti.', 'hotel-in-cloud'); ?></p>
                    </div>
                </div>
            </div>
            <div class="hic-wizard-actions">
                <button type="button" class="hic-button hic-button--primary hic-button--large" onclick="completeSetup()">
                    <?php esc_html_e('Completa e vai alla dashboard', 'hotel-in-cloud'); ?>
                </button>
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
                    window.location.href = '<?php echo admin_url('admin.php?page=hic-monitoring'); ?>';
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
        if (current_user_can('hic_manage')) {
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
        if (!$this->is_setup_wizard_hook($hook)) {
            return;
        }

        $base_url = plugin_dir_url(dirname(__DIR__) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php');

        wp_enqueue_style(
            'hic-admin-base',
            $base_url . 'assets/css/hic-admin.css',
            [],
            HIC_PLUGIN_VERSION
        );

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

    private function is_setup_wizard_hook($hook): bool
    {
        if (!is_string($hook)) {
            return false;
        }

        if (strpos($hook, '_page_hic-setup-wizard') !== false) {
            return true;
        }

        // Legacy fallback when hook names don't include the parent slug
        return strpos($hook, 'hic-setup-wizard') !== false;
    }
    
    // ===== AJAX HANDLERS =====
    
    /**
     * AJAX: Setup wizard step
     */
    public function ajax_setup_wizard_step() {
        hic_require_cap('hic_manage');
        
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
        hic_require_cap('hic_manage');

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
        hic_require_cap('hic_manage');

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
        hic_require_cap('hic_manage');
        
        if (!check_ajax_referer('hic_health_check', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        $this->run_health_check();
        wp_send_json_success(['message' => 'Health check completed']);
    }
    
    /**
     * Log messages
     */
    private function log($message, $level = null, array $context = []) {
        if (!function_exists('\\FpHic\\Helpers\\hic_log')) {
            return;
        }

        if ($level === null) {
            $level = defined('HIC_LOG_LEVEL_INFO') ? HIC_LOG_LEVEL_INFO : 'info';
        }

        \FpHic\Helpers\hic_log("[Enterprise Management] {$message}", $level, $context);
    }
}

// Note: Class instantiation moved to main plugin file for proper admin menu ordering