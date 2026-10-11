<?php
declare(strict_types=1);

define('ABSPATH', __DIR__.'/../');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['options']=[];
$GLOBALS['mail_alerts']=[];
function get_current_user_id(): int { return 19; }
function current_time(string $format): string { return $format==='mysql' ? '2026-10-11 10:30:00' : '2026-10-11'; }
function wp_json_encode($value) { return json_encode($value); }
function get_option(string $key,$fallback=null) { return $GLOBALS['options'][$key]??$fallback; }
function update_option(string $key,$value, bool $autoload=false): bool { $GLOBALS['options'][$key]=$value; return true; }
function is_email(string $email): bool { return filter_var($email,FILTER_VALIDATE_EMAIL)!==false; }
function wp_mail(string $email,string $subject,string $body): bool { $GLOBALS['mail_alerts'][]=$subject.'|'.$body; return true; }
function wp_next_scheduled(string $hook) { return false; }
function wp_schedule_event(int $timestamp,string $cadence,string $hook): bool { return true; }
function add_action(string $name,array $callback): void {}
define('HOUR_IN_SECONDS',3600);

class WP_Error {
    private string $code;
    public function __construct(string $code,string $message='',array $extra=[]) { $this->code=$code; }
    public function get_error_code(): string { return $this->code; }
}
class StayCore_DB {
    public static function tables(): array {
        return ['reservations'=>'wp_staycore_reservations','guests'=>'wp_staycore_guests',
            'reservation_units'=>'wp_staycore_reservation_units',
            'reservation_guests'=>'wp_staycore_reservation_guests',
            'payments'=>'wp_staycore_payments','revisions'=>'wp_staycore_reservation_revisions'];
    }
}
class SafetyMockDB {
    public int $insert_id=0;
    public int $insert_count=0;
    public bool $fail=false;
    public array $revisions=[];
    public array $current_ids=[12];
    public function prepare(string $sql,...$args): string { return $sql; }
    public function get_row(string $query,$format=null): ?array {
        if(str_contains($query,'wp_staycore_reservations')) return ['id'=>'12','guest_id'=>'6','check_in'=>'2026-10-01 13:00:00','check_out'=>'2026-10-12 11:00:00','total'=>'1200.00','status'=>'checked_in'];
        if(str_contains($query,'wp_staycore_guests')) return $this->fail?null:['id'=>'6','first_name'=>'Test','last_name'=>'Guest','phone'=>'redacted','email'=>'','nationality'=>'India'];
        return null;
    }
    public function get_results(string $sql,$format=null): array {
        if(str_contains($sql,'reservation_units')) return [['reservation_id'=>12,'unit_id'=>8]];
        if(str_contains($sql,'reservation_guests')) return [];
        if(str_contains($sql,'payments')) return [['id'=>3,'reservation_id'=>12,'amount'=>'400.00','status'=>'captured']];
        return [];
    }
    public function get_col(string $sql): array { return $this->current_ids; }
    public function get_var(string $sql) { return 0; }
    public function insert(string $table,array $data) {
        if($this->fail) return false;
        $this->insert_id=++$this->insert_count;
        $this->revisions[]=$data;
        return 1;
    }
}
require_once __DIR__.'/../includes/class-staycore-data-safety.php';

function check(bool $valid,string $name): void {
    if(!$valid) { fwrite(STDERR,'FAIL '.$name.PHP_EOL); exit(1); }
    echo 'PASS '.$name.PHP_EOL;
}
$wpdb=new SafetyMockDB();
check(StayCore_Data_Safety::snapshot_reservation(12,'before_reservation_update'),'Booking revision inserted');
check(count($wpdb->revisions)===1,'Exactly one revision appended');
$payload=$wpdb->revisions[0]['payload'];
check(hash('sha256',$payload)===$wpdb->revisions[0]['checksum'],'Snapshot checksum validates');
$data=json_decode($payload,true);
check($data['reservation']['total']==='1200.00'&&count($data['reservation_units'])===1,'Reservation state and assignments preserved');
check(count($data['payments'])===1&&$data['guest']['first_name']==='Test','Payment and guest details preserved');
check(!str_contains($payload,'id_image')&&!str_contains($payload,'api_secret'),'No guest ID image or credential copied into snapshot');

$wpdb->fail=true;
$r=StayCore_Data_Safety::safeguard(12,'before_status_change');
check($r instanceof WP_Error && $r->get_error_code()==='booking_snapshot_failed','Unprotected write is blocked');
$wpdb->fail=false;
check(StayCore_Data_Safety::safeguard(12,'before_status_change')===true,'Protected write permitted');
$GLOBALS['options']['admin_email']='ops@example.org';
$wpdb->current_ids=[12];
StayCore_Data_Safety::checkpoint();
check(get_option('staycore_last_booking_checkpoint_count')===1,'Daily checkpoint stores count baseline');
$wpdb->current_ids=[];
StayCore_Data_Safety::checkpoint();
check(count($GLOBALS['mail_alerts'])===1,'Unexpected decrease alerts administrators');
check(get_option('staycore_last_booking_checkpoint_count')===0,'Checkpoint updates last count');

$dbcode=file_get_contents(__DIR__.'/../includes/class-staycore-db.php');
$start=strpos($dbcode,'private static function migrate_reservation_units');
$end=strpos($dbcode,'public static function log(', $start);
$method=substr($dbcode,$start,$end-$start);
check(!str_contains($method,'$wpdb->delete('),'Legacy migration no longer deletes booking rows');
check(str_contains($method,'INSERT IGNORE'),'Legacy unit mappings remain non-destructively backfilled');
check(str_contains($dbcode,"'revisions' =>"),'Revision table included in schema map');
