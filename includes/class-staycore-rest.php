<?php
if (!defined('ABSPATH')) exit;

final class StayCore_REST {
    public static function boot(): void { add_action('rest_api_init',[__CLASS__,'routes']); }
    public static function can_view(): bool { return current_user_can('staycore_view_pms') || current_user_can('manage_options'); }
    public static function can_reservations(): bool { return current_user_can('staycore_manage_reservations') || current_user_can('manage_options'); }
    public static function can_payments(): bool { return current_user_can('staycore_manage_payments') || current_user_can('manage_options'); }
    public static function can_housekeeping(): bool { return current_user_can('staycore_manage_housekeeping') || current_user_can('manage_options'); }

    public static function routes(): void {
        register_rest_route('staycore/v1','/dashboard',['methods'=>'GET','callback'=>[__CLASS__,'dashboard'],'permission_callback'=>[__CLASS__,'can_view']]);
        register_rest_route('staycore/v1','/units',[
            ['methods'=>'GET','callback'=>[__CLASS__,'units'],'permission_callback'=>[__CLASS__,'can_view']],
            ['methods'=>'POST','callback'=>[__CLASS__,'create_unit'],'permission_callback'=>[__CLASS__,'can_reservations']],
        ]);
        register_rest_route('staycore/v1','/housekeeping',['methods'=>'POST','callback'=>[__CLASS__,'set_housekeeping'],'permission_callback'=>[__CLASS__,'can_housekeeping']]);
        register_rest_route('staycore/v1','/guest-lookup',['methods'=>'GET','callback'=>[__CLASS__,'guest_lookup'],'permission_callback'=>[__CLASS__,'can_reservations']]);
        register_rest_route('staycore/v1','/self-checkin/(?P<id>\\d+)',[
            ['methods'=>'GET','callback'=>[__CLASS__,'self_checkin_get'],'permission_callback'=>'__return_true'],
            ['methods'=>'POST','callback'=>[__CLASS__,'self_checkin_post'],'permission_callback'=>'__return_true'],
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
            ['methods'=>'GET','callback'=>[__CLASS__,'payments'],'permission_callback'=>[__CLASS__,'can_view']],
            ['methods'=>'POST','callback'=>[__CLASS__,'add_payment'],'permission_callback'=>[__CLASS__,'can_payments']],
        ]);
        register_rest_route('staycore/v1','/activity',['methods'=>'GET','callback'=>[__CLASS__,'activity'],'permission_callback'=>[__CLASS__,'can_view']]);
        register_rest_route('staycore/v1','/integrations',['methods'=>'GET','callback'=>fn()=>rest_ensure_response(StayCore_Integrations::all()),'permission_callback'=>[__CLASS__,'can_view']]);
    }

    private static function payment_summary(int $reservation_id): array {
        global $wpdb; $t=StayCore_DB::tables();
        $captured=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$t['payments']} WHERE reservation_id=%d AND status='captured'",$reservation_id));
        $total=(float)$wpdb->get_var($wpdb->prepare("SELECT total FROM {$t['reservations']} WHERE id=%d",$reservation_id));
        $balance=max(0,$total-$captured);
        $status=$total<=0?'not_set':($captured<=0?'pending':($balance>0.009?'partial':'paid'));
        return compact('total','captured','balance','status');
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
        return $row;
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

