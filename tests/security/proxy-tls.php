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

echo 'Passed ' . $checks . " proxy TLS checks.\n";
