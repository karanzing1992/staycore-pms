<?php
if (!defined('ABSPATH')) exit;

final class StayCore_Access {
    private const PIN_META = 'staycore_staff_pin_hash';
    private const PREVIOUS_PIN_META = 'staycore_staff_previous_pin_hash';
    private const NAME_META = 'staycore_staff_name';
    private const PRESET_META = 'staycore_staff_preset';
    private const PIN_ATTEMPTS = 5;
    private const PIN_LOCK_SECONDS = 900;
    private const IP_ATTEMPTS = 25;

    public static function boot(): void {
        add_action('show_user_profile',[__CLASS__,'profile_fields']);
        add_action('edit_user_profile',[__CLASS__,'profile_fields']);
        add_action('personal_options_update',[__CLASS__,'save_profile_fields']);
        add_action('edit_user_profile_update',[__CLASS__,'save_profile_fields']);
        add_filter('auth_cookie_expiration',[__CLASS__,'auth_cookie_expiration'],10,3);
    }

    public static function capabilities(): array {
        return [
            'read',
            'staycore_view_pms',
            'manage_staycore_pms',
            'staycore_manage_reservations',
            'staycore_view_guests',
            'staycore_view_guest_contact',
            'staycore_view_guest_id',
            'staycore_upload_guest_id',
            'staycore_manage_payments',
            'staycore_checkout',
            'staycore_manage_housekeeping',
            'staycore_view_reports',
            'staycore_view_activity',
            'staycore_manage_staff',
            'staycore_manage_settings',
        ];
    }

    private static function role_caps(array $caps): array {
        return array_fill_keys(array_values(array_unique(array_merge(['read'],$caps))), true);
    }


    public static function presets(): array {
        return [
            'owner' => [
                'label' => 'Owner',
                'caps' => array_values(array_diff(self::capabilities(),['read'])),
            ],
            'manager' => [
                'label' => 'Manager',
                'caps' => [
                    'staycore_view_pms','manage_staycore_pms','staycore_manage_reservations',
                    'staycore_view_guests','staycore_view_guest_contact','staycore_view_guest_id','staycore_upload_guest_id',
                    'staycore_manage_payments','staycore_checkout','staycore_manage_housekeeping',
                    'staycore_view_reports','staycore_view_activity',
                ],
            ],
            'front_desk' => [
                'label' => 'Front Desk',
                'caps' => [
                    'staycore_view_pms','staycore_manage_reservations',
                    'staycore_view_guests','staycore_view_guest_contact','staycore_view_guest_id','staycore_upload_guest_id',
                    'staycore_manage_payments','staycore_checkout','staycore_view_activity',
                ],
            ],
            'housekeeping' => [
                'label' => 'Housekeeping',
                'caps' => ['staycore_view_pms','staycore_manage_housekeeping'],
            ],
            'accounts' => [
                'label' => 'Accounts',
                'caps' => ['staycore_view_pms','staycore_view_guests','staycore_manage_payments','staycore_view_reports','staycore_view_activity'],
            ],
            'read_only' => [
                'label' => 'Read Only',
                'caps' => ['staycore_view_pms','staycore_view_reports','staycore_view_activity'],
            ],
        ];
    }

    public static function assign_preset(int $user_id,string $preset): bool {
        $presets=self::presets();
        if(!isset($presets[$preset])) return false;
        $user=get_userdata($user_id);
        if(!$user instanceof WP_User) return false;

        foreach(self::capabilities() as $cap){
            if($cap!=='read') $user->remove_cap($cap);
        }
        foreach($presets[$preset]['caps'] as $cap) $user->add_cap($cap,true);
        update_user_meta($user_id,self::PRESET_META,$preset);

        if(class_exists('StayCore_DB')){
            StayCore_DB::log('staff_access_changed','user',$user_id,'StayCore staff access changed.',['preset'=>$preset]);
        }
        return true;
    }

