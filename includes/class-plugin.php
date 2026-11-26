<?php
/**
 * Hooks: gateway registration, settings, connect on save, purchase → checkout → redirect,
 * webhook route and a signed status check on the success page.
 *
 * @package CaravelPayEdd
 */

namespace CaravelPay\Edd;

use CaravelPay\Currency;
use CaravelPay\FeeMode;
use CaravelPay\Kit\ConnectException;
use CaravelPay\Kit\Gateway;
use CaravelPay\Kit\Invoice;
use CaravelPay\Kit\SqlStorage;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    const GATEWAY = 'caravel_pay';
    const REST_NAMESPACE = 'caravel-pay-edd/v1';
    const NOTICE = 'caravel_pay_edd_notice';
    const DEFAULT_LABEL = 'Card, Apple Pay or Google Pay';

    public static function init()
    {
        add_filter('edd_payment_gateways', array(__CLASS__, 'register_gateway'));
        add_action('edd_' . self::GATEWAY . '_cc_form', array(__CLASS__, 'checkout_notice'));
        add_action('edd_gateway_' . self::GATEWAY, array(__CLASS__, 'process_purchase'));
        add_filter('edd_settings_sections_gateways', array(__CLASS__, 'settings_section'));
        add_filter('edd_settings_gateways', array(__CLASS__, 'settings'));
        add_action('update_option_edd_settings', array(__CLASS__, 'settings_saved'), 10, 2);
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('template_redirect', array(__CLASS__, 'check_on_return'));
    }

    /** @param array<string, array<string, mixed>> $gateways */
    public static function register_gateway($gateways)
    {
        $label = trim((string) edd_get_option('caravel_pay_checkout_label', ''));
        $gateways[self::GATEWAY] = array(
            'admin_label' => 'Caravel Pay',
            'checkout_label' => '' !== $label ? $label : self::DEFAULT_LABEL,
            'supports' => array(),
        );
        return $gateways;
    }

    /**
     * The checkout area of this gateway: no card fields (payment happens on the hosted page). When the store
     * passes fees to the buyer, one line says so before they continue.
     *
     * @return false
     */
    public static function checkout_notice()
    {
        // A store that doesn't price in US dollars: say that the charge is in US dollars, with an estimate when a rate is at hand.
        $currency = Currency::code(edd_get_currency());
        if (Currency::USD !== $currency && Currency::isSupported($currency)) {
            try {
                $total = function_exists('edd_get_cart_total') ? (float) edd_get_cart_total() : 0.0;
                $line = $total > 0
                    ? self::gateway()->buyerNotice(new Invoice('cart', Invoice::UNPAID, number_format($total, 2, '.', ''), $currency, ''))
                    : Currency::buyerNotice($currency);
            } catch (\Throwable $e) {
                $line = Currency::buyerNotice($currency);
            }
            echo '<p class="caravel-pay-edd-currency">' . esc_html($line) . '</p>';
        }
        $notice = FeeMode::buyerNotice(edd_get_option('caravel_pay_fee_mode', FeeMode::SELLER));
        if ('' !== $notice) {
            echo '<p class="caravel-pay-edd-fees">' . esc_html($notice) . '</p>';
        }
        return false;
    }

    /** @param array<string, string> $sections */
    public static function settings_section($sections)
    {
        $sections[self::GATEWAY] = 'Caravel Pay';
        return $sections;
    }

    /** @param array<string, mixed> $settings */
    public static function settings($settings)
    {
        $settings[self::GATEWAY] = array(
            'caravel_pay_header' => array('id' => 'caravel_pay_header', 'name' => '<strong>Caravel Pay</strong>', 'type' => 'header'),
            'caravel_pay_wallet' => array(
                'id' => 'caravel_pay_wallet',
                'name' => 'Payout wallet (Polygon)',
                'desc' => self::status_html(),
                'type' => 'text',
                'size' => 'regular',
            ),
            'caravel_pay_email' => array(
                'id' => 'caravel_pay_email',
                'name' => 'Contact email',
                'desc' => 'Optional. For operational notices from Caravel Pay. Never used to log in.',
                'type' => 'text',
                'size' => 'regular',
            ),
            'caravel_pay_checkout_label' => array(
                'id' => 'caravel_pay_checkout_label',
                'name' => 'Checkout label',
                'desc' => 'What buyers see at checkout.',
                'type' => 'text',
                'size' => 'regular',
                'std' => self::DEFAULT_LABEL,
            ),
            'caravel_pay_fee_mode' => array(
                'id' => 'caravel_pay_fee_mode',
                'name' => 'Who pays the fees',
                'desc' => esc_html(FeeMode::help()),
                'type' => 'select',
                'options' => FeeMode::labels(),
                'std' => FeeMode::SELLER,
            ),
            'caravel_pay_fx_buffer' => array(
                'id' => 'caravel_pay_fx_buffer',
                'name' => Currency::bufferLabel(),
                'desc' => esc_html(Currency::bufferHelp()),
                'type' => 'text',
                'size' => 'small',
                'std' => '0',
            ),
        );
        return $settings;
    }

    /**
     * Connect when the wallet is saved (and on later payments if this fails for a temporary reason).
     *
     * @param mixed $old Previous edd_settings.
     * @param mixed $new New edd_settings.
     */
    public static function settings_saved($old, $new)
    {
        $wallet = is_array($new) && isset($new['caravel_pay_wallet']) ? trim((string) $new['caravel_pay_wallet']) : '';
        if ('' === $wallet) {
            return;
        }
        $before = is_array($old) ? $old : array();
        $changed = !isset($before['caravel_pay_wallet']) || trim((string) $before['caravel_pay_wallet']) !== $wallet
            || (isset($before['caravel_pay_email']) ? $before['caravel_pay_email'] : '') !== (isset($new['caravel_pay_email']) ? $new['caravel_pay_email'] : '');
        $gateway = self::gateway(array('wallet' => $wallet, 'email' => isset($new['caravel_pay_email']) ? (string) $new['caravel_pay_email'] : ''));
        if (!$changed && $gateway->summary()['matches']) {
            return;
        }
        try {
            $credentials = $gateway->ensureConnected();
            set_transient(self::NOTICE, array('success', 'Caravel Pay is connected as ' . $credentials['merchant_id'] . '. Payouts go to ' . $credentials['payout_address'] . '.'), 300);
        } catch (ConnectException $e) {
            set_transient(self::NOTICE, array('error', 'Caravel Pay could not connect: ' . $e->getMessage()), 300);
        }
    }

    public static function admin_notice()
    {
        $notice = get_transient(self::NOTICE);
        if (!is_array($notice) || !current_user_can('manage_shop_settings')) {
            return;
        }
        delete_transient(self::NOTICE);
        echo '<div class="notice notice-' . ('success' === $notice[0] ? 'success' : 'error') . ' is-dismissible"><p>' . esc_html($notice[1]) . '</p></div>';
    }

    /**
     * Purchase → pending order → checkout → redirect to the hosted checkout.
     *
     * @param array<string, mixed> $purchase_data
     */
    public static function process_purchase($purchase_data)
    {
        if (!Currency::isSupported(Currency::code(edd_get_currency()))) {
            edd_set_error('caravel_pay_currency', 'Caravel Pay can\'t take orders in this store\'s currency. Please choose another payment method.');
            edd_send_back_to_checkout('?payment-mode=' . self::GATEWAY);
            return;
        }
        $payment_id = edd_insert_payment(array(
            'price' => $purchase_data['price'],
            'date' => $purchase_data['date'],
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency' => edd_get_currency(),
            'downloads' => $purchase_data['downloads'],
            'user_info' => $purchase_data['user_info'],
            'cart_details' => $purchase_data['cart_details'],
            'gateway' => self::GATEWAY,
            'status' => 'pending',
        ));
        if (!$payment_id) {
            edd_record_gateway_error('Caravel Pay', 'The order could not be created before payment.');
            edd_set_error('caravel_pay_order', 'Your order could not be created. Please try again.');
            edd_send_back_to_checkout('?payment-mode=' . self::GATEWAY);
            return;
        }
        $ledger = new EddLedger();
        $invoice = $ledger->invoice((string) $payment_id);
        $result = null === $invoice ? array('error' => 'order', 'message' => 'Your order could not be created. Please try again.')
            : self::gateway()->startPayment($invoice, add_query_arg('payment_key', rawurlencode((string) $purchase_data['purchase_key']), edd_get_success_page_uri()), edd_get_checkout_uri());
        if (isset($result['redirect'])) {
            wp_redirect($result['redirect'], 303);
            edd_die();
            return;
        }
        edd_insert_payment_note($payment_id, 'Caravel Pay: the checkout could not be started (' . $result['error'] . ').');
        edd_set_error('caravel_pay', $result['message']);
        edd_send_back_to_checkout('?payment-mode=' . self::GATEWAY);
    }

    public static function register_routes()
    {
        register_rest_route(self::REST_NAMESPACE, '/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'webhook'),
            'permission_callback' => '__return_true', // Authenticated by the HMAC over the raw request.
        ));
    }

    /**
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public static function webhook($request)
    {
        $urls = array(self::callback_url());
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ('' !== $uri && '/' === $uri[0]) {
            $urls[] = $uri;
        }
        $result = self::gateway()->handleWebhook((string) $request->get_body(), (array) $request->get_headers(), $urls);
        $response = new \WP_REST_Response($result['body'], $result['status']);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /** On the purchase confirmation page, ask the API (signed) whether a pending order was paid and clear the cart when complete. */
    public static function check_on_return()
    {
        if (!function_exists('edd_is_success_page') || !edd_is_success_page() || empty($_GET['payment_key'])) {
            return;
        }
        $order_id = edd_get_purchase_id_by_key(sanitize_text_field(wp_unslash($_GET['payment_key'])));
        $order = $order_id ? edd_get_order($order_id) : null;
        if ($order && self::GATEWAY === $order->gateway) {
            if ('pending' === $order->status) {
                self::gateway()->reconcile((string) $order_id);
                $order = edd_get_order($order_id);
            }
            if ($order && in_array($order->status, array('complete', 'publish'), true) && function_exists('edd_empty_cart')) {
                edd_empty_cart();
            }
        }
    }

    public static function callback_url()
    {
        return rest_url(self::REST_NAMESPACE . '/webhook');
    }

    /**
     * @param array{wallet?: string, email?: string} $override Values being saved.
     */
    public static function gateway(array $override = array())
    {
        global $wpdb;
        $config = array(
            'platform' => 'edd',
            'platform_name' => 'EDD',
            'version' => CARAVEL_PAY_EDD_VERSION,
            'payout_address' => isset($override['wallet']) ? $override['wallet'] : trim((string) edd_get_option('caravel_pay_wallet', '')),
            'email' => isset($override['email']) ? $override['email'] : trim((string) edd_get_option('caravel_pay_email', '')),
            // Who pays the fees: the saved select value; anything else (or nothing saved yet) is the default.
            'fee_mode' => (string) edd_get_option('caravel_pay_fee_mode', FeeMode::SELLER),
            // The margin on the converted amount, for a store that doesn't price in US dollars (default 0).
            'fx_buffer' => (string) edd_get_option('caravel_pay_fx_buffer', '0'),
            'site_url' => untrailingslashit(home_url('/')),
            'callback_url' => self::callback_url(),
        );
        if (defined('CARAVEL_PAY_API_BASE')) {
            $config['api_base'] = (string) constant('CARAVEL_PAY_API_BASE');
        }
        /**
         * Filters the Caravel Pay gateway configuration (for staging: api_base).
         *
         * @param array $config Gateway configuration.
         */
        $config = apply_filters('caravel_pay_edd_gateway_config', $config);
        return new Gateway(new SqlStorage(new WpdbSql($wpdb), $wpdb->prefix . 'caravelpay_edd_'), new EddLedger(), $config);
    }

    /** The wallet saved by PayGate's EDD gateway, read-only, so the admin can paste it. */
    public static function paygate_wallet()
    {
        $settings = function_exists('edd_get_settings') ? edd_get_settings() : array();
        foreach ((array) $settings as $key => $value) {
            if (false !== stripos((string) $key, 'paygate') && is_string($value) && preg_match('/^\s*(0x[0-9a-fA-F]{40})\s*$/D', $value, $match)) {
                return $match[1];
            }
        }
        return '';
    }

    private static function status_html()
    {
        $lines = array('Your Polygon address (0x…). Every payout is sent here, minus the 2% Caravel fee. Saving connects your store to Caravel Pay; a new wallet gets new API keys.');
        try {
            $summary = self::gateway()->summary();
            $lines[] = $summary['connected']
                ? '<strong>Connected</strong> as ' . esc_html($summary['merchant_id']) . ' for ' . esc_html($summary['payout_address']) . ($summary['matches'] ? '.' : '. The wallet or site address changed: it connects again on the next save or payment.')
                : '<strong>Not connected yet.</strong>';
        } catch (\Throwable $e) {
            $lines[] = 'Connection status unavailable.';
        }
        $paygate = self::paygate_wallet();
        if ('' !== $paygate) {
            $lines[] = 'Your PayGate gateway pays out to ' . esc_html($paygate) . '. Paste it above to keep the same wallet.';
        }
        $lines[] = 'A store in US dollars is charged as it is. A store in EUR, GBP or another supported currency is converted to US dollars when the buyer pays.';
        return implode('<br>', $lines);
    }
}
