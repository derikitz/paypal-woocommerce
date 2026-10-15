<?php
/** Run: php tests/security/logging-redaction.php. No WordPress or network needed. */
define('ABSPATH', __DIR__);
class WC_Gateway_PPCP_AngellEYE_Settings {
    public static function instance() { return new self(); }
    public function get($name, $default) { return $default; }
}
class TestLogger {
    public $entries = array();
    public function log($level, $message, $context) { $this->entries[] = compact('level', 'message', 'context'); }
}
$logger = new TestLogger();
function wc_get_logger() { global $logger; return $logger; }
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-log.php';
require __DIR__ . '/../../ppcp-gateway/class-angelleye-paypal-ppcp-response.php';
class WP_Error {
    public function get_error_message() { return 'SENSITIVE_CUSTOMER_TOKEN'; }
    public function get_error_code() { return 'http_request_failed'; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function delete_transient($key) {}
function wp_remote_retrieve_response_code($value) { return $value['response']['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_remote_retrieve_headers($value) { return $value['headers'] ?? array(); }
function do_action(...$args) {}
$checks = 0;
function check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } ++$checks; }
$log = new AngellEYE_PayPal_PPCP_Log();
check($log->log_option === 'errors_warnings_only', 'Safer default');
foreach (array('log', 'temp_log', 'migration_log') as $method) {
    $logger->entries = array();
    $log->$method(array('action' => 'capture_order'));
    check(!$logger->entries, "$method info suppressed");
    $log->$method(array('action' => 'capture_order', 'debug_id' => 'debug-123'), 'warning');
    check(count($logger->entries) === 1, "$method warnings retained");
    check(strpos($logger->entries[0]['message'], 'debug-123') !== false, "$method debug ID retained");
    $log->log_option = 'disabled';
    $log->$method('SECRET', 'error');
    check(count($logger->entries) === 1, "$method respects disabled");
    $log->log_option = 'errors_warnings_only';
}
$log->log_option = 'everything';
$secret = 'SENSITIVE_CUSTOMER_TOKEN';
$payload = array('authorization' => 'Bearer ' . $secret, 'access_token' => $secret,
    'client_secret' => $secret, 'payer' => array('name' => $secret, 'email_address' => $secret),
    'payment_source' => array('card' => array('number' => $secret)), 'shipping' => array('address' => $secret),
    'unknown' => $secret, 'message' => $secret, 'id' => $secret, 'debug_id' => 'debug-123',
    'details' => array(array('issue' => 'INSTRUMENT_DECLINED', 'description' => $secret)),
    'headers' => array('Authorization' => $secret, 'Set-Cookie' => $secret, 'paypal-debug-id' => 'debug-123'));
foreach (array($payload, json_encode($payload), print_r($payload, true), 'access_token=' . $secret,
    '<html>' . $secret . '</html>', (object)$payload) as $input) {
    $log->log($input, 'error');
    check(strpos(end($logger->entries)['message'], $secret) === false, 'Raw/structured data suppressed');
}
$safe = AngellEYE_PayPal_PPCP_Log::redact($payload);
check($safe['debug_id'] === 'debug-123', 'Correlation preserved');
check($safe['details'][0]['issue'] === 'INSTRUMENT_DECLINED', 'Issue preserved');
check(!isset($safe['headers']['Authorization']), 'Authorization omitted');
check(count(AngellEYE_PayPal_PPCP_Log::redact(array_fill(0, 100, $payload))) === 50, 'Entry bound');
check(AngellEYE_PayPal_PPCP_Log::redact(array('debug_id' => "forged\nlog")) === array(), 'Log injection rejected');
$cycle = array(); $cycle['body'] = &$cycle;
check(strlen(json_encode(AngellEYE_PayPal_PPCP_Log::redact($cycle))) < 300, 'Recursive data bounded');
$log->browser_error(array('error' => $secret, 'logTrace' => array_fill(0, 30,
    array('context' => 'api_response', 'status' => 500, 'body' => $secret, 'debug_id' => $secret, 'method' => $secret))));
$entry = end($logger->entries);
check(strpos($entry['message'], $secret) === false, 'Server independently sanitizes browser data');
$decoded = json_decode($entry['message'], true);
check(count($decoded['logTrace']) === 10, 'Browser trace bound');
check($decoded['logTrace'][0]['status'] === 500, 'Browser status preserved');
class TestResponseLog extends AngellEYE_PayPal_PPCP_Response {
    public function __construct($log) { $this->api_log = $log; $this->is_sandbox = true; }
}
$response = new TestResponseLog($log);
foreach (array(false, true) as $proxy) {
    $body = array_merge($payload, array('id' => 'CAPTURE-123'));
    if ($proxy) { $body = array('statusCode' => 422, 'body' => json_encode($body), 'requestId' => 'proxy-123'); }
    $response->angelleye_ppcp_write_log('https://example.com/?token=' . $secret,
        array('headers' => array('paypal-request-id' => 'request-123', 'Authorization' => $secret), 'body' => $secret),
        array('response' => array('code' => $proxy ? 200 : 422), 'headers' => array('paypal-debug-id' => 'header-123'),
            'body' => json_encode($body)), 'capture_order');
    $entry = end($logger->entries);
    check(strpos($entry['message'], $secret) === false, 'Transport payload redacted');
    foreach (array('CAPTURE-123', 'request-123', 'header-123', 'INSTRUMENT_DECLINED') as $id) {
        check(strpos($entry['message'], $id) !== false, 'Transport diagnostic preserved: ' . $id);
    }
    check($entry['level'] === 'error', 'Proxy/direct failure logged at error severity');
}
$response->angelleye_ppcp_write_log('url', array(), array('body' => json_encode(array('id' => $secret))), 'create_payment_token');
check(strpos(end($logger->entries)['message'], $secret) === false, 'Vault resource ID never promoted');
$log->log_option = 'errors_warnings_only';
$response->parse_response(new WP_Error(), 'https://example.com', array(), 'capture_order');
$entry = end($logger->entries);
check(strpos($entry['message'], 'TRANSPORT_ERROR') !== false, 'Transport failure logged safely');
check(strpos($entry['message'], $secret) === false, 'Transport exception message omitted');
$count = count($logger->entries);
$response->parse_response(new WP_Error(), 'https://example.com', array(), 'id_token', false);
check(count($logger->entries) === $count, 'Sensitive auth logging bypass respected');
$response->parse_response(array('response' => array('code' => 200), 'body' => '<html>' . $secret . '</html>'),
    'https://example.com', array(), 'capture_order');
$entry = end($logger->entries);
check($entry['level'] === 'error', 'Malformed success recorded as error');
check(strpos($entry['message'], 'INVALID_RESPONSE') !== false, 'Malformed response category retained');
check(strpos($entry['message'], $secret) === false, 'Malformed HTML omitted');
echo "$checks logging redaction checks passed.\n";
