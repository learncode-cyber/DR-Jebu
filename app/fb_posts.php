<?php
declare(strict_types=1);

/**
 * Facebook Page posts: the AI writes short awareness posts from the owner's topic list, the cron publishes them
 * to the Page on schedule, and each publish is reported to Telegram. A switch turns everything off, and AI posts
 * are capped per day. Posts are checked for banned claims (prices, percentages, guarantees, links, phone numbers).
 * Graph: POST /{page-id}/feed (permission pages_manage_posts). Delete: DELETE /{post-id}.
 */

const FBP_MAX_CHARS = 900;
const FBP_DEFAULT_TOPICS = [
    'মাসিকের স্বাভাবিক নিয়ম ও কখন চিকিৎসকের কাছে যাওয়া উচিত',
    'গর্ভাবস্থায় নিয়মিত চেকআপের গুরুত্ব',
    'বন্ধ্যাত্ব নিয়ে প্রচলিত কিছু ভুল ধারণা',
    'জরায়ুর সমস্যা নিয়ে কখন দ্রুত পরামর্শ নেবেন',
    'প্রসবের আগে ও পরে নারীর স্বাস্থ্যসেবা',
];

function fbp_tables(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS fb_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        topic VARCHAR(200) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        source VARCHAR(10) NOT NULL DEFAULT 'manual',
        status VARCHAR(12) NOT NULL DEFAULT 'queued',
        scheduled_at DATETIME NULL,
        posted_at DATETIME NULL,
        fb_post_id VARCHAR(190) NULL,
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        KEY idx_status (status, scheduled_at)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function fbp_enabled(): bool { return setting('fb_autopost_enabled', '0') === '1'; }

function fbp_daily_cap(): int { return max(1, min(6, (int) setting('fb_autopost_per_day', '1'))); }

function fbp_topics(): array {
    $list = setting_lines('fb_post_topics');
    return $list ?: FBP_DEFAULT_TOPICS;
}

/** Rejects text with prices, percentages, guarantees, links, phone numbers, or without the doctor-consultation line. */
function fbp_valid_text(string $t): bool {
    $len = mb_strlen($t);
    if ($len < 60 || $len > FBP_MAX_CHARS) { return false; }
    if (!str_contains($t, 'চিকিৎসক')) { return false; }
    if (preg_match('/%|৳|টাকা|\bTk\b|গ্যারান্টি|guarantee|https?:|www\.|\+?880|01[3-9]\d{8}|০১[৩-৯][০-৯]{8}/iu', $t)) { return false; }
    return true;
}

/** Asks Gemini for one awareness post on a topic. Returns '' when the AI is off or the text breaks the rules. */
function fbp_generate(string $topic): string {
    if (!ai_enabled()) { return ''; }
    $name = trim(setting('doctor_name_bn', 'ডাঃ জেবুন্নেসা জেবু'));
    $system = "তুমি খুলনার স্ত্রীরোগ ও প্রসূতি বিশেষজ্ঞ {$name}-এর ফেসবুক পেজের জন্য একটি স্বাস্থ্য-সচেতনতার পোস্ট লিখবে। "
        . "নিয়ম: ৩ থেকে ৫টি ছোট বাক্য, সহজ বাংলা। রোগ নির্ণয়, ওষুধের নাম বা ডোজ, চিকিৎসার গ্যারান্টি, সাফল্যের হার, দাম, শতাংশ, রোগীর গল্প বা রিভিউ লিখবে না। "
        . "কোনো লিঙ্ক বা ফোন নম্বর দেবে না। শেষে অবশ্যই লিখবে: 'নির্দিষ্ট সমস্যায় চিকিৎসকের সাথে পরামর্শ করুন।' শুধু পোস্টের লেখাটুকু দেবে, অন্য কিছু না।";
    $resp = gemini_generate([
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => 'বিষয়: ' . $topic]]]],
        'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 400],
    ]);
    $text = '';
    foreach (($resp['candidates'][0]['content']['parts'] ?? []) as $p) { $text .= (string) ($p['text'] ?? ''); }
    $text = trim(str_replace(['**', '##', '`'], '', $text));
    return fbp_valid_text($text) ? $text : '';
}

/**
 * Scheduling window: how far ahead a post may be planned. Default one year (owner setting fb_schedule_max_days).
 * Our queue posts at the right time with cron, so Meta's own limit on API scheduling (30 or 75 days) does not apply here.
 */
const FBP_SCHEDULE_MAX_DAYS = 365;

function fbp_schedule_max_days(): int {
    $d = (int) setting('fb_schedule_max_days', (string) FBP_SCHEDULE_MAX_DAYS);
    return max(1, min(3650, $d));
}

/** True when the time is parseable and not beyond the window. */
function fbp_schedule_ok(string $at, int $now, int $maxDays = FBP_SCHEDULE_MAX_DAYS): bool {
    $ts = strtotime($at);
    if ($ts === false) {
        return false;
    }
    return $ts <= $now + $maxDays * 86400;
}

