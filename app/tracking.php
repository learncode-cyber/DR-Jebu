<?php
declare(strict_types=1);

/*
 * Ad tracking with any number of accounts per platform.
 *
 *   meta, Meta Pixel (browser) + Conversions API (server). ext_id = Pixel ID, secret = access token, extra = test event code
 *   ga4, Google Analytics 4 (gtag) + Measurement Protocol (server). ext_id = G-… , secret = API secret
 *   gads, Google Ads (gtag) + lead conversion label. ext_id = AW-… , extra = conversion label
 *
 * Accounts live in the tracking_accounts table and are managed from the three tracking pages in the admin.
 * Secrets are read only on the server and are never printed into a page.
 */

const TRACK_META_GRAPH_VERSION = 'v26.0';
const TRACK_PLATFORMS = ['meta', 'ga4', 'gads'];

/** Creates the table once per request and imports the single-account settings of the earlier version (once). */
function tracking_ensure_table(): void {
    static $done = false;
    if ($done || !APP_CONFIGURED) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS tracking_accounts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        platform VARCHAR(10) NOT NULL,
        label VARCHAR(120) NOT NULL DEFAULT '',
        ext_id VARCHAR(40) NOT NULL,
        secret VARCHAR(255) NOT NULL DEFAULT '',
        extra VARCHAR(120) NOT NULL DEFAULT '',
        server_on TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_platform_ext (platform, ext_id),
        KEY idx_platform_active (platform, active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (setting('tracking_migrated') === '1') {
        return;
    }
    $legacy = [
        ['meta', setting('meta_pixel_id'), setting('meta_capi_token'), setting('meta_test_event_code'), setting('meta_capi_enabled') === '1' ? 1 : 0],
        ['ga4', setting('ga_measurement_id'), setting('ga4_api_secret'), '', setting('ga4_capi_enabled') === '1' ? 1 : 0],
        ['gads', setting('google_ads_id'), '', setting('google_ads_lead_label'), 0],
    ];
    foreach ($legacy as [$platform, $ext, $secret, $extra, $server]) {
        if ($ext === '') {
            continue;
        }
        q_exec('INSERT IGNORE INTO tracking_accounts (platform, label, ext_id, secret, extra, server_on, active) VALUES (?,?,?,?,?,?,1)',
            [$platform, 'মূল অ্যাকাউন্ট', $ext, $secret, $extra, $server]);
    }
    save_setting('tracking_migrated', '1');
}

/** @return list<array<string,mixed>> */
function tracking_all(string $platform): array {
    if (!in_array($platform, TRACK_PLATFORMS, true)) {
        return [];
    }
    tracking_ensure_table();
    return q_all('SELECT * FROM tracking_accounts WHERE platform = ? ORDER BY id', [$platform]);
}

/** Active accounts for the public site. Cached per request; a database problem gives an empty list, never a broken page. */
function tracking_active(string $platform): array {
    static $cache = [];
    if (!isset($cache[$platform])) {
        try {
            if (!in_array($platform, TRACK_PLATFORMS, true) || !APP_CONFIGURED) {
                $cache[$platform] = [];
            } else {
                tracking_ensure_table();
                $cache[$platform] = q_all('SELECT * FROM tracking_accounts WHERE platform = ? AND active = 1 ORDER BY id', [$platform]);
            }
        } catch (Throwable $e) {
            error_log('[jebu] tracking_active: ' . $e->getMessage());
            $cache[$platform] = [];
        }
    }
    return $cache[$platform];
}

function tracking_find(string $platform, int $id): ?array {
    tracking_ensure_table();
    return q_one('SELECT * FROM tracking_accounts WHERE id = ? AND platform = ?', [$id, $platform]);
}

/** Server-side events need the account's secret and the server switch on. */
function tracking_server_ready(array $acc): bool {
    return (int) ($acc['server_on'] ?? 0) === 1 && (string) ($acc['secret'] ?? '') !== '';
}

/**
 * Validates one account from the admin form. $existing = ['id'=>…,'secret'=>…] for an edit, ['id'=>0] for a new one.
 * @return array{0: array<string,mixed>, 1: list<string>}
 */
function tracking_clean(string $platform, array $in, array $existing): array {
    $errs = [];
    $ext = strtoupper(trim((string) ($in['ext_id'] ?? '')));
    $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 120);
    $extra = trim((string) ($in['extra'] ?? ''));
    $typed = trim((string) ($in['secret'] ?? ''));
    $server = !empty($in['server_on']) ? 1 : 0;
    $active = !empty($in['active']) ? 1 : 0;

    $idRule = match ($platform) {
        'meta' => ['/^\d{10,20}$/', 'Pixel ID শুধু সংখ্যা (১০-২০ অঙ্ক)।'],
        'ga4' => ['/^G-[A-Z0-9]{6,20}$/', 'GA4 Measurement ID অবশ্যই G- দিয়ে শুরু হবে (যেমন G-ABC123XYZ)।'],
        'gads' => ['/^AW-\d{6,20}$/', 'Google Ads ID অবশ্যই AW- দিয়ে শুরু হবে (যেমন AW-1234567890)।'],
        default => ['/(?!)/', 'অজানা প্ল্যাটফর্ম।'],
    };
    if (preg_match($idRule[0], $ext) !== 1) {
        $errs[] = $idRule[1];
    }
    if ($platform === 'meta' && $typed !== '' && preg_match('/^[A-Za-z0-9_|.\-]{20,400}$/', $typed) !== 1) {
        $errs[] = 'Access Token সঠিক নয়। Events Manager থেকে পুরোটা কপি করুন।';
    }
    if ($platform === 'ga4' && $typed !== '' && preg_match('/^[A-Za-z0-9_-]{10,80}$/', $typed) !== 1) {
        $errs[] = 'API Secret সঠিক নয়। GA4-এর Measurement Protocol API secrets থেকে কপি করুন।';
    }
    if ($platform === 'meta' && $extra !== '' && preg_match('/^[A-Za-z0-9_-]{3,40}$/', $extra) !== 1) {
        $errs[] = 'Test event code সঠিক নয়। Events Manager থেকে কপি করুন।';
    }
    if ($platform === 'gads' && $extra !== '' && preg_match('/^[A-Za-z0-9_-]{3,60}$/', $extra) !== 1) {
        $errs[] = 'Conversion label সঠিক নয়। Google Ads থেকে হুবহু কপি করুন।';
    }
    if ($platform === 'ga4') {
        $extra = '';
    }

    // Secrets: a blank field keeps the stored value; the clear box removes it. Google Ads has no secret here.
    $secret = !empty($in['secret_clear']) ? '' : ($typed !== '' ? $typed : (string) ($existing['secret'] ?? ''));
    if ($platform === 'gads') {
        $secret = '';
        $server = 0;
    }
    if ($server === 1 && $secret === '') {
        $errs[] = 'সার্ভার-সাইড চালু করতে ' . ($platform === 'meta' ? 'Access Token' : 'API Secret') . ' দিন।';
    }

    if ($errs === []) {
        $dup = q_one('SELECT id FROM tracking_accounts WHERE platform = ? AND ext_id = ? AND id <> ?', [$platform, $ext, (int) ($existing['id'] ?? 0)]);
        if ($dup !== null) {
            $errs[] = 'এই ID আগে থেকেই যোগ করা আছে।';
        }
    }
    return [['label' => $label, 'ext_id' => $ext, 'secret' => $secret, 'extra' => $extra, 'server_on' => $server, 'active' => $active], $errs];
}

