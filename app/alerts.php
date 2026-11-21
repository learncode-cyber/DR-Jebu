<?php
declare(strict_types=1);

/* ============ System alerts, cron heartbeat and hosting checks ============
 * - system_alert(): logs a problem once per hour (dedupe), and sends error/critical ones to Telegram.
 * - cron heartbeat: app/cron/dispatch.php marks each run; the admin warns (and alerts) when it stops.
 * - system_checks(): hosting and configuration checks, used by admin > সিস্টেম মনিটর and app/cron/health.php.
 */

const ALERT_DEDUPE_MINUTES = 60;
const CRON_WARN_MINUTES = 20;      // dispatch runs every 5-10 min: no run for 20 min is a warning
const CRON_CRITICAL_MINUTES = 45;  // no run for 45 min is critical (Telegram alert)

function system_alerts_table(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS system_alerts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        level VARCHAR(10) NOT NULL,
        source VARCHAR(40) NOT NULL,
        message VARCHAR(500) NOT NULL,
        fingerprint CHAR(40) NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 1,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        telegram_at DATETIME NULL,
        KEY idx_fp (fingerprint, last_seen)
    ) DEFAULT CHARSET=utf8mb4");
}

/** Plain text to the owner's Telegram chat. Returns [ok, error]. The bot token is never shown in errors. */
function telegram_send_text(string $text): array {
    if (!telegram_enabled() || !function_exists('curl_init')) {
        return [false, 'Telegram সেট করা নেই'];
    }
    $cfg = telegram_cfg();
    $ch = curl_init('https://api.telegram.org/bot' . $cfg['token'] . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_POSTFIELDS => ['chat_id' => $cfg['chat'], 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => 'true'],
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = str_replace($cfg['token'], '***', curl_error($ch));
    curl_close($ch);
    $ok = $res !== false && $code === 200;
    return [$ok, $ok ? '' : ($err !== '' ? $err : 'Telegram HTTP ' . $code)];
}

/**
 * Records a system problem. The same problem is stored once per hour (hits counts repeats).
 * error/critical problems are also sent to Telegram. Never throws: a broken alert must not break the site.
 */
function system_alert(string $level, string $source, string $message): void {
    try {
        system_alerts_table();
        $message = mb_substr(trim($message), 0, 500);
        $fp = sha1($source . '|' . mb_substr($message, 0, 120));
        $last = q_one('SELECT id, last_seen, telegram_at FROM system_alerts WHERE fingerprint = ? ORDER BY id DESC LIMIT 1', [$fp]);
        $now = time();
        if ($last && strtotime((string) $last['last_seen']) > $now - ALERT_DEDUPE_MINUTES * 60) {
            q_exec('UPDATE system_alerts SET hits = hits + 1, last_seen = ? WHERE id = ?', [date('Y-m-d H:i:s', $now), $last['id']]);
            return;
        }
        $id = q_insert('INSERT INTO system_alerts (level, source, message, fingerprint, first_seen, last_seen) VALUES (?,?,?,?,?,?)',
            [$level, $source, $message, $fp, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now)]);
        if (in_array($level, ['error', 'critical'], true) && telegram_enabled()) {
            $icon = $level === 'critical' ? '🔴' : '🟠';
            [$ok] = telegram_send_text($icon . ' ' . ($level === 'critical' ? 'জরুরি' : 'ত্রুটি') . ' [' . $source . '] ' . $message
                . "\nসময়: " . date('d.m.Y H:i') . "\nবিস্তারিত: " . abs_url('/' . trim(ADMIN_PATH, '/') . '/system'));
            if ($ok) {
                q_exec('UPDATE system_alerts SET telegram_at = ? WHERE id = ?', [date('Y-m-d H:i:s', $now), $id]);
            }
        }
    } catch (Throwable $e) {
        error_log('[jebu] system_alert failed: ' . $e->getMessage());
    }
}

/** Called by the cron job after each run. */
function cron_heartbeat(): void {
    save_setting('sys_cron_dispatch_at', date('c'));
}

