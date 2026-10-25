<?php
declare(strict_types=1);

function h(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function redirect(string $to, int $code = 302): void {
    header('Location: ' . $to, true, $code);
    exit;
}

function client_ip(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function base_url(): string {
    if (SITE_URL !== '') {
        return rtrim(SITE_URL, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function abs_url(string $path = '/'): string { return base_url() . '/' . ltrim($path, '/'); }

/** Versioned static asset URL (cache-busting by file mtime). */
function asset(string $path): string {
    $file = WEB_ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return '/' . ltrim($path, '/') . '?v=' . $v;
}

/** Public URL for an uploaded/stored image; falls back to a placeholder so no <img> is ever broken. */
function img_url(?string $path, string $fallback = '/assets/img/placeholder.svg'): string {
    $path = trim((string) $path);
    if ($path !== '') {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $p = '/' . ltrim($path, '/');
        if (is_file(WEB_ROOT . strtok($p, '?'))) {
            return $p;
        }
    }
    return $fallback;
}

// ---------------- Settings ----------------
function settings_all(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = site_defaults();
    try {
        foreach (q_all('SELECT skey, svalue FROM site_settings') as $r) {
            if ($r['svalue'] !== '') {
                $cache[$r['skey']] = $r['svalue'];
            }
        }
    } catch (Throwable $e) {
        // DB not ready: fall back to defaults so the page can still render
    }
    return $cache;
}
function setting(string $key, string $default = ''): string {
    $s = settings_all();
    return isset($s[$key]) ? (string) $s[$key] : $default;
}
function setting_lines(string $key): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', setting($key)) ?: []), static fn($l) => $l !== ''));
}
function setting_paras(string $key): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', setting($key)) ?: []), static fn($l) => $l !== ''));
}
function save_setting(string $key, string $value): void {
    $exists = q_val('SELECT COUNT(*) FROM site_settings WHERE skey = ?', [$key]);
    if ((int) $exists > 0) {
        q_exec('UPDATE site_settings SET svalue = ?, updated_at = ? WHERE skey = ?', [$value, date('Y-m-d H:i:s'), $key]);
    } else {
        q_exec('INSERT INTO site_settings (skey, svalue) VALUES (?, ?)', [$key, $value]);
    }
}

function phone_digits(string $s): string {
    $s = strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    return preg_replace('/[^0-9+]/', '', $s) ?? '';
}
function tel_link(): string { return 'tel:+' . preg_replace('/\D/', '', setting('whatsapp_intl')); }
function wa_link(string $msg = ''): string {
    $u = 'https://wa.me/' . preg_replace('/\D/', '', setting('whatsapp_intl'));
    return $msg !== '' ? $u . '?text=' . rawurlencode($msg) : $u;
}

// ---------------- Text rendering ----------------
function inline_md(string $s): string {
    $s = h($s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace_callback('/\[([^\]]+)\]\((\/[^)\s]*|https?:\/\/[^)\s]+)\)/', static function ($m) {
        $ext = str_starts_with($m[2], 'http');
        return '<a href="' . $m[2] . '"' . ($ext ? ' rel="noopener nofollow" target="_blank"' : '') . '>' . $m[1] . '</a>';
    }, $s) ?? $s;
    return $s;
}

/** Tiny safe Markdown subset: ## / ### headings, - lists, **bold**, [links](url), paragraphs. */
function md(?string $text): string {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    $blocks = preg_split('/\R{2,}/u', $text) ?: [];
    $out = '';
    foreach ($blocks as $b) {
        $b = trim($b);
        if ($b === '') {
            continue;
        }
        if (str_starts_with($b, '### ')) { $out .= '<h3>' . inline_md(substr($b, 4)) . '</h3>'; continue; }
        if (str_starts_with($b, '## '))  { $out .= '<h2>' . inline_md(substr($b, 3)) . '</h2>'; continue; }
        // A block that is only an image: ![alt](/uploads/file.jpg) or ![alt](https://...)
        if (preg_match('/^!\[([^\]]*)\]\(((?:\/|https:\/\/)[^)\s]+)\)$/u', $b, $im) === 1) {
            $out .= '<figure class="inline-img"><img src="' . h($im[2]) . '" alt="' . h($im[1]) . '" loading="lazy" decoding="async"></figure>';
            continue;
        }
        $lines = preg_split('/\R/u', $b) ?: [];
        $isList = count($lines) > 0 && count(array_filter($lines, static fn($l) => preg_match('/^\s*[-*•]\s+/u', $l) === 1)) === count($lines);
        if ($isList) {
            $out .= '<ul>';
            foreach ($lines as $l) {
                $out .= '<li>' . inline_md(preg_replace('/^\s*[-*•]\s+/u', '', $l) ?? $l) . '</li>';
            }
            $out .= '</ul>';
            continue;
        }
        $out .= '<p>' . nl2br(inline_md($b), false) . '</p>';
    }
    return $out;
}

function md_blocks(string $text): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', trim($text)) ?: [])));
}

function reading_minutes(string $text): int {
    $plain = strip_tags($text);
    $words = preg_match_all('/[\p{L}\p{N}]+/u', $plain);
    return max(1, (int) ceil(((int) $words) / 180));
}

function trim_text(string $s, int $len): string {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
    return mb_strlen($s) > $len ? rtrim(mb_substr($s, 0, $len - 1)) . '…' : $s;
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

function bn_date(?string $dt): string {
    if (!$dt) {
        return '';
    }
    $t = strtotime($dt);
    if (!$t) {
        return '';
    }
    $months = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
    $s = (int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1] . ', ' . date('Y', $t);
    return strtr($s, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
}
/** "১৫+" / "15+" / "২০০" -> [15, '+'] ; no digits -> [0, ''] */
function stat_parse(string $raw): array {
    $ascii = strtr($raw, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    if (preg_match('/\d+/', $ascii, $m) !== 1) { return [0, '']; }
    return [(int) $m[0], str_contains($ascii, '+') ? '+' : ''];
}

function bn_num(int|string $n): string {
    return strtr((string) $n, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
}

// ---------------- CSRF / form tokens ----------------
/** Stateless signed token for public forms (no cookie needed): "<ts>.<hmac>". */
function form_token(): string {
    $ts = (string) time();
    return $ts . '.' . hash_hmac('sha256', 'form|' . $ts, APP_KEY);
}
function form_token_ok(string $tok, int $minAge = 2, int $maxAge = 7200): bool {
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $tok, $m)) {
        return false;
    }
    if (!hash_equals(hash_hmac('sha256', 'form|' . $m[1], APP_KEY), $m[2])) {
        return false;
    }
    $age = time() - (int) $m[1];
    return $age >= $minAge && $age <= $maxAge;
}

// ---------------- Logging ----------------
function log_activity(string $action, string $detail = '', ?int $userId = null): void {
    try {
        q_exec('INSERT INTO activity_log (user_id, action, detail, ip) VALUES (?,?,?,?)', [$userId, mb_substr($action, 0, 120), mb_substr($detail, 0, 500), client_ip()]);
    } catch (Throwable $e) {
        // never break the request because of logging
    }
}
function log_404(): void {
    try {
        $url = mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500);
        $ref = mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
        $n = q_exec('UPDATE not_found_log SET hits = hits + 1, last_seen = ?, referrer = ?, user_agent = ? WHERE url = ?', [date('Y-m-d H:i:s'), $ref, $ua, $url]);
        if ($n === 0) {
            q_exec('INSERT INTO not_found_log (url, referrer, user_agent) VALUES (?,?,?)', [$url, $ref, $ua]);
        }
    } catch (Throwable $e) {
    }
}

function indexnow_ping(string $url): void {
    if (INDEXNOW_KEY === '' || !function_exists('curl_init')) {
        return;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return;
    }
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['host' => $host, 'key' => INDEXNOW_KEY, 'keyLocation' => 'https://' . $host . '/' . INDEXNOW_KEY . '.txt', 'urlList' => [$url]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function json_ld(array $data): string {
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
}
