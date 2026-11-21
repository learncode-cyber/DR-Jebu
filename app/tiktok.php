<?php
declare(strict_types=1);

/**
 * TikTok video posting (Content Posting API, Direct Post, FILE_UPLOAD).
 * Policy: nothing is posted automatically. The owner uploads a video as a draft and clicks "পোস্ট করুন".
 * Unaudited apps can only publish as private (SELF_ONLY); the default here is SELF_ONLY.
 * Videos are kept in app/private/tiktok/ which is blocked from the web.
 */

const TT_AUTH_URL = 'https://www.tiktok.com/v2/auth/authorize/';
const TT_TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
const TT_INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
const TT_STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';
const TT_SCOPES = 'user.info.basic,video.publish';
const TT_MAX_BYTES = 20 * 1024 * 1024;   // matches the host upload limit (.htaccess upload_max_filesize 20M)
const TT_TITLE_MAX = 2200;

function tt_tables(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS tiktok_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL DEFAULT '',
        file_name VARCHAR(190) NOT NULL,
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        privacy VARCHAR(40) NOT NULL DEFAULT 'SELF_ONLY',
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        publish_id VARCHAR(80) NULL,
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        posted_at DATETIME NULL,
        KEY idx_status (status, created_at)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Private folder for video files. Created with a deny-all .htaccess so nothing is served from the web. */
function tt_dir(): string {
    $dir = dirname(__DIR__) . '/app/private/tiktok';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $ht = dirname(__DIR__) . '/app/private/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function tt_cfg(): array {
    return [
        'client_key' => trim(setting('tiktok_client_key')),
        'client_secret' => trim(setting('tiktok_client_secret')),
        'token' => trim(setting('tiktok_access_token')),
        'refresh' => trim(setting('tiktok_refresh_token')),
        'expires_at' => (int) setting('tiktok_expires_at', '0'),
        'refresh_expires_at' => (int) setting('tiktok_refresh_expires_at', '0'),
    ];
}

function tt_connected(): bool {
    $c = tt_cfg();
    return $c['refresh'] !== '' && $c['refresh_expires_at'] > time();
}

function tt_redirect_uri(): string {
    return abs_url('/jebu-cp-3hr3f/tiktok-callback.php');
}

function tt_authorize_url(string $state): string {
    return TT_AUTH_URL . '?' . http_build_query([
        'client_key' => tt_cfg()['client_key'],
        'scope' => TT_SCOPES,
        'response_type' => 'code',
        'redirect_uri' => tt_redirect_uri(),
        'state' => $state,
    ]);
}

/** Exchanges the code for tokens. Returns [ok, message]. */
function tt_complete_connect(string $code): array {
    $c = tt_cfg();
    if ($c['client_key'] === '' || $c['client_secret'] === '') {
        return [false, 'TikTok Client Key বা Secret সেট করা নেই।'];
    }
    [$status, $res] = tt_http_form(TT_TOKEN_URL, [
        'client_key' => $c['client_key'], 'client_secret' => $c['client_secret'],
        'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => tt_redirect_uri(),
    ]);
    $json = json_decode($res, true);
    if ($status !== 200 || !is_array($json) || empty($json['access_token'])) {
        return [false, 'TikTok টোকেন পাওয়া যায়নি (HTTP ' . $status . ').'];
    }
    tt_store_tokens($json);
    return [true, 'TikTok সংযুক্ত হয়েছে।'];
}

function tt_store_tokens(array $json): void {
    $now = time();
    save_setting('tiktok_access_token', (string) $json['access_token']);
    save_setting('tiktok_expires_at', (string) ($now + (int) ($json['expires_in'] ?? 86400)));
    if (!empty($json['refresh_token'])) { save_setting('tiktok_refresh_token', (string) $json['refresh_token']); }
    save_setting('tiktok_refresh_expires_at', (string) ($now + (int) ($json['refresh_expires_in'] ?? 31536000)));
}

/** Returns a valid access token, refreshing it when it is close to expiry. '' when not connected. */
function tt_access_token(): string {
    $c = tt_cfg();
    if (!tt_connected()) { return ''; }
    if ($c['token'] !== '' && $c['expires_at'] - 300 > time()) { return $c['token']; }
    [$status, $res] = tt_http_form(TT_TOKEN_URL, [
        'client_key' => $c['client_key'], 'client_secret' => $c['client_secret'],
        'grant_type' => 'refresh_token', 'refresh_token' => $c['refresh'],
    ]);
    $json = json_decode($res, true);
    if ($status !== 200 || !is_array($json) || empty($json['access_token'])) {
        system_alert('critical', 'tiktok', 'TikTok টোকেন নবায়ন ব্যর্থ (HTTP ' . $status . '). আবার সংযুক্ত করুন।');
        return '';
    }
    tt_store_tokens($json);
    return (string) $json['access_token'];
}

function tt_disconnect(): void {
    foreach (['tiktok_access_token', 'tiktok_refresh_token', 'tiktok_expires_at', 'tiktok_refresh_expires_at'] as $k) { save_setting($k, ''); }
}

/** Saves a validated MP4/MOV/WebM upload as a draft. Returns the new ID or 0. */
function tt_draft_save(string $title, array $file): int {
    tt_tables();
    $title = trim($title);
    if (mb_strlen($title) > TT_TITLE_MAX || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) { return 0; }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > TT_MAX_BYTES) { return 0; }
    $head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 12);
    $isMp4OrMov = substr($head, 4, 4) === 'ftyp';
    $isWebm = substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
    if (!$isMp4OrMov && !$isWebm) { return 0; }
    $ext = $isWebm ? 'webm' : 'mp4';
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], tt_dir() . '/' . $name)) { return 0; }
    q_exec("INSERT INTO tiktok_posts (title, file_name, file_size, privacy, status, created_at) VALUES (?, ?, ?, 'SELF_ONLY', 'draft', ?)",
        [$title, $name, $size, date('Y-m-d H:i:s')]);
    return (int) q_val('SELECT MAX(id) FROM tiktok_posts');
}

