<?php
/**
 * Plugin Name: HIC GA4 + Brevo + Meta (bucket strategy)
 * Description: Tracciamento prenotazioni Hotel in Cloud → GA4 (purchase), Meta CAPI (Purchase) e Brevo (contact+event), con bucket gads/fbads/organic. Salvataggio gclid/fbclid↔sid e append sid ai link HIC.
 * Version: 1.4.0
 * Author: Francesco Passeri
 */

if (!defined('ABSPATH')) exit;

/* ================= CONFIG FUNCTIONS ================= */
function hic_get_option($key, $default = '') {
    return get_option('hic_' . $key, $default);
}

// Helper functions to get configuration values
function hic_get_measurement_id() { return hic_get_option('measurement_id', 'G-Z8PWZ8DGKQ'); }
function hic_get_api_secret() { return hic_get_option('api_secret', 'TIeYY5JwSgKtjB9jCJphOg'); }
function hic_get_brevo_api_key() { return hic_get_option('brevo_api_key', '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'); }
function hic_get_brevo_list_ita() { return hic_get_option('brevo_list_ita', '20'); }
function hic_get_brevo_list_eng() { return hic_get_option('brevo_list_eng', '21'); }
function hic_get_fb_pixel_id() { return hic_get_option('fb_pixel_id', '893129609196001'); }
function hic_get_fb_access_token() { return hic_get_option('fb_access_token', 'EAAO4m2qD0ZCcBPCFbm51lch1dto4kyCScoDwR7e2YhX8WWWocr7iggNG7ZAKWqtP8ylWXlFSc5ln77IlrjXilRX7ISrh51jZBzrWQWE3MCGeD6zJf67to3sXnaVPenfVl8DUPrB5hr0JTZBxZBv1OcCEtQyVvSTyou2ywu9qg6IBUhWXsfr8EcMlkcdvSeBamHQZDZD'); }
function hic_get_webhook_token() { return hic_get_option('webhook_token', 'hic2025ga4'); }
function hic_get_admin_email() { return hic_get_option('admin_email', 'francesco.passeri@gmail.com'); }
function hic_get_log_file() { return hic_get_option('log_file', WP_CONTENT_DIR . '/hic-ga4.log'); }
function hic_is_brevo_enabled() { return hic_get_option('brevo_enabled', '1') === '1'; }
function hic_get_connection_type() { return hic_get_option('connection_type', 'webhook'); }
function hic_get_api_url() { return hic_get_option('api_url', ''); }
function hic_get_api_key() { return hic_get_option('api_key', ''); }

/* ========================================== */

/* ============ Admin Settings Page ============ */
add_action('admin_menu', 'hic_add_admin_menu');
add_action('admin_init', 'hic_settings_init');

function hic_add_admin_menu() {
    add_options_page(
        'HIC Monitoring Settings',
        'HIC Monitoring',
        'manage_options',
        'hic-monitoring',
        'hic_options_page'
    );
}

