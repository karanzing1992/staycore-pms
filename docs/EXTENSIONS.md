# StayCore Extension Contract v1 (developer preview)

**Source of truth:** [StayCore PMS](https://github.com/karanzing1992/staycore-pms). The old copy in `andaz-vibe-stay/wordpress/staycore-pms` is obsolete.

## What this contract does

A separate, *already activated* WordPress plugin can declare its name, version, minimum core/PHP/WordPress versions, feature names and requested StayCore capabilities. The core records a compatibility status without granting any permission. Use `StayCore_Integrations::can()` in every protected extension endpoint.

This is a **discovery and capability contract**, not an installer, licence server, extension marketplace, webhook provider, background sync engine or updater. WordPress handles activation/deactivation; extension plugins own their own schema, callbacks, settings, routes and migrations. Registrations are not proof of external service authorization.

## Register an extension

Call from your independent WordPress plugin:

```php
add_action('staycore_pms_before_integrations_boot', static function () {
    if (!class_exists('StayCore_Integrations')) return;
    StayCore_Integrations::register_extension('payments-pos', [
        'name' => 'Payments & POS',
        'version' => '1.0.0',
        'requires_core' => '0.8.2',
        'requires_php' => '8.0.0',
        'requires_wordpress' => '6.4.0',
        'permissions' => ['staycore_manage_payments'],
        'capabilities' => ['pos', 'payments'],
    ]);
});
```

An extension should register its own WordPress REST routes on `rest_api_init`:

```php
register_rest_route('staycore-payments/v1', '/capture', [
    'methods' => 'POST',
    'callback' => 'my_extension_payment_handler',
    'permission_callback' => static fn() =>
        StayCore_Integrations::can('payments-pos', 'staycore_manage_payments'),
]);
```

You must separately validate request parameters, WordPress nonces/authentication, idempotency, payment provider signatures, and transaction state. This capability check does not provide those validations automatically.

## States

- `registered`: extension manifest is compatible. No sync/connection is claimed.
- `incompatible`: requires a newer core, PHP or WordPress version. The core denies its declared capability checks.
- `detected`: third-party plugin (currently WooCommerce) is present; core makes no sync claims.
- `connected`: reserved for a future verified adapter health protocol. Never infer from plugin detection.

The management integrations endpoint is `/wp-json/staycore/v1/integrations` and requires `staycore_manage_settings`. It reports only validated, documented manifest fields for extensions; do not put secrets in manifest metadata. Extension visibility does not imply all functional operations have passed testing.

## Hooks

- `staycore_pms_before_integrations_boot`: register extensions.
- `staycore_pms_extension_registered`: metadata accepted (including incompatible records; check status).
- `staycore_pms_adapter_registered`: generic adapter record stored.
- `staycore_pms_integrations_booted`: final registry snapshot available.
- `staycore_pms_event` and `staycore_pms_event_{sanitized_event}`: published PMS events.

## Deployment and testing policy

1. Develop extension as its own Git repository and standalone WordPress plugin, with separate versioning and CI.
2. Target the **tested StayCore version**, verify all declared compatibility requirements, and test in staging.
3. Core `main` currently has a direct Git deployer path. **That deployment trigger is not conditional on CI success.** Treat a push to `main` as a possible production publish until Deployer for Git is moved to a protected release branch.
4. GitHub Actions packages an exact validated core commit as a zip artifact; packaging does not deploy to WordPress. Inspect artefact and confirm tested SHA, then deploy on staging, verify health and data migrations, then promote to production with backup and rollback.
5. Verify live `/staycore/v1/health` and `staycore_pms_db_version`; these expose versions but not the exact source commit. Commit-level attestation is future work.

## Known limitations

There is no centralized "Install / Disable / Update Available / Configure" UI yet, and no extension licensing. WooCommerce's presence only produces `detected` and does **not** mean reservation or payment synchronization exists. Concurrent booking allocation and idempotent payment webhook handling remain separate hardening tasks.
