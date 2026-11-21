<?php
declare(strict_types=1);

/**
 * Gemini provider: credentials grouped by Google Cloud project, model router, quota ledger,
 * error classification, privacy guard, database job queue and cron worker.
 *
 * Rules built in (from the owner's brief and Google's documentation):
 * - Rate limits are per PROJECT, not per API key. Keys of one project share one quota ledger.
 * - Daily (RPD) quotas reset at midnight Pacific time; the reset is calculated in code, shown in Asia/Dhaka.
 * - Never switch to another project to get around a quota. One active project is used at a time.
 * - Paid billing is refused unless the owner switched it on (gemini_paid_allowed = 1) AND marked the key 'paid'.
 * - Medical tasks never fall back to a lite/fast model and never bypass the privacy guard.
 * - Every timestamp in the gem_* tables is UTC.
 */

const GEM_BASE = 'https://generativelanguage.googleapis.com/v1beta';
const GEM_PT = 'America/Los_Angeles';
const GEM_MEDICAL_TASKS = ['medical_summary', 'medical_claim_check', 'blog_draft', 'quality_review'];
const GEM_JOB_OPEN = ['pending', 'retry_scheduled', 'waiting_quota', 'processing'];
const GEM_JOB_STATUSES = ['pending', 'processing', 'completed', 'failed', 'retry_scheduled', 'waiting_quota', 'cancelled', 'manual_required'];

function gem_now(): string { return gmdate('Y-m-d H:i:s'); }
function gem_plus(int $seconds): string { return gmdate('Y-m-d H:i:s', time() + $seconds); }