function fbp_add(string $topic, string $body, string $source, string $at): int {
    fbp_tables();
    q_exec('INSERT INTO fb_posts (topic, body, source, status, scheduled_at, created_at) VALUES (?,?,?,?,?,?)',
        [mb_substr(trim($topic), 0, 200), trim($body), $source, 'queued', $at, date('Y-m-d H:i:s')]);
    return (int) q_val('SELECT MAX(id) FROM fb_posts');
}

/** Writes the next topic from the list and queues it for right now. Returns [ok, message]. */
function fbp_generate_one(): array {
    fbp_tables();
    $topics = fbp_topics();
    $idx = (int) setting('fb_post_topic_idx', '0');
    $topic = $topics[$idx % count($topics)];
    save_setting('fb_post_topic_idx', (string) ($idx + 1));
    $body = fbp_generate($topic);
    if ($body === '') {
        return [false, 'এআই পোস্ট তৈরি করতে পারেনি বা লেখাটি নিয়ম মানেনি। আবার চেষ্টা করুন।'];
    }
    fbp_add($topic, $body, 'ai', date('Y-m-d H:i:s'));
    return [true, 'এআই পোস্ট তৈরি হয়ে সারিতে যোগ হয়েছে।'];
}

function fbp_notify(string $text): void {
    if (telegram_enabled()) { telegram_send_text($text); }
}

/** Publishes one queued post to the Page. Records the result on the row. Returns [ok, message]. */
function fbp_publish(int $id): array {
    fbp_tables();
    $row = q_one('SELECT * FROM fb_posts WHERE id = ?', [$id]);
    if (!$row || !in_array($row['status'], ['queued', 'failed'], true)) {
        return [false, 'এই পোস্টটি পাঠানো যাবে না।'];
    }
    $c = meta_cfg();
    if ($c['page_token'] === '' || $c['page_id'] === '') {
        return [false, 'Meta Page ID বা Page token সেট করা নেই।'];
    }
    // Every publish goes through the database queue (idempotent, retried, dead-lettered).
    $jobId = fbq_submit('fbp_post', $id);
    [$ok, $msg] = fbq_run_job($jobId);
    return [$ok, $ok ? 'Facebook পেজে পোস্ট হয়েছে।' : 'পোস্ট করা যায়নি: ' . $msg];
}

/** Deletes a posted item from the Page (or just cancels a queued one). Returns [ok, message]. */
function fbp_delete(int $id): array {
    fbp_tables();
    $row = q_one('SELECT * FROM fb_posts WHERE id = ?', [$id]);
    if (!$row) { return [false, 'পোস্ট পাওয়া যায়নি।']; }
    if ($row['status'] === 'posted' && (string) $row['fb_post_id'] !== '') {
        $c = meta_cfg();
        $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . rawurlencode((string) $row['fb_post_id']);
        [$code, $res] = fbp_http_delete($url, $c['page_token']);
        $json = json_decode($res, true);
        if (!($code === 200 && is_array($json) && ($json['success'] ?? false) === true)) {
            return [false, 'Facebook থেকে মুছতে পারিনি (HTTP ' . $code . ')।'];
        }
    }
    q_exec("UPDATE fb_posts SET status = 'deleted' WHERE id = ?", [$id]);
    return [true, 'পোস্ট মুছে ফেলা হয়েছে।'];
}

function fbp_http_delete(string $url, string $token): array {
    if (!function_exists('curl_init') || $token === '') { return [0, '']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res === false ? '' : (string) $res];
}

/** Cron entry (every 5 minutes): optional AI queueing, then at most one due post. Does nothing when the switch is off. */
function fbp_cron(): void {
    if (!fbp_enabled()) { return; }
    fbp_tables();
    $cap = fbp_daily_cap();
    $today = date('Y-m-d 00:00:00');
    if (ai_enabled()) {
        $queuedAi = (int) q_val("SELECT COUNT(*) FROM fb_posts WHERE status = 'queued' AND source = 'ai'");
        $todayAi = (int) q_val("SELECT COUNT(*) FROM fb_posts WHERE source = 'ai' AND status <> 'deleted' AND created_at >= ?", [$today]);
        $last = (string) q_val("SELECT MAX(created_at) FROM fb_posts WHERE source = 'ai'");
        // Spread the AI posts evenly over the day (e.g. cap 2 = one every 12 hours)
        $dueGap = $last === '' || strtotime($last) <= time() - intdiv(86400, $cap);
        if ($queuedAi === 0 && $todayAi < $cap && $dueGap) { fbp_generate_one(); }
    }
    $next = q_one("SELECT id, source FROM fb_posts WHERE status = 'queued' AND scheduled_at IS NOT NULL AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT 1", [date('Y-m-d H:i:s')]);
    if (!$next) { return; }
    if ($next['source'] === 'ai') {
        $postedToday = (int) q_val("SELECT COUNT(*) FROM fb_posts WHERE status = 'posted' AND source = 'ai' AND posted_at >= ?", [$today]);
        if ($postedToday >= $cap) { return; }
    }
    fbp_publish((int) $next['id']);
}
