<?php
/** Run: php tests/security/capture-outcome.php. No WordPress installation used. */
define('ABSPATH', __DIR__ . '/');
trait WC_PPCP_Pre_Orders_Trait {}
class WP_Error {}
class CaptureResponseSent extends Error {
    public $data;
    public function __construct($data) { $this->data = $data; }
}
class AngellEye_Session_Manager {
    public static function get($key, $default = null) { return 'PAYPAL-ORDER'; }
    public static function clear() {}
}
function apply_filters($tag, $value, ...$args) { return $value; }
function angelleye_ppcp_trigger_payment_successful_result($result, $order) { return apply_filters('woocommerce_payment_successful_result', $result, $order); }
function angelleye_ppcp_get_awaiting_payment_order_id() { return 1; }
function wc_get_order($id) { return new CaptureTestOrder(); }
function wp_send_json_success($data) { throw new CaptureResponseSent($data); }
function WC() { global $store; return $store; }
class CaptureTestOrder {
    public function update_meta_data($key, $value) {}
    public function save_meta_data() {}
    public function get_checkout_order_received_url() { return '/receipt'; }
    public function get_checkout_payment_url() { return '/pay'; }
}
class CaptureTestCart {
    public $emptied = 0;
    public function empty_cart() { ++$this->emptied; }
}
class CaptureTestSession { public function set($key, $value) {} }
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-payment.php';
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-front-action.php';
class CaptureTestPayment extends AngellEYE_PayPal_PPCP_Payment {
    public $outcome;
    public function __construct() {}
    public function angelleye_ppcp_order_capture_request($woo_order_id, $need_to_update_order = true) { return $this->outcome; }
}
$handler = (new ReflectionClass('AngellEYE_PayPal_PPCP_Front_Action'))->newInstanceWithoutConstructor();
$handler->payment_request = new CaptureTestPayment();
$handler->paymentaction = 'capture';
$handler->advanced_card_payments = false;
$handler->is_sandbox = true;
$_GET['is_pay_page'] = 'yes';
$checks = 0;
foreach (array(true, false, null, array('payer_action_required' => true), new WP_Error()) as $outcome) {
    $store = (object) array('cart' => new CaptureTestCart(), 'session' => new CaptureTestSession());
    $handler->payment_request->outcome = $outcome;
    try {
        $handler->angelleye_ppcp_cc_capture();
        throw new RuntimeException('Expected capture JSON response');
    } catch (CaptureResponseSent $response) {
        $expected = $outcome === true ? 'success' : 'failure';
        if ($response->data['result'] !== $expected || $store->cart->emptied !== ($outcome === true ? 1 : 0)) {
            throw new RuntimeException('Capture result incorrectly reported success or emptied cart');
        }
        ++$checks;
    }
}
echo 'Passed ' . $checks . " server capture outcome checks.\n";
