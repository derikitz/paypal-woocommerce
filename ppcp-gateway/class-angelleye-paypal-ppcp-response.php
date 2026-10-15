<?php

defined('ABSPATH') || exit;

class AngellEYE_PayPal_PPCP_Response {

    public $api_log;
    public $setting_obj;
    public $generate_signup_link_default_request_param;
    protected static $_instance = null;
    public $is_sandbox;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        $this->angelleye_ppcp_load_class();
        $this->generate_signup_link_default_request_param = array(
            'tracking_id' => '',
            'partner_config_override' => array(
                'partner_logo_url' => 'https://www.angelleye.com/wp-content/uploads/2015/06/angelleye-logo-159x43.png',
                'return_url' => '',
                'return_url_description' => '',
                'show_add_credit_card' => true,
            ),
            'products' => '',
            'legal_consents' => array(
                array(
                    'type' => 'SHARE_DATA_CONSENT',
                    'granted' => true,
                ),
            ),
            'operations' => array(
                array(
                    'operation' => 'API_INTEGRATION',
                    'api_integration_preference' => array(
                        'rest_api_integration' => array(
                            'integration_method' => 'PAYPAL',
                            'integration_type' => 'THIRD_PARTY',
                            'third_party_details' => array(
                                'features' => array(
                                    'PAYMENT',
                                    'FUTURE_PAYMENT',
                                    'REFUND',
                                    'ADVANCED_TRANSACTIONS_SEARCH',
                                    'ACCESS_MERCHANT_INFORMATION',
                                    'PARTNER_FEE'
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $this->is_sandbox = 'yes' === $this->setting_obj->get('testmode', 'no');
        add_action('angelleye_ppcp_request_respose_data', array($this, 'angelleye_ppcp_tpv_tracking'), 10, 3);
    }

    public static function is_error($response) {
        return is_wp_error($response) || $response === null || $response === false ||
            (is_array($response) && (!empty($response['_angelleye_error']) || ($response['status'] ?? '') === 'failed'));
    }

    private function failure($name, $message, $http_status, $payload = array(), $transport_error = null, $debug_id = '') {
        $details = array();
        foreach (is_array($payload['details'] ?? null) ? $payload['details'] : array() as $detail) {
            if (is_array($detail)) {
                $details[] = array_merge($detail, array(
                    'field' => is_string($detail['field'] ?? null) ? $detail['field'] : '',
                    'issue' => is_string($detail['issue'] ?? null) ? $detail['issue'] : $name,
                    'description' => is_string($detail['description'] ?? null) ? $detail['description'] : $message,
                ));
            }
        }
        if (!$details) {
            $details[] = array('field' => '', 'issue' => $name, 'description' => $message);
        }
        // Errors deliberately have no payment status or resource ID: callers
        // must not mistake a transport/API failure for an accepted payment.
        $error = array(
            '_angelleye_error' => true,
            'name' => $name,
            'message' => $message,
            'details' => $details,
            'issue' => $details[0]['issue'],
            'debug_id' => is_string($payload['debug_id'] ?? null) ? $payload['debug_id'] : $debug_id,
            'http_status' => $http_status,
            'transport_error' => $transport_error,
        );
        foreach (array('error', 'error_description', 'links') as $key) {
            if (isset($payload[$key]) && ($key === 'links' ? is_array($payload[$key]) : is_string($payload[$key]))) {
                $error[$key] = $payload[$key];
            }
        }
        return $error;
    }

    public function parse_response($paypal_api_response, $url, $request, $action_name, $log_response = true) {
        try {
            if (is_wp_error($paypal_api_response)) {
                delete_transient('is_angelleye_aws_down');
                if ($log_response) {
                    try {
                        $this->api_log->log(array('action' => $action_name, 'issue' => 'TRANSPORT_ERROR',
                            'http_status' => 0), 'error');
                    } catch (Throwable $ex) { /* Diagnostics cannot replace the payment result. */ }
                }
                return $this->failure('TRANSPORT_ERROR', $paypal_api_response->get_error_message(), 0,
                    array(), (string) $paypal_api_response->get_error_code());
            }
            $status_code = (int) wp_remote_retrieve_response_code($paypal_api_response);
            $body = wp_remote_retrieve_body($paypal_api_response);
            $debug_id = $this->angelleye_ppcp_parse_headers(wp_remote_retrieve_headers($paypal_api_response), 'paypal-debug-id');
            $response = $body !== '' ? json_decode($body, true) : null;
            $valid_json = $body !== '' && json_last_error() === JSON_ERROR_NONE && is_array($response);
            $outer_status = $status_code;
            $proxy_failed = false;
            if ($valid_json && array_key_exists('body', $response)) {
                $envelope = $response;
                $proxy_failed = ($envelope['status'] ?? '') === 'failed';
                foreach (array('statusCode', 'status_code', 'http_status', 'httpStatusCode') as $key) {
                    if (isset($envelope[$key]) && is_numeric($envelope[$key]) && (int) $envelope[$key] >= 100 && (int) $envelope[$key] <= 599) {
                        $status_code = (int) $envelope[$key];
                        break;
                    }
                }
                $debug_id = $this->angelleye_ppcp_parse_headers($envelope['headers'] ?? array(), 'paypal-debug-id') ?: $debug_id;
                $response = $envelope['body'];
                if (is_string($response) && $response !== '') {
                    $response = json_decode($response, true);
                    $valid_json = json_last_error() === JSON_ERROR_NONE && is_array($response);
                } else {
                    $valid_json = is_array($response);
                }
                $body = $envelope['body'] === null ? '' : $envelope['body'];
            }
            $payload = $valid_json ? $response : array();
            $api_error = $proxy_failed || ($payload['status'] ?? '') === 'failed' ||
                (isset($payload['error']) && $payload['error'] !== false) ||
                (isset($payload['name'], $payload['message']) && !isset($payload['id']));
            if ($outer_status < 200 || $outer_status >= 300 || $status_code < 200 || $status_code >= 300 || $api_error) {
                $name = is_string($payload['name'] ?? null) ? $payload['name'] :
                    (is_string($payload['error'] ?? null) ? $payload['error'] : 'HTTP_ERROR');
                $message = is_string($payload['message'] ?? null) ? $payload['message'] :
                    (is_string($payload['error_description'] ?? null) ? $payload['error_description'] :
                    (is_string($payload['error_message'] ?? null) ? $payload['error_message'] : 'PayPal returned an unsuccessful response.'));
                $transport_error = $proxy_failed && is_string($payload['error_code'] ?? null) ? $payload['error_code'] : null;
                $response = $this->failure($transport_error ? 'TRANSPORT_ERROR' : $name, $message,
                    $outer_status >= 300 ? $outer_status : $status_code, $payload, $transport_error, $debug_id);
            } elseif ($status_code === 204 && ($body === '' || $body === null)) {
                $response = ''; // Preserve the existing successful no-content contract.
            } elseif (!$valid_json) {
                $response = $this->failure('INVALID_RESPONSE', 'PayPal returned an empty or malformed response.', $status_code, array(), null, $debug_id);
            }
            if (self::is_error($response) && ($outer_status >= 500 || $status_code >= 500 || $status_code === 0 ||
                $response['transport_error'] !== null || $response['name'] === 'INVALID_RESPONSE')) {
                delete_transient('is_angelleye_aws_down');
            }
            if ($log_response) {
                try {
                    $log_response_data = self::is_error($response) ? array(
                        'response' => array('code' => $response['http_status']),
                        'body' => json_encode($response),
                    ) : $paypal_api_response;
                    $this->angelleye_ppcp_write_log($url, $request, $log_response_data, $action_name);
                    if (!self::is_error($response) && strpos($url, 'paypal.com') !== false) {
                        do_action('angelleye_ppcp_request_respose_data', $request, $response, $action_name);
                    }
                } catch (Throwable $ex) {
                    // An observer/logger failure must not replace a payment result.
                }
            }
            return $response;
        } catch (Throwable $ex) {
            return $this->failure('INVALID_RESPONSE', 'Unable to process the PayPal response.', 0);
        }
    }

    public function angelleye_ppcp_write_log($url, $request, $response, $action_name = 'Exception') {
        $body = isset($response['body']) ? $response['body'] : $response;
        if (is_string($body)) {
            $body = json_decode($body, true);
        }
        // Proxy envelopes can contain either JSON strings or decoded upstream bodies.
        if (is_array($body) && isset($body['body']) && is_string($body['body'])) {
            $body['body'] = json_decode($body['body'], true);
        }
        $headers = isset($response['headers']) ? $response['headers'] : array();
        $status = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        $upstream = is_array($body) && isset($body['body']) && is_array($body['body']) ? $body['body'] : $body;
        if (is_array($body) && isset($body['statusCode']) && is_numeric($body['statusCode'])) {
            $status = (int) $body['statusCode'];
        }
        $resource_id = '';
        $resource_key = in_array($action_name, array('create_order', 'capture_order', 'authorize_order'), true) ? 'order_id' :
            ($action_name === 'refund_order' ? 'refund_id' : 'transaction_id');
        if (in_array($action_name, array('create_order', 'capture_order', 'authorize_order', 'refund_order', 'capture_authorized', 'void_authorized'), true) && is_array($upstream)) {
            $resource_id = $upstream['id'] ?? '';
        }
        $failed = $status >= 400 || (is_array($body) &&
            (isset($upstream['name']) || isset($upstream['error']) || !empty($upstream['_angelleye_error'])));
        $this->api_log->log(array(
            'action' => $action_name,
            'environment' => $this->is_sandbox ? 'SANDBOX' : 'LIVE',
            'http_status' => $status,
            'debug_id' => $this->angelleye_ppcp_parse_headers($headers, 'paypal-debug-id'),
            'request_id' => isset($request['headers']) ?
                $this->angelleye_ppcp_parse_headers($request['headers'], 'paypal-request-id') : '',
            $resource_key => $resource_id,
            'capture_id' => is_array($upstream) ? ($upstream['purchase_units'][0]['payments']['captures'][0]['id'] ?? '') : '',
            'response' => $body,
        ), $failed ? 'error' : 'info');
    }

    public function angelleye_ppcp_load_class() {
        try {
            if (!class_exists('AngellEYE_PayPal_PPCP_Log')) {
                include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-angelleye-paypal-ppcp-log.php';
            }
            if (!class_exists('WC_Gateway_PPCP_AngellEYE_Settings')) {
                include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-wc-gateway-ppcp-angelleye-settings.php';
            }
            $this->setting_obj = WC_Gateway_PPCP_AngellEYE_Settings::instance();
            $this->api_log = AngellEYE_PayPal_PPCP_Log::instance();
        } catch (Exception $ex) {
            $this->api_log->log("The exception was created on line: " . $ex->getFile() . ' ' .$ex->getLine(), 'error');
            $this->api_log->log($ex->getMessage(), 'error');
        }
    }

    public function angelleye_ppcp_tpv_tracking($request, $response, $action_name) {
        try {
            $allow_payment_event = array('capture_order', 'refund_order', 'authorize_order', 'void_authorized', 'capture_authorized');
            if (in_array($action_name, $allow_payment_event)) {
                if (class_exists('AngellEYE_PFW_Payment_Logger')) {
                    $amount = '';
                    $resource_id = '';
        $resource_key = in_array($action_name, array('create_order', 'capture_order', 'authorize_order'), true) ? 'order_id' :
            ($action_name === 'refund_order' ? 'refund_id' : 'transaction_id');
                    if (isset($response['purchase_units']['0']['amount']['value'])) {
                        $amount = $response['purchase_units']['0']['amount']['value'];
                    } elseif (isset($response['amount']['value'])) {
                        $amount = $response['amount']['value'];
                    }
                    if (isset($response['purchase_units']['0']['payments']['captures'][0]['id'])) {
                        $transaction_id = $response['purchase_units']['0']['payments']['captures'][0]['id'];
                    } elseif (isset($response['purchase_units']['0']['payments']['authorizations']['0']['id'])) {
                        $transaction_id = $response['purchase_units']['0']['payments']['authorizations']['0']['id'];
                    } elseif (isset($response['id'])) {
                        $transaction_id = $response['id'];
                    }
                    $payment_logger = AngellEYE_PFW_Payment_Logger::instance();
                    $request_param['type'] = 'ppcp_' . $action_name;
                    $request_param['amount'] = $amount;
                    $request_param['status'] = 'Success';
                    $request_param['site_url'] = get_bloginfo('url');
                    $request_param['mode'] = ($this->is_sandbox === true) ? 'sandbox' : 'live';
                    $request_param['merchant_id'] = isset($response['purchase_units']['0']['payee']['merchant_id']) ? $response['purchase_units']['0']['payee']['merchant_id'] : '';
                    $request_param['correlation_id'] = '';
                    $request_param['transaction_id'] = $transaction_id;
                    $request_param['product_id'] = '1';
                    $payment_logger->angelleye_tpv_request($request_param);
                }
            }
        } catch (Exception $ex) {
            $this->api_log->log("The exception was created on line: " . $ex->getFile() . ' ' .$ex->getLine(), 'error');
            $this->api_log->log($ex->getMessage(), 'error');
        }
    }

    public function angelleye_ppcp_parse_headers($headers, $key_debug) {
        $key_debug = strtolower($key_debug);
        if (is_string($headers)) {
            $headers = preg_split('/\r?\n/', $headers);
        }
        if (is_array($headers) || $headers instanceof Traversable) {
            foreach ($headers as $key => $value) {
                if (is_string($key) && strtolower($key) === $key_debug) {
                    return is_scalar($value) ? trim((string) $value) : '';
                }
                if (is_int($key) && is_string($value) && strpos($value, ':') !== false) {
                    list($name, $content) = explode(':', $value, 2);
                    if (strtolower(trim($name)) === $key_debug) {
                        return trim($content);
                    }
                }
            }
        } elseif ($headers instanceof ArrayAccess && isset($headers[$key_debug])) {
            return is_scalar($headers[$key_debug]) ? (string) $headers[$key_debug] : '';
        }
        return '';
    }

    public function angelleye_ppcp_signup_link_write_log($request) {
        $this->api_log->log(array('action' => 'generate_signup_link'));
    }

}
