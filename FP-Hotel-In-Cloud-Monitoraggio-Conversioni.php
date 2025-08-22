/**
 * Plugin Name: HIC GA4 + Brevo + Meta (bucket strategy)
 * Description: Tracciamento prenotazioni Hotel in Cloud → GA4 (purchase), Meta CAPI (Purchase) e Brevo (contact+event), con bucket gads/fbads/organic. Salvataggio gclid/fbclid↔sid e append sid ai link HIC.
 * Version: 1.3.0
 * Author: Francesco Passeri
 */

if (!defined('ABSPATH')) exit;

/* ================= CONFIG ================= */
define('HIC_MEASUREMENT_ID', 'G-Z8PWZ8DGKQ');  // GA4
define('HIC_API_SECRET',     'TIeYY5JwSgKtjB9jCJphOg');

define('HIC_BREVO_API_KEY',  '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');
define('HIC_LIST_ITA',       20);
define('HIC_LIST_ENG',       21);

define('HIC_FB_PIXEL_ID',    '893129609196001'); // Meta
define('HIC_FB_ACCESS_TOKEN','EAAO4m2qD0ZCcBPCFbm51lch1dto4kyCScoDwR7e2YhX8WWWocr7iggNG7ZAKWqtP8ylWXlFSc5ln77IlrjXilRX7ISrh51jZBzrWQWE3MCGeD6zJf67to3sXnaVPenfVl8DUPrB5hr0JTZBxZBv1OcCEtQyVvSTyou2ywu9qg6IBUhWXsfr8EcMlkcdvSeBamHQZDZD');

define('HIC_WEBHOOK_TOKEN',  'hic2025ga4');     // HIC → WP webhook token
define('HIC_ADMIN_EMAIL',    'francesco.passeri@gmail.com'); // Notifica admin
define('HIC_LOG_FILE',       WP_CONTENT_DIR . '/hic-ga4.log');
/* ========================================== */

/* ============ Helpers ============ */
function hic_log($msg){
  $date = date('Y-m-d H:i:s');
  $line = '['.$date.'] ' . (is_scalar($msg) ? $msg : print_r($msg, true)) . "\n";
  @file_put_contents(HIC_LOG_FILE, $line, FILE_APPEND);
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
  register_rest_route('hic/v1', '/conversion', [
    'methods'             => 'POST',
    'callback'            => 'hic_webhook_handler',
    'permission_callback' => '__return_true',
  ]);
});

function hic_webhook_handler(WP_REST_Request $request) {
  $token = $request->get_param('token');
  if ($token !== HIC_WEBHOOK_TOKEN) {
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
  hic_send_brevo_contact($data, $gclid, $fbclid);
  hic_send_brevo_event($data, $gclid, $fbclid);
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
       . rawurlencode(HIC_MEASUREMENT_ID)
       . '&api_secret='
       . rawurlencode(HIC_API_SECRET);

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
  if (!HIC_FB_PIXEL_ID || !HIC_FB_ACCESS_TOKEN) {
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

  $url = 'https://graph.facebook.com/v19.0/' . HIC_FB_PIXEL_ID . '/events?access_token=' . HIC_FB_ACCESS_TOKEN;
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
  if (!HIC_BREVO_API_KEY) { hic_log('Brevo disabilitato (API key vuota).'); return; }

  $email = $data['email'] ?? null;
  if (!$email) { hic_log('Nessuna email nel payload → skip Brevo contact.'); return; }

  // Lista in base alla lingua (supporta sia 'lingua' sia 'lang')
  $lang = $data['lingua'] ?? ($data['lang'] ?? '');
  $list_ids = [];
  if (strtolower($lang) === 'en') { $list_ids[] = HIC_LIST_ENG; } else { $list_ids[] = HIC_LIST_ITA; }

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
      'api-key'      => HIC_BREVO_API_KEY,
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
  if (!HIC_BREVO_API_KEY) { return; }
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
      'api-key'      => HIC_BREVO_API_KEY
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
  $to        = HIC_ADMIN_EMAIL;
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

/* ============ JS: appende sid ai link booking (e crea sid se manca) ============ */
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

  // Appendi sid ai link che puntano a booking.hotelincloud.com
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
});
</script>
<?php });
