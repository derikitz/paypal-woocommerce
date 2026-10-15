<?php
/** Run: php tests/security/payment-idempotency.php. No network or database used. */
define('ABSPATH', __DIR__ . '/');
define('VERSION_PFW', 'test');
define('AE_FEE', 'test-fee');
$options = $calls = $responses = array();
$checks = 0;
$fail_storage = false;
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function wp_generate_uuid4() {
    static $counter = 0;
    return sprintf('00000000-0000-4000-8000-%012d', ++$counter);
}
function wc_generate_order_key() { return 'wc_order_' . wp_generate_uuid4(); }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, $deprecated = '', $autoload = false) {
    global $options, $fail_storage;
    if ($fail_storage || array_key_exists($key, $options)) { return false; }
    $options[$key] = $value;
    return true;
}
function update_option($key, $value, $autoload = false) {
    global $options, $fail_storage;
    if ($fail_storage) { return false; }
    $options[$key] = $value;
    return true;
}
function delete_option($key) { global $options; unset($options[$key]); }
function expect_check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function expect_denied($callback, $message) {
    try { $callback(); } catch (RuntimeException $error) { expect_check(true, $message); return; }
    throw new RuntimeException($message);
}
class FakeOrder {
    public $meta = array();
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save_meta_data() {}
}
class FakeSession {
    public $values = array();
    public function get_customer_id() { return 'customer-1'; }
    public function get($key, $default = '') { return $this->values[$key] ?? $default; }
    public function set($key, $value) { $this->values[$key] = $value; }
}
class FakeCart { public function get_cart_hash() { return 'cart-1'; } }
$store = (object) array('session' => new FakeSession(), 'cart' => new FakeCart());
$order = new FakeOrder();
function WC() { global $store; return $store; }
function wc_get_order($id) { global $order; return $order; }
trait WC_PPCP_Pre_Orders_Trait {}
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-operation.php';
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-payment.php';

$scope = array(true, 'merchant', 'order:1', 'refund_order', 'CAPTURE-1');
$url = 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-1/refund';
$args = array('headers' => array('PayPal-Request-Id' => 'random'), 'body' => array('amount' => array('value' => '10.00', 'currency_code' => 'USD')));
$first = new AngellEYE_PayPal_PPCP_Operation($scope, 'refund_order');
$first->prepare($url, $args);
expect_check($first->has_been_submitted(), 'Prepared operation prevents re-patching the remote order on retry');
$id = $args['headers']['PayPal-Request-Id'];
$reference = $first->reference();
expect_check(strlen($id) === 36, 'Use UUID request ID');
expect_denied(function () use ($scope) { new AngellEYE_PayPal_PPCP_Operation($scope, 'refund_order'); }, 'Block concurrent worker');
$other_scope = array(true, 'merchant', 'order:1', 'capture_authorized', 'AUTH-1');
expect_denied(function () use ($other_scope) { new AngellEYE_PayPal_PPCP_Operation($other_scope, 'capture_authorized'); }, 'Serialize different operations on the same order');
$first->close();
$retry = new AngellEYE_PayPal_PPCP_Operation($scope, 'refund_order');
$reordered = array('headers' => array(), 'body' => array('amount' => array('currency_code' => 'USD', 'value' => '10.00')));
expect_check($retry->prepare($url, $reordered) === null, 'Retry an uncertain request within retention window');
expect_check($reordered['headers']['PayPal-Request-Id'] === $id && $retry->reference() === $reference, 'UUID and reference survive a new PHP operation instance');
$changed = $args;
$changed['body']['amount']['value'] = '20.00';
expect_denied(function () use ($retry, $url, &$changed) { $retry->prepare($url, $changed); }, 'Reject changed amount on unresolved operation');
$retry->record_response(null, 0);
$retry->close();

// A timeout must not rotate the durable UUID.
$retry = new AngellEYE_PayPal_PPCP_Operation($scope, 'refund_order');
$retry->prepare($url, $args);
expect_check($args['headers']['PayPal-Request-Id'] === $id, 'Timeout preserves request identity');
$retry->record_response(array('id' => 'REFUND-1', 'status' => 'COMPLETED'), 201);
expect_check($retry->prepare($url, $args) === 'https://api-m.sandbox.paypal.com/v2/payments/refunds/REFUND-1', 'Reconcile known refund with GET instead of another POST');
$retry->commit($order);
$retry->commit($order);
expect_check(count($order->meta['_ae_ppcp_refund_order_operations']['CAPTURE-1']) === 1, 'Local progress commit is idempotent');
$retry->close();