/** Creator info decides which privacy levels this account may use. Returns the list. */
function tt_privacy_options(): array {
    $token = tt_access_token();
    if ($token === '') { return []; }
    [$status, $res] = tt_http_json('https://open.tiktokapis.com/v2/post/publish/creator_info/query/', '{}', $token);
    $json = json_decode($res, true);
    return $status === 200 && is_array($json['data']['privacy_level_options'] ?? null) ? $json['data']['privacy_level_options'] : [];
}

/** Uploads one draft to TikTok (init, one PUT, then status check). Records the result on the row. */
function tt_post(int $id): array {
    tt_tables();
    $row = q_one('SELECT * FROM tiktok_posts WHERE id = ?', [$id]);
    if (!$row || $row['status'] === 'posted') { return [false, 'এই ভিডিওটি আগেই পাঠানো হয়েছে বা পাওয়া যায়নি।']; }
    $token = tt_access_token();
    if ($token === '') { return [false, 'TikTok সংযুক্ত নেই বা টোকেন নবায়ন হয়নি।']; }
    $path = tt_dir() . '/' . basename((string) $row['file_name']);
    if (!is_file($path)) { return [false, 'ভিডিও ফাইল পাওয়া যায়নি।']; }
    $size = (int) filesize($path);

    $body = json_encode([
        'post_info' => [
            'title' => mb_substr((string) $row['title'], 0, TT_TITLE_MAX),
            'privacy_level' => (string) $row['privacy'],
            'disable_duet' => true, 'disable_stitch' => true, 'disable_comment' => false,
        ],
        'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $size, 'total_chunk_count' => 1],
    ], JSON_UNESCAPED_UNICODE);
    [$status, $res] = tt_http_json(TT_INIT_URL, $body, $token);
    $json = json_decode($res, true);
    $code = (string) ($json['error']['code'] ?? '');
    if ($status !== 200 || $code !== 'ok' || empty($json['data']['upload_url'])) {
        return tt_fail($id, 'init ' . $status . ' ' . mb_substr($code, 0, 60) . ' ' . mb_substr((string) ($json['error']['message'] ?? ''), 0, 90));
    }
    $publishId = (string) $json['data']['publish_id'];
    [$put] = tt_upload_put((string) $json['data']['upload_url'], $path, $size);
    if ($put < 200 || $put > 299) {
        return tt_fail($id, 'upload HTTP ' . $put);
    }
    q_exec("UPDATE tiktok_posts SET status = 'sent', publish_id = ?, error = NULL WHERE id = ?", [$publishId, $id]);
    $st = tt_status($publishId, $token);
    if ($st === 'PUBLISH_COMPLETE') {
        q_exec("UPDATE tiktok_posts SET status = 'posted', posted_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
        return [true, 'TikTok-এ পোস্ট হয়েছে।'];
    }
    if ($st === 'FAILED') {
        return tt_fail($id, 'TikTok প্রক্রিয়াকরণে ব্যর্থ');
    }
    return [true, 'TikTok-এ জমা হয়েছে। প্রক্রিয়াকরণ চলছে (অবস্থা: ' . ($st ?: 'অজানা') . ').'];
}

function tt_fail(int $id, string $err): array {
    $err = mb_substr($err, 0, 250);
    q_exec("UPDATE tiktok_posts SET status = 'failed', error = ? WHERE id = ?", [$err, $id]);
    system_alert('error', 'tiktok', 'TikTok post failed: ' . $err);
    return [false, 'পোস্ট করা যায়নি: ' . $err];
}

/** Single PUT with Content-Range for the whole file. Returns [status, body]. */
function tt_upload_put(string $uploadUrl, string $path, int $size): array {
    if (isset($GLOBALS['tt_http_stub']) && is_callable($GLOBALS['tt_http_stub'])) { return ($GLOBALS['tt_http_stub'])('PUT', $uploadUrl, null); }
    if (!function_exists('curl_init')) { return [0, '']; }
    $fh = fopen($path, 'rb');
    $ch = curl_init($uploadUrl);
    $ctype = str_ends_with($path, '.webm') ? 'video/webm' : 'video/mp4';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $ctype, 'Content-Length: ' . $size,
            'Content-Range: bytes 0-' . ($size - 1) . '/' . $size],
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    return [$code, $res === false ? '' : (string) $res];
}

