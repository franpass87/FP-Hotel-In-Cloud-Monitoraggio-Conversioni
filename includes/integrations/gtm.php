<?php declare(strict_types=1);
namespace FpHic;
/**
 * Google Tag Manager Integration
 */

if (!defined('ABSPATH')) exit;

/**
 * Send conversion data to GTM Data Layer
 * This pushes data to the client-side dataLayer for GTM to process
 */
function hic_send_to_gtm_datalayer($data, $gclid, $fbclid, $sid = null) {
    // Only proceed if GTM is enabled
    if (!Helpers\hic_is_gtm_enabled()) {
        return false;
    }

    // Validate input data
    if (!is_array($data)) {
        hic_log('GTM DataLayer: data is not an array');
        return false;
    }

    $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid); // gads | fbads | organic
    $sid = !empty($sid) ? sanitize_text_field($sid) : '';

    // Validate and normalize amount
    $amount = 0;
    if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
        $amount = Helpers\hic_normalize_price($data['amount']);
    }

    // Generate transaction ID using consistent extraction
    $transaction_id = Helpers\hic_extract_reservation_id($data);
    if (empty($transaction_id)) {
        $transaction_id = uniqid('hic_gtm_');
    }

    // Prepare enhanced ecommerce data for GTM
    $gtm_data = [
        'event' => 'purchase',
        'ecommerce' => [
            'transaction_id' => $transaction_id,
            'affiliation' => 'HotelInCloud',
            'value' => $amount,
            'currency' => sanitize_text_field($data['currency'] ?? 'EUR'),
            'items' => [[
                'item_id' => $transaction_id,
                'item_name' => sanitize_text_field($data['room'] ?? 'Prenotazione'),
                'item_category' => 'Hotel',
                'quantity' => 1,
                'price' => $amount
            ]]
        ],
        // Custom dimensions for attribution
        'bucket' => $bucket,
        'vertical' => 'hotel',
        'method' => 'HotelInCloud'
    ];

    if ($sid !== '') {
        $gtm_data['client_id'] = $sid;
        $gtm_data['sid'] = $sid;
    }

    // Add tracking IDs if available for enhanced attribution
    if (!empty($gclid)) {
        $gtm_data['gclid'] = sanitize_text_field($gclid);
    }
    if (!empty($fbclid)) {
        $gtm_data['fbclid'] = sanitize_text_field($fbclid);
    }

    // Include UTM parameters if available
    if ($sid !== '') {
        $utm = Helpers\hic_get_utm_params_by_sid($sid);
        if (!empty($utm['utm_source']))   { $gtm_data['utm_source']   = sanitize_text_field($utm['utm_source']); }
        if (!empty($utm['utm_medium']))   { $gtm_data['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
        if (!empty($utm['utm_campaign'])) { $gtm_data['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
        if (!empty($utm['utm_content']))  { $gtm_data['utm_content']  = sanitize_text_field($utm['utm_content']); }
        if (!empty($utm['utm_term']))     { $gtm_data['utm_term']     = sanitize_text_field($utm['utm_term']); }
    }

    // Store the data to be pushed to dataLayer on next page load
    hic_queue_gtm_event($gtm_data, $sid);

    hic_log("GTM DataLayer: queued purchase event for transaction_id=$transaction_id, bucket=$bucket, value=$amount");
    return true;
}

/**
 * Queue GTM event for client-side processing
 * Events are stored in wp_options and pushed on next page load
 */
function hic_queue_gtm_event($event_data, $sid = '') {
    if (!is_array($event_data)) {
        return;
    }

    $sid = !empty($sid) ? sanitize_text_field((string) $sid) : '';
    if ($sid === '') {
        hic_log('GTM queue: evento ignorato perché SID mancante o non valido', HIC_LOG_LEVEL_DEBUG);
        return;
    }

    $option_key = hic_get_gtm_queue_option_key($sid);
    if ($option_key === '') {
        return;
    }

    $queued_events = get_option($option_key, []);
    if (!is_array($queued_events)) {
        $queued_events = [];
    }

    // Add timestamp to avoid conflicts
    $event_data['event_timestamp'] = current_time('timestamp');

    $queued_events[] = $event_data;

    // Keep only last 10 events to avoid database bloat
    if (count($queued_events) > 10) {
        $queued_events = array_slice($queued_events, -10);
    }

    update_option($option_key, $queued_events, false);
    Helpers\hic_clear_option_cache($option_key);
}

/**
 * Build the option key used to store GTM events for a specific SID
 */
function hic_get_gtm_queue_option_key(string $sid): string {
    $sid = sanitize_text_field($sid);
    if ($sid === '') {
        return '';
    }

    return 'hic_gtm_queue_' . hash('sha256', $sid);
}

/**
 * Get and clear queued GTM events for a specific SID
 */
function hic_get_and_clear_gtm_events_for_sid($sid) {
    $sid = !empty($sid) ? sanitize_text_field((string) $sid) : '';
    if ($sid === '') {
        return [];
    }

    $option_key = hic_get_gtm_queue_option_key($sid);
    if ($option_key === '') {
        return [];
    }

    $events = get_option($option_key, []);
    if (!is_array($events)) {
        $events = [];
    }

    if (!empty($events)) {
        delete_option($option_key);
        Helpers\hic_clear_option_cache($option_key);
    }

    return $events;
}

/**
 * Legacy helper maintained for backward compatibility
 */
function hic_get_and_clear_gtm_events() {
    delete_option('hic_gtm_queued_events');
    Helpers\hic_clear_option_cache('hic_gtm_queued_events');
    return [];
}

/**
 * Output GTM container code in <head>
 */
function hic_output_gtm_head_code() {
    if (!Helpers\hic_is_gtm_enabled()) {
        return;
    }
    
    $container_id = Helpers\hic_get_gtm_container_id();
    if (empty($container_id)) {
        return;
    }
    
    // Validate GTM container ID format
    if (!preg_match('/^GTM-[A-Z0-9]+$/', $container_id)) {
        hic_log("GTM: Invalid container ID format: $container_id");
        return;
    }
    
    ?>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','<?php echo esc_js($container_id); ?>');</script>
    <!-- End Google Tag Manager -->
    <?php
}

/**
 * Output GTM noscript code in <body>
 */
function hic_output_gtm_body_code() {
    if (!Helpers\hic_is_gtm_enabled()) {
        return;
    }
    
    $container_id = Helpers\hic_get_gtm_container_id();
    if (empty($container_id) || !preg_match('/^GTM-[A-Z0-9]+$/', $container_id)) {
        return;
    }
    
    ?>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($container_id); ?>"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <?php
}

/**
 * Output queued GTM events to dataLayer
 */
function hic_output_gtm_events() {
    // Gli eventi GTM vengono ora consegnati tramite endpoint REST dedicato.
}

/**
 * Hook GTM scripts into WordPress
 */
function hic_init_gtm_hooks() {
    if (!Helpers\hic_is_gtm_enabled()) {
        return;
    }
    
    // Add GTM head code
    add_action('wp_head', 'hic_output_gtm_head_code', 1);
    
    // Add GTM body code (early in body)
    add_action('wp_body_open', 'hic_output_gtm_body_code', 1);
    
    // Fallback for themes that don't support wp_body_open
    add_action('wp_footer', function() {
        if (!did_action('wp_body_open')) {
            hic_output_gtm_body_code();
        }
    }, 1);
    
}

/**
 * Register REST endpoint used to deliver queued GTM events
 */
function hic_register_gtm_rest_routes() {
    if (!Helpers\hic_is_gtm_enabled()) {
        return;
    }

    register_rest_route('hic/v1', '/gtm-events', [
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => __NAMESPACE__ . '\hic_handle_gtm_events_request',
        'permission_callback' => '__return_true',
        'args'                => [
            'sid' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Session ID associato alla prenotazione',
            ],
        ],
    ]);
}

/**
 * Handle REST request returning queued GTM events for a SID
 */
function hic_handle_gtm_events_request(\WP_REST_Request $request) {
    if (!Helpers\hic_is_gtm_enabled()) {
        return new \WP_REST_Response(['events' => []], 200);
    }

    $sid = $request->get_param('sid');
    if (empty($sid)) {
        return new \WP_Error('missing_sid', 'SID mancante', ['status' => 400]);
    }

    $validated_sid = HIC_Input_Validator::validate_sid($sid);
    if (is_wp_error($validated_sid)) {
        return $validated_sid;
    }

    $events = hic_get_and_clear_gtm_events_for_sid($validated_sid);

    return new \WP_REST_Response([
        'events' => array_values($events),
    ], 200);
}

// Initialize GTM hooks when WordPress is ready (safe hook registration)
\FpHic\Helpers\hic_safe_add_hook('action', 'init', 'hic_init_gtm_hooks');
\FpHic\Helpers\hic_safe_add_hook('action', 'rest_api_init', 'hic_register_gtm_rest_routes');

/**
 * GTM dispatcher for HIC reservation schema
 * Similar to GA4 dispatcher but for GTM DataLayer
 */
function hic_dispatch_gtm_reservation($data, $sid = '') {
    // Only proceed if GTM is enabled
    if (!Helpers\hic_is_gtm_enabled()) {
        return false;
    }

    // Validate input data
    if (!is_array($data)) {
        hic_log('GTM dispatch: data is not an array');
        return false;
    }

    // Validate required fields
    $required_fields = ['transaction_id', 'value', 'currency'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field])) {
            hic_log("GTM dispatch: Missing required field '$field'");
            return false;
        }
    }

    $transaction_id = sanitize_text_field($data['transaction_id']);
    $value = Helpers\hic_normalize_price($data['value']);
    $currency = sanitize_text_field($data['currency']);

    $sid = !empty($sid) ? \sanitize_text_field((string) $sid) : '';
    if ($sid === '' && !empty($data['sid']) && is_scalar($data['sid'])) {
        $sid = \sanitize_text_field((string) $data['sid']);
    }

    // Get gclid/fbclid for bucket normalization if available
    $gclid = '';
    $fbclid = '';
    $lookup_id = $sid !== '' ? $sid : $transaction_id;
    if (!empty($lookup_id)) {
        $tracking = Helpers\hic_get_tracking_ids_by_sid($lookup_id);
        $gclid = $tracking['gclid'] ?? '';
        $fbclid = $tracking['fbclid'] ?? '';
    }

    $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid);

    // Prepare GTM ecommerce data
    $gtm_data = [
        'event' => 'purchase',
        'ecommerce' => [
            'transaction_id' => $transaction_id,
            'affiliation' => 'HotelInCloud',
            'value' => $value,
            'currency' => $currency,
            'items' => [[
                'item_id' => sanitize_text_field($data['accommodation_id'] ?? ''),
                'item_name' => sanitize_text_field($data['accommodation_name'] ?? 'Accommodation'),
                'item_category' => sanitize_text_field($data['room_name'] ?? 'Hotel'),
                'quantity' => max(1, intval($data['guests'] ?? 1)),
                'price' => $value
            ]]
        ],
        // Custom properties
        'checkin' => sanitize_text_field($data['from_date'] ?? ''),
        'checkout' => sanitize_text_field($data['to_date'] ?? ''),
        'reservation_code' => sanitize_text_field($data['reservation_code'] ?? ''),
        'presence' => sanitize_text_field($data['presence'] ?? ''),
        'unpaid_balance' => Helpers\hic_normalize_price($data['unpaid_balance'] ?? 0),
        'bucket' => $bucket,
        'vertical' => 'hotel'
    ];

    if ($sid !== '') {
        $gtm_data['client_id'] = $sid;
        $gtm_data['hic_sid'] = $sid;
    }

    // Add tracking IDs if available
    if (!empty($gclid)) {
        $gtm_data['gclid'] = sanitize_text_field($gclid);
    }
    if (!empty($fbclid)) {
        $gtm_data['fbclid'] = sanitize_text_field($fbclid);
    }

    $utm_lookup = $sid !== '' ? $sid : $transaction_id;
    $utm = Helpers\hic_get_utm_params_by_sid($utm_lookup);
    if (!empty($utm['utm_source']))   { $gtm_data['utm_source']   = sanitize_text_field($utm['utm_source']); }
    if (!empty($utm['utm_medium']))   { $gtm_data['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
    if (!empty($utm['utm_campaign'])) { $gtm_data['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
    if (!empty($utm['utm_content']))  { $gtm_data['utm_content']  = sanitize_text_field($utm['utm_content']); }
    if (!empty($utm['utm_term']))     { $gtm_data['utm_term']     = sanitize_text_field($utm['utm_term']); }

    // Queue the event
    hic_queue_gtm_event($gtm_data, $sid);

    hic_log("GTM dispatch: queued purchase event for bucket=$bucket vertical=hotel transaction_id=$transaction_id value=$value $currency");
    return true;
}