// The real payment helper uses confirmed per-resource progress for equal partials.
$payment = (new ReflectionClass('AngellEYE_PayPal_PPCP_Payment'))->newInstanceWithoutConstructor();
$payment->is_sandbox = true;
$payment->merchant_id = 'merchant';
$begin = new ReflectionMethod($payment, 'begin_payment_operation');
$begin->setAccessible(true);
$partial = $begin->invoke($payment, 1, 'refund_order', 'CAPTURE-1');
$partial->prepare($url, $args);
$second_id = $args['headers']['PayPal-Request-Id'];
expect_check($second_id !== $id, 'A later equal-amount partial refund gets a distinct UUID');
$partial->close();
$order->meta['_ae_ppcp_refund_order_operations']['CAPTURE-2'] = array('OTHER-REFUND' => true);
$partial = $begin->invoke($payment, 1, 'refund_order', 'CAPTURE-1');
$partial->prepare($url, $args);
expect_check($args['headers']['PayPal-Request-Id'] === $second_id, 'Progress on another capture must not rotate an unresolved refund');
$partial->close();

// Unknown outcomes must never be replayed after server-side retention expires.
foreach ($options as $key => &$value) {
    if (strpos($key, 'ae_ppcp_operation_') === 0) { $value['created_at'] = time() - 21601; }
}
unset($value);
$partial = $begin->invoke($payment, 1, 'refund_order', 'CAPTURE-1');
expect_denied(function () use ($partial, $url, &$args) { $partial->prepare($url, $args); }, 'Expired uncertain request stops for manual reconciliation');
$partial->close();
$known = new AngellEYE_PayPal_PPCP_Operation($scope, 'refund_order');
expect_check(strpos($known->prepare($url, $args), '/refunds/REFUND-1') !== false, 'Known remote ID can still be reconciled after retention expires');
$known->close();

foreach (array('create_order' => '/v2/checkout/orders/REMOTE', 'capture_order' => '/v2/checkout/orders/REMOTE', 'authorize_order' => '/v2/checkout/orders/REMOTE', 'capture_authorized' => '/v2/payments/captures/REMOTE') as $action => $path) {
    $op = new AngellEYE_PayPal_PPCP_Operation(array(true, 'merchant', 'order:' . $action, $action, 'RESOURCE'), $action);
    $op->prepare($url, $args);
    $op->record_response(array('id' => 'REMOTE'), 201);
    expect_check($op->prepare($url, $args) === 'https://api-m.sandbox.paypal.com' . $path, 'Correct reconciliation resource for ' . $action);
    $op->close();
}

// Decline recovery can start a corrected operation; an already-captured error cannot.
$decline_scope = array(true, 'merchant', 'order:decline', 'create_order', '');
$op = new AngellEYE_PayPal_PPCP_Operation($decline_scope, 'create_order');
$op->prepare($url, $args);
$declined_id = $args['headers']['PayPal-Request-Id'];
$op->record_response(array('details' => array(array('issue' => 'INSTRUMENT_DECLINED'))), 422);
$op->close();
$op = new AngellEYE_PayPal_PPCP_Operation($decline_scope, 'create_order');
$op->prepare($url, $args);
expect_check($args['headers']['PayPal-Request-Id'] !== $declined_id, 'Definitive decline permits a new attempt');
$retained_id = $args['headers']['PayPal-Request-Id'];
$op->record_response(array('details' => array(array('issue' => 'ORDER_ALREADY_CAPTURED'))), 422);
$op->close();
$op = new AngellEYE_PayPal_PPCP_Operation($decline_scope, 'create_order');
$op->prepare($url, $args);
expect_check($args['headers']['PayPal-Request-Id'] === $retained_id, 'Already-captured response never rotates the key');
$op->close();

$checkout = $begin->invoke($payment, null, 'create_order', '');
$checkout->prepare($url, $args);
$checkout_id = $args['headers']['PayPal-Request-Id'];
$checkout->close();
WC()->session->set('ae_ppcp_checkout_generation', 'NEXT-PURCHASE');
$checkout = $begin->invoke($payment, null, 'create_order', '');
$checkout->prepare($url, $args);
expect_check($args['headers']['PayPal-Request-Id'] !== $checkout_id, 'Next completed checkout may purchase the same cart again');
$checkout->close();

// Locks have no unsafe automatic takeover; only their owner can release them.
$op = new AngellEYE_PayPal_PPCP_Operation(array(true, 'merchant', 'owner', 'create_order', ''), 'create_order');
$lock_key = null;
foreach ($options as $key => $value) { if (strpos($key, 'ae_ppcp_lock_') === 0) { $lock_key = $key; } }
$options[$lock_key]['owner'] = 'another-owner';
$op->close();
expect_check(isset($options[$lock_key]), 'Never delete another owner lock');
delete_option($lock_key);
$fail_storage = true;
expect_denied(function () { new AngellEYE_PayPal_PPCP_Operation(array(true, 'merchant', 'failed', 'create_order', ''), 'create_order'); }, 'Storage failure prevents payment');
$fail_storage = false;
expect_check(!AngellEYE_PayPal_PPCP_Operation::current('refund_order'), 'Closing operation clears active context');
$op = new AngellEYE_PayPal_PPCP_Operation(array(true, 'merchant', 'persist-failure', 'create_order', ''), 'create_order');
$fail_storage = true;
expect_denied(function () use ($op, $url, &$args) { $op->prepare($url, $args); }, 'Failure to persist submitted state must stop before payment');
$fail_storage = false;
$op->close();