/** Next midnight in Pacific time (Google's daily quota reset), as a UTC timestamp. */
function gem_next_pt_midnight(): string {
    $d = (new DateTimeImmutable('tomorrow', new DateTimeZone(GEM_PT)))->setTime(0, 0);
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function gem_pt_day(): string {
    return (new DateTimeImmutable('now', new DateTimeZone(GEM_PT)))->format('Y-m-d');
}

/** UTC timestamp shown as Asia/Dhaka local time for the admin. */
function gem_show_dhaka(?string $utc): string {
    if ($utc === null || $utc === '') {
        return '—';
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d M Y, h:i A');
}

function gem_tables(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS gem_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(80) NOT NULL,
        api_key VARCHAR(255) NOT NULL,
        project_id VARCHAR(40) NOT NULL,
        environment VARCHAR(12) NOT NULL DEFAULT 'production',
        billing VARCHAR(8) NOT NULL DEFAULT 'free',
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        verify_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
        last_ok_at DATETIME NULL,
        last_error VARCHAR(255) NOT NULL DEFAULT '',
        verified_at DATETIME NULL,
        rotated_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY gem_cred_project (project_id)
    ) DEFAULT CHARSET=utf8mb4");
    q_exec("CREATE TABLE IF NOT EXISTS gem_models (
        project_id VARCHAR(40) NOT NULL,
        model_id VARCHAR(80) NOT NULL,
        generates TINYINT NOT NULL DEFAULT 0,
        available TINYINT NOT NULL DEFAULT 1,
        seen_at DATETIME NULL,
        unavailable_reason VARCHAR(160) NOT NULL DEFAULT '',
        PRIMARY KEY (project_id, model_id)
    ) DEFAULT CHARSET=utf8mb4");
    q_exec("CREATE TABLE IF NOT EXISTS gem_usage (
        project_id VARCHAR(40) NOT NULL,
        model_id VARCHAR(80) NOT NULL,
        day_pt DATE NOT NULL,
        requests INT NOT NULL DEFAULT 0,
        ok_count INT NOT NULL DEFAULT 0,
        fail_count INT NOT NULL DEFAULT 0,
        rl_minute INT NOT NULL DEFAULT 0,
        rl_daily INT NOT NULL DEFAULT 0,
        tokens_in BIGINT NOT NULL DEFAULT 0,
        tokens_out BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (project_id, model_id, day_pt)
    ) DEFAULT CHARSET=utf8mb4");
    q_exec("CREATE TABLE IF NOT EXISTS gem_quota_block (
        project_id VARCHAR(40) NOT NULL,
        model_id VARCHAR(80) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        blocked_until DATETIME NOT NULL,
        reason VARCHAR(160) NOT NULL DEFAULT '',
        PRIMARY KEY (project_id, model_id, kind)
    ) DEFAULT CHARSET=utf8mb4");
    q_exec("CREATE TABLE IF NOT EXISTS gem_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        level VARCHAR(8) NOT NULL,
        event VARCHAR(24) NOT NULL,
        project_id VARCHAR(40) NOT NULL DEFAULT '',
        model_id VARCHAR(80) NOT NULL DEFAULT '',
        http_code INT NOT NULL DEFAULT 0,
        message VARCHAR(255) NOT NULL DEFAULT '',
        KEY gem_log_at (at),
        KEY gem_log_event (event, project_id, at)
    ) DEFAULT CHARSET=utf8mb4");
    q_exec("CREATE TABLE IF NOT EXISTS gem_jobs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        job_key CHAR(64) NOT NULL,
        task VARCHAR(40) NOT NULL,
        input_json MEDIUMTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        project_id VARCHAR(40) NOT NULL DEFAULT '',
        model_id VARCHAR(80) NOT NULL DEFAULT '',
        attempts INT NOT NULL DEFAULT 0,
        max_attempts INT NOT NULL DEFAULT 6,
        next_run_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        locked_at DATETIME NULL,
        lock_token VARCHAR(32) NULL,
        last_error VARCHAR(255) NOT NULL DEFAULT '',
        result_text MEDIUMTEXT NULL,
        UNIQUE KEY gem_jobs_key (job_key),
        KEY gem_jobs_due (status, next_run_at)
    ) DEFAULT CHARSET=utf8mb4");
    gem_migrate_keys();
}

/** One-time: widen the column for ciphertext and encrypt every stored Gemini key that is still plain text. */
function gem_migrate_keys(): void {
    if (setting('sec_gem_keys_v1') === '1') {
        return;
    }
    try {
        q_exec('ALTER TABLE gem_credentials MODIFY api_key VARCHAR(255) NOT NULL');
        foreach (q_all('SELECT id, api_key FROM gem_credentials') as $r) {
            $v = (string) $r['api_key'];
            if ($v !== '' && !sec_is_enc($v)) {
                q_exec('UPDATE gem_credentials SET api_key = ? WHERE id = ?', [sec_encrypt($v), (int) $r['id']]);
            }
        }
        save_setting('sec_gem_keys_v1', '1');
    } catch (Throwable $e) {
        error_log('[jebu] gem_migrate_keys: ' . $e->getMessage());
    }
}

/** The plain key for an HTTP call. Never log or display it. */
function gem_plain_key(array $row): string {
    return sec_decrypt((string) ($row['api_key'] ?? ''));
}

/* ---------------------------------------------------------------- settings & routes */

function gem_default_routes(): array {
    $fast = ['gemini-2.5-flash-lite', 'gemini-2.5-flash'];
    $quality = ['gemini-2.5-flash', 'gemini-2.5-pro'];
    return [
        'comment_classify' => ['tier' => 'fast', 'models' => $fast],
        'short_reply' => ['tier' => 'fast', 'models' => $fast],
        'keyword_group' => ['tier' => 'fast', 'models' => $fast],
        'seo_meta' => ['tier' => 'fast', 'models' => $fast],
        'blog_outline' => ['tier' => 'fast', 'models' => $fast],
        'medical_summary' => ['tier' => 'quality', 'models' => $quality],
        'blog_draft' => ['tier' => 'quality', 'models' => $quality],
        'medical_claim_check' => ['tier' => 'quality', 'models' => $quality],
        'quality_review' => ['tier' => 'quality', 'models' => $quality],
    ];
}

/**
 * Task → ordered model list. Lives only in code (gem_default_routes); changing a model means a code update.
 * Medical tasks can never use a lite or nano model, checked here on every read.
 */
function gem_routes(): array {
    $routes = gem_default_routes();
    foreach ($routes as $task => $r) {
        if (in_array($task, GEM_MEDICAL_TASKS, true)) {
            $routes[$task]['models'] = array_values(array_filter($r['models'], static fn($m) => preg_match('/lite|nano/i', $m) !== 1));
        }
    }
    return $routes;
}

function gem_paid_allowed(): bool { return setting('gemini_paid_allowed') === '1'; }

/** The one project used now. Quota is never moved to another project to get around a limit. */
function gem_active_project(): string {
    $p = trim(setting('gemini_active_project'));
    if ($p !== '') {
        return $p;
    }
    gem_tables();
    $row = q_one("SELECT project_id FROM gem_credentials WHERE status = 'active' AND billing = 'free' ORDER BY id LIMIT 1");
    return (string) ($row['project_id'] ?? '');
}

/* ---------------------------------------------------------------- HTTP (test stub hook) */

/**
 * Single HTTP entry point. Returns [code, lowercase headers, body, transport error].
 * The API key is sent only in the x-goog-api-key header and is never written to logs.
 * Tests replace it with $GLOBALS['gem_http_stub'].
 */
function gem_http(string $method, string $url, ?array $body, string $key): array {
    if (isset($GLOBALS['gem_http_stub']) && is_callable($GLOBALS['gem_http_stub'])) {
        return ($GLOBALS['gem_http_stub'])($method, $url, $body, $key);
    }
    if (!function_exists('curl_init')) {
        return [0, [], '', 'curl is not available'];
    }
    $headers = [];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            $p = strpos($line, ':');
            if ($p !== false) {
                $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string) curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return [0, $headers, '', $err !== '' ? $err : 'network error'];
    }
    return [$code, $headers, (string) $res, ''];
}

/** Google's error message only (never echoes request data). */
function gem_err_message(string $body): string {
    $j = json_decode($body, true);
    $m = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
    return mb_substr(preg_replace('/\s+/', ' ', $m) ?? '', 0, 200);
}

/* ---------------------------------------------------------------- classification */

/**
 * Turns one HTTP result into an action. Kinds:
 * ok | rate_minute | rate_daily | rate_unknown | auth | model_unavailable | bad_request | server | unavailable | network
 */
