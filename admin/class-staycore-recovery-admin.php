<?php
if (!defined('ABSPATH')) exit;

/**
 * Backup & Recovery readiness console (read-only foundation).
 *
 * Intentionally DOES NOT export personal data, accept uploads, or perform restores.
 * A future restore engine must require an independently verified offsite snapshot,
 * a tested staging drill, an explicit admin confirmation, and an audit trail.
 */
final class StayCore_Recovery_Admin {
    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
    }

    public static function menu(): void {
        add_submenu_page(
            'staycore-pms',
            'Backup & Recovery',
            'Backup & Recovery',
            'manage_options',
            'staycore-backup-recovery',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Administrator access required.', 'staycore-pms'), '', ['response' => 403]);
        }
        global $wpdb;
        $tables = StayCore_DB::tables();
        $counts = [];
        foreach ($tables as $name => $table) {
            // Table identifiers are supplied only by StayCore_DB::tables(), never user input.
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
            $counts[$name] = $exists ? (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`') : null;
        }
        echo '<div class="wrap"><h1>StayCore PMS — Backup &amp; Recovery</h1>';
        echo '<div class="notice notice-warning inline"><p><strong>Restore locked.</strong> Restoration remains locked. Encrypted upload-only snapshots are available after server-side Vault configuration. No restore engine is enabled.</p></div>';
        echo '<h2>Database inventory</h2><p>Counts are read-only. No guest details or credentials are displayed.</p>';
        echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Table</th><th>Rows</th><th>Status</th></tr></thead><tbody>';
        foreach ($counts as $name => $count) {
            echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($count === null ? '—' : (string) $count) . '</td><td>' . esc_html($count === null ? 'Missing' : 'Found') . '</td></tr>';
        }
        echo '</tbody></table>';
        $last = get_option('staycore_last_backup', []);
        echo '<h2>Encrypted offsite snapshot</h2>';
        echo '<p>Supabase Vault connection: <strong>' . (StayCore_Backup::configured() ? 'Configured' : 'Not configured') . '</strong>. Secrets are never shown in this dashboard.</p>';
        if (is_array($last) && !empty($last['utc'])) echo '<p>Most recent upload: ' . esc_html($last['utc']) . ' (uploaded, not independently restore-tested)</p>';
        $notice = get_transient('staycore_backup_notice_' . get_current_user_id());
        if (is_array($notice)) { echo '<div class="notice notice-' . ($notice['code'] === 'uploaded' ? 'success' : 'error') . ' inline"><p>' . esc_html($notice['message']) . '</p></div>'; delete_transient('staycore_backup_notice_' . get_current_user_id()); }
        if (StayCore_Backup::configured()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="staycore_backup_now">';
            wp_nonce_field('staycore_backup_now');
            submit_button('Create encrypted offsite snapshot');
            echo '</form>';
        }
        echo '<h2>Recovery prerequisites</h2><ol>';
        echo '<li>Verify encrypted, independent offsite booking backup</li>';
        echo '<li>Validate snapshot integrity and version compatibility</li>';
        echo '<li>Complete a non-destructive staging restore drill</li>';
        echo '<li>Enable explicit administrator-only, audited restore workflow</li>';
        echo '</ol><p><strong>Production data has not been changed by this screen.</strong></p></div>';
    }
}