/** Last cron run: ['state' => never|ok|warn|critical, 'at' => 'Y-m-d H:i' or '', 'minutes' => int|null]. */
function cron_status(): array {
    $raw = trim(setting('sys_cron_dispatch_at'));
    if ($raw === '') {
        return ['state' => 'never', 'at' => '', 'minutes' => null];
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return ['state' => 'never', 'at' => '', 'minutes' => null];
    }
    $min = (int) floor((time() - $ts) / 60);
    $state = $min >= CRON_CRITICAL_MINUTES ? 'critical' : ($min >= CRON_WARN_MINUTES ? 'warn' : 'ok');
    return ['state' => $state, 'at' => date('d.m.Y H:i', $ts), 'minutes' => $min];
}

/** Admin dashboard calls this: a stopped cron is reported (once per hour at most). */
function cron_guard(): void {
    $c = cron_status();
    if ($c['state'] === 'critical') {
        system_alert('critical', 'cron', 'ক্রন ' . $c['minutes'] . ' মিনিট ধরে চলেনি। cPanel > Cron Jobs চেক করুন।');
    } elseif ($c['state'] === 'never') {
        system_alert('error', 'cron', 'ক্রন এখনও একবারও চালানো হয়নি। cPanel-এ Cron Job যোগ করুন।');
    }
}

/**
 * Hosting and configuration checks. Each row: ['label', 'ok' => true|false|null (null = info), 'detail'].
 * $network = true also tests outbound HTTPS to Telegram and Meta (takes a few seconds).
 */