function hic_settings_init() {
    register_setting('hic_settings', 'hic_measurement_id');
    register_setting('hic_settings', 'hic_api_secret');
    register_setting('hic_settings', 'hic_brevo_enabled');
    register_setting('hic_settings', 'hic_brevo_api_key');
    register_setting('hic_settings', 'hic_brevo_list_ita');
    register_setting('hic_settings', 'hic_brevo_list_eng');
    register_setting('hic_settings', 'hic_fb_pixel_id');
    register_setting('hic_settings', 'hic_fb_access_token');
    register_setting('hic_settings', 'hic_webhook_token');
    register_setting('hic_settings', 'hic_admin_email');
    register_setting('hic_settings', 'hic_log_file');
    register_setting('hic_settings', 'hic_connection_type');
    register_setting('hic_settings', 'hic_api_url');
    register_setting('hic_settings', 'hic_api_key');

    add_settings_section('hic_main_section', 'Configurazione Principale', null, 'hic_settings');
    add_settings_section('hic_ga4_section', 'Google Analytics 4', null, 'hic_settings');
    add_settings_section('hic_brevo_section', 'Brevo Settings', null, 'hic_settings');
    add_settings_section('hic_fb_section', 'Facebook Meta', null, 'hic_settings');
    add_settings_section('hic_hic_section', 'Hotel in Cloud', null, 'hic_settings');

    // Main settings
    add_settings_field('hic_admin_email', 'Email Amministratore', 'hic_admin_email_render', 'hic_settings', 'hic_main_section');
    add_settings_field('hic_log_file', 'File di Log', 'hic_log_file_render', 'hic_settings', 'hic_main_section');
    
    // GA4 settings
    add_settings_field('hic_measurement_id', 'Measurement ID', 'hic_measurement_id_render', 'hic_settings', 'hic_ga4_section');
    add_settings_field('hic_api_secret', 'API Secret', 'hic_api_secret_render', 'hic_settings', 'hic_ga4_section');
    
    // Brevo settings
    add_settings_field('hic_brevo_enabled', 'Abilita Brevo', 'hic_brevo_enabled_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_api_key', 'API Key', 'hic_brevo_api_key_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_list_ita', 'Lista Italiana', 'hic_brevo_list_ita_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_list_eng', 'Lista Inglese', 'hic_brevo_list_eng_render', 'hic_settings', 'hic_brevo_section');
    
    // Facebook settings
    add_settings_field('hic_fb_pixel_id', 'Pixel ID', 'hic_fb_pixel_id_render', 'hic_settings', 'hic_fb_section');
    add_settings_field('hic_fb_access_token', 'Access Token', 'hic_fb_access_token_render', 'hic_settings', 'hic_fb_section');
    
    // Hotel in Cloud settings
    add_settings_field('hic_connection_type', 'Tipo Connessione', 'hic_connection_type_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_webhook_token', 'Webhook Token', 'hic_webhook_token_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_api_url', 'API URL', 'hic_api_url_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_api_key', 'API Key', 'hic_api_key_render', 'hic_settings', 'hic_hic_section');
}

