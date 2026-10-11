# Andaz StayCore → Supabase Storage: private encrypted offsite backups

**Code status:** Draft v0.8.7 (stacked on encrypted on-save booking safeguards v0.8.6). GitHub validation uses a mocked Supabase HTTPS server; the **separate Supabase project and private bucket were created on 2026-10-11**, but no real encrypted cloud upload or staging restore was performed. Production remains on StayCore v0.8.1 until an approved release.

## Actual provisioned Supabase resources (verified 2026-10-11)

- Organization: `karanzing1992's Org` (ID `nustutyfswtkmljhnked`)
- Dedicated project: `andaz-pms-backup-vault`, ref **`zwymapyqltevaaxdqqeu`**, region **`ap-south-1`** (Mumbai), **ACTIVE_HEALTHY**, quoted project cost **$0/month** on Free.
- API URL (non-secret): **https://zwymapyqltevaaxdqqeu.supabase.co**
- Private Storage bucket: **`andaz-pms-private`**, `public=false`, file size limit **10,485,760 bytes**, MIME type `application/octet-stream`.
- Confirmed **zero objects** and **no storage.object or storage.bucket RLS policies** that would expose guest files to ordinary users. Security advisor: **zero findings** on initial review.
- **Not yet configured:** A server-only project secret key, secure recovery passphrase, Hostinger private filesystem path, staging WordPress, real encrypted upload/restore or production release.
- Open the dedicated project's keys page: https://supabase.com/dashboard/project/zwymapyqltevaaxdqqeu/settings/api-keys . Create a NEW secret key in the dashboard, do **not** copy the secret into ChatGPT/GitHub. Record it in the staging server's private `wp-config.php`, along with a separately stored 24+ character recovery passphrase. Verify the live Supabase REST transport before activating on production.


## Project isolation and pricing

**Do not use existing `constituency-ops-v1` for guest data.** On October 11, 2026 the user explicitly approved a new isolated Supabase project for Andaz; the cost-confirmation tool returned $0/month and project creation **succeeded**. The new backup project is separate from the election application.

The dedicated **`andaz-pms-backup-vault`** Supabase project has been created in `ap-south-1` (Mumbai). On the Free plan a project may be paused after around seven days of low database activity, and no service-level guaranteed availability is provided. This means the durable local upload queue is essential, with alerts, daily independent verification and optional paid upgrade if high reliability is required. Supabase has no equivalent to Cloudflare R2 bucket-lock retention in this design; API access with a project secret can delete objects. Do not promise tamper-proof/WORM retention.

Supabase official docs:
- https://supabase.com/docs/guides/storage/security/access-control
- https://supabase.com/docs/guides/storage/buckets/fundamentals
- https://supabase.com/docs/guides/storage/uploads/standard-uploads
- https://supabase.com/docs/guides/getting-started/api-keys
- https://supabase.com/docs/guides/platform/free-project-pausing

## One-time Storage setup

1. **Completed 2026-10-11:** Dedicated private Storage bucket **`andaz-pms-private`** created with a 10 MB binary-file limit; no public asset URLs or user-facing Storage policies. Do not later make the bucket public or grant `anon`/`authenticated` access.
2. **Project Settings → API Keys:** create a separate **secret key** for the WordPress server (format `sb_secret_...`). NEVER use a publishable/anon key to bypass policies and never display the secret in chat, GitHub, logs or browser JS. The secret key bypasses RLS for the ENTIRE dedicated project; it is not bucket scoped. The dedicated project is therefore a hard access boundary.
3. Do not grant Storage object DELETE or UPDATE policies to ordinary users. The WordPress connector itself only uses POST without upsert and GET to verify backups, and implements no remote delete, but a stolen project secret can still alter data. Rotate compromised keys immediately.
4. In `wp-config.php`, **outside GitHub**, configure the *actual* dedicated project and private local path:

```php
define('STAYCORE_PRIVATE_BACKUP_DIR', '/real/private/hostinger/path/staycore-vault');
define('STAYCORE_SUPABASE_ENABLED', true);
define('STAYCORE_SUPABASE_PROJECT_REF', 'zwymapyqltevaaxdqqeu');
define('STAYCORE_SUPABASE_BUCKET', 'andaz-pms-private');
define('STAYCORE_SUPABASE_SECRET_KEY', 'sb_secret_REPLACE_SECURELY');
define('STAYCORE_SUPABASE_RECOVERY_PASSPHRASE', 'YOUR_24_PLUS_CHARACTER_RECOVERY_PASSPHRASE');
define('STAYCORE_SUPABASE_PREFIX', 'andaz/staycore/staging'); // staging first!
```

