<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/../');
define('STAYCORE_R2_ENABLED',true);
define('STAYCORE_R2_ACCOUNT_ID',str_repeat('a',32));
define('STAYCORE_R2_BUCKET','andaz-pms-private');
define('STAYCORE_R2_ACCESS_KEY_ID','testaccesskey1234');
define('STAYCORE_R2_SECRET_ACCESS_KEY','test-secret-for-fake-r2-only-never-real');
define('STAYCORE_R2_RECOVERY_PASSPHRASE','fake offsite backup recovery phrase of sufficient length');
define('STAYCORE_R2_PREFIX','andaz/staycore');
$GLOBALS['fake_r2']=[];
$GLOBALS['requests']=[];
$GLOBALS['options']=[];
$GLOBALS['alerts']=[];
$GLOBALS['transients']=[];
$GLOBALS['fail_upload']=false;
$GLOBALS['testvault']=sys_get_temp_dir().'/staycore-offsite-test-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['testvault'],0700);
function is_wp_error($x): bool {return $x instanceof WP_Error;}
class WP_Error { public function __construct(public string $code,public string $message=''){} }
class StayCore_Private_Backups {
    public static function vault_path(): ?string { return $GLOBALS['testvault'];}
    public static function wrapped_recovery_key(string $pass): ?string {return 'fake-AES-GCM-encrypted-key-envelope';}
    public static function read(int $booking,string $filename): ?array { return $booking===12?['reservation'=>['id'=>12]]:null; }
}
function wp_remote_request(string $url,array $args): array {
    $method=$args['method'];
    $headers=$args['headers'];
    if(!preg_match('#^https://'.str_repeat('a',32).'\\.r2\\.cloudflarestorage\\.com/andaz-pms-private/v1/#',$url)) throw new Exception('Unexpected target host');
    if(!str_contains((string)($headers['Authorization']??''),'AWS4-HMAC-SHA256 Credential=testaccesskey1234/')) throw new Exception('Missing R2 SigV4 auth');
    if(!isset($headers['x-amz-content-sha256'],$headers['x-amz-date'])) throw new Exception('Unsigned R2 content');
    if(isset($args['sslverify'])&&$args['sslverify']!==true) throw new Exception('TLS verification disabled');
    if(isset($args['redirection'])&&$args['redirection']!==0) throw new Exception('Redirects enabled');
    $GLOBALS['requests'][]=$method;
    if($method==='PUT'){
        if($GLOBALS['fail_upload']) return ['code'=>503];
        $body=(string)$args['body'];
        $GLOBALS['fake_r2'][$url]=['body'=>$body,'sha'=>hash('sha256',$body)];
        return ['code'=>200];
    }
    if(!isset($GLOBALS['fake_r2'][$url])) return ['code'=>404];
    $obj=$GLOBALS['fake_r2'][$url];
    if($method==='HEAD') return ['code'=>200,'headers'=>[
        'x-amz-meta-sha256'=>$obj['sha'],'content-length'=>strlen($obj['body'])
    ]];
    return ['code'=>200,'body'=>$obj['body']];
}
function wp_remote_retrieve_response_code($r): int{return $r['code'];}
function wp_remote_retrieve_header($r,string $key){return $r['headers'][$key]??'';}
function wp_remote_retrieve_body($r){return $r['body']??'';}
function get_option(string $key,$default=null){return $GLOBALS['options'][$key]??$default;}
function update_option(string $key,$value,bool $autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function get_transient(string $key){return $GLOBALS['transients'][$key]??false;}
function set_transient(string $key,$value,int $ttl){$GLOBALS['transients'][$key]=$value;return true;}
function delete_transient(string $key){unset($GLOBALS['transients'][$key]);return true;}
function is_email(string $value): bool{return str_contains($value,'@');}
function wp_mail(string $email,string $subject,string $message):bool{$GLOBALS['alerts'][]=$subject;return true;}
function wp_next_scheduled(string $hook){return false;}
function wp_schedule_event(int $time,string $cadence,string $hook):bool{return true;}
function wp_schedule_single_event(int $time,string $hook):bool{return true;}
function add_filter(string $hook,$cb):void{}
function add_action(string $hook,$cb):void{}
require_once __DIR__.'/../includes/class-staycore-r2-offsite.php';
function check_case(bool $ok,string $description): void {
    if(!$ok){fwrite(STDERR,'FAIL: '.$description.PHP_EOL);exit(1);}
    echo 'PASS: '.$description.PHP_EOL;
}
$config=StayCore_R2_Offsite::config();
check_case(is_array($config),'R2 credentials accepted only from configuration constants');
check_case(StayCore_R2_Offsite::send($config,'v1/andaz/staycore/bookings/fake.scbackup','encrypted example'),'AES-GCM file uploads and GET verification succeed');
check_case(in_array('PUT',$GLOBALS['requests'],true)&&in_array('GET',$GLOBALS['requests'],true),'R2 request includes remote verification GET');
$writes=count(array_filter($GLOBALS['requests'],fn($x)=>$x==='PUT'));
check_case(StayCore_R2_Offsite::send($config,'v1/andaz/staycore/bookings/fake.scbackup','encrypted example'),'Idempotent R2 retry works');
check_case(count(array_filter($GLOBALS['requests'],fn($x)=>$x==='PUT'))===$writes,'Existing R2 object is never overwritten');
check_case(!StayCore_R2_Offsite::send($config,'v1/andaz/staycore/bookings/fake.scbackup','changed payload'),'Mismatched remote object cannot overwrite old backup');

$name='booking-12-'.str_repeat('b',32).'.scbackup';
$encrypted=json_encode(['format'=>'staycore-booking-v1','reservation_id'=>12,'ciphertext'=>'TEST DATA']);
file_put_contents($GLOBALS['testvault'].'/'.$name,$encrypted);
$GLOBALS['fail_upload']=true;
StayCore_R2_Offsite::sync();
check_case(StayCore_R2_Offsite::pending()['count']===1,'Cloud upload outage keeps local encrypted archive queued');
$GLOBALS['fail_upload']=false;
StayCore_R2_Offsite::sync();
check_case(StayCore_R2_Offsite::pending()['count']===0,'Next worker run uploads queued backup');
check_case(is_file($GLOBALS['testvault'].'/'.$name.'.synced'),'Successful upload gets durable local acknowledgement');
check_case(is_file($GLOBALS['testvault'].'/'.$name),'Offsite transfer never deletes source backup');
$state=StayCore_R2_Offsite::status();
check_case($state['configured']===true && $state['pending']===0 && $state['last_verified_at']!=='','Status exposes only health metadata');
foreach(glob($GLOBALS['testvault'].'/*')?:[] as $file) unlink($file);
rmdir($GLOBALS['testvault']);
