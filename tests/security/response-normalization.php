<?php
/** Run: php tests/security/response-normalization.php. HTTP/WordPress are stubbed. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_remote_retrieve_headers($response) { return $response['headers'] ?? array(); }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function wp_generate_uuid4() { static $id = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$id); }
function wc_generate_order_key() { return 'wc_order_' . wp_generate_uuid4(); }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, $deprecated = '', $autoload = false) { global $options; if (isset($options[$key])) { return false; } $options[$key] = $value; return true; }
function update_option($key, $value, $autoload = false) { global $options; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); }
$health_resets = $hooks = $checks = 0;
function delete_transient($name) { global $health_resets; ++$health_resets; }
function do_action($name, ...$args) { global $hooks, $hook_args; ++$hooks; $hook_args = $args; }
function expect_check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function raw_response($code, $body = '', $headers = array()) {
    return array('response' => array('code' => $code), 'body' => $body, 'headers' => $headers);
}
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-response.php';
class TestNormalizedResponse extends AngellEYE_PayPal_PPCP_Response {
    public $logs = 0;
    public $throw_logger = false;
    public function __construct() {}
    public function angelleye_ppcp_write_log($url, $request, $response, $action_name = 'Exception') {
        ++$this->logs;
        if ($this->throw_logger) { throw new RuntimeException('Logger unavailable'); }
    }
}
$parser = new TestNormalizedResponse();
$url = 'https://api-m.sandbox.paypal.com/v2/checkout/orders';
function parse_test($response, $action = 'capture_order') {
    global $parser, $url;
    return $parser->parse_response($response, $url, array(), $action);
}
foreach (array(200, 201, 202) as $status) {
    $payload = array('id' => 'ORDER', 'status' => 'COMPLETED');
    expect_check(parse_test(raw_response($status, json_encode($payload))) === $payload, 'Preserve successful resource body');
}
expect_check(parse_test(raw_response(200, '[]')) === array(), 'Preserve successful empty JSON collection');
$before = $health_resets;
expect_check(parse_test(raw_response(204), 'update_order') === '' && $before === $health_resets, 'Successful no-content must not invalidate proxy health');
$error = parse_test(new WP_Error('http_request_failed', 'Certificate verification failed'));
expect_check($error['transport_error'] === 'http_request_failed' && $error['http_status'] === 0 && $error['message'] === 'Certificate verification failed', 'Return transport failure with code and message');
expect_check(!isset($error['status'], $error['id']) && AngellEYE_PayPal_PPCP_Response::is_error($error), 'Errors cannot look like payment resources');

$before = $hooks;
$api_error = array('name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Declined', 'debug_id' => 'BODY-DEBUG',
    'details' => array(array('issue' => 'INSTRUMENT_DECLINED')), 'id' => 'UNTRUSTED-ID', 'status' => 'COMPLETED',
    'links' => array(array('rel' => 'payer-action', 'href' => 'https://www.paypal.com/action')));
$error = parse_test(raw_response(422, json_encode($api_error)));
expect_check($error['name'] === 'UNPROCESSABLE_ENTITY' && $error['issue'] === 'INSTRUMENT_DECLINED' && $error['http_status'] === 422, 'Preserve PayPal error identifiers');
expect_check($error['debug_id'] === 'BODY-DEBUG' && $error['details'][0]['description'] === 'Declined', 'Preserve debug ID and supply safe readable detail defaults');
expect_check(!isset($error['id']) && !isset($error['status']) && $hooks === $before, 'Failed HTTP response must not masquerade as success or emit success tracking');
expect_check($error['links'] === $api_error['links'], 'Preserve payer-action links');
foreach (array('', '<html>Gateway error</html>', 'null', 'false', '"string"', '{broken') as $body) {
    $error = parse_test(raw_response(200, $body));
    expect_check($error['name'] === 'INVALID_RESPONSE' && $error['http_status'] === 200, 'Reject malformed/empty success body');
}
$error = parse_test(raw_response(503, '<html>PRIVATE-BODY</html>', array('PayPal-Debug-Id' => 'HEADER-DEBUG')));
expect_check($error['http_status'] === 503 && $error['debug_id'] === 'HEADER-DEBUG' && strpos(json_encode($error), 'PRIVATE-BODY') === false, 'HTTP failure preserves header debug ID without exposing raw HTML');
$error = parse_test(raw_response(401, json_encode(array('error' => 'invalid_client', 'error_description' => 'No permissions'))));
expect_check($error['name'] === 'invalid_client' && $error['error_description'] === 'No permissions' && $error['http_status'] === 401, 'Preserve OAuth compatibility fields');
$error = parse_test(raw_response(200, json_encode(array('error' => array('malformed'), 'error_description' => array('malformed'), 'links' => 'malformed'))));
expect_check(AngellEYE_PayPal_PPCP_Response::is_error($error) && !isset($error['error_description']) && !isset($error['links']), 'Malformed OAuth error fields must not reach string/array consumers');

foreach (array($api_error, json_encode($api_error)) as $body) {
    $envelope = array('statusCode' => 422, 'headers' => "HTTP/1.1 422 Error\r\nPayPal-Debug-Id: PROXY-DEBUG\r\n", 'body' => $body);
    $error = parse_test(raw_response(200, json_encode($envelope)));
    expect_check($error['http_status'] === 422 && $error['issue'] === 'INSTRUMENT_DECLINED', 'Unwrap proxy error body and upstream HTTP code');
}
$error = parse_test(raw_response(200, json_encode(array('body' => array('name' => 'INTERNAL_SERVER_ERROR', 'message' => 'Failed'), 'headers' => array('PAYPAL-DEBUG-ID' => 'PROXY-DEBUG')))));
expect_check($error['debug_id'] === 'PROXY-DEBUG' && AngellEYE_PayPal_PPCP_Response::is_error($error), 'Recognize proxy API errors even when outer HTTP succeeded');
$error = parse_test(raw_response(200, json_encode(array('status' => 'failed', 'body' => array('error_code' => 'proxy_timeout', 'error_message' => 'Upstream timed out')))));
expect_check($error['transport_error'] === 'proxy_timeout' && $error['message'] === 'Upstream timed out', 'Normalize legacy proxy transport failure');
expect_check(parse_test(raw_response(200, json_encode(array('statusCode' => 204, 'body' => '')))) === '', 'Preserve proxied 204 no-content success');
$payload = array('id' => 'ORDER', 'status' => 'CREATED');
expect_check(parse_test(raw_response(200, json_encode(array('body' => $payload)))) === $payload, 'Preserve proxied success representation');

foreach (array(array('PayPal-Debug-Id' => 'CASE'), array('PayPal-Debug-Id: CASE'), "PayPal-Debug-Id: CASE\r\n", new ArrayObject(array('PAYPAL-DEBUG-ID' => 'CASE'))) as $headers) {
    expect_check($parser->angelleye_ppcp_parse_headers($headers, 'paypal-debug-id') === 'CASE', 'Parse debug header representations safely');
}
$parser->throw_logger = true;
expect_check(parse_test(raw_response(201, json_encode($payload))) === $payload, 'Logging failure must not discard successful payment');
$parser->throw_logger = false;
$before = $parser->logs;
expect_check($parser->parse_response(raw_response(200, '{"id_token":"PRIVATE"}'), $url, array(), 'generate_id_token', false)['id_token'] === 'PRIVATE' && $parser->logs === $before, 'Sensitive OAuth parsing bypasses body logger');

// Real request wrapper must normalize exceptions and direct ID-token failures.
class AngellEYE_PayPal_PPCP_Mock { public function is_enabled() { return false; } }
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-request.php';
$http_result = raw_response(204);
$throw_http = false;
function wp_remote_get($url, $args) { global $http_result, $throw_http; if ($throw_http) { throw new RuntimeException('Transport exception'); } return $http_result; }
function wp_remote_post($url, $args) { return wp_remote_get($url, $args); }
class TestNormalizedRequest extends AngellEYE_PayPal_PPCP_Request {
    public function __construct($parser) {
        $this->api_response = $parser;
        $this->api_log = new class { public function log(...$args) {} };
        $this->is_first_party_used = 'yes';
        $this->token_url = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
        $this->basicAuth = base64_encode('client:secret');
    }
    public function angelleye_get_settings() {}
}
$request = new TestNormalizedRequest($parser);
$throw_http = true;
$error = $request->request('https://external.example/request', array('headers' => array()));
expect_check($error['transport_error'] === 'paypal_request_exception', 'Normalize request exception instead of returning null');
$error = $request->multi_account_request('https://external.example/request', array());
expect_check($error['transport_error'] === 'paypal_request_exception', 'Normalize multi-account exception');
$throw_http = false;
$http_result = new WP_Error('http_request_failed', 'ID token transport failure');
$before = $parser->logs;
$error = $request->request($request->token_url, array('headers' => array()), 'generate_id_token');
expect_check($error['transport_error'] === 'http_request_failed' && $parser->logs === $before, 'Normalize ID-token failure without logging token response');
$token_error = $error;
$http_result = raw_response(200, json_encode(array('statusCode' => 422, 'body' => array('name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Declined', 'details' => array(array('issue' => 'INSTRUMENT_DECLINED'))))));
$error = $request->request('https://external.example/request', array('headers' => array(), 'angelleye_operation_scope' => array('order_id' => 1, 'resource' => 'offer:1')), 'create_order');
$records = array_filter($options, function ($value) { return is_array($value) && isset($value['request_id']); });
expect_check($error['http_status'] === 422 && reset($records)['state'] === 'rejected', 'Durable operation sees upstream 422 rather than successful proxy HTTP status');

trait WC_PPCP_Pre_Orders_Trait {}
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-payment.php';
$payment = (new ReflectionClass('AngellEYE_PayPal_PPCP_Payment'))->newInstanceWithoutConstructor();
$payment->api_request = $request;
$payment->auth = 'https://external.example/authorizations/';
$payment->error_email_notification = false;
$http_result = raw_response(204);
expect_check($payment->angelleye_ppcp_void_authorized_payment('AUTH') === array('status' => 'VOIDED'), 'Void caller accepts documented no-content response');
expect_check($payment->angelleye_ppcp_get_readable_message($token_error) === 'ID token transport failure', 'Normalized transport error works with existing readable-message caller');
$parser->parse_response(raw_response(200, '{"id":"OBSERVED"}', array('paypal-debug-id' => 'observer-debug')), 'https://api-m.paypal.com/v2/checkout/orders/OBSERVED', array(), 'get_order');
expect_check(($hook_args[3] ?? '') === 'observer-debug', 'Upstream observer receives debug ID as fourth argument');

echo 'Passed ' . $checks . " response normalization checks.\n";