/** Returns the publish status string, or '' when it cannot be read yet. */
function tt_status(string $publishId, string $token): string {
    for ($i = 0; $i < 3; $i++) {
        sleep(3);
        [$status, $res] = tt_http_json(TT_STATUS_URL, json_encode(['publish_id' => $publishId]), $token);
        $json = json_decode($res, true);
        $st = (string) ($json['data']['status'] ?? '');
        if ($status === 200 && $st !== '' && $st !== 'PROCESSING_UPLOAD' && $st !== 'PROCESSING_DOWNLOAD' && $st !== 'SEND_TO_USER_INBOX') {
            return $st;
        }
        if ($st === 'PUBLISH_COMPLETE' || $st === 'FAILED' || $st === 'SEND_TO_USER_INBOX') { return $st; }
    }
    return '';
}

function tt_http_json(string $url, string $body, string $token): array {
    if (isset($GLOBALS['tt_http_stub']) && is_callable($GLOBALS['tt_http_stub'])) { return ($GLOBALS['tt_http_stub'])('JSON', $url, $body); }
    if (!function_exists('curl_init')) { return [0, '']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8']]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res === false ? '' : (string) $res];
}

function tt_http_form(string $url, array $fields): array {
    if (!function_exists('curl_init')) { return [0, '']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res === false ? '' : (string) $res];
}

/** Warns before the 1-year refresh token lapses. */
function tt_expiry_check(): void {
    $c = tt_cfg();
    if ($c['refresh'] === '') { return; }
    $left = $c['refresh_expires_at'] - time();
    if ($left <= 0) {
        system_alert('critical', 'tiktok', 'TikTok সংযোগের মেয়াদ শেষ। অ্যাডমিন › TikTok থেকে আবার সংযুক্ত করুন।');
    } elseif ($left < 30 * 86400) {
        system_alert('warning', 'tiktok', 'TikTok সংযোগ ' . (int) ceil($left / 86400) . ' দিনের মধ্যে শেষ হবে। আবার সংযুক্ত করুন।');
    }
}
