<?php declare(strict_types=1);

namespace FpHic\PerformanceAnalytics;

if (!defined('ABSPATH')) {
    exit;
}

final class PerformanceDashboard
{
    private const PAGE_SLUG = 'hic-performance-monitor';
    private const CAPABILITY = 'hic_manage';

    public function __construct()
    {
        \add_action('admin_menu', [$this, 'register_menu']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        \add_submenu_page(
            'hic-monitoring',
            \__('Monitoraggio Performance', 'hotel-in-cloud'),
            \__('Performance', 'hotel-in-cloud'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'hic-monitoring_page_' . self::PAGE_SLUG) {
            return;
        }

        \wp_enqueue_script('jquery');

        $base_url = \plugin_dir_url(dirname(__DIR__) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php');

        if (!\wp_script_is('chart-js', 'registered')) {
            \wp_register_script(
                'chart-js',
                $base_url . 'assets/vendor/chartjs/chart.min.js',
                [],
                '3.9.1',
                true
            );
        }

        \wp_enqueue_script('chart-js');

        \wp_enqueue_style(
            'hic-admin-base',
            $base_url . 'assets/css/hic-admin.css',
            [],
            HIC_PLUGIN_VERSION
        );

        \wp_enqueue_style(
            'hic-performance-dashboard',
            $base_url . 'assets/css/performance-dashboard.css',
            ['hic-admin-base'],
            HIC_PLUGIN_VERSION
        );
        // Utilities CSS (additivo)
        \wp_enqueue_style(
            'hic-utilities',
            $base_url . 'assets/css/hic-utilities.css',
            ['hic-admin-base'],
            HIC_PLUGIN_VERSION
        );

        \wp_enqueue_script(
            'hic-performance-dashboard',
            $base_url . 'assets/js/performance-dashboard.js',
            ['jquery', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );

        // Moduli additivi per dashboard performance
        \wp_enqueue_script(
            'hic-performance-fetch',
            $base_url . 'assets/js/performance-dashboard/modules/fetch.js',
            ['jquery', 'hic-performance-dashboard'],
            HIC_PLUGIN_VERSION,
            true
        );
        \wp_enqueue_script(
            'hic-performance-formatting',
            $base_url . 'assets/js/performance-dashboard/modules/formatting.js',
            ['hic-performance-dashboard'],
            HIC_PLUGIN_VERSION,
            true
        );
        \wp_enqueue_script(
            'hic-performance-charts',
            $base_url . 'assets/js/performance-dashboard/modules/charts.js',
            ['hic-performance-dashboard', 'chart-js'],
            HIC_PLUGIN_VERSION,
            true
        );

        \wp_localize_script('hic-performance-dashboard', 'hicPerformanceDashboard', [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'monitorNonce' => \wp_create_nonce('hic_monitor_nonce'),
            'i18n' => [
                'allOperations' => \__('Tutte le operazioni', 'hotel-in-cloud'),
                'loading' => \__('Caricamento dati in corso...', 'hotel-in-cloud'),
                'noData' => \__('Nessun dato disponibile per il periodo selezionato.', 'hotel-in-cloud'),
                'dateRange' => \__('Periodo analizzato: %1$s → %2$s', 'hotel-in-cloud'),
                'totalOperations' => \__('Operazioni monitorate', 'hotel-in-cloud'),
                'avgDuration' => \__('Durata media (s)', 'hotel-in-cloud'),
                'successRate' => \__('Tasso di successo', 'hotel-in-cloud'),
                'p95Duration' => \__('Durata p95 (s)', 'hotel-in-cloud'),
                'trendSummary' => \__('Volume: %1$s · Durata: %2$s · Successo: %3$s', 'hotel-in-cloud'),
                'trendIncrease' => \__('Trend in crescita', 'hotel-in-cloud'),
                'trendDecrease' => \__('Trend in calo', 'hotel-in-cloud'),
                'trendStable' => \__('Trend stabile', 'hotel-in-cloud'),
            ],
        ]);
    }

    public function render_page(): void
    {
        if (!\current_user_can(self::CAPABILITY)) {
            \wp_die(\__('Non hai i permessi per accedere a questa pagina.', 'hotel-in-cloud'));
        }
        ?>
        <div class="wrap hic-performance-dashboard">
            <h1><?php \esc_html_e('Monitoraggio delle Performance', 'hotel-in-cloud'); ?></h1>
            <p class="hic-performance-dashboard__intro">
                <?php \esc_html_e('Analizza le prestazioni delle integrazioni HIC nel tempo con metriche aggregate, trend e livelli di servizio.', 'hotel-in-cloud'); ?>
            </p>

            <div class="hic-performance-dashboard__filters">
                <label for="hic-performance-range" class="screen-reader-text"><?php \esc_html_e('Intervallo temporale', 'hotel-in-cloud'); ?></label>
                <select id="hic-performance-range">
                    <option value="7"><?php \esc_html_e('Ultimi 7 giorni', 'hotel-in-cloud'); ?></option>
                    <option value="14"><?php \esc_html_e('Ultimi 14 giorni', 'hotel-in-cloud'); ?></option>
                    <option value="30" selected><?php \esc_html_e('Ultimi 30 giorni', 'hotel-in-cloud'); ?></option>
                    <option value="60"><?php \esc_html_e('Ultimi 60 giorni', 'hotel-in-cloud'); ?></option>
                    <option value="90"><?php \esc_html_e('Ultimi 90 giorni', 'hotel-in-cloud'); ?></option>
                </select>

                <label for="hic-performance-operation" class="screen-reader-text"><?php \esc_html_e('Filtra per operazione', 'hotel-in-cloud'); ?></label>
                <select id="hic-performance-operation">
                    <option value="all"><?php \esc_html_e('Tutte le operazioni', 'hotel-in-cloud'); ?></option>
                </select>

                <span class="hic-performance-dashboard__range" data-role="range-label"></span>
            </div>

            <div class="hic-performance-dashboard__summary">
                <div class="hic-performance-card" data-summary="total">
                    <span class="hic-performance-card__label"><?php \esc_html_e('Operazioni monitorate', 'hotel-in-cloud'); ?></span>
                    <span class="hic-performance-card__value">—</span>
                </div>
                <div class="hic-performance-card" data-summary="avg">
                    <span class="hic-performance-card__label"><?php \esc_html_e('Durata media (s)', 'hotel-in-cloud'); ?></span>
                    <span class="hic-performance-card__value">—</span>
                </div>
                <div class="hic-performance-card" data-summary="success">
                    <span class="hic-performance-card__label"><?php \esc_html_e('Tasso di successo', 'hotel-in-cloud'); ?></span>
                    <span class="hic-performance-card__value">—</span>
                </div>
                <div class="hic-performance-card" data-summary="p95">
                    <span class="hic-performance-card__label"><?php \esc_html_e('Durata p95 (s)', 'hotel-in-cloud'); ?></span>
                    <span class="hic-performance-card__value">—</span>
                </div>
            </div>

            <div class="hic-performance-dashboard__content">
                <div class="hic-performance-panel">
                    <h2><?php \esc_html_e('Performance per operazione', 'hotel-in-cloud'); ?></h2>
                    <canvas id="hic-performance-operations" height="320"></canvas>
                </div>
                <div class="hic-performance-panel">
                    <h2><?php \esc_html_e('Tasso di successo', 'hotel-in-cloud'); ?></h2>
                    <canvas id="hic-performance-success" height="320"></canvas>
                </div>
                <div class="hic-performance-panel hic-performance-panel--wide">
                    <h2><?php \esc_html_e('Trend giornaliero', 'hotel-in-cloud'); ?></h2>
                    <canvas id="hic-performance-trend" height="360"></canvas>
                </div>
            </div>

            <div class="hic-performance-dashboard__table">
                <h2><?php \esc_html_e('Dettaglio operazioni', 'hotel-in-cloud'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php \esc_html_e('Operazione', 'hotel-in-cloud'); ?></th>
                            <th><?php \esc_html_e('Esecuzioni', 'hotel-in-cloud'); ?></th>
                            <th><?php \esc_html_e('Durata media (s)', 'hotel-in-cloud'); ?></th>
                            <th><?php \esc_html_e('Durata p95 (s)', 'hotel-in-cloud'); ?></th>
                            <th><?php \esc_html_e('Successo', 'hotel-in-cloud'); ?></th>
                            <th><?php \esc_html_e('Trend', 'hotel-in-cloud'); ?></th>
                        </tr>
                    </thead>
                    <tbody data-role="operations-body">
                        <tr>
                            <td colspan="6"><?php \esc_html_e('Caricamento dati in corso...', 'hotel-in-cloud'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}

new PerformanceDashboard();
