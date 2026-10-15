<?php

defined('ABSPATH') || exit;

class AngellEYE_PayPal_PPCP_Log {

    public $log_option;
    public $logger = false;
    protected static $_instance = null;
    public $setting_obj;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        $this->angelleye_ppcp_load_class();
        $this->log_option = isset($this->setting_obj) ? $this->setting_obj->get('debug', 'errors_warnings_only') : 'errors_warnings_only';
    }

    /** Keep only bounded diagnostic fields. Unknown payloads and free text fail closed. */
    public static function redact($value, $depth = 0) {
        if (!is_array($value) || $depth > 8) {
            return '[REDACTED]';
        }
        $result = array();
        $containers = array('request', 'response', 'body', 'headers', 'details', 'purchase_units',
            'payments', 'captures', 'authorizations', 'refunds', 'logTrace');
        $fields = array('action', 'environment', 'http_status', 'status', 'issue', 'debug_id',
            'paypal-debug-id', 'paypal-request-id', 'requestId', 'request_id', 'order_id',
            'capture_id', 'refund_id', 'transaction_id', 'context', 'method', 'line');
        foreach (array_slice($value, 0, 50, true) as $key => $item) {
            if (is_int($key) || in_array($key, $containers, true)) {
                $result[$key] = self::redact($item, $depth + 1);
            } elseif (in_array($key, $fields, true) && is_scalar($item) &&
                preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', (string) $item)) {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    public function browser_error($telemetry) {
        $trace = array();
        $entries = is_array($telemetry) && isset($telemetry['logTrace']) && is_array($telemetry['logTrace']) ?
            $telemetry['logTrace'] : array();
        foreach (array_slice($entries, -10) as $entry) {
            if (!is_array($entry)) { continue; }
            $safe = array();
            if (in_array($entry['context'] ?? null, array('api_request', 'api_response'), true)) {
                $safe['context'] = $entry['context'];
            }
            if (in_array($entry['method'] ?? null, array('GET', 'POST'), true)) {
                $safe['method'] = $entry['method'];
            }
            if (isset($entry['status']) && is_int($entry['status']) && $entry['status'] >= 100 && $entry['status'] <= 599) {
                $safe['status'] = $entry['status'];
            }
            $trace[] = $safe;
        }
        $this->log(array('action' => 'browser_error', 'logTrace' => $trace), 'error');
    }

    private function write($message, $level, $source) {
        $errors = array('emergency', 'alert', 'critical', 'error', 'warning');
        if ($this->log_option !== 'everything' &&
            !($this->log_option === 'errors_warnings_only' && in_array($level, $errors, true))) {
            return;
        }
        if (empty($this->logger)) {
            $this->logger = wc_get_logger();
        }
        if (!is_array($message)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1] ?? array();
            $message = array('action' => 'legacy_diagnostic_redacted',
                'context' => basename($caller['file'] ?? ''), 'line' => $caller['line'] ?? 0);
        }
        $format = isset($this->setting_obj) ? $this->setting_obj->get('log_format', 'json') : 'json';
        $flags = $format === 'detailed' ? JSON_PRETTY_PRINT : 0;
        $this->logger->log($level, json_encode(self::redact($message), $flags), array('source' => $source));
    }

    public function log($message, $level = 'info') {
        $this->write($message, $level, 'angelleye_ppcp');
    }

    public function temp_log($message, $level = 'info') {
        $this->write($message, $level, 'angelleye_ppcp_temp');
    }

    public function migration_log($message, $level = 'info') {
        $this->write($message, $level, 'angelleye_ppcp_migration');
    }

    public function angelleye_ppcp_load_class() {
        try {
            if (!class_exists('WC_Gateway_PPCP_AngellEYE_Settings')) {
                include_once PAYPAL_FOR_WOOCOMMERCE_PLUGIN_DIR . '/ppcp-gateway/class-wc-gateway-ppcp-angelleye-settings.php';
            }
            $this->setting_obj = WC_Gateway_PPCP_AngellEYE_Settings::instance();
        } catch (Exception $ex) {
            $this->log_option = 'errors_warnings_only';
            $this->log(array('action' => 'settings_load_failed'), 'error');
        }
    }

}
