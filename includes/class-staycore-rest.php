<?php
if (!defined('ABSPATH')) exit;

final class StayCore_REST {
    public static function boot(): void {
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_filter('rest_post_dispatch',[__CLASS__,'privacy_headers'],10,3);
    }

    public static function privacy_headers($response,$server,$request) {
        $route=(string)$request->get_route();
        if(str_starts_with($route,'/staycore/v1/')){
            $response=rest_ensure_response($response);
            $response->header('Cache-Control','private, no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma','no-cache');
        }
        if(str_starts_with($route,'/staycore/v1/self-checkin/') || str_starts_with($route,'/staycore/v1/feedback/') || str_contains($route,'/id-image')){
            $response->header('X-Robots-Tag','noindex, nofollow, noarchive');
            $response->header('Referrer-Policy','no-referrer');
            $response->header('X-Content-Type-Options','nosniff');
        }
        return $response;
    }
    public static function can_view(): bool { return StayCore_Access::can('staycore_view_pms'); }
    public static function can_reservations(): bool { return StayCore_Access::can('staycore_manage_reservations'); }
    public static function can_guests(): bool { return StayCore_Access::can('staycore_view_guests'); }
    public static function can_guest_contact(): bool { return StayCore_Access::can('staycore_view_guest_contact'); }
    public static function can_guest_id(): bool { return StayCore_Access::can('staycore_view_guest_id'); }
    public static function can_upload_guest_id(): bool { return StayCore_Access::can('staycore_upload_guest_id'); }
    public static function can_payments(): bool { return StayCore_Access::can('staycore_manage_payments'); }
    public static function can_checkout(): bool { return StayCore_Access::can('staycore_checkout'); }
    public static function can_housekeeping(): bool { return StayCore_Access::can('staycore_manage_housekeeping'); }
    public static function can_reports(): bool { return StayCore_Access::can('staycore_view_reports'); }
    public static function can_activity(): bool { return StayCore_Access::can('staycore_view_activity'); }
    public static function can_staff(): bool { return StayCore_Access::can('staycore_manage_staff'); }
    public static function can_settings(): bool { return StayCore_Access::can('staycore_manage_settings'); }
    public static function can_manage(): bool { return StayCore_Access::can('manage_staycore_pms'); }

    public static function routes(): void {
        register_rest_route('staycore/v1','/health',['methods'=>'GET','callback'=>static fn()=>rest_ensure_response([
            'status'=>'ok',
            'version'=>STAYCORE_PMS_VERSION,
            'features'=>[
                'camera_and_gallery_id_capture'=>true,
                'explicit_country_code'=>true,
                'verified_id_binding'=>true,
                'checkin_identity_gate'=>true,
                'safe_returning_guest_match'=>true,
                'guest_blacklist_gate'=>true,
                'booking_image_ocr_v2'=>true,
                'booking_id_photo_upload'=>true,
                'processing_loaders'=>true,
                'whatsapp_no_blank_tab_android'=>true,
                'field_validation_v2'=>true,
                'submit_error_summary'=>true,
                'simplified_form_labels'=>true,
                'non_gated_review_flow'=>true,
                'strict_staff_pin_validation'=>true,
                'ios_safari_photo_normalization'=>true,
                'ocr_optional_for_id_upload'=>true,
                'ios_whatsapp_same_tab'=>true,
                'legacy_dialog_fallback'=>true,
                'exact_self_checkin_time_gate'=>true,
            ],
        ]),'permission_callback'=>'__return_true']);
        register_rest_route('staycore/v1','/dashboard',['methods'=>'GET','callback'=>[__CLASS__,'dashboard'],'permission_callback'=>[__CLASS__,'can_view']]);
        register_rest_route('staycore/v1','/units',[
            ['methods'=>'GET','callback'=>[__CLASS__,'units'],'permission_callback'=>[__CLASS__,'can_view']],
            ['methods'=>'POST','callback'=>[__CLASS__,'create_unit'],'permission_callback'=>[__CLASS__,'can_manage']],
        ]);
        register_rest_route('staycore/v1','/housekeeping',['methods'=>'POST','callback'=>[__CLASS__,'set_housekeeping'],'permission_callback'=>[__CLASS__,'can_housekeeping']]);
        register_rest_route('staycore/v1','/guest-lookup',['methods'=>'GET','callback'=>[__CLASS__,'guest_lookup'],'permission_callback'=>[__CLASS__,'can_guests']]);
        register_rest_route('staycore/v1','/guests/(?P<id>\\d+)/id-image',[
            ['methods'=>'GET','callback'=>[__CLASS__,'guest_id_image'],'permission_callback'=>[__CLASS__,'can_guest_id']],
            ['methods'=>'POST','callback'=>[__CLASS__,'save_guest_id_image'],'permission_callback'=>[__CLASS__,'can_upload_guest_id']],
        ]);
        register_rest_route('staycore/v1','/guests/(?P<id>\\d+)/blacklist',['methods'=>'POST','callback'=>[__CLASS__,'set_guest_blacklist'],'permission_callback'=>[__CLASS__,'can_manage']]);
        register_rest_route('staycore/v1','/self-checkin/(?P<id>\\d+)',[
            ['methods'=>'GET','callback'=>[__CLASS__,'self_checkin_get'],'permission_callback'=>'__return_true'],
            ['methods'=>'POST','callback'=>[__CLASS__,'self_checkin_post'],'permission_callback'=>'__return_true'],
        ]);
        register_rest_route('staycore/v1','/feedback/(?P<id>\\d+)',[
            ['methods'=>'GET','callback'=>[__CLASS__,'feedback_get'],'permission_callback'=>'__return_true'],
            ['methods'=>'POST','callback'=>[__CLASS__,'feedback_post'],'permission_callback'=>'__return_true'],
        ]);
        register_rest_route('staycore/v1','/reservations',[
            ['methods'=>'GET','callback'=>[__CLASS__,'reservations'],'permission_callback'=>[__CLASS__,'can_view']],
            ['methods'=>'POST','callback'=>[__CLASS__,'create_reservation'],'permission_callback'=>[__CLASS__,'can_reservations']],
        ]);
        register_rest_route('staycore/v1','/reservations/(?P<id>\d+)',[
            ['methods'=>'GET','callback'=>[__CLASS__,'reservation_detail'],'permission_callback'=>[__CLASS__,'can_view']],
            ['methods'=>'PUT','callback'=>[__CLASS__,'update_reservation'],'permission_callback'=>[__CLASS__,'can_reservations']],
        ]);
        register_rest_route('staycore/v1','/reservations/(?P<id>\d+)/status',['methods'=>'POST','callback'=>[__CLASS__,'set_status'],'permission_callback'=>[__CLASS__,'can_reservations']]);
        register_rest_route('staycore/v1','/reservations/(?P<id>\d+)/move',['methods'=>'POST','callback'=>[__CLASS__,'move_unit'],'permission_callback'=>[__CLASS__,'can_reservations']]);
        register_rest_route('staycore/v1','/reservations/(?P<id>\d+)/payments',[
            ['methods'=>'GET','callback'=>[__CLASS__,'payments'],'permission_callback'=>[__CLASS__,'can_payments']],
            ['methods'=>'POST','callback'=>[__CLASS__,'add_payment'],'permission_callback'=>[__CLASS__,'can_payments']],
        ]);
        register_rest_route('staycore/v1','/activity',['methods'=>'GET','callback'=>[__CLASS__,'activity'],'permission_callback'=>[__CLASS__,'can_activity']]);
        register_rest_route('staycore/v1','/integrations',['methods'=>'GET','callback'=>fn()=>rest_ensure_response(StayCore_Integrations::all()),'permission_callback'=>[__CLASS__,'can_settings']]);
    }

