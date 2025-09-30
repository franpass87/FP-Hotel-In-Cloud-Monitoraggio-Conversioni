<?php declare(strict_types=1);

namespace FpHic\HicS2S\Admin;

use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Services\Ga4Service;
use FpHic\HicS2S\Services\MetaCapiService;
use FpHic\HicS2S\ValueObjects\BookingPayload;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    private const OPTION_NAME = 'hic_s2s_settings';

    public static function bootstrap(): void
    {
        if (!\function_exists('add_action')) {
            return;
        }

        \add_action('admin_menu', [self::class, 'registerMenu']);
        \add_action('admin_init', [self::class, 'registerSettings']);
        \add_action('admin_post_hic_s2s_test_webhook', [self::class, 'handleTestWebhook']);
        \add_action('admin_post_hic_s2s_ping_ga4', [self::class, 'handlePingGa4']);
        \add_action('admin_post_hic_s2s_ping_meta', [self::class, 'handlePingMeta']);
        \add_action('admin_post_hic_s2s_export_logs', [self::class, 'handleExportLogs']);
    }

    public static function registerMenu(): void
    {
        if (!\function_exists('add_submenu_page')) {
            return;
        }

        \add_submenu_page(
            'hic-monitoring',
            __('HIC Webhook & S2S', 'hotel-in-cloud'),
            __('HIC Webhook & S2S', 'hotel-in-cloud'),
            'hic_manage',
            'hic-webhook-s2s',
            [self::class, 'renderPage']
        );
    }

    public static function registerSettings(): void
    {
        if (!\function_exists('register_setting')) {
            return;
        }

        \register_setting(
            'hic_s2s_settings_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitizeSettings'],
                'default' => self::getDefaultSettings(),
            ]
        );
    }

    public static function sanitizeSettings($value): array
    {
        $value = is_array($value) ? $value : [];

        $redirectorEnabled = !empty($value['redirector_enabled']);

        return [
            'token' => sanitize_text_field($value['token'] ?? ''),
            'ga4_measurement_id' => sanitize_text_field($value['ga4_measurement_id'] ?? ''),
            'ga4_api_secret' => sanitize_text_field($value['ga4_api_secret'] ?? ''),
            'meta_pixel_id' => sanitize_text_field($value['meta_pixel_id'] ?? ''),
            'meta_access_token' => sanitize_text_field($value['meta_access_token'] ?? ''),
            'redirector_enabled' => $redirectorEnabled,
            'redirector_engine_url' => esc_url_raw($value['redirector_engine_url'] ?? ''),
        ];
    }

    public static function getSettings(): array
    {
        $settings = \get_option(self::OPTION_NAME, self::getDefaultSettings());

        if (!is_array($settings)) {
            $settings = self::getDefaultSettings();
        }

        return wp_parse_args($settings, self::getDefaultSettings());
    }

    public static function renderPage(): void
    {
        if (!\function_exists('current_user_can') || !\current_user_can('hic_manage')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.', 'hotel-in-cloud'));
        }

        $settings = self::getSettings();
        $engineUrl = $settings['redirector_engine_url'] ?? '';
        $snippet = '';

        if ($engineUrl !== '') {
            $snippet = '/?fp_go_booking=1&target=' . rawurlencode(base64_encode($engineUrl));
        }

        $channelFilter = isset($_GET['hic_s2s_log_channel']) ? sanitize_key((string) $_GET['hic_s2s_log_channel']) : '';
        $logsRepo = new Logs();
        $logs = $logsRepo->latest(20, $channelFilter !== '' ? $channelFilter : null);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('HIC Webhook & S2S', 'hotel-in-cloud'); ?></h1>
            <?php settings_errors('hic_s2s'); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('hic_s2s_settings_group');
                ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row">
                            <label for="hic_s2s_token"><?php esc_html_e('Token Webhook', 'hotel-in-cloud'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" id="hic_s2s_token" name="<?php echo esc_attr(self::OPTION_NAME); ?>[token]" value="<?php echo esc_attr($settings['token']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hic_s2s_ga4_measurement_id"><?php esc_html_e('GA4 Measurement ID', 'hotel-in-cloud'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" id="hic_s2s_ga4_measurement_id" name="<?php echo esc_attr(self::OPTION_NAME); ?>[ga4_measurement_id]" value="<?php echo esc_attr($settings['ga4_measurement_id']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hic_s2s_ga4_api_secret"><?php esc_html_e('GA4 API Secret', 'hotel-in-cloud'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" id="hic_s2s_ga4_api_secret" name="<?php echo esc_attr(self::OPTION_NAME); ?>[ga4_api_secret]" value="<?php echo esc_attr($settings['ga4_api_secret']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hic_s2s_meta_pixel_id"><?php esc_html_e('Meta Pixel ID', 'hotel-in-cloud'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" id="hic_s2s_meta_pixel_id" name="<?php echo esc_attr(self::OPTION_NAME); ?>[meta_pixel_id]" value="<?php echo esc_attr($settings['meta_pixel_id']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hic_s2s_meta_access_token"><?php esc_html_e('Meta Access Token', 'hotel-in-cloud'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" id="hic_s2s_meta_access_token" name="<?php echo esc_attr(self::OPTION_NAME); ?>[meta_access_token]" value="<?php echo esc_attr($settings['meta_access_token']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hic_s2s_redirector_enabled"><?php esc_html_e('Abilita redirector /go/booking', 'hotel-in-cloud'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="hic_s2s_redirector_enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[redirector_enabled]" value="1" <?php checked($settings['redirector_enabled']); ?> />
                                <?php esc_html_e('Attiva la gestione redirector', 'hotel-in-cloud'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hic_s2s_redirector_engine_url"><?php esc_html_e('URL booking engine', 'hotel-in-cloud'); ?></label>
                        </th>
                        <td>
                            <input type="url" class="regular-text" id="hic_s2s_redirector_engine_url" name="<?php echo esc_attr(self::OPTION_NAME); ?>[redirector_engine_url]" value="<?php echo esc_attr($settings['redirector_engine_url']); ?>" />
                            <?php if ($snippet !== '') : ?>
                                <p class="description"><?php esc_html_e('Link pronto da usare:', 'hotel-in-cloud'); ?> <code><?php echo esc_html($snippet); ?></code></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Strumenti di test', 'hotel-in-cloud'); ?></h2>
            <p><?php esc_html_e('Usa questi pulsanti per verificare la configurazione del webhook e delle integrazioni server-to-server.', 'hotel-in-cloud'); ?></p>
            <div class="hic-s2s-tools">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1em;">
                    <?php wp_nonce_field('hic_s2s_test_webhook'); ?>
                    <input type="hidden" name="action" value="hic_s2s_test_webhook" />
                    <?php submit_button(__('Invia finto webhook', 'hotel-in-cloud'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1em;">
                    <?php wp_nonce_field('hic_s2s_ping_ga4'); ?>
                    <input type="hidden" name="action" value="hic_s2s_ping_ga4" />
                    <?php submit_button(__('Ping GA4', 'hotel-in-cloud'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <?php wp_nonce_field('hic_s2s_ping_meta'); ?>
                    <input type="hidden" name="action" value="hic_s2s_ping_meta" />
                    <?php submit_button(__('Ping Meta', 'hotel-in-cloud'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <h2><?php esc_html_e('Ultimi log', 'hotel-in-cloud'); ?></h2>
            <form method="get" style="margin-bottom:1em;">
                <input type="hidden" name="page" value="hic-webhook-s2s" />
                <label for="hic_s2s_log_channel">
                    <?php esc_html_e('Canale', 'hotel-in-cloud'); ?>
                </label>
                <input type="text" id="hic_s2s_log_channel" name="hic_s2s_log_channel" value="<?php echo esc_attr($channelFilter); ?>" />
                <?php submit_button(__('Filtra', 'hotel-in-cloud'), 'secondary', 'submit', false); ?>
                <?php
                $exportUrl = wp_nonce_url(
                    add_query_arg([
                        'action' => 'hic_s2s_export_logs',
                        'channel' => $channelFilter,
                    ], admin_url('admin-post.php')),
                    'hic_s2s_export_logs'
                );
                ?>
                <a class="button" href="<?php echo esc_url($exportUrl); ?>"><?php esc_html_e('Esporta CSV', 'hotel-in-cloud'); ?></a>
            </form>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'hotel-in-cloud'); ?></th>
                    <th><?php esc_html_e('Timestamp', 'hotel-in-cloud'); ?></th>
                    <th><?php esc_html_e('Canale', 'hotel-in-cloud'); ?></th>
                    <th><?php esc_html_e('Livello', 'hotel-in-cloud'); ?></th>
                    <th><?php esc_html_e('Messaggio', 'hotel-in-cloud'); ?></th>
                    <th><?php esc_html_e('Contesto', 'hotel-in-cloud'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($logs === []) : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e('Nessun log disponibile.', 'hotel-in-cloud'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) :
                        $context = '';
                        if (!empty($log['context'])) {
                            $decoded = json_decode((string) $log['context'], true);
                            if (is_array($decoded)) {
                                $context = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
                            } else {
                                $context = (string) $log['context'];
                            }
                        }
                    ?>
                        <tr>
                            <td><?php echo esc_html((string) $log['id']); ?></td>
                            <td><?php echo esc_html((string) $log['ts']); ?></td>
                            <td><?php echo esc_html((string) $log['channel']); ?></td>
                            <td><?php echo esc_html((string) $log['level']); ?></td>
                            <td><?php echo esc_html((string) $log['message']); ?></td>
                            <td><code><?php echo esc_html($context); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handleTestWebhook(): void
    {
        self::requireCapability();
        check_admin_referer('hic_s2s_test_webhook');

        $settings = self::getSettings();
        $token = $settings['token'] ?? '';

        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_body_params([
            'token' => $token,
            'booking_code' => 'TEST-' . wp_generate_uuid4(),
            'status' => 'confirmed',
            'checkin' => gmdate('Y-m-d'),
            'checkout' => gmdate('Y-m-d', strtotime('+1 day')),
            'currency' => 'EUR',
            'amount' => 1,
            'guest_email' => 'qa@example.com',
            'guest_phone' => '+3900000000',
        ]);
        $request->set_param('token', $token);

        $response = rest_do_request($request);

        if (is_wp_error($response)) {
            self::redirectWithMessage(__('Errore durante il test webhook.', 'hotel-in-cloud') . ' ' . $response->get_error_message(), 'error');
        } else {
            $data = $response->get_data();
            $message = __('Webhook di prova inviato correttamente.', 'hotel-in-cloud');
            if (isset($data['conversion_id'])) {
                $message .= ' #' . sanitize_text_field((string) $data['conversion_id']);
            }

            self::redirectWithMessage($message, 'updated');
        }
    }

    public static function handlePingGa4(): void
    {
        self::requireCapability();
        check_admin_referer('hic_s2s_ping_ga4');

        try {
            $payload = BookingPayload::fromArray([
                'booking_code' => 'GA4-' . wp_generate_uuid4(),
                'status' => 'confirmed',
                'checkin' => gmdate('Y-m-d'),
                'checkout' => gmdate('Y-m-d', strtotime('+1 day')),
                'currency' => 'EUR',
                'amount' => 1,
            ]);
        } catch (\InvalidArgumentException $exception) {
            self::redirectWithMessage(__('Impossibile costruire il payload di test GA4.', 'hotel-in-cloud'), 'error');
            return;
        }

        $testCode = 'HIC_S2S_TEST';
        $filter = static function (array $body) use ($testCode) {
            $body['test_event_code'] = $testCode;
            return $body;
        };

        add_filter('hic_s2s_ga4_payload', $filter);
        $result = (new Ga4Service())->sendPurchase($payload, false);
        remove_filter('hic_s2s_ga4_payload', $filter);

        if (!empty($result['sent'])) {
            self::redirectWithMessage(__('Ping GA4 completato con successo.', 'hotel-in-cloud'), 'updated');
            return;
        }

        $code = $result['code'] ?? null;
        $message = __('Ping GA4 fallito.', 'hotel-in-cloud');
        if ($code !== null) {
            $message .= ' HTTP ' . (int) $code;
        }

        self::redirectWithMessage($message, 'error');
    }

    public static function handlePingMeta(): void
    {
        self::requireCapability();
        check_admin_referer('hic_s2s_ping_meta');

        try {
            $payload = BookingPayload::fromArray([
                'booking_code' => 'META-' . wp_generate_uuid4(),
                'status' => 'confirmed',
                'checkin' => gmdate('Y-m-d'),
                'checkout' => gmdate('Y-m-d', strtotime('+1 day')),
                'currency' => 'EUR',
                'amount' => 1,
            ]);
        } catch (\InvalidArgumentException $exception) {
            self::redirectWithMessage(__('Impossibile costruire il payload di test Meta.', 'hotel-in-cloud'), 'error');
            return;
        }

        $result = (new MetaCapiService())->sendPurchase($payload, false);

        if (!empty($result['sent'])) {
            self::redirectWithMessage(__('Ping Meta completato con successo.', 'hotel-in-cloud'), 'updated');
            return;
        }

        $code = $result['code'] ?? null;
        $message = __('Ping Meta fallito.', 'hotel-in-cloud');
        if ($code !== null) {
            $message .= ' HTTP ' . (int) $code;
        }

        self::redirectWithMessage($message, 'error');
    }

    public static function handleExportLogs(): void
    {
        self::requireCapability();
        check_admin_referer('hic_s2s_export_logs');

        $channel = isset($_GET['channel']) ? sanitize_key((string) $_GET['channel']) : '';
        $logs = (new Logs())->latest(200, $channel !== '' ? $channel : null);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hic-logs-' . gmdate('Ymd-His') . '.csv"');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(__('Impossibile generare il file CSV.', 'hotel-in-cloud'));
        }

        fputcsv($output, ['id', 'timestamp', 'channel', 'level', 'message', 'context']);
        foreach ($logs as $log) {
            $context = (string) ($log['context'] ?? '');
            fputcsv($output, [
                $log['id'] ?? '',
                $log['ts'] ?? '',
                $log['channel'] ?? '',
                $log['level'] ?? '',
                $log['message'] ?? '',
                $context,
            ]);
        }

        fclose($output);
        exit;
    }

    private static function getDefaultSettings(): array
    {
        return [
            'token' => 'hic2025ga4',
            'ga4_measurement_id' => '',
            'ga4_api_secret' => '',
            'meta_pixel_id' => '',
            'meta_access_token' => '',
            'redirector_enabled' => false,
            'redirector_engine_url' => '',
        ];
    }

    private static function requireCapability(): void
    {
        if (!current_user_can('hic_manage')) {
            wp_die(__('Permessi insufficienti per eseguire questa azione.', 'hotel-in-cloud'));
        }
    }

    private static function redirectWithMessage(string $message, string $type): void
    {
        add_settings_error('hic_s2s', 'hic_s2s_notice', $message, $type);
        set_transient('settings_errors', get_settings_errors('hic_s2s'), 30);
        wp_safe_redirect(admin_url('admin.php?page=hic-webhook-s2s'));
        exit;
    }
}
