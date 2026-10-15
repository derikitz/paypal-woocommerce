<?php
/** Run: php tests/security/direct-oauth.php. No network or database is used. */
define('ABSPATH', __DIR__ . '/');
define('VERSION_PFW', 'test');
define('AE_FEE', 'test-fee');

class WP_Error {
    public function __construct($code, $message) { $this->message = $message; }
    private $message;
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function get_home_url() { return 'https://store.example'; }
function get_option($name, $default = false) { global $operation_options; return $operation_options[$name] ?? $default; }
function add_option($key, $value, $deprecated = '', $autoload = false) {
    global $operation_options;
    if (isset($operation_options[$key])) { return false; }
    $operation_options[$key] = $value;
    return true;
}
function update_option($key, $value, $autoload = false) { global $operation_options; $operation_options[$key] = $value; return true; }
function delete_option($key) { global $operation_options; unset($operation_options[$key]); }
function wp_generate_uuid4() { static $id = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$id); }
function wc_generate_order_key() { return 'wc_order_' . wp_generate_uuid4(); }

$cache = $cache_ttls = $calls = $responses = array();
$checks = 0;
function get_transient($key) { global $cache; return $cache[$key] ?? false; }
function set_transient($key, $value, $ttl) {
    global $cache, $cache_ttls;
    $cache[$key] = $value;
    $cache_ttls[$key] = $ttl;
}
function delete_transient($key) { global $cache; unset($cache[$key]); }
function fake_http($url, $args) {
    global $calls, $responses;
    $calls[] = array('url' => $url, 'args' => $args);
    if (!$responses) { throw new RuntimeException('Unexpected HTTP request: ' . $url); }
    return array_shift($responses);
}
function wp_remote_post($url, $args) { return fake_http($url, $args); }
function wp_remote_get($url, $args) { return fake_http($url, $args); }
function expect_check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function response($status, $body) {
    return array('response' => array('code' => $status), 'body' => json_encode($body));
}
function token_response($token = 'server-token', $expiry = 3600) {
    return response(200, array('access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiry));
}
function reset_http($queue, $clear_cache = true) {
    global $calls, $responses, $cache, $cache_ttls;
    $calls = array();
    $responses = $queue;
    if ($clear_cache) { $cache = $cache_ttls = array(); }
}

class AngellEYE_PayPal_PPCP_Mock {
    public function is_enabled() { return false; }
}
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-response.php';
require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-request.php';

class TestOAuthResponse extends AngellEYE_PayPal_PPCP_Response {
    public function __construct() {}
    public function parse_response($response, $url, $request, $action) {
        return is_wp_error($response) ? $response : json_decode($response['body'], true);
    }
}
class TestOAuthRequest extends AngellEYE_PayPal_PPCP_Request {
    public function __construct() {
        $this->is_sandbox = true;
        $this->client_id = 'client';
        $this->secret_id = 'secret';
        $this->basicAuth = base64_encode('client:secret');
        $this->token_url = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
        $this->is_first_party_used = 'yes';
        $this->ppcp_host = 'https://proxy.example/';
        $this->api_response = new TestOAuthResponse();
    }
    public function angelleye_get_settings() {}
    public function angelleye_ppcp_paypal_fee() { return false; }
}
$request = new TestOAuthRequest();
$url = 'https://api-m.sandbox.paypal.com/v2/checkout/orders';
$args = array('method' => 'POST', 'headers' => array(
    'Content-Type' => 'application/json', 'authorization' => 'old',
    'paypal-auth-assertion' => 'partner-only', 'PayPal-Request-Id' => 'operation-1',
), 'body' => array('intent' => 'CAPTURE'));

reset_http(array(token_response(), response(201, array('id' => 'ORDER'))));
$result = $request->request($url, $args, 'create_order');
expect_check($result['id'] === 'ORDER' && count($calls) === 2, 'Exchange token before creating order');
expect_check($calls[0]['args']['headers']['Authorization'] === 'Basic ' . base64_encode('client:secret'), 'Token exchange must use Basic credentials');
expect_check($calls[0]['args']['body'] === 'grant_type=client_credentials', 'Token exchange must use form body');
expect_check($calls[0]['args']['headers']['Content-Type'] === 'application/x-www-form-urlencoded', 'Token content type');
expect_check($calls[0]['args']['sslverify'] && $calls[0]['args']['redirection'] === 0, 'Token requests verify TLS and disable redirects');
expect_check($calls[1]['args']['headers']['Authorization'] === 'Bearer server-token', 'Orders must use Bearer token');
expect_check(!isset($calls[1]['args']['headers']['authorization']) && !isset($calls[1]['args']['headers']['paypal-auth-assertion']), 'Remove stale auth and partner assertion');
expect_check($calls[1]['args']['body'] === json_encode($args['body']), 'Encode Orders JSON');
expect_check($calls[1]['args']['headers']['PayPal-Request-Id'] === 'operation-1', 'Preserve idempotency header');
expect_check(reset($cache_ttls) > 0 && reset($cache_ttls) <= 3540, 'Cache with expiry safety margin');
expect_check(strpos(key($cache), 'secret') === false, 'Cache key must not expose secret');

reset_http(array(response(200, array('client_token' => 'browser-token'))), false);
$result = $request->request('https://api-m.sandbox.paypal.com/v1/identity/generate-token', $args, 'get_client_token');
expect_check(count($calls) === 1 && $result['client_token'] === 'browser-token', 'Client-token creation reuses server token');
expect_check($calls[0]['args']['headers']['Authorization'] === 'Bearer server-token', 'Client-token endpoint must use Bearer access token');

// Expire the cached value without sleeping.
foreach ($cache as &$entry) { $entry['expires_at'] = time() - 1; }
unset($entry);
reset_http(array(token_response('renewed'), response(200, array())), false);
$request->request($url, $args);
expect_check(count($calls) === 2 && $calls[1]['args']['headers']['Authorization'] === 'Bearer renewed', 'Refresh expired cached tokens');

reset_http(array(response(401, array()), token_response('fresh'), response(201, array('id' => 'RETRY'))), false);
$result = $request->request($url, $args);
expect_check(count($calls) === 3 && $result['id'] === 'RETRY', 'Refresh token once after 401');
expect_check($calls[0]['args']['body'] === $calls[2]['args']['body'] && $calls[0]['args']['headers']['PayPal-Request-Id'] === $calls[2]['args']['headers']['PayPal-Request-Id'], '401 replay preserves operation');
expect_check($calls[2]['args']['headers']['Authorization'] === 'Bearer fresh', '401 replay uses refreshed token');

reset_http(array(response(401, array()), token_response('rejected'), response(401, array('name' => 'AUTHENTICATION_FAILURE'))), false);
$result = $request->request($url, $args);
expect_check(count($calls) === 3 && $result['name'] === 'AUTHENTICATION_FAILURE', 'Repeated 401 must terminate');
reset_http(array(response(401, array()), response(401, array('error' => 'invalid_client'))), false);
$result = $request->request($url, $args);
expect_check(count($calls) === 2 && empty($cache) && $result['name'] === 'AUTHENTICATION_FAILURE', 'Failed refresh must stop without payment replay');
reset_http(array(token_response(), response(200, array())));
$request->request($url, $args);
foreach (array(new WP_Error('timeout', 'timeout'), response(403, array()), response(500, array())) as $failure) {
    reset_http(array($failure), false);
    $request->request($url, $args);
    expect_check(count($calls) === 1, 'Do not replay timeouts, 403, or 500');
}

foreach (array(
    new WP_Error('tls', 'sensitive upstream detail'),
    response(401, array('error' => 'invalid_client', 'secret' => 'DO-NOT-LEAK')),
    array('response' => array('code' => 200), 'body' => 'invalid json'),
    response(200, array('access_token' => 'bad')),
    response(200, array('access_token' => 'bad', 'token_type' => 'Basic', 'expires_in' => 3600)),
    token_response('bad', 0),
) as $failure) {
    reset_http(array($failure));
    $result = $request->request($url, $args);
    expect_check(count($calls) === 1 && empty($cache), 'Failed token exchange must not send payment or cache failure');
    expect_check($result['name'] === 'AUTHENTICATION_FAILURE' && strpos(json_encode($result), 'DO-NOT-LEAK') === false, 'Sanitized authentication failure');
}
reset_http(array());
expect_check(is_wp_error(AngellEYE_PayPal_PPCP_Request::get_access_token(true, '', 'secret')) && !$calls, 'Missing credentials fail without HTTP');

// Cache isolation: environment, client ID, and rotated secret each need a token.
reset_http(array(token_response('sandbox'), token_response('live'), token_response('other'), token_response('rotated')));
expect_check(AngellEYE_PayPal_PPCP_Request::get_access_token(true, 'client', 'secret') === 'sandbox', 'Sandbox token');
expect_check(AngellEYE_PayPal_PPCP_Request::get_access_token(false, 'client', 'secret') === 'live', 'Live token');
expect_check($calls[1]['url'] === 'https://api-m.paypal.com/v1/oauth2/token', 'Live token endpoint');
expect_check(AngellEYE_PayPal_PPCP_Request::get_access_token(true, 'other', 'secret') === 'other', 'Different app token');
expect_check(AngellEYE_PayPal_PPCP_Request::get_access_token(true, 'client', 'rotated') === 'rotated', 'Rotated secret token');
expect_check(count($cache) === 4, 'Credential/environment caches remain separate');
reset_http(array(token_response('short', 30)));
expect_check(AngellEYE_PayPal_PPCP_Request::get_access_token(true, 'client', 'secret') === 'short' && !$cache, 'Short-lived token must not be cached indefinitely');

reset_http(array(response(200, array('id_token' => 'identity'))));
$id_args = $args;
$id_args['body'] = array('target_customer_id' => 'customer');
$result = $request->request($request->token_url, $id_args, 'generate_id_token');
parse_str($calls[0]['args']['body'], $form);
expect_check(count($calls) === 1 && $result['id_token'] === 'identity', 'ID token uses direct OAuth exchange');
expect_check($form === array('target_customer_id' => 'customer', 'grant_type' => 'client_credentials', 'response_type' => 'id_token'), 'ID token form preserves customer');
expect_check($calls[0]['args']['headers']['Authorization'] === 'Basic ' . base64_encode('client:secret'), 'ID token uses Basic credentials');
reset_http(array(response(200, array('id_token' => 'new-payer'))));
$id_args['body'] = null;
$request->request($request->token_url, $id_args, 'generate_id_token');
parse_str($calls[0]['args']['body'], $form);
expect_check($form === array('grant_type' => 'client_credentials', 'response_type' => 'id_token'), 'First-time payer ID token form');

$request->is_first_party_used = 'no';
reset_http(array(response(201, array('body' => array('id' => 'PROXY')))));
$request->request($url, $args);
expect_check(count($calls) === 1 && $calls[0]['url'] === 'https://proxy.example/ppcp-request', 'Partner flow must not obtain merchant OAuth token');
$proxy_body = json_decode($calls[0]['args']['body'], true);
expect_check($proxy_body['paypal_header'] === $args['headers'], 'Partner auth assertion preserved');

$request->is_first_party_used = 'yes';
reset_http(array(response(200, array())));
$request->request('https://api-m.paypal.com.example.test/path', array('headers' => array()));
expect_check(count($calls) === 1 && empty($calls[0]['args']['headers']['Authorization']), 'Never inject credentials into lookalike hosts');

// The separate readiness probe must also authenticate Orders with Bearer.
function angelleye_ppcp_get_currency() { return 'USD'; }
function get_bloginfo($field) { return 'Test store'; }
require dirname(__DIR__, 2) . '/ppcp-gateway/angelleye-paypal-ppcp-common-functions.php';
reset_http(array(token_response('probe'), response(201, array('status' => 'CREATED'))));
expect_check(angelleye_ppcp_account_ready_to_paid(true, 'client', 'secret', 'seller@example.test'), 'Readiness probe succeeds');
expect_check($calls[1]['args']['headers']['Authorization'] === 'Bearer probe', 'Readiness probe uses access token');
reset_http(array(response(401, array())));
expect_check(!angelleye_ppcp_account_ready_to_paid(true, 'client', 'secret', 'seller@example.test') && count($calls) === 1, 'Readiness probe stops on OAuth failure');

// Exercise durable identity through the real request/authentication transport.
$capture_url = $url . '/ORDER/capture';
$scope = array(true, 'merchant', 'order:transport', 'capture_order', 'ORDER');
$op = new AngellEYE_PayPal_PPCP_Operation($scope, 'capture_order');
reset_http(array(token_response(), new WP_Error('timeout', 'Capture response lost')));
$request->request($capture_url, $args, 'capture_order');
$sent_id = $calls[1]['args']['headers']['PayPal-Request-Id'];
$op->close();
$op = new AngellEYE_PayPal_PPCP_Operation($scope, 'capture_order');
reset_http(array(response(201, array('id' => 'ORDER', 'status' => 'COMPLETED'))), false);
$request->request($capture_url, $args, 'capture_order');
expect_check(count($calls) === 1 && $calls[0]['args']['headers']['PayPal-Request-Id'] === $sent_id, 'Payment retry after timeout reuses durable UUID through OAuth transport');
$op->close();
$op = new AngellEYE_PayPal_PPCP_Operation($scope, 'capture_order');
reset_http(array(response(200, array('id' => 'ORDER', 'status' => 'COMPLETED'))), false);
$request->request($capture_url, $args, 'capture_order');
expect_check($calls[0]['url'] === $url . '/ORDER' && $calls[0]['args']['method'] === 'GET' && !isset($calls[0]['args']['body']), 'Accepted capture is reconciled with GET rather than replayed');
$op->close();

$request->is_first_party_used = 'no';
$scope[2] = 'order:proxy';
$op = new AngellEYE_PayPal_PPCP_Operation($scope, 'capture_order');
reset_http(array(new WP_Error('timeout', 'Proxy timeout'), new WP_Error('timeout', 'Proxy timeout')));
$request->request($capture_url, $args, 'capture_order');
$proxy_first = json_decode($calls[0]['args']['body'], true);
$op->close();
$op = new AngellEYE_PayPal_PPCP_Operation($scope, 'capture_order');
reset_http(array(response(201, array('id' => 'ORDER', 'status' => 'COMPLETED'))));
$request->request($capture_url, $args, 'capture_order');
$proxy_retry = json_decode($calls[0]['args']['body'], true);
expect_check($proxy_first['paypal_header']['PayPal-Request-Id'] === $proxy_retry['paypal_header']['PayPal-Request-Id'], 'Proxy retries reuse durable UUID across invocations');
$op->close();

// Upsell adapters supply order/offer identity without keeping an outer context.
$request->is_first_party_used = 'yes';
$offer_args = $args;
$offer_args['angelleye_operation_scope'] = array('order_id' => 77, 'resource' => 'offer:1');
reset_http(array(token_response(), new WP_Error('timeout', 'Offer response lost')));
$request->request($url, $offer_args, 'create_order');
$offer_id = $calls[1]['args']['headers']['PayPal-Request-Id'];
expect_check(!isset($calls[1]['args']['angelleye_operation_scope']) && !AngellEYE_PayPal_PPCP_Operation::current('create_order'), 'Adapter context stays local and releases its lock');
reset_http(array(response(201, array('id' => 'OFFER-ORDER', 'status' => 'CREATED'))), false);
$request->request($url, $offer_args, 'create_order');
expect_check($calls[0]['args']['headers']['PayPal-Request-Id'] === $offer_id, 'Upsell retry retains request identity');
reset_http(array(response(200, array('id' => 'OFFER-ORDER', 'status' => 'CREATED'))), false);
$request->request($url, $offer_args, 'create_order');
expect_check($calls[0]['args']['method'] === 'GET' && $calls[0]['url'] === $url . '/OFFER-ORDER', 'Accepted upsell order is read instead of recreated');
$offer_args['angelleye_operation_scope']['resource'] = 'offer:2';
reset_http(array(response(201, array('id' => 'SECOND-OFFER', 'status' => 'CREATED'))), false);
$request->request($url, $offer_args, 'create_order');
expect_check($calls[0]['args']['headers']['PayPal-Request-Id'] !== $offer_id, 'Separate equal-amount offers get distinct request identities');

echo 'Passed ' . $checks . " direct OAuth and transport checks.\n";
