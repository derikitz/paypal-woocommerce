<?php
/**
 * Run: php tests/security/proxy-tls.php
 * Exercises the real proxy transport with a stubbed WordPress HTTP layer.
 * No network requests are made.
 */
define('ABSPATH', __DIR__ . '/');
define('VERSION_PFW', 'test');
define('AE_FEE', 'test-fee');

class WP_Error {
    private $message;
    public function __construct($code, $message) {
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error($value) {
    return $value instanceof WP_Error;
}

function get_home_url() {
    return 'https://store.example';
}

function wp_json_encode($value) {
    return json_encode($value);
}

$calls = array();
$responses = array();
$checks = 0;

function wp_remote_get($url, $args) {
    global $calls, $responses;
    $calls[] = array('url' => $url, 'args' => $args);
    if (!$responses) {
        throw new RuntimeException('Unexpected extra transport attempt');
    }
    return array_shift($responses);
}

function expect_check($condition, $message) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-request.php';

class TestProxyTransport extends AngellEYE_PayPal_PPCP_Request {
    public function __construct() {
        $this->is_sandbox = true;
        $this->ppcp_host = 'https://proxy.example/';
    }
    public function angelleye_ppcp_paypal_fee() {
        return false;
    }
}

$transport = new TestProxyTransport();
$paypal_url = 'https://api-m.sandbox.paypal.com/v2/checkout/orders';
$args = array(
    'method' => 'POST',
    'headers' => array('PayPal-Request-Id' => 'stable-operation-id'),
    'body' => array('intent' => 'CAPTURE'),
    'sslverify' => false,
);
$success = array('response' => array('code' => 201), 'body' => '{"id":"ORDER"}');

foreach (array('create_order' => 'ppcp-request', 'generate_id_token' => 'generate-id-token') as $action => $endpoint) {
    foreach (array('cURL error 60: SSL certificate problem', 'cURL error 28: Operation timed out') as $message) {
        $error = new WP_Error('http_request_failed', $message);
        foreach (array($success, $error) as $final_response) {
            $calls = array();
            $responses = array($error, $final_response);
            $result = $transport->angelleye_ppcp_remote_get($paypal_url, $args, $action);
            expect_check(count($calls) === 2, 'Expected at most one retry');
            expect_check($result === $final_response, 'Final response/error must be preserved');
            expect_check($calls[0] === $calls[1], 'Retry must preserve the exact prepared request');
            expect_check($calls[0]['args']['sslverify'] === true, 'TLS verification must override false');
            expect_check($calls[0]['url'] === 'https://proxy.example/' . $endpoint, 'Incorrect proxy endpoint');
            $body = json_decode($calls[0]['args']['body'], true);
            expect_check($body['paypal_body'] === $args['body'], 'PayPal payload must be preserved');
            expect_check($body['paypal_header'] === $args['headers'], 'Idempotency header must be preserved');
        }
    }
}

// Earlier failures must not weaken later requests on the shared instance.
$calls = array();
$responses = array($success);
unset($args['sslverify']);
$result = $transport->angelleye_ppcp_remote_get($paypal_url, $args, 'create_order');
expect_check(count($calls) === 1 && $result === $success, 'Successful request must not retry');
expect_check($calls[0]['args']['sslverify'] === true, 'Later requests must verify certificates');

// Preserve the existing retry-limit argument for callers already on attempt 2.
$calls = array();
$responses = array($error);
$result = $transport->angelleye_ppcp_remote_get($paypal_url, $args, 'create_order', 2);
expect_check(count($calls) === 1 && $result === $error, 'Retry limit must be respected');
expect_check($calls[0]['args']['sslverify'] === true, 'Final attempt must verify certificates');

// Proxy contract: preserve JSON objects/arrays, including explicit empty objects,
// without decoding nested strings or treating malformed bodies as full refunds.
$partial = array('amount' => array('value' => '10.00', 'currency_code' => 'USD'),
    'note_to_payer' => 'Partial refund – café', 'payment_instruction' => array(
        'platform_fees' => array(array('amount' => array('value' => '1.25', 'currency_code' => 'USD')))));
$cases = array(
    array($partial, $partial),
    array(json_encode($partial), $partial),
    array('  ' . json_encode($partial) . "\n", $partial),
    array('[{"op":"replace","path":"/purchase_units/@reference_id==original/amount","value":{"currency_code":"USD","value":"10.00"}}]',
        array(array('op' => 'replace', 'path' => '/purchase_units/@reference_id==original/amount', 'value' => array('currency_code' => 'USD', 'value' => '10.00')))),
    array('{}', (object) array()),
    array('[]', array()),
    array(array(), array()),
    array(null, null),
    array('', null),
    array('{"opaque":"{\"amount\":\"keep as text\"}","flags":[false,0,null],"metadata":{}}',
        (object) array('opaque' => '{"amount":"keep as text"}', 'flags' => array(false, 0, null), 'metadata' => (object) array())),
);
foreach ($cases as list($input, $expected)) {
    foreach (array(false, true) as $retry) {
        $calls = array();
        $responses = $retry ? array($error, $success) : array($success);
        $body_args = array('method' => 'POST', 'body' => $input, 'headers' => array('PayPal-Request-Id' => 'refund-stable'));
        $result = $transport->angelleye_ppcp_remote_get($paypal_url . '/CAPTURE/refund', $body_args, 'refund_order');
        $sent = json_decode($calls[0]['args']['body']);
        expect_check($result === $success, 'Valid body result preserved');
        expect_check(json_encode($sent->paypal_body) === json_encode($expected), 'Body fields and JSON container types preserved');
        expect_check($sent->paypal_header->{'PayPal-Request-Id'} === 'refund-stable', 'Refund request identity preserved');
        expect_check(count($calls) === ($retry ? 2 : 1), 'Valid request retry bounded');
        expect_check(!$retry || $calls[0] === $calls[1], 'JSON-string retry preserves exact envelope');
    }
}
$recursive = array(); $recursive['self'] = &$recursive;
$stream = fopen('php://memory', 'r');
foreach (array('{bad', 'null', 'true', 'false', '123', '"text"', '"{}"', '   ',
    '{"amount":"\ud800"}', false, 0, 1.5, new stdClass(), $stream, array('value' => INF), $recursive) as $invalid) {
    $calls = array(); $responses = array();
    $result = $transport->angelleye_ppcp_remote_get($paypal_url . '/CAPTURE/refund', array('body' => $invalid), 'refund_order');
    expect_check(is_wp_error($result), 'Malformed or unsupported body returns an error');
    expect_check(!$calls, 'Invalid body never sends or retries an HTTP request');
}
fclose($stream);
$calls = array(); $responses = array($success);
$result = $transport->angelleye_ppcp_remote_get($paypal_url . '/AUTH/void', array('method' => 'POST'), 'void_authorized');
expect_check($result === $success && json_decode($calls[0]['args']['body'])->paypal_body === null, 'Absent body remains no-content');

echo 'Passed ' . $checks . " proxy TLS and body checks.\n";