function gem_classify(int $code, array $headers, string $body, string $transportErr = ''): array {
    $msg = gem_err_message($body);
    $raw = strtolower($body);
    if ($code === 0) {
        return ['kind' => 'network', 'message' => $transportErr !== '' ? $transportErr : 'network error', 'retry_after' => null];
    }
    if ($code === 200) {
        return ['kind' => 'ok', 'message' => '', 'retry_after' => null];
    }
    if ($code === 429) {
        $retry = isset($headers['retry-after']) && ctype_digit((string) $headers['retry-after']) ? (int) $headers['retry-after'] : null;
        // RPD exhaustion carries "PerDay" in the quota id; RPM/TPM carry "PerMinute".
        if (preg_match('/perday|per day|requestsperday|daily/i', $raw . ' ' . $msg) === 1) {
            return ['kind' => 'rate_daily', 'message' => $msg, 'retry_after' => null];
        }
        if (preg_match('/perminute|per minute|input_token_count|tokens per minute|requests per minute/i', $raw . ' ' . $msg) === 1) {
            return ['kind' => 'rate_minute', 'message' => $msg, 'retry_after' => $retry];
        }
        return ['kind' => 'rate_unknown', 'message' => $msg, 'retry_after' => $retry];
    }
    if ($code === 401 || $code === 403) {
        $kind = str_contains($raw, 'api key not valid') || str_contains($raw, 'api_key_invalid') ? 'invalid' : 'restricted';
        return ['kind' => 'auth', 'auth_kind' => $kind, 'message' => $msg, 'retry_after' => null];
    }
    if ($code === 400 && (str_contains($raw, 'api key not valid') || str_contains($raw, 'api_key_invalid'))) {
        return ['kind' => 'auth', 'auth_kind' => 'invalid', 'message' => $msg, 'retry_after' => null];
    }
    if (($code === 404 || $code === 400) && preg_match('/models?\/|not found|not supported|unsupported|does not support|is not found/i', $msg) === 1 && stripos($raw, 'model') !== false) {
        return ['kind' => 'model_unavailable', 'message' => $msg, 'retry_after' => null];
    }
    if ($code === 400 || $code === 413) {
        return ['kind' => 'bad_request', 'message' => $msg, 'retry_after' => null];
    }
    if ($code === 503) {
        return ['kind' => 'unavailable', 'message' => $msg, 'retry_after' => null];
    }
    return ['kind' => 'server', 'message' => $msg !== '' ? $msg : 'HTTP ' . $code, 'retry_after' => null];
}

/** Exponential backoff with jitter, honouring Retry-After when given. Capped at 30 minutes. */
function gem_backoff(int $attempt, ?int $retryAfter = null): int {
    if ($retryAfter !== null && $retryAfter > 0) {
        return min(1800, $retryAfter) + random_int(0, 5);
    }
    $base = min(1800, 30 * (2 ** max(0, $attempt - 1)));
    return $base + random_int(0, (int) max(1, $base / 4));
}

/* ---------------------------------------------------------------- privacy guard */

/**
 * Blocks text that looks like patient or personal data before it can reach any Gemini model.
 * Heuristic and conservative: a block is reported with its reason, and the job is refused.
 * Returns [ok, reason].
 */
function gem_guard(string $text): array {
    $rules = [
        'ফোন নম্বর' => '/(?:\+?88)?\s*0?1[3-9]\d{2}[\s\-]?\d{6}\b/u',
        'আন্তর্জাতিক ফোন নম্বর' => '/\+\d[\d\s\-]{8,}\d/u',
        'ইমেইল' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
        'পরিচয়পত্র নম্বর' => '/(NID|এনআইডি|জাতীয় পরিচয়পত্র|পাসপোর্ট|passport)\D{0,20}\d{5,}/iu',
        'রোগীর পরিচয়' => '/(রোগীর নাম|রোগীর নম্বর|রোগী নম্বর|patient\s*(name|id|no\.?)|\bMRN\b|date of birth|জন্ম তারিখ)\s*[:：]?/iu',
        'প্রেসক্রিপশন' => '/(প্রেসক্রিপশন|prescription)\s*(নং|নম্বর|no\.?|#|[:：])\s*\S+/iu',
        'অ্যাপয়েন্টমেন্ট' => '/(অ্যাপয়েন্টমেন্ট|appointment)\s*(আইডি|নম্বর|id|no\.?|#|[:：])\s*\S+/iu',
        'ব্যক্তিগত নাম' => '/(রোগীর নাম|নাম|name)\s*[:：]\s*[A-Za-z\x{0980}-\x{09FF}]{2,}\s+[A-Za-z\x{0980}-\x{09FF}]{2,}/u',
    ];
    foreach ($rules as $label => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return [false, $label . ' ধরা পড়েছে; এই তথ্য Gemini-তে পাঠানো যাবে না'];
        }
    }
    return [true, ''];
}

/* ---------------------------------------------------------------- credentials */

function gem_mask(string $key): string {
    $k = trim($key);
    $n = strlen($k);
    return $n <= 8 ? str_repeat('•', $n) : substr($k, 0, 4) . str_repeat('•', 8) . substr($k, -4);
}

