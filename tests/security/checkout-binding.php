<?php
/** Run: php tests/security/checkout-binding.php. WordPress, storage and HTTP are stubbed. */
define('ABSPATH', __DIR__);
trait WC_PPCP_Pre_Orders_Trait {}
trait WC_Gateway_Base_AngellEYE { public function has_pre_order($id) { return false; } }
class WC_Payment_Gateway {}
class WC_Payment_Gateway_CC extends WC_Payment_Gateway {}
class WP_Error {}
class BoundResponse extends Error {
    public $data; public $status;
    public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
}
class AngellEye_Session_Manager {
    public static $data = array();
    public static function get($key, $default = null) { return self::$data[$key] ?? $default; }
    public static function set($key, $value) { self::$data[$key] = $value; }
    public static function unset($key) { unset(self::$data[$key]); }
    public static function clear() { self::$data = array(); }
}
class BindingSession {
    public $data = array();
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value) { $this->data[$key] = $value; }
}
class WC_Order {}
class BindingOrder extends WC_Order {
    public $type = 'shop_order';
    public function get_type() { return $this->type; }
    public $id; public $owner = 0; public $payable = true; public $total = '10.00'; public $currency = 'USD'; public $meta = array(); public $writes = 0;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function needs_payment() { return $this->payable; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; ++$this->writes; }
    public function save_meta_data() {}
}
$user = 0; $orders = array(1 => new BindingOrder(1), 2 => new BindingOrder(2));
$store = (object) array('session' => new BindingSession());
$notices = array();
function __($text, $domain = '') { return $text; }
function get_current_user_id() { global $user; return $user; }
function WC() { global $store; return $store; }
function wc_get_order($id) { global $orders; return $orders[$id] ?? false; }
function current_user_can($cap, $id) { global $orders, $user; return $cap === 'pay_for_order' && isset($orders[$id]) && (!$orders[$id]->owner || $orders[$id]->owner === $user); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value); }
function wp_generate_uuid4() { return 'binding-request'; }
function apply_filters($name, $value, ...$args) { return $value; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'nonce:' . $action; }
function wp_unslash($value) { return stripslashes($value); }
function wc_add_notice($message, $type = 'error') { global $notices; $notices[] = $message; }
function wc_get_checkout_url() { return '/checkout'; }
function wc_get_cart_url() { return '/cart'; }
function wp_safe_redirect($url) { throw new BoundResponse(array('redirect' => $url), 302); }
function wc_clean($value) { return $value; }
function wc_clear_notices() {}
function wp_send_json_error($data, $status = 200) { throw new BoundResponse($data, $status); }
function angelleye_ppcp_get_awaiting_payment_order_id() { return WC()->session->get('order_awaiting_payment', 0); }
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-response.php';
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-request.php';
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-payment.php';
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-front-action.php';
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-smart-button.php';
require __DIR__ . '/../../ppcp-gateway/class-wc-gateway-ppcp-angelleye.php';
require __DIR__ . '/../../ppcp-gateway/class-wc-gateway-cc-angelleye.php';
class BindingHTTP extends AngellEYE_PayPal_PPCP_Request {
    public $calls = array(); public $reply;
    public function __construct() { $this->is_first_party_used = 'yes'; $this->client_id = 'client'; $this->secret_id = 'secret'; }
    public function request($url, $args, $action_name = 'default') { $this->calls[] = array($url, $args, $action_name); return $this->reply; }
}
class BindingPayment extends AngellEYE_PayPal_PPCP_Payment {
    public function __construct() {
        $this->is_sandbox = true; $this->merchant_id = 'MERCHANT'; $this->api_request = new BindingHTTP();
        $this->paypal_order_api = 'https://api-m.sandbox.paypal.com/v2/checkout/orders/';
        $this->api_log = new class { public function log(...$args) {} };
    }
    public function angelleye_ppcp_paypalauthassertion() { return ''; }
    public function angelleye_ppcp_get_number_of_decimal_digits() { return 2; }
}
$payment = new BindingPayment();
$checks = 0;
function check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } ++$checks; }
function denied($callback, $message) { try { $callback(); } catch (RuntimeException $ex) { check(true, $message); return; } throw new RuntimeException('Expected denial: ' . $message); }
function payload($id = 'PAYPALORDER') { return array('id' => $id, 'status' => 'APPROVED', 'purchase_units' => array(array(
    'reference_id' => 'server-reference', 'amount' => array('value' => '10.00', 'currency_code' => 'USD'), 'payee' => array('merchant_id' => 'MERCHANT')))); }