function hic_options_page() {
    ?>
    <div class="wrap">
        <h1>Hotel in Cloud - Monitoraggio Conversioni</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('hic_settings');
            do_settings_sections('hic_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Render functions for settings fields
function hic_admin_email_render() {
    echo '<input type="email" name="hic_admin_email" value="' . esc_attr(hic_get_admin_email()) . '" class="regular-text" />';
}

function hic_log_file_render() {
    echo '<input type="text" name="hic_log_file" value="' . esc_attr(hic_get_log_file()) . '" class="regular-text" />';
}

function hic_measurement_id_render() {
    echo '<input type="text" name="hic_measurement_id" value="' . esc_attr(hic_get_measurement_id()) . '" class="regular-text" />';
}

function hic_api_secret_render() {
    echo '<input type="text" name="hic_api_secret" value="' . esc_attr(hic_get_api_secret()) . '" class="regular-text" />';
}

function hic_brevo_enabled_render() {
    $checked = hic_is_brevo_enabled() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_brevo_enabled" value="1" ' . $checked . ' /> Abilita integrazione Brevo';
}

function hic_brevo_api_key_render() {
    echo '<input type="password" name="hic_brevo_api_key" value="' . esc_attr(hic_get_brevo_api_key()) . '" class="regular-text" />';
}

function hic_brevo_list_ita_render() {
    echo '<input type="number" name="hic_brevo_list_ita" value="' . esc_attr(hic_get_brevo_list_ita()) . '" />';
}

function hic_brevo_list_eng_render() {
    echo '<input type="number" name="hic_brevo_list_eng" value="' . esc_attr(hic_get_brevo_list_eng()) . '" />';
}

function hic_fb_pixel_id_render() {
    echo '<input type="text" name="hic_fb_pixel_id" value="' . esc_attr(hic_get_fb_pixel_id()) . '" class="regular-text" />';
}

function hic_fb_access_token_render() {
    echo '<input type="password" name="hic_fb_access_token" value="' . esc_attr(hic_get_fb_access_token()) . '" class="regular-text" />';
}

function hic_connection_type_render() {
    $type = hic_get_connection_type();
    echo '<select name="hic_connection_type">';
    echo '<option value="webhook"' . selected($type, 'webhook', false) . '>Webhook</option>';
    echo '<option value="api"' . selected($type, 'api', false) . '>API Polling</option>';
    echo '</select>';
}

function hic_webhook_token_render() {
    echo '<input type="text" name="hic_webhook_token" value="' . esc_attr(hic_get_webhook_token()) . '" class="regular-text" />';
    echo '<p class="description">Token per autenticare il webhook</p>';
}

function hic_api_url_render() {
    echo '<input type="url" name="hic_api_url" value="' . esc_attr(hic_get_api_url()) . '" class="regular-text" />';
    echo '<p class="description">URL delle API Hotel in Cloud (solo se si usa API Polling)</p>';
}

function hic_api_key_render() {
    echo '<input type="password" name="hic_api_key" value="' . esc_attr(hic_get_api_key()) . '" class="regular-text" />';
    echo '<p class="description">API Key per Hotel in Cloud (solo se si usa API Polling)</p>';
}

/* ============ Helpers ============ */
function hic_log($msg){
  $date = date('Y-m-d H:i:s');
  $line = '['.$date.'] ' . (is_scalar($msg) ? $msg : print_r($msg, true)) . "\n";
  @file_put_contents(hic_get_log_file(), $line, FILE_APPEND);
}

function hic_get_bucket($gclid, $fbclid){
  if (!empty($gclid))  return 'gads';
  if (!empty($fbclid)) return 'fbads';
  return 'organic';
}

/* ============ DB: tabella sid↔gclid/fbclid ============ */
register_activation_hook(__FILE__, function(){
  global $wpdb;
  $table   = $wpdb->prefix . 'hic_gclids';
  $charset = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    gclid  VARCHAR(255),
    fbclid VARCHAR(255),
    sid    VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY gclid (gclid(100)),
    KEY fbclid (fbclid(100)),
    KEY sid (sid(100))
  ) $charset;";
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
  hic_log('DB ready: '.$table);
});

/* ============ Cattura gclid/fbclid → cookie + DB ============ */
add_action('init', function(){
  global $wpdb;
  $table = $wpdb->prefix . 'hic_gclids';

  if (!empty($_GET['gclid'])) {
    $gclid = sanitize_text_field($_GET['gclid']);
    setcookie('hic_sid', $gclid, time() + 60*60*24*30, '/', '', is_ssl(), true);
    $_COOKIE['hic_sid'] = $gclid;
    $wpdb->insert($table, ['gclid'=>$gclid, 'sid'=>$gclid]);
    hic_log("GCLID salvato → $gclid");
  }

  if (!empty($_GET['fbclid'])) {
    $fbclid = sanitize_text_field($_GET['fbclid']);
    setcookie('hic_sid', $fbclid, time() + 60*60*24*30, '/', '', is_ssl(), true);
    $_COOKIE['hic_sid'] = $fbclid;
    $wpdb->insert($table, ['fbclid'=>$fbclid, 'sid'=>$fbclid]);
    hic_log("FBCLID salvato → $fbclid");
  }
});

/* ============ REST: webhook HIC ============ */
/* Configura in Hotel in Cloud:
   https://www.villadianella.it/wp-json/hic/v1/conversion?token=hic2025ga4
*/
add_action('rest_api_init', function () {
  // Solo se siamo in modalità webhook
  if (hic_get_connection_type() === 'webhook') {
    register_rest_route('hic/v1', '/conversion', [
      'methods'             => 'POST',
      'callback'            => 'hic_webhook_handler',
      'permission_callback' => '__return_true',
    ]);
  }
});

/* ============ API Polling HIC ============ */
// Se selezionato API Polling, configura il cron
add_action('init', function() {
  if (hic_get_connection_type() === 'api' && hic_get_api_url() && hic_get_api_key()) {
    if (!wp_next_scheduled('hic_api_poll_event')) {
      wp_schedule_event(time(), 'hic_poll_interval', 'hic_api_poll_event');
    }
  } else {
    // Rimuovi il cron se non è più necessario
    $timestamp = wp_next_scheduled('hic_api_poll_event');
    if ($timestamp) {
      wp_unschedule_event($timestamp, 'hic_api_poll_event');
    }
  }
});

// Aggiungi intervallo personalizzato per il polling
add_filter('cron_schedules', function($schedules) {
  $schedules['hic_poll_interval'] = array(
    'interval' => 300, // 5 minuti
    'display' => 'Ogni 5 minuti (HIC Polling)'
  );
  return $schedules;
});

// Funzione di polling API
add_action('hic_api_poll_event', 'hic_api_poll_bookings');

function hic_api_poll_bookings() {
  $api_url = hic_get_api_url();
  $api_key = hic_get_api_key();
  
  if (!$api_url || !$api_key) {
    hic_log('API polling: URL o API key mancanti');
    return;
  }

  // Ottieni l'ultimo timestamp processato
  $last_poll = get_option('hic_last_api_poll', strtotime('-1 hour'));
  $current_time = time();

  // Costruisci l'URL API con filtri temporali
  $poll_url = add_query_arg([
    'api_key' => $api_key,
    'from' => date('Y-m-d H:i:s', $last_poll),
    'to' => date('Y-m-d H:i:s', $current_time)
  ], rtrim($api_url, '/') . '/bookings');

  $response = wp_remote_get($poll_url, [
    'timeout' => 30,
    'headers' => [
      'Accept' => 'application/json',
      'User-Agent' => 'WordPress/HIC-Plugin'
    ]
  ]);

  if (is_wp_error($response)) {
    hic_log('API polling errore: ' . $response->get_error_message());
    return;
  }

  $code = wp_remote_retrieve_response_code($response);
  if ($code !== 200) {
    hic_log("API polling HTTP error: $code");
    return;
  }

  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body, true);

  if (!$data || !is_array($data)) {
    hic_log('API polling: risposta non valida');
    return;
  }

  hic_log("API polling: trovate " . count($data) . " prenotazioni");

  // Processa ogni prenotazione
  foreach ($data as $booking) {
    hic_process_booking_data($booking);
  }

  // Aggiorna il timestamp dell'ultimo polling
  update_option('hic_last_api_poll', $current_time);
}