function system_checks(bool $network = false): array {
    $rows = [];
    $add = static function (string $label, ?bool $ok, string $detail = '') use (&$rows): void {
        $rows[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    };
    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    $add('PHP ভার্সন', $phpOk, PHP_VERSION . ($phpOk ? '' : ' (৮.১ বা নতুন দরকার)'));
    foreach (['curl' => 'cURL (Telegram ও Meta-র জন্য দরকার)', 'openssl' => 'OpenSSL (HTTPS)', 'gd' => 'GD (ছবি)', 'mbstring' => 'mbstring (বাংলা টেক্সট)', 'pdo_mysql' => 'PDO MySQL (ডাটাবেস)', 'exif' => 'EXIF (ফোনের ছবি সোজা করা)'] as $ext => $label) {
        $add($label, extension_loaded($ext), extension_loaded($ext) ? 'আছে' : 'নেই');
    }
    $add('WebP সাপোর্ট (ছবি)', function_exists('imagewebp'), function_exists('imagewebp') ? 'আছে' : 'নেই, ছবি WebP-তে হবে না');
    $add('Imagick (TIFF/HEIC/AVIF, ঐচ্ছিক)', class_exists('Imagick') ? true : null, class_exists('Imagick') ? 'আছে' : 'নেই: TIFF/HEIC/AVIF ছবি নেওয়া হবে না (JPG/PNG/WEBP ঠিক চলবে)');
    $add('allow_url_fopen', (bool) ini_get('allow_url_fopen'), (bool) ini_get('allow_url_fopen') ? 'চালু' : 'বন্ধ');
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $needFns = array_intersect(['curl_init', 'curl_exec', 'proc_open'], $disabled);
    $add('প্রয়োজনীয় ফাংশন সক্রিয়', $needFns === [], $needFns === [] ? 'সব ঠিক আছে' : 'বন্ধ: ' . implode(', ', $needFns));
    $uploadBytes = ini_bytes((string) ini_get('upload_max_filesize'));
    $postBytes = ini_bytes((string) ini_get('post_max_size'));
    $needBytes = 20 * 1024 * 1024;
    $add('আপলোডের সীমা (ছবির জন্য ২০MB)', $uploadBytes >= $needBytes && $postBytes >= $needBytes + 2 * 1024 * 1024,
        'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . ($uploadBytes >= $needBytes ? '' : ' (খুব ছোট: .htaccess বা হোস্ট সাপোর্ট দিয়ে বাড়ান)'));
    $add('মেমরি লিমিট', null, (string) ini_get('memory_limit'));
    $uploadsDir = WEB_ROOT . '/uploads';
    $writable = is_dir($uploadsDir) ? is_writable($uploadsDir) : is_writable(WEB_ROOT);
    $add('uploads ফোল্ডারে লেখা যায়', $writable, $writable ? 'হ্যাঁ' : 'না, Permission 755 দিন');
    $add('টাইমজোন', date_default_timezone_get() === 'Asia/Dhaka', date_default_timezone_get());
    $isWeb = PHP_SAPI !== 'cli';
    $add('HTTPS', $isWeb ? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') : null, $isWeb ? 'সাইট HTTPS-এ চললে ঠিক' : 'কমান্ড লাইন থেকে যাচাই করা যায় না');
    $cron = cron_status();
    $cronOk = $cron['state'] === 'ok' ? true : ($cron['state'] === 'warn' ? null : false);
    $cronText = match ($cron['state']) {
        'never' => 'কখনো চলেনি',
        default => 'শেষ চলা: ' . $cron['at'] . ' (' . $cron['minutes'] . ' মিনিট আগে)',
    };
    $add('ক্রন (প্রতি ৫-১০ মিনিটে)', $cronOk, $cronText);
    $add('Telegram সেটিংস', telegram_enabled() ? true : null, telegram_enabled() ? 'Token ও Chat ID দেওয়া আছে' : 'খালি: সেটিংসে Token ও Chat ID বসান (ঐচ্ছিক, তবে সতর্কবার্তার জন্য দরকার)');
    if ($network) {
        foreach (['https://api.telegram.org' => 'Telegram API', 'https://graph.facebook.com' => 'Meta Graph API'] as $url => $label) {
            $code = 0;
            $err = '';
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
            }
            $add('বাইরে যাওয়া: ' . $label, $code > 0, $code > 0 ? 'পৌঁছানো যায় (HTTP ' . $code . ')' : 'পৌঁছানো যাচ্ছে না' . ($err !== '' ? ': ' . $err : ''));
        }
    }
    return $rows;
}

/**
 * One view of every integration's failures for the admin error center: queue jobs that failed or are retrying,
 * and the LinkedIn/TikTok token state. Read-only.
 */
function error_center_snapshot(): array {
    fbq_table();
    $jobs = [];
    $dead = 0;
    $retry = 0;
    try {
        $jobs = q_all("SELECT id, job_key, kind, state, attempts, max_attempts, last_error, updated_at FROM fb_jobs WHERE state IN ('dead','retry') ORDER BY updated_at DESC LIMIT 50");
        $dead = (int) q_val("SELECT COUNT(*) FROM fb_jobs WHERE state = 'dead'");
        $retry = (int) q_val("SELECT COUNT(*) FROM fb_jobs WHERE state = 'retry'");
    } catch (Throwable $e) {
        error_log('[jebu] error_center_snapshot: ' . $e->getMessage());
    }
    $li = li_cfg();
    $tt = tt_cfg();
    return [
        'jobs' => $jobs,
        'dead' => $dead,
        'retry' => $retry,
        'linkedin' => ['connected' => li_connected(), 'expires_at' => (int) $li['expires_at']],
        'tiktok' => ['connected' => tt_connected(), 'expires_at' => (int) $tt['expires_at']],
    ];
}

/** php.ini size value ("20M", "2G", "512K") in bytes. */
function ini_bytes(string $v): int {
    $v = trim($v);
    $n = (int) $v;
    return match (strtoupper(substr($v, -1))) { 'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n };
}

/** Recent alerts for the admin page. */
function system_alerts_recent(int $limit = 50): array {
    system_alerts_table();
    return q_all('SELECT * FROM system_alerts ORDER BY last_seen DESC, id DESC LIMIT ' . (int) $limit);
}
