<?php

defined('ABSPATH') || exit;
require_once __DIR__ . '/class-angelleye-paypal-ppcp-operation.php';

class AngellEYE_PayPal_PPCP_Request {

    public $is_sandbox;
    public $token_url;
    public $paypal_oauth_api;
    public $generate_token_url;
    public $basicAuth;
    public $client_token;
    public ?AngellEYE_PayPal_PPCP_Response $api_response;
    public $result;
    public $setting_obj;
    public $api_request;
    protected static $_instance = null;
    public $api_log;
    public $ppcp_host;
    public $payment_request;
    public static $capabilitiesToCheck = null;
    public $paymentaction;
    public $sandbox_client_id;
    public $sandbox_secret_id;
    public $live_client_id;
    public $live_secret_id;
    public $is_sandbox_third_party_used;
    public $is_sandbox_first_party_used;
    public $is_live_third_party_used;
    public $is_live_first_party_used;
    public $merchant_id;
    public $client_id;
    public $secret_id;
    public $is_first_party_used;
    public $skip_final_review;
    public $billing_address;
    public $disable_term;
    public $softdescriptor;
    public $testmode;
    public $fraud_management_filters;
    public $email_notify_order_cancellations;
    public $pending_authorization_order_status;
    public $enable_in_context_checkout_flow;
    public $id;
    public $API_Endpoint;
    public $PAYPAL_URL;
    public $api_username;
    public $api_password;
    public $api_signature;
    public $Force_tls_one_point_two;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        $this->angelleye_ppcp_load_class();
        $this->angelleye_get_settings();
    }

    public function angelleye_get_settings() {
        $this->is_sandbox = 'yes' === $this->setting_obj->get('testmode', 'no');
        $this->paymentaction = $this->setting_obj->get('paymentaction', 'capture');
        $this->sandbox_client_id = $this->setting_obj->get('sandbox_client_id', '');
        $this->sandbox_secret_id = $this->setting_obj->get('sandbox_api_secret', '');
        $this->live_client_id = $this->setting_obj->get('api_client_id', '');
        $this->live_secret_id = $this->setting_obj->get('api_secret', '');
        if (!empty($this->sandbox_client_id) && !empty($this->sandbox_secret_id)) {
            $this->is_sandbox_first_party_used = 'yes';
            $this->is_sandbox_third_party_used = 'no';
        } else if (!empty($this->sandbox_merchant_id)) {
            $this->is_sandbox_third_party_used = 'yes';
            $this->is_sandbox_first_party_used = 'no';
        } else {
            $this->is_sandbox_third_party_used = 'no';
            $this->is_sandbox_first_party_used = 'no';
        }
        if (!empty($this->live_client_id) && !empty($this->live_secret_id)) {
            $this->is_live_first_party_used = 'yes';
            $this->is_live_third_party_used = 'no';
        } else if (!empty($this->live_merchant_id)) {
            $this->is_live_third_party_used = 'yes';
            $this->is_live_first_party_used = 'no';
        } else {
            $this->is_live_third_party_used = 'no';
            $this->is_live_first_party_used = 'no';
        }
        if ($this->is_sandbox) {
            $this->merchant_id = $this->setting_obj->get('sandbox_merchant_id', '');
            $this->client_id = $this->sandbox_client_id;
            $this->secret_id = $this->sandbox_secret_id;
            if ($this->is_sandbox_first_party_used === 'yes') {
                $this->is_first_party_used = 'yes';
            } else {
                $this->is_first_party_used = 'no';
            }
        } else {
            $this->merchant_id = $this->setting_obj->get('live_merchant_id', '');
            $this->client_id = $this->live_client_id;
            $this->secret_id = $this->live_secret_id;
            if ($this->is_live_first_party_used === 'yes') {
                $this->is_first_party_used = 'yes';
            } else {
                $this->is_first_party_used = 'no';
            }
        }
        $this->basicAuth = base64_encode($this->client_id . ":" . $this->secret_id);
        // TODO Include this file from a better place, not a good place to include it.
        if(!function_exists('is_angelleye_aws_down')) {
            include_once ( PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/angelleye-paypal-ppcp-common-functions.php');
        }
        if (is_angelleye_aws_down() == false) {
            $this->ppcp_host = PAYPAL_FOR_WOOCOMMERCE_PPCP_AWS_WEB_SERVICE;
        } else {
            $this->ppcp_host = PAYPAL_FOR_WOOCOMMERCE_PPCP_ANGELLEYE_WEB_SERVICE;
        }
        if ($this->is_sandbox) {
            $this->token_url = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
            $this->paypal_oauth_api = 'https://api-m.sandbox.paypal.com/v1/oauth2/token/';
            $this->generate_token_url = 'https://api-m.sandbox.paypal.com/v1/identity/generate-token';
        } else {
            $this->token_url = 'https://api-m.paypal.com/v1/oauth2/token';
            $this->paypal_oauth_api = 'https://api-m.paypal.com/v1/oauth2/token/';
            $this->generate_token_url = 'https://api-m.paypal.com/v1/identity/generate-token';
        }
    }

    public function angelleye_ppcp_remote_get($paypal_url, $args, $action_name, $retry = 1) {
        $body['testmode'] = ($this->is_sandbox) ? 'yes' : 'no';
        $body['meta'] = [
            'plugin_version' => VERSION_PFW,
            'wp_home' => get_home_url(),
        ];
        $body['paypal_url'] = $paypal_url;
        $body['paypal_header'] = $args['headers'] ?? [];
        $body['paypal_method'] = isset($args['method']) ? $args['method'] : 'GET';
        if (isset($args['body']) && is_array($args['body'])) {
            $body['paypal_body'] = $args['body'];
        } else {
            $body['paypal_body'] = null;
        }
        $body['action_name'] = $action_name;
        $args['method'] = 'POST';
        $args['body'] = wp_json_encode($body);
        $args['timeout'] = 70;
        $args['user-agent'] = 'PFW_PPCP';
        $args['headers'] = array('Content-Type' => 'application/json');
        if ('seller_onboarding_status' !== $action_name) {
            if ($this->angelleye_ppcp_paypal_fee()) {
                $args['headers'][AE_FEE] = "true";
            }
        }
        $args['headers']['plugin_version_id'] = VERSION_PFW;
        // Certificate verification must remain enabled, including on retries.
        $args['sslverify'] = true;
        $request_url = $this->ppcp_host . ('generate_id_token' === $action_name ? 'generate-id-token' : 'ppcp-request');
        $this->result = wp_remote_get($request_url, $args);
        if (is_wp_error($this->result) && $retry < 2) {
            // Reuse the prepared request so the retry preserves the payload and
            // PayPal idempotency headers instead of wrapping the proxy body again.
            $this->result = wp_remote_get($request_url, $args);
        }
        return $this->result;
    }

    /** Obtain a server-only token, isolated by environment and app credentials. */
    public static function get_access_token($is_sandbox, $client_id, $secret_id, $force_refresh = false) {
        $error = new WP_Error('paypal_oauth_failed', 'Unable to authenticate with PayPal. Check the configured API credentials and connection.');
        if (empty($client_id) || empty($secret_id)) {
            return $error;
        }
        $cache_key = 'ae_ppcp_oauth_' . hash('sha256', wp_json_encode(array((bool) $is_sandbox, $client_id, $secret_id)));
        if ($force_refresh) {
            delete_transient($cache_key);
        }
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['access_token']) && isset($cached['expires_at']) && $cached['expires_at'] > time()) {
            return $cached['access_token'];
        }

        $token_url = $is_sandbox ? 'https://api-m.sandbox.paypal.com/v1/oauth2/token' : 'https://api-m.paypal.com/v1/oauth2/token';
        $started_at = time();
        // Do not send token exchanges through the payment response logger.
        $response = wp_remote_post($token_url, array(
            'timeout' => 60,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret_id),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => 'grant_type=client_credentials',
        ));
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return $error;
        }
        $token = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($token) || empty($token['access_token']) || !is_string($token['access_token']) ||
            !isset($token['token_type'], $token['expires_in']) || !is_string($token['token_type']) ||
            0 !== strcasecmp($token['token_type'], 'Bearer') || !is_numeric($token['expires_in']) || (int) $token['expires_in'] <= 0) {
            return $error;
        }
        // Refresh early and account for time spent obtaining the token.
        $remaining = $started_at + (int) $token['expires_in'] - time();
        if ($remaining <= 0) {
            return $error;
        }
        $ttl = $remaining - 60;
        if ($ttl > 0) {
            set_transient($cache_key, array('access_token' => $token['access_token'], 'expires_at' => time() + $ttl), $ttl);
        }
        return $token['access_token'];
    }

    private function direct_request($url, $args, $action_name) {
        foreach (array_keys($args['headers'] ?? array()) as $header) {
            if (in_array(strtolower($header), array('authorization', 'paypal-auth-assertion'), true)) {
                unset($args['headers'][$header]);
            }
        }
        $args['timeout'] = 60;
        $args['user-agent'] = 'PFW_PPCP';
        $args['sslverify'] = true;
        $args['redirection'] = 0;

        // ID tokens are obtained at the OAuth endpoint, not with an access token.
        if ('generate_id_token' === $action_name && rtrim($url, '/') === $this->token_url) {
            $body = isset($args['body']) && is_array($args['body']) ? $args['body'] : array();
            $body['grant_type'] = 'client_credentials';
            $body['response_type'] = 'id_token';
            $args['headers']['Authorization'] = 'Basic ' . $this->basicAuth;
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body'] = http_build_query($body, '', '&');
            return wp_remote_post($url, $args);
        }

        if (isset($args['body']) && is_array($args['body'])) {
            $args['body'] = wp_json_encode($args['body']);
        }
        $token = self::get_access_token($this->is_sandbox, $this->client_id, $this->secret_id);
        if (!is_wp_error($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            $response = wp_remote_get($url, $args);
            // A rejected token may be revoked before its advertised expiry.
            // Retry only an explicit 401, never an ambiguous payment timeout.
            if (is_wp_error($response) || 401 !== (int) wp_remote_retrieve_response_code($response)) {
                return $response;
            }
            $token = self::get_access_token($this->is_sandbox, $this->client_id, $this->secret_id, true);
            if (!is_wp_error($token)) {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
                return wp_remote_get($url, $args);
            }
        }
        // Keep the existing parsed API response contract without exposing OAuth
        // response bodies, credentials, or tokens through the payment logger.
        return array(
            'headers' => array(),
            'response' => array('code' => 503),
            'body' => wp_json_encode(array('name' => 'AUTHENTICATION_FAILURE', 'message' => $token->get_error_message())),
        );
    }

    public function request($url, $args, $action_name = 'default') {

	    /**
	     * PayPal Mocker (Negative Testing)
	     * https://angelleye.atlassian.net/browse/PFW-1935
	     */
	    // Step 1: Load the mock manager
	    if (!class_exists('AngellEYE_PayPal_PPCP_Mock')) {
		    require_once dirname(__FILE__) . '/class-angelleye-paypal-ppcp-mocker.php';
	    }

	    $mock = new AngellEYE_PayPal_PPCP_Mock();

	    // Step 2: Check if mocking is enabled and get a mock response if applicable
	    if ($mock->is_enabled()) {
		    $mock_response = $mock->get_mock_response($url, $action_name);
		    if (!empty($mock_response)) {
			    // Log it for visibility
			    if (class_exists('WC_Logger')) {
				    $logger = wc_get_logger();
				    $logger->info(
					    PHP_EOL . PHP_EOL .
					    '========== MOCKED PAYPAL RESPONSE ==========' . PHP_EOL .
					    'Endpoint: ' . $url . PHP_EOL .
					    'Action: ' . $action_name . PHP_EOL .
					    'Mock Scenario: ' . $mock->get_mock_scenario() . PHP_EOL .
					    'Returned Mocked Data: ' . print_r($mock_response, true) .
					    PHP_EOL . '==========================================' . PHP_EOL,
					    array('source' => 'angelleye_ppcp')
				    );
			    }

			    return $mock_response;
		    }
	    }

        $owned_operation = null;
        try {
            $this->angelleye_get_settings();
            $operation = AngellEYE_PayPal_PPCP_Operation::current($action_name);
            if (!$operation && isset($args['angelleye_operation_scope']) &&
                in_array($action_name, array('create_order', 'capture_order', 'refund_order'), true)) {
                $scope = $args['angelleye_operation_scope'];
                $owned_operation = new AngellEYE_PayPal_PPCP_Operation(
                    array((bool) $this->is_sandbox, $this->merchant_id, 'order:' . $scope['order_id'], $action_name, $scope['resource']),
                    $action_name,
                    $scope['generation'] ?? array()
                );
                $operation = $owned_operation;
            }
            unset($args['angelleye_operation_scope']);
            $reconcile_url = $operation ? $operation->prepare($url, $args) : null;
            unset($args['angelleye_payment_state']);
            if ($reconcile_url) {
                $url = $reconcile_url;
                $args['method'] = 'GET';
                unset($args['body']);
            }
            $is_paypal_api = 'https' === wp_parse_url($url, PHP_URL_SCHEME) && in_array(wp_parse_url($url, PHP_URL_HOST),
                array('api-m.paypal.com', 'api-m.sandbox.paypal.com', 'api.paypal.com', 'api.sandbox.paypal.com'), true);
            if (!$is_paypal_api) {
                $args['timeout'] = '60';
                $args['user-agent'] = 'PFW_PPCP';
                $this->result = wp_remote_get($url, $args);
            } else if ($this->is_first_party_used === 'yes') {
                $this->result = $this->direct_request($url, $args, $action_name);
                if ('generate_id_token' === $action_name && rtrim($url, '/') === $this->token_url) {
                    // OAuth responses contain server credentials (access tokens)
                    // and must not pass through the payment-body logger.
                    return is_wp_error($this->result) ? null : json_decode(wp_remote_retrieve_body($this->result), true);
                }
            } else {
                $args['timeout'] = '60';
                $args['user-agent'] = 'PFW_PPCP';
                $this->result = $this->angelleye_ppcp_remote_get($url, $args, $action_name);
            }

            $response = $this->api_response->parse_response($this->result, $url, $args, $action_name);
            if ($operation) {
                $operation->record_response($response, is_wp_error($this->result) ? 0 : (int) wp_remote_retrieve_response_code($this->result));
            }
            return $response;
        } catch (Exception $ex) {
            $this->api_log->log("The exception was created on line: " . $ex->getFile() . ' ' .$ex->getLine(), 'error');
            $this->api_log->log($ex->getMessage(), 'error');
        } finally {
            if ($owned_operation) {
                $owned_operation->close();
            }
        }
    }

    public function multi_account_request($url, $args, $action_name = 'default') {
        try {
            $this->result = wp_remote_get($url, $args);
            return $this->api_response->parse_response($this->result, $url, $args, $action_name);
        } catch (Exception $ex) {
            $this->api_log->log("The exception was created on line: " . $ex->getLine(), 'error');
            $this->api_log->log($ex->getMessage(), 'error');
        }
    }

    public function angelleye_ppcp_load_class() {
        try {
            if (!class_exists('AngellEYE_PayPal_PPCP_Response')) {
                include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-angelleye-paypal-ppcp-response.php';
            }
            if (!class_exists('WC_Gateway_PPCP_AngellEYE_Settings')) {
                include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-wc-gateway-ppcp-angelleye-settings.php';
            }
            if (!class_exists('AngellEYE_PayPal_PPCP_Log')) {
                include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-angelleye-paypal-ppcp-log.php';
            }
            $this->api_log = AngellEYE_PayPal_PPCP_Log::instance();
            $this->setting_obj = WC_Gateway_PPCP_AngellEYE_Settings::instance();
            $this->api_response = AngellEYE_PayPal_PPCP_Response::instance();
        } catch (Exception $ex) {
            $this->api_log->log("The exception was created on line: " . $ex->getFile() . ' ' .$ex->getLine(), 'error');
            $this->api_log->log($ex->getMessage(), 'error');
        }
    }

    public function angelleye_ppcp_paypal_fee() {
        if (false === ( $value = get_transient(AE_FEE) )) {
            if (!class_exists('AngellEYE_PayPal_PPCP_Seller_Onboarding')) {
                include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-angelleye-paypal-ppcp-seller-onboarding.php';
            }
            $seller_onboarding = AngellEYE_PayPal_PPCP_Seller_Onboarding::instance();
            $result = $seller_onboarding->angelleye_track_seller_onboarding_status($this->merchant_id);
            if ($seller_onboarding->angelleye_ppcp_is_fee_enable($result)) {
                set_transient(AE_FEE, 'yes', 12 * HOUR_IN_SECONDS);
                return true;
            } else {
                set_transient(AE_FEE, 'no', 12 * HOUR_IN_SECONDS);
                return false;
            }
        } else {
            return 'yes' === $value;
        }
    }

    public static function angelleye_ppcp_get_available_endpoints($merchant_id) {
        if (self::$capabilitiesToCheck !== null) {
            return self::$capabilitiesToCheck;
        }
        $availableEndpoints = array();
        if (empty($merchant_id)) {
            return false;
        }
        if (!class_exists('AngellEYE_PayPal_PPCP_Seller_Onboarding')) {
            include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-angelleye-paypal-ppcp-seller-onboarding.php';
        }
        $seller_onboarding = AngellEYE_PayPal_PPCP_Seller_Onboarding::instance();
        $result = $seller_onboarding->angelleye_track_seller_onboarding_status($merchant_id);
        if (!isset($result['products'])) {
            self::$capabilitiesToCheck = false;
            return false;
        }

        $capabilitiesToCheck = ['advanced_cc' => 'CUSTOM_CARD_PROCESSING',
            'apple_pay' => 'APPLE_PAY', 'google_pay' => 'GOOGLE_PAY'];

        if (isset($result['products']) && isset($result['capabilities']) && !empty($result['products']) && !empty($result['products'])) {
            foreach ($result['products'] as $key => $product) {
                foreach ($capabilitiesToCheck as $capabilityKey => $capabilityName) {
                    if (isset($product['vetting_status']) && ('SUBSCRIBED' === $product['vetting_status'] || 'APPROVED' === $product['vetting_status'] ) && isset($product['capabilities']) && is_array($product['capabilities']) && in_array($capabilityName, $product['capabilities'])) {
                        foreach ($result['capabilities'] as $key => $capabilities) {
                            if (isset($capabilities['name']) && $capabilityName === $capabilities['name'] && 'ACTIVE' === $capabilities['status']) {
                                $availableEndpoints[$capabilityKey] = $product['vetting_status'];
                            }
                        }
                    }
                }
            }
        }
        if( angelleye_is_vaulting_enable($result) ) {
            $availableEndpoints['vaulting_advanced'] = 'SUBSCRIBED';
        }
        self::$capabilitiesToCheck = $availableEndpoints;
        return $availableEndpoints;
    }
}
