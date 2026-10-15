// Run: node tests/security/google-pay.cjs
// Exercises shipped JavaScript in a VM with stubbed browser/SDK/HTTP surfaces.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
}

async function verify(suffix) {
    const timers = new Map();
    let timerId = 0;
    const ui = {errors: [], hidden: 0};
    const chain = {length: 1, serialize: () => 'checkout=data', removeClass() { return this; }, on() { return this; }, submit() { return this; }};
    const context = vm.createContext({
        console: {log() {}, error() {}},
        window: {location: {href: ''}},
        document: {}, jQuery: () => chain,
        wp: {i18n: {__: message => message}},
        localizedMessages: {general_error_message: 'Payment failed', empty_cart_message: 'Empty cart'},
        angelleye_ppcp_manager: {page: 'checkout', cc_capture: '/capture?wc-api=1', woocommerce_process_checkout: 'nonce',
            payment_status_unconfirmed: 'Check your order before paying again', direct_capture: '/direct?x=1', checkout_url: '/review?x=1'},
        setTimeout(fn, delay) { const id = ++timerId; timers.set(id, {fn, delay}); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch: async () => { throw new Error('Unexpected fetch'); },
    });
    const dir = path.resolve(__dirname, '../../ppcp-gateway/js');
    vm.runInContext(fs.readFileSync(path.join(dir, `wc-angelleye-common-functions${suffix}.js`), 'utf8') + '\nglobalThis.order = angelleyeOrder;', context);
    vm.runInContext(fs.readFileSync(path.join(dir, `wc-gateway-ppcp-angelleye-google-pay${suffix}.js`), 'utf8') + '\nglobalThis.GoogleButton = GooglePayCheckoutButton;', context);
    const order = context.order;
    const genericError = vm.runInContext('localizedMessages.general_error_message', context);
    order.getCheckoutSelectorCss = () => 'form.checkout';
    order.showProcessingSpinner = () => {};
    order.hideProcessingSpinner = () => { ui.hidden++; };
    order.showError = message => ui.errors.push(message);
    order.handleCreateOrderError = error => ui.errors.push(error.message);
    const response = outcome => ({ok: true, json: async () => outcome});
    const capture = () => order.approveOrder({orderID: 'ORDER', payerID: '', deferRedirect: true});

    const waitCapture = deferred();
    context.fetch = () => waitCapture.promise;
    let settled = false;
    const pending = capture().then(value => { settled = true; return value; });
    await Promise.resolve();
    check(!settled, 'Approval must wait for capture HTTP response');
    waitCapture.resolve(response({success: true, data: {result: 'success', redirect: '/receipt'}}));
    check((await pending).result === 'success' && !context.window.location.href, 'Deferred capture must not navigate before sheet closes');
    for (const outcome of [
        {success: true, data: {result: 'failure', redirect: '/checkout'}},
        {success: false, data: {message: 'Failed'}}, null,
        {success: true, data: {redirect: '/receipt'}},
        {success: true, data: {result: 'success'}},
    ]) {
        context.fetch = async () => response(outcome);
        await assert.rejects(capture()); checks++;
    }
    for (const failedFetch of [
        async () => ({ok: false, json: async () => ({})}),
        async () => ({ok: true, json: async () => { throw new SyntaxError('Bad JSON'); }}),
        async () => { throw new Error('Network lost'); },
    ]) {
        context.fetch = failedFetch;
        await assert.rejects(capture(), error => error.paymentStatusUncertain === true); checks++;
    }
    context.fetch = async () => response({success: true, data: {result: 'failure', redirect: '/checkout'}});
    check((await order.approveOrder({orderID: 'ORDER'})).result === 'failure' && context.window.location.href === '/checkout', 'Legacy caller retains failure redirect');

    context.angelleye_ppcp_manager.page = 'product';
    context.window.location.href = '';
    const handoff = await capture();
    check(handoff.result === 'redirect' && handoff.redirect.startsWith('/review') && !context.window.location.href, 'Review handoff is distinct from completed capture');
    context.angelleye_ppcp_manager.is_skip_final_review = 'yes';
    check((await capture()).redirect.startsWith('/direct'), 'Skip-review handoff still uses server capture route');
    context.angelleye_ppcp_manager.page = 'checkout';

    order.createOrder = async () => ({orderID: 'ORDER'});
    order.updateCartTotalsInEnvironment = () => {};
    order.shippingAddressUpdate = async () => ({});
    const approval = deferred();
    order.approveOrder = () => approval.promise;
    context.angelleye_paypal_sdk = {Googlepay: () => ({confirmOrder: async () => ({status: 'APPROVED'})})};
    let button = new context.GoogleButton();
    const data = {paymentMethodData: {info: {}}};
    const callback = button.onPaymentAuthorized({thisObject: button}, data);
    await new Promise(resolve => setImmediate(resolve));
    check(button.paymentInFlight && !button.pendingRedirect, 'Google authorization remains pending until capture settles');
    const duplicate = await button.onPaymentAuthorized({thisObject: button}, data);
    check(duplicate.transactionState === 'ERROR', 'Concurrent authorization must not start another payment');
    approval.resolve({result: 'success', redirect: '/receipt'});
    check((await callback).transactionState === 'SUCCESS' && button.pendingRedirect === '/receipt', 'Capture success queues navigation');
    check(!context.window.location.href, 'Authorization callback must not navigate while sheet is open');
    check(timers.size === 0, 'Successful authorization clears deadline');

    for (const failure of [undefined, {transactionState: 'ERROR', error: {message: 'Declined'}}]) {
        button = new context.GoogleButton();
        button.processPayment = async () => failure;
        const outcome = await button.onPaymentAuthorized({thisObject: button}, data);
        check(outcome.transactionState === 'ERROR' && outcome.error.intent === 'PAYMENT_AUTHORIZATION' && !button.pendingRedirect, 'Resolved failure must stay an authorization error');
    }
    button = new context.GoogleButton();
    button.processPayment = async () => ({transactionState: 'SUCCESS'});
    check((await button.onPaymentAuthorized({thisObject: button}, data)).transactionState === 'ERROR', 'Malformed success must not close the sheet as paid');
    button = new context.GoogleButton();
    button.processPayment = async () => { throw 'Rejected as a string'; };
    check((await button.onPaymentAuthorized({thisObject: button}, data)).error.message === 'Rejected as a string', 'Rejected promise produces valid error object');
    check(button.paymentError(null).error.message === genericError, 'Null errors use fallback text');

    button = new context.GoogleButton();
    order.approveOrder = async () => ({result: 'failure', redirect: '/checkout'});
    check((await button.processPayment({thisObject: button}, data)).transactionState === 'ERROR', 'Capture failure must not become success');
    context.GoogleButton.googlePayObject = {confirmOrder: async () => ({status: 'PAYER_ACTION_REQUIRED'})};
    let captures = 0;
    order.approveOrder = async () => { captures++; return {result: 'success', redirect: '/receipt'}; };
    check((await button.processPayment({thisObject: button}, data)).transactionState === 'ERROR' && captures === 0, 'Unapproved/3DS-required status must not capture');
    context.GoogleButton.googlePayObject = {confirmOrder: async () => { throw new Error('Confirmation failed'); }};
    check((await button.processPayment({thisObject: button}, data)).transactionState === 'ERROR', 'Confirmation rejection stays error');
    order.createOrder = async () => ({});
    check((await button.processPayment({thisObject: button}, data)).transactionState === 'ERROR', 'Missing order ID stops payment');

    context.angelleye_ppcp_manager.page = 'product';
    context.GoogleButton.googlePayObject = {confirmOrder: async () => ({status: 'APPROVED'})};
    order.createOrder = async () => ({orderID: 'ORDER'});
    order.shippingAddressUpdate = async () => { throw new Error('Shipping failed'); };
    check((await button.processPayment({thisObject: button}, data)).transactionState === 'ERROR' && captures === 0, 'Shipping failure prevents approval handoff');
    order.shippingAddressUpdate = async () => ({});
    order.approveOrder = async () => ({result: 'redirect', redirect: '/review'});
    check((await button.processPayment({thisObject: button}, data)).redirect === '/review', 'Approved product payment can hand off to final review');
    context.angelleye_ppcp_manager.page = 'checkout';

    // Timeout responds before Google's 30-second limit; late success cannot redirect.
    button = new context.GoogleButton();
    const slowPayment = deferred();
    button.processPayment = () => slowPayment.promise;
    const timed = button.onPaymentAuthorized({thisObject: button}, data);
    await Promise.resolve();
    const deadline = [...timers.values()][0];
    check(deadline.delay < 30000, 'Authorization deadline must precede SDK timeout');
    deadline.fn();
    check((await timed).transactionState === 'ERROR' && button.paymentStatusUncertain, 'Timeout reports uncertain payment status');
    slowPayment.resolve({transactionState: 'SUCCESS', redirect: '/late-receipt'});
    await new Promise(resolve => setImmediate(resolve));
    check(!button.pendingRedirect, 'Late capture cannot turn timed-out callback into success');
    check((await button.onPaymentAuthorized({thisObject: button}, data)).transactionState === 'ERROR', 'Uncertain payment cannot be charged again in the same page');

    // loadPaymentData closes the sheet before queued navigation occurs.
    button = new context.GoogleButton();
    order.getCartDetails = () => ({totalAmount: '10.00'});
    order.setPaymentMethodSelector = () => {};
    order.triggerPaymentCancelEvent = () => {};
    button.getGooglePaymentDataRequest = () => ({});
    const sheet = deferred();
    button.getGooglePaymentsClient = () => ({loadPaymentData: () => sheet.promise});
    const navigation = button.handleClickEvent({}, button);
    button.pendingRedirect = '/receipt-after-sheet';
    check(!context.window.location.href, 'Wait for sheet close before navigating');
    sheet.resolve({});
    await navigation;
    check(context.window.location.href === '/receipt-after-sheet', 'Navigate after sheet closes successfully');
    context.window.location.href = '';
    button.getGooglePaymentsClient = () => ({loadPaymentData: () => Promise.reject({statusCode: 'CANCELED'})});
    await button.handleClickEvent({}, button);
    check(!button.pendingRedirect && !context.window.location.href, 'Canceled sheet clears queued navigation');
    let opened = 0;
    button.getGooglePaymentsClient = () => { opened++; throw new Error('Must not open sheet'); };
    button.paymentStatusUncertain = true;
    await button.handleClickEvent({}, button);
    check(opened === 0, 'Uncertain payment cannot reopen the sheet to retry');
    button.paymentStatusUncertain = false;
    order.getCartDetails = () => ({totalAmount: 0});
    await button.handleClickEvent({}, button);
    check(opened === 0, 'Empty cart cannot open payment sheet');
}

(async () => {
    await verify('');
    await verify('.min');
    console.log(`Passed ${checks} Google Pay checks across source and production bundles.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
