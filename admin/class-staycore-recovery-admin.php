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
        echo '<div class="notice notice-warning inline"><p><strong>Restore locked.</strong> No backup export, Supabase connection, or restore engine is enabled in this version. This page performs read-only checks only.</p></div>';
        echo '<h2>Database inventory</h2><p>Counts are read-only. No guest details or credentials are displayed.</p>';
        echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Table</th><th>Rows</th><th>Status</th></tr></thead><tbody>';
        foreach ($counts as $name => $count) {
            echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($count === null ? '—' : (string) $count) . '</td><td>' . esc_html($count === null ? 'Missing' : 'Found') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Recovery prerequisites</h2><ol>';
        echo '<li>Verify encrypted, independent offsite booking backup</li>';
        echo '<li>Validate snapshot integrity and version compatibility</li>';
        echo '<li>Complete a non-destructive staging restore drill</li>';
        echo '<li>Enable explicit administrator-only, audited restore workflow</li>';
        echo '</ol><p><strong>Production data has not been changed by this screen.</strong></p></div>';
    }
}
