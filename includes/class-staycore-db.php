<?php
if (!defined('ABSPATH')) exit;

final class StayCore_DB {
    public static function tables(): array {
        global $wpdb;
        return [
            'units' => $wpdb->prefix . 'staycore_units',
            'guests' => $wpdb->prefix . 'staycore_guests',
            'reservations' => $wpdb->prefix . 'staycore_reservations',
            'reservation_units' => $wpdb->prefix . 'staycore_reservation_units',
            'payments' => $wpdb->prefix . 'staycore_payments',
            'tasks' => $wpdb->prefix . 'staycore_tasks',
            'activity' => $wpdb->prefix . 'staycore_activity',
        ];
    }

    public static function activate(): void { self::upgrade(get_option('staycore_pms_db_version', '0.0.0')); }

    public static function maybe_upgrade(): void {
        $current = (string)get_option('staycore_pms_db_version', '0.0.0');
        if (version_compare($current, STAYCORE_PMS_VERSION, '<')) self::upgrade($current);
    }

    private static function upgrade(string $from): void {
        self::install_schema();
        self::ensure_roles();
        if (version_compare($from, '0.3.0', '<')) self::migrate_reservation_units();
        if (class_exists('StayCore_Public')) StayCore_Public::ensure_page();
        update_option('staycore_pms_db_version', STAYCORE_PMS_VERSION);
    }