/** @return array{ok:bool, id?:int, errors?:list<string>} */
function tracking_save(string $platform, int $id, array $in): array {
    if (!in_array($platform, TRACK_PLATFORMS, true)) {
        return ['ok' => false, 'errors' => ['অজানা প্ল্যাটফর্ম।']];
    }
    $existing = $id > 0 ? tracking_find($platform, $id) : null;
    if ($id > 0 && $existing === null) {
        return ['ok' => false, 'errors' => ['অ্যাকাউন্টটি পাওয়া যায়নি।']];
    }
    [$c, $errs] = tracking_clean($platform, $in, $existing ?? ['id' => 0]);
    if ($errs !== []) {
        return ['ok' => false, 'errors' => $errs];
    }
    if ($existing !== null) {
        q_exec('UPDATE tracking_accounts SET label = ?, ext_id = ?, secret = ?, extra = ?, server_on = ?, active = ? WHERE id = ? AND platform = ?',
            [$c['label'], $c['ext_id'], $c['secret'], $c['extra'], $c['server_on'], $c['active'], $id, $platform]);
        return ['ok' => true, 'id' => $id];
    }
    $newId = q_insert('INSERT INTO tracking_accounts (platform, label, ext_id, secret, extra, server_on, active) VALUES (?,?,?,?,?,?,?)',
        [$platform, $c['label'], $c['ext_id'], $c['secret'], $c['extra'], $c['server_on'], $c['active']]);
    return ['ok' => true, 'id' => $newId];
}