The project ref, bucket, secret and recovery passphrase values above are PLACEHOLDERS, not actual credentials. Production uses `andaz/staycore/production`, but only after validation. Do not reuse the staging WP database as live or expose backup files in `public_html`. Do not reuse a production vault key in a test vault. Recovery passphrase must be generated independently and retained in a separate secure password manager or offline kit, **outside Hostinger and Supabase**.

## Automatic archive workflow

- Every protected booking mutation writes a local SQL booking revision AND a verified AES-256-GCM file with a SHA256 checksum. A failed local backup prevents that protected booking write.
- Within about one minute of saving, or at the 5-minute cron interval, the Supabase queue retries up to 25 unsynced encrypted archives. Network outages do **not** block locally protected bookings.
- The worker first creates/validates a *passphrase-wrapped* 32-byte local AES key (PBKDF2-SHA256 + AES-256-GCM). It uploads the key envelope to the *private* Storage bucket under `v1/recovery/...`; it never sends the unwrapped AES key.
- Each booking archive is re-authenticated locally, uploaded via `POST /storage/v1/object/<bucket>/<object>` with `x-upsert: false`, and **downloaded via authenticated private Storage GET** for byte-for-byte SHA256 comparison. An existing remote object must match exactly or backup sync fails.
- Successful transfers record a local `.supabase-synced` receipt; original encrypted files are never deleted automatically. Failed transfers remain on disk and retry. Cron checks one older remote object each day and alerts administrators on failures or backlog beyond 30 minutes.
- Only the WordPress PHP server holds the Supabase secret. No guest names/payments are inserted in Supabase database tables; private bucket object names contain only reservation IDs and random identifiers. Decrypting a cloud archive requires the wrapped key, externally held passphrase and authorization.

### Monitor

In WordPress **Tools → StayCore Booking Recovery**, administrators see: cloud enabled/configured status, pending encrypted files, oldest queued minutes and last remote-verified time. Verify a real server-level 5-minute cron; WP-Cron can stall without traffic. Verify administrator emails are actually delivered.

## First staging acceptance test

1. Create Hostinger staging on **a distinct MySQL database**, protected by password and search-indexing disabled. Disable transactional emails, WhatsApp sends, OTAs, payment webhooks and all outgoing third-party calls except test Supabase Storage.
2. Install reviewed plugin v0.8.7 on staging only, use its own private local vault path, staging cloud prefix and a test booking; check **Tools → StayCore Booking Recovery** vault-ready / offsite-configured.
3. Save/change a *disposable* booking; see an AES-GCM local archive and a private Storage object; verify actual cloud download and SHA256 matches original.
4. Simulate offline mode in disposable staging, save another booking, observe pending offsite queue. Restore network and verify backlog reaches zero.
5. In a separate offline/staging folder, download the **wrapped vault key** and encrypted booking file from Supabase Storage. Unwrap the key with the independently retained passphrase, never by copying a production key into chat. Verify AES-GCM authentication and reservation ID; confirm wrong passphrase/corrupted ciphertext fails closed.
6. Test WordPress **Administrator-only** two-step preview & restore, reason+typed confirmation+audited rollback; ensure staff cannot restore, and no payments are lost. Group/closed/deleted booking recovery is manual and must use isolated staging.
7. Confirm the remote backup has not granted public access (an unauthenticated download must return 401/403). Confirm nothing in `storage.objects` is publicly readable under RLS; check Supabase security advisor.
8. Backup the current WordPress DB separately and preserve the old pre-October 1 Hostinger weekly snapshot before any deployment; do not restore old SQL over the current live database.

**IMPORTANT:** Do NOT click Hostinger "Publish staging": that can replace production database tables and erase bookings created since staging cloning. Deploy plugin-only reviewed files after release gating; never merge/deploy the draft PR before the vault is provisioned and a real restore drill succeeds.

## Recovery boundaries and limitations

- Server-offline/local key loss scenario is recoverable only if the wrapped vault-key envelope, every encrypted archive and independent passphrase survive. A disconnected/offline copy of the recovery passphrase remains mandatory.
- Automated WordPress administrator restore supports constrained fields of *existing* non-group, active reservations, after diff/conflict/payment checks and complete audit. It deliberately does not auto-recreate destroyed reservation IDs, overwrite payments, change guest identity or restore closed/group bookings.
- Historic booking IDs **7, 8, 11** predate the safeguard; their full source rows can only be recovered from independent historical SQL exports/OTA sources.
- Supabase Storage **is not a database snapshot or WORM storage**. Keep Hostinger database backups and optionally another independent provider for regulatory-grade tamper-resistant retention.
- Even with a five-minute queue, an abrupt server loss can destroy the most recent not-yet-synced encrypted files. A strict zero-data-loss RPO would require synchronous remote durability or a second write-ahead log, plus fail-closed booking transactions during cloud outages.
