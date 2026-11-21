<?php
declare(strict_types=1);

/**
 * LinkedIn posting to the owner's personal profile (self-serve "Share on LinkedIn", scope w_member_social).
 * Policy: nothing is posted automatically. A draft is created, reviewed in admin, and posted by an explicit click.
 * LinkedIn offers no DM automation for individuals, so there is no reply bot here.
 */

const LI_API_VERSION = '202609';          // LinkedIn-Version header (YYYYMM); a sunset version returns an error
const LI_SCOPES = 'openid profile w_member_social';
const LI_WARN_DAYS_BEFORE_EXPIRY = 7;

function li_tables(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS linkedin_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL DEFAULT '',
        commentary TEXT NOT NULL,
        link_url VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        linkedin_urn VARCHAR(190) NULL,
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        posted_at DATETIME NULL,
        KEY idx_status (status, created_at)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function li_cfg(): array {
    return [
        'client_id' => trim(setting('linkedin_client_id')),
        'client_secret' => trim(setting('linkedin_client_secret')),
        'token' => trim(setting('linkedin_access_token')),
        'person_urn' => trim(setting('linkedin_person_urn')),
        'expires_at' => (int) setting('linkedin_expires_at', '0'),
    ];
}

function li_connected(): bool {
    $c = li_cfg();
    return $c['token'] !== '' && $c['person_urn'] !== '' && $c['expires_at'] > time();
}

/** Redirect URI that must be registered in the LinkedIn app. */
function li_redirect_uri(): string {
    return abs_url('/jebu-cp-3hr3f/linkedin-callback.php');
}

/** Builds the consent URL. $state is stored in the admin session and checked on return. */
function li_authorize_url(string $state): string {
    $c = li_cfg();
    return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $c['client_id'],
        'redirect_uri' => li_redirect_uri(),
        'state' => $state,
        'scope' => LI_SCOPES,
    ]);
}

/** Exchanges the returned code for an access token, then reads the member ID. Returns [ok, message]. */
function li_complete_connect(string $code): array {
    $c = li_cfg();
    if ($c['client_id'] === '' || $c['client_secret'] === '') {
        return [false, 'LinkedIn Client ID বা Secret সেট করা নেই।'];
    }
    [$code1, $res] = li_http('POST', 'https://www.linkedin.com/oauth/v2/accessToken', http_build_query([
        'grant_type' => 'authorization_code', 'code' => $code,
        'redirect_uri' => li_redirect_uri(), 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
    ]), 'application/x-www-form-urlencoded', null);
    $json = json_decode($res, true);
    if ($code1 !== 200 || !is_array($json) || empty($json['access_token'])) {
        return [false, 'LinkedIn টোকেন পাওয়া যায়নি (HTTP ' . $code1 . ')।'];
    }
    $token = (string) $json['access_token'];
    [$code2, $ui] = li_http('GET', 'https://api.linkedin.com/v2/userinfo', null, null, $token);
    $info = json_decode($ui, true);
    if ($code2 !== 200 || !is_array($info) || empty($info['sub'])) {
        return [false, 'LinkedIn প্রোফাইল পড়া যায়নি (HTTP ' . $code2 . ')।'];
    }
    save_setting('linkedin_access_token', $token);
    save_setting('linkedin_person_urn', 'urn:li:person:' . (string) $info['sub']);
    save_setting('linkedin_expires_at', (string) (time() + (int) ($json['expires_in'] ?? 5184000)));
    return [true, 'LinkedIn সংযুক্ত হয়েছে।'];
}

function li_disconnect(): void {
    foreach (['linkedin_access_token', 'linkedin_person_urn', 'linkedin_expires_at'] as $k) { save_setting($k, ''); }
}

/** Creates a draft. Returns the new ID, or 0 when the text is invalid. */
function li_draft_save(string $title, string $commentary, string $link): int {
    li_tables();
    $commentary = trim($commentary);
    $link = trim($link);
    if ($commentary === '' || mb_strlen($commentary) > 2900) { return 0; }
    if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) { return 0; }
    q_exec('INSERT INTO linkedin_posts (title, commentary, link_url, status, created_at) VALUES (?, ?, ?, ?, ?)',
        [mb_substr(trim($title), 0, 200), $commentary, $link, 'draft', date('Y-m-d H:i:s')]);
    return (int) q_val('SELECT MAX(id) FROM linkedin_posts');
}

/**
 * Posts one draft through the queue (kind li_post): retry with backoff, dead-letter alert.
 * A draft that is already posted is never sent again.
 */
function li_post(int $id): array {
    li_tables();
    $row = q_one('SELECT status FROM linkedin_posts WHERE id = ?', [$id]);
    if (!$row || $row['status'] === 'posted') { return [false, 'এই পোস্টটি আগেই পাঠানো হয়েছে বা পাওয়া যায়নি।']; }
    if (!li_connected()) { return [false, 'LinkedIn সংযুক্ত নেই বা টোকেনের মেয়াদ শেষ।']; }
    return fbq_run_job(fbq_submit('li_post', $id));
}

