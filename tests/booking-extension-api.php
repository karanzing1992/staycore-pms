<?php
declare(strict_types=1);

define('ABSPATH', __DIR__.'/../');
define('ARRAY_A', 'ARRAY_A');
function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Kolkata'); }
function absint($value): int { return abs((int)$value); }
function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
function sanitize_textarea_field($value): string { return trim(strip_tags((string)$value)); }
function current_time(string $format): string { return $format==='mysql'?'2026-10-11 09:00:00':'2026-10-11'; }
function current_datetime(): DateTimeImmutable { return new DateTimeImmutable('2026-10-11 09:00:00',wp_timezone()); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function rest_ensure_response($value) { return $value; }
function apply_filters(string $name, $value, ...$args) { return $value; }

class WP_Error {
    private string $code;
    public function __construct(string $code, string $message='', array $data=[]) { $this->code=$code; }
    public function get_error_code(): string { return $this->code; }
}
class WP_REST_Request implements ArrayAccess {
    private array $params;
    public function __construct(array $params) { $this->params=$params; }
    public function offsetExists($offset): bool { return $offset==='id'; }
    public function offsetGet($offset) { return $offset==='id' ? 12 : null; }
    public function offsetSet($offset, $value): void {}
    public function offsetUnset($offset): void {}
    public function get_json_params(): array { return $this->params; }
}
class StayCore_Access {
    public static bool $manager=false;
    public static function can(string $cap): bool {
        return $cap==='manage_staycore_pms' && self::$manager;
    }
}
class StayCore_DB {
    public static array $events=[];
    public static function tables(): array { return [
        'reservations'=>'wp_staycore_reservations',
        'reservation_units'=>'wp_staycore_reservation_units',
        'units'=>'wp_staycore_units',
        'payments'=>'wp_staycore_payments',
    ]; }
    public static function log(string $action,string $type,?int $id,string $message,array $meta=[]): void {
        self::$events[]=['action'=>$action,'meta'=>$meta];
    }
}
class StayCore_Integrations {
    public static array $events=[];
    public static function emit(string $event,array $payload): void { self::$events[]=['event'=>$event,'payload'=>$payload]; }
}
class FakeBookingDB {
    public array $reservation=[];
    public bool $overlap=false;
    public bool $update_conflict=false;
    public string $unit_status='available';
    public int $updates=0;
    public float $collected=300.0;
    public function __construct() { $this->reservation=[
        'id'=>12,'status'=>'checked_in','check_in'=>'2026-10-08 13:00:00',
        'check_out'=>'2026-10-11 11:00:00','total'=>'1000.00'
    ]; }
    public function prepare(string $query,...$args): string { return $query; }
    public function get_row(string $query, $mode=null): ?array {
        if(str_contains($query,'SELECT * FROM wp_staycore_reservations')) return $this->reservation;
        if(str_contains($query,'SELECT name,status FROM wp_staycore_units'))
            return ['name'=>'Dorm A1','status'=>$this->unit_status];
        return null;
    }
    public function get_col(string $query): array { return [1]; }
    public function get_var(string $query) {
        if(str_contains($query,'SELECT r.id FROM wp_staycore_reservations')) return $this->overlap?77:0;
        if(str_contains($query,'SUM(amount)')) return $this->collected;
        if(str_contains($query,'SELECT total FROM wp_staycore_reservations')) return $this->reservation['total'];
        return 0;
    }
    public function update(string $table,array $fields,array $where): int {
        if($this->update_conflict)return 0;
        if($where['check_out']!==$this->reservation['check_out'] || $where['total']!==$this->reservation['total'])return 0;
        $this->updates++;
        $this->reservation=array_merge($this->reservation,$fields);
        return 1;
    }
}

require_once __DIR__.'/../includes/class-staycore-booking-extend.php';
require_once __DIR__.'/../includes/class-staycore-rest.php';
function assert_case(bool $valid,string $name): void {
    if(!$valid){ fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1); }
    echo 'PASS: '.$name.PHP_EOL;
}
function request(array $changes=[]): WP_REST_Request {
    return new WP_REST_Request(array_merge([
        'expected_check_out'=>'2026-10-11 11:00:00',
        'expected_total'=>'1000.00',
        'new_checkout_date'=>'2026-10-13',
        'nightly_rate'=>'650',
        'reason'=>'Guest requested more nights'
    ],$changes));
}
function reset_db(): FakeBookingDB {
    global $wpdb;
    $wpdb=new FakeBookingDB();
    StayCore_DB::$events=[];
    StayCore_Integrations::$events=[];
    StayCore_Access::$manager=false;
    return $wpdb;
}
$db=reset_db();$db->reservation['status']='checked_out';
$r=StayCore_REST::extend_reservation(request());
assert_case($r instanceof WP_Error&&$r->get_error_code()==='booking_closed'&&$db->updates===0,'Closed booking cannot be extended');
$db=reset_db();$r=StayCore_REST::extend_reservation(request(['expected_total'=>'999']));
assert_case($r instanceof WP_Error&&$r->get_error_code()==='stale_booking'&&$db->updates===0,'Changed total stops duplicate extension');
$db=reset_db();$db->reservation['check_out']='2026-10-08 11:00:00';
$r=StayCore_REST::extend_reservation(request(['expected_check_out'=>'2026-10-08 11:00:00','new_checkout_date'=>'2026-10-10']));
assert_case($r instanceof WP_Error&&$r->get_error_code()==='extension_not_future'&&$db->updates===0,'Cannot extend to a checkout that is already past');
$db=reset_db();$db->overlap=true;$r=StayCore_REST::extend_reservation(request());
assert_case($r instanceof WP_Error&&$r->get_error_code()==='extension_unavailable'&&$db->updates===0,'Conflicting bed blocks extension');
$db=reset_db();$db->unit_status='maintenance';$r=StayCore_REST::extend_reservation(request());
assert_case($r instanceof WP_Error&&$r->get_error_code()==='unit_unavailable'&&$db->updates===0,'Out of service bed blocks extension');
$db=reset_db();$r=StayCore_REST::extend_reservation(request(['nightly_rate'=>'0']));
assert_case($r instanceof WP_Error&&$r->get_error_code()==='complimentary_forbidden','Zero rate requires manager');
$db=reset_db();StayCore_Access::$manager=true;$r=StayCore_REST::extend_reservation(request(['nightly_rate'=>'0','reason'=>'']));
assert_case($r instanceof WP_Error&&$r->get_error_code()==='extension_reason_required','Complimentary extension must be documented');
$db=reset_db();$db->update_conflict=true;$r=StayCore_REST::extend_reservation(request());
assert_case($r instanceof WP_Error&&$r->get_error_code()==='stale_booking'&&count(StayCore_DB::$events)===0,'Concurrent edit failure does not issue extension receipt');
$db=reset_db();$r=StayCore_REST::extend_reservation(request());
assert_case(is_array($r)&&$r['check_out']==='2026-10-13 11:00:00'&&$r['total']===2300.0&&$r['payment']['balance']===2000.0,'Extension saves revised date, total and balance');
assert_case($db->updates===1&&count(StayCore_DB::$events)===1&&StayCore_DB::$events[0]['action']==='booking_extended','Single audited write');
assert_case(count(StayCore_Integrations::$events)===1&&StayCore_Integrations::$events[0]['event']==='reservation_extended','Extension event emitted');
$duplicate=StayCore_REST::extend_reservation(request());
assert_case($duplicate instanceof WP_Error&&$duplicate->get_error_code()==='stale_booking'&&$db->updates===1,'Retry cannot charge again');
