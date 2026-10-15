// Run: node tests/security/order-access.cjs
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let checks = 0;
function check(value, label) { assert.ok(value, label); ++checks; }
(async () => {
    for (const suffix of ['.js', '.min.js']) {
        const requests = [];
        const credentials = 'woo_order_id=1&woo_order_key=secret&angelleye_ppcp_pay_order_nonce=nonce';
        const context = vm.createContext({
            wp: {i18n: {__: x => x}},
            angelleye_ppcp_manager: {page:'checkout', is_pay_page:'yes', create_order_url:'/create', shipping_update_url:'/shipping'},
            jQuery: () => ({serialize: () => credentials, val() {return 'paypal';}, find() {return this;}, remove() {return this;}, appendTo() {return this;}}),
            fetch: async (url, options) => {
                requests.push({url, body:options.body});
                return {status:200,redirected:false,json:async()=>({orderID:'PAYPAL-ORDER'})};
            },
        });
        vm.runInContext(fs.readFileSync('ppcp-gateway/js/wc-angelleye-common-functions' + suffix, 'utf8') + '\n globalThis.order = angelleyeOrder;',context);
        const order = context.order;
        order.getWooFormSelector = () => 'form#order_review';
        order.removeError = () => {};
        order.ppcp_address = null;
        for (const selector of ['#angelleye_ppcp_checkout_top', '#angelleye_ppcp_checkout_top_google_pay', '#angelleye_ppcp_checkout_top_apple_pay']) {
            await order.createOrder({angelleye_ppcp_button_selector:selector});
            check(requests.at(-1).body.startsWith(credentials + '&angelleye_ppcp_checkout_source='), 'Pay page preserves authorization fields: '+selector);
        }
        await order.shippingAddressUpdate({});
        check(requests.at(-1).url === '/shipping' && requests.at(-1).body.startsWith(credentials + '&angelleye_ppcp_checkout_source='), 'Pay page shipping request includes authorization');
        order.ppcp_block_mode = true;
        await order.createOrder({angelleye_ppcp_button_selector:'#angelleye_ppcp_checkout_top'});
        check(requests.at(-1).body.startsWith(credentials), 'Pay-page credentials survive a stale Blocks mode flag');
        order.ppcp_block_mode = false;
        context.angelleye_ppcp_manager.is_pay_page = 'no';
        await order.createOrder({angelleye_ppcp_button_selector:'#angelleye_ppcp_checkout_top'});
        check(requests.at(-1).body === 'angelleye_ppcp_checkout_source=checkout_top', 'Regular express checkout sends the upstream source marker without pay-page credentials');
        order.ppcp_block_mode = true;
        await order.createOrder({});
        const blockBody = new URLSearchParams(requests.at(-1).body);
        check(blockBody.get('angelleye_ppcp_checkout_source') === 'block_checkout', 'Upstream Blocks preflight source preserved');
        check(blockBody.get('payment_method') === 'angelleye_ppcp_cc', 'Blocks preflight identifies the card gateway');
        check(!blockBody.has('woo_order_key'), 'Blocks preflight does not serialize unrelated pay-page credentials');
    }
    console.log(`${checks} browser order access checks passed.`);
})().catch(error => {console.error(error);process.exitCode=1;});