function tracking_delete(string $platform, int $id): bool {
    tracking_ensure_table();
    return q_exec('DELETE FROM tracking_accounts WHERE id = ? AND platform = ?', [$id, $platform]) > 0;
}

/** Safe JavaScript string literal for use inside a <script> block. */
function tracking_js(string $v): string {
    return (string) json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
}

/** Meta and Google tags in <head>: one gtag loader for every GA4 and Google Ads account, one Pixel base for every pixel. */
function tracking_head_html(): string {
    $out = '';
    $gIds = [];
    foreach (array_merge(tracking_active('ga4'), tracking_active('gads')) as $a) {
        $gIds[] = (string) $a['ext_id'];
    }
    $gIds = array_values(array_unique($gIds));
    if ($gIds !== []) {
        $out .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . h($gIds[0]) . '"></script>' . "\n"
            . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());';
        foreach ($gIds as $id) {
            $out .= 'gtag("config",' . tracking_js($id) . ');';
        }
        $out .= '</script>' . "\n";
    }
    $pixels = tracking_active('meta');
    if ($pixels !== []) {
        $out .= '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");';
        foreach ($pixels as $a) {
            $out .= 'fbq("init",' . tracking_js((string) $a['ext_id']) . ');';
        }
        $out .= 'fbq("track","PageView");</script>' . "\n";
    }
    return $out;
}

/** Right after <body>: the Meta no-script fallback for every pixel. */
function tracking_body_html(): string {
    $out = '';
    foreach (tracking_active('meta') as $a) {
        $out .= '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id='
            . rawurlencode((string) $a['ext_id']) . '&amp;ev=PageView&amp;noscript=1"></noscript>';
    }
    return $out;
}

