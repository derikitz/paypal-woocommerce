<?php

defined('ABSPATH') || exit;

/** Authorization for browser requests that name an existing payable order. */
class AngellEYE_PayPal_PPCP_Order_Access {

    public static function payable_order($order_id, $order_key) {
        if ((!is_string($order_id) && !is_int($order_id)) ||
            !preg_match('/^[1-9][0-9]*$/D', (string) $order_id) ||
            filter_var($order_id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false ||
            !is_string($order_key) || $order_key === '') {
            return false;
        }
        $order = wc_get_order((int) $order_id);
        if (!$order || !is_a($order, 'WC_Order') || (int) $order->get_id() !== (int) $order_id ||
            $order->get_type() !== 'shop_order' ||
            !hash_equals((string) $order->get_order_key(), $order_key) ||
            !current_user_can('pay_for_order', $order->get_id()) || !$order->needs_payment()) {
            return false;
        }
        return $order;
    }

    public static function payment_page_order($order_id, $order_key) {
        $order = self::payable_order($order_id, $order_key);
        if (!$order) {
            return false;
        }
        // Respect modern WooCommerce guest-email verification before exposing page data
        // or minting the nonce used by subsequent AJAX requests.
        $verifier = array('Automattic\\WooCommerce\\Internal\\Utilities\\Users', 'should_user_verify_order_email');
        if (is_callable($verifier)) {
            $email = null;
            if (isset($_POST['check_submission'], $_POST['email']) && is_string($_POST['check_submission']) &&
                is_string($_POST['email']) && wp_verify_nonce(wp_unslash($_POST['check_submission']), 'wc_verify_email')) {
                $email = sanitize_email(wp_unslash($_POST['email']));
            }
            if (call_user_func($verifier, $order->get_id(), $email, 'order-pay')) {
                return false;
            }
        }
        return $order;
    }

    public static function authorize_request($data) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($data)) {
            return false;
        }
        $order = self::payable_order($data['woo_order_id'] ?? null,
            isset($data['woo_order_key']) && is_string($data['woo_order_key']) ? wp_unslash($data['woo_order_key']) : null);
        $nonce = $data['angelleye_ppcp_pay_order_nonce'] ?? null;
        if (!$order || !is_string($nonce) ||
            !wp_verify_nonce(wp_unslash($nonce), 'angelleye_ppcp_pay_order_' . $order->get_id())) {
            return false;
        }
        return $order;
    }
}