    private static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$t['units']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'bed',
            room_group VARCHAR(120) NULL,
            capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            base_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'available',
            housekeeping_status VARCHAR(30) NOT NULL DEFAULT 'clean',
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY status (status), KEY room_group (room_group), KEY housekeeping_status (housekeeping_status)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['guests']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(120) NOT NULL,
            last_name VARCHAR(120) NULL,
            phone VARCHAR(40) NULL,
            email VARCHAR(190) NULL,
            nationality VARCHAR(100) NULL,
            id_type VARCHAR(60) NULL,
            id_number VARCHAR(120) NULL,
            notes TEXT NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY phone (phone), KEY email (email)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['reservations']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(60) NOT NULL DEFAULT 'direct',
            external_ref VARCHAR(190) NULL,
            check_in DATETIME NOT NULL,
            check_out DATETIME NOT NULL,
            adults SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            children SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'confirmed',
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'INR',
            notes TEXT NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY stay_dates (check_in, check_out), KEY status (status), KEY guest_id (guest_id), KEY unit_id (unit_id), KEY external_ref (external_ref)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['reservation_units']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NOT NULL,
            guests SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY reservation_unit (reservation_id, unit_id), KEY reservation_id (reservation_id), KEY unit_id (unit_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['payments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'INR',
            method VARCHAR(60) NOT NULL DEFAULT 'cash',
            status VARCHAR(30) NOT NULL DEFAULT 'captured',
            external_ref VARCHAR(190) NULL,
            meta LONGTEXT NULL,
            paid_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY reservation_id (reservation_id), KEY external_ref (external_ref), KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['tasks']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id BIGINT UNSIGNED NULL,
            reservation_id BIGINT UNSIGNED NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'housekeeping',
            title VARCHAR(190) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            assigned_user_id BIGINT UNSIGNED NULL,
            due_at DATETIME NULL,
            notes TEXT NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY status (status), KEY type (type), KEY due_at (due_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['activity']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            message VARCHAR(255) NOT NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY entity (entity_type, entity_id), KEY created_at (created_at)
        ) $charset;");

        if (!get_option('staycore_pms_settings')) add_option('staycore_pms_settings', [
            'property_name' => 'Andaz Vibe Stay - Arambol, Goa', 'currency' => 'INR',
            'timezone' => wp_timezone_string() ?: 'Asia/Kolkata', 'check_in_time' => '13:00', 'check_out_time' => '11:00',
        ]);
    }

    private static function ensure_roles(): void {
        $caps = ['read'=>true,'staycore_view_pms'=>true,'manage_staycore_pms'=>true,'staycore_manage_reservations'=>true,'staycore_manage_payments'=>true,'staycore_manage_housekeeping'=>true,'staycore_view_reports'=>true];
        $manager = get_role('staycore_manager') ?: add_role('staycore_manager','StayCore Manager',$caps);
        if ($manager) foreach ($caps as $cap=>$grant) $manager->add_cap($cap,$grant);

        $front_caps = ['read'=>true,'staycore_view_pms'=>true,'staycore_manage_reservations'=>true,'staycore_manage_payments'=>true,'staycore_manage_housekeeping'=>true];
        $front = get_role('staycore_front_desk') ?: add_role('staycore_front_desk','StayCore Front Desk',$front_caps);
        if ($front) foreach ($front_caps as $cap=>$grant) $front->add_cap($cap,$grant);

        $house_caps = ['read'=>true,'staycore_view_pms'=>true,'staycore_manage_housekeeping'=>true];
        $house = get_role('staycore_housekeeping') ?: add_role('staycore_housekeeping','StayCore Housekeeping',$house_caps);
        if ($house) foreach ($house_caps as $cap=>$grant) $house->add_cap($cap,$grant);

        $admin = get_role('administrator');
        if ($admin) foreach ($caps as $cap=>$grant) $admin->add_cap($cap,$grant);
    }

    private static function migrate_reservation_units(): void {
        global $wpdb;
        $t = self::tables();
        $now = current_time('mysql');
        $wpdb->query("INSERT IGNORE INTO {$t['reservation_units']} (reservation_id,unit_id,guests,created_at) SELECT id,unit_id,GREATEST(1,adults+children),created_at FROM {$t['reservations']}");

        $groups = $wpdb->get_results("SELECT external_ref,guest_id,source,check_in,check_out,COUNT(*) c FROM {$t['reservations']} WHERE external_ref IS NOT NULL AND external_ref<>'' GROUP BY external_ref,guest_id,source,check_in,check_out HAVING c>1", ARRAY_A);
        foreach ($groups as $g) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t['reservations']} WHERE external_ref=%s AND guest_id=%d AND source=%s AND check_in=%s AND check_out=%s ORDER BY id ASC",
                $g['external_ref'], $g['guest_id'], $g['source'], $g['check_in'], $g['check_out']
            ), ARRAY_A);
            if (count($rows) < 2) continue;
            $master = (int)$rows[0]['id']; $sum = 0.0; $all_ids = [];
            foreach ($rows as $row) { $sum += (float)$row['total']; $all_ids[] = (int)$row['id']; }
            foreach (array_slice($rows,1) as $row) {
                $old = (int)$row['id']; $unit = (int)$row['unit_id'];
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$t['reservation_units']} (reservation_id,unit_id,guests,created_at) VALUES (%d,%d,%d,%s)", $master,$unit,max(1,(int)$row['adults']+(int)$row['children']),$now));
                $wpdb->update($t['payments'], ['reservation_id'=>$master], ['reservation_id'=>$old]);
                $wpdb->delete($t['reservation_units'], ['reservation_id'=>$old]);
                $wpdb->delete($t['reservations'], ['id'=>$old]);
            }
            $wpdb->update($t['reservations'], ['total'=>$sum,'updated_at'=>$now], ['id'=>$master]);
            self::log('group_migrated','reservation',$master,'Grouped legacy multi-unit booking into one reservation.',['legacy_ids'=>$all_ids]);
        }
    }

    public static function log(string $action, string $entity_type, ?int $entity_id, string $message, array $meta=[]): void {
        global $wpdb; $t=self::tables();
        $wpdb->insert($t['activity'],[
            'user_id'=>get_current_user_id() ?: null,'action'=>sanitize_key($action),'entity_type'=>sanitize_key($entity_type),
            'entity_id'=>$entity_id,'message'=>sanitize_text_field($message),'meta'=>$meta?wp_json_encode($meta):null,'created_at'=>current_time('mysql')
        ]);
    }
}
