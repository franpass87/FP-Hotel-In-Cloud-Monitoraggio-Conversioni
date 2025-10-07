<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Privacy exporters and erasers split for modularity.
 */

function hic_register_exporter($exporters) {
    $exporters['hic-tracking-data'] = [
        'exporter_friendly_name' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\hic_export_tracking_data',
    ];
    return $exporters;
}

function hic_register_eraser($erasers) {
    $erasers['hic-tracking-data'] = [
        'eraser_friendly_name' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\hic_erase_tracking_data',
    ];
    return $erasers;
}


