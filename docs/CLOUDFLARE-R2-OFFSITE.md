# StayCore → Cloudflare R2 offsite booking backups (v0.8.7)

**Status: draft implementation; no Cloudflare account, live R2 bucket, secrets, or production WordPress configuration has been created.** This guide is for completing activation securely after staging QA.

## Why Cloudflare R2 Standard

- Cloudflare R2 Standard: free allowance of 10 GB-month, 1 million Class A operations and 10 million Class B operations per month. No outbound data-transfer fees. R2 may require adding a payment method; overage can be billed.
- Supabase Free Storage: 1 GB and free projects can pause on low activity. Avoid relying on a pausable Free Supabase project for critical booking disaster recovery.
- R2 supports private buckets, S3-compatible HTTPS API, and **bucket locks** to prevent overwrites and deletion for defined retention periods.

Official docs:
- https://developers.cloudflare.com/r2/pricing/
- https://developers.cloudflare.com/r2/get-started/
- https://developers.cloudflare.com/r2/api/tokens/
- https://developers.cloudflare.com/r2/buckets/bucket-locks/

## 1. Cloudflare setup (dashboard — NOT in GitHub)

1. Open https://dash.cloudflare.com/ → **R2 Object Storage** → Overview. Enable R2 (may require billing details).
2. Create a new **private** bucket named `andaz-pms-private`, storage class **Standard**. Do not enable public bucket URLs or custom public domains.
3. In bucket **Settings → Bucket lock rules**, create:
   - Prefix `v1/` with **90-day** minimum retention (or longer).
   - More restrictive prefix `v1/recovery/` with **365-day** minimum retention for encrypted recovery-key escrow.
   These settings are made through the dashboard and are **not automated by the WordPress plugin**. Do not enable early-expiry lifecycle deletion for backups.
4. Create an R2 **Account API token**, **Object Read & Write** scope for **this bucket only**, *not* Admin Read & Write. It supplies an **Access Key ID** and **Secret Access Key** used for S3 SigV4 authentication. Record both in an encrypted secrets manager; Cloudflare does not show the secret again.
5. Record the Cloudflare **Account ID** (32 hexadecimal characters).
6. Write a **recovery passphrase of 24+ random characters**, distinct from every login password. Place it in a secure password manager or written offline recovery kit **outside Hostinger**. Never send it through chat or commit it to Git.

### WordPress configuration — staging first

In the **staging** `wp-config.php`, outside the Git-tracked PMS plugin source:

```php
define('STAYCORE_PRIVATE_BACKUP_DIR', '/YOUR/HOSTINGER/PRIVATE/staycore-vault-stage');
define('STAYCORE_R2_ENABLED', true);
define('STAYCORE_R2_ACCOUNT_ID', 'REPLACE_WITH_32_HEX_ACCOUNT_ID');
define('STAYCORE_R2_BUCKET', 'andaz-pms-private');
define('STAYCORE_R2_ACCESS_KEY_ID', 'REPLACE_WITH_SCOPED_ACCESS_KEY');
define('STAYCORE_R2_SECRET_ACCESS_KEY', 'REPLACE_WITH_SCOPED_SECRET');
define('STAYCORE_R2_RECOVERY_PASSPHRASE', 'REPLACE_WITH_24_PLUS_CHARACTER_OFFLINE_STORED_PASSPHRASE');
define('STAYCORE_R2_PREFIX', 'andaz/staycore/staging');
define('STAYCORE_R2_JURISDICTION', 'default'); // Or eu/us only if bucket created in that jurisdiction
define('STAYCORE_RECOVERY_ENABLED', true); // staging only, never as a generic production default
```

Use the account's **real absolute private path**, not the placeholder. Ensure it is **outside** any `public_html`, `www`, `htdocs` or `httpdocs` directory. All vault files/key should be owned by the PHP hosting user with restrictive permissions. Do not mix staging and production prefixes.

On production, use a **different private vault directory**, `STAYCORE_R2_PREFIX='andaz/staycore/production'` and **do not enable restoration writes** until the staging rehearsal passes.

### What happens on a booking save

