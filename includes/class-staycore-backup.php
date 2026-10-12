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
            'message'=>is_wp_error($status) ? $status->get_error_message() : 'Encrypted snapshot uploaded and verified by private readback; restore drill remains pending.',
        ], 90);
        wp_safe_redirect(admin_url('admin.php?page=staycore-backup-recovery'));
        exit;
    }

    public static function upload() {
        if (!self::configured()) return new WP_Error('vault_unconfigured', 'Vault configuration is missing or invalid.');
        global $wpdb;
        $inventory = [];
        $total_bytes = 0;
        $tables = StayCore_DB::tables();
        // Consistent snapshots require transactional tables. Fail closed otherwise.
        foreach ($tables as $name=>$table) {
            $engine = $wpdb->get_var($wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            ));
            if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
                return new WP_Error('nontransactional_table', 'Backup requires InnoDB table: ' . $name);
            }
        }
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
            $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') === false) {
            return new WP_Error('transaction_failed', 'Could not start a consistent read-only database snapshot.');
        }
        $read_error = null;
        foreach ($tables as $name=>$table) {
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $rows = $wpdb->get_results('SELECT * FROM ' . $quoted, ARRAY_A);
            if (!is_array($rows) || $wpdb->last_error) {
                $read_error = new WP_Error('read_failed', 'Could not read PMS table ' . $name);
                break;
            }
            $encoded_rows = wp_json_encode($rows);
            if (!is_string($encoded_rows)) {
                $read_error = new WP_Error('encode_failed', 'Could not encode PMS table ' . $name);
                break;
            }
            $total_bytes += strlen($encoded_rows);
            if ($total_bytes > 12582912) {
                $read_error = new WP_Error('snapshot_limit', 'Snapshot exceeds the 12 MiB safety limit; use a streamed snapshot engine.');
                break;
            }
            $inventory[$name] = $rows;
        }
        // Never keep a database transaction open during encryption or remote network calls.
        $wpdb->query('ROLLBACK');
        if ($read_error) return $read_error;
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
        // Verify private object readback before reporting a successful backup.
        $check = wp_remote_get($url, [
            'timeout'=>45, 'redirection'=>0, 'limit_response_size'=>20000000,
            'sslverify'=>true,
            'headers'=>['apikey'=>STAYCORE_VAULT_KEY, 'Authorization'=>'Bearer ' . STAYCORE_VAULT_KEY],
        ]);
        if (is_wp_error($check) || (int)wp_remote_retrieve_response_code($check) !== 200) {
            return new WP_Error('vault_readback', 'Upload succeeded, but independent Vault readback failed.');
        }
        $remote = wp_remote_retrieve_body($check);
        if (!hash_equals(hash('sha256', $wrapper), hash('sha256', $remote))) {
            return new WP_Error('vault_integrity', 'The remote ciphertext differs from the uploaded snapshot.');
        }
        $envelope = json_decode($remote, true);
        if (!is_array($envelope) || !isset($envelope['nonce'], $envelope['ciphertext'])) {
            return new WP_Error('vault_integrity', 'Invalid remote snapshot envelope.');
        }
        $remote_nonce = base64_decode($envelope['nonce'], true);
        $remote_ciphertext = base64_decode($envelope['ciphertext'], true);
        if ($remote_nonce === false || strlen($remote_nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $remote_ciphertext === false) {
            return new WP_Error('vault_integrity', 'Invalid remote encryption fields.');
        }
        $plaintext = sodium_crypto_secretbox_open($remote_ciphertext, $remote_nonce, $key);
        if ($plaintext === false || !hash_equals(hash('sha256', $data), hash('sha256', $plaintext))) {
            return new WP_Error('vault_integrity', 'Remote snapshot authentication/decryption failed.');
        }
        update_option('staycore_last_backup', [
            'utc'=>gmdate('c'), 'object'=>$object, 'sha256'=>hash('sha256', $wrapper),
            'tables'=>array_map('count',$inventory), 'status'=>'ciphertext_verified',
        ], false);
        return true;
    }
}
