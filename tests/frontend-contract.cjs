const assert = require('node:assert/strict');
const fs = require('node:fs');

const app = fs.readFileSync(require('node:path').join(__dirname, '../admin/assets/app.js'), 'utf8');
assert.match(app, /StayCorePMS\.caps\.checkout/, 'Checkout button must honor the checkout capability');
assert.match(app, /Request checkout/, 'Unpaid checkout must expose a request action for server-side exceptions');
assert.match(app, /e\.code==='payment_due'/, 'Server-side payment refusal must focus payment collection');
assert.match(app, /sc-payment-needed/, 'Payment collection action must remain available');
console.log('PASS: frontend checkout contracts');