    private static function payment_summary(int $reservation_id): array {
        global $wpdb; $t=StayCore_DB::tables();
        $captured=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$t['payments']} WHERE reservation_id=%d AND status='captured'",$reservation_id));
        $total=(float)$wpdb->get_var($wpdb->prepare("SELECT total FROM {$t['reservations']} WHERE id=%d",$reservation_id));
        $balance=max(0,$total-$captured);
        $status=$total<=0?'not_set':($captured<=0?'pending':($balance>0.009?'partial':'paid'));
        $summary=compact('total','captured','balance','status');
        return apply_filters('staycore_pms_payment_summary',$summary,$reservation_id);
    }

    private static function assignments(int $reservation_id): array {
        global $wpdb; $t=StayCore_DB::tables();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ru.id assignment_id,ru.unit_id,ru.guests,u.name,u.type,u.room_group,u.capacity,u.status,u.housekeeping_status FROM {$t['reservation_units']} ru JOIN {$t['units']} u ON u.id=ru.unit_id WHERE ru.reservation_id=%d ORDER BY u.room_group,u.name",
            $reservation_id
        ),ARRAY_A);
    }

    private static function reservation_row(int $id): ?array {
        global $wpdb; $t=StayCore_DB::tables();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT r.*,CONCAT(g.first_name,' ',COALESCE(g.last_name,'')) guest_name,g.first_name,g.last_name,g.phone,g.email,g.nationality,g.id_type,g.id_number,g.notes guest_notes FROM {$t['reservations']} r LEFT JOIN {$t['guests']} g ON g.id=r.guest_id WHERE r.id=%d",
            $id
        ),ARRAY_A);
        if(!$row) return null;
        $row['assignments']=self::assignments($id);
        $row['payment']=self::payment_summary($id);
        $row['self_checkin_url']=self::self_checkin_url($row);
        $row['feedback_url']=self::feedback_url($row);
        $guest_meta=self::guest_meta((int)$row['guest_id']);
        $row['has_id_image']=!empty($guest_meta['id_image']['data']);
        $blacklist=self::guest_blacklist((int)$row['guest_id']);
        $row['is_blacklisted']=$blacklist['active'];
        $row['blacklist_reason']=$blacklist['reason'];
        $row['blacklist_updated_at']=$blacklist['updated_at'];
        return $row;
    }

    private static function redact_reservation_for_current_user(array $row): array {
        $can_guest=self::can_guests();
        $can_contact=self::can_guest_contact();
        $can_id=self::can_guest_id();
        $can_money=self::can_payments();

        if(!$can_guest){
            $row['guest_name']='Occupied';
            foreach(['first_name','last_name','phone','email','nationality','id_type','id_number','guest_notes','notes','meta','external_ref','self_checkin_url','feedback_url','has_id_image','is_blacklisted','blacklist_reason','blacklist_updated_at'] as $key) unset($row[$key]);
        } else {
            if(!$can_contact){
                foreach(['phone','email','guest_notes','notes','self_checkin_url','feedback_url'] as $key) unset($row[$key]);
            }
            if(!$can_id){
                foreach(['nationality','id_type','id_number','has_id_image'] as $key) unset($row[$key]);
            }
        }
        if(!$can_money){
            foreach(['total','currency','payment','payments'] as $key) unset($row[$key]);
        }
        return $row;
    }

    private static function local_datetime(string $value): ?DateTimeImmutable {
        try {
            return new DateTimeImmutable($value, wp_timezone());
        } catch (Exception $e) {
            return null;
        }
    }

    private static function public_link_expired(array $row,string $kind): bool {
        $now=current_time('timestamp');
        if($kind==='checkin'){
            $expires=strtotime((string)$row['check_out'].' +1 day');
            return $expires!==false && $now>$expires;
        }
        $expires=strtotime((string)$row['check_out'].' +30 days');
        return $expires!==false && $now>$expires;
    }

    private static function active_overlap(int $unit_id,string $check_in,string $check_out,int $exclude=0): int {
        global $wpdb; $t=StayCore_DB::tables();
        $sql="SELECT r.id FROM {$t['reservations']} r JOIN {$t['reservation_units']} ru ON ru.reservation_id=r.id WHERE ru.unit_id=%d AND r.status NOT IN ('cancelled','no_show','checked_out') AND r.check_in < %s AND r.check_out > %s";
        $args=[$unit_id,$check_out,$check_in];
        if($exclude){ $sql.=" AND r.id<>%d"; $args[]=$exclude; }
        $sql.=" LIMIT 1";
        return (int)$wpdb->get_var($wpdb->prepare($sql,...$args));
    }

    private static function normalize_unit_ids(array $p): array {
        $ids=[];
        if(isset($p['unit_ids']) && is_array($p['unit_ids'])) $ids=array_map('absint',$p['unit_ids']);
        elseif(!empty($p['unit_id'])) $ids=[absint($p['unit_id'])];
        return array_values(array_unique(array_filter($ids)));
    }

    private static function phone_digits(string $phone): string {
        return preg_replace('/\\D+/', '', $phone) ?: '';
    }

    private static function find_guest_row(string $phone='',string $email=''): ?array {
        global $wpdb; $t=StayCore_DB::tables();
        $email=sanitize_email($email); $phone=sanitize_text_field($phone);
        if($email){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['guests']} WHERE email=%s ORDER BY id DESC LIMIT 1",$email),ARRAY_A);
            if($row) return $row;
        }
        if($phone){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['guests']} WHERE phone=%s ORDER BY id DESC LIMIT 1",$phone),ARRAY_A);
            if($row) return $row;
            $needle=self::phone_digits($phone);
            if(strlen($needle)>=8){
                $rows=$wpdb->get_results("SELECT * FROM {$t['guests']} WHERE phone IS NOT NULL AND phone<>'' ORDER BY id DESC LIMIT 500",ARRAY_A);
                foreach($rows as $candidate){
                    $digits=self::phone_digits((string)$candidate['phone']);
                    if($digits===$needle || (strlen($digits)>=10 && strlen($needle)>=10 && substr($digits,-10)===substr($needle,-10))) return $candidate;
                }
            }
        }
        return null;
    }

    private static function unit_matches_stay_type(array $unit,string $stay_type): bool {
        $stay_type=sanitize_key($stay_type ?: 'dorm_any');
        if($stay_type==='private') return $unit['type']==='room';
        if($unit['type']!=='bed') return false;
        $group=strtolower((string)$unit['room_group']);
        $non_ac=str_contains($group,'non-ac') || str_contains($group,'non ac');
        if($stay_type==='non_ac_dorm') return $non_ac;
        if($stay_type==='ac_dorm') return !$non_ac && str_contains($group,'ac dorm');
        return true;
    }

    private static function auto_assign_units(string $check_in,string $check_out,int $people,string $stay_type) {
        global $wpdb; $t=StayCore_DB::tables();
        $people=max(1,$people); $stay_type=sanitize_key($stay_type ?: 'dorm_any');
        $same_day_or_past=substr($check_in,0,10)<=current_time('Y-m-d');
        $units=$wpdb->get_results("SELECT * FROM {$t['units']} WHERE status='available' AND housekeeping_status<>'maintenance' ORDER BY room_group,name",ARRAY_A);
        $eligible=[];
        foreach($units as $unit){
            if(!self::unit_matches_stay_type($unit,$stay_type)) continue;
            if($same_day_or_past && ($unit['housekeeping_status']??'clean')!=='clean') continue;
            if(self::active_overlap((int)$unit['id'],$check_in,$check_out)) continue;
            $eligible[]=$unit;
        }

        if($stay_type==='private'){
            $eligible=array_values(array_filter($eligible,fn($u)=>(int)$u['capacity'] >= $people));
            usort($eligible,function($a,$b){ $cap=(int)$a['capacity']<=>(int)$b['capacity']; return $cap ?: strnatcasecmp($a['name'],$b['name']); });
            return $eligible ? [(int)$eligible[0]['id']] : new WP_Error('no_inventory','No suitable private room is available for those dates.',['status'=>409]);
        }

        $groups=[];
        foreach($eligible as $unit){
            $key=(string)$unit['room_group'];
            if(!isset($groups[$key])) $groups[$key]=['name'=>$key,'available'=>[],'occupied'=>0];
            $groups[$key]['available'][]=$unit;
        }
        foreach($groups as $key=>&$group){
            $group['occupied']=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT ru.unit_id) FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id JOIN {$t['units']} u ON u.id=ru.unit_id WHERE u.room_group=%s AND u.type='bed' AND r.status NOT IN ('cancelled','no_show','checked_out') AND r.check_in<%s AND r.check_out>%s",
                $key,$check_out,$check_in
            ));
            usort($group['available'],fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        }
        unset($group);
        $groups=array_values($groups);
        usort($groups,function($a,$b){ $occ=$b['occupied']<=>$a['occupied']; if($occ) return $occ; $avail=count($a['available'])<=>count($b['available']); return $avail ?: strnatcasecmp($a['name'],$b['name']); });

        foreach($groups as $group){
            if(count($group['available']) >= $people) return array_map(fn($u)=>(int)$u['id'],array_slice($group['available'],0,$people));
        }
        $selected=[];
        foreach($groups as $group){
            foreach($group['available'] as $unit){ $selected[]=(int)$unit['id']; if(count($selected)>=$people) return $selected; }
        }
        return new WP_Error('no_inventory','Not enough suitable dorm beds are available for those dates.',['status'=>409]);
    }

    private static function self_checkin_token(array $row): string {
        return hash_hmac('sha256','staycore-checkin|'.(int)$row['id'].'|'.(int)$row['guest_id'].'|'.(string)$row['created_at'],wp_salt('auth'));
    }

    private static function self_checkin_url(array $row): string {
        $page=(int)get_option('staycore_self_checkin_page_id',0);
        $base=$page?get_permalink($page):home_url('/self-check-in/');
        return add_query_arg(['booking'=>(int)$row['id'],'token'=>self::self_checkin_token($row)],$base);
    }

    private static function valid_self_checkin_token(array $row,string $token): bool {
        return $token!=='' && hash_equals(self::self_checkin_token($row),$token);
    }

    private static function merge_reservation_meta(int $id,array $changes): void {
        global $wpdb; $t=StayCore_DB::tables();
        $raw=$wpdb->get_var($wpdb->prepare("SELECT meta FROM {$t['reservations']} WHERE id=%d",$id));
        $meta=is_string($raw)&&$raw!==''?json_decode($raw,true):[];
        if(!is_array($meta)) $meta=[];
        foreach($changes as $k=>$v) $meta[$k]=$v;
        $wpdb->update($t['reservations'],['meta'=>wp_json_encode($meta),'updated_at'=>current_time('mysql')],['id'=>$id]);
    }

    private static function guest_meta(int $guest_id): array {
        global $wpdb; $t=StayCore_DB::tables();
        $raw=$wpdb->get_var($wpdb->prepare("SELECT meta FROM {$t['guests']} WHERE id=%d",$guest_id));
        $meta=is_string($raw)&&$raw!==''?json_decode($raw,true):[];
        return is_array($meta)?$meta:[];
    }

    private static function merge_guest_meta(int $guest_id,array $changes): void {
        global $wpdb; $t=StayCore_DB::tables();
        $meta=self::guest_meta($guest_id);
        foreach($changes as $k=>$v) $meta[$k]=$v;
        $wpdb->update($t['guests'],['meta'=>wp_json_encode($meta),'updated_at'=>current_time('mysql')],['id'=>$guest_id]);
    }

    private static function guest_blacklist(int $guest_id): array {
        $meta=self::guest_meta($guest_id);
        $b=$meta['blacklist']??[];
        if(!is_array($b)) $b=[];
        return [
            'active'=>!empty($b['active']),
            'reason'=>sanitize_text_field((string)($b['reason']??'')),
            'updated_at'=>sanitize_text_field((string)($b['updated_at']??'')),
            'updated_by'=>absint($b['updated_by']??0),
        ];
    }

    private static function is_guest_blacklisted(int $guest_id): bool {
        return !empty(self::guest_blacklist($guest_id)['active']);
    }

    private static function find_blacklisted_guest(string $phone='',string $email='',string $id_number=''): ?array {
        global $wpdb; $t=StayCore_DB::tables();
        $candidates=[];
        $phone=sanitize_text_field($phone); $email=sanitize_email($email); $id_number=trim($id_number);
        if($phone!=='' || $email!==''){
            $row=self::find_guest_row($phone,$email);
            if($row) $candidates[(int)$row['id']]=$row;
        }
        if($id_number!==''){
            $needle=self::normalize_id_number($id_number);
            $rows=$wpdb->get_results("SELECT * FROM {$t['guests']} WHERE id_number IS NOT NULL AND id_number<>'' ORDER BY id DESC LIMIT 500",ARRAY_A);
            foreach($rows as $row){
                if(self::normalize_id_number((string)$row['id_number'])===$needle) $candidates[(int)$row['id']]=$row;
            }
        }
        foreach($candidates as $row) if(self::is_guest_blacklisted((int)$row['id'])) return $row;
        return null;
    }

    public static function set_guest_blacklist(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables();
        $guest_id=absint($request['id']);
        $guest=$wpdb->get_row($wpdb->prepare("SELECT id,first_name,last_name FROM {$t['guests']} WHERE id=%d",$guest_id),ARRAY_A);
        if(!$guest) return new WP_Error('not_found','Guest not found.',['status'=>404]);
        $p=$request->get_json_params();
        $active=!empty($p['active']);
        $reason=sanitize_text_field((string)($p['reason']??''));
        if($active && strlen(trim($reason))<3) return new WP_Error('reason_required','Add a short reason before blacklisting this guest.',['status'=>400]);
        $record=[
            'active'=>$active,
            'reason'=>$active?$reason:'',
            'updated_at'=>current_time('mysql'),
            'updated_by'=>get_current_user_id(),
        ];
        self::merge_guest_meta($guest_id,['blacklist'=>$record]);
        StayCore_DB::log($active?'guest_blacklisted':'guest_blacklist_removed','guest',$guest_id,$active?'Guest blacklisted.':'Guest removed from blacklist.',['reason'=>$record['reason']]);
        StayCore_Integrations::emit($active?'guest_blacklisted':'guest_blacklist_removed',['guest_id'=>$guest_id,'reason'=>$record['reason']]);
        return rest_ensure_response(['guest_id'=>$guest_id,'blacklist'=>$record]);
    }

    private static function feedback_token(array $row): string {
        return hash_hmac('sha256','staycore-feedback|'.(int)$row['id'].'|'.(int)$row['guest_id'].'|'.(string)$row['created_at'],wp_salt('auth'));
    }

    private static function feedback_url(array $row): string {
        $page=(int)get_option('staycore_feedback_page_id',0);
        $base=$page?get_permalink($page):home_url('/stay-feedback/');
        return add_query_arg(['booking'=>(int)$row['id'],'token'=>self::feedback_token($row)],$base);
    }

    private static function valid_feedback_token(array $row,string $token): bool {
        return $token!=='' && hash_equals(self::feedback_token($row),$token);
    }

    private static function feedback_config(bool $include_management=false): array {
        $s=get_option('staycore_pms_settings',[]);
        $property_name=sanitize_text_field($s['property_name']??get_bloginfo('name'));
        $review=esc_url_raw($s['review_url']??'');
        if(!$review) $review='https://www.google.com/maps/search/?api=1&query='.rawurlencode($property_name);
        $out=[
            'property_name'=>$property_name,
            'review_url'=>$review,
            'instagram_url'=>esc_url_raw($s['instagram_url']??''),
            'site_url'=>home_url('/'),
        ];
        if($include_management) $out['management_whatsapp']=preg_replace('/\\D+/', '', (string)($s['management_whatsapp']??''));
        return $out;
    }

    private static function valid_international_phone(string $phone): bool {
        return (bool)preg_match('/^\\+[1-9]\\d{7,14}$/',trim($phone));
    }

    private static function build_international_phone(string $country_code,string $local) {
        $cc=preg_replace('/\\D+/','',$country_code) ?: '';
        $local=preg_replace('/\\D+/','',$local) ?: '';
        $local=ltrim($local,'0');
        if(!preg_match('/^[1-9]\\d{0,2}$/',$cc)) return new WP_Error('country_code_required','Choose a valid country calling code.',['status'=>400]);
        if(!preg_match('/^\\d{6,12}$/',$local)) return new WP_Error('phone_required','Enter a valid local mobile number without the country code.',['status'=>400]);
        $phone='+'.$cc.$local;
        if(!self::valid_international_phone($phone)) return new WP_Error('phone_required','The full mobile number is not valid. Check the country code and number.',['status'=>400]);
        return $phone;
    }

    private static function normalize_id_number(string $value): string {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/','',$value) ?: '');
    }

    private static function id_number_hash(string $value): string {
        return hash_hmac('sha256',self::normalize_id_number($value),wp_salt('auth'));
    }

    private static function valid_guest_id_image(int $guest_id,string $id_number,array $image): bool {
        if(empty($image['data']) || empty($image['mime'])) return false;
        if((int)($image['guest_id']??0)!==$guest_id) return false;
        $stored_hash=(string)($image['id_number_hash']??'');
        if($stored_hash==='' || !hash_equals($stored_hash,self::id_number_hash($id_number))) return false;
        return true;
    }

    private static function checkin_requirements(array $row): array {
        $missing=[];
        if(!self::valid_international_phone((string)($row['phone']??''))) $missing[]='phone';
        if(trim((string)($row['nationality']??''))==='') $missing[]='nationality';
        if(trim((string)($row['id_type']??''))==='' || self::normalize_id_number((string)($row['id_number']??''))==='') $missing[]='id';
        $guest_id=(int)($row['guest_id']??0);
        $image=self::guest_meta($guest_id)['id_image']??[];
        if(!is_array($image) || !self::valid_guest_id_image($guest_id,(string)($row['id_number']??''),$image)) $missing[]='id_image';
        return array_values(array_unique($missing));
    }

    private static function parse_id_image(string $data_url) {
        if(!preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#',$data_url,$m)) return new WP_Error('bad_id_image','Add a clear JPG, PNG or WebP image of the ID.',['status'=>400]);
        $bytes=base64_decode($m[2],true);
        if($bytes===false || strlen($bytes)<20000) return new WP_Error('bad_id_image','The ID image is too small or unreadable. Use a clearer image with the full ID in frame.',['status'=>400]);
        if(strlen($bytes)>2097152) return new WP_Error('id_image_too_large','The ID image is too large. Use a normal-resolution image.',['status'=>413]);
        $info=@getimagesizefromstring($bytes);
        if(!$info || empty($info[0]) || empty($info[1])) return new WP_Error('bad_id_image','The selected file is not a readable image. Use another ID image.',['status'=>400]);
        $actual=image_type_to_mime_type((int)$info[2]);
        if(!in_array($actual,['image/jpeg','image/png','image/webp'],true)) return new WP_Error('bad_id_image','The captured ID image format is not supported.',['status'=>400]);
        $short=min((int)$info[0],(int)$info[1]); $long=max((int)$info[0],(int)$info[1]);
        if($short<400 || $long<640) return new WP_Error('id_image_resolution','Use a higher-resolution ID image so the document is readable.',['status'=>400]);
        return ['mime'=>$actual,'data'=>base64_encode($bytes),'width'=>(int)$info[0],'height'=>(int)$info[1],'updated_at'=>current_time('mysql')];
    }

    private static function store_guest_id_image(int $guest_id,int $reservation_id,array $p) {
        global $wpdb; $t=StayCore_DB::tables();
        if(!self::can_upload_guest_id()) return new WP_Error('id_upload_forbidden','Your role cannot upload guest IDs.',['status'=>403]);
        $guest=$wpdb->get_row($wpdb->prepare("SELECT id,id_type,id_number,nationality FROM {$t['guests']} WHERE id=%d",$guest_id),ARRAY_A);
        if(!$guest) return new WP_Error('guest_not_found','Guest not found.',['status'=>404]);
        if($reservation_id){
            $owner=(int)$wpdb->get_var($wpdb->prepare("SELECT guest_id FROM {$t['reservations']} WHERE id=%d",$reservation_id));
            if($owner!==$guest_id) return new WP_Error('reservation_guest_mismatch','This booking does not belong to this guest.',['status'=>409]);
        }
        $id_type=sanitize_text_field((string)($p['id_type']??$guest['id_type']??''));
        $id_number=sanitize_text_field((string)($p['id_number']??$guest['id_number']??''));
        $nationality=sanitize_text_field((string)($p['nationality']??$guest['nationality']??''));
        $normalized=self::normalize_id_number($id_number);
        if(!in_array($id_type,['Passport','Aadhaar','Driving Licence','Other'],true)) return new WP_Error('id_required','Choose a valid ID type before saving the ID image.',['status'=>400]);
        if(strlen($normalized)<4 || strlen($normalized)>40) return new WP_Error('id_required','Enter a valid ID number before saving the ID image.',['status'=>400]);
        if($id_type==='Aadhaar' && !preg_match('/^\\d{12}$/',$normalized)) return new WP_Error('id_required','Aadhaar number must contain 12 digits.',['status'=>400]);
        if(empty($p['id_confirm'])) return new WP_Error('id_confirmation_required','Confirm that the selected image matches this guest and ID number.',['status'=>400]);
        $ocr=self::normalize_id_number((string)($p['ocr_id_number']??''));
        if($ocr!=='' && !hash_equals($ocr,$normalized)) return new WP_Error('id_mismatch','The ID number read from the image does not match the entered ID number.',['status'=>409]);
        $method=sanitize_key((string)($p['capture_method']??'gallery'));
        if(!in_array($method,['camera','gallery'],true)) $method='gallery';
        $image=self::parse_id_image((string)($p['id_image_data']??''));
        if(is_wp_error($image)) return $image;
        $image['guest_id']=$guest_id;
        $image['reservation_id']=$reservation_id;
        $image['capture_method']=$method;
        $image['id_number_hash']=self::id_number_hash($id_number);
        self::merge_guest_meta($guest_id,['id_image'=>$image]);
        $wpdb->update($t['guests'],[
            'id_type'=>$id_type,
            'id_number'=>$id_number,
            'nationality'=>$nationality,
            'updated_at'=>current_time('mysql'),
        ],['id'=>$guest_id]);
        StayCore_DB::log('guest_id_uploaded','guest',$guest_id,'Guest ID image saved.',['reservation_id'=>$reservation_id,'capture_method'=>$method]);
        return $image;
    }

    public static function save_guest_id_image(WP_REST_Request $request) {
        $guest_id=absint($request['id']); $p=$request->get_json_params();
        $reservation_id=absint($p['reservation_id']??0);
        $image=self::store_guest_id_image($guest_id,$reservation_id,$p);
        if(is_wp_error($image)) return $image;
        return rest_ensure_response(['saved'=>true,'guest_id'=>$guest_id,'reservation_id'=>$reservation_id,'capture_method'=>$image['capture_method']??'gallery','updated_at'=>$image['updated_at']??null]);
    }

    public static function dashboard(): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables(); $today=current_time('Y-m-d'); $start=$today.' 00:00:00'; $end=$today.' 23:59:59';
        $arrivals=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservations']} WHERE DATE(check_in)=%s AND status='confirmed'",$today));
        $departures=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservations']} WHERE DATE(check_out)=%s AND status='checked_in'",$today));
        $inhouse=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['reservations']} WHERE status='checked_in'");
        $arrival_units=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE DATE(r.check_in)=%s AND r.status='confirmed'",$today));
        $departure_units=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE DATE(r.check_out)=%s AND r.status='checked_in'",$today));
        $inhouse_units=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE r.status='checked_in'");
        $occupied=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE r.status IN ('confirmed','checked_in') AND r.check_in<=%s AND r.check_out>%s",$end,$start));
        $available=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['units']} u WHERE u.status='available' AND u.housekeeping_status='clean' AND NOT EXISTS (SELECT 1 FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE ru.unit_id=u.id AND r.status IN ('confirmed','checked_in') AND r.check_in<=%s AND r.check_out>%s)",$end,$start));
        $cleaning=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['units']} WHERE status='available' AND housekeeping_status IN ('dirty','cleaning')");
        $maintenance=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['units']} WHERE housekeeping_status='maintenance'");
        $expected=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total),0) FROM {$t['reservations']} WHERE status IN ('confirmed','checked_in') AND check_in<=%s AND check_out>%s",$end,$start));
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['reservations']} WHERE status IN ('confirmed','checked_in') AND check_in<=%s AND check_out>%s",$end,$start));
        $collected=0.0;
        if($ids){ $placeholders=implode(',',array_fill(0,count($ids),'%d')); $collected=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$t['payments']} WHERE status='captured' AND reservation_id IN ($placeholders)",...array_map('intval',$ids))); }

        $attention=[];
        $add_attention=static function(int $reservation_id,string $type,string $message,array $extra=[]) use (&$attention): void {
            if(!$reservation_id) return;
            if(!isset($attention[$reservation_id])) $attention[$reservation_id]=['reservation_id'=>$reservation_id,'issues'=>[]];
            $attention[$reservation_id]['issues'][]=array_merge(['type'=>$type,'message'=>$message],$extra);
        };

        $recovery=$wpdb->get_results("SELECT reservation_id FROM {$t['tasks']} WHERE type='service_recovery' AND status IN ('open','in_progress') ORDER BY id DESC LIMIT 50",ARRAY_A);
        foreach($recovery as $x) $add_attention((int)$x['reservation_id'],'service_recovery','Guest needs service recovery');

        $overdue=$wpdb->get_results($wpdb->prepare("SELECT id FROM {$t['reservations']} WHERE status='checked_in' AND check_out<%s ORDER BY check_out",current_time('mysql')),ARRAY_A);
        foreach($overdue as $x) $add_attention((int)$x['id'],'overdue','Checkout overdue');

        $active_guests=$wpdb->get_results("SELECT id,guest_id FROM {$t['reservations']} WHERE status IN ('confirmed','checked_in')",ARRAY_A);
        foreach($active_guests as $x) if(self::is_guest_blacklisted((int)$x['guest_id'])) $add_attention((int)$x['id'],'blacklist','Blacklisted guest on active booking');

        $unpaid=$wpdb->get_results($wpdb->prepare("SELECT id FROM {$t['reservations']} WHERE status IN ('confirmed','checked_in') AND check_in<=%s AND check_out>%s AND total>0",$end,$start),ARRAY_A);
        foreach($unpaid as $x){
            $ps=self::payment_summary((int)$x['id']);
            if(($ps['balance']??0)>0.009) $add_attention((int)$x['id'],'payment','Payment due',['balance'=>(float)$ps['balance']]);
        }

        if(self::can_reservations()){
            $missing=$wpdb->get_results($wpdb->prepare("SELECT r.id FROM {$t['reservations']} r JOIN {$t['guests']} g ON g.id=r.guest_id WHERE r.status IN ('confirmed','checked_in') AND r.check_in<=%s AND r.check_out>%s AND (g.phone IS NULL OR g.phone='')",$end,$start),ARRAY_A);
            foreach($missing as $x) $add_attention((int)$x['id'],'contact','Guest phone missing');

            foreach($attention as $id=>&$item){
                $row=self::reservation_row((int)$id);
                if(!$row) continue;
                $item['guest_name']=trim((string)($row['guest_name']??'Guest')) ?: 'Guest';
                $item['assignment']=implode(', ',array_column($row['assignments']??[],'name'));
                $item['status']=$row['status']??'';
                $item['check_out']=$row['check_out']??'';
            }
            unset($item);
        } else {
            $attention=[];
        }

        $attention=array_values($attention);
        usort($attention,static function(array $a,array $b): int {
            $weight=['blacklist'=>0,'service_recovery'=>1,'overdue'=>2,'payment'=>3,'contact'=>4];
            $aw=min(array_map(static fn($i)=>$weight[$i['type']]??9,$a['issues']??[]));
            $bw=min(array_map(static fn($i)=>$weight[$i['type']]??9,$b['issues']??[]));
            return $aw<=>$bw ?: ((int)$a['reservation_id']<=> (int)$b['reservation_id']);
        });
        $breakdown=['blacklist'=>0,'payment'=>0,'overdue'=>0,'service_recovery'=>0,'contact'=>0];
        foreach($attention as $item) foreach($item['issues'] as $issue) if(isset($breakdown[$issue['type']])) $breakdown[$issue['type']]++;

        $out=[
            'arrivals'=>$arrivals,'departures'=>$departures,'inhouse'=>$inhouse,
            'arrival_units'=>$arrival_units,'departure_units'=>$departure_units,'inhouse_units'=>$inhouse_units,'occupied_units'=>$occupied,
            'available_units'=>$available,'cleaning_units'=>$cleaning,'maintenance_units'=>$maintenance,
            'alerts'=>$attention,'attention_count'=>count($attention),'attention_breakdown'=>$breakdown,
        ];
        if(self::can_payments()) $out+=['expected_revenue'=>$expected,'collected'=>$collected,'balance'=>max(0,$expected-$collected)];
        return rest_ensure_response($out);
    }

    public static function units(): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables();
        $rows=$wpdb->get_results("SELECT * FROM {$t['units']} ORDER BY room_group,name",ARRAY_A);
        if(!self::can_payments()) foreach($rows as &$row) unset($row['base_rate']);
        return rest_ensure_response($rows);
    }

    public static function create_unit(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $p=$request->get_json_params(); $now=current_time('mysql');
        $data=['name'=>sanitize_text_field($p['name']??''),'type'=>sanitize_key($p['type']??'bed'),'room_group'=>sanitize_text_field($p['room_group']??''),'capacity'=>max(1,absint($p['capacity']??1)),'base_rate'=>(float)($p['base_rate']??0),'status'=>sanitize_key($p['status']??'available'),'housekeeping_status'=>'clean','created_at'=>$now,'updated_at'=>$now];
        if(!$data['name']) return new WP_Error('missing_name','Unit name is required.',['status'=>400]);
        $wpdb->insert($t['units'],$data); if(!$wpdb->insert_id) return new WP_Error('db_error','Could not create unit.',['status'=>500]);
        $id=(int)$wpdb->insert_id; StayCore_DB::log('unit_created','unit',$id,'Inventory unit created.',$data); StayCore_Integrations::emit('unit_created',['id'=>$id]+$data);
        return rest_ensure_response(['id'=>$id]+$data);
    }

    public static function set_housekeeping(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $p=$request->get_json_params(); $now=current_time('mysql');
        $ids=array_values(array_filter(array_map('absint',(array)($p['unit_ids']??[])))); $status=sanitize_key($p['status']??'');
        if(!$ids || !in_array($status,['clean','dirty','cleaning','maintenance'],true)) return new WP_Error('bad_housekeeping','Units and a valid housekeeping status are required.',['status'=>400]);
        foreach($ids as $id) {
            $unit=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$t['units']} WHERE id=%d",$id),ARRAY_A);
            if(!$unit) continue;
            $wpdb->update($t['units'],['housekeeping_status'=>$status,'updated_at'=>$now],['id'=>$id]);
            if($status==='dirty'){
                $open=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['tasks']} WHERE unit_id=%d AND type='housekeeping' AND status IN ('open','in_progress') LIMIT 1",$id));
                if(!$open) $wpdb->insert($t['tasks'],['unit_id'=>$id,'type'=>'housekeeping','title'=>'Clean '.$unit['name'],'status'=>'open','priority'=>'normal','due_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            } elseif($status==='cleaning'){
                $wpdb->query($wpdb->prepare("UPDATE {$t['tasks']} SET status='in_progress',updated_at=%s WHERE unit_id=%d AND type='housekeeping' AND status='open'",$now,$id));
            } elseif($status==='clean'){
                $wpdb->query($wpdb->prepare("UPDATE {$t['tasks']} SET status='done',updated_at=%s WHERE unit_id=%d AND type='housekeeping' AND status IN ('open','in_progress')",$now,$id));
            }
        }
        StayCore_DB::log('housekeeping_changed','unit',null,'Housekeeping status changed.',['unit_ids'=>$ids,'status'=>$status]);
        StayCore_Integrations::emit('housekeeping_status_changed',['unit_ids'=>$ids,'status'=>$status]);
        return rest_ensure_response(['unit_ids'=>$ids,'status'=>$status]);
    }

    public static function reservations(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables();
        $from=sanitize_text_field($request->get_param('from')?:current_time('Y-m-d')); $to=sanitize_text_field($request->get_param('to')?:gmdate('Y-m-d',strtotime($from.' +30 days')));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT r.*,CONCAT(g.first_name,' ',COALESCE(g.last_name,'')) guest_name,g.first_name,g.last_name,g.phone,g.email,g.nationality,g.id_type,g.id_number FROM {$t['reservations']} r LEFT JOIN {$t['guests']} g ON g.id=r.guest_id WHERE r.check_in < %s AND r.check_out >= %s ORDER BY r.check_in ASC",
            $to.' 23:59:59',$from.' 00:00:00'
        ),ARRAY_A);
        foreach($rows as &$row){
            $row['assignments']=self::assignments((int)$row['id']);
            if(self::can_payments()) $row['payment']=self::payment_summary((int)$row['id']);
            $blacklist=self::guest_blacklist((int)$row['guest_id']);
            $row['is_blacklisted']=$blacklist['active'];
            $row['blacklist_reason']=$blacklist['reason'];
            $row['blacklist_updated_at']=$blacklist['updated_at'];
            $row=self::redact_reservation_for_current_user($row);
        }
        return rest_ensure_response($rows);
    }

    public static function reservation_detail(WP_REST_Request $request) {
        $row=self::reservation_row(absint($request['id'])); if(!$row) return new WP_Error('not_found','Reservation not found.',['status'=>404]);
        global $wpdb; $t=StayCore_DB::tables();
        if(self::can_payments()) $row['payments']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['payments']} WHERE reservation_id=%d ORDER BY paid_at DESC,id DESC",(int)$row['id']),ARRAY_A);
        if(self::can_activity()) $row['activity']=$wpdb->get_results($wpdb->prepare("SELECT a.id,a.user_id,a.action,a.entity_type,a.entity_id,a.message,a.created_at,u.display_name user_name FROM {$t['activity']} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE a.entity_type='reservation' AND a.entity_id=%d ORDER BY a.id DESC LIMIT 30",(int)$row['id']),ARRAY_A);
        return rest_ensure_response(self::redact_reservation_for_current_user($row));
    }

    public static function create_reservation(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $p=apply_filters('staycore_pms_reservation_payload',$request->get_json_params()); $now=current_time('mysql');
        $first=sanitize_text_field($p['first_name']??''); $phone=sanitize_text_field($p['phone']??''); $email=sanitize_email($p['email']??'');
        $check_in=sanitize_text_field($p['check_in']??''); $check_out=sanitize_text_field($p['check_out']??'');
        $adults=max(1,absint($p['adults']??1)); $children=absint($p['children']??0); $people=$adults+$children;
        $stay_type=sanitize_key($p['stay_type']??'dorm_any'); $unit_ids=self::normalize_unit_ids($p);
        $auto_assign=array_key_exists('auto_assign',$p)?(bool)$p['auto_assign']:!$unit_ids;
        $was_auto_assign=!$unit_ids && $auto_assign;
        $pending_id_image=null;
        if(!empty($p['id_image_data'])){
            if(!self::can_upload_guest_id()) return new WP_Error('id_upload_forbidden','Your role cannot upload guest IDs.',['status'=>403]);
            $id_type=sanitize_text_field((string)($p['id_type']??''));
            $id_number=sanitize_text_field((string)($p['id_number']??''));
            $normalized=self::normalize_id_number($id_number);
            if(!in_array($id_type,['Passport','Aadhaar','Driving Licence','Other'],true)) return new WP_Error('id_required','Choose the ID type before creating this booking.',['status'=>400]);
            if(strlen($normalized)<4 || strlen($normalized)>40) return new WP_Error('id_required','Enter the ID number before creating this booking.',['status'=>400]);
            if($id_type==='Aadhaar' && !preg_match('/^\\d{12}$/',$normalized)) return new WP_Error('id_required','Aadhaar number must contain 12 digits.',['status'=>400]);
            if(empty($p['id_confirm'])) return new WP_Error('id_confirmation_required','Confirm the ID image matches this guest.',['status'=>400]);
            $ocr=self::normalize_id_number((string)($p['ocr_id_number']??''));
            if($ocr!=='' && !hash_equals($ocr,$normalized)) return new WP_Error('id_mismatch','The ID number read from the image does not match the entered ID number.',['status'=>409]);
            $pending_id_image=self::parse_id_image((string)$p['id_image_data']);
            if(is_wp_error($pending_id_image)) return $pending_id_image;
        }
        if(!$first || !$check_in || !$check_out) return new WP_Error('missing_fields','Guest name, check-in and check-out are required.',['status'=>400]);
        if(strtotime($check_out)<=strtotime($check_in)) return new WP_Error('bad_dates','Check-out must be after check-in.',['status'=>400]);
        $blacklisted_match=self::find_blacklisted_guest($phone,$email,sanitize_text_field($p['id_number']??''));
        if($blacklisted_match){
            $b=self::guest_blacklist((int)$blacklisted_match['id']);
            return new WP_Error('guest_blacklisted','This guest is blacklisted and cannot be booked.'.($b['reason']?' Reason: '.$b['reason']:''),['status'=>409,'guest_id'=>(int)$blacklisted_match['id'],'reason'=>$b['reason']]);
        }
        if($was_auto_assign){
            $assigned=self::auto_assign_units($check_in,$check_out,$people,$stay_type);
            if(is_wp_error($assigned)) return $assigned;
            $unit_ids=$assigned;
        }
        if(!$unit_ids) return new WP_Error('missing_units','Choose a room/bed or enable automatic assignment.',['status'=>400]);

        $capacity=0;
        foreach($unit_ids as $uid){
            $unit=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['units']} WHERE id=%d",$uid),ARRAY_A); if(!$unit) return new WP_Error('unit_not_found','A selected room or bed was not found.',['status'=>404]);
            if($unit['status']!=='available' || self::active_overlap($uid,$check_in,$check_out)) return new WP_Error('unit_unavailable',$unit['name'].' is unavailable for those dates.',['status'=>409]);
            if(substr($check_in,0,10)<=current_time('Y-m-d') && $unit['housekeeping_status']!=='clean') return new WP_Error('unit_not_ready',$unit['name'].' is vacant but not ready. Confirm cleaning with housekeeping before assigning.',['status'=>409,'unit_id'=>$uid,'housekeeping_status'=>$unit['housekeeping_status']]);
            $capacity+=max(1,(int)$unit['capacity']);
        }
        if(($adults+$children)>$capacity) return new WP_Error('capacity_exceeded','Selected units allow a maximum of '.$capacity.' guests.',['status'=>400]);

        $existing=null; $explicit_guest=!empty($p['guest_id']);
        if($explicit_guest) $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['guests']} WHERE id=%d",absint($p['guest_id'])),ARRAY_A);
        if($existing && self::is_guest_blacklisted((int)$existing['id'])){
            $b=self::guest_blacklist((int)$existing['id']);
            return new WP_Error('guest_blacklisted','This guest is blacklisted and cannot be booked.'.($b['reason']?' Reason: '.$b['reason']:''),['status'=>409,'guest_id'=>(int)$existing['id'],'reason'=>$b['reason']]);
        }
        if(!$existing && !$explicit_guest){
            $candidate=self::find_guest_row($phone,$email);
            if($candidate){
                $candidate_first=strtolower(trim((string)$candidate['first_name']));
                $incoming_first=strtolower(trim($first));
                $candidate_last=strtolower(trim((string)($candidate['last_name']??'')));
                $incoming_last=strtolower(trim(sanitize_text_field($p['last_name']??'')));
                $name_match=$candidate_first!=='' && $incoming_first!=='' && $candidate_first===$incoming_first && ($candidate_last==='' || $incoming_last==='' || $candidate_last===$incoming_last);
                if($name_match) $existing=$candidate;
            }
        }
        if($existing){
            $guest_id=(int)$existing['id']; $updates=['updated_at'=>$now];
            foreach(['first_name','last_name','nationality','id_type','id_number'] as $field){
                $value=sanitize_text_field($p[$field]??''); if($value!=='') $updates[$field]=$value;
            }
            if($phone!=='') $updates['phone']=$phone;
            if($email!=='') $updates['email']=$email;
            if(!empty($p['guest_notes'])) $updates['notes']=sanitize_textarea_field($p['guest_notes']);
            $wpdb->update($t['guests'],$updates,['id'=>$guest_id]);
        } else {
            $wpdb->insert($t['guests'],['first_name'=>$first,'last_name'=>sanitize_text_field($p['last_name']??''),'phone'=>$phone,'email'=>$email,'nationality'=>sanitize_text_field($p['nationality']??''),'id_type'=>sanitize_text_field($p['id_type']??''),'id_number'=>sanitize_text_field($p['id_number']??''),'notes'=>sanitize_textarea_field($p['guest_notes']??''),'created_at'=>$now,'updated_at'=>$now]);
            $guest_id=(int)$wpdb->insert_id;
        }

        $meta=['stay_type'=>$stay_type,'auto_assigned'=>$was_auto_assign];
        if(isset($p['meta'])&&is_array($p['meta'])) $meta=array_merge($p['meta'],$meta);
        $data=['guest_id'=>$guest_id,'unit_id'=>$unit_ids[0],'source'=>sanitize_key($p['source']??'direct'),'external_ref'=>sanitize_text_field($p['external_ref']??''),'check_in'=>$check_in,'check_out'=>$check_out,'adults'=>$adults,'children'=>$children,'status'=>sanitize_key($p['status']??'confirmed'),'total'=>(float)($p['total']??0),'currency'=>strtoupper(sanitize_text_field($p['currency']??'INR')),'notes'=>sanitize_textarea_field($p['notes']??''),'meta'=>wp_json_encode($meta),'created_at'=>$now,'updated_at'=>$now];
        $wpdb->insert($t['reservations'],$data); if(!$wpdb->insert_id) return new WP_Error('db_error','Could not create reservation.',['status'=>500]); $id=(int)$wpdb->insert_id;
        foreach($unit_ids as $uid) $wpdb->insert($t['reservation_units'],['reservation_id'=>$id,'unit_id'=>$uid,'guests'=>1,'created_at'=>$now]);
        if(is_array($pending_id_image)){
            $method=sanitize_key((string)($p['capture_method']??'gallery'));
            if(!in_array($method,['camera','gallery'],true)) $method='gallery';
            $pending_id_image['guest_id']=$guest_id;
            $pending_id_image['reservation_id']=$id;
            $pending_id_image['capture_method']=$method;
            $pending_id_image['id_number_hash']=self::id_number_hash((string)$p['id_number']);
            self::merge_guest_meta($guest_id,['id_image'=>$pending_id_image]);
            StayCore_DB::log('guest_id_uploaded','guest',$guest_id,'Guest ID image saved during booking creation.',['reservation_id'=>$id,'capture_method'=>$method]);
        }
        StayCore_DB::log('reservation_created','reservation',$id,'Reservation created.',['unit_ids'=>$unit_ids,'auto_assigned'=>$auto_assign,'stay_type'=>$stay_type,'returning_guest'=>(bool)$existing]);
        StayCore_Integrations::emit('reservation_created',['id'=>$id]+$data+['unit_ids'=>$unit_ids]);
        return rest_ensure_response(self::reservation_row($id));
    }

    public static function update_reservation(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $id=absint($request['id']); $existing=self::reservation_row($id); if(!$existing) return new WP_Error('not_found','Reservation not found.',['status'=>404]);
        $p=$request->get_json_params(); $check_in=sanitize_text_field($p['check_in']??$existing['check_in']); $check_out=sanitize_text_field($p['check_out']??$existing['check_out']);
        if(strtotime($check_out)<=strtotime($check_in)) return new WP_Error('bad_dates','Check-out must be after check-in.',['status'=>400]);
        $unit_ids=isset($p['unit_ids'])?self::normalize_unit_ids($p):array_map(fn($x)=>(int)$x['unit_id'],$existing['assignments']);
        if(!$unit_ids) return new WP_Error('missing_units','At least one room or bed is required.',['status'=>400]);
        foreach($unit_ids as $uid) if(self::active_overlap($uid,$check_in,$check_out,$id)) return new WP_Error('unit_unavailable','One of the selected units is unavailable for those dates.',['status'=>409]);
        $capacity=0; foreach($unit_ids as $uid) $capacity+=(int)$wpdb->get_var($wpdb->prepare("SELECT capacity FROM {$t['units']} WHERE id=%d",$uid));
        $adults=max(1,absint($p['adults']??$existing['adults'])); $children=absint($p['children']??$existing['children']);
        if(($adults+$children)>$capacity) return new WP_Error('capacity_exceeded','Selected units allow a maximum of '.$capacity.' guests.',['status'=>400]);
        $guest=['first_name'=>sanitize_text_field($p['first_name']??$existing['first_name']),'last_name'=>sanitize_text_field($p['last_name']??$existing['last_name']),'phone'=>sanitize_text_field($p['phone']??$existing['phone']),'email'=>sanitize_email($p['email']??$existing['email']),'nationality'=>sanitize_text_field($p['nationality']??$existing['nationality']),'id_type'=>sanitize_text_field($p['id_type']??$existing['id_type']),'id_number'=>sanitize_text_field($p['id_number']??$existing['id_number']),'notes'=>sanitize_textarea_field($p['guest_notes']??$existing['guest_notes']),'updated_at'=>current_time('mysql')];
        $wpdb->update($t['guests'],$guest,['id'=>(int)$existing['guest_id']]);
        $data=['unit_id'=>$unit_ids[0],'source'=>sanitize_key($p['source']??$existing['source']),'external_ref'=>sanitize_text_field($p['external_ref']??$existing['external_ref']),'check_in'=>$check_in,'check_out'=>$check_out,'adults'=>$adults,'children'=>$children,'status'=>$existing['status'],'total'=>(float)($p['total']??$existing['total']),'notes'=>sanitize_textarea_field($p['notes']??$existing['notes']),'updated_at'=>current_time('mysql')];
        $wpdb->update($t['reservations'],$data,['id'=>$id]);
        if(isset($p['unit_ids'])){ $wpdb->delete($t['reservation_units'],['reservation_id'=>$id]); foreach($unit_ids as $uid) $wpdb->insert($t['reservation_units'],['reservation_id'=>$id,'unit_id'=>$uid,'guests'=>1,'created_at'=>current_time('mysql')]); }
        StayCore_DB::log('reservation_updated','reservation',$id,'Reservation details updated.',['unit_ids'=>$unit_ids]); StayCore_Integrations::emit('reservation_updated',['id'=>$id]+$data+['unit_ids'=>$unit_ids]);
        return rest_ensure_response(self::reservation_row($id));
    }

    public static function move_unit(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $id=absint($request['id']); $p=$request->get_json_params(); $from=absint($p['from_unit_id']??0); $to=absint($p['to_unit_id']??0);
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['reservations']} WHERE id=%d",$id),ARRAY_A); if(!$r) return new WP_Error('not_found','Reservation not found.',['status'=>404]);
        $mapped=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['reservation_units']} WHERE reservation_id=%d AND unit_id=%d",$id,$from)); if(!$mapped) return new WP_Error('not_assigned','Source unit is not assigned to this booking.',['status'=>400]);
        $target=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['units']} WHERE id=%d",$to),ARRAY_A); if(!$target) return new WP_Error('unit_not_found','Destination unit not found.',['status'=>404]);
        if(strtotime($r['check_in'])<=current_time('timestamp') && $target['housekeeping_status']!=='clean') return new WP_Error('unit_not_ready',$target['name'].' is vacant but not ready. Confirm cleaning with housekeeping before assigning.',['status'=>409,'unit_id'=>$to,'housekeeping_status'=>$target['housekeeping_status']]);
        if(self::active_overlap($to,$r['check_in'],$r['check_out'],$id)) return new WP_Error('unit_unavailable',$target['name'].' is unavailable for those dates.',['status'=>409]);
        $wpdb->update($t['reservation_units'],['unit_id'=>$to],['reservation_id'=>$id,'unit_id'=>$from]); if((int)$r['unit_id']===$from) $wpdb->update($t['reservations'],['unit_id'=>$to,'updated_at'=>current_time('mysql')],['id'=>$id]);
        StayCore_DB::log('unit_moved','reservation',$id,'Guest moved to another room/bed.',['from_unit_id'=>$from,'to_unit_id'=>$to]); StayCore_Integrations::emit('reservation_unit_moved',['id'=>$id,'from_unit_id'=>$from,'to_unit_id'=>$to]);
        return rest_ensure_response(self::reservation_row($id));
    }

    public static function set_status(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $id=absint($request['id']); $status=sanitize_key(($request->get_json_params()['status']??'')); $now=current_time('mysql');
        if(!in_array($status,['confirmed','checked_in','checked_out','cancelled','no_show'],true)) return new WP_Error('bad_status','Invalid reservation status.',['status'=>400]);
        $assign=self::assignments($id);
        if($status==='checked_in'){
            $row=self::reservation_row($id);
            if(!$row) return new WP_Error('not_found','Reservation not found.',['status'=>404]);
            if(!empty($row['is_blacklisted'])) return new WP_Error('guest_blacklisted','This guest is blacklisted and cannot be checked in.'.(!empty($row['blacklist_reason'])?' Reason: '.$row['blacklist_reason']:''),['status'=>409,'reason'=>$row['blacklist_reason']??'']);
            $missing=self::checkin_requirements($row);
            if($missing) return new WP_Error('checkin_requirements','Complete guest phone, nationality, ID details and a verified ID photo before check-in.',['status'=>409,'missing'=>$missing]);
            foreach($assign as $a) if(($a['status']??'available')!=='available' || ($a['housekeeping_status']??'clean')!=='clean') return new WP_Error('unit_not_ready',$a['name'].' is not ready. Housekeeping must mark it clean before check-in.',['status'=>409]);
        }
        if($status==='checked_out'){
            if(!self::can_checkout()) return new WP_Error('checkout_forbidden','Your role is not allowed to check guests out.',['status'=>403]);
            $payment=self::payment_summary($id);
            $balance=max(0,(float)($payment['balance']??0));
            $clear=(bool)apply_filters('staycore_pms_checkout_payment_clear',$balance<=0.009,$id,$payment);
            if(!$clear){
                StayCore_DB::log('checkout_blocked_payment','reservation',$id,'Checkout blocked because payment is pending.',['balance'=>$balance,'payment'=>$payment]);
                StayCore_Integrations::emit('checkout_payment_required',['reservation_id'=>$id,'balance'=>$balance,'payment'=>$payment]);
                return new WP_Error('payment_due','Collect the pending payment before checkout.',['status'=>409,'balance'=>$balance,'payment'=>$payment]);
            }
        }
        $wpdb->update($t['reservations'],['status'=>$status,'updated_at'=>$now],['id'=>$id]); if(!$wpdb->rows_affected) return new WP_Error('not_found','Reservation was not updated.',['status'=>404]);
        if($status==='checked_out'){
            foreach($assign as $a){
                $uid=(int)$a['unit_id'];
                $wpdb->update($t['units'],['housekeeping_status'=>'dirty','updated_at'=>$now],['id'=>$uid]);
                $open=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['tasks']} WHERE unit_id=%d AND type='housekeeping' AND status IN ('open','in_progress') LIMIT 1",$uid));
                if(!$open) $wpdb->insert($t['tasks'],['unit_id'=>$uid,'reservation_id'=>$id,'type'=>'housekeeping','title'=>'Clean '.$a['name'],'status'=>'open','priority'=>'normal','due_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            }
            StayCore_DB::log('housekeeping_required','reservation',$id,'Checkout completed; assigned room/bed marked for cleaning.');
        }
        StayCore_DB::log('status_changed','reservation',$id,'Reservation status changed to '.$status.'.'); StayCore_Integrations::emit('reservation_status_changed',['id'=>$id,'status'=>$status]);
        return rest_ensure_response(self::reservation_row($id));
    }

    public static function payments(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables(); $id=absint($request['id']);
        return rest_ensure_response(['summary'=>self::payment_summary($id),'payments'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['payments']} WHERE reservation_id=%d ORDER BY paid_at DESC,id DESC",$id),ARRAY_A)]);
    }

    public static function add_payment(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $id=absint($request['id']); $p=$request->get_json_params(); $amount=(float)($p['amount']??0);
        if($amount<=0) return new WP_Error('bad_amount','Payment amount must be greater than zero.',['status'=>400]);
        if(!(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['reservations']} WHERE id=%d",$id))) return new WP_Error('not_found','Reservation not found.',['status'=>404]);
        $data=['reservation_id'=>$id,'amount'=>$amount,'currency'=>strtoupper(sanitize_text_field($p['currency']??'INR')),'method'=>sanitize_key($p['method']??'cash'),'status'=>sanitize_key($p['status']??'captured'),'external_ref'=>sanitize_text_field($p['external_ref']??''),'meta'=>!empty($p['notes'])?wp_json_encode(['notes'=>sanitize_textarea_field($p['notes'])]):null,'paid_at'=>sanitize_text_field($p['paid_at']??current_time('mysql')),'created_at'=>current_time('mysql')];
        $wpdb->insert($t['payments'],$data); if(!$wpdb->insert_id) return new WP_Error('db_error','Could not record payment.',['status'=>500]);
        StayCore_DB::log('payment_recorded','reservation',$id,'Payment recorded.',['payment_id'=>(int)$wpdb->insert_id,'amount'=>$amount,'method'=>$data['method']]); StayCore_Integrations::emit('payment_recorded',['id'=>(int)$wpdb->insert_id]+$data);
        return rest_ensure_response(['id'=>(int)$wpdb->insert_id,'summary'=>self::payment_summary($id)]);
    }

    public static function guest_lookup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables();
        $phone=sanitize_text_field($request->get_param('phone')?:''); $email=sanitize_email($request->get_param('email')?:'');
        if(!$phone && !$email) return rest_ensure_response(['found'=>false]);
        $guest=self::find_guest_row($phone,$email);
        if(!$guest) return rest_ensure_response(['found'=>false]);
        $stays=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservations']} WHERE guest_id=%d",(int)$guest['id']));
        $last=$wpdb->get_row($wpdb->prepare("SELECT check_in,check_out,source FROM {$t['reservations']} WHERE guest_id=%d ORDER BY check_out DESC LIMIT 1",(int)$guest['id']),ARRAY_A);
        $data=[
            'id'=>(int)$guest['id'],'first_name'=>$guest['first_name'],'last_name'=>$guest['last_name'],
            'phone'=>$guest['phone'],'email'=>$guest['email'],'nationality'=>$guest['nationality'],
            'id_type'=>$guest['id_type'],'id_number'=>$guest['id_number'],
            'has_id_image'=>!empty(self::guest_meta((int)$guest['id'])['id_image']['data']),
            'is_blacklisted'=>self::is_guest_blacklisted((int)$guest['id']),
            'blacklist_reason'=>self::guest_blacklist((int)$guest['id'])['reason'],
            'notes'=>$guest['notes'],'stay_count'=>$stays,'last_stay'=>$last,
        ];
        if(!self::can_guest_contact()) foreach(['phone','email','notes'] as $key) unset($data[$key]);
        if(!self::can_guest_id()) foreach(['nationality','id_type','id_number','has_id_image'] as $key) unset($data[$key]);
        return rest_ensure_response(['found'=>true,'guest'=>$data]);
    }

    public static function guest_id_image(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables();
        $guest_id=absint($request['id']);
        $guest=$wpdb->get_row($wpdb->prepare("SELECT id,id_number FROM {$t['guests']} WHERE id=%d",$guest_id),ARRAY_A);
        if(!$guest) return new WP_Error('not_found','Guest not found.',['status'=>404]);
        $image=self::guest_meta($guest_id)['id_image']??null;
        if(!is_array($image)||empty($image['data'])||empty($image['mime'])) return new WP_Error('not_found','No ID image is stored for this guest.',['status'=>404]);
        if(!self::valid_guest_id_image($guest_id,(string)$guest['id_number'],$image)) return new WP_Error('id_binding_mismatch','Stored ID image is not verified against this guest record. Capture a fresh ID photo.',['status'=>409]);
        StayCore_DB::log('guest_id_viewed','guest',$guest_id,'Verified guest ID image viewed.',['source_reservation_id'=>(int)($image['reservation_id']??0)]);
        return rest_ensure_response([
            'mime'=>$image['mime'],'data'=>$image['data'],'updated_at'=>$image['updated_at']??null,
            'verified'=>true,'guest_id'=>$guest_id,'source_reservation_id'=>(int)($image['reservation_id']??0),
            'capture_method'=>$image['capture_method']??'camera',
        ]);
    }

    public static function self_checkin_get(WP_REST_Request $request) {
        $id=absint($request['id']); $token=sanitize_text_field($request->get_param('token')?:'');
        $row=self::reservation_row($id);
        if(!$row || !self::valid_self_checkin_token($row,$token)) return new WP_Error('invalid_link','This self check-in link is invalid.',['status'=>403]);
        if(self::public_link_expired($row,'checkin')) return new WP_Error('expired_link','This self check-in link has expired. Please contact the front desk.',['status'=>410]);
        if(in_array($row['status'],['cancelled','no_show','checked_out'],true)) return new WP_Error('booking_closed','This booking is no longer open for self check-in.',['status'=>409]);
        if(self::is_guest_blacklisted((int)$row['guest_id'])) return new WP_Error('guest_blacklisted','Self check-in is disabled for this booking. Please contact the front desk.',['status'=>409]);
        $meta=is_string($row['meta']??null)?json_decode($row['meta'],true):[];
        if(!is_array($meta)) $meta=[];
        $missing=self::checkin_requirements($row);
        if(empty($row['email'])) $missing[]='email';
        return rest_ensure_response([
            'id'=>(int)$row['id'],'first_name'=>$row['first_name'],'reference'=>$row['external_ref']?:'#'.$row['id'],
            'check_in'=>$row['check_in'],'check_out'=>$row['check_out'],'assignment'=>implode(', ',array_column($row['assignments'],'name')),
            'nationality'=>(string)$row['nationality'],'id_type'=>(string)$row['id_type'],'id_number'=>(string)$row['id_number'],
            'missing'=>array_values(array_unique($missing)),'precheckin'=>!empty($meta['precheckin_at']),'status'=>$row['status'],
            'can_checkin_now'=>($check_in_at=self::local_datetime((string)$row['check_in'])) ? $check_in_at<=current_datetime() : false
        ]);
    }

    public static function self_checkin_post(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $id=absint($request['id']); $p=$request->get_json_params(); $token=sanitize_text_field($p['token']??'');
        $row=self::reservation_row($id);
        if(!$row || !self::valid_self_checkin_token($row,$token)) return new WP_Error('invalid_link','This self check-in link is invalid.',['status'=>403]);
        if(self::public_link_expired($row,'checkin')) return new WP_Error('expired_link','This self check-in link has expired. Please contact the front desk.',['status'=>410]);
        if(in_array($row['status'],['cancelled','no_show','checked_out'],true)) return new WP_Error('booking_closed','This booking is no longer open for self check-in.',['status'=>409]);
        if(self::is_guest_blacklisted((int)$row['guest_id'])) return new WP_Error('guest_blacklisted','Self check-in is disabled for this booking. Please contact the front desk.',['status'=>409]);

        $updates=['updated_at'=>current_time('mysql')];
        if(array_key_exists('phone',$p)){
            $phone=self::build_international_phone((string)($p['country_code']??''),(string)$p['phone']);
            if(is_wp_error($phone)) return $phone;
            $updates['phone']=$phone;
        }
        if(!empty($p['email'])) $updates['email']=sanitize_email($p['email']);
        if(array_key_exists('nationality',$p)) $updates['nationality']=sanitize_text_field((string)$p['nationality']);
        if(array_key_exists('id_type',$p)) $updates['id_type']=sanitize_text_field((string)$p['id_type']);
        if(array_key_exists('id_number',$p)) $updates['id_number']=sanitize_text_field((string)$p['id_number']);

        $phone=$updates['phone']??(string)$row['phone'];
        $nationality=trim((string)($updates['nationality']??$row['nationality']));
        $id_type=trim((string)($updates['id_type']??$row['id_type']));
        $id_number=trim((string)($updates['id_number']??$row['id_number']));
        if(!self::valid_international_phone($phone)) return new WP_Error('phone_required','A valid mobile number with country code is required.',['status'=>400]);
        if(strlen($nationality)<2) return new WP_Error('nationality_required','Nationality is required.',['status'=>400]);
        if(!in_array($id_type,['Passport','Aadhaar','Driving Licence','Other'],true)) return new WP_Error('id_required','Choose a valid ID type.',['status'=>400]);
        $normalized_id=self::normalize_id_number($id_number);
        if(strlen($normalized_id)<4 || strlen($normalized_id)>40) return new WP_Error('id_required','Enter a valid ID number.',['status'=>400]);
        if($id_type==='Aadhaar' && !preg_match('/^\\d{12}$/',$normalized_id)) return new WP_Error('id_required','Aadhaar number must contain 12 digits.',['status'=>400]);

        $guest_id=(int)$row['guest_id'];
        $guest_meta=self::guest_meta($guest_id);
        if(!empty($p['id_image_data'])){
            $capture_method=sanitize_key((string)($p['capture_method']??''));
            if(!in_array($capture_method,['camera','gallery'],true)) return new WP_Error('id_source_required','Add the ID using the camera or gallery.',['status'=>400]);
            if(empty($p['id_confirm'])) return new WP_Error('id_confirmation_required','Confirm that the captured photo is the guest ID shown in the details.',['status'=>400]);
            $ocr_number=self::normalize_id_number((string)($p['ocr_id_number']??''));
            if($ocr_number!=='' && !hash_equals($ocr_number,$normalized_id)) return new WP_Error('id_mismatch','The ID number read from the photo does not match the ID number entered. Retake the photo or correct the details.',['status'=>409]);
            $image=self::parse_id_image((string)$p['id_image_data']);
            if(is_wp_error($image)) return $image;
            $image['guest_id']=$guest_id;
            $image['reservation_id']=$id;
            $image['capture_method']=$capture_method;
            $image['id_number_hash']=self::id_number_hash($id_number);
            self::merge_guest_meta($guest_id,['id_image'=>$image]);
            $guest_meta['id_image']=$image;
        }
        if(!is_array($guest_meta['id_image']??null) || !self::valid_guest_id_image($guest_id,$id_number,$guest_meta['id_image'])) return new WP_Error('id_image_required','Add a new image of the same ID shown in the details.',['status'=>400]);

        $wpdb->update($t['guests'],$updates,['id'=>$guest_id]);
        self::merge_reservation_meta($id,['precheckin_at'=>current_time('mysql')]);
        StayCore_DB::log('guest_prechecked','reservation',$id,'Guest completed self check-in details.');

        $now=current_datetime();
        $check_in_at=self::local_datetime((string)$row['check_in']);
        $check_out_at=self::local_datetime((string)$row['check_out']);
        if($check_in_at && $check_in_at>$now) return rest_ensure_response(['state'=>'prechecked']);
        if($check_out_at && $check_out_at<$now) return new WP_Error('booking_closed','This booking has already ended.',['status'=>409]);

        foreach(self::assignments($id) as $unit){
            if(($unit['status']??'available')!=='available' || ($unit['housekeeping_status']??'clean')!=='clean'){
                StayCore_DB::log('self_checkin_waiting','reservation',$id,'Guest self check-in is waiting for housekeeping.');
                return rest_ensure_response(['state'=>'waiting_housekeeping']);
            }
        }
        if($row['status']!=='checked_in'){
            $wpdb->update($t['reservations'],['status'=>'checked_in','updated_at'=>current_time('mysql')],['id'=>$id]);
            StayCore_DB::log('self_checked_in','reservation',$id,'Guest completed self check-in.');
            StayCore_Integrations::emit('reservation_status_changed',['id'=>$id,'status'=>'checked_in','via'=>'self_checkin']);
        }
        return rest_ensure_response(['state'=>'checked_in']);
    }

    public static function feedback_get(WP_REST_Request $request) {
        $id=absint($request['id']); $token=sanitize_text_field($request->get_param('token')?:'');
        $row=self::reservation_row($id);
        if(!$row || !self::valid_feedback_token($row,$token)) return new WP_Error('invalid_link','This feedback link is invalid.',['status'=>403]);
        if(self::public_link_expired($row,'feedback')) return new WP_Error('expired_link','This feedback link has expired.',['status'=>410]);
        if($row['status']!=='checked_out') return new WP_Error('not_checked_out','This feedback link becomes available after checkout.',['status'=>409]);
        $meta=is_string($row['meta']??null)?json_decode($row['meta'],true):[];
        if(!is_array($meta)) $meta=[];
        return rest_ensure_response([
            'id'=>(int)$row['id'],'first_name'=>$row['first_name'],'reference'=>$row['external_ref']?:'#'.$row['id'],
            'sentiment'=>$meta['feedback_sentiment']??'','message'=>$meta['feedback_message']??'',
            'config'=>self::feedback_config(false),
        ]);
    }

    public static function feedback_post(WP_REST_Request $request) {
        $id=absint($request['id']); $p=$request->get_json_params(); $token=sanitize_text_field($p['token']??'');
        $row=self::reservation_row($id);
        if(!$row || !self::valid_feedback_token($row,$token)) return new WP_Error('invalid_link','This feedback link is invalid.',['status'=>403]);
        if(self::public_link_expired($row,'feedback')) return new WP_Error('expired_link','This feedback link has expired.',['status'=>410]);
        if($row['status']!=='checked_out') return new WP_Error('not_checked_out','Feedback is available after checkout.',['status'=>409]);
        $sentiment=sanitize_key($p['sentiment']??'');
        if(!in_array($sentiment,['happy','not_happy'],true)) return new WP_Error('bad_sentiment','Choose Happy or Not happy.',['status'=>400]);
        $message=sanitize_textarea_field($p['message']??'');
        if($sentiment==='not_happy' && strlen(trim($message))<3) return new WP_Error('message_required','Tell us what went wrong so management can fix it.',['status'=>400]);
        self::merge_reservation_meta($id,['feedback_sentiment'=>$sentiment,'feedback_message'=>$message,'feedback_at'=>current_time('mysql')]);
        if($sentiment==='not_happy'){
            global $wpdb; $t=StayCore_DB::tables(); $now=current_time('mysql');
            $open=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['tasks']} WHERE reservation_id=%d AND type='service_recovery' AND status IN ('open','in_progress') LIMIT 1",$id));
            if(!$open) $wpdb->insert($t['tasks'],['reservation_id'=>$id,'type'=>'service_recovery','title'=>'Guest reported an unhappy stay','status'=>'open','priority'=>'high','due_at'=>$now,'notes'=>$message,'created_at'=>$now,'updated_at'=>$now]);
        }
        StayCore_DB::log('guest_feedback','reservation',$id,$sentiment==='happy'?'Guest reported a happy stay.':'Guest requested service recovery.',['sentiment'=>$sentiment,'message'=>$message]);
        return rest_ensure_response(['saved'=>true,'sentiment'=>$sentiment,'message'=>$message,'config'=>self::feedback_config($sentiment==='not_happy'),'reference'=>$row['external_ref']?:'#'.$row['id']]);
    }

    public static function activity(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables(); $limit=min(100,max(10,absint($request->get_param('limit')?:50)));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT a.*,u.display_name user_name FROM {$t['activity']} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id ORDER BY a.id DESC LIMIT %d",$limit),ARRAY_A);
        if(!self::can_manage()) foreach($rows as &$row) unset($row['meta']);
        return rest_ensure_response($rows);
    }
}