/** Adds a credential. Returns [ok, message]. The key is stored but is never shown again in full. */
function gem_cred_add(string $label, string $key, string $project, string $env, string $billing): array {
    gem_tables();
    $key = trim($key);
    $project = strtolower(trim($project));
    $label = trim($label);
    if (preg_match('/^AIza[0-9A-Za-z_\-]{30,}$/', $key) !== 1) {
        return [false, 'API key দেখতে সঠিক নয় (AIza দিয়ে শুরু হয়)।'];
    }
    if (preg_match('/^[a-z][a-z0-9\-]{4,28}[a-z0-9]$/', $project) !== 1) {
        return [false, 'Google Cloud Project ID সঠিক নয়।'];
    }
    if (!in_array($env, ['development', 'staging', 'production'], true)) {
        return [false, 'Environment ভুল।'];
    }
    if (!in_array($billing, ['free', 'paid'], true)) {
        return [false, 'Billing ভুল।'];
    }
    if ($label === '') {
        $label = 'Key ' . gem_mask($key);
    }
    q_insert('INSERT INTO gem_credentials (label, api_key, project_id, environment, billing, status, verify_status, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [mb_substr($label, 0, 80), sec_encrypt($key), $project, $env, $billing, 'active', 'unknown', gem_now()]);
    gem_log('info', 'cred_add', $project, '', 0, 'credential added: ' . gem_mask($key));
    return [true, 'Credential যুক্ত হয়েছে। এখন "Verify" চাপুন।'];
}

function gem_cred_list(): array {
    gem_tables();
    $rows = q_all('SELECT id, label, api_key, project_id, environment, billing, status, verify_status, last_ok_at, last_error, verified_at, rotated_at, created_at FROM gem_credentials ORDER BY project_id, id');
    foreach ($rows as &$r) {
        $r['masked'] = gem_mask(gem_plain_key($r));
        unset($r['api_key']);
    }
    return $rows;
}

function gem_cred_set_status(int $id, string $status): void {
    gem_tables();
    if (in_array($status, ['active', 'disabled'], true)) {
        q_exec('UPDATE gem_credentials SET status = ? WHERE id = ?', [$status, $id]);
    }
}

function gem_cred_delete(int $id): void {
    gem_tables();
    q_exec('DELETE FROM gem_credentials WHERE id = ?', [$id]);
}

/** Replaces a key (rotation). The old key is removed from the database row. */
function gem_cred_rotate(int $id, string $newKey): array {
    gem_tables();
    $newKey = trim($newKey);
    if (preg_match('/^AIza[0-9A-Za-z_\-]{30,}$/', $newKey) !== 1) {
        return [false, 'নতুন API key সঠিক নয়।'];
    }
    q_exec("UPDATE gem_credentials SET api_key = ?, verify_status = 'unknown', rotated_at = ?, last_error = '' WHERE id = ?", [sec_encrypt($newKey), gem_now(), $id]);
    return [true, 'Key বদলানো হয়েছে। এখন Verify করুন।'];
}

function gem_cred_row(int $id): ?array {
    gem_tables();
    return q_one('SELECT * FROM gem_credentials WHERE id = ?', [$id]);
}

/**
 * Lists models from models.list (official discovery) and stores them for the credential's project.
 * Returns [ok, message, code].
 */
function gem_discover(array $cred): array {
    $project = (string) $cred['project_id'];
    $found = [];
    $pageToken = '';
    for ($i = 0; $i < 5; $i++) {
        $url = GEM_BASE . '/models?pageSize=100' . ($pageToken !== '' ? '&pageToken=' . rawurlencode($pageToken) : '');
        [$code, , $body, $err] = gem_http('GET', $url, null, gem_plain_key($cred));
        if ($code !== 200) {
            $msg = gem_err_message($body);
            return [false, $msg !== '' ? $msg : ($err !== '' ? $err : 'HTTP ' . $code), $code];
        }
        $j = json_decode($body, true);
        foreach ((array) ($j['models'] ?? []) as $m) {
            $found[] = $m;
        }
        $pageToken = (string) ($j['nextPageToken'] ?? '');
        if ($pageToken === '') {
            break;
        }
    }
    $now = gem_now();
    foreach ($found as $m) {
        $id = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
        if ($id === '') {
            continue;
        }
        $generates = in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true) ? 1 : 0;
        q_exec('INSERT INTO gem_models (project_id, model_id, generates, available, seen_at, unavailable_reason) VALUES (?,?,?,1,?,\'\')
                ON DUPLICATE KEY UPDATE generates = VALUES(generates), available = 1, seen_at = VALUES(seen_at), unavailable_reason = \'\'',
            [$project, mb_substr($id, 0, 80), $generates, $now]);
    }
    return [true, count($found) . 'টি মডেল পাওয়া গেছে।', 200];
}

/** Verifies one credential with a real models.list call (run only when the owner clicks Verify). */
function gem_cred_verify(int $id): array {
    $cred = gem_cred_row($id);
    if (!$cred) {
        return [false, 'Credential পাওয়া যায়নি।'];
    }
    [$ok, $msg, $code] = gem_discover($cred);
    $now = gem_now();
    if ($ok) {
        q_exec("UPDATE gem_credentials SET verify_status = 'ok', last_ok_at = ?, verified_at = ?, last_error = '' WHERE id = ?", [$now, $now, $id]);
        gem_log('info', 'verify_ok', (string) $cred['project_id'], '', 200, $msg);
        return [true, 'যাচাই সফল: ' . $msg];
    }
    $status = $code === 400 || $code === 401 ? 'invalid' : ($code === 403 ? 'restricted' : 'unknown');
    q_exec('UPDATE gem_credentials SET verify_status = ?, verified_at = ?, last_error = ? WHERE id = ?', [$status, $now, mb_substr($msg, 0, 255), $id]);
    gem_log('error', 'verify_fail', (string) $cred['project_id'], '', $code, $msg);
    if ($status !== 'unknown') {
        system_alert('error', 'gemini', 'Gemini credential "' . $cred['label'] . '" যাচাই ব্যর্থ (' . $status . '): ' . $msg);
    }
    return [false, 'যাচাই ব্যর্থ (' . $status . '): ' . $msg];
}

