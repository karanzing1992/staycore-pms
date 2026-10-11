<?php
if (!defined('ABSPATH')) exit;

/**
 * Supabase Storage off-host mirror for AES-256-GCM encrypted booking archives.
 *
 * This is storage only; guest records are never inserted into Supabase tables.
 * The only credential is a dedicated PROJECT secret key on the PHP server.
 * Configure Supabase in an isolated project: the secret key bypasses RLS.
 * No DELETE, UPDATE, overwrite, public URLs or browser-facing endpoints.
 */
final class StayCore_Supabase_Offsite {
    private const SYNC_HOOK='staycore_supabase_offsite_sync';
    private const SOON_HOOK='staycore_supabase_offsite_once';
    private const RECEIPT='.supabase-synced';
    private const MAX_BATCH=25;
    private const MAX_FILE=10485760;

    public static function config(): ?array {
        if(!defined('STAYCORE_SUPABASE_ENABLED') || STAYCORE_SUPABASE_ENABLED!==true) return null;
        $c=[
            'ref'=>(string)(defined('STAYCORE_SUPABASE_PROJECT_REF')?STAYCORE_SUPABASE_PROJECT_REF:''),
            'bucket'=>(string)(defined('STAYCORE_SUPABASE_BUCKET')?STAYCORE_SUPABASE_BUCKET:''),
            'secret'=>(string)(defined('STAYCORE_SUPABASE_SECRET_KEY')?STAYCORE_SUPABASE_SECRET_KEY:''),
            'passphrase'=>(string)(defined('STAYCORE_SUPABASE_RECOVERY_PASSPHRASE')?STAYCORE_SUPABASE_RECOVERY_PASSPHRASE:''),
            'prefix'=>(string)(defined('STAYCORE_SUPABASE_PREFIX')?STAYCORE_SUPABASE_PREFIX:'andaz/staycore/production'),
        ];
        if(!preg_match('/^[a-z0-9]{20}$/D',$c['ref']) ||
            !preg_match('/^[a-z0-9][a-z0-9_-]{2,62}$/D',$c['bucket']) ||
            !str_starts_with($c['secret'],'sb_secret_') || strlen($c['secret'])<30 ||
            strlen($c['passphrase'])<24 ||
            !preg_match('#^[a-z0-9][a-z0-9_/-]{0,110}$#D',$c['prefix']) ||
            str_contains($c['prefix'],'..') || str_contains($c['prefix'],'//')) return null;
        return $c;
    }

    private static function request(array $c,string $method,string $object,string $content='') {
        if(!in_array($method,['GET','POST'],true) ||
            !preg_match('#^[a-z0-9][a-z0-9_./-]{1,245}$#D',$object) ||
            str_contains($object,'..') || str_contains($object,'//') ||
            ($method==='POST' && strlen($content)>self::MAX_FILE)) {
            return new WP_Error('supabase_bad_archive','Invalid encrypted archive request.');
        }
        $path=implode('/',array_map('rawurlencode',explode('/',$object)));
        $resource=$method==='POST'?'object':'object/authenticated';
        $url='https://'.$c['ref'].'.supabase.co/storage/v1/'.$resource.'/'.rawurlencode($c['bucket']).'/'.$path;
        $headers=[
            // sb_secret keys are NOT JWTs: send as apikey, never a Bearer JWT.
            'apikey'=>$c['secret'],
            'Accept'=>'application/octet-stream',
            'Cache-Control'=>'no-store',
        ];
        if($method==='POST'){
            $headers['Content-Type']='application/octet-stream';
            $headers['x-upsert']='false';
        }
        return wp_remote_request($url,[
            'method'=>$method,'headers'=>$headers,
            'body'=>$method==='POST'?$content:null,
            'timeout'=>25,'redirection'=>0,'sslverify'=>true,
            'reject_unsafe_urls'=>true,'limit_response_size'=>self::MAX_FILE+4096,
        ]);
    }

    private static function status_code($response): int {
        return is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
    }