function bind_checkout($payment, $order_id = 0) {
    AngellEye_Session_Manager::$data = array();
    $response = payload();
    AngellEYE_PayPal_PPCP_Checkout_Binding::remember($response, array('body' => $response), $payment, $order_id);
    return AngellEye_Session_Manager::get('checkout_binding');
}
$original = bind_checkout($payment);
check($original['remote_id'] === 'PAYPALORDER' && $original['order_id'] === 0, 'Server-created express binding recorded');
check(!isset($original['secret_id']) && !isset($original['client_id']), 'Credentials are not stored in clear text');
foreach (array(null, array('PAYPALORDER'), 'OTHERORDER', 'PAYPALORDER/../../', ' PAYPALORDER', '') as $id) {
    $before = AngellEye_Session_Manager::$data;
    denied(function() use ($id, $payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::require_current($id, $payment); }, 'Foreign/malformed remote ID refused');
    check(AngellEye_Session_Manager::$data === $before, 'Denied ID preserves binding/cache');
}
foreach (array('scope' => 'foreign', 'expires' => time() - 1, 'user_id' => 99) as $key => $value) {
    AngellEye_Session_Manager::$data['checkout_binding'] = array_merge($original, array($key => $value));
    denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::require_current('PAYPALORDER', $payment); }, 'Changed scope, expired state or different account refused');
}
AngellEye_Session_Manager::$data['checkout_binding'] = $original;
$payment->is_sandbox = false;
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::require_current('PAYPALORDER', $payment); }, 'Wrong environment refused');
$payment->is_sandbox = true; $payment->merchant_id = 'OTHER';
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::require_current('PAYPALORDER', $payment); }, 'Wrong merchant refused');
$payment->merchant_id = 'MERCHANT'; $payment->api_request->client_id = 'other';
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::require_current('PAYPALORDER', $payment); }, 'Changed direct app refused');
$payment->api_request->client_id = 'client';
$orders[1]->owner = 88;
denied(function() use ($payment, $orders) { AngellEYE_PayPal_PPCP_Checkout_Binding::attach('PAYPALORDER', $payment, $orders[1]); }, 'Other customer order refused');
$orders[1]->owner = 0;
denied(function() use ($payment, $orders) { AngellEYE_PayPal_PPCP_Checkout_Binding::attach('PAYPALORDER', $payment, $orders[1]); }, 'Unrelated local order outside checkout session refused');
$store->session->set('store_api_draft_order', 1);
$attached = AngellEYE_PayPal_PPCP_Checkout_Binding::attach('PAYPALORDER', $payment, $orders[1]);
check($attached['order_id'] === 1 && $orders[1]->meta['_paypal_order_id'] === 'PAYPALORDER', 'Blocks draft order attaches to server binding');
$store->session->set('order_awaiting_payment', 2);
denied(function() use ($payment, $orders) { AngellEYE_PayPal_PPCP_Checkout_Binding::attach('PAYPALORDER', $payment, $orders[2]); }, 'Binding cannot move to a different local order');
$orders[1]->payable = false;
denied(function() use ($payment, $orders) { AngellEYE_PayPal_PPCP_Checkout_Binding::attach('PAYPALORDER', $payment, $orders[1]); }, 'Already paid/cancelled order refused');
$orders[1]->payable = true;
foreach (array('reference_id', 'amount', 'payee') as $field) {
    $response = payload();
    $response['purchase_units'][0][$field] = $field === 'reference_id' ? 'foreign-ref' : ($field === 'amount' ? array('value' => '11.00', 'currency_code' => 'USD') : array('merchant_id' => 'OTHER'));
    denied(function() use ($payment, $response) { AngellEYE_PayPal_PPCP_Checkout_Binding::verify_response('PAYPALORDER', $payment, $response); }, 'Remote reference/amount/payee mismatch refused');
}
$response = payload(); $response['purchase_units'][0]['amount']['currency_code'] = 'AUD';
denied(function() use ($payment, $response) { AngellEYE_PayPal_PPCP_Checkout_Binding::verify_response('PAYPALORDER', $payment, $response); }, 'Remote currency mismatch refused');
$payment->api_request->reply = payload();
AngellEYE_PayPal_PPCP_Checkout_Binding::before_mutation('PAYPALORDER', $payment, $orders[1]);
check(count($payment->api_request->calls) === 1 && $payment->api_request->calls[0][2] === 'get_order', 'Fresh read-only verification before capture');
$orders[1]->total = '11.00';
$count = count($payment->api_request->calls);
denied(function() use ($payment, $orders) { AngellEYE_PayPal_PPCP_Checkout_Binding::before_mutation('PAYPALORDER', $payment, $orders[1]); }, 'Changed local amount blocks capture');
check(count($payment->api_request->calls) === $count, 'Local mismatch does not send payment request');
$patches = array(array('op' => 'replace', 'path' => "/purchase_units/@reference_id=='server-reference'", 'value' => array('amount' => array('value' => '11.00', 'currency_code' => 'USD'))));
$updated = AngellEYE_PayPal_PPCP_Checkout_Binding::prepare_update('PAYPALORDER', $payment, $orders[1], $patches);
check($patches[0]['value']['payee']['merchant_id'] === 'MERCHANT', 'Server patch retains bound payee');
check(AngellEye_Session_Manager::get('checkout_binding')['units']['server-reference']['amount'] === '10', 'Preparing patch does not advance expected amount');
AngellEYE_PayPal_PPCP_Checkout_Binding::commit_update($updated);
check(AngellEye_Session_Manager::get('checkout_binding')['units']['server-reference']['amount'] === '11', 'Confirmed patch advances binding');
$bad = $patches; $bad[0]['value']['payee']['merchant_id'] = 'OTHER';
denied(function() use ($payment, $orders, &$bad) { AngellEYE_PayPal_PPCP_Checkout_Binding::prepare_update('PAYPALORDER', $payment, $orders[1], $bad); }, 'Patch cannot substitute a different payee');
$bad = array(array('op' => 'replace', 'path' => '/payer', 'value' => array()));
denied(function() use ($payment, $orders, &$bad) { AngellEYE_PayPal_PPCP_Checkout_Binding::prepare_update('PAYPALORDER', $payment, $orders[1], $bad); }, 'Unverified patch path refused');
// Gate real detail-read method before any HTTP or customer-data cache mutation.
$payment->api_request->calls = array();
$before = AngellEye_Session_Manager::$data;
check($payment->angelleye_ppcp_get_checkout_details('OTHERORDER') === null, 'Real detail method rejects foreign order');
check(!$payment->api_request->calls && AngellEye_Session_Manager::$data === $before, 'No HTTP/cache mutation on denied read');
$orders[1]->total = '10.00'; $orders[1]->meta = array();
bind_checkout($payment); $payment->api_request->reply = payload();
$details = $payment->angelleye_ppcp_get_checkout_details('PAYPALORDER');
check($details->id === 'PAYPALORDER' && AngellEye_Session_Manager::get('paypal_order_id') === 'PAYPALORDER', 'Bound detail method returns/caches verified data');
// Exercise real public dispatcher: a foreign ID never overwrites a valid session.
$front = (new ReflectionClass('AngellEYE_PayPal_PPCP_Front_Action'))->newInstanceWithoutConstructor();
$front->payment_request = $payment;
foreach (array('cc_capture', 'direct_capture', 'regular_capture', 'display_order_page') as $action) {
    $_GET = array('angelleye_ppcp_action' => $action, 'paypal_order_id' => 'OTHERORDER', 'token' => 'OTHERORDER');
    $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
    $before = AngellEye_Session_Manager::$data;
    try { $front->handle_wc_api(); throw new RuntimeException('Unbound dispatcher request continued'); }
    catch (BoundResponse $response) { check($response->status === ($action === 'cc_capture' ? 403 : 302), 'Dispatcher rejects unbound callback in the appropriate response format'); }
    check(AngellEye_Session_Manager::$data === $before, 'Rejected callback preserves valid session');
}
foreach (array(array('GET', 'nonce:woocommerce-process_checkout'), array('POST', 'bad')) as list($method, $nonce)) {
    $_GET = array('angelleye_ppcp_action' => 'cc_capture', 'paypal_order_id' => 'PAYPALORDER', 'woocommerce-process-checkout-nonce' => $nonce);
    $_SERVER['REQUEST_METHOD'] = $method;
    try { $front->handle_wc_api(); throw new RuntimeException('Invalid AJAX request continued'); }
    catch (BoundResponse $response) { check($response->status === 403, 'AJAX capture requires POST and checkout nonce'); }
}
// Cached data must not bypass checks in classic/Blocks field-prefill hooks.
$smart = (new ReflectionClass('AngellEYE_PayPal_PPCP_Smart_Button'))->newInstanceWithoutConstructor();
$smart->payment_request = $payment;
foreach (array('copy_checkout_details_to_post', 'angelleye_ppcp_session_manager', 'angelleye_ppcp_update_checkout_field_details', 'angelleye_ppcp_block_set_address') as $method) {
    $_GET = array('paypal_order_id' => 'OTHERORDER');
    $smart->checkout_details = payload(); $before = AngellEye_Session_Manager::$data;
    $smart->$method();
    check(AngellEye_Session_Manager::$data === $before, 'Prefill hook rejects query-selected cached data');
}
// Both gateway processors reject foreign Store API IDs before local writes.
foreach (array('WC_Gateway_PPCP_AngellEYE', 'WC_Gateway_CC_AngellEYE') as $class) {
    $gateway = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $gateway->payment_request = $payment; $gateway->paymentaction = 'capture';
    $_POST = array('paypal_order_id' => 'OTHERORDER');
    $writes = $orders[1]->writes; $before = AngellEye_Session_Manager::$data;
    $gateway->process_payment(1);
    check($orders[1]->writes === $writes && AngellEye_Session_Manager::$data === $before, 'Gateway rejects foreign Blocks ID before order/session changes');
}
// Real mutation entry points must refuse an unbound ID before operation/HTTP work.
AngellEye_Session_Manager::set('paypal_order_id', 'OTHERORDER');
$payment->api_request->calls = array();
check($payment->angelleye_ppcp_order_capture_request(1) === false, 'Real capture entry point rejects foreign session ID');
check($payment->angelleye_ppcp_order_auth_request(1) === false, 'Real authorization entry point rejects foreign session ID');
check($payment->angelleye_ppcp_update_order($orders[1]) === false, 'Real patch entry point rejects foreign session ID');
check(!$payment->api_request->calls, 'No mutation HTTP from unbound entry points');
// Missing bindings are never reconstructed from an old _paypal_order_id.
AngellEye_Session_Manager::$data = array();
$orders[1]->meta['_paypal_order_id'] = 'PAYPALORDER';
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::require_current('PAYPALORDER', $payment); }, 'Legacy order metadata is not a trusted checkout binding');
// A recovered create keeps its original deadline; expired approval cannot be renewed.
$orders[1]->meta = array();
$record = bind_checkout($payment, 1);
$record['expires'] = time() + 30;
AngellEye_Session_Manager::set('checkout_binding', $record);
AngellEYE_PayPal_PPCP_Checkout_Binding::remember(payload(), array('body' => payload()), $payment, 1);
check(AngellEye_Session_Manager::get('checkout_binding')['expires'] === $record['expires'], 'Recovered create does not extend callback lifetime');
$record['expires'] = time() - 1;
AngellEye_Session_Manager::set('checkout_binding', $record);
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::remember(payload(), array('body' => payload()), $payment, 1); }, 'Expired create recovery cannot renew a binding');
// Rejected create responses must not overwrite a valid current checkout.
$original = bind_checkout($payment);
foreach (array('id', 'reference', 'amount', 'currency', 'payee', 'status') as $field) {
    $reply = payload();
    if ($field === 'id') $reply['id'] = '../unsafe';
    if ($field === 'reference') $reply['purchase_units'][0]['reference_id'] = 'OTHER';
    if ($field === 'amount') $reply['purchase_units'][0]['amount']['value'] = '9.00';
    if ($field === 'currency') $reply['purchase_units'][0]['amount']['currency_code'] = 'AUD';
    if ($field === 'payee') $reply['purchase_units'][0]['payee']['merchant_id'] = 'OTHER';
    if ($field === 'status') $reply['status'] = 'VOIDED';
    denied(function() use ($payment, $reply) { AngellEYE_PayPal_PPCP_Checkout_Binding::remember($reply, array('body' => payload()), $payment); }, 'Mismatched create response refused');
    check(AngellEye_Session_Manager::get('checkout_binding') === $original, 'Failed create preserves previous checkout binding');
}
// Multi-unit sums use decimal strings, with no float rounding tolerance.
$split = payload();
$split['purchase_units'][0]['amount']['value'] = '0.10';
$split['purchase_units'][] = array('reference_id' => 'second-ref', 'amount' => array('value' => '0.20', 'currency_code' => 'USD'), 'payee' => array('merchant_id' => 'SECOND'));
AngellEYE_PayPal_PPCP_Checkout_Binding::remember($split, array('body' => $split), $payment);
$store->session->set('order_awaiting_payment', 2);
$orders[2]->total = '0.30'; $orders[2]->meta = array();
$payment->api_request->reply = $split;
AngellEYE_PayPal_PPCP_Checkout_Binding::before_mutation('PAYPALORDER', $payment, $orders[2]);
check(AngellEye_Session_Manager::get('checkout_binding')['order_id'] === 2, 'Split-payee decimal total attaches and verifies');
$orders[2]->total = '0.30000000000000001';
denied(function() use ($payment, $orders) { AngellEYE_PayPal_PPCP_Checkout_Binding::before_mutation('PAYPALORDER', $payment, $orders[2]); }, 'Tiny mismatched decimal total is refused exactly');
// Login during a guest checkout can continue, but a known user's binding cannot move accounts.
$orders[2]->total = '10'; $orders[2]->meta = array();
bind_checkout($payment); $user = 7;
check(AngellEYE_PayPal_PPCP_Checkout_Binding::require_current('PAYPALORDER', $payment)['user_id'] === 0, 'Guest checkout survives login in its retained WC session');
bind_checkout($payment); $user = 8;
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::require_current('PAYPALORDER', $payment); }, 'Known account binding rejects another login');
$user = 0;
$orders[1]->meta = array('_paypal_order_id' => 'PAYPALORDER');
AngellEye_Session_Manager::$data = array();
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::remember(payload(), array('body' => payload()), $payment, 1); }, 'Recovered remote order cannot mint approval for a different/legacy browser session');
$orders[1]->meta = array();
denied(function() use ($payment) { AngellEYE_PayPal_PPCP_Checkout_Binding::remember(payload(), array('body' => payload()), $payment, 0, time() - 10801); }, 'Durable operation creation time limits approval lifetime even after session loss');
// Valid callback routing still works: browser redirects rely on the WC binding,
// while AJAX capture additionally supplies its checkout nonce.
class WC_Gateway_PPCP_AngellEYE_Settings {
    public static function instance() { return new self(); }
    public function get($key, $default) { return $default; }
}
class ValidBindingFront extends AngellEYE_PayPal_PPCP_Front_Action {
    public function __construct() {}
    public function angelleye_ppcp_regular_capture() { throw new BoundResponse('regular'); }
    public function angelleye_ppcp_direct_capture() { throw new BoundResponse('direct'); }
    public function angelleye_ppcp_display_order_page() { throw new BoundResponse('display'); }
    public function angelleye_ppcp_cc_capture() { throw new BoundResponse('ajax'); }
}
$valid_front = new ValidBindingFront(); $valid_front->payment_request = $payment;
bind_checkout($payment);
foreach (array('regular_capture' => 'regular', 'direct_capture' => 'direct', 'display_order_page' => 'display', 'cc_capture' => 'ajax') as $action => $expected) {
    $_GET = array('angelleye_ppcp_action' => $action, 'paypal_order_id' => 'PAYPALORDER', 'token' => 'PAYPALORDER', 'paypal_payer_id' => 'PAYER');
    $_POST = array(); $_SERVER['REQUEST_METHOD'] = $action === 'cc_capture' ? 'POST' : 'GET';
    if ($action === 'cc_capture') $_POST['woocommerce-process-checkout-nonce'] = 'nonce:woocommerce-process_checkout';
    try { $valid_front->handle_wc_api(); throw new RuntimeException('Callback did not reach expected handler'); }
    catch (BoundResponse $response) { check($response->data === $expected, 'Valid bound callback reaches the correct handler'); }
}
// A JSON-encoded create request and canonical decimal formatting remain valid.
$orders[1]->meta = array();
$body = payload(); $reply = payload(); $reply['purchase_units'][0]['amount']['value'] = '10';
AngellEYE_PayPal_PPCP_Checkout_Binding::remember($reply, array('body' => json_encode($body)), $payment);
check(AngellEye_Session_Manager::get('checkout_binding')['units']['server-reference']['amount'] === '10', 'JSON create body and equivalent decimal representations match');
$store->session->set('order_awaiting_payment', 2);
$orders[2]->total = '20.00'; $orders[2]->meta = array();
$payment->api_request->calls = array();
denied(function() use ($payment) { $payment->angelleye_ppcp_update_woo_order_data('PAYPALORDER'); }, 'Completed-order display cannot fulfill a different local amount');
check(!$payment->api_request->calls, 'Display amount mismatch rejected before remote customer read or completion');
// A valid remote ID with a mismatched local session is also rejected before
// normal handler failure cleanup could clear the legitimate binding.
$orders[1]->meta = array(); $orders[1]->total = '10';
bind_checkout($payment, 1);
$store->session->set('order_awaiting_payment', 2);
$_GET = array('angelleye_ppcp_action' => 'cc_capture', 'paypal_order_id' => 'PAYPALORDER');
$_POST = array('woocommerce-process-checkout-nonce' => 'nonce:woocommerce-process_checkout');
$_SERVER['REQUEST_METHOD'] = 'POST';
$before = AngellEye_Session_Manager::$data;
try { $front->handle_wc_api(); throw new RuntimeException('Mismatched local callback continued'); }
catch (BoundResponse $response) { check($response->status === 403, 'Local order mismatch refused in callback preflight'); }
check(AngellEye_Session_Manager::$data === $before, 'Local order mismatch preserves original checkout binding');
$orders[1]->type = 'shop_order_refund';
denied(function() use ($payment, $orders) { AngellEYE_PayPal_PPCP_Checkout_Binding::attach('PAYPALORDER', $payment, $orders[1]); }, 'A non-order resource cannot be bound as a checkout payment');
$orders[1]->type = 'shop_order';
echo "$checks checkout binding checks passed.\n";