    public static function ensure_roles(): void {
        $role_map=[
            'staycore_owner'=>'owner',
            'staycore_manager'=>'manager',
            'staycore_front_desk'=>'front_desk',
            'staycore_housekeeping'=>'housekeeping',
            'staycore_accounts'=>'accounts',
            'staycore_read_only'=>'read_only',
        ];
        $presets=self::presets();
        $all=self::capabilities();

        foreach($role_map as $slug=>$preset){
            $def=$presets[$preset];
            $role=get_role($slug) ?: add_role($slug,'StayCore '.$def['label'],self::role_caps($def['caps']));
            if(!$role) continue;
            foreach($all as $cap) $role->remove_cap($cap);
            foreach(self::role_caps($def['caps']) as $cap=>$grant) $role->add_cap($cap,$grant);
        }

        $admin=get_role('administrator');
        if($admin) foreach($all as $cap) $admin->add_cap($cap,true);
    }

    public static function can(string $cap): bool {
        return current_user_can($cap) || current_user_can('manage_options');
    }

    public static function auth_cookie_expiration(int $length, int $user_id, bool $remember): int {
        $user = get_userdata($user_id);
        if ($user instanceof WP_User && user_can($user,'staycore_view_pms') && !user_can($user,'manage_options')) {
            return 12 * HOUR_IN_SECONDS;
        }
        return $length;
    }

    public static function profile_fields(WP_User $user): void {
        if (!self::can('staycore_manage_staff')) return;
        $name=(string)get_user_meta($user->ID,self::NAME_META,true);
        if($name==='') $name=$user->display_name;
        $has_pin=(string)get_user_meta($user->ID,self::PIN_META,true)!=='';
        $has_previous=(string)get_user_meta($user->ID,self::PREVIOUS_PIN_META,true)!=='';
        $preset=(string)get_user_meta($user->ID,self::PRESET_META,true);
        $presets=self::presets();

        wp_nonce_field('staycore_staff_security_'.$user->ID,'staycore_staff_security_nonce');
        echo '<h2>StayCore staff access</h2><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label for="staycore_staff_preset">PMS access</label></th><td><select id="staycore_staff_preset" name="staycore_staff_preset"><option value="">No StayCore access</option>';
        foreach($presets as $key=>$def) echo '<option value="'.esc_attr($key).'" '.selected($preset,$key,false).'>'.esc_html($def['label']).'</option>';
        echo '</select><p class="description">Adds PMS permissions to this existing WordPress user without changing their normal WordPress/POS role.</p></td></tr>';
        echo '<tr><th><label for="staycore_staff_name">Sign-in name</label></th><td><input type="text" class="regular-text" id="staycore_staff_name" name="staycore_staff_name" value="'.esc_attr($name).'" autocomplete="off"><p class="description">Employees sign in with this name and a 4-digit PIN.</p></td></tr>';
        echo '<tr><th><label for="staycore_staff_pin">4-digit PIN</label></th><td><input type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" class="regular-text" id="staycore_staff_pin" name="staycore_staff_pin" value="" autocomplete="new-password"><p class="description">'.($has_pin?'A PIN is set. Enter four digits to replace it.':'No PIN is set yet. Enter exactly four digits to enable staff sign-in.').'</p>';
        if($has_previous) echo '<label style="display:block;margin-top:10px"><input type="checkbox" name="staycore_revert_pin" value="1"> Revert to previous PIN</label><p class="description">Restores the immediately previous PIN without revealing it.</p>';
        echo '</td></tr></tbody></table>';
    }