// Funzione comune per processare i dati di prenotazione (sia webhook che API)
function hic_process_booking_data($data) {
  $sid    = $data['sid'] ?? null;
  $gclid  = null;
  $fbclid = null;

  if ($sid) {
    global $wpdb;
    $table = $wpdb->prefix . 'hic_gclids';
    $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));
    if ($row) { $gclid = $row->gclid; $fbclid = $row->fbclid; }
  }

  // Invii
  hic_send_to_ga4($data, $gclid, $fbclid);
  hic_send_to_fb($data, $gclid, $fbclid);
  if (hic_is_brevo_enabled()) {
    hic_send_brevo_contact($data, $gclid, $fbclid);
    hic_send_brevo_event($data, $gclid, $fbclid);
  }
  hic_send_admin_email($data, $gclid, $fbclid, $sid);
}

function hic_webhook_handler(WP_REST_Request $request) {
  $token = $request->get_param('token');
  if ($token !== hic_get_webhook_token()) {
    hic_log('Webhook rifiutato: token invalido');
    return new WP_Error('invalid_token','Token non valido',['status'=>403]);
  }

  $data = $request->get_json_params();
  if (!$data) {
    hic_log('Webhook senza payload');
    return new WP_REST_Response(['error'=>'no data'], 400);
  }
  hic_log(['Webhook ricevuto' => $data]);

  $sid    = $data['sid'] ?? null; // passa tramite ?sid= nell’URL del booking
  $gclid  = null;
  $fbclid = null;

  if ($sid) {
    global $wpdb;
    $table = $wpdb->prefix . 'hic_gclids';
    $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));
    if ($row) { $gclid = $row->gclid; $fbclid = $row->fbclid; }
  }

  // Invii
  hic_send_to_ga4($data, $gclid, $fbclid);
  hic_send_to_fb($data, $gclid, $fbclid);
  if (hic_is_brevo_enabled()) {
    hic_send_brevo_contact($data, $gclid, $fbclid);
    hic_send_brevo_event($data, $gclid, $fbclid);
  }
  hic_send_admin_email($data, $gclid, $fbclid, $sid);

  return ['status'=>'ok'];
}

