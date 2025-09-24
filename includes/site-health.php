<?php declare(strict_types=1);
namespace FpHic;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Site Health tests for the plugin.
 */
function hic_register_site_health_tests(array $tests): array
{
    $tests['direct']['hic_ga4_config'] = [
        'label' => __('Configurazione GA4', 'hotel-in-cloud'),
        'test'  => __NAMESPACE__ . '\\hic_site_health_ga4_config',
    ];

    $tests['async']['hic_webhook_ping'] = [
        'label' => __('Ping Webhook', 'hotel-in-cloud'),
        'test'  => __NAMESPACE__ . '\\hic_site_health_webhook_ping',
    ];

    return $tests;
}

/**
 * Direct test: verify GA4 configuration.
 */
function hic_site_health_ga4_config(): array
{
    $configured = !empty(Helpers\hic_get_measurement_id()) && !empty(Helpers\hic_get_api_secret());

    if ($configured) {
        $status = 'good';
        $message = __('La configurazione GA4 è completa.', 'hotel-in-cloud');
    } else {
        $status = 'critical';
        $message = __('GA4 non è configurato correttamente.', 'hotel-in-cloud');
    }

    return [
        'label'       => __('Configurazione GA4', 'hotel-in-cloud'),
        'status'      => $status,
        'description' => $message,
        'test'        => 'hic_site_health_ga4_config',
    ];
}

/**
 * Async test: ping webhook/health endpoint.
 *
 * @param array $result Default test result.
 * @return array Updated test result.
 */
function hic_site_health_webhook_ping(array $result): array
{
    $result['label'] = __('Ping Webhook', 'hotel-in-cloud');

    $token = Helpers\hic_get_health_token();
    if (empty($token)) {
        $result['status']      = 'recommended';
        $result['description'] = __('Token health non configurato.', 'hotel-in-cloud');
        return $result;
    }

    $url      = rest_url('hic/v1/health?token=' . urlencode($token));
    $response = wp_remote_get($url, ['timeout' => 10]);

    if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
        $result['status']      = 'good';
        $result['description'] = __('Webhook risponde correttamente.', 'hotel-in-cloud');
    } else {
        $result['status']      = 'critical';
        $result['description'] = __('Webhook non raggiungibile o token non valido.', 'hotel-in-cloud');
    }

    return $result;
}

add_filter('site_status_tests', __NAMESPACE__ . '\\hic_register_site_health_tests');