/** A usable credential of one project. Paid keys only when the owner switched paid use on. */
function gem_pick_cred(string $project, array $exclude = []): ?array {
    gem_tables();
    $billingSql = gem_paid_allowed() ? "billing IN ('free','paid')" : "billing = 'free'";
    $rows = q_all("SELECT * FROM gem_credentials WHERE project_id = ? AND status = 'active' AND verify_status IN ('ok','unknown') AND $billingSql ORDER BY last_ok_at DESC, id", [$project]);
    foreach ($rows as $r) {
        if (!in_array((int) $r['id'], $exclude, true)) {
            return $r;
        }
    }
    return null;
}

/* ---------------------------------------------------------------- quota ledger */

function gem_block(string $project, string $model, string $kind, string $until, string $reason): void {
    q_exec('INSERT INTO gem_quota_block (project_id, model_id, kind, blocked_until, reason) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until), reason = VALUES(reason)',
        [$project, $model, $kind, $until, mb_substr($reason, 0, 160)]);
}

/** The latest active block (UTC) for a project+model, or null when free to call. */
function gem_block_until(string $project, string $model): ?string {
    $row = q_one('SELECT MAX(blocked_until) AS u FROM gem_quota_block WHERE project_id = ? AND model_id = ? AND blocked_until > ?', [$project, $model, gem_now()]);
    return $row['u'] ?? null;
}

function gem_usage_add(string $project, string $model, bool $ok, int $tokIn, int $tokOut, string $rl): void {
    q_exec('INSERT INTO gem_usage (project_id, model_id, day_pt, requests, ok_count, fail_count, rl_minute, rl_daily, tokens_in, tokens_out)
            VALUES (?,?,?,1,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE requests = requests + 1, ok_count = ok_count + VALUES(ok_count), fail_count = fail_count + VALUES(fail_count),
            rl_minute = rl_minute + VALUES(rl_minute), rl_daily = rl_daily + VALUES(rl_daily),
            tokens_in = tokens_in + VALUES(tokens_in), tokens_out = tokens_out + VALUES(tokens_out)',
        [$project, $model, gem_pt_day(), $ok ? 1 : 0, $ok ? 0 : 1, $rl === 'minute' ? 1 : 0, $rl === 'daily' ? 1 : 0, $tokIn, $tokOut]);
}

/** Requests sent to a project today (Pacific day), all models together. */
function gem_requests_today(string $project): int {
    return (int) q_val('SELECT COALESCE(SUM(requests),0) FROM gem_usage WHERE project_id = ? AND day_pt = ?', [$project, gem_pt_day()]);
}

function gem_requests_last_minute(string $project): int {
    return (int) q_val("SELECT COUNT(*) FROM gem_log WHERE event = 'call' AND project_id = ? AND at >= ?", [$project, gem_plus(-60)]);
}

function gem_log(string $level, string $event, string $project, string $model, int $code, string $message): void {
    gem_tables();
    q_exec('INSERT INTO gem_log (at, level, event, project_id, model_id, http_code, message) VALUES (?,?,?,?,?,?,?)',
        [gem_now(), $level, $event, $project, $model, $code, mb_substr($message, 0, 255)]);
}

/* ---------------------------------------------------------------- model router */

/** Known models of a project (empty when discovery has not run yet). */
function gem_known_models(string $project): array {
    $rows = q_all('SELECT model_id, generates, available FROM gem_models WHERE project_id = ?', [$project]);
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['model_id']] = ['generates' => (int) $r['generates'] === 1, 'available' => (int) $r['available'] === 1];
    }
    return $out;
}

/**
 * First approved model for the task that is discovered, available and not quota-blocked.
 * Medical tasks never fall back to a lite/nano model: when nothing else is free, the job waits.
 */
function gem_pick_model(string $task, string $project, array $exclude = []): ?string {
    $routes = gem_routes();
    if (!isset($routes[$task])) {
        return null;
    }
    $known = gem_known_models($project);
    $medical = in_array($task, GEM_MEDICAL_TASKS, true);
    foreach ($routes[$task]['models'] as $m) {
        if (in_array($m, $exclude, true)) {
            continue;
        }
        if ($medical && preg_match('/lite|nano/i', $m) === 1) {
            continue;
        }
        if ($known !== [] && (!isset($known[$m]) || !$known[$m]['generates'] || !$known[$m]['available'])) {
            continue;
        }
        if (gem_block_until($project, $m) !== null) {
            continue;
        }
        return $m;
    }
    return null;
}

/* ---------------------------------------------------------------- job queue */

/**
 * Queues one Gemini task. Duplicate work (same task, prompt and reference) returns the existing job.
 * Returns [ok, jobId, message].
 */
