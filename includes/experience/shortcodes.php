<?php declare(strict_types=1);

namespace FP_Exp;

use FP_Exp\Frontend\Assets;
use FP_Exp\Utils\Helpers as MetaHelpers;
use WP_Post;
use function FpHic\Helpers\hic_log;
use function FpHic\Helpers\hic_safe_add_hook;

if (!defined('ABSPATH')) {
    exit;
}

const SHORTCODE_PAGE   = 'fp_exp_page';
const SHORTCODE_WIDGET = 'fp_exp_widget';

hic_safe_add_hook('action', 'init', __NAMESPACE__ . '\\register_shortcodes');
hic_safe_add_hook('action', 'save_post_fp_experience', __NAMESPACE__ . '\\handle_experience_save', 20, 3);

function register_shortcodes(): void
{
    add_shortcode(SHORTCODE_PAGE, __NAMESPACE__ . '\\render_page_shortcode');
    add_shortcode(SHORTCODE_WIDGET, __NAMESPACE__ . '\\render_widget_shortcode');
}

function render_page_shortcode($atts = [], $content = '', $shortcode = ''): string
{
    $post = resolve_experience_post((array) $atts);

    if (!$post) {
        return '';
    }

    Assets::request_enqueue();
    maybe_send_no_store_header();

    $sections = parse_sections($atts['sections'] ?? '');

    $highlights   = MetaHelpers::get_meta_array($post->ID, '_fp_highlights');
    $inclusions   = MetaHelpers::get_meta_array($post->ID, '_fp_inclusions');
    $ticketTypes  = MetaHelpers::get_meta_array($post->ID, '_fp_ticket_types');
    $pricingRows  = MetaHelpers::get_meta_array($post->ID, '_fp_pricing');
    $meetingPoint = get_meeting_point_data($post->ID);

    $sectionsMap = [
        'highlights'    => $highlights,
        'inclusions'    => $inclusions,
        'ticket_types'  => $ticketTypes,
        'pricing'       => $pricingRows,
        'meeting_point' => $meetingPoint,
    ];

    if (empty($sections)) {
        $sections = array_keys($sectionsMap);
    }

    $sections = array_values(array_intersect(array_keys($sectionsMap), $sections));

    ob_start();
    ?>
    <div class="fp-exp-page" data-exp-id="<?php echo esc_attr((string) $post->ID); ?>" data-version="<?php echo esc_attr(get_post_modified_time('U', true, $post)); ?>">
        <div class="fp-exp-header">
            <h2 class="fp-exp-title"><?php echo esc_html(get_the_title($post)); ?></h2>
            <?php if ($excerpt = get_the_excerpt($post)) : ?>
                <p class="fp-exp-excerpt"><?php echo esc_html($excerpt); ?></p>
            <?php endif; ?>
        </div>
        <?php foreach ($sections as $sectionKey) : ?>
            <?php $data = $sectionsMap[$sectionKey]; ?>
            <?php if (empty($data)) { continue; } ?>
            <?php echo render_section($sectionKey, $data, $post); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_widget_shortcode($atts = [], $content = '', $shortcode = ''): string
{
    $post = resolve_experience_post((array) $atts);

    if (!$post) {
        return '';
    }

    Assets::request_enqueue();
    maybe_send_no_store_header();

    $pricingRows = MetaHelpers::get_meta_array($post->ID, '_fp_pricing');
    $ticketTypes = MetaHelpers::get_meta_array($post->ID, '_fp_ticket_types');

    $pricingData = prepare_pricing_payload($pricingRows, $post->ID);

    ob_start();
    ?>
    <aside class="fp-exp-widget" data-exp-id="<?php echo esc_attr((string) $post->ID); ?>"
        data-pricing="<?php echo esc_attr(wp_json_encode($pricingData)); ?>"
        data-pricing-version="<?php echo esc_attr(get_post_modified_time('U', true, $post)); ?>">
        <h3 class="fp-exp-widget__title"><?php echo esc_html(get_the_title($post)); ?></h3>
        <p class="fp-exp-widget__summary" data-role="fp-exp-summary"></p>
        <div class="fp-exp-widget__body">
            <?php if (!empty($pricingRows)) : ?>
                <?php echo render_pricing_table($pricingRows, $post); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else : ?>
                <p class="fp-exp-widget__notice"><?php esc_html_e('Tariffe disponibili su richiesta.', 'hotel-in-cloud'); ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($ticketTypes)) : ?>
            <ul class="fp-exp-widget__tickets">
                <?php foreach ($ticketTypes as $ticket) : ?>
                    <?php $ticketRow = normalise_ticket_row($ticket); ?>
                    <li>
                        <span class="fp-exp-widget__ticket-label"><?php echo esc_html($ticketRow['label']); ?></span>
                        <?php if ($ticketRow['price'] !== '') : ?>
                            <span class="fp-exp-widget__ticket-price"><?php echo esc_html($ticketRow['price']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
    <?php

    return (string) ob_get_clean();
}

function resolve_experience_post(array $atts): ?WP_Post
{
    $id = isset($atts['id']) ? absint($atts['id']) : 0;

    if ($id <= 0) {
        $currentId = get_the_ID();
        if ($currentId && get_post_type($currentId) === 'fp_experience') {
            $id = (int) $currentId;
        } else {
            hic_log('[FP_Exp] Shortcode senza attributo id e nessun contesto fp_experience valido.', HIC_LOG_LEVEL_DEBUG);
            return null;
        }
    }

    $post = get_post($id);

    if (!$post instanceof WP_Post || $post->post_type !== 'fp_experience') {
        hic_log('[FP_Exp] Esperienza non trovata per ID fornito: ' . $id, HIC_LOG_LEVEL_WARNING, ['post_id' => $id]);
        return null;
    }

    if ($post->post_status !== 'publish') {
        hic_log('[FP_Exp] Esperienza con stato non pubblicato esclusa dal render.', HIC_LOG_LEVEL_DEBUG, ['post_id' => $id, 'status' => $post->post_status]);
        return null;
    }

    return $post;
}

function parse_sections($sections): array
{
    if (empty($sections)) {
        return [];
    }

    if (is_string($sections)) {
        $sections = preg_split('/\s*,\s*/', $sections);
    }

    if (!is_array($sections)) {
        return [];
    }

    $sections = array_map(static function ($section) {
        return sanitize_key((string) $section);
    }, $sections);

    return array_filter($sections);
}

function render_section(string $sectionKey, $data, WP_Post $post): string
{
    switch ($sectionKey) {
        case 'highlights':
            return render_list_section(__('In evidenza', 'hotel-in-cloud'), (array) $data, 'fp-exp-section--highlights');
        case 'inclusions':
            return render_list_section(__('Cosa è incluso', 'hotel-in-cloud'), (array) $data, 'fp-exp-section--inclusions');
        case 'ticket_types':
            return render_ticket_types($data);
        case 'pricing':
            return render_pricing_table($data, $post);
        case 'meeting_point':
            return render_meeting_point($data);
    }

    return '';
}

function render_list_section(string $title, array $items, string $class): string
{
    if (empty($items)) {
        return '';
    }

    ob_start();
    ?>
    <section class="fp-exp-section <?php echo esc_attr($class); ?>">
        <h3 class="fp-exp-section__title"><?php echo esc_html($title); ?></h3>
        <ul class="fp-exp-section__list">
            <?php foreach ($items as $item) : ?>
                <li><?php echo esc_html(is_scalar($item) ? (string) $item : wp_json_encode($item)); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php

    return (string) ob_get_clean();
}

function render_ticket_types(array $ticketTypes): string
{
    if (empty($ticketTypes)) {
        return '';
    }

    ob_start();
    ?>
    <section class="fp-exp-section fp-exp-section--tickets">
        <h3 class="fp-exp-section__title"><?php esc_html_e('Tipologie di biglietto', 'hotel-in-cloud'); ?></h3>
        <ul class="fp-exp-section__tickets">
            <?php foreach ($ticketTypes as $ticket) : ?>
                <?php $row = normalise_ticket_row($ticket); ?>
                <?php if ($row['label'] === '' && $row['description'] === '' && $row['price'] === '') { continue; } ?>
                <li class="fp-exp-ticket">
                    <span class="fp-exp-ticket__label"><?php echo esc_html($row['label']); ?></span>
                    <?php if ($row['description'] !== '') : ?>
                        <span class="fp-exp-ticket__description"><?php echo esc_html($row['description']); ?></span>
                    <?php endif; ?>
                    <?php if ($row['price'] !== '') : ?>
                        <span class="fp-exp-ticket__price"><?php echo esc_html($row['price']); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php

    return (string) ob_get_clean();
}

function normalise_ticket_row($ticket): array
{
    $label       = '';
    $description = '';
    $price       = '';

    if (is_array($ticket)) {
        $label = trim((string) ($ticket['label'] ?? $ticket['name'] ?? ''));
        $description = trim((string) ($ticket['description'] ?? ''));
        $priceValue  = $ticket['price'] ?? $ticket['amount'] ?? '';
        $currency    = $ticket['currency'] ?? '';

        if (is_numeric($priceValue)) {
            $priceValue = number_format_i18n((float) $priceValue, 2);
        }

        if ($priceValue !== '') {
            $price = trim($priceValue . ' ' . (string) $currency);
        }
    } elseif (is_scalar($ticket)) {
        $label = trim((string) $ticket);
    }

    return [
        'label'       => $label,
        'description' => $description,
        'price'       => $price,
    ];
}

function render_pricing_table(array $pricingRows, WP_Post $post): string
{
    if (empty($pricingRows)) {
        return '';
    }

    ob_start();
    ?>
    <table class="fp-exp-pricing">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Opzione', 'hotel-in-cloud'); ?></th>
                <th scope="col"><?php esc_html_e('Prezzo', 'hotel-in-cloud'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pricingRows as $row) : ?>
                <?php $normalised = normalise_pricing_row($row, $post->ID); ?>
                <?php if ($normalised['label'] === '' && $normalised['price'] === '') { continue; } ?>
                <tr>
                    <td><?php echo esc_html($normalised['label']); ?></td>
                    <td><?php echo esc_html($normalised['price']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php

    return (string) ob_get_clean();
}

function normalise_pricing_row($row, int $postId): array
{
    $label = '';
    $price = '';

    if (is_array($row)) {
        $label = trim((string) ($row['label'] ?? $row['name'] ?? ''));
        $amount = $row['amount'] ?? $row['price'] ?? '';
        $currency = $row['currency'] ?? get_post_meta($postId, '_fp_currency', true);

        if (is_numeric($amount)) {
            $amount = number_format_i18n((float) $amount, 2);
        }

        if ($amount !== '') {
            $price = trim($amount . ' ' . (string) $currency);
        }
    } elseif (is_scalar($row)) {
        $label = trim((string) $row);
    }

    return [
        'label' => $label,
        'price' => $price,
    ];
}

function get_meeting_point_data(int $postId): array
{
    $pointId   = (string) get_post_meta($postId, '_fp_meeting_point_id', true);
    $alt       = (string) get_post_meta($postId, '_fp_meeting_point_alt', true);
    $address   = (string) get_post_meta($postId, '_fp_meeting_point_address', true);
    $latitude  = get_post_meta($postId, '_fp_meeting_point_lat', true);
    $longitude = get_post_meta($postId, '_fp_meeting_point_lng', true);

    if ($pointId === '' && $alt === '' && $address === '' && $latitude === '' && $longitude === '') {
        return [];
    }

    return [
        'id'        => $pointId,
        'alt'       => $alt,
        'address'   => $address,
        'latitude'  => is_numeric($latitude) ? (float) $latitude : null,
        'longitude' => is_numeric($longitude) ? (float) $longitude : null,
    ];
}

function render_meeting_point(array $data): string
{
    if (empty($data)) {
        return '';
    }

    $label = $data['alt'] ?: $data['address'];

    if ($label === '' && $data['id'] !== '') {
        $label = sprintf(__('Punto d\'incontro #%s', 'hotel-in-cloud'), $data['id']);
    }

    if ($label === '') {
        return '';
    }

    $mapLink = '';
    if (is_numeric($data['latitude']) && is_numeric($data['longitude'])) {
        $mapLink = sprintf('https://www.google.com/maps?q=%s,%s', rawurlencode((string) $data['latitude']), rawurlencode((string) $data['longitude']));
    } elseif ($data['address'] !== '') {
        $mapLink = sprintf('https://www.google.com/maps/search/?api=1&query=%s', rawurlencode($data['address']));
    }

    ob_start();
    ?>
    <section class="fp-exp-section fp-exp-section--meeting-point">
        <h3 class="fp-exp-section__title"><?php esc_html_e('Punto di incontro', 'hotel-in-cloud'); ?></h3>
        <p class="fp-exp-section__content"><?php echo esc_html($label); ?></p>
        <?php if ($mapLink !== '') : ?>
            <a class="fp-exp-section__maps" href="<?php echo esc_url($mapLink); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Apri in Maps', 'hotel-in-cloud'); ?></a>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

function prepare_pricing_payload(array $rows, int $postId): array
{
    $payload = [];

    foreach ($rows as $row) {
        $normalised = normalise_pricing_row($row, $postId);
        if ($normalised['label'] === '' && $normalised['price'] === '') {
            continue;
        }

        $payload[] = $normalised;
    }

    return $payload;
}

function maybe_send_no_store_header(): void
{
    static $sent = false;

    if ($sent || headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $sent = true;
}

function handle_experience_save(int $postId, $post, bool $update): void
{
    if ($postId <= 0 || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    if (wp_is_post_revision($postId)) {
        return;
    }

    delete_transient('fp_exp_page_' . $postId);
    delete_transient('fp_exp_widget_' . $postId);
}
