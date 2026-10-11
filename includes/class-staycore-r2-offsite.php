<?php
if (!defined('ABSPATH')) exit;

/**
 * Cloudflare R2 immutable, encrypted offsite copy for StayCore booking vault.
 *
 * WordPress credentials must be configured as constants in wp-config.php.
 * This worker never serves or logs keys, requests, or backup bodies publicly.
 * Uploads never delete, list, or overwrite existing R2 objects.
 */
final class StayCore_R2_Offsite {
    private const HOOK='staycore_r2_offsite_sync';
    private const MAX_BATCH=25;
    private const MAX_ARCHIVE=10485760;
    private const BACKUP_DELAY_ALERT=1800;

    public static function config(): ?array {
        if(!defined('STAYCORE_R2_ENABLED') || STAYCORE_R2_ENABLED!==true) return null;
        $c=[
            'account'=>(string)(defined('STAYCORE_R2_ACCOUNT_ID')?STAYCORE_R2_ACCOUNT_ID:''),
            'bucket'=>(string)(defined('STAYCORE_R2_BUCKET')?STAYCORE_R2_BUCKET:''),
            'access'=>(string)(defined('STAYCORE_R2_ACCESS_KEY_ID')?STAYCORE_R2_ACCESS_KEY_ID:''),
            'secret'=>(string)(defined('STAYCORE_R2_SECRET_ACCESS_KEY')?STAYCORE_R2_SECRET_ACCESS_KEY:''),
            'passphrase'=>(string)(defined('STAYCORE_R2_RECOVERY_PASSPHRASE')?STAYCORE_R2_RECOVERY_PASSPHRASE:''),
            'prefix'=>(string)(defined('STAYCORE_R2_PREFIX')?STAYCORE_R2_PREFIX:'andaz/staycore'),
            'jurisdiction'=>(string)(defined('STAYCORE_R2_JURISDICTION')?STAYCORE_R2_JURISDICTION:'default'),
        ];
        if(!preg_match('/^[a-f0-9]{32}$/D',$c['account']) ||
            !preg_match('/^[a-z0-9][a-z0-9.-]{2,62}$/D',$c['bucket']) ||
            !preg_match('/^[A-Za-z0-9]{12,200}$/D',$c['access']) ||
            strlen($c['secret'])<24 ||
            strlen($c['passphrase'])<24 ||
            !preg_match('#^[a-z0-9][a-z0-9_/-]{0,110}$#D',$c['prefix']) ||
            str_contains($c['prefix'],'//') || str_contains($c['prefix'],'..') ||
            !in_array($c['jurisdiction'],['default','eu','us'],true)) return null;
        return $c;
    }

    private static function host(array $c): string {
        return $c['account'].($c['jurisdiction']==='default'?'':'.'.$c['jurisdiction']).'.r2.cloudflarestorage.com';
    }

    private static function keypath(string $key): string {
        return implode('/',array_map('rawurlencode',explode('/',$key)));
    }

    /**
     * Minimal AWS Signature V4 client for R2 S3 API.
     * Never permits an arbitrary host, path, method or URL.
     */
    private static function call(array $c,string $method,string $object,string $body='',array $metadata=[]) {
        if(!in_array($method,['PUT','HEAD','GET'],true) ||
            !preg_match('#^[a-zA-Z0-9/_\\.-]{1,240}$#D',$object) ||
            str_contains($object,'..')) return new WP_Error('r2_invalid_request','Invalid R2 archive request.');

        $host=self::host($c);
        $path='/'.self::keypath($c['bucket']).'/'.self::keypath($object);
        $date=gmdate('Ymd');
        $stamp=gmdate('Ymd\\THis\\Z');
        $payload_hash=hash('sha256',$method==='PUT'?$body:'');
        $headers=[
            'host'=>$host,
            'x-amz-content-sha256'=>$payload_hash,
            'x-amz-date'=>$stamp,
        ];
        if($method==='PUT' && isset($metadata['sha256'])){
            $headers['x-amz-meta-sha256']=$metadata['sha256'];
        }
        ksort($headers,SORT_STRING);
        $canonical='';
        foreach($headers as $header=>$value) $canonical.=$header.':'.trim($value)."\n";
        $signed=implode(';',array_keys($headers));
        $canonical_request=$method."\n".$path."\n\n".$canonical."\n".$signed."\n".$payload_hash;
        $scope=$date.'/auto/s3/aws4_request';
        $to_sign="AWS4-HMAC-SHA256\n".$stamp."\n".$scope."\n".hash('sha256',$canonical_request);
        $k_date=hash_hmac('sha256',$date,'AWS4'.$c['secret'],true);
        $k_region=hash_hmac('sha256','auto',$k_date,true);
        $k_service=hash_hmac('sha256','s3',$k_region,true);
        $k_signing=hash_hmac('sha256','aws4_request',$k_service,true);
        $signature=hash_hmac('sha256',$to_sign,$k_signing);
        $auth='AWS4-HMAC-SHA256 Credential='.$c['access'].'/'.$scope.', SignedHeaders='.$signed.', Signature='.$signature;
        unset($headers['host']);
        $headers['Authorization']=$auth;
        $headers['Content-Type']='application/octet-stream';
        $headers['Cache-Control']='no-store';
        return wp_remote_request('https://'.$host.$path,[
            'method'=>$method,'headers'=>$headers,
            'body'=>$method==='PUT'?$body:null,
            'timeout'=>20,'redirection'=>0,'sslverify'=>true,
            'reject_unsafe_urls'=>true,
            'limit_response_size'=>self::MAX_ARCHIVE+1024,
        ]);
    }