function gem_submit(string $task, string $prompt, string $system = '', int $maxOut = 400, string $ref = ''): array {
    gem_tables();
    if (!array_key_exists($task, gem_routes())) {
        return [false, 0, 'অজানা কাজ: ' . $task];
    }
    $prompt = trim($prompt);
    if ($prompt === '' || mb_strlen($prompt) > 20000) {
        return [false, 0, 'প্রম্পট খালি, অথবা অনেক বড়।'];
    }
    $guard = gem_guard($system . "\n" . $prompt . "\n" . $ref);
    if (!$guard[0]) {
        gem_log('warn', 'privacy_block', '', '', 0, $guard[1] . ' (task ' . $task . ')');
        return [false, 0, $guard[1]];
    }
    $key = hash('sha256', $task . "\x1f" . $prompt . "\x1f" . $system . "\x1f" . $ref);
    $dup = q_one('SELECT id, status FROM gem_jobs WHERE job_key = ?', [$key]);
    if ($dup) {
        $st = (string) $dup['status'];
        if (in_array($st, ['failed', 'manual_required', 'cancelled'], true)) {
            return [false, (int) $dup['id'], 'এই কাজটি আগে ব্যর্থ/বাতিল হয়েছিল। "Retry" দিন।'];
        }
        return [true, (int) $dup['id'], 'একই কাজ আগে থেকেই আছে (ডুপ্লিকেট যোগ হয়নি)।'];
    }
    $input = json_encode(['system' => $system, 'prompt' => $prompt, 'max_output_tokens' => max(64, min(2048, $maxOut))], JSON_UNESCAPED_UNICODE);
    $now = gem_now();
    $id = q_insert('INSERT INTO gem_jobs (job_key, task, input_json, status, attempts, max_attempts, next_run_at, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [$key, $task, $input, 'pending', 0, max(1, min(20, (int) setting('gemini_max_attempts', '6'))), $now, $now]);
    return [true, $id, 'কাজ কিউতে যোগ হয়েছে।'];
}

function gem_job(int $id): ?array {
    gem_tables();
    return q_one('SELECT * FROM gem_jobs WHERE id = ?', [$id]);
}

/** Admin "Retry": resets attempts and makes the job due now. */
function gem_job_retry(int $id): array {
    $j = gem_job($id);
    if (!$j) {
        return [false, 'কাজ পাওয়া যায়নি।'];
    }
    if (!in_array($j['status'], ['failed', 'manual_required', 'cancelled', 'waiting_quota', 'retry_scheduled'], true)) {
        return [false, 'এই অবস্থায় Retry দেওয়া যাবে না।'];
    }
    q_exec("UPDATE gem_jobs SET status = 'pending', attempts = 0, next_run_at = ?, last_error = '', finished_at = NULL WHERE id = ?", [gem_now(), $id]);
    gem_log('info', 'job_retry', (string) $j['project_id'], '', 0, 'admin retry job ' . $id);
    return [true, 'কাজটি আবার কিউতে দেওয়া হয়েছে।'];
}

function gem_job_cancel(int $id): array {
    $j = gem_job($id);
    if (!$j) {
        return [false, 'কাজ পাওয়া যায়নি।'];
    }
    if (!in_array($j['status'], ['pending', 'retry_scheduled', 'waiting_quota', 'manual_required'], true)) {
        return [false, 'চলমান বা শেষ হওয়া কাজ বাতিল করা যাবে না।'];
    }
    q_exec("UPDATE gem_jobs SET status = 'cancelled', finished_at = ?, lock_token = NULL WHERE id = ?", [gem_now(), $id]);
    return [true, 'কাজ বাতিল হয়েছে।'];
}

/** Atomic claim: only one worker can own a job (status change + random token, then verified). */
function gem_claim(int $id): ?array {
    $tok = bin2hex(random_bytes(12));
    $now = gem_now();
    q_exec("UPDATE gem_jobs SET status = 'processing', lock_token = ?, locked_at = ?, started_at = COALESCE(started_at, ?)
            WHERE id = ? AND status IN ('pending','retry_scheduled','waiting_quota') AND next_run_at <= ?",
        [$tok, $now, $now, $id, $now]);
    $row = gem_job($id);
    return ($row && (string) $row['lock_token'] === $tok) ? $row : null;
}

function gem_job_set(int $id, string $status, string $msg, bool $terminal): void {
    q_exec('UPDATE gem_jobs SET status = ?, last_error = ?, lock_token = NULL, locked_at = NULL, finished_at = ? WHERE id = ?',
        [$status, mb_substr($msg, 0, 255), $terminal ? gem_now() : null, $id]);
}

/** Waits until a time. $refund gives back the attempt counted before the call (quota waits are not failures). */
function gem_job_wait(int $id, string $until, string $status, string $msg, bool $refund): void {
    q_exec('UPDATE gem_jobs SET status = ?, last_error = ?, next_run_at = ?, lock_token = NULL, locked_at = NULL,
            attempts = GREATEST(0, attempts - ?) WHERE id = ?',
        [$status, mb_substr($msg, 0, 255), $until, $refund ? 1 : 0, $id]);
}

/** Jobs stuck in 'processing' after a worker stopped are put back in the queue. */
function gem_recover_stale(): int {
    return q_exec("UPDATE gem_jobs SET status = 'retry_scheduled', lock_token = NULL, locked_at = NULL, next_run_at = ?,
                   last_error = 'worker interrupted; job recovered' WHERE status = 'processing' AND locked_at < ?",
        [gem_now(), gem_plus(-600)]);
}

/** Runs one claimed job to a terminal or waiting state. Never calls the API for a privacy-blocked text. */
function gem_process(array $job): string {
    $id = (int) $job['id'];
    $task = (string) $job['task'];
    $in = json_decode((string) $job['input_json'], true);
    $in = is_array($in) ? $in : [];
    $project = (string) $job['project_id'] !== '' ? (string) $job['project_id'] : gem_active_project();
    if ($project === '') {
        gem_job_set($id, 'manual_required', 'কোনো সক্রিয় Free-tier প্রজেক্ট কনফিগার করা নেই।', false);
        return 'manual_required';
    }
    $guard = gem_guard(($in['system'] ?? '') . "\n" . ($in['prompt'] ?? ''));
    if (!$guard[0]) {
        gem_job_set($id, 'failed', 'Privacy block: ' . $guard[1], true);
        gem_log('warn', 'privacy_block', $project, '', 0, $guard[1] . ' (job ' . $id . ')');
        return 'failed';
    }
    if (gem_requests_today($project) >= (int) setting('gemini_daily_cap', '200')) {
        gem_job_wait($id, gem_next_pt_midnight(), 'waiting_quota', 'দৈনিক নিরাপত্তা সীমা শেষ; পরের দিনের জন্য অপেক্ষা', true);
        return 'waiting_quota';
    }
    if (gem_requests_last_minute($project) >= (int) setting('gemini_rpm_cap', '8')) {
        gem_job_wait($id, gem_plus(65), 'retry_scheduled', 'স্থানীয় RPM সীমায় অপেক্ষা', true);
        return 'retry_scheduled';
    }
    $cred = gem_pick_cred($project);
    if ($cred === null) {
        gem_job_wait($id, gem_plus(600), 'manual_required', 'এই প্রজেক্টে যাচাই-করা সক্রিয় credential নেই', true);
        return 'manual_required';
    }
    $model = gem_pick_model($task, $project);
    if ($model === null) {
        gem_job_wait($id, gem_plus(900), 'waiting_quota', 'অনুমোদিত মডেল এখন উপলব্ধ নেই (কোটা বা লভ্যতা); অন্য মডেলে নেওয়া হয়নি', true);
        return 'waiting_quota';
    }
    $excludeCreds = [];
    $excludeModels = [];
    for ($hop = 0; $hop < 6; $hop++) {
        q_exec('UPDATE gem_jobs SET attempts = attempts + 1, model_id = ?, project_id = ? WHERE id = ?', [$model, $project, $id]);
        $attempt = (int) q_val('SELECT attempts FROM gem_jobs WHERE id = ?', [$id]);
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => (string) ($in['prompt'] ?? '')]]]],
            'generationConfig' => ['maxOutputTokens' => (int) ($in['max_output_tokens'] ?? 400), 'temperature' => 0.4],
        ];
        if (!empty($in['system'])) {
            $body['systemInstruction'] = ['parts' => [['text' => (string) $in['system']]]];
        }
        $url = GEM_BASE . '/models/' . rawurlencode($model) . ':generateContent';
        [$code, $headers, $raw, $err] = gem_http('POST', $url, $body, gem_plain_key($cred));
        $c = gem_classify($code, $headers, $raw, $err);
        gem_log($c['kind'] === 'ok' ? 'info' : 'warn', 'call', $project, $model, $code, $c['kind'] === 'ok' ? 'job ' . $id : $c['kind'] . ': ' . $c['message']);

        if ($c['kind'] === 'ok') {
            $j = json_decode($raw, true);
            $text = '';
            foreach ((array) ($j['candidates'][0]['content']['parts'] ?? []) as $p) {
                $text .= (string) ($p['text'] ?? '');
            }
            $tin = (int) ($j['usageMetadata']['promptTokenCount'] ?? 0);
            $tout = (int) ($j['usageMetadata']['candidatesTokenCount'] ?? 0);
            gem_usage_add($project, $model, true, $tin, $tout, '');
            if (trim($text) === '') {
                gem_job_set($id, 'retry_scheduled', 'খালি উত্তর এসেছে; আবার চেষ্টা হবে', false);
                q_exec('UPDATE gem_jobs SET next_run_at = ? WHERE id = ?', [gem_plus(gem_backoff($attempt)), $id]);
                return 'retry_scheduled';
            }
            q_exec("UPDATE gem_jobs SET status = 'completed', result_text = ?, last_error = '', lock_token = NULL, locked_at = NULL, finished_at = ? WHERE id = ?",
                [$text, gem_now(), $id]);
            q_exec('UPDATE gem_credentials SET last_ok_at = ?, last_error = \'\' WHERE id = ?', [gem_now(), (int) $cred['id']]);
            return 'completed';
        }

        if ($c['kind'] === 'rate_daily') {
            gem_usage_add($project, $model, false, 0, 0, 'daily');
            gem_block($project, $model, 'daily', gem_next_pt_midnight(), 'daily quota');
            gem_job_wait($id, gem_next_pt_midnight(), 'waiting_quota', 'দৈনিক কোটা শেষ; Pacific midnight-এ রিসেট হবে', true);
            system_alert('warn', 'gemini', 'দৈনিক কোটা শেষ (প্রজেক্ট ' . $project . ', মডেল ' . $model . '); কাজ রিসেট পর্যন্ত অপেক্ষায়।');
            return 'waiting_quota';
        }
        if ($c['kind'] === 'rate_minute' || $c['kind'] === 'rate_unknown') {
            gem_usage_add($project, $model, false, 0, 0, $c['kind'] === 'rate_minute' ? 'minute' : '');
            $wait = gem_backoff($attempt, $c['retry_after']);
            gem_block($project, $model, 'minute', gem_plus($wait), 'per-minute limit');
            if ($attempt >= (int) $job['max_attempts']) {
                gem_job_set($id, 'manual_required', 'বারবার রেট-লিমিট: ' . $c['message'], false);
                return 'manual_required';
            }
            gem_job_wait($id, gem_plus($wait), 'retry_scheduled', 'রেট-লিমিট: ' . $c['message'], false);
            return 'retry_scheduled';
        }
        if ($c['kind'] === 'auth') {
            gem_usage_add($project, $model, false, 0, 0, '');
            $status = $c['auth_kind'] === 'invalid' ? 'invalid' : 'restricted';
            q_exec('UPDATE gem_credentials SET verify_status = ?, last_error = ? WHERE id = ?', [$status, mb_substr($c['message'], 0, 255), (int) $cred['id']]);
            system_alert('error', 'gemini', 'Gemini credential "' . $cred['label'] . '" কাজ করছে না (' . $status . '). Admin > Gemini থেকে নতুন key দিন।');
            $excludeCreds[] = (int) $cred['id'];
            $alt = gem_pick_cred($project, $excludeCreds); // same project only
            if ($alt === null) {
                gem_job_set($id, 'manual_required', 'Credential প্রত্যাখ্যাত (' . $status . '): ' . $c['message'], false);
                return 'manual_required';
            }
            $cred = $alt;
            continue;
        }
        if ($c['kind'] === 'model_unavailable') {
            q_exec("UPDATE gem_models SET available = 0, unavailable_reason = ? WHERE project_id = ? AND model_id = ?", [mb_substr($c['message'], 0, 160), $project, $model]);
            $excludeModels[] = $model;
            $next = gem_pick_model($task, $project, $excludeModels);
            if ($next === null) {
                gem_job_wait($id, gem_plus(3600), 'waiting_quota', 'কোনো অনুমোদিত মডেল উপলব্ধ নেই: ' . $c['message'], true);
                return 'waiting_quota';
            }
            $model = $next;
            continue;
        }
        if ($c['kind'] === 'bad_request') {
            gem_usage_add($project, $model, false, 0, 0, '');
            gem_job_set($id, 'failed', 'Invalid request (HTTP ' . $code . '): ' . $c['message'], true);
            return 'failed';
        }
        // server, unavailable (503), network, unknown: controlled retry, then manual
        gem_usage_add($project, $model, false, 0, 0, '');
        if ($attempt >= (int) $job['max_attempts']) {
            gem_job_set($id, 'manual_required', 'সার্ভার ত্রুটি ' . $attempt . ' বার: ' . $c['message'], false);
            system_alert('error', 'gemini', 'Gemini কাজ ' . $id . ' বারবার ব্যর্থ; Admin > Gemini থেকে দেখুন।');
            return 'manual_required';
        }
        gem_job_wait($id, gem_plus(gem_backoff($attempt)), 'retry_scheduled', $c['kind'] . ': ' . $c['message'], false);
        return 'retry_scheduled';
    }
    gem_job_wait($id, gem_plus(600), 'retry_scheduled', 'একই রানে অনেক বার মডেল/credential বদলানো হয়েছে; পরে আবার', false);
    return 'retry_scheduled';
}

