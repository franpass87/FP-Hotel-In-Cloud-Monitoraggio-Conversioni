<?php declare(strict_types=1);

namespace FpHic\Privacy;

use function FpHic\Helpers\hic_sanitize_identifier;

if (!defined('ABSPATH')) {
    exit;
}

const EXPORTER_PAGE_SIZE = 25;
const ERASER_PAGE_SIZE = 25;

if (\function_exists('add_filter')) {
    \add_filter('wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\register_exporter');
    \add_filter('wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\register_eraser');
}

/**
 * Register the personal data exporter with WordPress privacy tools.
 *
 * @param array<string, array{exporter_friendly_name:string, callback:callable}> $exporters
 * @return array<string, array{exporter_friendly_name:string, callback:callable}>
 */
function register_exporter(array $exporters): array
{
    $exporters['fp-hic-monitor-reservations'] = [
        'exporter_friendly_name' => \__('FP HIC Monitor - Prenotazioni e tracking', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\export_personal_data',
    ];

    return $exporters;
}

/**
 * Register the personal data eraser with WordPress privacy tools.
 *
 * @param array<string, array{eraser_friendly_name:string, callback:callable}> $erasers
 * @return array<string, array{eraser_friendly_name:string, callback:callable}>
 */
function register_eraser(array $erasers): array
{
    $erasers['fp-hic-monitor-reservations'] = [
        'eraser_friendly_name' => \__('FP HIC Monitor - Prenotazioni e tracking', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\erase_personal_data',
    ];

    return $erasers;
}

/**
 * Export personal data tracked by the plugin for the provided email address.
 *
 * @return array{data: array<int, array<string,mixed>>, done: bool}
 */
function export_personal_data(string $email_address, int $page = 1): array
{
    $sanitized_email = sanitize_email($email_address);
    if ($sanitized_email === '') {
        return ['data' => [], 'done' => true];
    }

    $reservations = get_reservation_ids_for_email($sanitized_email);
    if ($reservations === []) {
        return ['data' => [], 'done' => true];
    }

    $page = max(1, (int) $page);
    $offset = ($page - 1) * EXPORTER_PAGE_SIZE;
    $slice = array_slice($reservations, $offset, EXPORTER_PAGE_SIZE);

    $items = [];
    foreach ($slice as $reservation_id) {
        $items = array_merge($items, build_export_items_for_reservation($reservation_id, $sanitized_email));
    }

    $done = ($offset + EXPORTER_PAGE_SIZE) >= count($reservations);

    return ['data' => $items, 'done' => $done];
}

/**
 * Remove personal data tracked by the plugin for the provided email address.
 *
 * @return array{items_removed: bool, items_retained: bool, messages: array<int,string>, done: bool}
 */
function erase_personal_data(string $email_address, int $page = 1): array
{
    $sanitized_email = sanitize_email($email_address);
    if ($sanitized_email === '') {
        return [
            'items_removed' => false,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }

    $reservations = get_reservation_ids_for_email($sanitized_email);
    if ($reservations === []) {
        $changed = remove_email_map_entries($sanitized_email, []);
        return [
            'items_removed' => $changed,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }

    $page = max(1, (int) $page);
    $offset = ($page - 1) * ERASER_PAGE_SIZE;
    $slice = array_slice($reservations, $offset, ERASER_PAGE_SIZE);

    $wpdb = get_database();
    if ($wpdb === null) {
        return [
            'items_removed' => false,
            'items_retained' => true,
            'messages' => [\__('Impossibile accedere al database per completare l\'operazione.', 'hotel-in-cloud')],
            'done' => true,
        ];
    }

    $items_removed = false;
    $items_retained = false;
    $messages = [];

    $booking_metrics_table = $wpdb->prefix . 'hic_booking_metrics';
    $realtime_table = $wpdb->prefix . 'hic_realtime_sync';
    $booking_events_table = $wpdb->prefix . 'hic_booking_events';

    foreach ($slice as $reservation_id) {
        $metrics = get_booking_metrics($reservation_id);
        $sid = $metrics['sid'] ?? '';

        if ($metrics !== []) {
            $delete_result = $wpdb->delete($booking_metrics_table, ['reservation_id' => $reservation_id], ['%s']);
            if ($delete_result === false) {
                $items_retained = true;
                $messages[] = sprintf(\__('Impossibile eliminare le metriche per la prenotazione %s.', 'hotel-in-cloud'), $reservation_id);
            } elseif ($delete_result > 0) {
                $items_removed = true;
            }
        }

        $realtime_deleted = $wpdb->delete($realtime_table, ['reservation_id' => $reservation_id], ['%s']);
        if ($realtime_deleted === false) {
            $items_retained = true;
            $messages[] = sprintf(\__('Impossibile rimuovere lo stato di sincronizzazione per la prenotazione %s.', 'hotel-in-cloud'), $reservation_id);
        } elseif ($realtime_deleted > 0) {
            $items_removed = true;
        }

        $events_deleted = $wpdb->delete($booking_events_table, ['booking_id' => $reservation_id], ['%s']);
        if ($events_deleted === false) {
            $items_retained = true;
            $messages[] = sprintf(\__('Impossibile cancellare gli eventi di prenotazione per %s.', 'hotel-in-cloud'), $reservation_id);
        } elseif ($events_deleted > 0) {
            $items_removed = true;
        }

        if ($sid !== '') {
            $tracking_deleted = delete_tracking_rows_for_sid($sid);
            if ($tracking_deleted === false) {
                $items_retained = true;
                $messages[] = sprintf(\__('Impossibile rimuovere i dati di tracking associati alla sessione %s.', 'hotel-in-cloud'), $sid);
            } elseif ($tracking_deleted > 0) {
                $items_removed = true;
            }
        }
    }

    if (remove_email_map_entries($sanitized_email, $slice)) {
        $items_removed = true;
    }

    $done = ($offset + ERASER_PAGE_SIZE) >= count($reservations);

    return [
        'items_removed' => $items_removed,
        'items_retained' => $items_retained,
        'messages' => $messages,
        'done' => $done,
    ];
}

/**
 * @return array<int, string>
 */
function get_reservation_ids_for_email(string $email): array
{
    $map = get_option('hic_res_email_map', []);
    if (!\is_array($map)) {
        return [];
    }

    $matches = [];
    foreach ($map as $reservation_id => $stored_email) {
        if (!\is_scalar($reservation_id) || !\is_string($stored_email)) {
            continue;
        }

        $normalized_email = sanitize_email($stored_email);
        if ($normalized_email === '' || strcasecmp($normalized_email, $email) !== 0) {
            continue;
        }

        $normalized_reservation = \FpHic\hic_normalize_reservation_id((string) $reservation_id);
        if ($normalized_reservation !== '') {
            $matches[$normalized_reservation] = true;
        }
    }

    return array_keys($matches);
}

/**
 * Build the export payload for a single reservation.
 *
 * @return array<int, array<string, mixed>>
 */
function build_export_items_for_reservation(string $reservation_id, string $email): array
{
    $items = [];
    $metrics = get_booking_metrics($reservation_id);
    $realtime = get_realtime_sync_row($reservation_id);
    $events = get_booking_events($reservation_id);
    $sid = $metrics['sid'] ?? '';

    $reservation_item = [
        'group_id' => 'fp-hic-monitor-reservations',
        'group_label' => \__('Prenotazioni monitorate', 'hotel-in-cloud'),
        'item_id' => 'reservation-' . md5($reservation_id),
        'data' => array_filter([
            ['name' => \__('ID prenotazione', 'hotel-in-cloud'), 'value' => $reservation_id],
            ['name' => \__('Email associata', 'hotel-in-cloud'), 'value' => $email],
            $metrics !== [] && ($metrics['channel'] ?? '') !== '' ? ['name' => \__('Canale', 'hotel-in-cloud'), 'value' => (string) $metrics['channel']] : null,
            $metrics !== [] && ($metrics['status'] ?? '') !== '' ? ['name' => \__('Stato', 'hotel-in-cloud'), 'value' => (string) $metrics['status']] : null,
            $metrics !== [] && ($metrics['amount'] ?? null) !== null ? ['name' => \__('Importo', 'hotel-in-cloud'), 'value' => (string) $metrics['amount'] . ' ' . (string) ($metrics['currency'] ?? 'EUR')] : null,
            $metrics !== [] && ($metrics['sid'] ?? '') !== '' ? ['name' => \__('Sessione HIC SID', 'hotel-in-cloud'), 'value' => (string) $metrics['sid']] : null,
            $metrics !== [] && ($metrics['utm_source'] ?? '') !== '' ? ['name' => \__('UTM Source', 'hotel-in-cloud'), 'value' => (string) $metrics['utm_source']] : null,
            $metrics !== [] && ($metrics['utm_medium'] ?? '') !== '' ? ['name' => \__('UTM Medium', 'hotel-in-cloud'), 'value' => (string) $metrics['utm_medium']] : null,
            $metrics !== [] && ($metrics['utm_campaign'] ?? '') !== '' ? ['name' => \__('UTM Campaign', 'hotel-in-cloud'), 'value' => (string) $metrics['utm_campaign']] : null,
            $metrics !== [] && ($metrics['utm_content'] ?? '') !== '' ? ['name' => \__('UTM Content', 'hotel-in-cloud'), 'value' => (string) $metrics['utm_content']] : null,
            $metrics !== [] && ($metrics['utm_term'] ?? '') !== '' ? ['name' => \__('UTM Term', 'hotel-in-cloud'), 'value' => (string) $metrics['utm_term']] : null,
            $metrics !== [] && ($metrics['created_at'] ?? '') !== '' ? ['name' => \__('Creato il', 'hotel-in-cloud'), 'value' => (string) $metrics['created_at']] : null,
            $metrics !== [] && ($metrics['updated_at'] ?? '') !== '' ? ['name' => \__('Aggiornato il', 'hotel-in-cloud'), 'value' => (string) $metrics['updated_at']] : null,
        ]),
    ];

    if (!empty($reservation_item['data'])) {
        $items[] = $reservation_item;
    }

    if ($realtime !== []) {
        $payload = format_payload($realtime['payload_json'] ?? null);
        $items[] = [
            'group_id' => 'fp-hic-monitor-realtime-sync',
            'group_label' => \__('Stato sincronizzazione realtime', 'hotel-in-cloud'),
            'item_id' => 'realtime-' . md5($reservation_id),
            'data' => array_filter([
                ['name' => \__('ID prenotazione', 'hotel-in-cloud'), 'value' => $reservation_id],
                ['name' => \__('Stato sincronizzazione', 'hotel-in-cloud'), 'value' => (string) ($realtime['sync_status'] ?? '')],
                $realtime['first_seen'] ?? null ? ['name' => \__('Primo rilevamento', 'hotel-in-cloud'), 'value' => (string) $realtime['first_seen']] : null,
                $realtime['last_attempt'] ?? null ? ['name' => \__('Ultimo tentativo', 'hotel-in-cloud'), 'value' => (string) $realtime['last_attempt']] : null,
                ['name' => \__('Tentativi', 'hotel-in-cloud'), 'value' => (string) ($realtime['attempt_count'] ?? 0)],
                ['name' => \__('Evento Brevo inviato', 'hotel-in-cloud'), 'value' => ($realtime['brevo_event_sent'] ?? 0) ? \__('Sì', 'hotel-in-cloud') : \__('No', 'hotel-in-cloud')],
                $realtime['last_error'] ?? null ? ['name' => \__('Ultimo errore', 'hotel-in-cloud'), 'value' => (string) $realtime['last_error']] : null,
                $payload !== null ? ['name' => \__('Payload registrato', 'hotel-in-cloud'), 'value' => $payload] : null,
            ]),
        ];
    }

    if ($events !== []) {
        foreach ($events as $index => $event) {
            $event_payload = format_payload($event['raw_data'] ?? null);
            $items[] = [
                'group_id' => 'fp-hic-monitor-booking-events',
                'group_label' => \__('Eventi di prenotazione in coda', 'hotel-in-cloud'),
                'item_id' => 'booking-event-' . md5($reservation_id . '-' . $index),
                'data' => array_filter([
                    ['name' => \__('ID prenotazione', 'hotel-in-cloud'), 'value' => $reservation_id],
                    $event['poll_timestamp'] ?? null ? ['name' => \__('Rilevato il', 'hotel-in-cloud'), 'value' => (string) $event['poll_timestamp']] : null,
                    ['name' => \__('Processato', 'hotel-in-cloud'), 'value' => ($event['processed'] ?? 0) ? \__('Sì', 'hotel-in-cloud') : \__('No', 'hotel-in-cloud')],
                    $event['processed_at'] ?? null ? ['name' => \__('Processato il', 'hotel-in-cloud'), 'value' => (string) $event['processed_at']] : null,
                    ['name' => \__('Tentativi di elaborazione', 'hotel-in-cloud'), 'value' => (string) ($event['process_attempts'] ?? 0)],
                    $event['last_error'] ?? null ? ['name' => \__('Ultimo errore', 'hotel-in-cloud'), 'value' => (string) $event['last_error']] : null,
                    $event_payload !== null ? ['name' => \__('Payload originale', 'hotel-in-cloud'), 'value' => $event_payload] : null,
                ]),
            ];
        }
    }

    if ($sid !== '') {
        $tracking_rows = get_tracking_rows_by_sid($sid);
        foreach ($tracking_rows as $index => $row) {
            $items[] = [
                'group_id' => 'fp-hic-monitor-tracking',
                'group_label' => \__('Dati di tracking campagne', 'hotel-in-cloud'),
                'item_id' => 'tracking-' . md5($sid . '-' . $index),
                'data' => array_filter([
                    ['name' => \__('Sessione HIC SID', 'hotel-in-cloud'), 'value' => $sid],
                    $row['gclid'] ?? null ? ['name' => 'gclid', 'value' => (string) $row['gclid']] : null,
                    $row['fbclid'] ?? null ? ['name' => 'fbclid', 'value' => (string) $row['fbclid']] : null,
                    $row['msclkid'] ?? null ? ['name' => 'msclkid', 'value' => (string) $row['msclkid']] : null,
                    $row['ttclid'] ?? null ? ['name' => 'ttclid', 'value' => (string) $row['ttclid']] : null,
                    $row['gbraid'] ?? null ? ['name' => 'gbraid', 'value' => (string) $row['gbraid']] : null,
                    $row['wbraid'] ?? null ? ['name' => 'wbraid', 'value' => (string) $row['wbraid']] : null,
                    $row['utm_source'] ?? null ? ['name' => \__('UTM Source', 'hotel-in-cloud'), 'value' => (string) $row['utm_source']] : null,
                    $row['utm_medium'] ?? null ? ['name' => \__('UTM Medium', 'hotel-in-cloud'), 'value' => (string) $row['utm_medium']] : null,
                    $row['utm_campaign'] ?? null ? ['name' => \__('UTM Campaign', 'hotel-in-cloud'), 'value' => (string) $row['utm_campaign']] : null,
                    $row['utm_content'] ?? null ? ['name' => \__('UTM Content', 'hotel-in-cloud'), 'value' => (string) $row['utm_content']] : null,
                    $row['utm_term'] ?? null ? ['name' => \__('UTM Term', 'hotel-in-cloud'), 'value' => (string) $row['utm_term']] : null,
                    $row['created_at'] ?? null ? ['name' => \__('Creato il', 'hotel-in-cloud'), 'value' => (string) $row['created_at']] : null,
                ]),
            ];
        }
    }

    return $items;
}

/**
 * Retrieve booking metrics for a reservation.
 *
 * @return array<string, mixed>
 */
function get_booking_metrics(string $reservation_id): array
{
    $wpdb = get_database();
    if ($wpdb === null) {
        return [];
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_booking_metrics', 'table');
    $query = $wpdb->prepare(
        "SELECT reservation_id, sid, channel, utm_source, utm_medium, utm_campaign, utm_content, utm_term, amount, currency, is_refund, status, created_at, updated_at FROM `{$table}` WHERE reservation_id = %s",
        $reservation_id
    );

    $row = $wpdb->get_row($query, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');

    return is_array($row) ? $row : [];
}

/**
 * Retrieve realtime sync information for a reservation.
 *
 * @return array<string, mixed>
 */
function get_realtime_sync_row(string $reservation_id): array
{
    $wpdb = get_database();
    if ($wpdb === null) {
        return [];
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_realtime_sync', 'table');
    $query = $wpdb->prepare(
        "SELECT reservation_id, sync_status, first_seen, last_attempt, attempt_count, brevo_event_sent, last_error, payload_json FROM `{$table}` WHERE reservation_id = %s",
        $reservation_id
    );

    $row = $wpdb->get_row($query, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');

    return is_array($row) ? $row : [];
}

/**
 * Retrieve queued booking events for a reservation.
 *
 * @return array<int, array<string, mixed>>
 */
function get_booking_events(string $reservation_id): array
{
    $wpdb = get_database();
    if ($wpdb === null) {
        return [];
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_booking_events', 'table');
    $query = $wpdb->prepare(
        "SELECT booking_id, poll_timestamp, processed, processed_at, process_attempts, last_error, raw_data FROM `{$table}` WHERE booking_id = %s ORDER BY id ASC",
        $reservation_id
    );

    $results = $wpdb->get_results($query, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');

    return is_array($results) ? $results : [];
}

/**
 * Retrieve tracking entries for a session ID.
 *
 * @return array<int, array<string, mixed>>
 */
function get_tracking_rows_by_sid(string $sid): array
{
    $wpdb = get_database();
    if ($wpdb === null) {
        return [];
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
    $query = $wpdb->prepare(
        "SELECT gclid, fbclid, msclkid, ttclid, gbraid, wbraid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at FROM `{$table}` WHERE sid = %s ORDER BY created_at DESC",
        $sid
    );

    $results = $wpdb->get_results($query, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');

    return is_array($results) ? $results : [];
}

/**
 * Delete tracking entries for a given SID.
 *
 * @return int|false Number of rows deleted or false on failure.
 */
function delete_tracking_rows_for_sid(string $sid)
{
    $wpdb = get_database();
    if ($wpdb === null) {
        return false;
    }

    $table = $wpdb->prefix . 'hic_gclids';
    $prepared = $wpdb->prepare("DELETE FROM {$table} WHERE sid = %s", $sid);
    $result = $wpdb->query($prepared);

    return $result;
}

/**
 * Remove reservation mappings for the provided email.
 */
function remove_email_map_entries(string $email, array $reservation_ids): bool
{
    $map = get_option('hic_res_email_map', []);
    if (!\is_array($map) || $map === []) {
        return false;
    }

    $reservation_lookup = [];
    foreach ($reservation_ids as $reservation_id) {
        $reservation_lookup[$reservation_id] = true;
    }

    $changed = false;
    $normalized = [];

    foreach ($map as $key => $stored_email) {
        if (!\is_scalar($key) || !\is_string($stored_email)) {
            continue;
        }

        $normalized_key = \FpHic\hic_normalize_reservation_id((string) $key);
        $normalized_email = sanitize_email($stored_email);

        if ($normalized_email !== '' && strcasecmp($normalized_email, $email) === 0 && (empty($reservation_lookup) || isset($reservation_lookup[$normalized_key]))) {
            $changed = true;
            continue;
        }

        $normalized[$normalized_key ?: (string) $key] = $stored_email;
    }

    if ($changed) {
        update_option('hic_res_email_map', $normalized, false);
        return true;
    }

    return false;
}

/**
 * Return a normalized representation of structured payloads for export.
 */
function format_payload($payload): ?string
{
    if ($payload === null || $payload === '') {
        return null;
    }

    if (\is_array($payload) || \is_object($payload)) {
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : (string) json_encode($payload);
    }

    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $encoded = function_exists('wp_json_encode')
                ? wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return $payload;
    }

    return (string) $payload;
}

/**
 * Retrieve the database connection used by the plugin.
 */
function get_database(): ?object
{
    $callback = null;
    if (\function_exists('FpHic\\hic_get_wpdb')) {
        $callback = 'FpHic\\hic_get_wpdb';
    } elseif (\function_exists('hic_get_wpdb')) {
        $callback = 'hic_get_wpdb';
    }

    if ($callback === null) {
        return null;
    }

    $required_methods = ['prepare', 'get_row', 'get_results', 'delete', 'query'];
    $wpdb = \call_user_func($callback, $required_methods);

    return $wpdb ?: null;
}