    private static function matches(array $c,string $key,string $encrypted): bool {
        $response=self::request($c,'GET',$key);
        return self::status_code($response)===200 &&
            hash_equals(hash('sha256',$encrypted),
                hash('sha256',(string)wp_remote_retrieve_body($response)));
    }

    /** Does not overwrite cloud files, including when a retry races an upload. */
    public static function send(array $c,string $key,string $encrypted): bool {
        if(strlen($encrypted)<10 || strlen($encrypted)>self::MAX_FILE) return false;
        $prior=self::request($c,'GET',$key);
        $status=self::status_code($prior);
        if($status===200){
            return hash_equals(hash('sha256',$encrypted),
                hash('sha256',(string)wp_remote_retrieve_body($prior)));
        }
        if($status!==404) return false;
        $sent=self::request($c,'POST',$key,$encrypted);
        $code=self::status_code($sent);
        // Supabase may return 400/409 for a concurrent non-upsert upload.
        // Only matching remote bytes constitute success.
        if(!in_array($code,[200,201,400,409],true)) return false;
        return self::matches($c,$key,$encrypted);
    }

    public static function pending(): array {
        $dir=StayCore_Private_Backups::vault_path();
        if(!$dir) return ['files'=>[],'count'=>0,'oldest'=>0];
        $files=glob($dir.'/booking-*.scbackup');
        if(!is_array($files)) return ['files'=>[],'count'=>0,'oldest'=>0];
        $pending=[];$oldest=0;
        foreach($files as $path){
            if(is_link($path) ||
                !preg_match('/^booking-[0-9]+-[a-f0-9]{32}\\.scbackup$/D',basename($path))) continue;
            $marker=$path.self::RECEIPT;
            if(is_file($marker)){
                $receipt=@file_get_contents($marker);
                $sha=@hash_file('sha256',$path);
                if(is_string($sha) && is_string($receipt) &&
                    str_starts_with($receipt,$sha."\n")) continue;
            }
            $pending[]=$path;
            $age=max(0,time()-(int)@filemtime($path));
            if($age>$oldest) $oldest=$age;
        }
        sort($pending,SORT_STRING);
        return ['files'=>$pending,'count'=>count($pending),'oldest'=>$oldest];
    }

    public static function status(): array {
        $q=self::pending();
        return [
            'enabled'=>defined('STAYCORE_SUPABASE_ENABLED') && STAYCORE_SUPABASE_ENABLED===true,
            'configured'=>self::config()!==null,
            'pending'=>$q['count'],
            'oldest_pending_seconds'=>$q['oldest'],
            'last_verified_at'=>(string)get_option('staycore_supabase_last_verified',''),
            'last_failure_at'=>(string)get_option('staycore_supabase_last_failure',''),
            'last_restore_check_at'=>(string)get_option('staycore_supabase_last_check',''),
        ];
    }

    private static function failure(string $message): void {
        update_option('staycore_supabase_last_failure',gmdate('Y-m-d H:i:s'),false);
        error_log('StayCore encrypted offsite backup: '.$message);
        $previous=(int)get_option('staycore_supabase_last_alert',0);
        if(time()-$previous<3600) return;
        update_option('staycore_supabase_last_alert',time(),false);
        $mail=(string)get_option('admin_email','');
        if($mail && is_email($mail)) wp_mail($mail,'Andaz PMS — Supabase backup attention required',$message);
    }