    public static function save_profile_fields(int $user_id): void {
        if (!self::can('staycore_manage_staff') || !current_user_can('edit_user',$user_id)) return;
        $nonce = sanitize_text_field(wp_unslash($_POST['staycore_staff_security_nonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce,'staycore_staff_security_'.$user_id)) return;

        $name = trim(sanitize_text_field(wp_unslash($_POST['staycore_staff_name'] ?? '')));
        if ($name !== '') {
            $dupes = get_users([
                'meta_key' => self::NAME_META,
                'meta_value' => $name,
                'number' => 2,
                'fields' => 'ids',
            ]);
            $dupes = array_values(array_filter(array_map('intval',$dupes),fn($id)=>$id !== $user_id));
            if (!$dupes) update_user_meta($user_id,self::NAME_META,$name);
        }

        $preset=sanitize_key((string)wp_unslash($_POST['staycore_staff_preset'] ?? ''));
        if($preset!==''){
            self::assign_preset($user_id,$preset);
        } else {
            $user=get_userdata($user_id);
            if($user instanceof WP_User){
                foreach(self::capabilities() as $cap) if($cap!=='read') $user->remove_cap($cap);
            }
            delete_user_meta($user_id,self::PRESET_META);
        }

        if(!empty($_POST['staycore_revert_pin'])){
            $current=(string)get_user_meta($user_id,self::PIN_META,true);
            $previous=(string)get_user_meta($user_id,self::PREVIOUS_PIN_META,true);
            if($previous!==''){
                update_user_meta($user_id,self::PIN_META,$previous);
                if($current!=='') update_user_meta($user_id,self::PREVIOUS_PIN_META,$current);
                if(class_exists('StayCore_DB')) StayCore_DB::log('staff_pin_reverted','user',$user_id,'Staff PIN reverted to previous value.');
            }
            return;
        }

        $pin=preg_replace('/\D+/','',(string)wp_unslash($_POST['staycore_staff_pin'] ?? ''));
        if($pin!=='' && preg_match('/^\d{4}$/',$pin)){
            $current=(string)get_user_meta($user_id,self::PIN_META,true);
            if($current!=='') update_user_meta($user_id,self::PREVIOUS_PIN_META,$current);
            update_user_meta($user_id,self::PIN_META,wp_hash_password($pin));
            if(class_exists('StayCore_DB')) StayCore_DB::log('staff_pin_changed','user',$user_id,'Staff PIN changed.');
        }
    }

    private static function normalized_name(string $name): string {
        return strtolower(trim(preg_replace('/\s+/',' ',$name)));
    }

    private static function remote_ip(): string {
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        return substr($ip,0,64);
    }

    private static function attempt_key(string $name): string {
        return 'staycore_pin_' . substr(hash('sha256',self::remote_ip().'|'.self::normalized_name($name)),0,32);
    }

    private static function ip_key(): string {
        return 'staycore_pin_ip_' . substr(hash('sha256',self::remote_ip()),0,32);
    }

    private static function attempts(string $key): int {
        return max(0,(int)get_transient($key));
    }

    private static function bump_attempts(string $name): void {
        $key = self::attempt_key($name);
        set_transient($key,self::attempts($key)+1,self::PIN_LOCK_SECONDS);
        $ip_key = self::ip_key();
        set_transient($ip_key,self::attempts($ip_key)+1,self::PIN_LOCK_SECONDS);
    }

    private static function clear_attempts(string $name): void {
        delete_transient(self::attempt_key($name));
    }

    private static function find_staff(string $name) {
        global $wpdb;
        $normalized = self::normalized_name($name);
        if ($normalized === '') return null;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND LOWER(TRIM(meta_value))=%s LIMIT 2",
            self::NAME_META,
            $normalized
        ));

        if (count($ids) === 0) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE LOWER(TRIM(user_login))=%s LIMIT 2",
                $normalized
            ));
        }

        if (count($ids) !== 1) return null;
        $user = get_userdata((int)$ids[0]);
        if (!$user instanceof WP_User || !user_can($user,'staycore_view_pms')) return null;
        return $user;
    }

    public static function authenticate_staff(string $name, string $pin) {
        $name = trim($name);
        $pin = preg_replace('/\D+/','',$pin);

        if (self::attempts(self::attempt_key($name)) >= self::PIN_ATTEMPTS || self::attempts(self::ip_key()) >= self::IP_ATTEMPTS) {
            return new WP_Error('staycore_pin_locked','Too many sign-in attempts. Try again in 15 minutes.');
        }

        if ($name === '' || !preg_match('/^\d{4}$/',$pin)) {
            self::bump_attempts($name);
            return new WP_Error('staycore_bad_pin','Incorrect name or PIN.');
        }

        $user = self::find_staff($name);
        if (!$user) {
            self::bump_attempts($name);
            return new WP_Error('staycore_bad_pin','Incorrect name or PIN.');
        }

        $hash = (string)get_user_meta($user->ID,self::PIN_META,true);
        if ($hash === '' || !wp_check_password($pin,$hash,$user->ID)) {
            self::bump_attempts($name);
            if (class_exists('StayCore_DB')) StayCore_DB::log('staff_login_failed','user',$user->ID,'Failed staff PIN sign-in.');
            return new WP_Error('staycore_bad_pin','Incorrect name or PIN.');
        }

        self::clear_attempts($name);
        return $user;
    }
}
