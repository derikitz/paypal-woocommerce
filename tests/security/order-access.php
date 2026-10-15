<?php
/** Run: php tests/security/order-access.php. HTTP and WooCommerce are stubbed. */
define('ABSPATH', __DIR__);
trait WC_Gateway_Base_AngellEYE {}
trait WC_PPCP_Pre_Orders_Trait {}
class AccessTestResult extends Error {
    public $payload;
    public $status;
    public function __construct($payload, $status = 200) { $this->payload = $payload; $this->status = $status; }
}
class WC_Order {
    public $id = 1;
    public $owner = 12;
    public $key = 'wc_order_secret';
    public $payable = true;
    public $type = 'shop_order';
    public function get_id() { return $this->id; }
    public function get_order_key() { return $this->key; }
    public function needs_payment() { return $this->payable; }
    public function get_type() { return $this->type; }
}
class AccessSession {
    public $values = array('order_awaiting_payment' => 999);
    public $cookies = 0;
    public function set($key, $value) { $this->values[$key] = $value; }
    public function has_session() { return false; }
    public function set_customer_session_cookie($value) { ++$this->cookies; }
}
class AngellEye_Session_Manager {
    public static $changes = array();
    public static function set($key, $value) { self::$changes[$key] = $value; }
    public static function unset($key) { self::$changes[$key] = null; }
}
class AngellEYE_PayPal_PPCP_Payment {
    public $requests = 0;
    public function angelleye_ppcp_create_order_request($id = null) {
        ++$this->requests;
        throw new AccessTestResult(array('created_for' => $id));
    }
    public function ae_get_updated_checkout_payment_data($order = null) {
        ++$this->requests;
        return array('totals_for' => $order ? $order->get_id() : 0);
    }
}
$order = new WC_Order();
$user = 12;
$before_pay_hooks = 0;
$notices = array();
$store = (object) array('session' => new AccessSession(), 'cart' => new class { public function get_total($context) { return 10; } });
function WC() { global $store; return $store; }
function wc_get_order($id) { global $order; return (int)$id === $order->id ? $order : false; }
function current_user_can($cap, $id) { global $order, $user; return $cap === 'pay_for_order' && ($order->owner === 0 || $user === $order->owner); }
function wp_verify_nonce($nonce, $action) { return $nonce === 'nonce:' . $action; }
function wp_unslash($value) { return stripslashes($value); }
function __($value, $domain = '') { return $value; }
function wc_clear_notices() {}
function wc_get_notices($type) { global $notices; return $notices; }
function do_action($name, ...$args) { global $before_pay_hooks; if ($name === 'woocommerce_before_pay_action') { ++$before_pay_hooks; } }
function wp_send_json_error($payload, $status = 200) { throw new AccessTestResult($payload, $status); }
function wp_send_json($payload, $status = 200) { throw new AccessTestResult($payload, $status); }
function esc_attr($value) { return htmlspecialchars((string)$value, ENT_QUOTES); }
function wp_nonce_field($action, $name, $referer = true) { echo '<input name="' . esc_attr($name) . '" value="nonce:' . esc_attr($action) . '" />'; }
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-front-action.php';
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-smart-button.php';
$handler = (new ReflectionClass('AngellEYE_PayPal_PPCP_Front_Action'))->newInstanceWithoutConstructor();
$handler->payment_request = new AngellEYE_PayPal_PPCP_Payment();
$smart = (new ReflectionClass('AngellEYE_PayPal_PPCP_Smart_Button'))->newInstanceWithoutConstructor();
$checks = 0;
function check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } ++$checks; }
$valid = array('woo_order_id' => '1', 'woo_order_key' => 'wc_order_secret',
    'angelleye_ppcp_pay_order_nonce' => 'nonce:angelleye_ppcp_pay_order_1');