    public static function sync(): void {
        $c=self::config();
        if($c===null){
            if(defined('STAYCORE_SUPABASE_ENABLED') && STAYCORE_SUPABASE_ENABLED===true)
                self::failure('Supabase Storage settings are incomplete.');
            return;
        }
        $lock='staycore_supabase_offsite_lock';
        if(get_transient($lock)) return;
        set_transient($lock,1,240);
        try {
            $wrapped=StayCore_Private_Backups::wrapped_recovery_key($c['passphrase']);
            if(!is_string($wrapped) || strlen($wrapped)<10){
                self::failure('Could not encrypt/verify independent key escrow.');
                return;
            }
            $escrow='v1/recovery/'.$c['prefix'].'/vault-key-'.substr(hash('sha256',$wrapped),0,24).'.keybackup';
            if(!self::send($c,$escrow,$wrapped)){
                self::failure('Supabase recovery-key escrow failed remote verification.');
                return;
            }

            $q=self::pending();
            foreach(array_slice($q['files'],0,self::MAX_BATCH) as $path){
                $name=basename($path);
                $encrypted=@file_get_contents($path);
                if(!is_string($encrypted) || strlen($encrypted)>self::MAX_FILE){
                    self::failure('Unreadable encrypted local backup retained for review.');
                    continue;
                }
                $data=json_decode($encrypted,true);
                $id=(int)($data['reservation_id']??0);
                if($id<1 || ($data['format']??'')!=='staycore-booking-v1' ||
                    !preg_match('/^booking-'.preg_quote((string)$id,'/').'-[a-f0-9]{32}\\.scbackup$/D',$name) ||
                    !is_array(StayCore_Private_Backups::read($id,$name))){
                    self::failure('Encrypted archive did not authenticate; no upload attempted.');
                    continue;
                }
                $object='v1/'.$c['prefix'].'/bookings/'.$name;
                if(!self::send($c,$object,$encrypted)){
                    self::failure('Encrypted archive upload or download verification failed.');
                    continue;
                }
                $receipt=$path.self::RECEIPT;
                if(@file_put_contents($receipt,hash('sha256',$encrypted)."\n".gmdate('c')."\n",LOCK_EX)===false){
                    self::failure('Cloud verified, but could not save local delivery receipt.');
                    continue;
                }
                @chmod($receipt,0600);
                update_option('staycore_supabase_last_verified',gmdate('Y-m-d H:i:s'),false);
            }

            // Independently verify one previously uploaded object each day.
            $last=(int)get_option('staycore_supabase_last_check_epoch',0);
            if(time()-$last>=86400){
                $dir=StayCore_Private_Backups::vault_path();
                $verified=false;
                if($dir){
                    $receipts=glob($dir.'/booking-*.scbackup'.self::RECEIPT);
                    if(is_array($receipts) && $receipts){
                        sort($receipts,SORT_STRING);
                        $path=substr($receipts[(int)(floor(time()/86400)%count($receipts))],0,-strlen(self::RECEIPT));
                        $payload=@file_get_contents($path);
                        if(is_string($payload) && strlen($payload)<=self::MAX_FILE)
                            $verified=self::matches($c,'v1/'.$c['prefix'].'/bookings/'.basename($path),$payload);
                    } else $verified=true;
                }
                if($verified){
                    update_option('staycore_supabase_last_check_epoch',time(),false);
                    update_option('staycore_supabase_last_check',gmdate('Y-m-d H:i:s'),false);
                } else self::failure('Daily Supabase cloud-backup restore check failed.');
            }
            $remaining=self::pending();
            if($remaining['oldest']>1800)
                self::failure('Booking backups remain only on-site for more than 30 minutes.');
        } finally {
            delete_transient($lock);
        }
    }

    public static function schedules(array $schedules): array {
        $schedules['staycore_supabase_five_minutes']=[
            'interval'=>300,'display'=>'Every 5 minutes (encrypted Supabase backups)'];
        return $schedules;
    }

    public static function schedule_soon(): void {
        if(self::config()!==null && !wp_next_scheduled(self::SOON_HOOK))
            wp_schedule_single_event(time()+60,self::SOON_HOOK);
    }

    public static function boot(): void {
        add_filter('cron_schedules',[__CLASS__,'schedules']);
        add_action(self::SYNC_HOOK,[__CLASS__,'sync']);
        add_action(self::SOON_HOOK,[__CLASS__,'sync']);
        if(self::config()!==null && !wp_next_scheduled(self::SYNC_HOOK))
            wp_schedule_event(time()+120,'staycore_supabase_five_minutes',self::SYNC_HOOK);
    }
}
