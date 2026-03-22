<?php declare(strict_types=1);

namespace FpHic\SimpleBrevoSync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap plugin minimale HIC -> Brevo.
 */
final class Bootstrap
{
    private const OPTION_WEBHOOK_TOKEN = 'hic_webhook_token';
    private const OPTION_BREVO_API_KEY = 'hic_brevo_api_key';
    private const OPTION_BREVO_LIST_ID = 'hic_brevo_list_id';
    private const OPTION_BREVO_EVENT_MODE = 'hic_brevo_event_mode';
    private const OPTION_BREVO_EVENT_ENDPOINT = 'hic_brevo_event_endpoint';
    private const OPTION_BREVO_EVENT_API_KEY = 'hic_brevo_event_api_key';
    private const OPTION_ENABLE_FP_TRACKING = 'hic_enable_fp_tracking';
    private const OPTION_BREVO_TEST_HISTORY = 'hic_brevo_test_history';
    private const OPTION_LAST_HIC_PAYLOAD = 'hic_last_hic_payload';
    private const REST_NAMESPACE = 'hic/v1';
    private const REST_ROUTE = '/conversion';

    /**
     * Registra hook principali del plugin.
     */
    public static function init(): void
    {
        \add_action('plugins_loaded', [self::class, 'loadTextDomain']);
        \add_action('admin_menu', [self::class, 'registerAdminPage']);
        \add_action('admin_init', [self::class, 'registerSettings']);
        \add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
        \add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        \add_action('wp_ajax_hic_test_brevo_connection', [self::class, 'ajaxTestBrevoConnection']);
        \add_action('wp_ajax_hic_clear_brevo_test_history', [self::class, 'ajaxClearBrevoTestHistory']);
        \add_filter('plugin_action_links_' . \plugin_basename(FP_HIC_BREVO_FILE), [self::class, 'addPluginActionLinks']);

        \register_activation_hook(FP_HIC_BREVO_FILE, [self::class, 'activate']);
    }

    /**
     * Imposta valori default all'attivazione.
     */
    public static function activate(): void
    {
        if (\get_option(self::OPTION_ENABLE_FP_TRACKING, null) === null) {
            \update_option(self::OPTION_ENABLE_FP_TRACKING, true);
        }
        if (\get_option(self::OPTION_BREVO_EVENT_MODE, null) === null) {
            \update_option(self::OPTION_BREVO_EVENT_MODE, 'v3');
        }
        if (\get_option(self::OPTION_BREVO_EVENT_ENDPOINT, null) === null) {
            \update_option(self::OPTION_BREVO_EVENT_ENDPOINT, 'https://api.brevo.com/v3/events');
        }
    }

    /**
     * Carica file traduzioni.
     */
    public static function loadTextDomain(): void
    {
        \load_plugin_textdomain('hotel-in-cloud', false, \dirname(\plugin_basename(FP_HIC_BREVO_FILE)) . '/languages');
    }

    /**
     * Aggiunge link impostazioni nella lista plugin.
     *
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public static function addPluginActionLinks(array $links): array
    {
        $settings_url = \admin_url('options-general.php?page=hic-brevo-sync');
        \array_unshift(
            $links,
            '<a href="' . \esc_url($settings_url) . '">' . \esc_html__('Impostazioni', 'hotel-in-cloud') . '</a>'
        );

        return $links;
    }

    /**
     * Registra pagina impostazioni.
     */
    public static function registerAdminPage(): void
    {
        \add_options_page(
            \__('FP HIC → Brevo', 'hotel-in-cloud'),
            \__('FP HIC → Brevo', 'hotel-in-cloud'),
            'manage_options',
            'hic-brevo-sync',
            [self::class, 'renderAdminPage']
        );
    }

    /**
     * Registra opzioni del plugin.
     */
    public static function registerSettings(): void
    {
        \register_setting('hic_brevo_sync', self::OPTION_WEBHOOK_TOKEN, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeToken'],
            'default' => '',
        ]);

        \register_setting('hic_brevo_sync', self::OPTION_BREVO_API_KEY, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        \register_setting('hic_brevo_sync', self::OPTION_BREVO_LIST_ID, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        \register_setting('hic_brevo_sync', self::OPTION_BREVO_EVENT_MODE, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeEventMode'],
            'default' => 'v3',
        ]);

        \register_setting('hic_brevo_sync', self::OPTION_BREVO_EVENT_ENDPOINT, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://api.brevo.com/v3/events',
        ]);

        \register_setting('hic_brevo_sync', self::OPTION_BREVO_EVENT_API_KEY, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        \register_setting('hic_brevo_sync', self::OPTION_ENABLE_FP_TRACKING, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);
    }

    /**
     * Sanifica token webhook.
     */
    public static function sanitizeToken($value): string
    {
        if (!\is_string($value)) {
            return '';
        }

        $value = \trim(\sanitize_text_field($value));

        if ($value === '') {
            return '';
        }

        if (\strlen($value) > 128) {
            $value = \substr($value, 0, 128);
        }

        return $value;
    }

    /**
     * Sanifica modalità evento Brevo.
     */
    public static function sanitizeEventMode($value): string
    {
        if (!\is_string($value)) {
            return 'v3';
        }

        $value = \strtolower(\trim(\sanitize_text_field($value)));
        if (!\in_array($value, ['v3', 'legacy'], true)) {
            return 'v3';
        }

        return $value;
    }