$_SERVER['REQUEST_METHOD'] = 'POST';
check(AngellEYE_PayPal_PPCP_Order_Access::authorize_request($valid) === $order, 'Owner and key authorized');
$user = 0;
check(!AngellEYE_PayPal_PPCP_Order_Access::authorize_request($valid), 'Account order requires its owner');
$order->owner = 0;
check(AngellEYE_PayPal_PPCP_Order_Access::authorize_request($valid) === $order, 'Guest with key authorized');
$order->owner = 12; $user = 12;
$invalid = array();
foreach (array('woo_order_id', 'woo_order_key', 'angelleye_ppcp_pay_order_nonce') as $field) {
    $data = $valid; unset($data[$field]); $invalid[] = $data;
    $data = $valid; $data[$field] = array('unexpected'); $invalid[] = $data;
}
foreach (array('0', '-1', '01', '1junk', '1.0', ' 1', '2', '999999999999999999999999999999999999', 1.1) as $id) {
    $invalid[] = array_merge($valid, array('woo_order_id' => $id));
}
$invalid[] = array_merge($valid, array('woo_order_key' => 'wrong'));
$invalid[] = array_merge($valid, array('angelleye_ppcp_pay_order_nonce' => 'nonce:angelleye_ppcp_pay_order_2'));
foreach (array('create_order', 'shipping_address_update') as $action) {
    $_GET = array('angelleye_ppcp_action' => $action, 'from' => 'pay_page');
    foreach ($invalid as $_POST) {
        $before = $handler->payment_request->requests;
        try { $handler->handle_wc_api(); throw new RuntimeException('Denied request continued'); }
        catch (AccessTestResult $result) {
            check($result->status === 403, 'Invalid request denied');
            check($handler->payment_request->requests === $before, 'Denied request makes no payment/totals call');
            check($store->session->values['order_awaiting_payment'] === 999 && $store->session->cookies === 0 &&
                !AngellEye_Session_Manager::$changes && !$before_pay_hooks, 'No denied request mutates checkout state');
            check(strpos(json_encode($result->payload), 'wc_order_secret') === false && !isset($result->payload['totals_for']), 'No order key or totals disclosed');
        }
    }
}
$_POST = $valid;
foreach (array('GET', 'PUT') as $method) {
    $_SERVER['REQUEST_METHOD'] = $method;
    check(!AngellEYE_PayPal_PPCP_Order_Access::authorize_request($valid), 'POST required');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach (array('create_order', 'shipping_address_update') as $action) {
    $_GET = array('angelleye_ppcp_action' => $action, 'from' => 'checkout');
    try { $handler->handle_wc_api(); throw new RuntimeException('Route bypass accepted'); }
    catch (AccessTestResult $result) { check($result->status === 403, 'Changing source cannot bypass order authorization'); }
}
$order->payable = false;
check(!AngellEYE_PayPal_PPCP_Order_Access::authorize_request($valid), 'Paid/cancelled order refused');
$order->payable = true; $order->type = 'shop_order_refund';
check(!AngellEYE_PayPal_PPCP_Order_Access::authorize_request($valid), 'Non-payment resource refused');
$order->type = 'shop_order';
// Authorization credentials are emitted only on a valid payment link.
$wp = (object) array('query_vars' => array('order-pay' => '1'));
foreach (array('', 'wrong', array('bad'), 'wc_order_secret') as $key) {
    $_GET = array('key' => $key);
    ob_start(); $smart->angelleye_ppcp_add_order_id(); $html = ob_get_clean();
    if ($key === 'wc_order_secret') {
        check(strpos($html, 'name="woo_order_key" value="wc_order_secret"') !== false, 'Pay form sends order key');
        check(strpos($html, 'nonce:angelleye_ppcp_pay_order_1') !== false, 'Pay form sends order-bound nonce');
    } else { check($html === '', 'Invalid link cannot mint credentials'); }
}
$user = 99;
$_GET = array('key' => 'wc_order_secret');
ob_start(); $smart->angelleye_ppcp_add_order_id(); $html = ob_get_clean();
check($html === '', 'Other customer cannot mint credentials');
$user = 12;
// Valid shipping lookup returns this authorized order, before creating any payment.
$_GET = array('angelleye_ppcp_action' => 'shipping_address_update', 'from' => 'pay_page');
$_POST = $valid;
try { $handler->handle_wc_api(); } catch (AccessTestResult $result) { check(($result->payload['totals_for'] ?? 0) === 1, 'Authorized shipping lookup works'); }
$_GET['angelleye_ppcp_action'] = 'create_order';
try { $handler->handle_wc_api(); } catch (AccessTestResult $result) {
    check(($result->payload['created_for'] ?? 0) === 1, 'Authorized order sent to PayPal create');
    check($store->session->values['order_awaiting_payment'] === 1, 'Only authorized order bound to session');
    check($before_pay_hooks === 1, 'Existing WooCommerce validation hook preserved');
}
// Where WooCommerce provides guest email verification, page data and credentials
// remain unavailable until that check passes. AJAX uses the nonce minted there.
class AccessEmailVerification {
    public static function should_user_verify_order_email($id, $email, $context) {
        return $email !== 'guest@example.test';
    }
}
class_alias('AccessEmailVerification', 'Automattic\\WooCommerce\\Internal\\Utilities\\Users');
function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
$order->owner = 0; $user = 0;
$_GET = array('key' => 'wc_order_secret'); $_POST = array();
check(!AngellEYE_PayPal_PPCP_Order_Access::payment_page_order('1', 'wc_order_secret'), 'Unverified guest cannot receive page data');
ob_start(); $smart->angelleye_ppcp_add_order_id(); $html = ob_get_clean();
check($html === '', 'Unverified guest cannot mint AJAX nonce');
$_POST = array('email' => 'guest@example.test', 'check_submission' => 'bad');
check(!AngellEYE_PayPal_PPCP_Order_Access::payment_page_order('1', 'wc_order_secret'), 'Guest verification nonce required');
$_POST['check_submission'] = 'nonce:wc_verify_email';
check(AngellEYE_PayPal_PPCP_Order_Access::payment_page_order('1', 'wc_order_secret') === $order, 'Verified guest allowed');
ob_start(); $smart->angelleye_ppcp_add_order_id(); $html = ob_get_clean();
check(strpos($html, 'angelleye_ppcp_pay_order_nonce') !== false, 'Verified guest receives AJAX nonce');
echo "$checks order access checks passed.\n";
