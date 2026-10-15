<?php
defined('ABSPATH') || exit;

/** Durable request identity and a cross-worker lock for a payment resource. */
class AngellEYE_PayPal_PPCP_Operation {
    private static $active = array();
    private static $held_locks = array();
    private $action;
    private $key;
    private $lock;
    private $owner;
    private $record;
    private $resource;
    private $closed = false;

    public function __construct($scope, $action, $generation = array()) {
        $this->action = $action;
        $this->resource = (string) end($scope);
        $hash = hash('sha256', wp_json_encode($scope));
        $this->lock = 'ae_ppcp_lock_' . hash('sha256', wp_json_encode(array_slice($scope, 0, 3)));
        $this->owner = wp_generate_uuid4();
        // add_option uses the unique option name to arbitrate across PHP workers.
        // Do not automatically steal a stale lock while a payment may be running.
        if (isset(self::$held_locks[$this->lock])) {
            if ('refund_batch' !== (self::$held_locks[$this->lock]['action'] ?? '')) {
                throw new RuntimeException('A PayPal operation is already in progress. Please wait before retrying.');
            }
            $this->owner = self::$held_locks[$this->lock]['owner'];
            ++self::$held_locks[$this->lock]['depth'];
        } elseif (!add_option($this->lock, array('owner' => $this->owner, 'created_at' => time()), '', false)) {
            throw new RuntimeException('A PayPal operation is already in progress. Please wait before retrying.');
        } else {
            self::$held_locks[$this->lock] = array('owner' => $this->owner, 'depth' => 1, 'action' => $action);
        }
        register_shutdown_function(array($this, 'close'));
        $this->key = 'ae_ppcp_operation_' . hash('sha256', $hash . wp_json_encode($generation));
        $this->record = get_option($this->key, array());
        if (!$this->record || in_array($this->record['state'] ?? '', array('rejected', 'batch_completed'), true)) {
            $this->record = array('request_id' => wp_generate_uuid4(), 'reference' => wc_generate_order_key(), 'created_at' => time(), 'state' => 'new', 'scope' => $scope);
            $this->persist();
        }
        self::$active[$action] = $this;
    }

    private function persist() {
        if (!add_option($this->key, $this->record, '', false)) {
            update_option($this->key, $this->record, false);
        }
        // Never send a payment unless its identity was durably stored.
        if (get_option($this->key) !== $this->record) {
            throw new RuntimeException('Unable to persist the PayPal operation. No payment request was sent.');
        }
    }

    public static function current($action) {
        return self::$active[$action] ?? null;
    }

    public function reference() {
        return $this->record['reference'];
    }

    public function has_been_submitted() {
        return $this->record['state'] !== 'new';
    }

    public function start_batch() {
        if ($this->has_been_submitted()) {
            throw new RuntimeException('A previous multi-capture refund did not finish. Reconcile its PayPal refunds before starting another refund.');
        }
        $this->record['state'] = 'submitted';
        $this->persist();
    }

    public function finish_batch() {
        $this->record['state'] = 'batch_completed';
        $this->persist();
    }

    private static function canonicalize($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return array_map(array(__CLASS__, 'canonicalize'), $value);
    }

    /** Return a read-only reconciliation URL, or prepare the original POST. */
    public function prepare($url, &$args) {
        $body = $args['body'] ?? null;
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
        }
        $fingerprint = hash('sha256', wp_json_encode(array($url, self::canonicalize($body), $args['angelleye_payment_state'] ?? null)));
        if (isset($this->record['fingerprint']) && $this->record['fingerprint'] !== $fingerprint) {
            throw new RuntimeException('The previous PayPal operation has different payment details. Reconcile it before changing or retrying this operation.');
        }
        $args['headers']['PayPal-Request-Id'] = $this->record['request_id'];
        if (!empty($this->record['remote_id'])) {
            $origin = 'https://' . wp_parse_url($url, PHP_URL_HOST);
            $id = rawurlencode($this->record['remote_id']);
            if ('refund_order' === $this->action) {
                return $origin . '/v2/payments/refunds/' . $id;
            }
            if ('capture_authorized' === $this->action) {
                return $origin . '/v2/payments/captures/' . $id;
            }
            return $origin . '/v2/checkout/orders/' . $id;
        }
        // Orders defaults to six hours of server-side request-ID retention.
        // After that window an uncertain POST must be reconciled manually.
        if (time() - $this->record['created_at'] >= 6 * 60 * 60) {
            throw new RuntimeException('The PayPal retry window has expired. Reconcile this operation before attempting another payment.');
        }
        $this->record['fingerprint'] = $fingerprint;
        $this->record['state'] = 'submitted';
        $this->persist();
        return null;
    }

    public function record_response($response, $status_code) {
        if (is_array($response) && !empty($response['id']) && $status_code >= 200 && $status_code < 300) {
            $this->record['remote_id'] = $response['id'];
            $this->record['state'] = 'accepted';
            $this->persist();
            if (in_array($this->action, array('capture_order', 'authorize_order'), true) && function_exists('WC') && WC()->session) {
                WC()->session->set('ae_ppcp_checkout_generation', $this->record['remote_id']);
            }
        } elseif (422 === $status_code && empty($this->record['remote_id']) &&
            in_array($response['details'][0]['issue'] ?? '', array('INSTRUMENT_DECLINED', 'PAYMENT_SOURCE_DECLINED'), true)) {
            // A definitive validation/decline response permits a corrected attempt.
            $this->record['state'] = 'rejected';
            $this->persist();
        }
    }

    /** Advance partial-payment progress only after local bookkeeping succeeds. */
    public function commit($order) {
        if (empty($this->record['remote_id'])) {
            return;
        }
        $key = '_ae_ppcp_' . $this->action . '_operations';
        $ids = $order->get_meta($key, true);
        $ids = is_array($ids) ? $ids : array();
        if (!isset($ids[$this->resource]) || !is_array($ids[$this->resource])) {
            $ids[$this->resource] = array();
        }
        $ids[$this->resource][$this->record['remote_id']] = true;
        $order->update_meta_data($key, $ids);
        $order->save_meta_data();
    }

    public function close() {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ((self::$active[$this->action] ?? null) === $this) {
            unset(self::$active[$this->action]);
        }
        if (isset(self::$held_locks[$this->lock]) && --self::$held_locks[$this->lock]['depth'] > 0) {
            return;
        }
        unset(self::$held_locks[$this->lock]);
        $lock = get_option($this->lock);
        if (is_array($lock) && ($lock['owner'] ?? '') === $this->owner) {
            delete_option($this->lock);
        }
    }
}
