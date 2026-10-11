<?php
if (!defined('ABSPATH')) exit;

/**
 * Server-side revision ledger for booking recoverability.
 *
 * This is NOT a replacement for independently retained off-site database
 * backups. The snapshots live in the same WordPress database and inherit
 * its disaster-recovery and access-control limitations.
 */
final class StayCore_Data_Safety {
    /**
     * Capture the previous complete booking state BEFORE modifying it.
     * Snapshot creation is mandatory for mutating existing reservations.
     * Never include ID images, guest meta, WP auth tokens or API credentials.
     */
    public static function snapshot_reservation(int $id, string $action): bool {
        global $wpdb;
        if ($id < 1 || !preg_match('/^[a-z][a-z0-9_]{2,59}$/D', $action)) return false;
        $t = StayCore_DB::tables();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['reservations']} WHERE id=%d", $id), ARRAY_A);
        if (!is_array($row)) return false;

        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id,first_name,last_name,phone,email,nationality,created_at,updated_at FROM {$t['guests']} WHERE id=%d",
            (int)$row['guest_id']
        ), ARRAY_A);
        if (!is_array($guest)) return false;

        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['reservation_units']} WHERE reservation_id=%d ORDER BY id", $id
        ), ARRAY_A);
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['reservation_guests']} WHERE reservation_id=%d ORDER BY id", $id
        ), ARRAY_A);
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT id,reservation_id,amount,currency,method,status,external_ref,paid_at,created_at FROM {$t['payments']} WHERE reservation_id=%d ORDER BY id",
            $id
        ), ARRAY_A);
        if (!is_array($units) || !is_array($members) || !is_array($payments)) return false;

        $payload = wp_json_encode([
            'schema_version' => 1,
            'reservation' => $row,
            'guest' => $guest,
            'reservation_units' => $units,
            'reservation_guests' => $members,
            'payments' => $payments,
        ]);
        if (!is_string($payload) || $payload === '') return false;
        // An independent encrypted file is written before the SQL revision.
        // A disk/crypto failure must never permit an unprotected booking edit.
        if (!StayCore_Private_Backups::save($id,$action,$payload,hash('sha256',$payload))) return false;
        $inserted = $wpdb->insert($t['revisions'], [
            'reservation_id' => $id,
            'action' => $action,
            'payload' => $payload,
            'checksum' => hash('sha256', $payload),
            'user_id' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);
        return $inserted === 1 && (int)$wpdb->insert_id > 0;
    }

    /** Direct callers should refuse changes if a snapshot fails. */
    public static function safeguard(int $reservation_id, string $action) {
        if (self::snapshot_reservation($reservation_id, $action)) return true;
        error_log('StayCore: blocked booking write because reservation revision could not be saved. Booking ID: '. $reservation_id);
        return new WP_Error(
            'booking_snapshot_failed',
            'Could not preserve the booking history. No changes were saved. Contact the manager.',
            ['status'=>503]
        );
    }

    /** Snapshot all bookings once a day, including those created elsewhere. */
    public static function checkpoint(): void {
        global $wpdb;
        $t=StayCore_DB::tables();
        $today=current_time('Y-m-d');
        $ids=$wpdb->get_col("SELECT id FROM {$t['reservations']} ORDER BY id ASC LIMIT 2001");
        if (!is_array($ids)) return;
        if (count($ids)>2000) {
            self::alert('Booking checkpoint exceeds the safe batch limit (2000). Set up a separate DB backup.');
            return;
        }
        $failures=0;
        foreach ($ids as $id) {
            $already=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$t['revisions']} WHERE reservation_id=%d AND action='daily_checkpoint' AND created_at>=%s LIMIT 1",
                (int)$id, $today.' 00:00:00'
            ));
            if ($already) continue;
            if (!self::snapshot_reservation((int)$id, 'daily_checkpoint')) $failures++;
        }
        $current=count($ids);
        $previous=get_option('staycore_last_booking_checkpoint_count',null);
        if ($previous!==null && $current<(int)$previous) {
            self::alert('StayCore booking count decreased from '.(int)$previous.' to '.$current.'. Investigate before modifying booking data.');
        }
        if ($failures) self::alert($failures.' StayCore booking revisions could not be saved. Check the database immediately.');
        if (!$failures) update_option('staycore_last_booking_checkpoint_count',$current,false);
        update_option('staycore_last_booking_checkpoint_time',current_time('mysql'),false);
    }

    private static function alert(string $message): void {
        error_log('StayCore data integrity: '.$message);
        $email=(string)get_option('admin_email','');
        if ($email!=='' && is_email($email)) {
            wp_mail($email,'Andaz PMS — booking data integrity alert',$message);
        }
    }

    public static function schedule_checkpoint(): void {
        if (!wp_next_scheduled('staycore_booking_safety_checkpoint')) {
            wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','staycore_booking_safety_checkpoint');
        }
    }

    /** Executed as a read/write cron task, separate from HTTP booking requests. */
    public static function boot(): void {
        add_action('staycore_booking_safety_checkpoint',[__CLASS__,'checkpoint']);
        self::schedule_checkpoint();
    }
}