1. Save the previous booking version as a private SQL revision and an **AES-256-GCM** encrypted on-disk archive. Reservation creation and ordinary edit also back up the newly saved state.
2. The local save/booking transaction completes without a network dependency.
3. Every **five minutes** (WordPress Cron), a bounded queue of up to 25 previously unsynced encrypted archives is uploaded to R2 through HTTPS S3 Signature V4. Automatic and retry sync is best-effort; do not describe a booking as offsite-protected until it has been remotely verified.
4. Before uploading a booking, the worker encrypts the original local **32-byte vault key** with the externally retained passphrase (PBKDF2-SHA256 + AES-256-GCM) and verifies the encrypted escrow object in R2. No unencrypted vault key is uploaded.
5. Every encrypted booking object is written under `v1/<configured-prefix>/bookings/<random-name>.scbackup`. Remote HEAD and GET checks ensure the stored bytes match the source SHA-256; successful uploads receive a local `.synced` marker. Existing objects are not overwritten.
6. Network errors leave encrypted files in the durable local queue. Admin alerts fire if backlog exceeds **30 minutes**. One previously stored cloud object is independently rechecked daily. No R2 delete request is implemented.

**Important availability trade-off:** WordPress booking writes remain possible during an R2 outage as long as local private snapshot and local SQL revision succeed. The admin must investigate unsynced archives promptly; the R2 copy can lag when internet/WP-Cron fails.

## 2. Verification & staging restoration

- In staging WordPress: **Tools → StayCore Booking Recovery**. Verify private vault READY, R2 Configured, 0 pending encrypted files after sync, latest verified time. In staging only, create a fake/test booking. Edit it and check that the SQL revision and encrypted local file both exist.
- From R2 dashboard, confirm objects exist under the **staging** prefix and an encrypted recovery-key escrow exists under `v1/recovery/`. Never publish bucket.
- Create a deliberately failed network-response test on disposable staging, confirm the booking still saves locally and the archive remains pending, then restore connectivity and see verified offsite status.
- Copy encrypted archive files to a **second disconnected/staging vault** and import the encrypted escrow. Use the independently held passphrase to decrypt the key into the private staging directory with mode 0600. Never overwrite a nonempty production vault key. Test that the restored file's AES-GCM authentication and SQL SHA-256 match.
- The **WordPress admin-only** restore UI can preview and apply safe *existing booking* revisions after permission, nonce, diff, snapshot, conflicts, audit and explicit approval. This does **not** automatically recreate deleted historic reservation IDs or all group/guest/payment records from R2 archives; those must be reconciled in isolated staging and then carefully reentered or merged under an audit.
- A complete independent rebuild from *only* R2 files is a separate disaster-recovery scenario, and must be rehearsed before claiming full disaster recovery.
- Stage WordPress must have a **distinct database**. Never use Hostinger **Publish staging** to replace the production database; deploy plugin files only after testing.

## 3. Operational checklist

- Use a **managed hosting cron** to invoke WordPress Cron about every five minutes. Default WP-Cron may stall on a low-traffic website.
- Monitor the offsite queue and failures daily. Alerts go to the WordPress administrator email address only if WordPress mail works; verify notification delivery before launch.
- Preserve both the user's Cloudflare login recovery method and the independent vault-key passphrase. Without the passphrase, losing the Hostinger server makes encrypted R2 files undecryptable.
- Do not store booking objects under public R2 domains, expose account/secret keys in browser JS, or copy credentials into GitHub.
- Keep Cloudflare R2 **bucket lock** enforced on `v1/`, and confirm its retention from the Cloudflare dashboard. It mitigates malicious overwrites or deletion by a compromised WordPress token.
- Daily private checkpoints remain useful but are NOT an all-site MySQL backup. Keep full independently stored Hostinger DB/media backups, including the old pre-October-1 backup, until the historical missing reservation IDs are reconciled.
- Before deployment, confirm all required PMS branches are rebased/merged, check release/backup gates in issues #32 and #35, run CI and real staging restore checks, then deploy approved plugin code without replacing live DB.

### Debugging

- **R2 not enabled:** check `STAYCORE_R2_ENABLED` flag, credentials, and 24+ character recovery passphrase in wp-config.php; never paste these values into logs/support messages.
- **Vault not ready:** use a private directory not under a public webroot, writable by PHP.
- **Pending > 0 after >30 minutes:** verify WordPress cron, Cloudflare account billing, private bucket and scoped S3 API token, key escrow, and no unintended bucket lock errors.
- **Wrong region / 403:** R2 uses SigV4 region `auto`, and a jurisdictional endpoint must match the bucket jurisdiction.
- **403 attempting to overwrite existing object:** intentional fail-safe. Never disable bucket lock to fix a backup collision. Investigate mismatched object checksums.
