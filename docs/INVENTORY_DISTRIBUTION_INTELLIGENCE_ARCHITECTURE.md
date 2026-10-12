# StayCore PMS — Inventory, Distribution & Intelligence Architecture
Status: **design contract**; v0.8.5 implements admin inventory and a read-only seven-day occupancy grid. No OTA or AI connections are active.

## Benchmarks / reference patterns
- Little Hotelier: unified reservation calendar, mobile front desk, central room-and-rate grid, channel distribution.
  https://www.littlehotelier.com/features/
- Mews: two-way channel-manager synchronization of availability, rates, restrictions and booking lifecycle.
  https://docs.mews.com/channel-manager-api
- Beds24: property/room-rate/channel mappings and independently configurable daily pricing.
  https://wiki.beds24.com/index.php/Connect_PMS_with_API

## Domain contracts (future migrations; not existing database tables)
**Property** (tenant_id, name, timezone, currency) → **PhysicalRoom** (room_number, room_type_id, housekeeping, floor) → **SellableUnit** (unit_id, bed or entire room, capacity, base_rate, active flag).
**RoomType** (dorm AC/non-AC, private AC, occupancy rules) ↔ **RatePlan** (plan_id, currency, meal inclusion, occupancy pricing, taxes, cancellation policy, min/max nights).
**UnitCalendar** (unit_id, local_date, derived availability, reason, optional restriction_id). Physical stock and sellable stock are distinct. No future API may sell private rooms and their constituent beds simultaneously.
**ReservationLedger** (reservation_id, state, date range [check-in, check-out), immutable payment references, customer/guest identifiers separate from inventory).
**SourceConnection** (provider, account scope, credentials reference, rate-map version, capability grants, sync cursor).
**ExternalIdentityMap** (provider, property_ref, room_type_ref, rate_plan_ref, remote_booking_ref, internal_id, direction).
**EventInbox/Outbox** (event_id, provider, external_id, idempotency key, payload hash, timestamp, processing state, retry count, error, replay authority). Raw external payloads must be redacted, limited in retention and never logged with secret credentials.
**ExternalObservation** (provider, observed_at, location, event_date, source license, metric, unit, confidence, TTL) stored separately from customer reservations.
**PriceProposal** (room_type_id, date range, proposed INR rate, floor/ceiling, source features, model identifier, rationale, confidence, created_by, approval status, version).

## Booking and ARI invariants
1. WordPress remains the source of truth for physical units and reservation commitments until a separately approved migration.
2. Availability/rates/restrictions (ARI) are separately versioned per date, rate plan, and source channel.
3. The booking engine must atomically guard overlapping active reservations. Keep today's overlap recheck; add DB concurrency controls and transaction tests before enabling any OTA ingestion.
4. Check-out is exclusive. A bed capacity is one person; private room occupancy must respect max capacity.
5. Never let external platform errors, AI output, or stale caches create inventory or rewrite captured payments.
6. Every OTA reservation update uses a stable provider booking ID, deduplication, version ordering, acknowledgment, retry/backoff and dead-letter review.
7. OTA outbound queues use delta updates plus periodic full reconciliation, per-source rate limits, circuit breakers and sync status.
8. No automatic price mutation from model suggestions. Human approved proposals are validated against floor/ceiling, special dates, tax treatment and rate parity rules. A future explicit toggle may allow narrow guarded automation with rollback.
9. Keep all third-party source licensing, provenance, timestamp, legal purpose, retention and privacy policies in the ingestion contract.

## Mobile-first user journeys
**Today:** arrival/departure cards, bed availability, housekeeping, exceptions, one-tap reservation.
**Inventory:** add rooms/dorm beds, grouped list, occupancy status, rate editor, swipeable seven-day grid.
**Calendar (future):** selectable day/week/month, sticky unit names, horizontal gestures, filters, booking chips and modification conflict warning.
**Distribution (future):** mapped/unmapped channels, last sync, per-channel errors and pause control.
**Revenue intelligence (future):** suggested price, current price, reason, confidence, approval, expiry and audit history.
Never put guest ID photos or private contact data into analytics datasets or AI prompts.

## Future integration ports (no active implementation)
- `InventoryReadPort`: getStock(property, dateRange, unitType); getRatePlans; getRestrictions.
- `ReservationWritePort`: ingestBooking(provider, externalRef, version, normalizedPayload, idempotencyKey) through validation and audited core booking service.
- `ARIOutboxPort`: queue availability/rate/restriction changes, reconcile acknowledgements.
- `ObservationIngestPort`: acquire → verify source terms → schema validate → normalize timezone/currency → deduplicate → provenance stamp → quality checks → TTL.
- `PriceRecommendationPort`: propose only; model output is non-authoritative until approved, with offline backtests before deployment.

## Release gates
- v0.8.5: admin-only mobile inventory UI and read-only calendar, unit create/update paths; no schema migration and no new provider credentials.
- Future phase: split room types from physical rooms, rate plans and date-based ARI behind a reviewed additive migration and staging rollback.
- Separate integrations phase: provider sandbox, idempotent event processing, reconciliation, security review and formal certification where required.
- Separate AI phase: consent and source licensing, forecast accuracy tests, business constraints, human-in-the-loop approval, model rollback.
- Preserve encrypted offsite backups and a proved staging restore drill before any data migration or production activation.

## Rollback and tenant boundaries
Source-only feature branch until CI/staging pass. Revert v0.8.5 plugin files to canonical v0.8.4 ZIP; do not delete PMS tables. Keep Andaz customer data, marketing tools and Moksha checkout isolated. No OTA credentials or guest records stored in this design document.