    private static function response_ok($response,int $code): bool {
        return !is_wp_error($response) && (int)wp_remote_retrieve_response_code($response)===$code;
    }

    /**
     * Verify remote file bytes (not only HTTP success / untrusted ETag).
     * Existing files are never overwritten. Remote mismatch halts the batch.
     */
    public static function send(array $c,string $object,string $encrypted): bool {
        $local_hash=hash('sha256',$encrypted);
        $check=self::call($c,'HEAD',$object);
        $code=is_wp_error($check)?0:(int)wp_remote_retrieve_response_code($check);
        if($code!==404 && $code!==200) return false;
        if($code===200){
            if(!hash_equals($local_hash,(string)wp_remote_retrieve_header($check,'x-amz-meta-sha256')) ||
                (int)wp_remote_retrieve_header($check,'content-length')!==strlen($encrypted)) return false;
        } else {
            $uploaded=self::call($c,'PUT',$object,$encrypted,['sha256'=>$local_hash]);
            if(!self::response_ok($uploaded,200)) return false;
        }
        $download=self::call($c,'GET',$object);
        return self::response_ok($download,200) &&
            hash_equals($local_hash,hash('sha256',(string)wp_remote_retrieve_body($download)));
    }

    private static function alert(string $message): void {
        error_log('StayCore offsite backup: '.$message);
        $last=(int)get_option('staycore_r2_last_alert',0);
        if(time()-$last<3600) return;
        update_option('staycore_r2_last_alert',time(),false);
        $address=(string)get_option('admin_email','');
        if($address!=='' && is_email($address))
            wp_mail($address,'Andaz PMS — offsite backup alert',$message);
    }

    public static function pending(): array {
        $root=StayCore_Private_Backups::vault_path();
        if(!$root) return ['count'=>0,'oldest'=>0,'files'=>[]];
        $files=glob($root.'/booking-*.scbackup');
        if(!is_array($files)) return ['count'=>0,'oldest'=>0,'files'=>[]];
        $pending=[];$oldest=0;
        foreach($files as $path){
            $name=basename($path);
            if(!preg_match('/^booking-[0-9]+-[a-f0-9]{32}\\.scbackup$/D',$name) ||
                is_link($path)) continue;
            if(is_file($path.'.synced')){
                $receipt=@file_get_contents($path.'.synced');
                $local_hash=@hash_file('sha256',$path);
                if(is_string($receipt) && is_string($local_hash) &&
                    preg_match('/^([a-f0-9]{64})/',$receipt,$m) &&
                    hash_equals($m[1],$local_hash)) continue;
            }
            $pending[]=$path;
            $age=max(0,time()-(int)filemtime($path));
            if($age>$oldest)$oldest=$age;
        }
        sort($pending,SORT_STRING);
        return ['count'=>count($pending),'oldest'=>$oldest,'files'=>$pending];
    }

    public static function status(): array {
        $q=self::pending();
        $enabled=defined('STAYCORE_R2_ENABLED') && STAYCORE_R2_ENABLED===true;
        return [
            'enabled'=>$enabled,
            'configured'=>self::config()!==null,
            'pending'=>$q['count'],'oldest_pending_seconds'=>$q['oldest'],
            'last_verified_at'=>(string)get_option('staycore_r2_last_verified',''),
            'last_error_at'=>(string)get_option('staycore_r2_last_error',''),
        ];
    }

