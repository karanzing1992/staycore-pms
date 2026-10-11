const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const app=fs.readFileSync(path.join(__dirname,'../admin/assets/app.js'),'utf8');
const admin=fs.readFileSync(path.join(__dirname,'../admin/class-staycore-admin.php'),'utf8');
const backend=fs.readFileSync(path.join(__dirname,'../includes/class-staycore-rest.php'),'utf8');

assert.match(app,/StayCorePMS\.caps\.extend&&\['confirmed','checked_in'\]/,'Only active bookings should show extension action');
assert.match(app,/name="new_checkout_date"/,'The extension form must request a new checkout date');
assert.match(app,/name="nightly_rate"/,'The form must request a price instead of silently using the old total');
assert.match(app,/expected_check_out:r\.check_out/,'The form must send the checkout version for optimistic locking');
assert.match(app,/expected_total:r\.total/,'The form must send the total version for optimistic locking');
assert.match(app,/api\('reservations\/'\+id\+'\/extend'/,'The UI must use the dedicated extension endpoint');
assert.match(app,/Send WhatsApp/,'A revised guest notification action must be available');
assert.match(admin,/'extend'=>StayCore_REST::can_extend_booking\(\)/,'The capability must be supplied to the frontend');
assert.match(backend,/self::can_reservations\(\) && self::can_payments\(\)/,'Both booking and payment permissions are required');
assert.match(backend,/booking_extended/,'Extension updates must have a distinct audit log');
assert.match(backend,/reservation_extended/,'Extension event must be published');
assert.match(backend,/use_extend_booking/,'Generic edits must not bypass the extension flow');
console.log('PASS: Extend Booking frontend + permission contracts');