/**
 * Cron entry: one worker at a time (MariaDB named lock). Returns a short report.
 * Processes at most $max due jobs per run.
 */
function gem_worker_tick(int $max = 5): array {
    gem_tables();
    $got = (int) q_val("SELECT GET_LOCK('jebu_gemini_worker', 0)");
    if ($got !== 1) {
        return ['skipped' => 'another worker is running'];
    }
    $done = [];
    try {
        $recovered = gem_recover_stale();
        q_exec('DELETE FROM gem_log WHERE at < ?', [gem_plus(-30 * 86400)]);
        $due = q_all("SELECT id FROM gem_jobs WHERE status IN ('pending','retry_scheduled','waiting_quota') AND next_run_at <= ? ORDER BY next_run_at, id LIMIT " . (int) $max, [gem_now()]);
        foreach ($due as $d) {
            $job = gem_claim((int) $d['id']);
            if ($job === null) {
                continue; // another worker took it
            }
            try {
                $done[(int) $d['id']] = gem_process($job);
            } catch (Throwable $e) {
                error_log('[jebu] gem_process: ' . $e->getMessage());
                gem_job_wait((int) $d['id'], gem_plus(300), 'retry_scheduled', 'internal error; retry scheduled', false);
                $done[(int) $d['id']] = 'internal_error';
            }
        }
        return ['recovered' => $recovered, 'processed' => $done];
    } finally {
        q_val("SELECT RELEASE_LOCK('jebu_gemini_worker')");
    }
}

/** Counts for the admin dashboard (no AI calls). */
function gem_health(): array {
    gem_tables();
    $counts = [];
    foreach (GEM_JOB_STATUSES as $s) {
        $counts[$s] = (int) q_val('SELECT COUNT(*) FROM gem_jobs WHERE status = ?', [$s]);
    }
    return [
        'jobs' => $counts,
        'last_ok' => q_val("SELECT MAX(at) FROM gem_log WHERE event = 'call' AND level = 'info'"),
        'rate_limits_24h' => (int) q_val("SELECT COUNT(*) FROM gem_log WHERE event = 'call' AND http_code = 429 AND at >= ?", [gem_plus(-86400)]),
        'errors_24h' => (int) q_val("SELECT COUNT(*) FROM gem_log WHERE event = 'call' AND level = 'warn' AND at >= ?", [gem_plus(-86400)]),
        'active_project' => gem_active_project(),
        'requests_today' => gem_requests_today(gem_active_project()),
        'blocks' => q_all('SELECT project_id, model_id, kind, blocked_until, reason FROM gem_quota_block WHERE blocked_until > ? ORDER BY blocked_until', [gem_now()]),
    ];
}
