<?php
if (!defined('ABSPATH')) exit;

final class StayCore_Integrations {
    private static array $adapters = [];

    public static function boot(): void {
        do_action('staycore_pms_before_integrations_boot');
        if (class_exists('WooCommerce')) {
            self::register('woocommerce', [
                'label' => 'WooCommerce',
                'status' => 'available',
                'capabilities' => ['orders', 'payments', 'customers', 'products'],
            ]);
        }
        do_action('staycore_pms_integrations_booted', self::$adapters);
    }

    public static function register(string $slug, array $adapter): void {
        self::$adapters[$slug] = $adapter;
        do_action('staycore_pms_adapter_registered', $slug, $adapter);
    }

    public static function all(): array {
        return apply_filters('staycore_pms_integrations', self::$adapters);
    }

    public static function emit(string $event, array $payload): void {
        do_action('staycore_pms_event', $event, $payload);
        do_action('staycore_pms_event_' . sanitize_key($event), $payload);
    }
}
