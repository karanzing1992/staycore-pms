<?php
if (!defined('ABSPATH')) exit;

/**
 * Deliberately narrow admin-only recovery path.
 *
 * No customer, POS or manager role can reach these actions. A restore requires
 * a reviewed, valid snapshot, an administrator, a CSRF nonce, a short-lived
 * preview ticket, a recorded reason and an explicit booking-ID confirmation.
 * Payments, guest identity, and group bookings are never auto-restored.
 */
final class StayCore_Recovery_Admin {
    private const TICKET_TTL = 600;
    private const FIELDS = [
        'check_in','check_out','adults','children','total','source',
        'external_ref','notes',
    ];

    public static function boot(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_post_staycore_recovery_preview',[__CLASS__,'preview_post']);
        add_action('admin_post_staycore_recovery_checkpoint',[__CLASS__,'checkpoint_post']);
        add_action('admin_post_staycore_recovery_apply',[__CLASS__,'apply_post']);
    }

    public static function allowed(): bool {
        return get_current_user_id()>0 && current_user_can('manage_options');
    }

    private static function deny(): void {
        wp_die('Administrator access is required for booking recovery.', 'Permission denied', ['response'=>403]);
    }

    public static function menu(): void {
        add_management_page('StayCore Booking Recovery','StayCore Booking Recovery',
            'manage_options','staycore-booking-recovery',[__CLASS__,'page']);
    }

