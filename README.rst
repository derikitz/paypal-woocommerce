PayPal for WooCommerce - independent fork
=======================================

This repository is an independently maintained fork of
`Angell EYE's PayPal for WooCommerce <https://github.com/angelleye/paypal-woocommerce>`_,
starting from plugin version 4.6.3. It extends WooCommerce with PayPal gateways,
including PayPal Complete Payments, card fields, Apple Pay, Google Pay, vaulted
payment methods, and legacy gateway integrations.

The current upstream integration incorporates version 4.6.16. Merge resolutions
retain this fork's TLS, OAuth, idempotency, capture outcome, log redaction and
pay-page authorization changes. Upstream Blocks, wallet, refund and extension
changes still require WordPress and PayPal sandbox integration testing.

Changes here are maintained for this fork. Our workflow does not include sending
pull requests to the upstream plugin. Upstream branding and attribution in the
inherited source do not imply endorsement or support for this fork.

Purpose and current work
------------------------

The immediate focus is reviewing the inherited integration against current PayPal
documentation and improving security, payment reliability, and maintainability.
The audit is a source review; it does not certify production readiness.

Work proceeds in separate, reviewable stages:

1. Secure add-on installation and activation actions.
2. Remove the TLS certificate-verification bypass.
3. Correct direct merchant OAuth authentication.
4. Persist idempotency keys and protect concurrent payment operations.
5. Correct Google Pay success/failure propagation and capture completion.
6. Normalize transport error responses.
7. Redact sensitive payment logs.
8. Authorize existing-order pay-page creation and totals requests.

After each stage, run focused checks, review the diff, provide a commit message,
and pause for review and a separate commit before beginning the next stage.
Webhook reconciliation, order authorization, decline recovery, PHP compatibility,
and staged JavaScript SDK v6 migration are additional audit follow-ups.

The detailed local reference is ``PAYPAL-AUDIT.md``. It is intentionally ignored
by Git and will not be present in a fresh clone. Keep shared project guidance in
this README and record implemented changes in commit history.

Development and validation
--------------------------

Use a local or staging WordPress installation with WooCommerce and PayPal sandbox
credentials. Place this checkout in ``wp-content/plugins/``, activate the plugin,
and configure the gateway under WooCommerce -> Settings -> Payments.

The inherited metadata lists WordPress 5.8+ and WooCommerce 3.0+, with testing
claims up to WordPress 6.8.2 and WooCommerce 10.1.2. Those are upstream declarations,
not a compatibility matrix verified for this fork. The inherited Composer PHP
requirement is also outdated: current source includes typed properties and
``str_contains()``. Use PHP 8+ for development; the supported version matrix still
needs explicit validation and alignment across metadata.

Install development tools with ``composer install``. Available scripts are
``composer lint``, ``composer lint:errors``, and ``composer lint:fix``. Syntax
checks do not replace WordPress integration and sandbox tests. Focused standalone
regression checks live under ``tests/``; run the add-on authorization check with
``php tests/security/addon-installation.php``. Run the proxy TLS check with
``php tests/security/proxy-tls.php``; its HTTP layer is stubbed and makes no
network requests. Run ``php tests/security/direct-oauth.php`` to check direct
merchant authentication, token caching, refresh limits, and partner routing with
stubbed HTTP and transient storage.
Run ``php tests/security/payment-idempotency.php`` to check durable operation
identity, order locks, partial payments, and refund bookkeeping with stubbed
WordPress storage.
Run ``node tests/security/google-pay.cjs`` to check Google Pay authorization,
capture errors, callback deadlines, and navigation against both readable and
production JavaScript bundles. Run ``php tests/security/capture-outcome.php`` to
check that only an explicit successful capture result empties the cart and
returns a successful checkout response.
Run ``php tests/security/response-normalization.php`` to check transport/API
errors, proxy envelopes, debug headers, and successful no-content responses.

API response errors
-------------------

The shared response parser returns normalized failure arrays marked with
``_angelleye_error``. Use ``AngellEYE_PayPal_PPCP_Response::is_error()`` to identify
them. Failures include ``name``, ``message``, ``details``, ``issue``, ``debug_id``,
``http_status``, and ``transport_error``. The HTTP status reflects the upstream
status when supplied by the proxy, otherwise the received HTTP status; transport
failures without an HTTP response use zero. The transport error code is null for
ordinary HTTP/API errors. Errors omit payment ``status`` and resource ``id`` so
truthy error arrays cannot resemble accepted payments.

Successful resource bodies retain their existing shape; successful HTTP 204
responses retain the empty-string contract. The void caller maps a confirmed
no-content result to ``VOIDED``. Failed order updates stop capture, and upsell
adapters use the shared error predicate. ID-token responses are parsed without
passing sensitive bodies to the payment logger. Logger/observer exceptions do
not replace a valid payment response.

Existing-order payment access
-----------------------------

Pay-page order creation and shipping/totals AJAX requests require the matching
order key, WooCommerce ``pay_for_order`` permission, a payable order, POST and an
order-specific nonce. Rejected requests return HTTP 403 before modifying checkout
state or returning order data. The pay form supplies the key and nonce; keep these
hidden fields when customizing the template. Payment-page data and credential
output also respect WooCommerce guest email verification where available.

