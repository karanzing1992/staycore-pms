<?php
if (!defined('ABSPATH')) exit;

/**
 * Encrypted, upload-only StayCore snapshots.
 * No restore, no local archive, no browser access to secrets.
 * Configuration is server-side in wp-config.php:
 * STAYCORE_VAULT_URL = https://<project>.supabase.co
 * STAYCORE_VAULT_KEY = a secret/service key (never publish)
 * STAYCORE_VAULT_BUCKET = a PRIVATE storage bucket
 * STAYCORE_BACKUP_KEY = base64 of 32 cryptographically random bytes
 */
final class StayCore_Backup {
    public static function boot(): void {
        add_action('admin_post_staycore_backup_now', [__CLASS__, 'manual']);
    }

    public static function configured(): bool {
        return defined('STAYCORE_VAULT_URL') && defined('STAYCORE_VAULT_KEY')
            && defined('STAYCORE_VAULT_BUCKET') && defined('STAYCORE_BACKUP_KEY')
            && filter_var(STAYCORE_VAULT_URL, FILTER_VALIDATE_URL)
            && str_starts_with(STAYCORE_VAULT_URL, 'https://')
            && is_string(STAYCORE_VAULT_KEY) && strlen(STAYCORE_VAULT_KEY) > 20
            && preg_match('/^[a-z0-9][a-z0-9_-]{1,60}$/', STAYCORE_VAULT_BUCKET)
            && strlen((string)base64_decode(STAYCORE_BACKUP_KEY, true)) === 32
            && function_exists('sodium_crypto_secretbox');
    }

    public static function manual(): void {
        if (!current_user_can('manage_options')) wp_die('Not authorized.', '', ['response'=>403]);
        check_admin_referer('staycore_backup_now');
        $status = self::upload();
        $code = is_wp_error($status) ? 'failed' : 'uploaded';
        set_transient('staycore_backup_notice_' . get_current_user_id(), [
            'code'=>$code,
            'message'=>is_wp_error($status) ? $status->get_error_message() : 'Encrypted snapshot uploaded; remote restore drill remains pending.',
        ], 90);
        wp_safe_redirect(admin_url('admin.php?page=staycore-backup-recovery'));
        exit;
    }

    public static function upload() {
        if (!self::configured()) return new WP_Error('vault_unconfigured', 'Vault configuration is missing or invalid.');
        global $wpdb;
        $inventory = [];
        $total_bytes = 0;
        foreach (StayCore_DB::tables() as $name=>$table) {
            // Identifiers originate from the plugin's fixed table map, not HTTP requests.
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                return new WP_Error('table_missing', 'Required PMS table missing: ' . $name);
            }
            $rows = $wpdb->get_results('SELECT * FROM ' . $quoted, ARRAY_A);
            if (!is_array($rows)) return new WP_Error('read_failed', 'Could not read PMS table ' . $name);
            $inventory[$name] = $rows;
            $total_bytes += strlen(wp_json_encode($rows));
            if ($total_bytes > 12582912) return new WP_Error('snapshot_limit', 'Snapshot exceeds the 12 MiB safety limit; use a streamed snapshot engine.');
        }
        $timestamp = gmdate('Y-m-d\\TH-i-s\\Z');
        $data = wp_json_encode([
            'format'=>'staycore-snapshot-v1',
            'plugin_version'=>STAYCORE_PMS_VERSION,
            'created_utc'=>gmdate('c'),
            'site_hash'=>hash('sha256', home_url()),
            'tables'=>$inventory,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($data)) return new WP_Error('encode_failed', 'Unable to encode snapshot.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = base64_decode(STAYCORE_BACKUP_KEY, true);
        $payload = sodium_crypto_secretbox($data, $nonce, $key);
        $wrapper = wp_json_encode([
            'format'=>'staycore-secretbox-v1',
            'alg'=>'XSalsa20-Poly1305',
            'nonce'=>base64_encode($nonce),
            'ciphertext'=>base64_encode($payload),
            'sha256'=>hash('sha256', $data),
        ]);
        $object = 'staycore/' . hash('sha256', home_url()) . '/' . $timestamp . '-' . bin2hex(random_bytes(5)) . '.json';
        $url = rtrim(STAYCORE_VAULT_URL, '/') . '/storage/v1/object/' . rawurlencode(STAYCORE_VAULT_BUCKET) . '/' . $object;
        $response = wp_remote_post($url, [
            'timeout'=>45,
            'redirection'=>0,
            'headers'=>[
                'apikey'=>STAYCORE_VAULT_KEY,
                'Authorization'=>'Bearer ' . STAYCORE_VAULT_KEY,
                'Content-Type'=>'application/json',
                'x-upsert'=>'false',
            ],
            'body'=>$wrapper,
            'sslverify'=>true,
        ]);
        if (is_wp_error($response)) return new WP_Error('vault_network', 'Vault upload failed (network).');
        $http = (int)wp_remote_retrieve_response_code($response);
        if (!in_array($http, [200,201], true)) return new WP_Error('vault_upload', 'Vault rejected snapshot (HTTP ' . $http . ').');
        update_option('staycore_last_backup', [
            'utc'=>gmdate('c'), 'object'=>$object, 'sha256'=>hash('sha256', $wrapper),
            'tables'=>array_map('count',$inventory), 'status'=>'uploaded_unverified',
        ], false);
        return true;
    }
}
