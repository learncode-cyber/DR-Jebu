<?php
declare(strict_types=1);
// Encryption for stored secrets: Gemini API keys and every setting of type 'secret'
// (Meta App Secret, Page token, LinkedIn/TikTok client secrets).
//
// Key location: <account home>/secure/enc.key, i.e. OUTSIDE the public web root (public_html).
// It is created once with mode 0600. It is never written to the database.
// Format: "v1:" . base64( iv[12] . tag[16] . ciphertext ), AES-256-GCM.
//
// Limit (stated to the owner): if an attacker gets BOTH the database dump AND the server's
// secure/ folder, the secrets can be read. Keep the secure/ folder out of shared backups.

function sec_key_path(): string {
    return dirname(WEB_ROOT) . '/secure/enc.key';
}

/** 'file' when the key file is in use, 'derived' when the folder cannot be written (weaker fallback). */
function sec_key_source(): string {
    sec_key();
    return $GLOBALS['sec_key_source'] ?? 'file';
}

function sec_key(): string {
    if (isset($GLOBALS['sec_key_bin'])) {
        return $GLOBALS['sec_key_bin'];
    }
    $path = sec_key_path();
    $dir = dirname($path);
    if (is_file($path)) {
        $bin = base64_decode(trim((string) file_get_contents($path)), true);
        if ($bin === false || strlen($bin) !== 32) {
            throw new RuntimeException('enc.key সঠিক নয়। ফাইলটি পরিবর্তন করবেন না।');
        }
        $GLOBALS['sec_key_source'] = 'file';
        return $GLOBALS['sec_key_bin'] = $bin;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir)) {
        $bin = random_bytes(32);
        // 'x' = create only if missing: two processes cannot overwrite each other's key.
        $fh = @fopen($path, 'x');
        if ($fh !== false) {
            fwrite($fh, base64_encode($bin));
            fclose($fh);
            @chmod($path, 0600);
            $GLOBALS['sec_key_source'] = 'file';
            return $GLOBALS['sec_key_bin'] = $bin;
        }
        if (is_file($path)) {
            return sec_key(); // another process created it first; read it
        }
    }
    // Last resort: the folder cannot be written. Derive from APP_KEY (also on the server, so weaker).
    $GLOBALS['sec_key_source'] = 'derived';
    return $GLOBALS['sec_key_bin'] = hash('sha256', 'jebu-secret|' . APP_KEY, true);
}

function sec_is_enc(string $v): bool {
    return str_starts_with($v, 'v1:');
}

function sec_encrypt(string $plain): string {
    if ($plain === '' || sec_is_enc($plain)) {
        return $plain;
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', sec_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('encryption failed');
    }
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

/** Returns '' when the value cannot be decrypted (wrong key or damaged). Plain text passes through (legacy rows). */
function sec_decrypt(string $v): string {
    if ($v === '' || !sec_is_enc($v)) {
        return $v;
    }
    $bin = base64_decode(substr($v, 3), true);
    if ($bin === false || strlen($bin) < 29) {
        return '';
    }
    $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', sec_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    return $pt === false ? '' : $pt;
}

/** True for settings whose value must be stored encrypted. */
function sec_is_secret_setting(string $key): bool {
    $schema = settings_schema();
    return isset($schema[$key]) && $schema[$key][1] === 'secret';
}

/** Encrypts every plain-text secret setting once. Safe to call on every request (cheap check). */
function sec_migrate_settings(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    sec_migrate_plain_settings();
}

/** Does the work of sec_migrate_settings(); callable directly (tests). */
function sec_migrate_plain_settings(): int {
    $n = 0;
    try {
        foreach (q_all('SELECT skey, svalue FROM site_settings') as $r) {
            $k = (string) $r['skey'];
            $v = (string) $r['svalue'];
            if ($v !== '' && !sec_is_enc($v) && sec_is_secret_setting($k)) {
                q_exec('UPDATE site_settings SET svalue = ? WHERE skey = ?', [sec_encrypt($v), $k]);
                $n++;
            }
        }
    } catch (Throwable $e) {
        error_log('[jebu] sec_migrate_settings: ' . $e->getMessage());
    }
    return $n;
}
