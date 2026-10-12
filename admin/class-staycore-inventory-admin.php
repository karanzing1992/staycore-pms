<?php
if (!defined('ABSPATH')) exit;
/** Admin-only inventory setup: separate from operational room dashboard. */
final class StayCore_Inventory_Admin {
    public static function boot(): void {
        add_action('admin_menu',[__CLASS__,'menu'],21);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        add_action('admin_post_staycore_inventory_create',[__CLASS__,'create']);
        add_action('admin_post_staycore_inventory_update',[__CLASS__,'update']);
    }
    public static function assets(string $hook): void {
        if ($hook === 'staycore-pms_page_staycore-inventory') wp_enqueue_style('staycore-inventory', STAYCORE_PMS_URL . 'admin/assets/inventory.css', [], STAYCORE_PMS_VERSION);
    }
    public static function menu(): void {
        add_submenu_page('staycore-pms','Inventory Manager','Inventory Manager','manage_options','staycore-inventory',[__CLASS__,'render']);
    }
    private static function redirect(string $notice): void {
        wp_safe_redirect(add_query_arg(['page'=>'staycore-inventory','inventory_notice'=>$notice],admin_url('admin.php')));
        exit;
    }
    public static function create(): void {
        if(!current_user_can('manage_options')) wp_die('Forbidden','',['response'=>403]);
        check_admin_referer('staycore_inventory_create');
        global $wpdb; $t=StayCore_DB::tables(); $p=wp_unslash($_POST);
        $room=sanitize_text_field($p['room_name']??'');
        $kind=sanitize_key($p['kind']??'bed');
        $group=sanitize_text_field($p['category']??'');
        $count=absint($p['count']??1);
        $capacity=absint($p['capacity']??1);
        $rate=filter_var($p['base_rate']??'',FILTER_VALIDATE_FLOAT);
        if(!$room || !in_array($kind,['bed','room'],true) || $count<1 || $count>24 || !$capacity || $capacity>12 || $rate===false || $rate<0 || $rate>1000000) self::redirect('invalid');
        if($kind==='room' && $count!==1) self::redirect('invalid');
        // Refuse duplicate room/category inventory rather than silently creating extra beds.
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['units']} WHERE room_group=%s AND (name=%s OR name LIKE %s)",$group,$room,$wpdb->esc_like($room.' · Bed ').'%'));
        if($exists) self::redirect('duplicate');
        $now=current_time('mysql'); $created=[];
        for($i=1;$i<=$count;$i++){
            $data=['name'=>$kind==='bed'?$room.' · Bed '.$i:$room,'type'=>$kind,'room_group'=>$group,'capacity'=>$kind==='bed'?1:$capacity,'base_rate'=>round((float)$rate,2),'status'=>'available','housekeeping_status'=>'clean','created_at'=>$now,'updated_at'=>$now];
            if(!$wpdb->insert($t['units'],$data)){
                foreach($created as $id) $wpdb->delete($t['units'],['id'=>$id]);
                self::redirect('failed');
            }
            $created[]=(int)$wpdb->insert_id;
        }
        StayCore_DB::log('inventory_created','unit',null,'Inventory units created.',['count'=>count($created),'room'=>$room,'type'=>$kind]);
        self::redirect('created');
    }
    public static function update(): void {
        if(!current_user_can('manage_options')) wp_die('Forbidden','',['response'=>403]);
        check_admin_referer('staycore_inventory_update');
        global $wpdb; $t=StayCore_DB::tables(); $p=wp_unslash($_POST);
        $id=absint($p['unit_id']??0); $rate=filter_var($p['base_rate']??'',FILTER_VALIDATE_FLOAT);
        $status=sanitize_key($p['status']??'');
        if(!$id||$rate===false||$rate<0||$rate>1000000||!in_array($status,['available','maintenance','inactive'],true))self::redirect('invalid');
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['units']} WHERE id=%d",$id)))self::redirect('invalid');
        if($wpdb->update($t['units'],['base_rate'=>round((float)$rate,2),'status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id])===false)self::redirect('failed');
        StayCore_DB::log('inventory_updated','unit',$id,'Unit inventory and price updated.',['status'=>$status,'base_rate'=>$rate]);
        self::redirect('updated');
    }
    public static function render(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden','',['response'=>403]);
        global $wpdb; $t=StayCore_DB::tables();
        $units=$wpdb->get_results("SELECT * FROM {$t['units']} ORDER BY room_group,name",ARRAY_A)?:[];
        $date=sanitize_text_field(wp_unslash($_GET['inventory_date']??current_time('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date=current_time('Y-m-d');
        $next=gmdate('Y-m-d',strtotime($date.' +7 days'));
        $assigned=$wpdb->get_results($wpdb->prepare("SELECT ru.unit_id,r.check_in,r.check_out FROM {$t['reservation_units']} ru JOIN {$t['reservations']} r ON r.id=ru.reservation_id WHERE r.status NOT IN ('cancelled','checked_out') AND r.check_in < %s AND r.check_out > %s",$next.' 00:00:00',$date.' 00:00:00'),ARRAY_A)?:[];
        $busy=[];
        foreach($assigned as $row){$busy[(int)$row['unit_id']][]=$row;}
        echo '<div class="wrap staycore-inventory"><h1>Inventory &amp; Availability</h1><p class="sc-inventory-intro">Create private rooms and dorm beds, manage baseline rates, and see available nights at a glance. Administrator only.</p>';
        $notice=sanitize_key($_GET['inventory_notice']??'');
        if($notice)echo '<div class="notice notice-info"><p>'.esc_html(['created'=>'Inventory created.','updated'=>'Unit updated.','invalid'=>'Check all fields.','duplicate'=>'Room inventory already exists.','failed'=>'Database operation failed.'][$notice]??'Inventory status changed.').'</p></div>';
        echo '<section class="sc-inventory-panel"><h2>Add a room or dorm beds</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="staycore_inventory_create">';
        wp_nonce_field('staycore_inventory_create');
        echo '<p><label>Room label <input required name="room_name" placeholder="Room 8"></label> <label>Category <input name="category" placeholder="Non-AC Dorm"></label></p>';
        echo '<p><label>Unit type <select name="kind"><option value="bed">Dormitory beds</option><option value="room">Private room</option></select></label> <label>Number of beds / units <input type="number" name="count" min="1" max="24" value="1"></label></p>';
        echo '<p><label>Private room maximum occupancy <input type="number" name="capacity" min="1" max="12" value="2"></label> <label>Base price per bed or room (₹) <input required type="number" step="0.01" min="0" name="base_rate" value="1000"></label></p>';
        submit_button('Create inventory'); echo '</form></section><section class="sc-inventory-panel"><h2>Inventory list and pricing</h2><div class="sc-inventory-table-scroll">';
        echo '<table class="widefat striped"><thead><tr><th>Unit</th><th>Category</th><th>Type</th><th>Capacity</th><th>Base rate/status</th></tr></thead><tbody>';
        foreach($units as $u){echo '<tr><td>'.esc_html($u['name']).'</td><td>'.esc_html($u['room_group']).'</td><td>'.esc_html($u['type']).'</td><td>'.(int)$u['capacity'].'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="staycore_inventory_update"><input type="hidden" name="unit_id" value="'.(int)$u['id'].'">';
            wp_nonce_field('staycore_inventory_update');
            echo '<input type="number" min="0" step="0.01" style="width:110px" name="base_rate" value="'.esc_attr($u['base_rate']).'"> <select name="status">';
            foreach(['available','maintenance','inactive'] as $st)echo '<option value="'.$st.'" '.selected($u['status'],$st,false).'>'.esc_html(ucfirst($st)).'</option>';
            echo '</select> <button class="button">Save</button></form></td></tr>';
        }
        if(!$units) echo '<tr><td colspan="5">No inventory yet.</td></tr>';
        echo '</tbody></table></div></section><section class="sc-inventory-panel"><h2>Seven-day occupancy calendar</h2>';
        echo '<form method="get"><input type="hidden" name="page" value="staycore-inventory"><label>Start date <input type="date" name="inventory_date" value="'.esc_attr($date).'"></label> <button class="button">View</button></form>';
        echo '<div class="sc-inventory-calendar" role="region" aria-label="Seven-day room availability, swipe horizontally on mobile" tabindex="0"><table class="widefat striped"><thead><tr><th scope="col">Unit</th>';
        for($i=0;$i<7;$i++)echo '<th>'.esc_html(gmdate('D j M',strtotime($date.' +'.$i.' days'))).'</th>';
        echo '</tr></thead><tbody>';
        foreach($units as $u){echo '<tr><th>'.esc_html($u['name']).'</th>';
            for($i=0;$i<7;$i++){
                $d=gmdate('Y-m-d',strtotime($date.' +'.$i.' days'));$occupied=false;
                foreach($busy[(int)$u['id']]??[] as $x){if(substr($x['check_in'],0,10)<=$d && substr($x['check_out'],0,10)>$d){$occupied=true;break;}}
                $state=$occupied?'Booked':($u['status']!=='available'?'Blocked':'Available');
                echo '<td><span class="sc-state sc-state--'.esc_attr(strtolower($state)).'">'.esc_html($state).'</span></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div><p>Calendar is read-only; the existing reservation engine remains responsible for checking overlaps.</p></section></div>';
    }
}