/* ============ GA4 (purchase + bucket) ============ */
function hic_send_to_ga4($data, $gclid, $fbclid) {
  $bucket    = hic_get_bucket($gclid, $fbclid); // gads | fbads | organic
  $client_id = $gclid ?: ($fbclid ?: (string) wp_generate_uuid4());

  $params = [
    'transaction_id' => $data['reservation_id'] ?? ($data['id'] ?? uniqid()),
    'currency'       => $data['currency'] ?? 'EUR',
    'value'          => isset($data['amount']) ? floatval($data['amount']) : 0,
    'items'          => [[
      'item_name' => $data['room'] ?? 'Prenotazione',
      'quantity'  => 1,
      'price'     => isset($data['amount']) ? floatval($data['amount']) : 0
    ]],
    'bucket'         => $bucket,         // <-- crea dimensione evento "bucket" in GA4
    'method'         => 'HotelInCloud',
  ];
  if (!empty($gclid))  { $params['gclid']  = $gclid; }
  if (!empty($fbclid)) { $params['fbclid'] = $fbclid; }

  $payload = [
    'client_id' => $client_id,
    'events'    => [[
      'name'   => 'purchase',            // SEMPRE purchase
      'params' => $params
    ]]
  ];

  $url = 'https://www.google-analytics.com/mp/collect?measurement_id='
       . rawurlencode(hic_get_measurement_id())
       . '&api_secret='
       . rawurlencode(hic_get_api_secret());

  $res  = wp_remote_post($url, [
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => wp_json_encode($payload),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['GA4 inviato: purchase (bucket='.$bucket.')' => $payload, 'HTTP'=>$code]);
}

/* ============ Meta CAPI (Purchase + bucket) ============ */
function hic_send_to_fb($data, $gclid, $fbclid){
  if (!hic_get_fb_pixel_id() || !hic_get_fb_access_token()) {
    hic_log('FB Pixel non configurato.');
    return;
  }
  $bucket   = hic_get_bucket($gclid, $fbclid); // gads | fbads | organic
  $event_id = $data['reservation_id'] ?? uniqid();

  $payload = [
    'data' => [[
      'event_name'       => 'Purchase',      // evento standard Meta
      'event_time'       => time(),
      'event_id'         => $event_id,
      'action_source'    => 'website',
      'event_source_url' => home_url(),
      'user_data' => [
        'em' => [ hash('sha256', strtolower(trim($data['email']      ?? ''))) ],
        'fn' => [ hash('sha256', strtolower(trim($data['first_name'] ?? ''))) ],
        'ln' => [ hash('sha256', strtolower(trim($data['last_name']  ?? ''))) ],
        // opzionale: usa whatsapp come telefono, normalizzato numerico
        'ph' => !empty($data['whatsapp']) ? [ hash('sha256', preg_replace('/\D/','', $data['whatsapp'])) ] : []
      ],
      'custom_data' => [
        'currency'     => $data['currency'] ?? 'EUR',
        'value'        => isset($data['amount']) ? floatval($data['amount']) : 0,
        'order_id'     => $event_id,
        'bucket'       => $bucket,           // per creare custom conversions per fbads/organic/gads
        'content_name' => $data['room'] ?? 'Prenotazione'
      ]
    ]]
  ];

  $url = 'https://graph.facebook.com/v19.0/' . hic_get_fb_pixel_id() . '/events?access_token=' . hic_get_fb_access_token();
  $res = wp_remote_post($url, [
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => wp_json_encode($payload),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['FB inviato: Purchase (bucket='.$bucket.')' => $payload, 'HTTP'=>$code]);
}

/* ============ Brevo: aggiorna contatto ============ */
function hic_send_brevo_contact($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { hic_log('Brevo disabilitato (API key vuota).'); return; }

  $email = $data['email'] ?? null;
  if (!$email) { hic_log('Nessuna email nel payload → skip Brevo contact.'); return; }

  // Lista in base alla lingua (supporta sia 'lingua' sia 'lang')
  $lang = $data['lingua'] ?? ($data['lang'] ?? '');
  $list_ids = [];
  if (strtolower($lang) === 'en') { $list_ids[] = intval(hic_get_brevo_list_eng()); } else { $list_ids[] = intval(hic_get_brevo_list_ita()); }

  $body = [
    'email' => $email,
    'attributes' => [
      'FIRSTNAME' => $data['first_name'] ?? '',
      'LASTNAME'  => $data['last_name'] ?? '',
      'RESVID'    => $data['reservation_id'] ?? ($data['id'] ?? ''),
      'GCLID'     => $gclid  ?? '',
      'FBCLID'    => $fbclid ?? '',
      'DATE'      => $data['date'] ?? date('Y-m-d'),
      'AMOUNT'    => isset($data['amount']) ? floatval($data['amount']) : 0,
      'CURRENCY'  => $data['currency'] ?? 'EUR',
      'WHATSAPP'  => $data['whatsapp'] ?? '',
      'LINGUA'    => $lang
    ],
    'listIds'       => $list_ids,
    'updateEnabled' => true
  ];

  $res = wp_remote_post('https://api.brevo.com/v3/contacts', [
    'headers' => [
      'accept'       => 'application/json',
      'api-key'      => hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ],
    'body'    => wp_json_encode($body),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['Brevo contact inviato' => $body, 'HTTP'=>$code]);
}

/* ============ Brevo: evento personalizzato (purchase + bucket) ============ */
function hic_send_brevo_event($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { return; }
  $bucket = hic_get_bucket($gclid, $fbclid);

  $body = [
    'event' => 'purchase', // puoi rinominare in 'hic_booking' se preferisci
    'email' => $data['email'] ?? '',
    'properties' => [
      'reservation_id' => $data['reservation_id'] ?? ($data['id'] ?? ''),
      'amount'         => isset($data['amount']) ? floatval($data['amount']) : 0,
      'currency'       => $data['currency'] ?? 'EUR',
      'date'           => $data['date'] ?? date('Y-m-d'),
      'whatsapp'       => $data['whatsapp'] ?? '',
      'lingua'         => $data['lingua'] ?? ($data['lang'] ?? ''),
      'firstname'      => $data['first_name'] ?? '',
      'lastname'       => $data['last_name'] ?? '',
      'bucket'         => $bucket
    ]
  ];

  $res = wp_remote_post('https://in-automate.brevo.com/api/v2/trackEvent', [
    'headers' => [
      'accept'       => 'application/json',
      'content-type' => 'application/json',
      'api-key'      => hic_get_brevo_api_key()
    ],
    'body'    => wp_json_encode($body),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['Brevo event: purchase (bucket='.$bucket.')' => $body, 'HTTP'=>$code]);
}

/* ============ Email admin (include bucket) ============ */
function hic_send_admin_email($data, $gclid, $fbclid, $sid){
  $bucket    = hic_get_bucket($gclid, $fbclid);
  $to        = hic_get_admin_email();
  $site_name = get_bloginfo('name');
  $subject   = "Nuova prenotazione da " . $site_name;

  $body  = "Hai ricevuto una nuova prenotazione da $site_name:\n\n";
  $body .= "Reservation ID: " . ($data['reservation_id'] ?? ($data['id'] ?? 'n/a')) . "\n";
  $body .= "Importo: " . (isset($data['amount']) ? $data['amount'] : '0') . " " . ($data['currency'] ?? 'EUR') . "\n";
  $body .= "Nome: " . trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) . "\n";
  $body .= "Email: " . ($data['email'] ?? 'n/a') . "\n";
  $body .= "Lingua: " . ($data['lingua'] ?? ($data['lang'] ?? 'n/a')) . "\n";
  $body .= "Camera: " . ($data['room'] ?? 'n/a') . "\n";
  $body .= "Check-in: " . ($data['checkin'] ?? 'n/a') . "\n";
  $body .= "Check-out: " . ($data['checkout'] ?? 'n/a') . "\n";
  $body .= "SID: " . ($sid ?? 'n/a') . "\n";
  $body .= "GCLID: " . ($gclid ?? 'n/a') . "\n";
  $body .= "FBCLID: " . ($fbclid ?? 'n/a') . "\n";
  $body .= "Bucket: " . $bucket . "\n";

  add_filter('wp_mail_content_type', function(){ return 'text/plain; charset=UTF-8'; });
  wp_mail($to, $subject, $body);
  remove_filter('wp_mail_content_type', '__return_false');

  hic_log('Email admin inviata (bucket='.$bucket.') a '.$to);
}

/* ============ JS: appende sid ai link booking (e crea sid se manca) + supporto iframe ============ */
add_action('wp_footer', function(){ ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  function uuidv4(){
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
      (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
  }
  function getCookie(name){
    var m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
    return m ? m[2] : null;
  }
  function setCookie(name, val, days){
    var d = new Date(); d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = name + "=" + val + "; path=/; SameSite=Lax";
  }

  // Assicura un SID anche per traffico non-ads
  var sid = getCookie('hic_sid');
  if (!sid) { sid = uuidv4(); setCookie('hic_sid', sid, 90); }

  // Funzione per appendere SID ai link
  function appendSidToLinks() {
    var links = document.querySelectorAll('a.js-book, a[href*="booking.hotelincloud.com"]');
    links.forEach(function(link){
      link.addEventListener('click', function(){
        var s = getCookie('hic_sid');
        if (s) {
          try {
            var url = new URL(link.href);
            url.searchParams.set('sid', s);
            link.href = url.toString();
          } catch(e){}
        }
      });
    });
  }

  // Appendi sid ai link nel documento principale
  appendSidToLinks();

  // Supporto per iframe - monitora per nuovi link aggiunti dinamicamente
  if (window.MutationObserver) {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'childList') {
          mutation.addedNodes.forEach(function(node) {
            if (node.nodeType === 1) { // Element node
              // Controlla se il nodo aggiunto contiene link booking
              var newLinks = node.querySelectorAll ? node.querySelectorAll('a.js-book, a[href*="booking.hotelincloud.com"]') : [];
              if (newLinks.length > 0) {
                newLinks.forEach(function(link){
                  link.addEventListener('click', function(){
                    var s = getCookie('hic_sid');
                    if (s) {
                      try {
                        var url = new URL(link.href);
                        url.searchParams.set('sid', s);
                        link.href = url.toString();
                      } catch(e){}
                    }
                  });
                });
              }
            }
          });
        }
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  // Supporto per iframe - comunica con la finestra padre se siamo in un iframe
  if (window !== window.top) {
    try {
      window.parent.postMessage({
        type: 'hic_sid_sync',
        sid: sid
      }, '*');
    } catch(e) {
      // Cross-origin, non possiamo comunicare
    }
  }

  // Ascolta messaggi da iframe
  window.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'hic_sid_sync' && event.data.sid) {
      setCookie('hic_sid', event.data.sid, 90);
    }
  });
});
</script>
<?php });
