<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/../');
define('STAYCORE_SUPABASE_ENABLED',true);
define('STAYCORE_SUPABASE_PROJECT_REF','abcdefghijklmnopqrst');
define('STAYCORE_SUPABASE_BUCKET','andaz-pms-private');
define('STAYCORE_SUPABASE_SECRET_KEY','sb_secret_TEST_ONLY_not_a_real_secret_123456');
define('STAYCORE_SUPABASE_RECOVERY_PASSPHRASE','fake staging recovery passphrase stored offline');
define('STAYCORE_SUPABASE_PREFIX','andaz/staycore/staging');

$GLOBALS['store']=[];
$GLOBALS['ops']=[];
$GLOBALS['options']=[];
$GLOBALS['transients']=[];
$GLOBALS['fail_upload']=false;
$GLOBALS['dir']=sys_get_temp_dir().'/staycore-supabase-test-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['dir'],0700);
class WP_Error {public function __construct(public string $code,public string $message=''){}}
function is_wp_error($r):bool {return $r instanceof WP_Error;}
class StayCore_Private_Backups {
    public static function vault_path(): ?string {return $GLOBALS['dir'];}
    public static function wrapped_recovery_key(string $phrase): ?string{return 'fake-AES-GCM-wrapped-recovery-vault-key';}
    public static function read(int $id,string $file): ?array{return $id===12?['reservation'=>['id'=>12]]:null;}
}
function wp_remote_request(string $url,array $request):array {
    if(!str_starts_with($url,'https://abcdefghijklmnopqrst.supabase.co/storage/v1/object/'))
        throw new Exception('Requests may only reach configured Supabase project');
    if(($request['headers']['apikey']??'')!==STAYCORE_SUPABASE_SECRET_KEY ||
        isset($request['headers']['Authorization'])) throw new Exception('Expected server-only apikey without JWT Bearer');
    if(($request['sslverify']??false)!==true || ($request['redirection']??1)!==0)
        throw new Exception('HTTPS verification and no redirects required');
    $method=(string)$request['method'];
    if($method==='POST'){
        if(!str_contains($url,'/object/andaz-pms-private/'))throw new Exception('Unexpected upload path');
        if(($request['headers']['x-upsert']??'')!=='false')throw new Exception('Upload must prohibit overwrite');
        $key=substr($url,strpos($url,'/object/andaz-pms-private/')+strlen('/object/andaz-pms-private/'));
        $GLOBALS['ops'][]='POST';
        if($GLOBALS['fail_upload']) return ['code'=>503];
        if(array_key_exists($key,$GLOBALS['store']))return ['code'=>409];
        $GLOBALS['store'][$key]=(string)$request['body'];
        return ['code'=>200];
    }
    if($method!=='GET' || !str_contains($url,'/object/authenticated/andaz-pms-private/'))
        throw new Exception('Unexpected download path');
    $key=substr($url,strpos($url,'/object/authenticated/andaz-pms-private/')+strlen('/object/authenticated/andaz-pms-private/'));
    $GLOBALS['ops'][]='GET';
    if(!array_key_exists($key,$GLOBALS['store'])) return ['code'=>404];
    return ['code'=>200,'body'=>$GLOBALS['store'][$key]];
}
function wp_remote_retrieve_response_code($r):int{return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body']??'';}
function get_option(string $key,$default=null){return $GLOBALS['options'][$key]??$default;}
function update_option(string $key,$v,bool $autoload=false):bool{$GLOBALS['options'][$key]=$v;return true;}
function get_transient(string $key){return $GLOBALS['transients'][$key]??false;}
function set_transient(string $key,$val,int $expiry){$GLOBALS['transients'][$key]=$val;return true;}
function delete_transient(string $key){unset($GLOBALS['transients'][$key]);return true;}
function wp_next_scheduled(string $name){return false;}
function wp_schedule_event(int $ts,string $repeat,string $name):bool{return true;}
function wp_schedule_single_event(int $ts,string $name):bool{return true;}
function add_filter(string $hook,$cb):void{}
function add_action(string $hook,$cb):void{}
function is_email(string $s):bool{return str_contains($s,'@');}
function wp_mail(string $email,string $title,string $body):bool{return true;}

require_once __DIR__.'/../includes/class-staycore-supabase-offsite.php';
function verify(bool $value,string $description):void {
    if(!$value){fwrite(STDERR,'FAIL: '.$description.PHP_EOL);exit(1);}
    echo 'PASS: '.$description.PHP_EOL;
}
$c=StayCore_Supabase_Offsite::config();
verify(is_array($c),'Only configured secret-key-based Supabase project is accepted');
$key='v1/andaz/staycore/staging/bookings/booking-12-test.scbackup';
verify(StayCore_Supabase_Offsite::send($c,$key,'encrypted fixture 123'),'New encrypted object uploaded and downloaded');
verify(in_array('POST',$GLOBALS['ops']) && in_array('GET',$GLOBALS['ops']),'Upload requires remote GET readback');
$uploads=count(array_filter($GLOBALS['ops'],static fn($op)=>$op==='POST'));
verify(StayCore_Supabase_Offsite::send($c,$key,'encrypted fixture 123'),'Retry of identical remote archive succeeds');
verify(count(array_filter($GLOBALS['ops'],static fn($op)=>$op==='POST'))===$uploads,'Existing object is never overwritten');
verify(!StayCore_Supabase_Offsite::send($c,$key,'different encrypted bytes'),'Changed remote content is refused');

$name='booking-12-'.str_repeat('a',32).'.scbackup';
$contents=json_encode(['format'=>'staycore-booking-v1','reservation_id'=>12,'ciphertext'=>'encrypted-only-fixture']);
file_put_contents($GLOBALS['dir'].'/'.$name,$contents);
$GLOBALS['fail_upload']=true;
StayCore_Supabase_Offsite::sync();
verify(StayCore_Supabase_Offsite::pending()['count']===1,'Storage outage cannot delete a pending local encrypted archive');
$GLOBALS['fail_upload']=false;
StayCore_Supabase_Offsite::sync();
verify(StayCore_Supabase_Offsite::pending()['count']===0,'Recovery sync clears backlog after remote verification');
verify(is_file($GLOBALS['dir'].'/'.$name),'Cloud upload never deletes local backup');
verify(is_file($GLOBALS['dir'].'/'.$name.'.supabase-synced'),'Verified delivery writes a local durable receipt');
$state=StayCore_Supabase_Offsite::status();
verify($state['configured'] && $state['pending']===0 && $state['last_verified_at']!=='','Admin-facing status does not expose secrets');
$receipt=$GLOBALS['dir'].'/'.$name.'.supabase-synced';
file_put_contents($receipt,'invalid old receipt');
verify(StayCore_Supabase_Offsite::pending()['count']===1,'Corrupt local receipt re-queues archive');
foreach(glob($GLOBALS['dir'].'/*')?:[] as $file) unlink($file);
rmdir($GLOBALS['dir']);
