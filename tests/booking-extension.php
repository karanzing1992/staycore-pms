<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/../');
function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Kolkata'); }

class WP_Error {
    private string $code;
    public function __construct(string $code, string $message='', array $data=[]) { $this->code=$code; }
    public function get_error_code(): string { return $this->code; }
}

require_once __DIR__ . '/../includes/class-staycore-booking-extend.php';
function pass(bool $condition, string $description): void {
    if(!$condition){ fwrite(STDERR, 'FAIL: '.$description.PHP_EOL); exit(1); }
    echo 'PASS: '.$description.PHP_EOL;
}
function error_code($value): string { return $value instanceof WP_Error ? $value->get_error_code() : ''; }

$q=StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-13','650.50');
pass(is_array($q) && $q['nights']===2 && $q['additional_charge']===1301.00 && $q['new_checkout']==='2026-10-13 11:00:00','Two extra nights charged at agreed rate; checkout clock preserved');
$q=StayCore_Booking_Extend::quote('2028-02-28 11:00:00','2028-03-01','1100');
pass(is_array($q) && $q['nights']===2 && $q['additional_charge']===2200.00,'Leap-day extension counts calendar nights correctly');
$q=StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-12','0');
pass(is_array($q) && $q['additional_charge']===0.0,'Zero-rate quote is expressible; authorization is checked by REST');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-11','100'))==='not_extension','Same day cannot silently alter checkout');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-10','100'))==='not_extension','Earlier dates cannot be an extension');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-02-30','100'))==='bad_extension_date','Invalid dates are rejected');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2027-02-01','100'))==='extension_limit','Over 90 nights cannot be added in a single action');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-12','-100'))==='bad_extension_rate','Negative nightly rates rejected');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-12','100.123'))==='bad_extension_rate','Fractional paise rejected');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-12','1e3'))==='bad_extension_rate','Scientific notation rejected for charges');
pass(error_code(StayCore_Booking_Extend::quote('2026-10-11 11:00:00','2026-10-12','1000001'))==='bad_extension_rate','Implausibly high nightly rate rejected');
