PayPal for WooCommerce - independent fork
=======================================

This repository is an independently maintained fork of
`Angell EYE's PayPal for WooCommerce <https://github.com/angelleye/paypal-woocommerce>`_,
starting from plugin version 4.6.3. It extends WooCommerce with PayPal gateways,
including PayPal Complete Payments, card fields, Apple Pay, Google Pay, vaulted
payment methods, and legacy gateway integrations.

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

Before deploying payment changes, verify the affected success and failure paths,
including permissions/nonces for administrative actions, and capture failures,
timeouts, retries, and duplicate requests for payment operations. Record which
checks ran and which still require a configured store.

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