    /**
     * Render della pagina impostazioni.
     */
    public static function renderAdminPage(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('Permessi insufficienti.', 'hotel-in-cloud'));
        }

        $token = (string) \get_option(self::OPTION_WEBHOOK_TOKEN, '');
        $api_key = (string) \get_option(self::OPTION_BREVO_API_KEY, '');
        $list_id = (int) \get_option(self::OPTION_BREVO_LIST_ID, 0);
        $event_mode = self::sanitizeEventMode((string) \get_option(self::OPTION_BREVO_EVENT_MODE, 'v3'));
        $event_endpoint = (string) \get_option(self::OPTION_BREVO_EVENT_ENDPOINT, 'https://api.brevo.com/v3/events');
        $event_api_key = (string) \get_option(self::OPTION_BREVO_EVENT_API_KEY, '');
        $tracking_enabled = self::isOptionEnabled(self::OPTION_ENABLE_FP_TRACKING, true);
        $endpoint = \rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
        ?>
        <div class="wrap">
            <h1 class="screen-reader-text"><?php echo \esc_html__('FP HIC Brevo Sync', 'hotel-in-cloud'); ?></h1>
            <h2><?php echo \esc_html__('FP HIC → Brevo (minimale)', 'hotel-in-cloud'); ?></h2>
            <p><?php echo \esc_html__('Il plugin riceve nuove prenotazioni e le sincronizza con Brevo.', 'hotel-in-cloud'); ?></p>
            <?php \settings_errors(); ?>

            <form action="options.php" method="post">
                <?php \settings_fields('hic_brevo_sync'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo \esc_attr(self::OPTION_WEBHOOK_TOKEN); ?>"><?php echo \esc_html__('Webhook token HIC', 'hotel-in-cloud'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="<?php echo \esc_attr(self::OPTION_WEBHOOK_TOKEN); ?>" name="<?php echo \esc_attr(self::OPTION_WEBHOOK_TOKEN); ?>" value="<?php echo \esc_attr($token); ?>" />
                            <p class="description"><?php echo \esc_html__('Token condiviso usato per autenticare le chiamate webhook.', 'hotel-in-cloud'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo \esc_attr(self::OPTION_BREVO_API_KEY); ?>"><?php echo \esc_html__('Brevo API Key', 'hotel-in-cloud'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="<?php echo \esc_attr(self::OPTION_BREVO_API_KEY); ?>" name="<?php echo \esc_attr(self::OPTION_BREVO_API_KEY); ?>" value="<?php echo \esc_attr($api_key); ?>" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo \esc_attr(self::OPTION_BREVO_LIST_ID); ?>"><?php echo \esc_html__('Brevo List ID (opzionale)', 'hotel-in-cloud'); ?></label></th>
                        <td>
                            <input type="number" class="small-text" id="<?php echo \esc_attr(self::OPTION_BREVO_LIST_ID); ?>" name="<?php echo \esc_attr(self::OPTION_BREVO_LIST_ID); ?>" value="<?php echo \esc_attr((string) $list_id); ?>" min="0" step="1" />
                            <p class="description"><?php echo \esc_html__('Se impostato, il contatto viene aggiunto/aggiornato nella lista.', 'hotel-in-cloud'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_MODE); ?>"><?php echo \esc_html__('Brevo Event API mode', 'hotel-in-cloud'); ?></label></th>
                        <td>
                            <select id="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_MODE); ?>" name="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_MODE); ?>">
                                <option value="v3" <?php \selected($event_mode, 'v3'); ?>><?php echo \esc_html__('V3 Events (consigliato)', 'hotel-in-cloud'); ?></option>
                                <option value="legacy" <?php \selected($event_mode, 'legacy'); ?>><?php echo \esc_html__('Legacy trackEvent', 'hotel-in-cloud'); ?></option>
                            </select>
                            <p class="description"><?php echo \esc_html__('Usa V3 Events come impostazione aggiornata Brevo. Legacy solo per retrocompatibilità.', 'hotel-in-cloud'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_ENDPOINT); ?>"><?php echo \esc_html__('Brevo Event endpoint', 'hotel-in-cloud'); ?></label></th>
                        <td>
                            <input type="url" class="regular-text" id="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_ENDPOINT); ?>" name="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_ENDPOINT); ?>" value="<?php echo \esc_attr($event_endpoint); ?>" />
                            <p class="description"><?php echo \esc_html__('Default aggiornato: https://api.brevo.com/v3/events', 'hotel-in-cloud'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_API_KEY); ?>"><?php echo \esc_html__('Brevo Event API Key (opzionale)', 'hotel-in-cloud'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_API_KEY); ?>" name="<?php echo \esc_attr(self::OPTION_BREVO_EVENT_API_KEY); ?>" value="<?php echo \esc_attr($event_api_key); ?>" autocomplete="off" />
                            <p class="description"><?php echo \esc_html__('Se vuota, viene usata la Brevo API Key principale.', 'hotel-in-cloud'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Integrazione FP Tracking', 'hotel-in-cloud'); ?></th>
                        <td>
                            <label for="<?php echo \esc_attr(self::OPTION_ENABLE_FP_TRACKING); ?>">
                                <input type="checkbox" id="<?php echo \esc_attr(self::OPTION_ENABLE_FP_TRACKING); ?>" name="<?php echo \esc_attr(self::OPTION_ENABLE_FP_TRACKING); ?>" value="1" <?php \checked($tracking_enabled); ?> />
                                <?php echo \esc_html__("Emetti evento 'fp_tracking_event' dopo sync Brevo.", 'hotel-in-cloud'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php \submit_button(\__('Salva impostazioni', 'hotel-in-cloud')); ?>
            </form>

            <hr />
            <h3><?php echo \esc_html__('Validazione live Brevo', 'hotel-in-cloud'); ?></h3>
            <p><?php echo \esc_html__('Esegue un test reale su Brevo: connessione API, contatto e evento.', 'hotel-in-cloud'); ?></p>
            <p>
                <button type="button" class="button button-secondary" id="hic-test-brevo-connection">
                    <?php echo \esc_html__('Test connessione Brevo', 'hotel-in-cloud'); ?>
                </button>
                <button type="button" class="button" id="hic-clear-brevo-history">
                    <?php echo \esc_html__('Svuota storico test', 'hotel-in-cloud'); ?>
                </button>
            </p>
            <div id="hic-test-brevo-result" class="notice" style="display:none; padding:12px;"></div>
            <?php self::renderBrevoTestHistory(); ?>
            <?php self::renderLastPayloadPanel(); ?>

            <hr />
            <p><strong><?php echo \esc_html__('Endpoint webhook', 'hotel-in-cloud'); ?>:</strong> <code><?php echo \esc_html($endpoint); ?></code></p>
            <p class="description"><?php echo \esc_html__('Inviare richieste POST JSON con query parameter token.', 'hotel-in-cloud'); ?></p>
        </div>
        <?php
    }

    /**
     * Registra endpoint webhook.
     */
    public static function registerRestRoutes(): void
    {
        \register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'webhookPermission'],
            'callback' => [self::class, 'handleWebhook'],
        ]);
    }

    /**
     * Valida token del webhook.
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public static function webhookPermission(\WP_REST_Request $request)
    {
        $expected = self::sanitizeToken((string) \get_option(self::OPTION_WEBHOOK_TOKEN, ''));
        if ($expected === '') {
            return new \WP_Error(
                'hic_missing_token',
                \__('Token webhook non configurato.', 'hotel-in-cloud'),
                ['status' => 500]
            );
        }

        $provided = (string) $request->get_param('token');
        $provided = self::sanitizeToken($provided);

        if ($provided === '' || !\hash_equals($expected, $provided)) {
            return new \WP_Error(
                'hic_invalid_token',
                \__('Token webhook non valido.', 'hotel-in-cloud'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Gestisce payload prenotazione e invia dati a Brevo.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handleWebhook(\WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!\is_array($payload)) {
            return new \WP_Error('hic_invalid_payload', \__('Payload JSON non valido.', 'hotel-in-cloud'), ['status' => 400]);
        }

        $booking = self::normalizeBooking($payload);
        self::storeLastHicPayload($payload, $booking);
        if (empty($booking['customer_email'])) {
            return new \WP_Error('hic_missing_email', \__('Email cliente mancante.', 'hotel-in-cloud'), ['status' => 422]);
        }

        if (!self::isNewBooking($payload, $booking)) {
            return new \WP_REST_Response([
                'status' => 'ok',
                'processed' => false,
                'reason' => 'not_new_booking',
            ], 200);
        }

        if (!self::markAsProcessed($booking)) {
            return new \WP_REST_Response([
                'status' => 'ok',
                'processed' => false,
                'reason' => 'duplicate',
            ], 200);
        }

        $contact_result = self::sendBrevoContact($booking);
        if (\is_wp_error($contact_result)) {
            return $contact_result;
        }

        $event_result = self::sendBrevoEvent($booking);
        if (\is_wp_error($event_result)) {
            return $event_result;
        }

        self::dispatchFpTrackingEvent($booking);

        return new \WP_REST_Response([
            'status' => 'ok',
            'processed' => true,
            'reservation_id' => $booking['reservation_id'],
        ], 200);
    }

    /**
     * Normalizza payload in struttura unica per Brevo.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeBooking(array $payload): array
    {
        $source = $payload;
        if (isset($payload['reservation']) && \is_array($payload['reservation'])) {
            $source = $payload['reservation'];
        }

        $customer = [];
        if (isset($payload['customer']) && \is_array($payload['customer'])) {
            $customer = $payload['customer'];
        } elseif (isset($source['customer']) && \is_array($source['customer'])) {
            $customer = $source['customer'];
        }

        $reservation_id = self::firstScalar($source, ['reservation_id', 'transaction_id', 'id', 'reservation_code']);
        $arrival = self::firstScalar($source, ['arrival_date', 'from_date', 'checkin', 'date_from']);
        $departure = self::firstScalar($source, ['departure_date', 'to_date', 'checkout', 'date_to']);
        $booking_date = self::firstScalar($source, ['booking_date', 'date', 'created_at', 'reservation_date']);
        if ($booking_date === '') {
            $booking_date = \current_time('Y-m-d');
        }

        $amount = self::toFloat(self::firstScalar($source, ['amount', 'total_amount', 'value', 'price', 'original_price']));
        $currency = self::firstScalar($source, ['currency']);
        if ($currency === '') {
            $currency = 'EUR';
        }
        $status = self::firstScalar($source, ['status', 'presence']);

        $email = self::sanitizeEmail(
            self::firstScalar($customer, ['email']) !== ''
                ? self::firstScalar($customer, ['email'])
                : self::firstScalar($source, ['email'])
        );

        return [
            'reservation_id' => $reservation_id,
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'booking_date' => $booking_date,
            'customer_email' => $email,
            'customer_first_name' => self::firstScalarMerged($customer, $source, ['first_name', 'guest_first_name', 'firstname']),
            'customer_last_name' => self::firstScalarMerged($customer, $source, ['last_name', 'guest_last_name', 'lastname']),
            'customer_phone' => self::firstScalarMerged($customer, $source, ['phone', 'mobile', 'whatsapp']),
            'customer_language' => self::firstScalarMerged($customer, $source, ['language', 'lang', 'locale']),
            'customer_address' => self::firstScalarMerged($customer, $source, ['address', 'address_line1', 'street']),
            'customer_city' => self::firstScalarMerged($customer, $source, ['city']),
            'customer_country' => self::firstScalarMerged($customer, $source, ['country', 'country_code']),
            'customer_zip' => self::firstScalarMerged($customer, $source, ['zip', 'postal_code']),
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
        ];
    }

    /**
     * Determina se la prenotazione rappresenta un "nuovo booking".
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $booking
     */
    private static function isNewBooking(array $payload, array $booking): bool
    {
        $event = \strtolower(self::firstScalar($payload, ['event', 'action', 'type']));
        if ($event !== '') {
            return \in_array($event, ['reservation_created', 'booking_created', 'new_booking', 'created'], true);
        }

        return ($booking['reservation_id'] ?? '') !== '';
    }

    /**
     * Evita doppio invio ravvicinato verso Brevo.
     *
     * @param array<string,mixed> $booking
     */
    private static function markAsProcessed(array $booking): bool
    {
        $key = self::buildDedupKey($booking);
        if ($key === '') {
            return true;
        }

        if (\get_transient($key)) {
            return false;
        }

        \set_transient($key, '1', 12 * HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Costruisce chiave di deduplica.
     *
     * @param array<string,mixed> $booking
     */
    private static function buildDedupKey(array $booking): string
    {
        $base = (string) ($booking['reservation_id'] ?? '');
        if ($base === '') {
            $base = (string) ($booking['customer_email'] ?? '') . '|' . (string) ($booking['arrival_date'] ?? '');
        }

        $base = \trim($base);
        if ($base === '') {
            return '';
        }

        return 'hic_brevo_sync_' . \substr(\hash('sha256', $base), 0, 32);
    }

    /**
     * Crea/aggiorna contatto Brevo con dati cliente e soggiorno.
     *
     * @param array<string,mixed> $booking
     * @return true|\WP_Error
     */
    private static function sendBrevoContact(array $booking)
    {
        $api_key = \sanitize_text_field((string) \get_option(self::OPTION_BREVO_API_KEY, ''));
        if ($api_key === '') {
            return new \WP_Error('hic_missing_brevo_key', \__('Brevo API Key non configurata.', 'hotel-in-cloud'), ['status' => 500]);
        }

        $list_id = (int) \get_option(self::OPTION_BREVO_LIST_ID, 0);

        $body = [
            'email' => (string) $booking['customer_email'],
            'attributes' => [
                'FIRSTNAME' => (string) ($booking['customer_first_name'] ?? ''),
                'LASTNAME' => (string) ($booking['customer_last_name'] ?? ''),
                'PHONE' => (string) ($booking['customer_phone'] ?? ''),
                'ADDRESS' => (string) ($booking['customer_address'] ?? ''),
                'CITY' => (string) ($booking['customer_city'] ?? ''),
                'COUNTRY' => (string) ($booking['customer_country'] ?? ''),
                'ZIP' => (string) ($booking['customer_zip'] ?? ''),
                'LANGUAGE' => (string) ($booking['customer_language'] ?? ''),
                'HIC_RES_ID' => (string) ($booking['reservation_id'] ?? ''),
                'HIC_ARRIVAL_DATE' => (string) ($booking['arrival_date'] ?? ''),
                'HIC_DEPARTURE_DATE' => (string) ($booking['departure_date'] ?? ''),
                'HIC_BOOKING_DATE' => (string) ($booking['booking_date'] ?? ''),
            ],
            'updateEnabled' => true,
        ];

        if ($list_id > 0) {
            $body['listIds'] = [$list_id];
        }

        $response = \wp_remote_post('https://api.brevo.com/v3/contacts', [
            'headers' => [
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'api-key' => $api_key,
            ],
            'body' => \wp_json_encode($body),
            'timeout' => 20,
        ]);

        return self::validateBrevoResponse($response, 'contact');
    }

    /**
     * Invia evento custom a Brevo per automazioni.
     *
     * @param array<string,mixed> $booking
     * @return true|\WP_Error
     */
    private static function sendBrevoEvent(array $booking)
    {
        $api_key = \sanitize_text_field((string) \get_option(self::OPTION_BREVO_EVENT_API_KEY, ''));
        if ($api_key === '') {
            $api_key = \sanitize_text_field((string) \get_option(self::OPTION_BREVO_API_KEY, ''));
        }
        if ($api_key === '') {
            return new \WP_Error('hic_missing_brevo_key', \__('Brevo API Key non configurata.', 'hotel-in-cloud'), ['status' => 500]);
        }

        $mode = self::sanitizeEventMode((string) \get_option(self::OPTION_BREVO_EVENT_MODE, 'v3'));

        if ($mode === 'legacy') {
            $endpoint = (string) \get_option(self::OPTION_BREVO_EVENT_ENDPOINT, 'https://in-automate.brevo.com/api/v2/trackEvent');
            if ($endpoint === '') {
                $endpoint = 'https://in-automate.brevo.com/api/v2/trackEvent';
            }

            $payload = [
                'event' => 'reservation_created',
                'email' => (string) $booking['customer_email'],
                'properties' => [
                    'reservation_id' => (string) ($booking['reservation_id'] ?? ''),
                    'arrival_date' => (string) ($booking['arrival_date'] ?? ''),
                    'departure_date' => (string) ($booking['departure_date'] ?? ''),
                    'booking_date' => (string) ($booking['booking_date'] ?? ''),
                    'first_name' => (string) ($booking['customer_first_name'] ?? ''),
                    'last_name' => (string) ($booking['customer_last_name'] ?? ''),
                    'phone' => (string) ($booking['customer_phone'] ?? ''),
                ],
            ];

            $response = \wp_remote_post($endpoint, [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                    'ma-key' => $api_key,
                ],
                'body' => \wp_json_encode($payload),
                'timeout' => 20,
            ]);

            return self::validateBrevoResponse($response, 'event_legacy');
        }

        $endpoint = (string) \get_option(self::OPTION_BREVO_EVENT_ENDPOINT, 'https://api.brevo.com/v3/events');
        if ($endpoint === '') {
            $endpoint = 'https://api.brevo.com/v3/events';
        }

        $payload = [
            'event_name' => 'reservation_created',
            'event_date' => \gmdate('c'),
            'identifiers' => [
                'email_id' => (string) $booking['customer_email'],
            ],
            'contact_properties' => [
                'FNAME' => (string) ($booking['customer_first_name'] ?? ''),
                'LNAME' => (string) ($booking['customer_last_name'] ?? ''),
                'PHONE' => (string) ($booking['customer_phone'] ?? ''),
                'LANGUAGE' => (string) ($booking['customer_language'] ?? ''),
            ],
            'event_properties' => [
                'reservation_id' => (string) ($booking['reservation_id'] ?? ''),
                'arrival_date' => (string) ($booking['arrival_date'] ?? ''),
                'departure_date' => (string) ($booking['departure_date'] ?? ''),
                'booking_date' => (string) ($booking['booking_date'] ?? ''),
                'address' => (string) ($booking['customer_address'] ?? ''),
                'city' => (string) ($booking['customer_city'] ?? ''),
                'country' => (string) ($booking['customer_country'] ?? ''),
                'zip' => (string) ($booking['customer_zip'] ?? ''),
            ],
        ];

        $response = \wp_remote_post($endpoint, [
            'headers' => [
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'api-key' => $api_key,
            ],
            'body' => \wp_json_encode($payload),
            'timeout' => 20,
        ]);

        return self::validateBrevoResponse($response, 'event_v3');
    }

    /**
     * Valida risposta HTTP da Brevo.
     *
     * @param mixed  $response
     * @param string $context
     * @return true|\WP_Error
     */
    private static function validateBrevoResponse($response, string $context)
    {
        if (\is_wp_error($response)) {
            return new \WP_Error(
                'hic_brevo_http_error',
                \sprintf(\__('Errore Brevo (%s): %s', 'hotel-in-cloud'), $context, $response->get_error_message()),
                ['status' => 502]
            );
        }

        $code = (int) \wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return true;
        }

        $body = (string) \wp_remote_retrieve_body($response);
        $message = $body !== '' ? $body : 'HTTP ' . $code;

        return new \WP_Error(
            'hic_brevo_api_error',
            \sprintf(\__('Brevo ha risposto con errore (%s): %s', 'hotel-in-cloud'), $context, $message),
            ['status' => 502]
        );
    }

    /**
     * Invia evento compatibile con FP Tracking Layer.
     *
     * @param array<string,mixed> $booking
     */
    private static function dispatchFpTrackingEvent(array $booking): void
    {
        $tracking_enabled = self::isOptionEnabled(self::OPTION_ENABLE_FP_TRACKING, true);
        if (!$tracking_enabled) {
            return;
        }

        $reservation_id = (string) ($booking['reservation_id'] ?? '');
        $transaction_id = $reservation_id !== '' ? 'hic-' . $reservation_id : '';
        $amount = isset($booking['amount']) ? (float) $booking['amount'] : 0.0;
        $currency = (string) ($booking['currency'] ?? 'EUR');

        $event_params = [
            'source' => 'fp-hic-monitor',
            'reservation_id' => $reservation_id,
            'transaction_id' => $transaction_id,
            'email' => (string) ($booking['customer_email'] ?? ''),
            'arrival_date' => (string) ($booking['arrival_date'] ?? ''),
            'departure_date' => (string) ($booking['departure_date'] ?? ''),
            'booking_date' => (string) ($booking['booking_date'] ?? ''),
            'status' => (string) ($booking['status'] ?? ''),
            'value' => $amount,
            'currency' => $currency,
            'user_data' => [
                'em' => (string) ($booking['customer_email'] ?? ''),
                'fn' => (string) ($booking['customer_first_name'] ?? ''),
                'ln' => (string) ($booking['customer_last_name'] ?? ''),
                'ph' => (string) ($booking['customer_phone'] ?? ''),
            ],
            'customer' => [
                'first_name' => (string) ($booking['customer_first_name'] ?? ''),
                'last_name' => (string) ($booking['customer_last_name'] ?? ''),
                'phone' => (string) ($booking['customer_phone'] ?? ''),
                'language' => (string) ($booking['customer_language'] ?? ''),
                'address' => (string) ($booking['customer_address'] ?? ''),
                'city' => (string) ($booking['customer_city'] ?? ''),
                'country' => (string) ($booking['customer_country'] ?? ''),
                'zip' => (string) ($booking['customer_zip'] ?? ''),
            ],
        ];

        $status = \strtolower((string) ($booking['status'] ?? ''));
        $has_value = $amount > 0;
        $is_confirmed = \in_array($status, ['confirmed', 'paid', 'approved', 'booked'], true) || $status === '';

        // Canonical cross-plugin events for FP Tracking Layer.
        if ($is_confirmed) {
            \do_action('fp_tracking_event', 'booking_confirmed', $event_params);
        }
        if ($has_value && $is_confirmed) {
            \do_action('fp_tracking_event', 'purchase', $event_params);
        }

        // Legacy/custom HIC events kept for backward compatibility.
        \do_action('fp_tracking_event', 'hic_booking_created', $event_params);
        \do_action('fp_tracking_event', 'hic_brevo_booking_synced', $event_params);
    }

    /**
     * Ritorna primo valore scalare non vuoto.
     *
     * @param array<string,mixed> $source
     * @param array<int,string>   $keys
     */
    private static function firstScalar(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($source[$key]) || !\is_scalar($source[$key])) {
                continue;
            }

            $value = \sanitize_text_field((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Cerca valore prima in customer poi in source.
     *
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $source
     * @param array<int,string>   $keys
     */
    private static function firstScalarMerged(array $customer, array $source, array $keys): string
    {
        $value = self::firstScalar($customer, $keys);
        if ($value !== '') {
            return $value;
        }

        return self::firstScalar($source, $keys);
    }

    /**
     * Sanifica email.
     */
    private static function sanitizeEmail(string $email): string
    {
        $email = \sanitize_email($email);
        return \is_email($email) ? $email : '';
    }

    /**
     * Converte una stringa numerica in float in modo resiliente.
     */
    private static function toFloat(string $value): float
    {
        if ($value === '') {
            return 0.0;
        }

        $normalized = \str_replace(',', '.', $value);
        if (!\is_numeric($normalized)) {
            return 0.0;
        }

        return (float) $normalized;
    }

    /**
     * Normalizza opzioni booleane salvate come stringhe o bool.
     */
    private static function isOptionEnabled(string $option_name, bool $default = false): bool
    {
        $value = \get_option($option_name, $default);

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \in_array(\strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (\is_numeric($value)) {
            return (int) $value === 1;
        }

        return $default;
    }

    /**
     * Carica JS minimale per il test live Brevo in admin.
     *
     * @param string $hook
     */
    public static function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'settings_page_hic-brevo-sync') {
            return;
        }

        \wp_register_script('hic-brevo-admin-inline', '', ['jquery'], FP_HIC_BREVO_VERSION, true);
        \wp_enqueue_script('hic-brevo-admin-inline');
        \wp_localize_script('hic-brevo-admin-inline', 'hicBrevoTest', [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'nonce' => \wp_create_nonce('hic_test_brevo_connection'),
            'clearNonce' => \wp_create_nonce('hic_clear_brevo_test_history'),
            'i18n' => [
                'running' => \__('Test in corso...', 'hotel-in-cloud'),
                'networkError' => \__('Errore di rete durante il test.', 'hotel-in-cloud'),
                'clearConfirm' => \__('Vuoi davvero svuotare lo storico test?', 'hotel-in-cloud'),
                'clearDone' => \__('Storico test svuotato.', 'hotel-in-cloud'),
            ],
        ]);

        $inline_js = <<<'JS'
(function ($) {
  'use strict';

  function setResult(type, html) {
    var $box = $('#hic-test-brevo-result');
    $box.removeClass('notice-success notice-error notice-warning');
    $box.addClass(type);
    $box.html(html);
    $box.show();
  }

  $(document).on('click', '#hic-test-brevo-connection', function () {
    var $btn = $(this);
    $btn.prop('disabled', true);
    setResult('notice notice-warning', '<p>' + (hicBrevoTest.i18n.running || 'Test in corso...') + '</p>');

    $.post(hicBrevoTest.ajaxUrl, {
      action: 'hic_test_brevo_connection',
      nonce: hicBrevoTest.nonce
    }).done(function (response) {
      if (response && response.success && response.data) {
        var lines = [];
        lines.push('<strong>OK</strong> - ' + (response.data.message || 'Test completato'));
        if (response.data.details) {
          lines.push('<br><code>' + JSON.stringify(response.data.details) + '</code>');
        }
        setResult('notice notice-success', '<p>' + lines.join('') + '</p>');
        return;
      }

      var err = (response && response.data && response.data.message) ? response.data.message : 'Test fallito';
      var details = (response && response.data && response.data.details) ? response.data.details : null;
      var html = '<p><strong>ERRORE</strong> - ' + err + '</p>';
      if (details) {
        html += '<p><code>' + JSON.stringify(details) + '</code></p>';
      }
      setResult('notice notice-error', html);
    }).fail(function () {
      setResult('notice notice-error', '<p>' + (hicBrevoTest.i18n.networkError || 'Errore di rete durante il test.') + '</p>');
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

  $(document).on('click', '#hic-clear-brevo-history', function () {
    var confirmText = (hicBrevoTest.i18n.clearConfirm || 'Vuoi davvero svuotare lo storico test?');
    if (!window.confirm(confirmText)) {
      return;
    }

    var $btn = $(this);
    $btn.prop('disabled', true);

    $.post(hicBrevoTest.ajaxUrl, {
      action: 'hic_clear_brevo_test_history',
      nonce: hicBrevoTest.clearNonce
    }).done(function (response) {
      if (response && response.success) {
        setResult('notice notice-success', '<p>' + (hicBrevoTest.i18n.clearDone || 'Storico test svuotato.') + '</p>');
        window.location.reload();
        return;
      }

      var err = (response && response.data && response.data.message) ? response.data.message : 'Errore durante la pulizia';
      setResult('notice notice-error', '<p><strong>ERRORE</strong> - ' + err + '</p>');
    }).fail(function () {
      setResult('notice notice-error', '<p>' + (hicBrevoTest.i18n.networkError || 'Errore di rete durante il test.') + '</p>');
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });
})(jQuery);
JS;

        \wp_add_inline_script('hic-brevo-admin-inline', $inline_js);
    }

    /**
     * AJAX: esegue validazione live della connessione Brevo.
     */
    public static function ajaxTestBrevoConnection(): void
    {
        if (!\check_ajax_referer('hic_test_brevo_connection', 'nonce', false)) {
            \wp_send_json_error([
                'message' => \__('Nonce non valido.', 'hotel-in-cloud'),
            ], 403);
        }

        if (!\current_user_can('manage_options')) {
            \wp_send_json_error([
                'message' => \__('Permessi insufficienti.', 'hotel-in-cloud'),
            ], 403);
        }

        $result = self::runBrevoConnectionTest();
        if (\is_wp_error($result)) {
            self::appendBrevoTestHistory(false, $result->get_error_message(), [
                'error_data' => $result->get_error_data(),
            ]);
            \wp_send_json_error([
                'message' => $result->get_error_message(),
                'details' => $result->get_error_data(),
            ], 500);
        }

        self::appendBrevoTestHistory(
            true,
            \__('Test live completato con successo.', 'hotel-in-cloud'),
            isset($result['details']) && \is_array($result['details']) ? $result['details'] : []
        );
        \wp_send_json_success($result);
    }

    /**
     * AJAX: svuota storico test Brevo.
     */
    public static function ajaxClearBrevoTestHistory(): void
    {
        if (!\check_ajax_referer('hic_clear_brevo_test_history', 'nonce', false)) {
            \wp_send_json_error([
                'message' => \__('Nonce non valido.', 'hotel-in-cloud'),
            ], 403);
        }

        if (!\current_user_can('manage_options')) {
            \wp_send_json_error([
                'message' => \__('Permessi insufficienti.', 'hotel-in-cloud'),
            ], 403);
        }

        \delete_option(self::OPTION_BREVO_TEST_HISTORY);
        \wp_send_json_success([
            'message' => \__('Storico test svuotato.', 'hotel-in-cloud'),
        ]);
    }

    /**
     * Esegue test live completo su Brevo.
     *
     * @return array<string,mixed>|\WP_Error
     */
    private static function runBrevoConnectionTest()
    {
        $api_key = \sanitize_text_field((string) \get_option(self::OPTION_BREVO_API_KEY, ''));
        if ($api_key === '') {
            return new \WP_Error('hic_brevo_missing_key', \__('Brevo API Key principale mancante.', 'hotel-in-cloud'));
        }

        $event_mode = self::sanitizeEventMode((string) \get_option(self::OPTION_BREVO_EVENT_MODE, 'v3'));
        $event_key = \sanitize_text_field((string) \get_option(self::OPTION_BREVO_EVENT_API_KEY, ''));
        if ($event_key === '') {
            $event_key = $api_key;
        }

        $test_email = 'hic-test-' . \gmdate('YmdHis') . '@example.invalid';
        $details = [];

        $account_check = \wp_remote_get('https://api.brevo.com/v3/account', [
            'headers' => [
                'accept' => 'application/json',
                'api-key' => $api_key,
            ],
            'timeout' => 20,
        ]);
        $account_result = self::validateBrevoResponse($account_check, 'account');
        if (\is_wp_error($account_result)) {
            return $account_result;
        }
        $details['account'] = 'ok';

        $contact_payload = [
            'email' => $test_email,
            'attributes' => [
                'FIRSTNAME' => 'HIC',
                'LASTNAME' => 'TEST',
            ],
            'updateEnabled' => true,
        ];

        $contact_check = \wp_remote_post('https://api.brevo.com/v3/contacts', [
            'headers' => [
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'api-key' => $api_key,
            ],
            'body' => \wp_json_encode($contact_payload),
            'timeout' => 20,
        ]);
        $contact_result = self::validateBrevoResponse($contact_check, 'contact_test');
        if (\is_wp_error($contact_result)) {
            return $contact_result;
        }
        $details['contact'] = 'ok';

        if ($event_mode === 'legacy') {
            $legacy_endpoint = (string) \get_option(self::OPTION_BREVO_EVENT_ENDPOINT, 'https://in-automate.brevo.com/api/v2/trackEvent');
            if ($legacy_endpoint === '') {
                $legacy_endpoint = 'https://in-automate.brevo.com/api/v2/trackEvent';
            }

            $event_payload = [
                'event' => 'hic_connection_test',
                'email' => $test_email,
                'properties' => [
                    'source' => 'fp-hic-monitor',
                    'timestamp' => \gmdate('c'),
                ],
            ];

            $event_check = \wp_remote_post($legacy_endpoint, [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                    'ma-key' => $event_key,
                ],
                'body' => \wp_json_encode($event_payload),
                'timeout' => 20,
            ]);
            $event_result = self::validateBrevoResponse($event_check, 'event_legacy_test');
            if (\is_wp_error($event_result)) {
                return $event_result;
            }
            $details['event'] = 'ok_legacy';
        } else {
            $v3_endpoint = (string) \get_option(self::OPTION_BREVO_EVENT_ENDPOINT, 'https://api.brevo.com/v3/events');
            if ($v3_endpoint === '') {
                $v3_endpoint = 'https://api.brevo.com/v3/events';
            }

            $event_payload = [
                'event_name' => 'hic_connection_test',
                'event_date' => \gmdate('c'),
                'identifiers' => [
                    'email_id' => $test_email,
                ],
                'event_properties' => [
                    'source' => 'fp-hic-monitor',
                    'timestamp' => \gmdate('c'),
                ],
            ];

            $event_check = \wp_remote_post($v3_endpoint, [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                    'api-key' => $event_key,
                ],
                'body' => \wp_json_encode($event_payload),
                'timeout' => 20,
            ]);
            $event_result = self::validateBrevoResponse($event_check, 'event_v3_test');
            if (\is_wp_error($event_result)) {
                return $event_result;
            }
            $details['event'] = 'ok_v3';
        }

        \wp_remote_request('https://api.brevo.com/v3/contacts/' . \rawurlencode($test_email), [
            'method' => 'DELETE',
            'headers' => [
                'accept' => 'application/json',
                'api-key' => $api_key,
            ],
            'timeout' => 20,
        ]);

        return [
            'message' => \__('Connessione Brevo valida: account, contatto ed evento confermati.', 'hotel-in-cloud'),
            'details' => $details,
        ];
    }

    /**
     * Renderizza tabella storico ultimi test Brevo.
     */
    private static function renderBrevoTestHistory(): void
    {
        $history = self::getBrevoTestHistory();

        echo '<h4>' . \esc_html__('Storico ultimi test connessione', 'hotel-in-cloud') . '</h4>';

        if (empty($history)) {
            echo '<p class="description">' . \esc_html__('Nessun test eseguito finora.', 'hotel-in-cloud') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:980px;">';
        echo '<thead><tr>';
        echo '<th>' . \esc_html__('Data/Ora', 'hotel-in-cloud') . '</th>';
        echo '<th>' . \esc_html__('Esito', 'hotel-in-cloud') . '</th>';
        echo '<th>' . \esc_html__('Messaggio', 'hotel-in-cloud') . '</th>';
        echo '<th>' . \esc_html__('Dettagli', 'hotel-in-cloud') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($history as $entry) {
            $created_at = isset($entry['created_at']) ? (string) $entry['created_at'] : '';
            $success = !empty($entry['success']);
            $message = isset($entry['message']) ? (string) $entry['message'] : '';
            $details = isset($entry['details']) && \is_array($entry['details']) ? $entry['details'] : [];

            echo '<tr>';
            echo '<td>' . \esc_html($created_at) . '</td>';
            echo '<td>' . ($success ? '<span style="color:#0a7a2f;"><strong>OK</strong></span>' : '<span style="color:#b32d2e;"><strong>ERRORE</strong></span>') . '</td>';
            echo '<td>' . \esc_html($message) . '</td>';
            echo '<td><code>' . \esc_html(\wp_json_encode($details)) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Aggiunge una riga allo storico test (max 20).
     *
     * @param array<string,mixed> $details
     */
    private static function appendBrevoTestHistory(bool $success, string $message, array $details = []): void
    {
        $history = self::getBrevoTestHistory();

        \array_unshift($history, [
            'created_at' => \current_time('mysql'),
            'success' => $success,
            'message' => \sanitize_text_field($message),
            'details' => $details,
        ]);

        if (\count($history) > 20) {
            $history = \array_slice($history, 0, 20);
        }

        \update_option(self::OPTION_BREVO_TEST_HISTORY, $history, false);
    }

    /**
     * Recupera storico test normalizzato.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function getBrevoTestHistory(): array
    {
        $history = \get_option(self::OPTION_BREVO_TEST_HISTORY, []);
        if (!\is_array($history)) {
            return [];
        }

        $normalized = [];
        foreach ($history as $entry) {
            if (\is_array($entry)) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * Salva ultimo payload HIC in forma mascherata.
     *
     * @param array<string,mixed> $raw_payload
     * @param array<string,mixed> $normalized_booking
     */
    private static function storeLastHicPayload(array $raw_payload, array $normalized_booking): void
    {
        $stored = [
            'received_at' => \current_time('mysql'),
            'raw_payload_masked' => self::maskSensitiveData($raw_payload),
            'normalized_booking_masked' => self::maskSensitiveData($normalized_booking),
        ];

        \update_option(self::OPTION_LAST_HIC_PAYLOAD, $stored, false);
    }

    /**
     * Renderizza pannello con ultimo payload ricevuto.
     */
    private static function renderLastPayloadPanel(): void
    {
        echo '<h4>' . \esc_html__('Ultimo payload HIC ricevuto', 'hotel-in-cloud') . '</h4>';

        $stored = \get_option(self::OPTION_LAST_HIC_PAYLOAD, []);
        if (!\is_array($stored) || empty($stored)) {
            echo '<p class="description">' . \esc_html__('Nessun payload ricevuto finora.', 'hotel-in-cloud') . '</p>';
            return;
        }

        $received_at = isset($stored['received_at']) ? (string) $stored['received_at'] : '';
        $raw = isset($stored['raw_payload_masked']) && \is_array($stored['raw_payload_masked']) ? $stored['raw_payload_masked'] : [];
        $normalized = isset($stored['normalized_booking_masked']) && \is_array($stored['normalized_booking_masked']) ? $stored['normalized_booking_masked'] : [];

        echo '<p><strong>' . \esc_html__('Ricevuto il', 'hotel-in-cloud') . ':</strong> ' . \esc_html($received_at) . '</p>';
        echo '<table class="widefat striped" style="max-width:980px;">';
        echo '<thead><tr>';
        echo '<th>' . \esc_html__('Sezione', 'hotel-in-cloud') . '</th>';
        echo '<th>' . \esc_html__('Dati', 'hotel-in-cloud') . '</th>';
        echo '</tr></thead><tbody>';
        echo '<tr><td>' . \esc_html__('Payload raw (mascherato)', 'hotel-in-cloud') . '</td><td><code>' . \esc_html(\wp_json_encode($raw)) . '</code></td></tr>';
        echo '<tr><td>' . \esc_html__('Payload normalizzato (mascherato)', 'hotel-in-cloud') . '</td><td><code>' . \esc_html(\wp_json_encode($normalized)) . '</code></td></tr>';
        echo '</tbody></table>';
    }

    /**
     * Maschera dati sensibili in array ricorsivo.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function maskSensitiveData($value)
    {
        if (\is_array($value)) {
            $masked = [];
            foreach ($value as $key => $item) {
                $key_string = \is_string($key) ? \strtolower($key) : '';

                if (\in_array($key_string, ['email', 'customer_email'], true) && \is_scalar($item)) {
                    $masked[$key] = self::maskEmail((string) $item);
                    continue;
                }

                if (\in_array($key_string, ['phone', 'mobile', 'whatsapp', 'customer_phone'], true) && \is_scalar($item)) {
                    $masked[$key] = self::maskPhone((string) $item);
                    continue;
                }

                if (\in_array($key_string, ['first_name', 'last_name', 'lastname', 'guest_first_name', 'guest_last_name', 'customer_first_name', 'customer_last_name'], true) && \is_scalar($item)) {
                    $masked[$key] = self::maskGeneric((string) $item);
                    continue;
                }

                $masked[$key] = self::maskSensitiveData($item);
            }

            return $masked;
        }

        return $value;
    }

    /**
     * Maschera email mantenendo il dominio.
     */
    private static function maskEmail(string $email): string
    {
        $email = \trim($email);
        if ($email === '' || \strpos($email, '@') === false) {
            return '***';
        }

        [$local, $domain] = \explode('@', $email, 2);
        $local_masked = \strlen($local) > 2 ? \substr($local, 0, 2) . '***' : '***';

        return $local_masked . '@' . $domain;
    }

    /**
     * Maschera telefono mantenendo le ultime due cifre.
     */
    private static function maskPhone(string $phone): string
    {
        $digits = \preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '***';
        }

        if (\strlen($digits) <= 2) {
            return '***';
        }

        return '***' . \substr($digits, -2);
    }

    /**
     * Maschera stringhe generiche.
     */
    private static function maskGeneric(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\strlen($value) <= 2) {
            return '**';
        }

        return \substr($value, 0, 1) . '***';
    }
}
