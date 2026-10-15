<?php
/**
 * Standalone authorization regression check. Run with:
 * php tests/security/addon-installation.php
 *
 * WordPress functions are stubbed; no downloads or filesystem mutations occur.
 */
define('ABSPATH', __DIR__ . '/');

class AddonAuthorizationDenied extends RuntimeException {}

$capabilities = array();
$nonce_action = null;
$checks = 0;

function current_user_can($capability) {
    global $capabilities;
    return in_array($capability, $capabilities, true);
}

function esc_html__($text, $domain) {
    return $text;
}

function wp_die($message, $title, $args) {
    throw new AddonAuthorizationDenied($message, $args['response']);
}

function check_admin_referer($action) {
    global $nonce_action;
    if ($nonce_action !== $action) {
        throw new AddonAuthorizationDenied('Invalid nonce', 403);
    }
    return 1;
}

function expect_check($condition, $message) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

require dirname(__DIR__, 2) . '/ppcp-gateway/class-angelleye-paypal-ppcp-front-action.php';
$reflection = new ReflectionClass('AngellEYE_PayPal_PPCP_Front_Action');
$handler = $reflection->newInstanceWithoutConstructor();
$authorize = $reflection->getMethod('authorize_addon_installation');
$authorize->setAccessible(true);
$actions = array(
    'install_shipment_tracking_plugin' => 'angelleye_ppcp_install_shipment_plugin',
    'install_pfwma_plugin' => 'angelleye_ppcp_install_pfwma_plugin',
);

foreach ($actions as $method => $action) {
    // Exercise the real installer entry points. Authorization must stop them
    // before any WordPress filesystem/download functions can be reached.
    foreach (array(array(), array('install_plugins'), array('activate_plugins')) as $allowed) {
        $capabilities = $allowed;
        $nonce_action = $action;
        try {
            $handler->$method();
            throw new RuntimeException('Installer accepted insufficient permissions');
        } catch (AddonAuthorizationDenied $error) {
            expect_check($error->getCode() === 403, 'Expected capability denial');
        }
    }

    $capabilities = array('install_plugins', 'activate_plugins');
    foreach (array(null, 'invalid', $action === reset($actions) ? end($actions) : reset($actions)) as $invalid_nonce) {
        $nonce_action = $invalid_nonce;
        try {
            $handler->$method();
            throw new RuntimeException('Installer accepted an invalid nonce');
        } catch (AddonAuthorizationDenied $error) {
            expect_check($error->getCode() === 403, 'Expected nonce denial');
        }
    }

    // Check successful authorization alone, without installing an add-on.
    $nonce_action = $action;
    $authorize->invoke($handler, $action);
    expect_check(true, 'Authorized request passed');
}

// Legacy public API actions must no longer reach an installer.
foreach (array('install_shipment_plugin', 'install_pfwma_plugin') as $legacy_action) {
    $_GET = array('angelleye_ppcp_action' => $legacy_action);
    $handler->handle_wc_api();
    expect_check(true, 'Legacy public action is inert');
}

echo 'Passed ' . $checks . " add-on authorization checks.\n";