    /** Five-minute cron worker: bounded scans; durable local backlog on errors. */
    public static function sync(): void {
        $c=self::config();
        if($c===null){
            if(defined('STAYCORE_R2_ENABLED') && STAYCORE_R2_ENABLED===true)
                self::alert('Offsite backup is enabled but configuration is incomplete.');
            return;
        }
        $lock='staycore_r2_sync_lock';
        if(get_transient($lock)) return;
        set_transient($lock,1,240);
        try {
            $q=self::pending();
            $archive_key=StayCore_Private_Backups::wrapped_recovery_key($c['passphrase']);
            if(!is_string($archive_key) || $archive_key===''){
                self::failure('Encrypted key escrow could not be prepared.');
                return;
            }
            $vault_key='v1/recovery/'.$c['prefix'].'/vault-key-'.substr(hash('sha256',$archive_key),0,20).'.keybackup';
            if(!self::send($c,$vault_key,$archive_key)){
                self::failure('Recovery-key escrow upload/verification failed.');
                return;
            }
            foreach(array_slice($q['files'],0,self::MAX_BATCH) as $path){
                $name=basename($path);
                $bytes=@file_get_contents($path);
                if(!is_string($bytes) || strlen($bytes)<10 || strlen($bytes)>self::MAX_ARCHIVE){
                    self::failure('A private booking backup cannot be read safely.');
                    continue;
                }
                $item=json_decode($bytes,true);
                if(!is_array($item) || ($item['format']??'')!=='staycore-booking-v1' ||
                    !preg_match('/^booking-'.(int)($item['reservation_id']??0).'-[a-f0-9]{32}\\.scbackup$/D',$name)){
                    self::failure('Private backup failed format validation.');
                    continue;
                }
                $object='v1/'.$c['prefix'].'/bookings/'.$name;
                if(!self::send($c,$object,$bytes)){
                    self::failure('Offsite archive transfer/verification failed.');
                    continue;
                }
                // Persistent local acknowledgement for retries; never delete
                // the encrypted source archive after upload.
                $receipt=$path.'.synced';
                $receipt_text=hash('sha256',$bytes)."\n".gmdate('c')."\n";
                if(@file_put_contents($receipt,$receipt_text,LOCK_EX)===false){
                    self::failure('Offsite archive verified but local acknowledgement failed.');
                    continue;
                }
                @chmod($receipt,0600);
                update_option('staycore_r2_last_verified',gmdate('Y-m-d H:i:s'),false);
            }
            // Verify one previously uploaded cloud archive per day.
            $last_audit=(int)get_option('staycore_r2_last_restore_audit_epoch',0);
            if(time()-$last_audit>=86400) {
                $root=StayCore_Private_Backups::vault_path();
                $verified=false;
                if($root) {
                    $synced=glob($root.'/booking-*.scbackup.synced');
                    if(is_array($synced) && $synced){
                        sort($synced,SORT_STRING);
                        $index=(int)(floor(time()/86400)%count($synced));
                        $path=substr($synced[$index],0,-7);
                        $body=@file_get_contents($path);
                        if(is_string($body) && strlen($body)<=self::MAX_ARCHIVE){
                            $key='v1/'.$c['prefix'].'/bookings/'.basename($path);
                            $verified=self::remote_only_verify($c,$key,$body);
                        }
                    } else $verified=true;
                }
                if($verified) update_option('staycore_r2_last_restore_audit_epoch',time(),false);
                else self::failure('Daily independent cloud restore-verification failed.');
            }
            $remaining=self::pending();
            if($remaining['oldest']>self::BACKUP_DELAY_ALERT)
                self::alert('Some encrypted booking backups have not been copied offsite for more than 30 minutes.');
        } finally {
            delete_transient($lock);
        }
    }

    private static function remote_only_verify(array $c,string $object,string $body): bool {
        $head=self::call($c,'HEAD',$object);
        if(!self::response_ok($head,200)) return false;
        if(!hash_equals(hash('sha256',$body),(string)wp_remote_retrieve_header($head,'x-amz-meta-sha256'))) return false;
        $get=self::call($c,'GET',$object);
        return self::response_ok($get,200) &&
            hash_equals(hash('sha256',$body),hash('sha256',(string)wp_remote_retrieve_body($get)));
    }

    private static function failure(string $message): void {
        update_option('staycore_r2_last_error',gmdate('Y-m-d H:i:s'),false);
        self::alert($message);
    }

    public static function schedules(array $schedules): array {
        $schedules['staycore_r2_five_minutes']=['interval'=>300,'display'=>'Every five minutes (StayCore offsite)'];
        return $schedules;
    }

    public static function schedule_soon(): void {
        if(self::config()!==null && !wp_next_scheduled(self::HOOK))
            wp_schedule_single_event(time()+60,self::HOOK);
    }

    public static function boot(): void {
        add_filter('cron_schedules',[__CLASS__,'schedules']);
        add_action(self::HOOK,[__CLASS__,'sync']);
        if(self::config()!==null && !wp_next_scheduled(self::HOOK))
            wp_schedule_event(time()+120,'staycore_r2_five_minutes',self::HOOK);
    }
}
