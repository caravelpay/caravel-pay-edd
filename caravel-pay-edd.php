<?php
/**
 * Plugin Name:       Caravel Pay for Easy Digital Downloads
 * Description:       Accept card, Apple Pay, Google Pay and USDC in Easy Digital Downloads. Payouts go to your own Polygon wallet, minus the 2% Caravel fee.
 * Version:           1.2.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Caravel Pay
 * Author URI:        https://caravelpay.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       caravel-pay-edd
 *
 * @package CaravelPayEdd
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CARAVEL_PAY_EDD_VERSION', '1.2.2');
define('CARAVEL_PAY_EDD_FILE', __FILE__);

require_once __DIR__ . '/lib/caravel-php/autoload.php';
require_once __DIR__ . '/lib/caravel-kit/autoload.php';
require_once __DIR__ . '/includes/class-wpdb-sql.php';
require_once __DIR__ . '/includes/class-edd-ledger.php';
require_once __DIR__ . '/includes/class-plugin.php';

add_action('plugins_loaded', array('CaravelPay\\Edd\\Plugin', 'init'));
