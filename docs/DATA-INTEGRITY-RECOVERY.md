# StayCore: Booking Data Integrity and Recovery

**Status:** Proposed PMS v0.8.5 protections only. Not deployed or validated in a production clone. The revision ledger is not an off-site backup.

## Verified incident — 2026-10-01

The old `migrate_reservation_units()` grouped certain legacy reservations under a master ID and **physically deleted** the source rows. The current database has 27 reservations, with three missing IDs among 1..30. Live `group_migrated` audit events confirm the exact mapping:

| Surviving master | Legacy IDs in migration event | Removed source IDs |
| --- | --- | --- |
| 6 | 6, 7, 8 | 7, 8 |
| 10 | 10, 11 | 11 |

Payments and assignment rows were moved to each master; individual deleted reservation rows were not archived. Merging rows is not necessarily the loss of whole stays, but can destroy original per-booking amounts/statuses/notes that were not copied to the master.

The live check found **no orphan guest, reservation-unit, or payment references**. That does **not** rule out missing records older than the current installation or wrong booking details after the merge.

## What v0.8.5 adds

- Eliminates automatic legacy group merge/deletion. The migration now only backfills missing unit mappings, logs duplicate groups and leaves originals intact for manual reconciliation.
- New private `staycore_reservation_revisions` table: append-only JSON snapshots of the booking, guest contact (without guest ID image/meta), assignments, group members and payment rows. Each snapshot has an SHA-256 checksum and actor/time metadata. No public REST export is exposed.
- Existing reservation updates fail closed if the prior version cannot be saved. Reservation edits use InnoDB transactions and add/remove only changed assignment rows.
- Creation, moving units and checking out use transactions: if required writes or revision insertion fail, all changes should roll back on InnoDB. Group roster changes and payments also require previous-version snapshots.
- Daily WordPress Cron checkpoints each booking, track total count and email the WordPress admin on an unexplained decrease or checkpoint failure. Exceeding 2000 reservations triggers an explicit alert rather than claiming full coverage.
- All work stays in GitHub PR review until database restore drills, WordPress staging smoke tests and migration tests pass.

## Crucial limitations

- **A revision in the same WordPress database is NOT an independent backup.** Disk loss, compromised DB credentials, a hosting reset, or a destructive raw SQL import can destroy both the live booking and ledger.
- Daily checkpoints use WP-Cron, which depends on WordPress traffic/cron execution. Monitor actual firing in production.
- This is not a write-ahead log or physical point-in-time recovery. Snapshots omit passport/Aadhaar ID numbers, ID images and guest meta for privacy. Preserve uploaded guest ID assets in separately encrypted file/database backups according to applicable law.
- Only the audited REST mutation routes are protected. Third-party integrations and direct SQL can bypass these PHP checks. Database accounts with raw SQL access remain powerful.
- Globally atomic *room allocation across simultaneous different bookings* remains open (#31).
- No actual off-site scheduled backup is created or verified by these code changes.

## Mandatory independent backup policy

Use an independent encrypted backup destination under the property owner's control. Minimum target:
1. Database dump and uploaded media daily; retain 7 daily, 6 weekly and 3 monthly restore points (or stricter policy chosen by the owner).
2. Before each plugin deploy/migration: capture a verified backup and record restore point ID + tested commit SHA.
3. Test a restore into staging monthly initially, then quarterly after stable operation; verify booking count, assignment count, captured payments and two randomly selected reservation histories.
4. Protect backup encryption keys and guest personal details; never put backup SQL dumps in public GitHub, website uploads, publicly accessible cloud folders, or unsecured email.
5. Alert when a scheduled backup was skipped, is corrupt, or cannot be decrypted/restored.

### Hostinger recovery — urgent

Hostinger documents hPanel **Websites → Dashboard → Backups → Restore and download**. Check available WordPress database restore points, paying particular attention to backups before the migration time **2026-10-01 23:47:30** (as recorded by WP). Download a fresh copy of the current site first.

**NEVER restore a full older backup directly over production.** It would overwrite bookings and payments created since that restore point. Import older SQL into **isolated staging**, compare missing IDs 7, 8 and 11 with their master bookings 6 and 10, and selectively reconcile data. Seek a qualified operator for any SQL merge.

Sources:
- https://www.hostinger.com/support/5981435-how-to-download-backups-at-hostinger/
- https://www.hostinger.com/support/4283700-how-to-restore-backups-at-hostinger/

## Release acceptance gates

- CI safety snapshot tests (guest/payment/assignment preservation, hash verification, fail-closed mutations, abnormal count detection).
- Verified transactional database (live StayCore tables were read-only checked: InnoDB).
- Real transactional rollback tests against disposable InnoDB staging with injected failures, including partial group insert, payment capture, room reassignment and checkout housekeeping tasks.
- Existing old group data not dropped or auto-merged on upgrade.
- Plugin deploy must be gated, staged and reversible (see #32).
- Protect the proposed Extend Booking (#34) with `StayCore_Data_Safety::safeguard($id,'before_extension')` before it merges.
