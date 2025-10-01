<?php declare(strict_types=1);

namespace FpHic\AutomatedReporting;

use function FpHic\Helpers\hic_require_cap;

if (!defined('ABSPATH')) exit;

/**
 * Automated Reporting System - Enterprise Grade
 * 
 * Provides automated daily/weekly reports via email, CSV/Excel exports,
 * period comparisons, and revenue attribution analytics.
 */

class AutomatedReportingManager {

    /** @var self|null */
    private static ?self $instance = null;
    
    /** @var array Report types configuration */
    private const REPORT_TYPES = [
        'daily' => [
            'frequency' => 'daily',
            'hook' => 'hic_daily_report',
            'template' => 'daily-report'
        ],
        'weekly' => [
            'frequency' => 'weekly',
            'hook' => 'hic_weekly_report', 
            'template' => 'weekly-report'
        ],
        'monthly' => [
            'frequency' => 'monthly',
            'hook' => 'hic_monthly_report',
            'template' => 'monthly-report'
        ]
    ];
    
    /** @var string Export directory */
    private $export_dir;
    
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function register_cron_hooks(): void
    {
        add_action('hic_daily_report', [self::class, 'handle_daily_report']);
        add_action('hic_weekly_report', [self::class, 'handle_weekly_report']);
        add_action('hic_monthly_report', [self::class, 'handle_monthly_report']);
        add_action('hic_cleanup_exports', [self::class, 'handle_cleanup_exports']);
    }

    /**
     * Register additional cron schedules required by the reporting system.
     *
     * WordPress exposes only hourly, twice daily and daily intervals by
     * default. The automated reports rely on dedicated weekly and monthly
     * frequencies; without registering them the related wp_schedule_event()
     * calls would simply be ignored and the reports would never run.
     *
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function register_cron_schedules($schedules)
    {
        $day_seconds = defined('DAY_IN_SECONDS') ? \DAY_IN_SECONDS : 86400;

        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => defined('WEEK_IN_SECONDS') ? \WEEK_IN_SECONDS : 7 * $day_seconds,
                'display'  => \__('Weekly (HIC Reports)', 'hotel-in-cloud'),
            ];
        }

        if (!isset($schedules['monthly'])) {
            $month_interval = defined('MONTH_IN_SECONDS') ? \MONTH_IN_SECONDS : 30 * $day_seconds;

            $schedules['monthly'] = [
                'interval' => $month_interval,
                'display'  => \__('Monthly (HIC Reports)', 'hotel-in-cloud'),
            ];
        }

        return $schedules;
    }

    public function __construct() {
        if (null === self::$instance) {
            self::$instance = $this;
        }

        add_filter('cron_schedules', [self::class, 'register_cron_schedules']);

        add_action('init', [$this, 'initialize_reporting'], 30);

        // AJAX handlers for manual reports and exports
        add_action('wp_ajax_hic_generate_manual_report', [$this, 'ajax_generate_manual_report']);
        add_action('wp_ajax_hic_export_data_csv', [$this, 'ajax_export_data_csv']);
        add_action('wp_ajax_hic_export_data_excel', [$this, 'ajax_export_data_excel']);
        add_action('wp_ajax_hic_schedule_report', [$this, 'ajax_schedule_report']);
        add_action('wp_ajax_hic_get_report_history', [$this, 'ajax_get_report_history']);
        add_action('wp_ajax_hic_download_export', [$this, 'handle_download_export']);
        
        // Admin menu integration
        add_action('admin_menu', [$this, 'add_reports_menu'], 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_reporting_assets']);
        
        // Email template hooks
        add_action('hic_before_email_report', [$this, 'prepare_email_context']);
        add_filter('hic_email_report_subject', [$this, 'customize_email_subject'], 10, 2);
        
        $this->export_dir = wp_upload_dir()['basedir'] . '/hic-exports/';
    }
    
    /**
     * Initialize reporting system
     */
    public function initialize_reporting() {
        if (did_action('hic_automated_reporting_initialized')) {
            $this->log('Automated Reporting Manager already initialized, skipping duplicate setup');
            return;
        }

        $this->log('Initializing Automated Reporting Manager');

        // Create exports directory
        try {
            $this->ensure_export_directory();
        } catch (\RuntimeException $exception) {
            $this->log('Export directory initialization failed: ' . $exception->getMessage());
            $this->add_admin_error_notice('Automated Reporting error: ' . $exception->getMessage());
            return;
        }

        // Create reports history table
        $this->create_reports_history_table();

        // Schedule automatic reports
        $this->schedule_automatic_reports();

        // Clean up old exports periodically
        $this->schedule_export_cleanup();

        do_action('hic_automated_reporting_initialized', $this);
    }
    
    /**
     * Ensure export directory exists and is secure
     */
    private function ensure_export_directory() {
        if (!is_dir($this->export_dir)) {
            $directory_created = false;

            if (function_exists('wp_mkdir_p')) {
                $directory_created = wp_mkdir_p($this->export_dir);
            } else {
                $directory_created = @mkdir($this->export_dir, 0755, true);
            }

            if (!$directory_created && !is_dir($this->export_dir)) {
                $message = sprintf('Failed to create export directory at %s. Please verify the directory permissions.', $this->export_dir);
                $this->log($message);
                throw new \RuntimeException($message);
            }
        }

        $htaccess_path = $this->export_dir . '.htaccess';
        if (!file_exists($htaccess_path)) {
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            if (@file_put_contents($htaccess_path, $htaccess_content) === false) {
                $message = sprintf('Failed to write .htaccess file for export directory at %s. Please verify the directory permissions.', $htaccess_path);
                $this->log($message);
                throw new \RuntimeException($message);
            }
        }

        $index_path = $this->export_dir . 'index.php';
        if (!file_exists($index_path)) {
            if (@file_put_contents($index_path, '<?php // Silence is golden') === false) {
                $message = sprintf('Failed to write index.php file for export directory at %s. Please verify the directory permissions.', $index_path);
                $this->log($message);
                throw new \RuntimeException($message);
            }
        }
    }

    private function add_admin_error_notice($message) {
        $notice_message = $message;

        if (function_exists('esc_html')) {
            $notice_message = esc_html($notice_message);
        }

        $notice_callback = static function () use ($notice_message) {
            echo '<div class="notice notice-error"><p>' . $notice_message . '</p></div>';
        };

        add_action('admin_notices', $notice_callback);
        add_action('network_admin_notices', $notice_callback);
    }
    
    /**
     * Create reports history tracking table
     */
    private function create_reports_history_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_reports_history';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            report_type VARCHAR(50) NOT NULL,
            report_period VARCHAR(50) NOT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('generating', 'completed', 'failed', 'sent') DEFAULT 'generating',
            file_path VARCHAR(500),
            file_size BIGINT DEFAULT 0,
            email_recipients TEXT,
            error_message TEXT,
            metrics_snapshot LONGTEXT,
            
            INDEX idx_report_type (report_type),
            INDEX idx_generated_at (generated_at),
            INDEX idx_status (status)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Schedule automatic reports based on settings
     */
    private function schedule_automatic_reports() {
        $reporting_settings = get_option('hic_reporting_settings', [
            'daily_enabled' => false,
            'weekly_enabled' => true,
            'monthly_enabled' => true,
            'email_recipients' => [get_option('admin_email')],
            'include_attachments' => true
        ]);
        
        foreach (self::REPORT_TYPES as $type => $config) {
            $enabled_key = $type . '_enabled';
            
            if (!empty($reporting_settings[$enabled_key])) {
                if (!wp_next_scheduled($config['hook'])) {
                    $schedule_time = $this->get_optimal_schedule_time($type);
                    wp_schedule_event($schedule_time, $config['frequency'], $config['hook']);
                    $this->log("Scheduled {$type} report generation");
                }
            } else {
                // Unschedule if disabled
                wp_clear_scheduled_hook($config['hook']);
            }
        }
    }
    
    /**
     * Get optimal schedule time for reports (non-peak hours)
     */
    private function get_optimal_schedule_time($report_type) {
        $base_time = time();
        
        switch ($report_type) {
            case 'daily':
                // Schedule for 6 AM next day
                return strtotime('tomorrow 06:00:00');
            case 'weekly':
                // Schedule for Monday 7 AM
                return strtotime('next monday 07:00:00');
            case 'monthly':
                // Schedule for 1st of next month, 8 AM
                return strtotime('first day of next month 08:00:00');
            default:
                return $base_time + 3600; // 1 hour from now
        }
    }
    
