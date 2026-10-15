<?php

defined('ABSPATH') || exit;

class AngellEYE_PayPal_PPCP_Checkout_Binding_Exception extends RuntimeException {}

/** Server-created browser checkout identity. Never reconstruct this from a return URL. */
class AngellEYE_PayPal_PPCP_Checkout_Binding {
    const KEY = 'checkout_binding';
    const TTL = 10800;

    private static function deny() {
        throw new AngellEYE_PayPal_PPCP_Checkout_Binding_Exception(__('Unable to verify this payment session. Check your order status before starting another payment.', 'paypal-for-woocommerce'));
    }

    private static function scope($payment) {
        $request = isset($payment->api_request) ? $payment->api_request : null;
        return hash('sha256', json_encode(array((bool) $payment->is_sandbox, (string) $payment->merchant_id,
            $request ? $request->is_first_party_used : null,
            $request ? $request->client_id : null, $request ? $request->secret_id : null)));
    }

    private static function money($value) {
        if (!is_scalar($value) || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', (string) $value)) { self::deny(); }
        $parts = explode('.', (string) $value, 2);
        $whole = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1] ?? '', '0');
        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }

    private static function add($left, $right) {
        $a = explode('.', $left, 2); $b = explode('.', $right, 2);
        $scale = max(strlen($a[1] ?? ''), strlen($b[1] ?? ''));
        $a = $a[0] . str_pad($a[1] ?? '', $scale, '0');
        $b = $b[0] . str_pad($b[1] ?? '', $scale, '0');
        $sum = ''; $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; --$i, --$j) {
            $digit = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $sum = ($digit % 10) . $sum; $carry = intdiv($digit, 10);
        }
        $sum = str_pad($sum, $scale + 1, '0', STR_PAD_LEFT);
        return self::money($scale ? substr($sum, 0, -$scale) . '.' . substr($sum, -$scale) : $sum);
    }

    private static function units($units) {
        if (!is_array($units) || !$units || count($units) > 10) { self::deny(); }
        $result = array();
        foreach ($units as $unit) {
            $ref = $unit['reference_id'] ?? 'default';
            $currency = $unit['amount']['currency_code'] ?? '';
            $payee = $unit['payee']['merchant_id'] ?? '';
            if (!is_string($ref) || $ref === '' || isset($result[$ref]) || !is_string($currency) ||
                !preg_match('/^[A-Z]{3}$/D', $currency) || !is_string($payee)) { self::deny(); }
            $result[$ref] = array('reference_id' => $ref, 'currency' => $currency,
                'amount' => self::money($unit['amount']['value'] ?? null), 'payee' => $payee);
        }
        ksort($result);
        return $result;
    }

    public static function remember($response, $request, $payment, $order_id = 0, $created_at = null) {
        if (!is_array($response) || !is_string($response['id'] ?? null) ||
            !preg_match('/^[A-Z0-9]{1,36}$/D', $response['id']) ||
            !in_array($response['status'] ?? '', array('CREATED', 'APPROVED', 'PAYER_ACTION_REQUIRED', 'COMPLETED'), true) || !WC()->session) { self::deny(); }
        $body = $request['body'] ?? array();
        if (is_string($body)) { $body = json_decode($body, true); }
        $expected = self::units($body['purchase_units'] ?? null);
        $actual = self::units($response['purchase_units'] ?? null);
        foreach ($expected as $ref => &$unit) {
            if (!isset($actual[$ref]) || $unit['amount'] !== $actual[$ref]['amount'] ||
                $unit['currency'] !== $actual[$ref]['currency'] ||
                ($unit['payee'] !== '' && $unit['payee'] !== $actual[$ref]['payee'])) { self::deny(); }
            $unit['payee'] = $actual[$ref]['payee'];
        }
        unset($unit);
        if (count($expected) !== count($actual)) { self::deny(); }
        $expires = ($created_at === null ? time() : (int) $created_at) + self::TTL;
        if ($expires <= time()) { self::deny(); }
        $previous = AngellEye_Session_Manager::get(self::KEY);
        $record = array('remote_id' => $response['id'], 'scope' => self::scope($payment),
            'user_id' => get_current_user_id(), 'order_id' => (int) $order_id, 'units' => $expected,
            'expires' => $expires);
        if (is_array($previous) && ($previous['remote_id'] ?? '') === $record['remote_id']) {
            // A recovered create must not renew an old approval or change its owner.
            self::require_current($record['remote_id'], $payment);
            $record['expires'] = $previous['expires'];
            $record['order_id'] = $previous['order_id'];
        }
        if ($order_id) {
            $order = wc_get_order($order_id);
            if (!$order || !is_a($order, 'WC_Order') || $order->get_type() !== 'shop_order' ||
                !$order->needs_payment() || !current_user_can('pay_for_order', $order_id)) { self::deny(); }
            if ($order->get_meta('_paypal_order_id', true) === $record['remote_id'] &&
                (!is_array($previous) || ($previous['remote_id'] ?? '') !== $record['remote_id'])) {
                // Recovering an existing order is not proof that a different browser
                // owns the original approval. Require its existing session binding.
                self::deny();
            }
            $record['order_id'] = (int) $order_id;
            $order->update_meta_data('_ae_ppcp_checkout_binding', $record);
            $order->save_meta_data();
        }
        AngellEye_Session_Manager::set(self::KEY, $record);
        AngellEye_Session_Manager::unset('paypal_transaction_details');
    }

    public static function require_current($remote_id, $payment) {
        $record = AngellEye_Session_Manager::get(self::KEY);
        if (!is_string($remote_id) || !preg_match('/^[A-Z0-9]{1,36}$/D', $remote_id) || !is_array($record) ||
            !hash_equals((string) ($record['remote_id'] ?? ''), $remote_id) ||
            !hash_equals((string) ($record['scope'] ?? ''), self::scope($payment)) ||
            ($record['expires'] ?? 0) <= time() ||
            (!empty($record['user_id']) && (int) $record['user_id'] !== get_current_user_id())) { self::deny(); }
        return $record;
    }

    public static function check_local($remote_id, $payment, $order) {
        $record = self::require_current($remote_id, $payment);
        if (!$order || !is_a($order, 'WC_Order') || $order->get_type() !== 'shop_order' ||
            !$order->needs_payment() || !current_user_can('pay_for_order', $order->get_id())) { self::deny(); }
        $id = (int) $order->get_id();
        if ($record['order_id'] && $record['order_id'] !== $id) { self::deny(); }
        if (!$record['order_id'] && $id !== (int) WC()->session->get('order_awaiting_payment') &&
            $id !== (int) WC()->session->get('store_api_draft_order')) { self::deny(); }
        $stored = $order->get_meta('_ae_ppcp_checkout_binding', true);
        $remote = $order->get_meta('_paypal_order_id', true);
        if (($stored && !is_array($stored)) || ($remote && $remote !== $remote_id) || ($stored &&
            (($stored['remote_id'] ?? '') !== $remote_id || ($stored['scope'] ?? '') !== $record['scope']))) { self::deny(); }
        $record['order_id'] = $id;
        return $record;
    }

    public static function attach($remote_id, $payment, $order) {
        $record = self::check_local($remote_id, $payment, $order);
        $order->update_meta_data('_ae_ppcp_checkout_binding', $record);
        $order->update_meta_data('_paypal_order_id', $remote_id);
        $order->save_meta_data();
        AngellEye_Session_Manager::set(self::KEY, $record);
        return $record;
    }

    public static function verify_response($remote_id, $payment, $response) {
        $record = self::require_current($remote_id, $payment);
        if (!is_array($response) || ($response['id'] ?? '') !== $remote_id ||
            self::units($response['purchase_units'] ?? null) !== $record['units']) { self::deny(); }
    }

    private static function verify_total($units, $order) {
        $total = '0';
        foreach ($units as $unit) {
            if ($unit['currency'] !== $order->get_currency()) { self::deny(); }
            $total = self::add($total, $unit['amount']);
        }
        if ($total !== self::money($order->get_total())) { self::deny(); }
    }

    public static function prepare_update($remote_id, $payment, $order, &$patches) {
        $record = self::attach($remote_id, $payment, $order);
        if (!is_array($patches) || !$patches) { self::deny(); }
        foreach ($patches as &$patch) {
            if (($patch['op'] ?? '') !== 'replace') { self::deny(); }
            if (($patch['path'] ?? '') === '/intent' && in_array($patch['value'] ?? '', array('CAPTURE', 'AUTHORIZE'), true)) { continue; }
            $matched = false;
            foreach ($record['units'] as $ref => $unit) {
                if (($patch['path'] ?? '') !== "/purchase_units/@reference_id=='$ref'") { continue; }
                $value = $patch['value'];
                $value['reference_id'] = $ref;
                if (empty($value['payee']['merchant_id']) && $unit['payee'] !== '') {
                    $value['payee']['merchant_id'] = $unit['payee'];
                    $patch['value']['payee']['merchant_id'] = $unit['payee'];
                }
                $updated = self::units(array($value));
                if ($updated[$ref]['payee'] !== $unit['payee']) { self::deny(); }
                $record['units'][$ref] = $updated[$ref]; $matched = true;
            }
            if (!$matched) { self::deny(); }
        }
        self::verify_total($record['units'], $order);
        return $record;
    }

    public static function commit_update($record) {
        if (!empty($record['order_id'])) {
            $order = wc_get_order($record['order_id']);
            if (!$order) { self::deny(); }
            $order->update_meta_data('_ae_ppcp_checkout_binding', $record);
            $order->save_meta_data();
        }
        AngellEye_Session_Manager::set(self::KEY, $record);
        AngellEye_Session_Manager::unset('paypal_transaction_details');
    }

    public static function verify_local($remote_id, $payment, $order) {
        $record = self::attach($remote_id, $payment, $order);
        self::verify_total($record['units'], $order);
    }

    public static function before_mutation($remote_id, $payment, $order) {
        self::verify_local($remote_id, $payment, $order);
        $response = $payment->angelleye_ppcp_get_paypal_order($remote_id);
        self::verify_response($remote_id, $payment, $response);
        if (!in_array($response['status'] ?? '', array('APPROVED', 'COMPLETED'), true)) { self::deny(); }
    }
}
