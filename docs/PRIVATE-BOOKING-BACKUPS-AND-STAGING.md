# Andaz StayCore — Private Booking Backups & Staging Recovery

**Status:** v0.8.6 draft branch only. Production is still v0.8.1 until reviewed deployment. No live backup, staging environment or restoration has been created or tested through this repository change.

## 1. On-save protection

StayCore uses three safeguards specifically for reservation data, not a full WordPress dump at every save.

1. **Before modifying an existing booking:** append a full private SQL revision of its reservation row, assigned inventory, group roster, sanitized guest contact and payment references. Include actor, action, timestamp and SHA-256 checksum.
2. **Independent encrypted archive:** before accepting the revision, write a matching AES-256-GCM authenticated file in a server-side directory outside WordPress's publicly served root. The vault maintains a randomly generated 256-bit key in a separate file. Files and key use restrictive filesystem permissions. Booking create and ordinary Edit Booking Save additionally capture the newly saved state before commit.
3. **Transaction gate:** only commit edits/new bookings after protected snapshots have succeeded. If backup or a required booking DB write fails, roll back the InnoDB transaction and tell staff to contact management. This prevents silent partial writes.

Use the administrator-only **Tools → StayCore Booking Recovery → Create encrypted checkpoint of every current booking** to capture an on-demand baseline before deployment. A daily WordPress cron checkpoint remains as a fallback.

The vault contains encrypted **reservation-scoped** history, not an all-table MySQL dump. Full MySQL and media disaster recovery still depends on independent Hostinger backups and off-site exports. The encrypted per-booking snapshots deliberately exclude passport/Aadhaar ID images and secrets.

### Private vault setup

Before installing on staging, configure a path outside the WordPress webroot, writable only by the PHP process. Prefer a private directory under the hosting account but **not** `public_html`. In the staging site's `wp-config.php`:

```php
define('STAYCORE_PRIVATE_BACKUP_DIR', '/absolute/private/path/staycore-vault-stage');
define('STAYCORE_RECOVERY_ENABLED', true); // staging only, never blindly set on production
```

Do not copy an example path literally. Use your Hostinger account's actual private filesystem path. The plugin's administrator-only Recovery page reports whether the vault is ready. If it is not, protected booking writes intentionally fail closed. Stage and correct permissions before production release.

A randomly generated `vault.key` is needed to decrypt every `.scbackup` created by that vault. Copy **both** the encrypted archives and the key to a separate encrypted off-site storage account with restricted access. Do not put the key in GitHub, WordPress media, emails or publicly shared drives. Never replace the existing key when restoring the vault.

### Supported restore scope

Only WordPress administrators with `manage_options` can access **Tools → StayCore Booking Recovery**. Staff roles, including StayCore Owner and Manager without WordPress administrator status, cannot restore.

1. Find a booking ID and select a stored revision.
2. Verify the SQL revision checksum and matching AES-GCM authenticated file in the private vault.
3. Preview each booking-field/room assignment change and check status, capacity, conflicts and already-captured payments.
4. Enter an audit reason (12+ characters) and explicitly type **RESTORE BOOKING {id}**.
5. Within ten minutes, confirm the action. The server rechecks the current booking fingerprint under a database lock, saves the previous state, restores the approved fields in one transaction, saves the new state and writes the admin activity audit record. Any failed step rolls back.

**Not automatically restored:** guest identity, payments, group bookings, closed bookings and booking status transitions. These require staged/manual reconciliation to avoid overwriting legitimate subsequent changes. Deleted booking IDs 7/8/11 cannot be recreated from a v0.8.6 revision because they were deleted before that version existed.

**Live writes are disabled by default:** `STAYCORE_RECOVERY_ENABLED` must be explicitly defined as `true` only after a staging rehearsal, a current backup and documented administrative authorization.

## 2. Hostinger staging — safest setup

Hostinger's native staging tool is available for eligible Business/Cloud WordPress plans:

1. Log in at **https://hpanel.hostinger.com** → Websites → locate `mokshagoa.com` → Dashboard → WordPress → Staging → **Create staging**.
2. Choose a staging name; wait for its own WordPress installation and separate database. If no Staging option is available, create a separate password-protected subdomain and WordPress database manually, following Hostinger's staging documentation.
3. Before testing, confirm its database is different from the live DB (check `DB_NAME` in staging `wp-config.php`), and that `home`/`siteurl` point to the staging URL.
4. Password-protect the staging site and disable outgoing emails, WhatsApp, payment gateway callbacks and OTA/channel-manager synchronization; use sandbox/test integrations only. Prevent search indexing.
5. Create a **separate staging vault path**, copy a test set of encrypted private backups and `vault.key` securely from the live vault after the new version has been tested and deployed. Never send the key in this chat.
6. Install the reviewed PMS plugin ZIP on **staging only**. Confirm `/wp-json/staycore/v1/health`, current booking counts and database revision schema.
7. Through WordPress **Tools → StayCore Booking Recovery**, force a fresh checkpoint, verify the vault status, modify a non-group *test copy* of a confirmed booking, then preview and restore an older version. Confirm that original payments remain intact and that `admin_booking_restored` is recorded. Test regular staff denial.
8. Tamper with one **staging copy** of an encrypted archive and confirm its matching recovery preview is rejected.
9. Inject failed database writes on disposable staging data and confirm both reservation and related assignments remain consistent.
10. Perform a final comparison of current bookings, assignment counts, payments and audit events. Pass only after a full reversible test.

**NEVER click Hostinger “Publish staging” for the PMS release.** On standard Hostinger staging, publishing replaces the production database and files and can delete bookings created after staging was cloned. Deploy a tested **plugin-only artifact** through a verified protected release mechanism instead.

Official docs:
- https://www.hostinger.com/support/5720286-how-to-create-a-wordpress-staging-environment-in-hostinger/
- https://www.hostinger.com/support/5981435-how-to-download-backups-at-hostinger/

## 3. Historical loss (October 1, 2026)

The former group migration deleted reservation rows **7, 8, 11**, retaining group masters **6 and 10**. The production audit log contains the merge events; it does not contain complete original deleted rows.

Hostinger states that eligible automated **weekly** backups are retained for **six weeks** (daily backups generally seven days). Search existing weekly backups dated **before October 1, 2026**. Download and preserve both the current DB and the older dated DB **without clicking Restore Database**.

Import the older SQL into a **second isolated historical MySQL database**, not the live database and not the staging WordPress operational database. Using restricted phpMyAdmin/SSH, compare only historical `staycore_reservations`, `staycore_reservation_units`, `staycore_guests` and `staycore_payments` with the live master reservations. Record fields/amounts lost in the merge; manually reconcile required corrections after authorization. A full old-database restore over production would erase subsequent bookings and payments.

## 4. Release gates

- Hostinger historical/current DB backups copied to an independently retained safe location.
- Staging clone confirmed on a distinct database, all email/payment/OTA side effects disabled.
- Encrypted private vault exists, secure key retention and off-host copy independently tested.
- Restoration preview, role checks, conflict checks and audit failure rollback verified on a non-production reservation.
- CI syntax, encrypted crypto archive, booking safety, and recovery contract tests green.
- Booking data safeguards PR #36 integrated, and package/deployment gating #32 addressed before rollout.
- Global concurrent inventory allocation issue #31 tracked separately; per-booking snapshotting alone does not prevent double-selling a room.

**Operating policy:** Back up before and after every booking save; never silently delete history; restoration is restricted to administrator+audit; never overwrite the live database with a staging database.