    /**
     * Schedule periodic cleanup of old exports
     */
    private function schedule_export_cleanup() {
        if (!wp_next_scheduled('hic_cleanup_exports')) {
            wp_schedule_event(time(), 'weekly', 'hic_cleanup_exports');
        }
    }
    
    /**
     * Generate daily report
     */
    public function generate_daily_report() {
        $this->log('Generating daily report');
        
        $report_data = $this->collect_daily_data();
        $report_id = $this->create_report_record('daily', 'today');
        
        try {
            // Generate report files
            $csv_file = $this->generate_csv_report($report_data, 'daily');
            $excel_file = $this->generate_excel_report($report_data, 'daily');
            
            // Send email report
            $email_sent = $this->send_email_report('daily', $report_data, [$csv_file, $excel_file]);
            
            // Update report record
            $this->update_report_record($report_id, 'completed', $csv_file, $report_data);
            
            $this->log('Daily report generated successfully');
            
        } catch (\Exception $e) {
            $this->update_report_record($report_id, 'failed', null, null, $e->getMessage());
            $this->log('Daily report generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate weekly report
     */
    public function generate_weekly_report() {
        $this->log('Generating weekly report');
        
        $report_data = $this->collect_weekly_data();
        $report_id = $this->create_report_record('weekly', 'last_7_days');
        
        try {
            // Generate comprehensive weekly analysis
            $report_data['comparison'] = $this->get_period_comparison('weekly');
            $report_data['trends'] = $this->analyze_weekly_trends($report_data);
            $report_data['recommendations'] = $this->generate_recommendations($report_data);
            
            // Generate report files
            $csv_file = $this->generate_csv_report($report_data, 'weekly');
            $excel_file = $this->generate_excel_report($report_data, 'weekly');
            $pdf_file = $this->generate_pdf_report($report_data, 'weekly');
            
            // Send email report
            $email_sent = $this->send_email_report('weekly', $report_data, [$csv_file, $excel_file, $pdf_file]);
            
            // Update report record
            $this->update_report_record($report_id, $email_sent ? 'sent' : 'completed', $csv_file, $report_data);
            
            $this->log('Weekly report generated successfully');
            
        } catch (\Exception $e) {
            $this->update_report_record($report_id, 'failed', null, null, $e->getMessage());
            $this->log('Weekly report generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate monthly report
     */
    public function generate_monthly_report() {
        $this->log('Generating monthly report');
        
        $report_data = $this->collect_monthly_data();
        $report_id = $this->create_report_record('monthly', 'last_30_days');
        
        try {
            // Generate comprehensive monthly analysis
            $report_data['comparison'] = $this->get_period_comparison('monthly');
            $report_data['trends'] = $this->analyze_monthly_trends($report_data);
            $report_data['channel_performance'] = $this->analyze_channel_performance($report_data);
            $report_data['recommendations'] = $this->generate_recommendations($report_data);
            $report_data['forecast'] = $this->generate_forecast($report_data);
            
            // Generate report files
            $csv_file = $this->generate_csv_report($report_data, 'monthly');
            $excel_file = $this->generate_excel_report($report_data, 'monthly');
            $pdf_file = $this->generate_pdf_report($report_data, 'monthly');
            
            // Send email report
            $email_sent = $this->send_email_report('monthly', $report_data, [$csv_file, $excel_file, $pdf_file]);
            
            // Update report record
            $this->update_report_record($report_id, $email_sent ? 'sent' : 'completed', $csv_file, $report_data);
            
            $this->log('Monthly report generated successfully');
            
        } catch (\Exception $e) {
            $this->update_report_record($report_id, 'failed', null, null, $e->getMessage());
            $this->log('Monthly report generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Collect daily data for reporting
     */
    private function collect_daily_data() {
        global $wpdb;

        $main_table = $wpdb->prefix . 'hic_booking_metrics';
        $current_day = current_time('mysql');
        if (!is_string($current_day) || $current_day === '') {
            $today = gmdate('Y-m-d');
        } else {
            $today = substr($current_day, 0, 10);
        }

        $data = [
            'period' => 'daily',
            'date_range' => $today,
            'summary' => [],
            'by_hour' => [],
            'by_source' => [],
            'by_medium' => [],
            'conversions' => []
        ];

        // Summary statistics based on actual booking metrics.
        $data['summary'] = $wpdb->get_row($wpdb->prepare("
            SELECT
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as total_bookings,
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'google' THEN 1 ELSE 0 END) as google_conversions,
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'facebook' THEN 1 ELSE 0 END) as facebook_conversions,
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'direct' THEN 1 ELSE 0 END) as direct_conversions,
                COALESCE(SUM(amount), 0) as estimated_revenue
            FROM {$main_table}
            WHERE DATE(created_at) = %s
        ", $today), ARRAY_A);

        // Hourly breakdown with real revenue totals.
        $data['by_hour'] = $wpdb->get_results($wpdb->prepare("
            SELECT
                HOUR(created_at) as hour,
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                COALESCE(SUM(amount), 0) as revenue
            FROM {$main_table}
            WHERE DATE(created_at) = %s
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ", $today), ARRAY_A);

        if (is_array($data['by_hour'])) {
            foreach ($data['by_hour'] as &$hour_data) {
                $hour_data['bookings'] = isset($hour_data['bookings']) ? (int) $hour_data['bookings'] : 0;
                $hour_data['revenue'] = isset($hour_data['revenue']) ? (float) $hour_data['revenue'] : 0.0;
            }
            unset($hour_data);
        }

        // Attribution by source with friendly labels.
        $sources = $wpdb->get_results($wpdb->prepare("
            SELECT
                COALESCE(NULLIF(utm_source, ''), 'direct') as source_key,
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                COALESCE(SUM(amount), 0) as revenue
            FROM {$main_table}
            WHERE DATE(created_at) = %s
            GROUP BY source_key
            ORDER BY bookings DESC
        ", $today), ARRAY_A);

        foreach ($sources as $row) {
            $data['by_source'][] = [
                'source' => $this->normalize_source_label($row['source_key'] ?? ''),
                'bookings' => isset($row['bookings']) ? (int) $row['bookings'] : 0,
                'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
            ];
        }

        // Determine top-performing sources and campaigns for each hour to enrich exports.
        $hourly_sources = $wpdb->get_results($wpdb->prepare("
            SELECT
                HOUR(created_at) AS hour,
                utm_source AS source_key,
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) AS bookings
            FROM {$main_table}
            WHERE DATE(created_at) = %s
            GROUP BY HOUR(created_at), utm_source
            ORDER BY HOUR(created_at), bookings DESC
        ", $today), ARRAY_A);

        $top_sources_by_hour = [];
        foreach ($hourly_sources as $row) {
            $hour = isset($row['hour']) ? (int) $row['hour'] : null;
            if ($hour === null) {
                continue;
            }

            $bookings = (int) ($row['bookings'] ?? 0);
            if ($bookings <= 0) {
                continue;
            }

            if (!isset($top_sources_by_hour[$hour]) || $bookings > $top_sources_by_hour[$hour]['bookings']) {
                $top_sources_by_hour[$hour] = [
                    'label' => $this->normalize_source_label($row['source_key'] ?? ''),
                    'bookings' => $bookings,
                ];
            }
        }

        $hourly_campaigns = $wpdb->get_results($wpdb->prepare("
            SELECT
                HOUR(created_at) AS hour,
                utm_campaign AS campaign_key,
                SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) AS bookings
            FROM {$main_table}
            WHERE DATE(created_at) = %s
            GROUP BY HOUR(created_at), utm_campaign
            ORDER BY HOUR(created_at), bookings DESC
        ", $today), ARRAY_A);

        $top_campaigns_by_hour = [];
        foreach ($hourly_campaigns as $row) {
            $hour = isset($row['hour']) ? (int) $row['hour'] : null;
            if ($hour === null) {
                continue;
            }

            $bookings = (int) ($row['bookings'] ?? 0);
            if ($bookings <= 0) {
                continue;
            }

            if (!isset($top_campaigns_by_hour[$hour]) || $bookings > $top_campaigns_by_hour[$hour]['bookings']) {
                $top_campaigns_by_hour[$hour] = [
                    'label' => $this->normalize_campaign_label($row['campaign_key'] ?? ''),
                    'bookings' => $bookings,
                ];
            }
        }

        if (is_array($data['by_hour'])) {
            foreach ($data['by_hour'] as &$hour_data) {
                $hour = isset($hour_data['hour']) ? (int) $hour_data['hour'] : null;
                if ($hour === null) {
                    continue;
                }

                $total_bookings = (int) ($hour_data['bookings'] ?? 0);

                if (isset($top_sources_by_hour[$hour])) {
                    $best_source = $top_sources_by_hour[$hour];
                    $hour_data['top_source_label'] = $best_source['label'];
                    $hour_data['top_source_count'] = $best_source['bookings'];
                    $hour_data['top_source_share'] = $total_bookings > 0
                        ? round(($best_source['bookings'] / $total_bookings) * 100, 1)
                        : null;
                }

                if (isset($top_campaigns_by_hour[$hour])) {
                    $best_campaign = $top_campaigns_by_hour[$hour];
                    $hour_data['top_campaign_label'] = $best_campaign['label'];
                    $hour_data['top_campaign_count'] = $best_campaign['bookings'];
                    $hour_data['top_campaign_share'] = $total_bookings > 0
                        ? round(($best_campaign['bookings'] / $total_bookings) * 100, 1)
                        : null;
                }
            }
            unset($hour_data);
        }

        return $data;
    }
    
    /**
     * Collect weekly data for reporting
     */
    private function collect_weekly_data() {
        global $wpdb;

        $main_table = $wpdb->prefix . 'hic_booking_metrics';

        $current_timestamp = function_exists('current_time')
            ? (int) current_time('timestamp')
            : time();
        $seconds_per_day = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        $weekly_start = date('Y-m-d H:i:s', $current_timestamp - (7 * $seconds_per_day));

        $data = [
            'period' => 'weekly',
            'date_range' => date('Y-m-d', strtotime('-7 days')) . ' to ' . date('Y-m-d'),
            'summary' => [],
            'daily_breakdown' => [],
            'by_source' => [],
            'by_campaign' => [],
            'performance_metrics' => []
        ];

        // Weekly summary aggregated from real bookings.
        $weekly_summary_sql = $wpdb->prepare(
            "
            SELECT
                totals.total_bookings,
                totals.google_conversions,
                totals.facebook_conversions,
                totals.direct_conversions,
                totals.estimated_revenue,
                ROUND(IFNULL(avg_stats.avg_bookings, 0), 2) as avg_daily_bookings
            FROM (
                SELECT
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as total_bookings,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'google' THEN 1 ELSE 0 END) as google_conversions,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'facebook' THEN 1 ELSE 0 END) as facebook_conversions,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'direct' THEN 1 ELSE 0 END) as direct_conversions,
                    COALESCE(SUM(amount), 0) as estimated_revenue
                FROM {$main_table}
                WHERE created_at >= %s
            ) as totals
            CROSS JOIN (
                SELECT AVG(day_bookings) AS avg_bookings
                FROM (
                    SELECT SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) AS day_bookings
                    FROM {$main_table}
                    WHERE created_at >= %s
                    GROUP BY DATE(created_at)
                ) AS per_day
            ) AS avg_stats
        ",
            $weekly_start,
            $weekly_start
        );
        $data['summary'] = $wpdb->get_row($weekly_summary_sql, ARRAY_A);

        // Daily breakdown for the week with revenue totals.
        $data['daily_breakdown'] = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    DATE(created_at) as date,
                    DAYNAME(created_at) as day_name,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                    COALESCE(SUM(amount), 0) as revenue
                FROM {$main_table}
                WHERE created_at >= %s
                GROUP BY DATE(created_at)
                ORDER BY date
            ",
                $weekly_start
            ),
            ARRAY_A
        );

        $previous_bookings = null;
        foreach ($data['daily_breakdown'] as &$day_data) {
            $day_data['bookings'] = isset($day_data['bookings']) ? (int) $day_data['bookings'] : 0;
            $day_data['revenue'] = isset($day_data['revenue']) ? (float) $day_data['revenue'] : 0.0;

            $growth = $this->calculate_percentage_change((float) $day_data['bookings'], $previous_bookings);
            $day_data['growth_percent'] = $growth !== null ? round($growth, 2) : null;
            $previous_bookings = (float) $day_data['bookings'];
        }
        unset($day_data);

        // Attribution aggregated for the week.
        $source_rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    COALESCE(NULLIF(utm_source, ''), 'direct') as source_key,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                    COALESCE(SUM(amount), 0) as revenue
                FROM {$main_table}
                WHERE created_at >= %s
                GROUP BY source_key
                ORDER BY bookings DESC
            ",
                $weekly_start
            ),
            ARRAY_A
        );

        foreach ($source_rows as $row) {
            $data['by_source'][] = [
                'source' => $this->normalize_source_label($row['source_key'] ?? ''),
                'bookings' => isset($row['bookings']) ? (int) $row['bookings'] : 0,
                'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
            ];
        }

        // Top performing campaigns with actual booking counts.
        $data['by_campaign'] = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    utm_campaign as campaign,
                    COALESCE(NULLIF(utm_source, ''), 'direct') as source,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                    COALESCE(SUM(amount), 0) as revenue,
                    CASE
                        WHEN totals.total_bookings > 0 THEN ROUND((SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) * 100.0) / totals.total_bookings, 2)
                        ELSE 0
                    END as percentage
                FROM {$main_table}
                CROSS JOIN (
                    SELECT SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as total_bookings
                    FROM {$main_table}
                    WHERE created_at >= %s
                    AND utm_campaign IS NOT NULL AND utm_campaign != ''
                ) as totals
                WHERE created_at >= %s
                AND utm_campaign IS NOT NULL AND utm_campaign != ''
                GROUP BY utm_campaign, source
                ORDER BY bookings DESC
                LIMIT 10
            ",
                $weekly_start,
                $weekly_start
            ),
            ARRAY_A
        );

        foreach ($data['by_campaign'] as &$campaign_row) {
            $campaign_row['source'] = $this->normalize_source_label($campaign_row['source'] ?? '');
            $campaign_row['bookings'] = isset($campaign_row['bookings']) ? (int) $campaign_row['bookings'] : 0;
            $campaign_row['revenue'] = isset($campaign_row['revenue']) ? (float) $campaign_row['revenue'] : 0.0;
        }
        unset($campaign_row);

        return $data;
    }
    
    /**
     * Collect monthly data for reporting
     */
    private function collect_monthly_data() {
        global $wpdb;

        $main_table = $wpdb->prefix . 'hic_booking_metrics';

        $current_timestamp = function_exists('current_time')
            ? (int) current_time('timestamp')
            : time();
        $seconds_per_day = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        $monthly_start = date('Y-m-d H:i:s', $current_timestamp - (30 * $seconds_per_day));

        $data = [
            'period' => 'monthly',
            'date_range' => date('Y-m-d', strtotime('-30 days')) . ' to ' . date('Y-m-d'),
            'summary' => [],
            'weekly_breakdown' => [],
            'top_campaigns' => [],
            'channel_analysis' => []
        ];

        // Monthly summary with real revenue.
        $monthly_summary_sql = $wpdb->prepare(
            "
            SELECT
                totals.total_bookings,
                totals.google_conversions,
                totals.facebook_conversions,
                totals.estimated_revenue,
                ROUND(IFNULL(avg_stats.avg_bookings, 0), 2) as avg_daily_bookings
            FROM (
                SELECT
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as total_bookings,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'google' THEN 1 ELSE 0 END) as google_conversions,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 AND LOWER(COALESCE(NULLIF(utm_source, ''), 'direct')) = 'facebook' THEN 1 ELSE 0 END) as facebook_conversions,
                    COALESCE(SUM(amount), 0) as estimated_revenue
                FROM {$main_table}
                WHERE created_at >= %s
            ) as totals
            CROSS JOIN (
                SELECT AVG(day_bookings) AS avg_bookings
                FROM (
                    SELECT SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) AS day_bookings
                    FROM {$main_table}
                    WHERE created_at >= %s
                    GROUP BY DATE(created_at)
                ) AS per_day
            ) AS avg_stats
        ",
            $monthly_start,
            $monthly_start
        );
        $data['summary'] = $wpdb->get_row($monthly_summary_sql, ARRAY_A);

        // Weekly breakdown for trend analysis with real revenue.
        $data['weekly_breakdown'] = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    WEEK(created_at, 1) as week_number,
                    CONCAT('Week ', WEEK(created_at, 1)) as week_label,
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                    COALESCE(SUM(amount), 0) as revenue,
                    MIN(DATE(created_at)) as week_start
                FROM {$main_table}
                WHERE created_at >= %s
                GROUP BY WEEK(created_at, 1)
                ORDER BY week_start
            ",
                $monthly_start
            ),
            ARRAY_A
        );

        $previous_revenue = null;
        foreach ($data['weekly_breakdown'] as &$week_data) {
            $week_data['bookings'] = isset($week_data['bookings']) ? (int) $week_data['bookings'] : 0;
            $week_data['revenue'] = isset($week_data['revenue']) ? (float) $week_data['revenue'] : 0.0;

            $trend = $this->calculate_percentage_change($week_data['revenue'], $previous_revenue);
            $week_data['trend_percent'] = $trend !== null ? round($trend, 2) : null;
            $previous_revenue = $week_data['revenue'];
        }
        unset($week_data);

        return $data;
    }

    /**
     * Calculate the percentage change between two numeric values.
     */
    private function calculate_percentage_change($current, $previous) {
        $current_value = $current !== null ? (float) $current : 0.0;

        if ($previous === null) {
            return null;
        }

        $previous_value = (float) $previous;

        if ($previous_value === 0.0) {
            if ($current_value === 0.0) {
                return 0.0;
            }

            return 100.0;
        }

        return (($current_value - $previous_value) / $previous_value) * 100.0;
    }
    
    /**
     * Get period comparison data
     */
    private function get_period_comparison($period_type) {
        global $wpdb;

        $main_table = $wpdb->prefix . 'hic_booking_metrics';

        $current_timestamp = function_exists('current_time')
            ? (int) current_time('timestamp')
            : time();
        $seconds_per_day = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;

        $range_days = 7;
        if ($period_type === 'daily') {
            $range_days = 1;
        } elseif ($period_type === 'monthly') {
            $range_days = 30;
        }

        $current_start = date('Y-m-d H:i:s', $current_timestamp - ($range_days * $seconds_per_day));
        $previous_start = date('Y-m-d H:i:s', $current_timestamp - ($range_days * 2 * $seconds_per_day));

        $current = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                    COALESCE(SUM(amount), 0) as revenue
                FROM {$main_table}
                WHERE created_at >= %s
            ",
                $current_start
            ),
            ARRAY_A
        );

        $previous = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT
                    SUM(CASE WHEN COALESCE(is_refund, 0) = 0 THEN 1 ELSE 0 END) as bookings,
                    COALESCE(SUM(amount), 0) as revenue
                FROM {$main_table}
                WHERE created_at >= %s
                  AND created_at < %s
            ",
                $previous_start,
                $current_start
            ),
            ARRAY_A
        );

        $current = is_array($current) ? $current : ['bookings' => 0, 'revenue' => 0.0];
        $previous = is_array($previous) ? $previous : ['bookings' => 0, 'revenue' => 0.0];

        $current['bookings'] = isset($current['bookings']) ? (int) $current['bookings'] : 0;
        $current['revenue'] = isset($current['revenue']) ? (float) $current['revenue'] : 0.0;
        $previous['bookings'] = isset($previous['bookings']) ? (int) $previous['bookings'] : 0;
        $previous['revenue'] = isset($previous['revenue']) ? (float) $previous['revenue'] : 0.0;

        $booking_growth = $this->calculate_percentage_change((float) $current['bookings'], (float) $previous['bookings']);
        $revenue_growth = $this->calculate_percentage_change($current['revenue'], $previous['revenue']);

        return [
            'current' => $current,
            'previous' => $previous,
            'growth' => [
                'bookings' => $booking_growth !== null ? round($booking_growth, 2) : 0.0,
                'revenue' => $revenue_growth !== null ? round($revenue_growth, 2) : 0.0,
            ]
        ];
    }
    
    /**
     * Generate CSV report
     */
    private function generate_csv_report($data, $report_type) {
        $filename = sprintf('hic-%s-report-%s.csv', $report_type, date('Y-m-d-H-i-s'));
        $filepath = $this->export_dir . $filename;

        $file = @fopen($filepath, 'w');

        if ($file === false) {
            $this->log('Failed to open report file for writing: ' . $filepath);
            throw new \RuntimeException('Unable to open export file for writing. Please verify the export directory is writable.');
        }

        [$headers, $rows] = $this->get_report_table_rows($data, $report_type);

        try {
            if (!empty($headers)) {
                fputcsv($file, $headers);
            }

            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }

        $this->log("CSV report generated: {$filename}");

        return $filepath;
    }

    /**
     * Generate Excel report using PhpSpreadsheet
     */
    private function generate_excel_report($data, $report_type) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $this->log('PhpSpreadsheet not available, skipping Excel generation');
            return null;
        }

        $filename = sprintf('hic-%s-report-%s.xlsx', $report_type, date('Y-m-d-H-i-s'));
        $filepath = $this->export_dir . $filename;

        [$headers, $rows] = $this->get_report_table_rows($data, $report_type);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $current_row = 1;

        if (!empty($headers)) {
            $sheet->fromArray($headers, null, 'A' . $current_row);
            $current_row++;
        }

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A' . $current_row);
        }

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filepath);

        $this->log("Excel report generated: {$filename}");

        return $filepath;
    }
    
    /**
     * Generate PDF report
     */
    private function generate_pdf_report($data, $report_type) {
        $filename = sprintf('hic-%s-report-%s.pdf', $report_type, date('Y-m-d-H-i-s'));
        $filepath = $this->export_dir . $filename;

        $report_data = is_array($data) ? $data : (array) $data;
        $lines = $this->build_pdf_text_lines($report_data, (string) $report_type);
        $pdf_binary = $this->render_pdf_document($lines);

        if (@file_put_contents($filepath, $pdf_binary) === false) {
            throw new \RuntimeException('Failed to write PDF report to ' . $filepath);
        }

        $this->log("PDF report generated: {$filename}");

        return $filepath;
    }
    
    /**
     * Send email report
     */
    private function send_email_report($report_type, $data, $attachments = []) {
        $settings = get_option('hic_reporting_settings', []);
        $recipients = $settings['email_recipients'] ?? [get_option('admin_email')];

        if (empty($recipients)) {
            $this->log('No email recipients configured for reports');
            return false;
        }

        if (!empty($attachments)) {
            $attachments = array_values(array_filter(
                $attachments,
                static function ($attachment) {
                    return is_string($attachment) && $attachment !== '';
                }
            ));
        }

        $subject = $this->get_email_subject($report_type, $data);
        $message = $this->get_email_message($report_type, $data);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
        
        $sent = true;
        foreach ($recipients as $recipient) {
            if (!wp_mail($recipient, $subject, $message, $headers, $attachments)) {
                $sent = false;
                $this->log("Failed to send report email to: {$recipient}");
            }
        }
        
        if ($sent) {
            $this->log("Report email sent successfully to " . count($recipients) . " recipients");
        }
        
        return $sent;
    }
    
    /**
     * Build table rows for report exports
     */
    private function get_report_table_rows($data, $report_type) {
        $headers = [];
        $rows = [];

        switch ($report_type) {
            case 'daily':
                $headers = ['Hour', 'Bookings', 'Revenue', 'Source', 'Campaign'];

                if (!empty($data['by_hour']) && is_array($data['by_hour'])) {
                    foreach ($data['by_hour'] as $hour_data) {
                        $rows[] = [
                            isset($hour_data['hour']) ? $hour_data['hour'] . ':00' : '',
                            $hour_data['bookings'] ?? 0,
                            '€' . number_format((float)($hour_data['revenue'] ?? 0), 2),
                            $this->format_share_label(
                                $hour_data['top_source_label'] ?? null,
                                $hour_data['top_source_share'] ?? null,
                                $hour_data['top_source_count'] ?? null
                            ),
                            $this->format_share_label(
                                $hour_data['top_campaign_label'] ?? null,
                                $hour_data['top_campaign_share'] ?? null,
                                $hour_data['top_campaign_count'] ?? null
                            )
                        ];
                    }
                }

                break;
            case 'weekly':
                $headers = ['Date', 'Day', 'Bookings', 'Revenue', 'Growth %'];

                if (!empty($data['daily_breakdown']) && is_array($data['daily_breakdown'])) {
                    foreach ($data['daily_breakdown'] as $day_data) {
                        $rows[] = [
                            $day_data['date'] ?? '',
                            $day_data['day_name'] ?? '',
                            $day_data['bookings'] ?? 0,
                            '€' . number_format((float)($day_data['revenue'] ?? 0), 2),
                            $this->format_percentage($day_data['growth_percent'] ?? null)
                        ];
                    }
                }

                break;
            case 'monthly':
                $headers = ['Week', 'Bookings', 'Revenue', 'Trend'];

                if (!empty($data['weekly_breakdown']) && is_array($data['weekly_breakdown'])) {
                    foreach ($data['weekly_breakdown'] as $week_data) {
                        $rows[] = [
                            $week_data['week_label'] ?? '',
                            $week_data['bookings'] ?? 0,
                            '€' . number_format((float)($week_data['revenue'] ?? 0), 2),
                            $this->format_trend_label($week_data['trend_percent'] ?? null)
                        ];
                    }
                }

                break;
        }

        return [$headers, $rows];
    }

    /**
     * Build plain-text lines for PDF rendering using booking metrics data.
     *
     * @param array<string, mixed> $data
     * @param string $report_type
     * @return array<int, string>
     */
    private function build_pdf_text_lines(array $data, string $report_type): array
    {
        $lines = [];

        $lines[] = 'FP HIC Monitor - ' . ucfirst($report_type) . ' report';
        $lines[] = 'Generated on ' . date('Y-m-d H:i');

        if (!empty($data['date_range'])) {
            $lines[] = 'Period: ' . (is_array($data['date_range']) ? implode(' to ', $data['date_range']) : (string) $data['date_range']);
        }

        $lines[] = '';

        if (!empty($data['summary']) && is_array($data['summary'])) {
            $summary = $data['summary'];
            $lines[] = 'Summary';
            $lines[] = '- Total bookings: ' . $this->format_number_value($summary['total_bookings'] ?? 0);
            if (array_key_exists('estimated_revenue', $summary)) {
                $lines[] = '- Estimated revenue: ' . $this->format_currency_value($summary['estimated_revenue']);
            }
            if (array_key_exists('google_conversions', $summary)) {
                $lines[] = '- Google conversions: ' . $this->format_number_value($summary['google_conversions']);
            }
            if (array_key_exists('facebook_conversions', $summary)) {
                $lines[] = '- Facebook conversions: ' . $this->format_number_value($summary['facebook_conversions']);
            }
            if (array_key_exists('direct_conversions', $summary)) {
                $lines[] = '- Direct conversions: ' . $this->format_number_value($summary['direct_conversions']);
            }
            if (array_key_exists('avg_daily_bookings', $summary)) {
                $lines[] = '- Average daily bookings: ' . $this->format_number_value($summary['avg_daily_bookings'], 2);
            }
        }

        if (!empty($data['comparison']['growth'])) {
            $growth = $data['comparison']['growth'];
            $lines[] = '';
            $lines[] = 'Period comparison';
            $lines[] = '- Booking growth: ' . $this->format_percentage_ascii($growth['bookings'] ?? null, 2);
            $lines[] = '- Revenue growth: ' . $this->format_percentage_ascii($growth['revenue'] ?? null, 2);
        }

        if (!empty($data['by_source']) && is_array($data['by_source'])) {
            $lines[] = '';
            $lines[] = 'Top sources';
            foreach (array_slice($data['by_source'], 0, 5) as $row) {
                $source_label = isset($row['source']) ? (string) $row['source'] : 'n/a';
                $lines[] = sprintf(
                    '- %s: %s bookings, %s revenue',
                    $source_label,
                    $this->format_number_value($row['bookings'] ?? 0),
                    $this->format_currency_value($row['revenue'] ?? 0)
                );
            }
        }

        if ($report_type === 'daily' && !empty($data['by_hour']) && is_array($data['by_hour'])) {
            $lines[] = '';
            $lines[] = 'Hourly performance';
            foreach (array_slice($data['by_hour'], 0, 12) as $hour_data) {
                $hour = isset($hour_data['hour']) ? sprintf('%02d:00', (int) $hour_data['hour']) : '--:--';
                $top_source = $this->describe_share(
                    $hour_data['top_source_label'] ?? null,
                    isset($hour_data['top_source_share']) ? (float) $hour_data['top_source_share'] : null,
                    isset($hour_data['top_source_count']) ? (int) $hour_data['top_source_count'] : null
                );
                $top_campaign = $this->describe_share(
                    $hour_data['top_campaign_label'] ?? null,
                    isset($hour_data['top_campaign_share']) ? (float) $hour_data['top_campaign_share'] : null,
                    isset($hour_data['top_campaign_count']) ? (int) $hour_data['top_campaign_count'] : null
                );

                $lines[] = sprintf(
                    '- %s | bookings: %s | revenue: %s | top source: %s | top campaign: %s',
                    $hour,
                    $this->format_number_value($hour_data['bookings'] ?? 0),
                    $this->format_currency_value($hour_data['revenue'] ?? 0),
                    $top_source,
                    $top_campaign
                );
            }
        }

        if ($report_type === 'weekly' && !empty($data['daily_breakdown']) && is_array($data['daily_breakdown'])) {
            $lines[] = '';
            $lines[] = 'Daily breakdown';
            foreach (array_slice($data['daily_breakdown'], 0, 10) as $day) {
                $lines[] = sprintf(
                    '- %s (%s): %s bookings, %s revenue, growth %s',
                    $day['date'] ?? 'N/A',
                    $day['day_name'] ?? 'Day',
                    $this->format_number_value($day['bookings'] ?? 0),
                    $this->format_currency_value($day['revenue'] ?? 0),
                    $this->format_percentage_ascii($day['growth_percent'] ?? null)
                );
            }
        }

        if ($report_type === 'monthly' && !empty($data['weekly_breakdown']) && is_array($data['weekly_breakdown'])) {
            $lines[] = '';
            $lines[] = 'Weekly breakdown';
            foreach (array_slice($data['weekly_breakdown'], 0, 12) as $week) {
                $lines[] = sprintf(
                    '- %s: %s bookings, %s revenue, trend %s',
                    $week['week_label'] ?? 'Week',
                    $this->format_number_value($week['bookings'] ?? 0),
                    $this->format_currency_value($week['revenue'] ?? 0),
                    $this->format_trend_ascii($week['trend_percent'] ?? null)
                );
            }
        }

        if (!empty($data['by_campaign']) && is_array($data['by_campaign'])) {
            $lines[] = '';
            $lines[] = 'Top campaigns';
            foreach (array_slice($data['by_campaign'], 0, 5) as $campaign) {
                $lines[] = sprintf(
                    '- %s via %s: %s bookings, %s revenue',
                    $campaign['campaign'] ?? 'N/A',
                    $campaign['source'] ?? 'n/a',
                    $this->format_number_value($campaign['bookings'] ?? 0),
                    $this->format_currency_value($campaign['revenue'] ?? 0)
                );
            }
        }

        if (!empty($data['top_campaigns']) && is_array($data['top_campaigns'])) {
            $lines[] = '';
            $lines[] = 'Monthly top campaigns';
            foreach (array_slice($data['top_campaigns'], 0, 5) as $campaign) {
                $lines[] = sprintf(
                    '- %s: %s bookings, %s revenue',
                    $campaign['campaign'] ?? 'N/A',
                    $this->format_number_value($campaign['bookings'] ?? 0),
                    $this->format_currency_value($campaign['revenue'] ?? 0)
                );
            }
        }

        if (empty(array_filter($lines, static fn($line) => trim((string) $line) !== ''))) {
            $lines[] = 'No metrics available for the selected period.';
        }

        return $lines;
    }

    private function describe_share(?string $label, ?float $share, ?int $count): string
    {
        $clean_label = trim((string) $label);

        if ($clean_label === '') {
            $clean_label = 'n/a';
        }

        if ($share !== null) {
            return sprintf('%s (%s%%)', $clean_label, $this->format_number_value($share, 1));
        }

        if ($count !== null) {
            return sprintf('%s (%d)', $clean_label, (int) $count);
        }

        return $clean_label;
    }

    private function format_percentage_ascii(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return $this->format_number_value($value, $decimals) . '%';
    }

    private function format_trend_ascii(?float $percent): string
    {
        if ($percent === null) {
            return 'n/a';
        }

        if ($percent > 0.01) {
            return 'up ' . $this->format_number_value($percent, 2) . '%';
        }

        if ($percent < -0.01) {
            return 'down ' . $this->format_number_value(abs($percent), 2) . '%';
        }

        return 'flat 0.00%';
    }

    private function format_currency_value($value): string
    {
        return 'EUR ' . $this->format_number_value($value, 2);
    }

    private function format_number_value($value, int $decimals = 0): string
    {
        $numeric = is_numeric($value) ? (float) $value : 0.0;

        return number_format($numeric, $decimals, '.', ',');
    }

    /**
     * Render the prepared text lines as a minimal PDF document.
     *
     * @param array<int, string> $lines
     */
    private function render_pdf_document(array $lines): string
    {
        if (empty($lines)) {
            $lines = ['FP HIC Monitor report'];
        }

        $normalized_lines = [];
        foreach ($lines as $line) {
            $sanitized = $this->sanitize_pdf_line((string) $line);
            $normalized_lines[] = $sanitized === '' ? ' ' : $sanitized;
        }

        $content_lines = [
            'BT',
            '/F1 12 Tf',
            '40 760 Td',
        ];

        foreach ($normalized_lines as $index => $line) {
            if ($index > 0) {
                $content_lines[] = '0 -16 Td';
            }

            $content_lines[] = '(' . $this->escape_pdf_text($line) . ') Tj';
        }

        $content_lines[] = 'ET';

        $text_stream = implode("\n", $content_lines) . "\n";
        $stream_length = strlen($text_stream);

        $pdf = "%PDF-1.4\n";
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            "4 0 obj << /Length {$stream_length} >> stream\n{$text_stream}endstream\nendobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];

        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xref_position = strlen($pdf);
        $pdf .= 'xref' . "\n";
        $pdf .= '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }

        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n";
        $pdf .= $xref_position . "\n";
        $pdf .= '%%EOF';

        return $pdf;
    }

    private function sanitize_pdf_line(string $line): string
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

        return preg_replace('/[^\x20-\x7E]/', '', $trimmed) ?? '';
    }

    private function escape_pdf_text(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return $escaped;
    }

    /**
     * Normalise the attribution source label for reporting.
     */
    private function normalize_source_label(?string $source): string
    {
        $value = trim((string) $source);

        if ($value === '') {
            return 'Direct';
        }

        $lower = strtolower($value);

        if ($lower === 'google') {
            return 'Google';
        }

        if ($lower === 'facebook') {
            return 'Facebook';
        }

        $normalized = str_replace(['-', '_'], ' ', $lower);

        return ucwords($normalized);
    }

    /**
     * Normalise campaign labels, providing a fallback for missing data.
     */
    private function normalize_campaign_label(?string $campaign): string
    {
        $value = trim((string) $campaign);

        if ($value === '') {
            return 'N/A';
        }

        return $value;
    }

    /**
     * Format attribution details with percentage share or booking counts.
     */
    private function format_share_label(?string $label, ?float $share, ?int $count): string
    {
        if ($label === null || $label === '') {
            return '—';
        }

        if ($share !== null) {
            return sprintf('%s (%s%%)', $label, number_format($share, 1));
        }

        if ($count !== null) {
            return sprintf('%s (%d)', $label, $count);
        }

        return $label;
    }

    /**
     * Format percentage values with a unified fallback for missing data.
     */
    private function format_percentage(?float $value, int $decimals = 2): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, $decimals) . '%';
    }

    /**
     * Format trend information with directional arrows.
     */
    private function format_trend_label(?float $percent): string
    {
        if ($percent === null) {
            return '—';
        }

        $rounded = (float) $percent;

        if ($rounded > 0.01) {
            return sprintf('▲ +%s%%', number_format($rounded, 2));
        }

        if ($rounded < -0.01) {
            return sprintf('▼ -%s%%', number_format(abs($rounded), 2));
        }

        return '▬ 0.00%';
    }
    
    /**
     * Generate report HTML content
     */
    private function generate_report_html($data, $report_type) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>FP HIC Monitor - <?php echo ucfirst($report_type); ?> Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { border-bottom: 2px solid #0073aa; padding-bottom: 10px; margin-bottom: 20px; }
                .metric { display: inline-block; margin: 10px 20px 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
                .metric-value { font-size: 24px; font-weight: bold; color: #0073aa; }
                .metric-label { font-size: 12px; color: #666; text-transform: uppercase; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f9f9f9; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>FP HIC Monitor - <?php echo ucfirst($report_type); ?> Report</h1>
                <p>Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
                <p>Period: <?php echo $data['date_range']; ?></p>
            </div>
            
            <div class="summary">
                <h2>Summary Metrics</h2>
                <?php if (isset($data['summary'])): ?>
                    <div class="metric">
                        <div class="metric-value"><?php echo number_format($data['summary']['total_bookings']); ?></div>
                        <div class="metric-label">Total Bookings</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value">€<?php echo number_format($data['summary']['estimated_revenue']); ?></div>
                        <div class="metric-label">Estimated Revenue</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value"><?php echo number_format($data['summary']['google_conversions']); ?></div>
                        <div class="metric-label">Google Conversions</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value"><?php echo number_format($data['summary']['facebook_conversions']); ?></div>
                        <div class="metric-label">Facebook Conversions</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (isset($data['comparison'])): ?>
            <div class="comparison">
                <h2>Period Comparison</h2>
                <p>Bookings Growth: <strong><?php echo $data['comparison']['growth']['bookings']; ?>%</strong></p>
                <p>Revenue Growth: <strong><?php echo $data['comparison']['growth']['revenue']; ?>%</strong></p>
            </div>
            <?php endif; ?>
            
            <div class="footer">
                <p style="font-size: 12px; color: #666; margin-top: 40px;">
                    Report generated by FP HIC Monitor v3.0 | <a href="https://www.francopasseri.it">Franco Passeri</a>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get email subject
     */
    private function get_email_subject($report_type, $data) {
        $site_name = get_bloginfo('name');
        $period_label = ucfirst($report_type);
        $total_bookings = $data['summary']['total_bookings'] ?? 0;
        
        return sprintf(
            '%s - %s Report: %d bookings (%s)',
            $site_name,
            $period_label,
            $total_bookings,
            date('M j, Y')
        );
    }
    
    /**
     * Get email message
     */
    private function get_email_message($report_type, $data) {
        return $this->generate_report_html($data, $report_type);
    }
    
    /**
     * Create report record in database
     */
    private function create_report_record($type, $period) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_reports_history';
        
        $wpdb->insert($table_name, [
            'report_type' => $type,
            'report_period' => $period,
            'status' => 'generating'
        ]);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update report record
     */
    private function update_report_record($id, $status, $file_path = null, $metrics = null, $error = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_reports_history';
        
        $update_data = ['status' => $status];
        
        if ($file_path) {
            $update_data['file_path'] = $file_path;
            $update_data['file_size'] = file_exists($file_path) ? filesize($file_path) : 0;
        }
        
        if ($metrics) {
            $update_data['metrics_snapshot'] = json_encode($metrics);
        }
        
        if ($error) {
            $update_data['error_message'] = $error;
        }
        
        $wpdb->update($table_name, $update_data, ['id' => $id]);
    }
    
    /**
     * Cleanup old export files
     */
    public function cleanup_old_exports() {
        $files = glob($this->export_dir . '*');
        $cutoff_time = time() - (30 * 24 * 3600); // 30 days ago

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff_time) {
                unlink($file);
                $this->log('Cleaned up old export file: ' . basename($file));
            }
        }
    }

    public static function handle_daily_report(): void
    {
        self::run_report('daily');
    }

    public static function handle_weekly_report(): void
    {
        self::run_report('weekly');
    }

    public static function handle_monthly_report(): void
    {
        self::run_report('monthly');
    }

    public static function handle_cleanup_exports(): void
    {
        self::instance()->cleanup_old_exports();
    }

    private static function run_report(string $type): void
    {
        $instance = self::instance();
        $method = 'generate_' . $type . '_report';

        if (method_exists($instance, $method)) {
            $instance->{$method}();
        }
    }
    
    /**
     * Add reports admin menu
     */
    public function add_reports_menu() {
        add_submenu_page(
            'hic-monitoring',
            'Reports & Analytics',
            'Reports',
            'hic_manage',
            'hic-reports',
            [$this, 'render_reports_page']
        );
    }
    
    /**
     * Enqueue reporting assets
     */
    public function enqueue_reporting_assets($hook) {
        if (!$this->is_reports_hook($hook)) {
            return;
        }

        $base_url = plugin_dir_url(dirname(__DIR__) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php');

        wp_enqueue_style(
            'hic-admin-base',
            $base_url . 'assets/css/hic-admin.css',
            [],
            HIC_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'hic-reporting',
            $base_url . 'assets/js/reporting.js',
            ['jquery'],
            HIC_PLUGIN_VERSION,
            true
        );

        wp_localize_script('hic-reporting', 'hicReporting', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'hic_reporting_nonce' => wp_create_nonce('hic_reporting_nonce')
        ]);
    }

    private function is_reports_hook($hook): bool
    {
        if (!is_string($hook)) {
            return false;
        }

        if (strpos($hook, '_page_hic-reports') !== false) {
            return true;
        }

        return strpos($hook, 'hic-reports') !== false;
    }
    
    /**
     * Render reports admin page
     */
    public function render_reports_page() {
        global $wpdb;

        $report_stats = [
            'total' => 0,
            'completed' => 0,
            'failed' => 0,
            'last_completed' => null,
        ];

        if ($wpdb instanceof \wpdb) {
            $table_name = $wpdb->prefix . 'hic_reports_history';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;

            if ($table_exists) {
                $stats = $wpdb->get_row(
                    "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN status IN ('completed', 'sent') THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                        MAX(CASE WHEN status IN ('completed', 'sent') THEN generated_at ELSE NULL END) AS last_completed
                    FROM {$table_name}",
                    ARRAY_A
                );

                if (is_array($stats)) {
                    $report_stats['total'] = isset($stats['total']) ? (int) $stats['total'] : 0;
                    $report_stats['completed'] = isset($stats['completed']) ? (int) $stats['completed'] : 0;
                    $report_stats['failed'] = isset($stats['failed']) ? (int) $stats['failed'] : 0;
                    $report_stats['last_completed'] = $stats['last_completed'] ?? null;
                }
            }
        }

        $reporting_settings = get_option('hic_reporting_settings', []);
        $automation_enabled = 0;
        foreach (['daily', 'weekly', 'monthly'] as $type) {
            if (!empty($reporting_settings[$type . '_enabled'])) {
                $automation_enabled++;
            }
        }

        $last_completed_label = __('—', 'hotel-in-cloud');
        if (!empty($report_stats['last_completed'])) {
            $timestamp = strtotime($report_stats['last_completed']);
            if ($timestamp) {
                $last_completed_label = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            }
        }

        ?>
        <div class="wrap hic-admin-page hic-reports-page">
            <div class="hic-page-hero">
                <div class="hic-page-header">
                    <div class="hic-page-header__content">
                        <h1 class="hic-page-header__title">📈 <?php esc_html_e('FP HIC Monitor - Reports &amp; Analytics', 'hotel-in-cloud'); ?></h1>
                        <p class="hic-page-header__subtitle"><?php esc_html_e('Genera report personalizzati e consulta lo storico con la stessa UI della dashboard di monitoraggio.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-page-actions">
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url(admin_url('admin.php?page=hic-monitoring')); ?>">
                            <span class="dashicons dashicons-chart-line"></span>
                            <?php esc_html_e('Dashboard live', 'hotel-in-cloud'); ?>
                        </a>
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url('https://support.francopasseri.it/hic-reports'); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-portfolio"></span>
                            <?php esc_html_e('Guida reportistica', 'hotel-in-cloud'); ?>
                        </a>
                    </div>
                </div>

                <div class="hic-page-meta">
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-active"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Report generati', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><?php echo esc_html(number_format_i18n($report_stats['total'])); ?></p>
                            <p class="hic-page-meta__description"><?php esc_html_e('Totale esportazioni archiviate.', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-active"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Ultimo completato', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><?php echo esc_html($last_completed_label); ?></p>
                            <p class="hic-page-meta__description"><?php esc_html_e('Data di generazione più recente.', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status <?php echo $report_stats['failed'] > 0 ? 'is-warning' : 'is-active'; ?>"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Errori recenti', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><?php echo esc_html(number_format_i18n($report_stats['failed'])); ?></p>
                            <p class="hic-page-meta__description"><?php esc_html_e('Tentativi falliti nelle ultime generazioni.', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-active"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php esc_html_e('Automazioni attive', 'hotel-in-cloud'); ?></p>
                            <p class="hic-page-meta__value"><?php echo esc_html($automation_enabled . '/3'); ?></p>
                            <p class="hic-page-meta__description"><?php esc_html_e('Programmazioni pianificate (daily/weekly/monthly).', 'hotel-in-cloud'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hic-grid hic-grid--two hic-reports-dashboard">
                <!-- Manual Report Generation -->
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title">Generate Manual Report</h2>
                            <p class="hic-card__subtitle">Crea un'esportazione ad-hoc selezionando intervallo e formati.</p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <form id="hic-manual-report-form" class="hic-form" novalidate>
                            <div class="hic-field-grid">
                                <div class="hic-field-row">
                                    <label class="hic-field-label" for="hic-report-type">Report Type</label>
                                    <div class="hic-field-control">
                                        <select id="hic-report-type" name="report_type">
                                            <option value="daily">Daily Report</option>
                                            <option value="weekly">Weekly Report</option>
                                            <option value="monthly">Monthly Report</option>
                                            <option value="custom">Custom Period</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="hic-field-row">
                                    <div class="hic-field-label">Export Format</div>
                                    <div class="hic-field-control">
                                        <label class="hic-toggle">
                                            <input type="checkbox" name="formats[]" value="csv" checked>
                                            <span>CSV</span>
                                        </label>
                                        <label class="hic-toggle">
                                            <input type="checkbox" name="formats[]" value="excel">
                                            <span>Excel</span>
                                        </label>
                                        <label class="hic-toggle">
                                            <input type="checkbox" name="formats[]" value="pdf">
                                            <span>PDF</span>
                                        </label>
                                        <p class="description">Puoi selezionare uno o più formati per l'esportazione.</p>
                                    </div>
                                </div>

                                <div class="hic-field-row">
                                    <div class="hic-field-label">Email Report</div>
                                    <div class="hic-field-control">
                                        <label class="hic-toggle">
                                            <input type="checkbox" name="send_email">
                                            <span>Send via email</span>
                                        </label>
                                        <p class="description">L'invio utilizza l'indirizzo di amministrazione configurato nelle impostazioni.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="hic-form-actions">
                                <button type="submit" class="button hic-button hic-button--primary">Generate Report</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Report History -->
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title">Report History</h2>
                            <p class="hic-card__subtitle">Consultazione rapida dei report generati recentemente.</p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <div id="hic-report-history">Loading...</div>
                    </div>
                </div>

                <!-- Quick Export -->
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title">Quick Data Export</h2>
                            <p class="hic-card__subtitle">Scarica velocemente dataset predefiniti per analisi esterne.</p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <p>Export raw data for external analysis:</p>
                        <div class="hic-form-actions">
                            <button type="button" class="button hic-button hic-button--secondary" onclick="hicExportCSV('last_7_days')">Export Last 7 Days (CSV)</button>
                            <button type="button" class="button hic-button hic-button--secondary" onclick="hicExportCSV('last_30_days')">Export Last 30 Days (CSV)</button>
                            <button type="button" class="button hic-button hic-button--secondary" onclick="hicExportExcel('last_7_days')">Export Last 7 Days (Excel)</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Generate manual report
     */
    public function ajax_generate_manual_report() {
        if (!check_ajax_referer('hic_reporting_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        hic_require_cap('hic_manage');
        
        $allowed_report_types = array_keys(self::REPORT_TYPES);
        $default_report_type = 'weekly';

        $report_type = $default_report_type;
        if (isset($_POST['report_type'])) {
            $raw_report_type = wp_unslash($_POST['report_type']);
            if (!is_string($raw_report_type)) {
                wp_send_json_error('Invalid report type');
            }

            $submitted_report_type = sanitize_text_field($raw_report_type);
            if ($submitted_report_type === '') {
                $submitted_report_type = $default_report_type;
            }

            if (!in_array($submitted_report_type, $allowed_report_types, true)) {
                wp_send_json_error('Invalid report type');
            }

            $report_type = $submitted_report_type;
        }

        $collector_method = 'collect_' . $report_type . '_data';
        if (!method_exists($this, $collector_method)) {
            wp_send_json_error('Report type handler not available');
        }

        $formats = array_map('sanitize_text_field', (array) wp_unslash($_POST['formats'] ?? ['csv']));
        $send_email = !empty($_POST['send_email']);

        try {
            $report_data = $this->{$collector_method}();
            $report_id = $this->create_report_record($report_type, 'manual');
            
            $files = [];
            foreach ($formats as $format) {
                switch ($format) {
                    case 'csv':
                        $files[] = $this->generate_csv_report($report_data, $report_type);
                        break;
                    case 'excel':
                        $files[] = $this->generate_excel_report($report_data, $report_type);
                        break;
                    case 'pdf':
                        $files[] = $this->generate_pdf_report($report_data, $report_type);
                        break;
                }
            }
            
            if ($send_email) {
                $this->send_email_report($report_type, $report_data, $files);
            }
            
            $this->update_report_record($report_id, 'completed', $files[0] ?? null, $report_data);
            
            wp_send_json_success([
                'message' => 'Report generated successfully',
                'files' => array_map('basename', $files)
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('Failed to generate report: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Export data as CSV
     */
    public function ajax_export_data_csv() {
        if (!check_ajax_referer('hic_reporting_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        hic_require_cap('hic_manage');

        $period = sanitize_text_field($_POST['period'] ?? 'last_7_days');
        
        try {
            $data = $this->get_raw_data_for_period($period);
            $file = $this->generate_raw_csv_export($data, $period);
            
            wp_send_json_success([
                'download_url' => $this->get_secure_download_url($file),
                'filename' => basename($file)
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('Export failed: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Export data as Excel
     */
    public function ajax_export_data_excel() {
        if (!check_ajax_referer('hic_reporting_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        hic_require_cap('hic_manage');

        $period = sanitize_text_field($_POST['period'] ?? 'last_7_days');

        try {
            $data = $this->get_raw_data_for_period($period);
            $file = $this->generate_raw_excel_export($data, $period);

            wp_send_json_success([
                'download_url' => $this->get_secure_download_url($file),
                'filename' => basename($file)
            ]);

        } catch (\Exception $e) {
            wp_send_json_error('Export failed: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Schedule report generation
     */
    public function ajax_schedule_report() {
        if (!check_ajax_referer('hic_reporting_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        hic_require_cap('hic_manage');

        $report_type = sanitize_text_field($_POST['report_type'] ?? '');

        if (empty(self::REPORT_TYPES[$report_type])) {
            wp_send_json_error('Invalid report type');
        }

        $hook = self::REPORT_TYPES[$report_type]['hook'];
        wp_schedule_single_event(time(), $hook);

        wp_send_json_success(['message' => 'Report scheduled']);
    }

    /**
     * AJAX: Get report history
     */
    public function ajax_get_report_history() {
        if (!check_ajax_referer('hic_reporting_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        hic_require_cap('hic_manage');

        global $wpdb;
        $table = $wpdb->prefix . 'hic_reports_history';

        $history = $wpdb->get_results("SELECT id, report_type, report_period, generated_at, status, file_path, file_size FROM {$table} ORDER BY generated_at DESC LIMIT 50", ARRAY_A);

        wp_send_json_success($history);
    }

    /**
     * Handle secure export downloads
     */
    public function handle_download_export() {
        $filename = sanitize_file_name($_GET['file'] ?? '');

        if ($filename === '') {
            wp_die('Invalid file');
        }

        if (!check_ajax_referer('hic_download_' . $filename, 'nonce', false)) {
            wp_die('Invalid nonce');
        }

        hic_require_cap('hic_manage');

        $allowed_extensions = array('csv', 'xlsx');
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension === '' || !in_array($extension, $allowed_extensions, true)) {
            $this->log('Blocked export download due to invalid extension: ' . $filename);
            wp_die('File not found');
        }

        $base_path = realpath($this->export_dir);

        if ($base_path === false) {
            $this->log('Export directory missing or inaccessible: ' . $this->export_dir);
            wp_die('File not found');
        }

        $raw_filepath = $this->export_dir . $filename;

        if (is_link($raw_filepath)) {
            $this->log('Blocked export download for symbolic link: ' . $raw_filepath);
            wp_die('File not found');
        }

        $filepath = realpath($raw_filepath);

        if ($filepath === false) {
            $this->log('Requested export file could not be resolved: ' . $filename);
            wp_die('File not found');
        }

        $base_prefix = rtrim($base_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (strpos($filepath, $base_prefix) !== 0 || !is_file($filepath)) {
            $this->log('Blocked export download outside export directory: ' . $filepath);
            wp_die('File not found');
        }

        nocache_headers();
        header('X-Content-Type-Options: nosniff');

        if ($extension === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
        } else {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
    
    /**
     * Get raw data for period
     */
    private function get_raw_data_for_period($period) {
        global $wpdb;
        
        $table_name = \esc_sql($wpdb->prefix . 'hic_booking_metrics');
        $date_condition = $this->get_date_condition_for_period($period);

        $sql = "
            SELECT
                reservation_id,
                sid,
                channel,
                utm_source,
                utm_medium,
                utm_campaign,
                utm_content,
                utm_term,
                amount,
                currency,
                is_refund,
                status,
                created_at,
                updated_at
            FROM `{$table_name}`
            WHERE {$date_condition}
            ORDER BY created_at DESC
        ";

        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Get date condition for period
     */
    private function get_date_condition_for_period($period) {
        switch ($period) {
            case 'last_7_days':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case 'last_30_days':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            case 'today':
                return "DATE(created_at) = CURDATE()";
            case 'yesterday':
                return "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            default:
                return "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        }
    }
    
    /**
     * Generate raw CSV export
     */
    private function generate_raw_csv_export($data, $period) {
        $filename = sprintf('hic-raw-export-%s-%s.csv', $period, date('Y-m-d-H-i-s'));
        $filepath = $this->export_dir . $filename;
        
        $file = @fopen($filepath, 'w');

        if ($file === false) {
            $this->log('Failed to open export file for writing: ' . $filepath);
            throw new \RuntimeException('Unable to open export file for writing. Please verify the export directory is writable.');
        }

        try {
            if (!empty($data)) {
                fputcsv($file, array_keys($data[0]));

                foreach ($data as $row) {
                    fputcsv($file, $row);
                }
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }

        return $filepath;
    }

    /**
     * Generate raw Excel export
     */
    private function generate_raw_excel_export($data, $period) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return $this->generate_raw_csv_export($data, $period);
        }

        $filename = sprintf('hic-raw-export-%s-%s.xlsx', $period, date('Y-m-d-H-i-s'));
        $filepath = $this->export_dir . $filename;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        if (!empty($data)) {
            $sheet->fromArray(array_keys($data[0]), null, 'A1');
            $sheet->fromArray($data, null, 'A2');
        }

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filepath);

        return $filepath;
    }

    /**
     * Get secure download URL
     */
    private function get_secure_download_url($filepath) {
        $filename = basename($filepath);
        return admin_url("admin-ajax.php?action=hic_download_export&file=" . urlencode($filename) . "&nonce=" . wp_create_nonce('hic_download_' . $filename));
    }
    
    /**
     * Log messages with reporting prefix
     */
    private function log($message) {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log("[Automated Reporting] {$message}");
        }
    }
}

AutomatedReportingManager::register_cron_hooks();