    /** Return a verified snapshot or fail without exposing private data. */
    private static function revision(int $booking,int $revision) {
        global $wpdb;
        $t=StayCore_DB::tables();
        $record=$wpdb->get_row($wpdb->prepare(
            "SELECT id,reservation_id,action,payload,checksum,created_at FROM {$t['revisions']} WHERE id=%d AND reservation_id=%d",
            $revision,$booking
        ), ARRAY_A);
        if(!is_array($record)) return new WP_Error('revision_not_found','Recovery revision not found.',['status'=>404]);
        $json=(string)$record['payload'];
        $hash=(string)$record['checksum'];
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash) || !hash_equals($hash,hash('sha256',$json))){
            return new WP_Error('revision_corrupt','Revision checksum verification failed.',['status'=>409]);
        }
        // A SQL checksum alone is not trustworthy against SQL tampering.
        // The separate AES-GCM private vault must authenticate the exact
        // recovery revision before it may be previewed or applied.
        if(!StayCore_Private_Backups::attests($booking,$hash)){
            return new WP_Error('backup_attestation_failed','Independent encrypted backup was not verified. Restore blocked.',['status'=>409]);
        }
        $data=json_decode($json,true);
        if(!is_array($data) || (int)($data['schema_version']??0)!==1 ||
            (int)($data['reservation']['id']??0)!==$booking ||
            !is_array($data['reservation_units']??null)){
            return new WP_Error('revision_invalid','Revision data is not compatible.',['status'=>409]);
        }
        return $data;
    }

    private static function current(int $booking): ?array {
        global $wpdb;
        $t=StayCore_DB::tables();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['reservations']} WHERE id=%d", $booking
        ), ARRAY_A);
        if(!is_array($row)) return null;
        $units=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['reservation_units']} WHERE reservation_id=%d ORDER BY id",
            $booking
        ), ARRAY_A);
        if(!is_array($units)) return null;
        $members=$wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$t['reservation_guests']} WHERE reservation_id=%d",$booking
        ),ARRAY_A);
        if(!is_array($members)) return null;
        return ['row'=>$row,'units'=>$units,'members'=>$members];
    }

    private static function fingerprint(array $current): string {
        $row=$current['row'];
        $fields=[];
        foreach (self::FIELDS as $k) $fields[$k]=(string)($row[$k]??'');
        $fields['status']=(string)$row['status'];
        $fields['unit_id']=(string)$row['unit_id'];
        $fields['guest_id']=(string)$row['guest_id'];
        $fields['updated_at']=(string)$row['updated_at'];
        $fields['member_ids']=array_map(static fn($m)=>(int)$m['id'],$current['members']);
        $fields['units']=array_map(
            static fn($u)=>[(int)$u['unit_id'],(int)($u['guests']??1)],
            $current['units']
        );
        return hash('sha256',wp_json_encode($fields));
    }

    private static function differences(array $current,array $data): array {
        $diff=[];
        foreach(self::FIELDS as $name){
            $before=(string)($current['row'][$name]??'');
            $after=(string)($data['reservation'][$name]??'');
            if($before!==$after) $diff[$name]=['current'=>$before,'recovered'=>$after];
        }
        $cur=array_map(static fn($u)=>(int)$u['unit_id'],$current['units']);
        $old=array_map(static fn($u)=>(int)$u['unit_id'],$data['reservation_units']);
        sort($cur);sort($old);
        if($cur!==$old) $diff['unit_ids']=['current'=>implode(', ',$cur),'recovered'=>implode(', ',$old)];
        return $diff;
    }

    private static function restriction(array $current,array $data): ?string {
        $row=$current['row'];
        $saved=$data['reservation'];
        if(!in_array((string)$row['status'],['confirmed','checked_in'],true) ||
            (string)$saved['status']!==(string)$row['status']) {
            return 'Closed bookings and status changes require an isolated staging recovery.';
        }
        if((int)$saved['guest_id']!==(int)$row['guest_id']) {
            return 'Guest identity changed; resolve this in staging.';
        }
        if(!empty($data['reservation_guests']) || !empty($current['members']) ||
            (!empty($current['row']['meta']) &&
             str_contains((string)$current['row']['meta'],'"group_booking":true'))){
            return 'Group bookings need a separate staged reconciliation; automatic restore is disabled.';
        }
        if(!$data['reservation_units']) return 'Revision has no assigned inventory.';
        $newIn=strtotime((string)$saved['check_in']);
        $newOut=strtotime((string)$saved['check_out']);
        if(!$newIn || !$newOut || $newOut<=$newIn) return 'Recovered stay dates are invalid.';
        if((float)$saved['total']<0) return 'Recovered booking total is invalid.';
        return null;
    }

    private static function inventory_safe(int $booking,array $data): ?string {
        global $wpdb;
        $t=StayCore_DB::tables();
        $row=$data['reservation'];
        $units=$data['reservation_units'];
        $seen=[];
        $capacity=0;
        foreach($units as $unit){
            $uid=(int)($unit['unit_id']??0);
            if($uid<1 || isset($seen[$uid])) return 'Backup contains duplicate or invalid room/bed assignments.';
            $seen[$uid]=true;
            $info=$wpdb->get_row($wpdb->prepare(
                "SELECT status,capacity FROM {$t['units']} WHERE id=%d",$uid
            ),ARRAY_A);
            if(!is_array($info) || ($info['status']??'')!=='available') return 'An assigned room/bed is unavailable.';
            $capacity+=max(1,(int)$info['capacity']);
            $overlap=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['reservation_units']} ru
                 JOIN {$t['reservations']} r ON r.id=ru.reservation_id
                 WHERE ru.unit_id=%d AND r.id<>%d
                   AND r.status IN ('confirmed','checked_in')
                   AND r.check_in<%s AND r.check_out>%s",
                $uid,$booking,(string)$row['check_out'],(string)$row['check_in']
            ));
            if($overlap>0) return 'Room/bed is now reserved by another booking. Restore blocked.';
        }
        if((int)$row['adults']+(int)$row['children']>$capacity) return 'Recovered inventory cannot hold booked guests.';
        $captured=(float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$t['payments']}
             WHERE reservation_id=%d AND status='captured'",$booking
        ));
        if((float)$row['total']+0.009<$captured) return 'Recovered total would be below already captured payments.';
        return null;
    }

    /**
     * Only this method can mutate a booking from recovery.
     * On production it is disabled until explicitly enabled after staging QA.
     */
    public static function apply(int $booking,int $revision,string $fingerprint,string $reason) {
        if(!self::allowed()) return new WP_Error('forbidden','Only a WordPress administrator can restore bookings.',['status'=>403]);
        if(!defined('STAYCORE_RECOVERY_ENABLED') || STAYCORE_RECOVERY_ENABLED !== true) {
            return new WP_Error('recovery_disabled','Recovery writes are disabled until staging verification is completed.',['status'=>503]);
        }
        if(strlen(trim($reason))<12) return new WP_Error('reason_required','Enter a detailed recovery reason (12+ characters).',['status'=>400]);
        $data=self::revision($booking,$revision);
        if(is_wp_error($data)) return $data;
        global $wpdb;
        $t=StayCore_DB::tables();
        if($wpdb->query('START TRANSACTION')===false) return new WP_Error('transaction_failed','Cannot begin recovery transaction.',['status'=>503]);
        $locked=$wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$t['reservations']} WHERE id=%d FOR UPDATE",$booking
        ),ARRAY_A);
        $current=$locked?self::current($booking):null;
        if(!$current || !hash_equals($fingerprint,self::fingerprint($current))) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('stale_recovery','Booking changed after preview. Start a fresh recovery review.',['status'=>409]);
        }
        $restriction=self::restriction($current,$data);
        $inventory=$restriction?null:self::inventory_safe($booking,$data);
        if($restriction || $inventory){
            $wpdb->query('ROLLBACK');
            return new WP_Error('restore_blocked',$restriction?:$inventory,['status'=>409]);
        }
        $safe=StayCore_Data_Safety::safeguard($booking,'before_admin_restore');
        if(is_wp_error($safe)){ $wpdb->query('ROLLBACK'); return $safe; }

        $fields=[];
        foreach(self::FIELDS as $name) $fields[$name]=$data['reservation'][$name]??null;
        $fields['unit_id']=(int)$data['reservation_units'][0]['unit_id'];
        $fields['updated_at']=current_time('mysql');
        if($wpdb->update($t['reservations'],$fields,['id'=>$booking])===false){
            $wpdb->query('ROLLBACK');
            return new WP_Error('restore_failed','Could not restore booking details.',['status'=>500]);
        }

        $now=current_time('mysql');
        $currentUnits=array_column($current['units'],'unit_id');
        $snapshotUnits=array_column($data['reservation_units'],'unit_id');
        foreach(array_diff($snapshotUnits,$currentUnits) as $uid){
            if($wpdb->insert($t['reservation_units'],[
                'reservation_id'=>$booking,'unit_id'=>(int)$uid,'guests'=>1,'created_at'=>$now
            ])!==1){$wpdb->query('ROLLBACK');return new WP_Error('restore_failed','Could not restore bed assignments.',['status'=>500]);}
        }
        foreach(array_diff($currentUnits,$snapshotUnits) as $uid){
            if($wpdb->delete($t['reservation_units'],['reservation_id'=>$booking,'unit_id'=>(int)$uid])===false){
                $wpdb->query('ROLLBACK');return new WP_Error('restore_failed','Could not remove superseded bed assignments.',['status'=>500]);
            }
        }
        if(!StayCore_Data_Safety::snapshot_reservation($booking,'after_admin_restore')){
            $wpdb->query('ROLLBACK');
            return new WP_Error('backup_failed','Restored state could not be protected; rollback applied.',['status'=>503]);
        }
        // Restore audit must be durable or the entire transaction is rejected.
        $logged=$wpdb->insert($t['activity'],[
            'user_id'=>get_current_user_id(),
            'action'=>'admin_booking_restored',
            'entity_type'=>'reservation',
            'entity_id'=>$booking,
            'message'=>'Administrator applied audited booking recovery.',
            'meta'=>wp_json_encode([
                'revision_id'=>$revision,'reason'=>sanitize_textarea_field($reason),
                'previous_fingerprint'=>$fingerprint,
                'restored_fields'=>array_keys(self::differences($current,$data)),
                'payment_policy'=>'unchanged','guest_policy'=>'unchanged',
            ]),
            'created_at'=>current_time('mysql')
        ]);
        if($logged!==1 || $wpdb->query('COMMIT')===false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('audit_failed','Recovery was not committed; audit log is required.',['status'=>500]);
        }
        return ['booking_id'=>$booking,'revision_id'=>$revision,'restored'=>true];
    }

    public static function checkpoint_post(): void {
        if(!self::allowed()) self::deny();
        check_admin_referer('staycore_recovery_checkpoint');
        $result=StayCore_Data_Safety::checkpoint(true);
        StayCore_DB::log('admin_backup_checkpoint','reservation',null,
            'Administrator created an on-demand booking checkpoint.',
            ['total'=>(int)$result['total'],'archived'=>(int)$result['archived'],'failed'=>(int)$result['failed']]);
        wp_safe_redirect(add_query_arg([
            'page'=>'staycore-booking-recovery',
            'checkpoint'=>$result['failed']?'failed':'complete',
            'archived'=>(int)$result['archived'],
        ],admin_url('tools.php')));
        exit;
    }

    public static function preview_post(): void {
        if(!self::allowed()) self::deny();
        check_admin_referer('staycore_recovery_preview');
        $booking=absint($_POST['booking_id']??0);
        $revision=absint($_POST['revision_id']??0);
        $data=self::revision($booking,$revision);
        $current=self::current($booking);
        if(is_wp_error($data) || !$current) wp_die('Booking or verified backup not found.');
        $diff=self::differences($current,$data);
        $restriction=self::restriction($current,$data);
        $inventory=$restriction?null:self::inventory_safe($booking,$data);
        try {$ticket=bin2hex(random_bytes(20));} catch(Throwable $e){wp_die('Secure preview token unavailable.');}
        $key='staycore_restore_'.hash('sha256',$ticket);
        set_transient($key,[
            'user_id'=>get_current_user_id(),'booking'=>$booking,
            'revision'=>$revision,'fingerprint'=>self::fingerprint($current),
            'diff'=>$diff,'blocked'=>$restriction?:$inventory,
        ],self::TICKET_TTL);
        StayCore_DB::log('recovery_previewed','reservation',$booking,'Administrator inspected recovery revision.',['revision_id'=>$revision,'blocked'=>(bool)($restriction?:$inventory)]);
        wp_safe_redirect(add_query_arg(['page'=>'staycore-booking-recovery','ticket'=>$ticket],admin_url('tools.php')));
        exit;
    }

    public static function apply_post(): void {
        if(!self::allowed()) self::deny();
        check_admin_referer('staycore_recovery_apply');
        $ticket=sanitize_text_field(wp_unslash($_POST['ticket']??''));
        $key='staycore_restore_'.hash('sha256',$ticket);
        $review=preg_match('/^[a-f0-9]{40}$/D',$ticket)?get_transient($key):false;
        if(!is_array($review) || (int)$review['user_id']!==get_current_user_id()) wp_die('Recovery review expired or is not yours.');
        $booking=(int)$review['booking'];
        if(sanitize_text_field(wp_unslash($_POST['confirmation']??''))!=='RESTORE BOOKING '.$booking) wp_die('Booking confirmation did not match.');
        if(!empty($review['blocked'])) wp_die('This recovery cannot be applied automatically.');
        $result=self::apply($booking,(int)$review['revision'],(string)$review['fingerprint'],
            (string)wp_unslash($_POST['reason']??''));
        if(is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        delete_transient($key);
        wp_safe_redirect(add_query_arg(['page'=>'staycore-booking-recovery','restored'=>$booking],admin_url('tools.php')));
        exit;
    }

    public static function page(): void {
        if(!self::allowed()) self::deny();
        global $wpdb;
        $t=StayCore_DB::tables();
        echo '<div class="wrap"><h1>StayCore Booking Recovery</h1>';
        echo '<p>Administrator only. Preview is read-only. Payments, guest identity and group bookings are never overwritten.</p>';
        $offsite=StayCore_Supabase_Offsite::status();
        echo '<p><strong>Supabase offsite:</strong> '.esc_html(!$offsite['enabled']?'Not enabled':(!$offsite['configured']?'Settings incomplete':'Configured')).' · queued: '.(int)$offsite['pending'].' · oldest queued: '.(int)ceil($offsite['oldest_pending_seconds']/60).' min · verified (UTC): '.esc_html($offsite['last_verified_at']?:'none').'</p>';
        if($offsite['enabled'] && (!$offsite['configured'] || $offsite['oldest_pending_seconds']>1800))
            echo '<div class="notice notice-error"><p>Supabase Storage backups need attention. Verify the dedicated project, private bucket and cron worker; never discard local backups.</p></div>';
        $backup=StayCore_Private_Backups::status();
        if(!empty($backup['ready'])){
            echo '<div class="notice notice-success"><p><strong>Private booking backup vault is ready.</strong> Verify separate off-host copies of its key and encrypted files.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p><strong>Booking backup vault NOT READY.</strong> Writes are configured to fail closed rather than save unprotected bookings. Check a writable directory outside the public site and the server crypto extension.</p></div>';
        }
        if(!defined('STAYCORE_RECOVERY_ENABLED') || STAYCORE_RECOVERY_ENABLED!==true){
            echo '<div class="notice notice-warning"><p><strong>Restoration is disabled.</strong> Enable only after staging restore testing and a verified independent backup.</p></div>';
        }
        if(isset($_GET['restored'])) echo '<div class="notice notice-success"><p>Audited booking recovery committed. Review the new booking history.</p></div>';
        if(isset($_GET['checkpoint'])) echo '<div class="notice notice-'.($_GET['checkpoint']==='complete'?'success':'error').'"><p>'.($_GET['checkpoint']==='complete'?'Private booking checkpoint completed.':'Checkpoint failed for one or more bookings; investigate immediately.').' Archived: '.(int)($_GET['archived']??0).'</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="staycore_recovery_checkpoint">';
        wp_nonce_field('staycore_recovery_checkpoint');
        echo '<p><button type="submit" class="button">Create encrypted checkpoint of every current booking</button></p></form>';
        $booking=absint($_GET['booking_id']??0);
        echo '<form method="get" action="'.esc_url(admin_url('tools.php')).'"><input type="hidden" name="page" value="staycore-booking-recovery"><label>Booking ID <input type="number" name="booking_id" min="1" value="'.esc_attr((string)$booking).'"></label> <button class="button">Find revisions</button></form>';
        if($booking){
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,action,created_at,user_id FROM {$t['revisions']} WHERE reservation_id=%d ORDER BY id DESC LIMIT 100",$booking
            ),ARRAY_A);
            echo '<h2>Saved booking revisions</h2>';
            if(!$rows) echo '<p>No saved revisions.</p>';
            foreach((array)$rows as $row){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:8px 0">';
                echo '<input type="hidden" name="action" value="staycore_recovery_preview">';
                echo '<input type="hidden" name="booking_id" value="'.(int)$booking.'">';
                echo '<input type="hidden" name="revision_id" value="'.(int)$row['id'].'">';
                wp_nonce_field('staycore_recovery_preview');
                echo '<button class="button">Preview revision #'.(int)$row['id'].' — '.esc_html((string)$row['action']).' — '.esc_html((string)$row['created_at']).'</button></form>';
            }
        }
        $ticket=sanitize_text_field(wp_unslash($_GET['ticket']??''));
        $review=preg_match('/^[a-f0-9]{40}$/D',$ticket)?get_transient('staycore_restore_'.hash('sha256',$ticket)):false;
        if(is_array($review) && (int)$review['user_id']===get_current_user_id()){
            echo '<hr><h2>Recovery audit — booking #'.(int)$review['booking'].'</h2>';
            echo '<p>Revision #'.(int)$review['revision'].'. Review all proposed changes before proceeding.</p><table class="widefat striped"><thead><tr><th>Field</th><th>Current</th><th>Recover</th></tr></thead><tbody>';
            foreach((array)$review['diff'] as $name=>$diff){
                echo '<tr><td>'.esc_html((string)$name).'</td><td>'.esc_html((string)$diff['current']).'</td><td>'.esc_html((string)$diff['recovered']).'</td></tr>';
            }
            echo '</tbody></table>';
            if($review['blocked']) echo '<div class="notice notice-error"><p>'.esc_html((string)$review['blocked']).'</p></div>';
            if(!$review['blocked']){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                echo '<input type="hidden" name="action" value="staycore_recovery_apply"><input type="hidden" name="ticket" value="'.esc_attr($ticket).'">';
                wp_nonce_field('staycore_recovery_apply');
                echo '<p><label>Audit reason and approval notes (required)<br><textarea name="reason" rows="3" cols="72" required minlength="12"></textarea></label></p>';
                echo '<p><label>Type <strong>RESTORE BOOKING '.(int)$review['booking'].'</strong> to confirm<br><input name="confirmation" size="35" required></label></p>';
                echo '<p><button class="button button-primary" type="submit">Apply audited restore</button></p></form>';
            }
        }
        echo '</div>';
    }
}