Run ``php tests/security/order-access.php`` and
``node tests/security/order-access.cjs`` to check access controls and pay-page
form serialization in both source and production JavaScript. Validate account
and guest links, email verification, wallet flows and custom templates in staging.
Remote PayPal/local order mapping across capture and review callbacks remains a
separate audit follow-up. Webhook handling is not configured or verified for this
fork; no webhook listener/subscription has been added in this stage.

Payment diagnostics
-------------------

New gateway configurations default to errors and warnings. Existing saved debug
preferences remain in effect. Verbose diagnostics retain only bounded fields
such as action, HTTP status, PayPal issue codes, debug IDs, request IDs and payment
resource IDs. Request bodies, credentials, customer details, wallet tokens, URLs,
and arbitrary response/error text are omitted. Legacy free-text logger calls
record a redaction event and source location; use structured diagnostic arrays
for new log entries. Temporary and migration logs respect the same debug setting.
FunnelKit and CartFlows diagnostics also omit raw payload/error text; those
extensions retain control over their own log settings.

Browser telemetry retains at most ten context/method/status entries per operation
and twenty operations. The server independently limits and filters received
traces. Error messages, stacks and raw response bodies are not reported. Checkout
scripts no longer print SDK/customer objects to the browser console. Run
``php tests/security/logging-redaction.php`` and
``node tests/security/logging-redaction.cjs`` to check PHP redaction, verbosity,
direct/proxy diagnostics and shipped browser bundles. WordPress/WooCommerce,
wallet and extension integration checks still need staging validation. Historical
logs are unchanged; review and remove them under your retention policy.

Google Pay capture handling
---------------------------

Checkout authorization awaits the server capture/authorization outcome. Failed
or malformed responses stay errors in the Google Pay sheet. Navigation waits
until the sheet closes. Product/cart flows that require a review or server-side
redirect continue to hand off to that route; a handoff is not confirmation that
funds have been captured.

The authorization callback has a 25-second deadline. A timeout or uncertain
capture response asks the buyer to check order status and blocks another payment
attempt in that page instance. The server request may still finish after a client
timeout. Additional authentication responses are rejected without capture; this
change does not implement a new 3D Secure challenge flow.

The updated ``.min.js`` files were rebuilt with Terser 5.51.2 using ``--compress
--mangle``. Keep both production bundles synchronized with their readable sources.
Live Google Pay sheets, browser cancellation, slow network behaviour, and classic
checkout/Blocks compatibility still require staging verification.

Payment operation records
-------------------------

Core create, authorize, capture, and refund paths persist operation UUIDs before
submission. FunnelKit and CartFlows adapters also provide order/offer identity
to the shared transport. An uncertain retry preserves its request UUID and
payment details. A known PayPal result is recovered with a read-only API call.
Distinct partial captures and refunds advance only after local bookkeeping;
recovered refund IDs do not increase local refunded totals twice.

Records use non-autoloaded ``ae_ppcp_operation_*`` WordPress options and order
metadata named ``_ae_ppcp_*_operations``. Records contain identifiers and request
fingerprints rather than full payment bodies. Do not delete these records as
routine cache cleanup: they protect retries after an interrupted payment.

Order locks use ``ae_ppcp_lock_*`` options and are released on normal completion
or PHP shutdown. A worker killed without shutdown can leave a lock behind. Locks
are deliberately not stolen automatically. Confirm that no worker is still
processing the order and reconcile its PayPal state before manually clearing an
orphaned lock. An unknown outcome beyond the conservative six-hour retry window,
or an interrupted multi-capture refund batch, also requires reconciliation before
another operation. There is no automatic administrative recovery UI yet.

Before deploying payment changes, verify the affected success and failure paths,
including permissions/nonces for administrative actions, and capture failures,
timeouts, retries, and duplicate requests for payment operations. Record which
checks ran and which still require a configured store.
In particular, the standalone checks do not exercise real database contention,
HPOS persistence, PayPal sandbox behaviour, or installed upsell plugins. Validate
these integrations in staging before deploying the operation changes.

Maintaining and deploying this fork
-----------------------------------

Deploy reviewed builds from this repository through your own release process.
An upstream update may overwrite fork changes; review and test upstream changes
before incorporating them. This README does not change the plugin's updater
behaviour, identifiers, or external service dependencies.

Some inherited integrations use Angell EYE-hosted services and add-ons. Changes
to this repository alone do not establish control over those services. Review
their behaviour and availability when validating the fork.

References
-----------

* `PayPal REST authentication <https://developer.paypal.com/api/rest/authentication/>`_
* `PayPal idempotency <https://developer.paypal.com/api/rest/reference/idempotency/>`_
* `PayPal webhooks <https://developer.paypal.com/api/rest/webhooks/rest/>`_
* `JavaScript SDK v5 to v6 migration <https://developer.paypal.com/v5-v6>`_
* `PayPal PHP Server SDK <https://github.com/paypal/PayPal-PHP-Server-SDK>`_

License and attribution
------------------------

This fork retains the original Angell EYE attribution and the WooThemes/SkyVerge
derivative-work attribution in the plugin source. The inherited plugin is
licensed under the GNU General Public License v3.0; see ``LICENSE.txt`` and the
individual notices in bundled libraries. ``readme.txt`` retains upstream plugin
description and changelog material; this README describes the fork's workflow.
