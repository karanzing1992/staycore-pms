<?php
if (!defined('ABSPATH')) exit;

/**
 * StayCore extension discovery / capability contract.
 *
 * This registry is metadata only. WordPress remains responsible for installing,
 * activating and updating separate extension plugins. Registration never grants
 * a permission or executes extension-supplied routes/callbacks.
 */
final class StayCore_Integrations {
    private static array $adapters = [];
    private static bool $booted = false;

    public static function boot(): void {
        if (self::$booted) return;
        self::$booted = true;
        do_action('staycore_pms_before_integrations_boot');
        if (class_exists('WooCommerce')) {
            self::register('woocommerce', [
                'label' => 'WooCommerce',
                'type' => 'detected_plugin',
                'status' => 'detected',
                'connected' => false,
                'message' => 'WooCommerce detected. No StayCore order or payment synchronization is configured by the core plugin.',
                'capabilities' => ['orders', 'payments', 'customers', 'products'],
            ]);
        }
        do_action('staycore_pms_integrations_booted', self::all());
    }

    /**
     * Backward-compatible integration adapter registration.
     * Do not permit an extension to silently replace an existing adapter.
     */
    public static function register(string $slug, array $adapter): void {
        if ($slug === '' || $slug !== sanitize_key($slug) || isset(self::$adapters[$slug])) return;
        self::$adapters[$slug] = $adapter;
        do_action('staycore_pms_adapter_registered', $slug, $adapter);
    }

    /**
     * Register a separately installed WordPress extension.
     *
     * Supported manifest keys:
     * name, version, requires_core, requires_php?, requires_wordpress?,
     * permissions? (declared StayCore capability names), capabilities?
     * (informational feature slugs).
     *
     * Returns false when invalid, incompatible or already registered. An
     * incompatible extension still appears in the registry with a reason.
     */
    public static function register_extension(string $slug, array $manifest): bool {
        if ($slug === '' || $slug !== sanitize_key($slug) ||
            !preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $slug) ||
            $slug === 'woocommerce' || isset(self::$adapters[$slug])) return false;

        $name = $manifest['name'] ?? null;
        $version = $manifest['version'] ?? null;
        $requires_core = $manifest['requires_core'] ?? null;
        if (!is_string($name) || trim($name) === '' || !is_string($version) ||
            !is_string($requires_core) || !self::valid_version($version) ||
            !self::valid_version($requires_core)) return false;

        $permissions = $manifest['permissions'] ?? [];
        $features = $manifest['capabilities'] ?? [];
        if (!is_array($permissions) || !is_array($features)) return false;
        $allowed = StayCore_Access::capabilities();
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !in_array($permission, $allowed, true)) return false;
        }
        foreach ($features as $feature) {
            if (!is_string($feature) || $feature === '' || $feature !== sanitize_key($feature)) return false;
        }
        foreach (['requires_php','requires_wordpress'] as $requirement) {
            if (isset($manifest[$requirement]) &&
                (!is_string($manifest[$requirement]) || !self::valid_version($manifest[$requirement]))) return false;
        }

        $reason = '';
        if (version_compare(STAYCORE_PMS_VERSION, $requires_core, '<')) {
            $reason = 'Requires StayCore '. $requires_core .' or newer.';
        } elseif (isset($manifest['requires_php']) && version_compare(PHP_VERSION, $manifest['requires_php'], '<')) {
            $reason = 'Requires PHP '. $manifest['requires_php'] .' or newer.';
        } elseif (isset($manifest['requires_wordpress']) &&
                  version_compare(get_bloginfo('version'), $manifest['requires_wordpress'], '<')) {
            $reason = 'Requires WordPress '. $manifest['requires_wordpress'] .' or newer.';
        }

        $record = [
            'label' => sanitize_text_field($name),
            'type' => 'extension',
            'version' => $version,
            'requires_core' => $requires_core,
            'status' => $reason === '' ? 'registered' : 'incompatible',
            'message' => $reason,
            'capabilities' => array_values(array_unique($features)),
            'permissions' => array_values(array_unique($permissions)),
            // "registered" means the plugin declares support, NOT that its
            // external service is authorized or syncing successfully.
            'connected' => false,
        ];
        self::register($slug, $record);
        do_action('staycore_pms_extension_registered', $slug, $record);
        return $reason === '';
    }

    private static function valid_version(string $version): bool {
        return (bool) preg_match('/^\\d+(?:\\.\\d+){1,3}(?:[-+][a-zA-Z0-9._-]+)?$/D', $version);
    }

    /**
     * Extension routes should call this in their permission_callback. Merely
     * listing a capability does not grant it to a WordPress user.
     */
    public static function can(string $slug, string $permission): bool {
        $adapter = self::$adapters[$slug] ?? null;
        return is_array($adapter) && ($adapter['type'] ?? '') === 'extension' &&
            ($adapter['status'] ?? '') === 'registered' &&
            in_array($permission, $adapter['permissions'] ?? [], true) &&
            StayCore_Access::can($permission);
    }

    public static function all(): array {
        return apply_filters('staycore_pms_integrations', self::$adapters);
    }

    public static function emit(string $event, array $payload): void {
        $event = sanitize_key($event);
        if ($event === '') return;
        do_action('staycore_pms_event', $event, $payload);
        do_action('staycore_pms_event_' . $event, $payload);
    }
}
