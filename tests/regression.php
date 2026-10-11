<?php
declare(strict_types=1);

/**
 * Lightweight regression tests that exercise the real StayCore REST methods
 * without requiring a running WordPress site or a test database.
 */
define('ABSPATH', __DIR__ . '/../');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['staycore_test_now'] = new DateTimeImmutable('2026-10-02 10:59:59', new DateTimeZone('Asia/Kolkata'));

function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Kolkata'); }
function current_datetime(): DateTimeImmutable { return $GLOBALS['staycore_test_now']; }
function absint($v): int { return abs((int) $v); }

class WP_Error {
    private string $code;
    public function __construct(string $code, string $message = '', array $data = []) { $this->code = $code; }
    public function get_error_code(): string { return $this->code; }
}

class WP_REST_Request implements ArrayAccess {
    private array $route;
    private array $body;
    public function __construct(array $route = [], array $body = []) { $this->route = $route; $this->body = $body; }
    public function offsetExists(mixed $offset): bool { return isset($this->route[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->route[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new RuntimeException('Read only'); }
    public function offsetUnset(mixed $offset): void { throw new RuntimeException('Read only'); }
    public function get_json_params(): array { return $this->body; }
}

class StayCore_DB {
    public static function tables(): array {
        return [
            'reservations' => 'wp_staycore_reservations',
            'reservation_units' => 'wp_staycore_reservation_units',
            'reservation_guests' => 'wp_staycore_reservation_guests',
            'units' => 'wp_staycore_units',
        ];
    }
}

class FakeWPDB {
    public string $reservation_status = 'confirmed';
    public string $target_status = 'available';
    public int $assigned_to_target = 0;
    public int $guest_count = 1;

    public function prepare(string $sql, ...$params): string { return vsprintf($sql, $params); }
    public function get_row(string $query, $mode = null): ?array {
        if (str_contains($query, 'FROM wp_staycore_reservations')) {
            return ['id' => 12, 'status' => $this->reservation_status, 'unit_id' => 1,
                    'check_in' => '2026-10-02 13:00:00', 'check_out' => '2026-10-03 11:00:00'];
        }
        if (str_contains($query, 'FROM wp_staycore_units')) {
            return ['id' => 2, 'name' => 'Test bed', 'status' => $this->target_status,
                    'capacity' => 1, 'housekeeping_status' => 'clean'];
        }
        return null;
    }
    public function get_var(string $query): int {
        if (str_contains($query, 'SELECT guests')) return $this->guest_count;
        if (str_contains($query, 'unit_id=2')) return $this->assigned_to_target;
        if (str_contains($query, 'unit_id=1')) return 1;
        return 0;
    }
}

require_once __DIR__ . '/../includes/class-staycore-rest.php';

function check(bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}

$expires = new ReflectionMethod(StayCore_REST::class, 'public_link_expired');
$checkout = ['check_out' => '2026-10-01 11:00:00'];

check($expires->invoke(null, $checkout, 'checkin') === false, 'Check-in link valid until 24h after checkout in Goa');
$GLOBALS['staycore_test_now'] = new DateTimeImmutable('2026-10-02 11:00:01', wp_timezone());
check($expires->invoke(null, $checkout, 'checkin') === true, 'Check-in link expires after local deadline');
$GLOBALS['staycore_test_now'] = new DateTimeImmutable('2026-10-31 10:59:59', wp_timezone());
check($expires->invoke(null, $checkout, 'feedback') === false, 'Feedback link valid within 30 days');
$GLOBALS['staycore_test_now'] = new DateTimeImmutable('2026-10-31 11:00:01', wp_timezone());
check($expires->invoke(null, $checkout, 'feedback') === true, 'Feedback link expires after 30 local days');
check($expires->invoke(null, ['check_out' => 'invalid'], 'feedback') === true, 'Invalid checkout date is treated as expired');

global $wpdb;
$wpdb = new FakeWPDB();
$move = new WP_REST_Request(['id' => 12], ['from_unit_id' => 1, 'to_unit_id' => 2]);

$wpdb->reservation_status = 'checked_out';
$result = StayCore_REST::move_unit($move);
check($result instanceof WP_Error && $result->get_error_code() === 'booking_closed', 'Closed reservations cannot be moved');

$wpdb->reservation_status = 'confirmed';
$wpdb->target_status = 'out_of_service';
$result = StayCore_REST::move_unit($move);
check($result instanceof WP_Error && $result->get_error_code() === 'unit_unavailable', 'Out-of-service unit cannot be assigned');

$wpdb->target_status = 'available';
$wpdb->assigned_to_target = 1;
$result = StayCore_REST::move_unit($move);
check($result instanceof WP_Error && $result->get_error_code() === 'unit_already_assigned', 'Cannot duplicate unit assignment');

$wpdb->assigned_to_target = 0;
$wpdb->guest_count = 2;
$result = StayCore_REST::move_unit($move);
check($result instanceof WP_Error && $result->get_error_code() === 'capacity_exceeded', 'Move rejects insufficient unit capacity');