// Replay bookkeeping must not inflate an item's refunded amount.
$item_meta = array('_ppcp_capture_details' => array(array('_ppcp_transaction_id' => 'CAPTURE', 'total_refund_amount' => '0')));
function wc_get_order_item_meta($item_id, $key, $single) { global $item_meta; return $item_meta[$key] ?? ''; }
function wc_update_order_item_meta($item_id, $key, $value) { global $item_meta; $item_meta[$key] = $value; }
$payment->angelleye_ppcp_update_capture_details('CAPTURE', 'REFUND', '10.00', 1);
$payment->angelleye_ppcp_update_capture_details('CAPTURE', 'REFUND', '10.00', 1);
expect_check(count($item_meta['_ppcp_capture_details'][0]['refund']) === 1 && $item_meta['_ppcp_capture_details'][0]['total_refund_amount'] == 10, 'Recovered refund must only count once locally');
$payment->angelleye_ppcp_update_capture_details('CAPTURE', 'REFUND-2', '10.00', 1);
expect_check($item_meta['_ppcp_capture_details'][0]['total_refund_amount'] == 20, 'Distinct equal-amount refund must count separately');

$capture_scope = array(true, 'merchant', 'order:1', 'capture_authorized', 'AUTH');
$capture = $begin->invoke($payment, 1, 'capture_authorized', 'AUTH');
$capture->prepare($url, $args);
$capture_id = $args['headers']['PayPal-Request-Id'];
$capture->record_response(array('id' => 'CAPTURE-A', 'status' => 'COMPLETED'), 201);
$capture->commit($order);
$capture->close();
$capture = $begin->invoke($payment, 1, 'capture_authorized', 'AUTH');
$capture->prepare($url, $args);
expect_check($args['headers']['PayPal-Request-Id'] !== $capture_id, 'Distinct equal-amount partial captures get separate UUIDs');
$capture->close();

// A multi-capture batch keeps the order lock while child operations finish.
$batch_scope = array(true, 'merchant', 'order:batch', 'refund_batch', '');
$batch = new AngellEYE_PayPal_PPCP_Operation($batch_scope, 'refund_batch');
$batch->start_batch();
$child = new AngellEYE_PayPal_PPCP_Operation(array(true, 'merchant', 'order:batch', 'refund_order', 'CAPTURE'), 'refund_order');
$child->close();
$lock_count = count(array_filter(array_keys($options), function ($key) { return strpos($key, 'ae_ppcp_lock_') === 0; }));
expect_check($lock_count === 1, 'Child completion must not release batch lock');
$batch->close();
$batch = new AngellEYE_PayPal_PPCP_Operation($batch_scope, 'refund_batch');
expect_denied(function () use ($batch) { $batch->start_batch(); }, 'Interrupted batch must stop for reconciliation');
$batch->finish_batch();
$batch->close();
$batch = new AngellEYE_PayPal_PPCP_Operation($batch_scope, 'refund_batch');
$batch->start_batch();
expect_check($batch->has_been_submitted(), 'Completed batch permits a distinct later refund');
$batch->finish_batch();
$batch->close();

// Exercise the real gateway trait: WP_Error must not count as a refund success.
class WP_Error {
    public function __construct($code, $message) { $this->message = $message; }
    private $message;
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function apply_filters($name, $value, ...$args) { return $value; }
function angelleye_ppcp_order_item_meta_key_exists($order, $key) { return true; }
function __($message, $domain) { return $message; }
trait AngellEye_PPCP_Core {}
require dirname(__DIR__, 2) . '/ppcp-gateway/class-wc-gateway-base-angelleye.php';
class FakeRefundPayment {
    public $is_sandbox = true;
    public $merchant_id = 'merchant';
    public $attempts = 0;
    public $fail = true;
    public function angelleye_ppcp_prepare_refund_request_data_for_capture($order, $amount) { return array(1 => array('CAPTURE' => $amount)); }
    public function angelleye_ppcp_refund_capture_order(...$args) {
        ++$this->attempts;
        return $this->fail ? new WP_Error('failed', 'Uncertain refund') : true;
    }
}
class FakeRefundGateway {
    use WC_Gateway_Base_AngellEYE;
    public $payment_request;
    public function can_refund_order($order) { return true; }
}
$gateway = new FakeRefundGateway();
$gateway->payment_request = new FakeRefundPayment();
expect_check(is_wp_error($gateway->process_refund(900, 10)), 'Gateway propagates WP_Error rather than treating it as success');
$gateway->payment_request->fail = false;
expect_check(is_wp_error($gateway->process_refund(900, 10)) && $gateway->payment_request->attempts === 1, 'Unresolved batch must not recompute and resend completed portions');
expect_check($gateway->process_refund(901, 10) === true, 'Complete batch succeeds');
expect_check($gateway->process_refund(901, 10) === true, 'A later batch may refund the same amount');

echo 'Passed ' . $checks . " payment idempotency checks.\n";
