# StayCore — Extend Booking (v0.8.4 proposal)

## Staff workflow

1. Open an **active** Confirmed or Checked In reservation in the StayCore staff dashboard.
2. Under **Extend booking**, choose a new checkout date. The existing checkout clock (e.g. 11:00 AM) is preserved.
3. Enter the **agreed nightly rate for the whole reservation**, including groups. No rate is guessed from the old booking total, base bed price or OTA source.
4. Review the calculated extra nights, added charge, revised booking total and remaining balance.
5. Confirm. The server rejects unavailable beds/rooms, stale edited bookings, closed bookings, invalid prices, and checkout dates in the past.
6. A single audited extension updates the current reservation's checkout and total, preserving all previous captured payments. Staff can then send the revised stay confirmation manually using the WhatsApp link.
7. Any new payment is recorded through the existing Payments form, then Checkout can proceed when the remaining balance is cleared.

## Pricing and permissions

- Added charge = additional **calendar nights × agreed booking nightly rate** (round to two decimals).
- Revised total = previous reservation total + added charge.
- Outstanding balance = revised total − already captured payments (never below zero).
- Only staff with **both** `staycore_manage_reservations` and `staycore_manage_payments` can extend.
- A zero-rate complimentary extension additionally requires manager permission and a documented reason.
- Each extension is capped at 90 extra nights.
- The ordinary Edit Booking form cannot extend checked-in dates or increase a checkout by a calendar day, so staff must use the dedicated flow.

## API

Authenticated POST `/wp-json/staycore/v1/reservations/{id}/extend`

```json
{
  "expected_check_out": "2026-10-11 11:00:00",
  "expected_total": "1000.00",
  "new_checkout_date": "2026-10-13",
  "nightly_rate": "650.00",
  "reason": "Guest requested two more nights"
}
```

The receipt contains `id`, `status`, `check_out`, `total`, `payment` and an `extension` audit summary. A stale request returns HTTP 409 instead of charging again.

## Validation / rollout

`Validate StayCore` runs `tests/booking-extension.php`, `tests/booking-extension-api.php` and `tests/extend-ui-contract.cjs`, alongside existing PHP/JS and checkout tests.

**Do not deploy until a protected production release workflow and staging verification are ready.** GitHub CI does not prove the full WordPress browser UX. Verify group extensions, concurrent check-ins, role restrictions, staff confirmation links, housekeeping state and payment collection on staging. The existing global concurrent inventory allocation race remains separately tracked and is not resolved by per-reservation optimistic locking.

No automatic OTA/channel-manager calendar update or WhatsApp API send is implemented. Staff must reconcile affected OTA reservations with their channel manager if required.