    public static function dashboard(): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables(); $today=current_time('Y-m-d'); $start=$today.' 00:00:00'; $end=$today.' 23:59:59';
        $arrivals=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservations']} WHERE DATE(check_in)=%s AND status NOT IN ('cancelled','no_show')",$today));
        $departures=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservations']} WHERE DATE(check_out)=%s AND status NOT IN ('cancelled','no_show')",$today));
        $inhouse=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['reservations']} WHERE status='checked_in'");
        $occupied=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE r.status IN ('confirmed','checked_in') AND r.check_in<=%s AND r.check_out>%s",$end,$start));
        $available=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['units']} u WHERE u.status='available' AND u.housekeeping_status='clean' AND NOT EXISTS (SELECT 1 FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE ru.unit_id=u.id AND r.status IN ('confirmed','checked_in') AND r.check_in<=%s AND r.check_out>%s)",$end,$start));
        $cleaning=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['units']} WHERE status='available' AND housekeeping_status IN ('dirty','cleaning')");
        $maintenance=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['units']} WHERE housekeeping_status='maintenance'");
        $expected=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total),0) FROM {$t['reservations']} WHERE status IN ('confirmed','checked_in') AND check_in<=%s AND check_out>%s",$end,$start));
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['reservations']} WHERE status IN ('confirmed','checked_in') AND check_in<=%s AND check_out>%s",$end,$start));
        $collected=0.0;
        if($ids){ $placeholders=implode(',',array_fill(0,count($ids),'%d')); $collected=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$t['payments']} WHERE status='captured' AND reservation_id IN ($placeholders)",...array_map('intval',$ids))); }
        $alerts=[];
        $overdue=$wpdb->get_results($wpdb->prepare("SELECT id,guest_id,check_out FROM {$t['reservations']} WHERE status='checked_in' AND check_out<%s ORDER BY check_out",current_time('mysql')),ARRAY_A);
        foreach($overdue as $x) $alerts[]=['type'=>'overdue','reservation_id'=>(int)$x['id'],'message'=>'Checkout overdue'];
        $unpaid=$wpdb->get_results($wpdb->prepare("SELECT id,total FROM {$t['reservations']} WHERE status IN ('confirmed','checked_in') AND check_in<=%s AND check_out>%s AND total>0",$end,$start),ARRAY_A);
        foreach($unpaid as $x){ $ps=self::payment_summary((int)$x['id']); if($ps['balance']>0.009) $alerts[]=['type'=>'payment','reservation_id'=>(int)$x['id'],'message'=>'₹'.number_format($ps['balance'],2).' balance due']; }
        $missing=$wpdb->get_results($wpdb->prepare("SELECT r.id FROM {$t['reservations']} r JOIN {$t['guests']} g ON g.id=r.guest_id WHERE r.status IN ('confirmed','checked_in') AND r.check_in<=%s AND r.check_out>%s AND (g.phone IS NULL OR g.phone='')",$end,$start),ARRAY_A);
        foreach($missing as $x) $alerts[]=['type'=>'contact','reservation_id'=>(int)$x['id'],'message'=>'Guest phone missing'];
        return rest_ensure_response(['arrivals'=>$arrivals,'departures'=>$departures,'inhouse'=>$inhouse,'occupied_units'=>$occupied,'available_units'=>$available,'cleaning_units'=>$cleaning,'maintenance_units'=>$maintenance,'expected_revenue'=>$expected,'collected'=>$collected,'balance'=>max(0,$expected-$collected),'alerts'=>$alerts]);
    }

    public static function units(): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables();
        return rest_ensure_response($wpdb->get_results("SELECT * FROM {$t['units']} ORDER BY room_group,name",ARRAY_A));
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
            "SELECT r.*,CONCAT(g.first_name,' ',COALESCE(g.last_name,'')) guest_name,g.phone,g.email,g.nationality,g.id_type,g.id_number FROM {$t['reservations']} r LEFT JOIN {$t['guests']} g ON g.id=r.guest_id WHERE r.check_in < %s AND r.check_out >= %s ORDER BY r.check_in ASC",
            $to.' 23:59:59',$from.' 00:00:00'
        ),ARRAY_A);
        foreach($rows as &$row){ $row['assignments']=self::assignments((int)$row['id']); $row['payment']=self::payment_summary((int)$row['id']); }
        return rest_ensure_response($rows);
    }

    public static function reservation_detail(WP_REST_Request $request) {
        $row=self::reservation_row(absint($request['id'])); if(!$row) return new WP_Error('not_found','Reservation not found.',['status'=>404]);
        global $wpdb; $t=StayCore_DB::tables();
        $row['payments']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['payments']} WHERE reservation_id=%d ORDER BY paid_at DESC,id DESC",(int)$row['id']),ARRAY_A);
        $row['activity']=$wpdb->get_results($wpdb->prepare("SELECT a.*,u.display_name user_name FROM {$t['activity']} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE a.entity_type='reservation' AND a.entity_id=%d ORDER BY a.id DESC LIMIT 30",(int)$row['id']),ARRAY_A);
        return rest_ensure_response($row);
    }

    public static function create_reservation(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $p=apply_filters('staycore_pms_reservation_payload',$request->get_json_params()); $now=current_time('mysql');
        $first=sanitize_text_field($p['first_name']??''); $phone=sanitize_text_field($p['phone']??''); $email=sanitize_email($p['email']??'');
        $check_in=sanitize_text_field($p['check_in']??''); $check_out=sanitize_text_field($p['check_out']??'');
        $adults=max(1,absint($p['adults']??1)); $children=absint($p['children']??0); $people=$adults+$children;
        $stay_type=sanitize_key($p['stay_type']??'dorm_any'); $unit_ids=self::normalize_unit_ids($p);
        $auto_assign=array_key_exists('auto_assign',$p)?(bool)$p['auto_assign']:!$unit_ids;
        if(!$first || !$check_in || !$check_out) return new WP_Error('missing_fields','Guest name, check-in and check-out are required.',['status'=>400]);
        if(strtotime($check_out)<=strtotime($check_in)) return new WP_Error('bad_dates','Check-out must be after check-in.',['status'=>400]);
        if(!$unit_ids && $auto_assign){
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

        $existing=null;
        if(!empty($p['guest_id'])) $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['guests']} WHERE id=%d",absint($p['guest_id'])),ARRAY_A);
        if(!$existing) $existing=self::find_guest_row($phone,$email);
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

        $meta=['stay_type'=>$stay_type,'auto_assigned'=>$auto_assign && !isset($p['unit_ids'])];
        if(isset($p['meta'])&&is_array($p['meta'])) $meta=array_merge($p['meta'],$meta);
        $data=['guest_id'=>$guest_id,'unit_id'=>$unit_ids[0],'source'=>sanitize_key($p['source']??'direct'),'external_ref'=>sanitize_text_field($p['external_ref']??''),'check_in'=>$check_in,'check_out'=>$check_out,'adults'=>$adults,'children'=>$children,'status'=>sanitize_key($p['status']??'confirmed'),'total'=>(float)($p['total']??0),'currency'=>strtoupper(sanitize_text_field($p['currency']??'INR')),'notes'=>sanitize_textarea_field($p['notes']??''),'meta'=>wp_json_encode($meta),'created_at'=>$now,'updated_at'=>$now];
        $wpdb->insert($t['reservations'],$data); if(!$wpdb->insert_id) return new WP_Error('db_error','Could not create reservation.',['status'=>500]); $id=(int)$wpdb->insert_id;
        foreach($unit_ids as $uid) $wpdb->insert($t['reservation_units'],['reservation_id'=>$id,'unit_id'=>$uid,'guests'=>1,'created_at'=>$now]);
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
        $data=['unit_id'=>$unit_ids[0],'source'=>sanitize_key($p['source']??$existing['source']),'external_ref'=>sanitize_text_field($p['external_ref']??$existing['external_ref']),'check_in'=>$check_in,'check_out'=>$check_out,'adults'=>$adults,'children'=>$children,'status'=>sanitize_key($p['status']??$existing['status']),'total'=>(float)($p['total']??$existing['total']),'notes'=>sanitize_textarea_field($p['notes']??$existing['notes']),'updated_at'=>current_time('mysql')];
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
            foreach($assign as $a) if(($a['status']??'available')!=='available' || ($a['housekeeping_status']??'clean')!=='clean') return new WP_Error('unit_not_ready',$a['name'].' is not ready. Housekeeping must mark it clean before check-in.',['status'=>409]);
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
        return rest_ensure_response(['found'=>true,'guest'=>[
            'id'=>(int)$guest['id'],'first_name'=>$guest['first_name'],'last_name'=>$guest['last_name'],'phone'=>$guest['phone'],'email'=>$guest['email'],
            'nationality'=>$guest['nationality'],'id_type'=>$guest['id_type'],'id_number'=>$guest['id_number'],'notes'=>$guest['notes'],'stay_count'=>$stays,'last_stay'=>$last,
        ]]);
    }

    public static function self_checkin_get(WP_REST_Request $request) {
        $id=absint($request['id']); $token=sanitize_text_field($request->get_param('token')?:'');
        $row=self::reservation_row($id);
        if(!$row || !self::valid_self_checkin_token($row,$token)) return new WP_Error('invalid_link','This self check-in link is invalid.',['status'=>403]);
        if(in_array($row['status'],['cancelled','no_show','checked_out'],true)) return new WP_Error('booking_closed','This booking is no longer open for self check-in.',['status'=>409]);
        $meta=is_string($row['meta']??null)?json_decode($row['meta'],true):[];
        if(!is_array($meta)) $meta=[];
        $missing=[];
        if(empty($row['phone'])) $missing[]='phone';
        if(empty($row['email'])) $missing[]='email';
        if(empty($row['nationality'])) $missing[]='nationality';
        if(empty($row['id_type']) || empty($row['id_number'])) $missing[]='id';
        return rest_ensure_response([
            'id'=>(int)$row['id'],'first_name'=>$row['first_name'],'reference'=>$row['external_ref']?:'#'.$row['id'],
            'check_in'=>$row['check_in'],'check_out'=>$row['check_out'],'assignment'=>implode(', ',array_column($row['assignments'],'name')),
            'missing'=>$missing,'precheckin'=>!empty($meta['precheckin_at']),'status'=>$row['status'],
            'can_checkin_now'=>substr($row['check_in'],0,10)<=current_time('Y-m-d')
        ]);
    }

    public static function self_checkin_post(WP_REST_Request $request) {
        global $wpdb; $t=StayCore_DB::tables(); $id=absint($request['id']); $p=$request->get_json_params(); $token=sanitize_text_field($p['token']??'');
        $row=self::reservation_row($id);
        if(!$row || !self::valid_self_checkin_token($row,$token)) return new WP_Error('invalid_link','This self check-in link is invalid.',['status'=>403]);
        if(in_array($row['status'],['cancelled','no_show','checked_out'],true)) return new WP_Error('booking_closed','This booking is no longer open for self check-in.',['status'=>409]);

        $updates=['updated_at'=>current_time('mysql')];
        if(!empty($p['phone'])) $updates['phone']=sanitize_text_field($p['phone']);
        if(!empty($p['email'])) $updates['email']=sanitize_email($p['email']);
        if(!empty($p['nationality'])) $updates['nationality']=sanitize_text_field($p['nationality']);
        if(!empty($p['id_type'])) $updates['id_type']=sanitize_text_field($p['id_type']);
        if(!empty($p['id_number'])) $updates['id_number']=sanitize_text_field($p['id_number']);
        $phone=$updates['phone']??$row['phone'];
        if(!$phone) return new WP_Error('phone_required','Please enter a mobile number to complete self check-in.',['status'=>400]);
        $wpdb->update($t['guests'],$updates,['id'=>(int)$row['guest_id']]);
        self::merge_reservation_meta($id,['precheckin_at'=>current_time('mysql')]);
        StayCore_DB::log('guest_prechecked','reservation',$id,'Guest completed self check-in details.');

        $today=current_time('Y-m-d');
        if(substr($row['check_in'],0,10)>$today) return rest_ensure_response(['state'=>'prechecked']);
        if(substr($row['check_out'],0,10)<$today) return new WP_Error('booking_closed','This booking has already ended.',['status'=>409]);

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

    public static function activity(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $t=StayCore_DB::tables(); $limit=min(100,max(10,absint($request->get_param('limit')?:50)));
        return rest_ensure_response($wpdb->get_results($wpdb->prepare("SELECT a.*,u.display_name user_name FROM {$t['activity']} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id ORDER BY a.id DESC LIMIT %d",$limit),ARRAY_A));
    }
}
