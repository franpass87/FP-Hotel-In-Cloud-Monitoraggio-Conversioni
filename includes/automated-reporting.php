<?php declare(strict_types=1);

namespace FpHic\AutomatedReporting;

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

    public function __construct() {
        if (null === self::$instance) {
            self::$instance = $this;
        }

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
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $today = current_time('Y-m-d');
        
        $data = [
            'period' => 'daily',
            'date_range' => $today,
            'summary' => [],
            'by_hour' => [],
            'by_source' => [],
            'by_medium' => [],
            'conversions' => []
        ];
        
        // Summary statistics
        $data['summary'] = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_bookings,
                COUNT(DISTINCT gclid) as google_conversions,
                COUNT(DISTINCT fbclid) as facebook_conversions,
                COUNT(DISTINCT CASE WHEN utm_source = '' OR utm_source IS NULL THEN sid END) as direct_conversions,
                COUNT(*) * 150 as estimated_revenue
            FROM {$main_table} 
            WHERE DATE(created_at) = %s
        ", $today), ARRAY_A);
        
        // Hourly breakdown
        $data['by_hour'] = $wpdb->get_results($wpdb->prepare("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as bookings,
                COUNT(*) * 150 as revenue
            FROM {$main_table} 
            WHERE DATE(created_at) = %s
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ", $today), ARRAY_A);
        
        // By source
        $data['by_source'] = $wpdb->get_results($wpdb->prepare("
            SELECT 
                CASE 
                    WHEN utm_source = 'google' THEN 'Google'
                    WHEN utm_source = 'facebook' THEN 'Facebook'
                    WHEN utm_source = '' OR utm_source IS NULL THEN 'Direct'
                    ELSE CONCAT(UPPER(SUBSTRING(utm_source, 1, 1)), SUBSTRING(utm_source, 2))
                END as source,
                COUNT(*) as bookings,
                COUNT(*) * 150 as revenue
            FROM {$main_table} 
            WHERE DATE(created_at) = %s
            GROUP BY source
            ORDER BY bookings DESC
        ", $today), ARRAY_A);
        
        return $data;
    }
    
    /**
     * Collect weekly data for reporting
     */
    private function collect_weekly_data() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';

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
        
        // Weekly summary
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
                    COUNT(*) as total_bookings,
                    COUNT(DISTINCT gclid) as google_conversions,
                    COUNT(DISTINCT fbclid) as facebook_conversions,
                    COUNT(DISTINCT CASE WHEN utm_source = '' OR utm_source IS NULL THEN sid END) as direct_conversions,
                    COUNT(*) * 150 as estimated_revenue
                FROM {$main_table}
                WHERE created_at >= %s
            ) as totals
            CROSS JOIN (
                SELECT AVG(day_bookings) AS avg_bookings
                FROM (
                    SELECT COUNT(*) AS day_bookings
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
        
        // Daily breakdown for the week
        $data['daily_breakdown'] = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    DATE(created_at) as date,
                    DAYNAME(created_at) as day_name,
                    COUNT(*) as bookings,
                    COUNT(*) * 150 as revenue
                FROM {$main_table}
                WHERE created_at >= %s
                GROUP BY DATE(created_at)
                ORDER BY date
            ",
                $weekly_start
            ),
            ARRAY_A
        );
        
        // Top performing campaigns
        $data['by_campaign'] = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    utm_campaign as campaign,
                    utm_source as source,
                    COUNT(*) as bookings,
                    COUNT(*) * 150 as revenue,
                    CASE
                        WHEN totals.total_bookings > 0 THEN ROUND((COUNT(*) * 100.0) / totals.total_bookings, 2)
                        ELSE 0
                    END as percentage
                FROM {$main_table}
                CROSS JOIN (
                    SELECT COUNT(*) as total_bookings
                    FROM {$main_table}
                    WHERE created_at >= %s
                    AND utm_campaign IS NOT NULL AND utm_campaign != ''
                ) as totals
                WHERE created_at >= %s
                AND utm_campaign IS NOT NULL AND utm_campaign != ''
                GROUP BY utm_campaign, utm_source
                ORDER BY bookings DESC
                LIMIT 10
            ",
                $weekly_start,
                $weekly_start
            ),
            ARRAY_A
        );
        
        return $data;
    }
    
    /**
     * Collect monthly data for reporting
     */
    private function collect_monthly_data() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';

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
        
        // Monthly summary with growth rates
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
                    COUNT(*) as total_bookings,
                    COUNT(DISTINCT gclid) as google_conversions,
                    COUNT(DISTINCT fbclid) as facebook_conversions,
                    COUNT(*) * 150 as estimated_revenue
                FROM {$main_table}
                WHERE created_at >= %s
            ) as totals
            CROSS JOIN (
                SELECT AVG(day_bookings) AS avg_bookings
                FROM (
                    SELECT COUNT(*) AS day_bookings
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
        
        // Weekly breakdown for trend analysis
        $data['weekly_breakdown'] = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    WEEK(created_at, 1) as week_number,
                    CONCAT('Week ', WEEK(created_at, 1)) as week_label,
                    COUNT(*) as bookings,
                    COUNT(*) * 150 as revenue
                FROM {$main_table}
                WHERE created_at >= %s
                GROUP BY WEEK(created_at, 1)
                ORDER BY week_number
            ",
                $monthly_start
            ),
            ARRAY_A
        );
        
        return $data;
    }
    
    /**
     * Get period comparison data
     */
    private function get_period_comparison($period_type) {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $intervals = [
            'daily' => ['current' => 'CURDATE()', 'previous' => 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)'],
            'weekly' => ['current' => 'INTERVAL 7 DAY', 'previous' => 'INTERVAL 14 DAY'],
            'monthly' => ['current' => 'INTERVAL 30 DAY', 'previous' => 'INTERVAL 60 DAY']
        ];
        
        $interval = $intervals[$period_type] ?? $intervals['weekly'];
        
        // Get current period data
        $current = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as bookings,
                COUNT(*) * 150 as revenue
            FROM {$main_table} 
            WHERE created_at >= DATE_SUB(NOW(), %s)
        ", $interval['current']), ARRAY_A);
        
        // Get previous period data
        $previous = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as bookings,
                COUNT(*) * 150 as revenue
            FROM {$main_table} 
            WHERE created_at >= DATE_SUB(NOW(), %s)
            AND created_at < DATE_SUB(NOW(), %s)
        ", $interval['previous'], $interval['current']), ARRAY_A);
        
        // Calculate growth rates
        $booking_growth = $previous['bookings'] > 0 ? 
            round((($current['bookings'] - $previous['bookings']) / $previous['bookings']) * 100, 2) : 0;
            
        $revenue_growth = $previous['revenue'] > 0 ? 
            round((($current['revenue'] - $previous['revenue']) / $previous['revenue']) * 100, 2) : 0;
        
        return [
            'current' => $current,
            'previous' => $previous,
            'growth' => [
                'bookings' => $booking_growth,
                'revenue' => $revenue_growth
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
        
        // Generate HTML content
        $html_content = $this->generate_report_html($data, $report_type);
        
        // Convert to PDF (would use library like mPDF or TCPDF)
        // For now, save as HTML
        file_put_contents(str_replace('.pdf', '.html', $filepath), $html_content);
        
        return str_replace('.pdf', '.html', $filepath);
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
                            '', // Source placeholder
                            '' // Campaign placeholder
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
                            '' // Growth placeholder
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
                            '' // Trend placeholder
                        ];
                    }
                }

                break;
        }

        return [$headers, $rows];
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
            'manage_options',
            'hic-reports',
            [$this, 'render_reports_page']
        );
    }
    
    /**
     * Enqueue reporting assets
     */
    public function enqueue_reporting_assets($hook) {
        if ($hook !== 'hic-monitoring_page_hic-reports') {
            return;
        }
        
        wp_enqueue_script(
            'hic-reporting',
            plugin_dir_url(dirname(__DIR__) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php') . 'assets/js/reporting.js',
            ['jquery'],
            '3.1.0',
            true
        );
        
        wp_localize_script('hic-reporting', 'hicReporting', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'hic_reporting_nonce' => wp_create_nonce('hic_reporting_nonce')
        ]);
    }
    
    /**
     * Render reports admin page
     */
    public function render_reports_page() {
        ?>
        <div class="wrap">
            <h1>FP HIC Monitor - Reports & Analytics</h1>
            
            <div class="hic-reports-dashboard">
                <!-- Manual Report Generation -->
                <div class="postbox">
                    <h2>Generate Manual Report</h2>
                    <div class="inside">
                        <form id="hic-manual-report-form">
                            <table class="form-table">
                                <tr>
                                    <th>Report Type</th>
                                    <td>
                                        <select name="report_type">
                                            <option value="daily">Daily Report</option>
                                            <option value="weekly">Weekly Report</option>
                                            <option value="monthly">Monthly Report</option>
                                            <option value="custom">Custom Period</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Export Format</th>
                                    <td>
                                        <label><input type="checkbox" name="formats[]" value="csv" checked> CSV</label><br>
                                        <label><input type="checkbox" name="formats[]" value="excel"> Excel</label><br>
                                        <label><input type="checkbox" name="formats[]" value="pdf"> PDF</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Email Report</th>
                                    <td>
                                        <label><input type="checkbox" name="send_email"> Send via email</label>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <button type="submit" class="button button-primary">Generate Report</button>
                            </p>
                        </form>
                    </div>
                </div>
                
                <!-- Report History -->
                <div class="postbox">
                    <h2>Report History</h2>
                    <div class="inside">
                        <div id="hic-report-history">Loading...</div>
                    </div>
                </div>
                
                <!-- Quick Export -->
                <div class="postbox">
                    <h2>Quick Data Export</h2>
                    <div class="inside">
                        <p>Export raw data for external analysis:</p>
                        <button type="button" class="button" onclick="hicExportCSV('last_7_days')">Export Last 7 Days (CSV)</button>
                        <button type="button" class="button" onclick="hicExportCSV('last_30_days')">Export Last 30 Days (CSV)</button>
                        <button type="button" class="button" onclick="hicExportExcel('last_7_days')">Export Last 7 Days (Excel)</button>
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

        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }
        
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

        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

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

        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

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

        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

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

        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

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

        if (empty($filename)) {
            wp_die('Invalid file');
        }

        if (!check_ajax_referer('hic_download_' . $filename, 'nonce', false)) {
            wp_die('Invalid nonce');
        }

        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

        $base_path = realpath($this->export_dir);

        if ($base_path === false) {
            $this->log('Export directory missing or inaccessible: ' . $this->export_dir);
            wp_die('File not found');
        }

        $filepath = realpath($this->export_dir . $filename);

        if ($filepath === false) {
            $this->log('Requested export file could not be resolved: ' . $filename);
            wp_die('File not found');
        }

        $base_prefix = rtrim($base_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (strpos($filepath, $base_prefix) !== 0 || !file_exists($filepath)) {
            $this->log('Blocked export download outside export directory: ' . $filepath);
            wp_die('File not found');
        }

        header('Content-Type: application/octet-stream');
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
        
        $table_name = \esc_sql($wpdb->prefix . 'hic_gclids');
        $date_condition = $this->get_date_condition_for_period($period);

        $sql = "
            SELECT
                id,
                gclid,
                fbclid,
                msclkid,
                ttclid,
                gbraid,
                wbraid,
                sid,
                utm_source,
                utm_medium,
                utm_campaign,
                utm_content,
                utm_term,
                created_at
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
