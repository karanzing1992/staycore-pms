<?php
if (!defined('ABSPATH')) exit;

/**
 * Separate, encrypted StayCore booking snapshots on private server disk.
 *
 * These files are an independently stored copy of an individual booking
 * revision; they are NOT an off-host backup. Operators must copy the entire
 * vault AND the key to a separately secured/off-site backup destination.
 */
final class StayCore_Private_Backups {
    private static function root(): ?string {
        $candidate = defined('STAYCORE_PRIVATE_BACKUP_DIR')
            ? (string) STAYCORE_PRIVATE_BACKUP_DIR
            : dirname(rtrim(ABSPATH, '/\\')) . '/staycore-private-vault';
        if ($candidate === '' || !str_starts_with($candidate, '/') || str_contains($candidate, "\\0")) return null;
        $parent = realpath(dirname($candidate));
        if ($parent === false) return null;
        $root = $parent . '/' . basename($candidate);
        $web_root = realpath(ABSPATH);
        $document_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
            ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        foreach ([$web_root, $document_root] as $public) {
            if ($public && ($root === $public || str_starts_with($root, rtrim($public, '/') . '/'))) return null;
        }
        // If WordPress itself is installed beneath public_html/www, then its
        // parent is often public: require an explicit private location.
        if (!defined('STAYCORE_PRIVATE_BACKUP_DIR') &&
            in_array(strtolower(basename($parent)), ['public_html','www','htdocs','httpdocs'], true)) return null;
        if (is_link($root)) return null;
        if (!is_dir($root) && !@mkdir($root, 0700, false)) return null;
        if (!is_dir($root) || !is_writable($root)) return null;
        @chmod($root, 0700);
        return $root;
    }

    private static function key(string $root): ?string {
        $file = $root . '/vault.key';
        if (is_link($file)) return null;
        if (is_file($file)) {
            $key = @file_get_contents($file);
            return is_string($key) && strlen($key) === 32 ? $key : null;
        }
        try { $key=random_bytes(32); } catch (Throwable $e) { return null; }
        // Exclusive creation prevents overwriting a concurrent key.
        $h=@fopen($file,'x');
        if ($h===false) {
            $existing=@file_get_contents($file);
            return is_string($existing) && strlen($existing)===32 ? $existing : null;
        }
        @chmod($file,0600);
        $ok=@fwrite($h,$key);
        @fflush($h);
        fclose($h);
        if ($ok!==32) { @unlink($file); return null; }
        return $key;
    }

    /** Returns true only when a complete encrypted, verified archive was saved. */
    public static function save(int $id, string $action, string $payload, string $checksum): bool {
        if ($id < 1 || !preg_match('/^[a-z][a-z0-9_]{2,59}$/D', $action) ||
            !hash_equals(hash('sha256',$payload),$checksum) || !function_exists('openssl_encrypt')) return false;
        $root=self::root();
        if ($root===null) return false;
        $key=self::key($root);
        if ($key===null) return false;
        try {
            $nonce=random_bytes(12);
            $file_id=bin2hex(random_bytes(16));
        } catch (Throwable $e) { return false; }
        $aad='staycore:v1:'.$id.':'.$action;
        $cipher=openssl_encrypt($payload,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if (!is_string($cipher) || strlen($tag)!==16) return false;
        $envelope=wp_json_encode([
            'format'=>'staycore-booking-v1','reservation_id'=>$id,
            'action'=>$action,'sha256'=>$checksum,
            'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
            'ciphertext'=>base64_encode($cipher),
        ]);
        if (!is_string($envelope) || $envelope==='') return false;
        $file=$root.'/booking-'.$id.'-'.$file_id.'.scbackup';
        $tmp=$file.'.tmp';
        $h=@fopen($tmp,'x');
        if ($h===false) return false;
        @chmod($tmp,0600);
        $bytes=@fwrite($h,$envelope);
        @fflush($h);
        fclose($h);
        if ($bytes!==strlen($envelope) || !@rename($tmp,$file)){
            @unlink($tmp);
            return false;
        }
        @chmod($file,0600);
        // Verify the saved archive immediately.
        $verify=@file_get_contents($file);
        $parsed=is_string($verify) ? json_decode($verify,true) : null;
        if (!is_array($parsed) ||
            !hash_equals($checksum,(string)($parsed['sha256']??'')) ||
            (self::decrypt($parsed,$key)!==$payload)){
            @unlink($file);
            return false;
        }
        return true;
    }

    private static function decrypt(array $envelope,string $key): ?string {
        if (($envelope['format']??'')!=='staycore-booking-v1') return null;
        $nonce=base64_decode((string)($envelope['nonce']??''),true);
        $tag=base64_decode((string)($envelope['tag']??''),true);
        $cipher=base64_decode((string)($envelope['ciphertext']??''),true);
        if ($nonce===false || strlen($nonce)!==12 || $tag===false || strlen($tag)!==16 || $cipher===false) return null;
        $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,
            'staycore:v1:'.(int)($envelope['reservation_id']??0).':'.(string)($envelope['action']??''));
        if (!is_string($plain) || !hash_equals((string)($envelope['sha256']??''),hash('sha256',$plain))) return null;
        return $plain;
    }
}
