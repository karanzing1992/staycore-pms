<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');
define('STAYCORE_PMS_VERSION', '0.8.2');

$GLOBALS['test_permissions'] = [];
$GLOBALS['test_hooks'] = [];
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function get_bloginfo(string $key): string { return '7.1.2'; }
function do_action(string $name, ...$args): void { $GLOBALS['test_hooks'][] = $name; }
function apply_filters(string $name, mixed $value): mixed { return $value; }

class StayCore_Access {
    public static function capabilities(): array {
        return ['read', 'staycore_view_pms', 'staycore_manage_payments', 'staycore_checkout'];
    }
    public static function can(string $cap): bool {
        return in_array($cap, $GLOBALS['test_permissions'], true);
    }
}
class WooCommerce {}

require_once __DIR__ . '/../includes/class-staycore-integrations.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}

StayCore_Integrations::boot();
StayCore_Integrations::boot();
$integrations = StayCore_Integrations::all();
check(isset($integrations['woocommerce']), 'WooCommerce plugin is detected');
check($integrations['woocommerce']['status'] === 'detected' &&
      $integrations['woocommerce']['connected'] === false,
      'Detection must not claim working WooCommerce payment sync');
check(count(array_filter($GLOBALS['test_hooks'], fn($name) => $name === 'staycore_pms_integrations_booted')) === 1, 'Boot event is idempotent');

$manifest = [
    'name' => 'Payments & POS',
    'version' => '1.0.0',
    'requires_core' => '0.8.2',
    'permissions' => ['staycore_manage_payments'],
    'capabilities' => ['pos', 'payment_capture'],
    'api_secret' => 'MUST_NOT_BE_EXPOSED',
];
check(StayCore_Integrations::register_extension('payments-pos', $manifest), 'Compatible extension registers');
$record = StayCore_Integrations::all()['payments-pos'];
check($record['status'] === 'registered' && $record['connected'] === false, 'Registered does not mean connected');
check(!isset($record['api_secret']), 'Undeclared manifest fields are not exposed');
check(!StayCore_Integrations::can('payments-pos', 'staycore_manage_payments'), 'Declared permission does not grant access');
$GLOBALS['test_permissions'] = ['staycore_manage_payments'];
check(StayCore_Integrations::can('payments-pos', 'staycore_manage_payments'), 'WordPress granted permission is required');
check(!StayCore_Integrations::can('payments-pos', 'staycore_checkout'), 'Undeclared extension privilege is denied');
check(!StayCore_Integrations::register_extension('payments-pos', $manifest), 'Duplicate extensions cannot replace registrations');

check(!StayCore_Integrations::register_extension('Bad Name', $manifest), 'Invalid extension slug rejected');
check(!StayCore_Integrations::register_extension('woocommerce', $manifest), 'WooCommerce core adapter is reserved');
check(!StayCore_Integrations::register_extension('elevated', array_merge($manifest, ['permissions' => ['manage_options']])), 'Unknown privilege rejected');
check(!StayCore_Integrations::register_extension('missing', ['name' => 'Missing', 'version' => '1.0.0']), 'Core requirement is mandatory');

$future = array_merge($manifest, ['requires_core' => '99.0.0']);
check(!StayCore_Integrations::register_extension('future', $future), 'Incompatible core requirement rejected');
check(StayCore_Integrations::all()['future']['status'] === 'incompatible', 'Incompatible extension visible with reason');
check(!StayCore_Integrations::can('future', 'staycore_manage_payments'), 'Incompatible extension permissions denied');

$php = array_merge($manifest, ['requires_php' => '99.0.0']);
check(!StayCore_Integrations::register_extension('future-php', $php), 'Incompatible PHP requirement rejected');
check(StayCore_Integrations::all()['future-php']['status'] === 'incompatible', 'PHP incompatibility visible');
$wordpress = array_merge($manifest, ['requires_wordpress' => '99.0.0']);
check(!StayCore_Integrations::register_extension('future-wp', $wordpress), 'Incompatible WordPress requirement rejected');
check(StayCore_Integrations::all()['future-wp']['status'] === 'incompatible', 'WordPress incompatibility visible');
