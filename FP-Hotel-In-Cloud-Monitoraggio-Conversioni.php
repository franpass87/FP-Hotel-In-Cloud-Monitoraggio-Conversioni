<?php declare(strict_types=1);
/**
 * Plugin Name: FP HIC Monitor
 * Plugin URI: https://francescopasseri.com
 * Description: Plugin minimale: riceve nuove prenotazioni da Hotel in Cloud e invia i dati cliente + soggiorno a Brevo.
 * Version: 3.6.0
 * Author: Francesco Passeri
 * Author URI: https://francescopasseri.com
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: hotel-in-cloud
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FP_HIC_BREVO_VERSION', '3.6.0');
define('FP_HIC_BREVO_FILE', __FILE__);
define('FP_HIC_BREVO_DIR', __DIR__);

require_once __DIR__ . '/includes/simple-brevo-sync.php';

\FpHic\SimpleBrevoSync\Bootstrap::init();