/** @return array{status:int,body:string,error:string} */
function tracking_http_post(string $url, string $json): array {
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'cURL চালু নেই'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

/** Meta Conversions API for one pixel. The token stays in the request URL on the server only. */
function tracking_meta_send(array $acc, array $event): array {
    $url = 'https://graph.facebook.com/' . TRACK_META_GRAPH_VERSION . '/' . rawurlencode((string) $acc['ext_id'])
        . '/events?access_token=' . rawurlencode((string) $acc['secret']);
    $payload = ['data' => [$event]];
    if ((string) ($acc['extra'] ?? '') !== '') {
        $payload['test_event_code'] = (string) $acc['extra'];
    }
    $res = tracking_http_post($url, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($res['status'] !== 200) {
        error_log('[jebu] meta capi ' . $acc['ext_id'] . ' status ' . $res['status'] . ' ' . $res['error']);
    }
    return $res;
}

/** GA4 Measurement Protocol for one property. $debug = true asks Google to validate instead of recording. */
function tracking_ga4_send(array $acc, string $clientId, array $events, bool $debug = false): array {
    $url = 'https://www.google-analytics.com/' . ($debug ? 'debug/' : '') . 'mp/collect?measurement_id='
        . rawurlencode((string) $acc['ext_id']) . '&api_secret=' . rawurlencode((string) $acc['secret']);
    $res = tracking_http_post($url, (string) json_encode(['client_id' => $clientId, 'events' => $events], JSON_UNESCAPED_UNICODE));
    if (!$debug && $res['status'] !== 204 && $res['status'] !== 200) {
        error_log('[jebu] ga4 mp ' . $acc['ext_id'] . ' status ' . $res['status'] . ' ' . $res['error']);
    }
    return $res;
}

/** Everything a server-side event needs, captured while the request is still alive. */
function tracking_lead_context(array $post, string $kind, string $eid, string $pageUrl): array {
    $name = trim((string) ($post['name'] ?? ''));
    return [
        'eid' => $eid,
        'kind' => $kind,
        'url' => $pageUrl,
        'name' => $name,
        'email' => trim((string) ($post['email'] ?? '')),
        'phone' => (string) ($post['phone'] ?? ''),
        'ip' => client_ip(),
        'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
        'fbp' => tracking_cookie('_fbp', '/^fb\.\d\.\d+\.\d+$/'),
        'fbc' => tracking_cookie('_fbc', '/^fb\.\d\.\d+\.[\w-]+$/'),
        'ga' => tracking_ga_client_id((string) ($_COOKIE['_ga'] ?? '')),
    ];
}

function tracking_cookie(string $name, string $pattern): string {
    $v = (string) ($_COOKIE[$name] ?? '');
    return preg_match($pattern, $v) === 1 ? $v : '';
}

/** GA client id from the _ga cookie (GA1.1.<random>.<timestamp> -> <random>.<timestamp>). */
function tracking_ga_client_id(string $raw = ''): string {
    if (preg_match('/^GA\d\.\d\.(\d+\.\d+)$/', $raw, $m) === 1) {
        return $m[1];
    }
    return random_int(100000000, 999999999) . '.' . time();
}

/** Meta and GA4 expect lowercase, trimmed values hashed with SHA-256 before sending. */
function tracking_hash(string $value): string {
    return hash('sha256', mb_strtolower(trim($value)));
}

/** Bangladesh numbers normalised to country-code digits (01XXXXXXXXX -> 8801XXXXXXXXX) before hashing. */
function tracking_phone_hash(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (str_starts_with($digits, '01')) {
        $digits = '880' . substr($digits, 1);
    }
    return hash('sha256', $digits);
}

/** Server-side lead events for every server-enabled Meta pixel and GA4 property. Duplicates are avoided by event_id (Meta) and by not repeating in the browser (GA4). */
function tracking_send_lead(array $c): void {
    $label = $c['kind'] === 'appointment' ? 'অ্যাপয়েন্টমেন্ট' : 'যোগাযোগ';
    $pixels = array_values(array_filter(tracking_active('meta'), 'tracking_server_ready'));
    if ($pixels !== []) {
        $user = ['client_ip_address' => $c['ip'], 'client_user_agent' => $c['ua']];
        if ($c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL)) { $user['em'] = [tracking_hash($c['email'])]; }
        if ($c['phone'] !== '') { $user['ph'] = [tracking_phone_hash($c['phone'])]; }
        $first = preg_split('/\s+/u', $c['name'])[0] ?? '';
        if ($first !== '') { $user['fn'] = [tracking_hash($first)]; }
        if ($c['fbp'] !== '') { $user['fbp'] = $c['fbp']; }
        if ($c['fbc'] !== '') { $user['fbc'] = $c['fbc']; }
        $event = [
            'event_name' => 'Lead',
            'event_time' => time(),
            'event_id' => $c['eid'],
            'action_source' => 'website',
            'event_source_url' => $c['url'],
            'user_data' => $user,
            'custom_data' => ['content_name' => $label, 'lead_type' => $c['kind']],
        ];
        foreach ($pixels as $acc) {
            tracking_meta_send($acc, $event);
        }
    }
    foreach (array_filter(tracking_active('ga4'), 'tracking_server_ready') as $acc) {
        tracking_ga4_send($acc, $c['ga'], [['name' => 'generate_lead', 'params' => [
            'lead_type' => $c['kind'], 'engagement_time_msec' => 100,
        ]]]);
    }
}

/** Runs the server-side events after the redirect has reached the visitor, so the form never waits on the network. */
function tracking_after_lead(array $c): void {
    register_shutdown_function(static function () use ($c): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            tracking_send_lead($c);
        } catch (Throwable $e) {
            error_log('[jebu] tracking: ' . $e->getMessage());
        }
    });
}

