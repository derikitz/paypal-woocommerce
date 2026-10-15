// Run: node tests/security/logging-redaction.cjs
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
(async () => {
 for (const suffix of ['.js', '.min.js']) {
    const requests = [];
    const context = vm.createContext({wp: {i18n: {__: x => x}},
        angelleye_ppcp_manager: {handle_js_errors:'/telemetry'},
        fetch: async (url, options) => {requests.push(options); return {};}});
    vm.runInContext(fs.readFileSync('ppcp-gateway/js/wc-angelleye-common-functions' + suffix, 'utf8') + '\n globalThis.reporter = angelleyeJsErrorLogger;', context);
    const reporter = context.reporter;
    const secret = 'SENSITIVE_CUSTOMER_TOKEN';
    for (let i = 0; i < 30; i++) reporter.addToLog(123, {context:'api_response',status:500,
        body:secret, url:secret, response:{body:secret}, token:secret, payer:{name:secret}, method:secret});
    check(JSON.stringify(reporter.errorStackMeta).indexOf(secret) < 0, 'No raw data retained');
    check(reporter.getLogTrace(123).length === 10, 'Trace bounded');
    check(reporter.getLogTrace(123)[0].status === 500, 'HTTP status retained');
    reporter.logJsError({msg:secret,stack:secret},123);
    await Promise.resolve();
    check(!requests[0].body.includes(secret), 'No error/stack/token sent');
    check(JSON.parse(requests[0].body).error === 'browser_error', 'Static browser error category');
    check(reporter.getLogTrace(123).length === 0, 'Sent trace discarded');
    for (let i=0;i<100;i++) reporter.addToLog(i,{context:'api_request',method:'POST',body:secret});
    check(Object.keys(reporter.errorStackMeta).length === 20, 'Stored operations bounded');
    reporter.addToLog('__proto__', {context:secret});
    check(!JSON.stringify(reporter.errorStackMeta).includes(secret), 'Invalid IDs ignored');
 }
 for(const file of fs.readdirSync('ppcp-gateway/js').filter(x=>x.endsWith('.js'))) {
    const source=fs.readFileSync('ppcp-gateway/js/'+file,'utf8');
    check(!/\bconsole\.(?:log|error|warn|info|debug)\s*\(/.test(source), 'No raw browser console logs: '+file);
 }
 console.log(`${checks} browser logging checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