/** Queue handler for kind li_post. Outcome: ok | transient | permanent (see fbq_classify rules). */
function fbq_do_li_post(int $id): array {
    li_tables();
    $row = q_one('SELECT status FROM linkedin_posts WHERE id = ?', [$id]);
    if (!$row) { return ['permanent', 'পোস্ট পাওয়া যায়নি।', '']; }
    if ($row['status'] === 'posted') { return ['ok', 'আগেই পাঠানো হয়েছে।', '']; }
    if (!li_connected()) { return ['permanent', 'LinkedIn সংযুক্ত নেই বা টোকেনের মেয়াদ শেষ।', '']; }
    [$ok, $msg] = li_do_post($id);
    if ($ok) { return ['ok', $msg, '']; }
    // Client errors that a retry cannot fix (bad request, expired or revoked token, forbidden) stop at once.
    $permanent = (bool) preg_match('/HTTP (400|401|403|404|422)\b/', $msg);
    return [$permanent ? 'permanent' : 'transient', $msg, ''];
}

/** One send attempt. Records the result on the row. Called only by the queue handler. */
function li_do_post(int $id): array {
    li_tables();
    $row = q_one('SELECT * FROM linkedin_posts WHERE id = ?', [$id]);
    if (!$row || $row['status'] === 'posted') { return [false, 'এই পোস্টটি আগেই পাঠানো হয়েছে বা পাওয়া যায়নি।']; }
    if (!li_connected()) { return [false, 'LinkedIn সংযুক্ত নেই বা টোকেনের মেয়াদ শেষ।']; }
    $c = li_cfg();
    $body = [
        'author' => $c['person_urn'],
        'commentary' => (string) $row['commentary'],
        'visibility' => 'PUBLIC',
        'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
        'lifecycleState' => 'PUBLISHED',
        'isReshareDisabledByAuthor' => false,
    ];
    if ((string) $row['link_url'] !== '') {
        $body['content'] = ['article' => ['source' => (string) $row['link_url'], 'title' => mb_substr((string) ($row['title'] ?: 'Read more'), 0, 200)]];
    }
    [$code, $res, $headers] = li_http('POST', 'https://api.linkedin.com/rest/posts', json_encode($body, JSON_UNESCAPED_UNICODE), 'application/json', $c['token'], true);
    if ($code === 201) {
        $urn = $headers['x-restli-id'] ?? '';
        q_exec("UPDATE linkedin_posts SET status = 'posted', linkedin_urn = ?, error = NULL, posted_at = ? WHERE id = ?", [$urn, date('Y-m-d H:i:s'), $id]);
        return [true, 'LinkedIn-এ পোস্ট হয়েছে।'];
    }
    $err = 'HTTP ' . $code . ' ' . mb_substr(strip_tags((string) $res), 0, 150);
    q_exec("UPDATE linkedin_posts SET status = 'failed', error = ? WHERE id = ?", [mb_substr($err, 0, 250), $id]);
    system_alert($code === 401 ? 'critical' : 'error', 'linkedin', 'LinkedIn post failed: ' . $err);
    return [false, 'পোস্ট করা যায়নি: ' . $err];
}

/** Warns the owner before the 60-day token runs out, so the connection never lapses silently. */
function li_expiry_check(): void {
    $c = li_cfg();
    if ($c['token'] === '') { return; }
    $left = $c['expires_at'] - time();
    if ($left <= 0) {
        system_alert('critical', 'linkedin', 'LinkedIn টোকেনের মেয়াদ শেষ। অ্যাডমিন › LinkedIn থেকে আবার সংযুক্ত করুন।');
    } elseif ($left < LI_WARN_DAYS_BEFORE_EXPIRY * 86400) {
        system_alert('warning', 'linkedin', 'LinkedIn টোকেন ' . (int) ceil($left / 86400) . ' দিনের মধ্যে শেষ হবে। আবার সংযুক্ত করুন।');
    }
}

/** Small HTTP helper. Returns [status, body] or [status, body, headers] when $withHeaders is true. */
function li_http(string $method, string $url, ?string $body, ?string $contentType, ?string $bearer, bool $withHeaders = false): array {
    // Test hook (offline suites only): replaces the network call when a suite sets it.
    if (isset($GLOBALS['li_http_stub']) && is_callable($GLOBALS['li_http_stub'])) { return ($GLOBALS['li_http_stub'])($method, $url, $body, $withHeaders); }
    if (!function_exists('curl_init')) { return $withHeaders ? [0, '', []] : [0, '']; }
    $headers = [];
    if ($contentType !== null) { $headers[] = 'Content-Type: ' . $contentType; }
    if ($bearer !== null) {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    if (str_contains($url, 'api.linkedin.com/rest/')) {
        $headers[] = 'Linkedin-Version: ' . LI_API_VERSION;
        $headers[] = 'X-Restli-Protocol-Version: 2.0.0';
    }
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $p = strpos($line, ':');
            if ($p !== false) { $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); }
            return strlen($line);
        },
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body); }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = $res === false ? '' : (string) $res;
    return $withHeaders ? [$code, $text, $respHeaders] : [$code, $text];
}