/** Thank-you page: browser events that carry the same event_id as the server events. */
function tracking_thanks_html(string $kind, string $eid): string {
    $eid = preg_match('/^[a-f0-9]{32}$/', $eid) === 1 ? $eid : '';
    $name = $kind === 'appointment' ? 'অ্যাপয়েন্টমেন্ট' : 'যোগাযোগ';
    $js = [];
    if ($eid !== '') {
        // trackSingle sends to one pixel only, so every pixel gets its own browser event next to its server event
        foreach (tracking_active('meta') as $acc) {
            $js[] = 'if(typeof fbq==="function"){fbq("trackSingle",' . tracking_js((string) $acc['ext_id']) . ',"Lead",{content_name:' . tracking_js($name) . ',lead_type:' . tracking_js($kind) . '},{eventID:' . tracking_js($eid) . '});}';
        }
    }
    foreach (tracking_active('ga4') as $acc) {
        if (tracking_server_ready($acc)) {
            continue; // the server already sends generate_lead for this property
        }
        $js[] = 'if(typeof gtag==="function"){gtag("event","generate_lead",{lead_type:' . tracking_js($kind) . ',send_to:' . tracking_js((string) $acc['ext_id']) . '});}';
    }
    foreach (tracking_active('gads') as $acc) {
        if ((string) $acc['extra'] === '') {
            continue;
        }
        $js[] = 'if(typeof gtag==="function"){gtag("event","conversion",{send_to:' . tracking_js($acc['ext_id'] . '/' . $acc['extra']) . '});}';
    }
    if ($js === []) {
        return '';
    }
    return '<script>(function(){try{' . implode('', $js) . '}catch(e){}})();</script>';
}

/** Admin "send test": one Lead event to a single pixel with its test code. */
function tracking_meta_test(array $acc): array {
    $res = tracking_meta_send($acc, [
        'event_name' => 'Lead', 'event_time' => time(), 'event_id' => 'test-' . bin2hex(random_bytes(6)),
        'action_source' => 'website', 'event_source_url' => abs_url('/'),
        'user_data' => ['client_ip_address' => client_ip(), 'client_user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'admin-test'), 0, 300)],
    ]);
    $json = json_decode($res['body'], true);
    if ($res['status'] === 200 && is_array($json)) {
        return ['ok' => true, 'msg' => 'Meta গ্রহণ করেছে। events_received: ' . (int) ($json['events_received'] ?? 0)
            . (!empty($json['fbtrace_id']) ? ' · trace: ' . $json['fbtrace_id'] : '')];
    }
    $err = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
    if ($res['status'] === 0) {
        return ['ok' => false, 'msg' => 'Meta-র সার্ভারে পৌঁছানো যায়নি। সার্ভারের ইন্টারনেট ও cURL অনুমতি দেখুন।'];
    }
    return ['ok' => false, 'msg' => 'Meta ইভেন্ট নেয়নি (HTTP ' . $res['status'] . ')' . ($err !== '' ? ': ' . $err : '') . '। Pixel ID ও Token মিলিয়ে দেখুন।'];
}

/** Admin "send test": Google's debug endpoint validates the payload without recording it. */
function tracking_ga4_test(array $acc): array {
    $res = tracking_ga4_send($acc, '123456789.' . time(), [['name' => 'generate_lead', 'params' => ['lead_type' => 'test', 'engagement_time_msec' => 100]]], true);
    $json = json_decode($res['body'], true);
    $messages = is_array($json) ? (array) ($json['validationMessages'] ?? []) : [];
    if ($messages !== []) {
        $first = is_array($messages[0]) ? (string) ($messages[0]['description'] ?? '') : '';
        return ['ok' => false, 'msg' => 'GA4 ত্রুটি: ' . $first];
    }
    if ($res['status'] === 200) {
        return ['ok' => true, 'msg' => 'GA4 payload ঠিক আছে (Google কোনো ত্রুটি দেয়নি)।'];
    }
    if ($res['status'] === 0) {
        return ['ok' => false, 'msg' => 'Google Analytics-এর সার্ভারে পৌঁছানো যায়নি। সার্ভারের ইন্টারনেট ও cURL অনুমতি দেখুন।'];
    }
    return ['ok' => false, 'msg' => 'GA4 পৌঁছায়নি (HTTP ' . $res['status'] . ')। Measurement ID ও API Secret মিলিয়ে দেখুন।'];
}
