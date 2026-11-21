<?php
declare(strict_types=1);

/**
 * Facebook posts from owner-supplied source notes (approval first).
 * Flow: owner adds source name + short summary -> Gemini drafts (queue, privacy guard) -> owner approves ->
 * post goes to the Page feed (no links in the body) -> the appointment link is added as the FIRST COMMENT.
 * Nothing is scraped or published automatically in this version.
 */

const FBS_STATUSES = ['new', 'drafting', 'ready', 'needs_owner', 'published', 'failed', 'skipped'];

function fbs_table(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS fb_source_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_name VARCHAR(120) NOT NULL,
        source_url VARCHAR(500) NOT NULL DEFAULT '',
        title VARCHAR(200) NOT NULL DEFAULT '',
        notes TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'new',
        draft_job_id BIGINT NULL,
        draft TEXT NULL,
        fb_post_id VARCHAR(190) NULL,
        first_comment_status VARCHAR(12) NOT NULL DEFAULT '',
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        published_at DATETIME NULL,
        KEY fbs_status (status)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Adds a source note and queues its draft. Returns [ok, message]. */
function fbs_add(string $sourceName, string $url, string $title, string $notes): array {
    fbs_table();
    $sourceName = trim($sourceName);
    $url = trim($url);
    $notes = trim($notes);
    if ($sourceName === '' || mb_strlen($sourceName) > 120) { return [false, 'উৎসের নাম দিন (১২০ অক্ষরের মধ্যে)।']; }
    if ($url !== '' && preg_match('#^https://[^\s]+$#i', $url) !== 1) { return [false, 'উৎসের লিংক https:// দিয়ে শুরু হতে হবে।']; }
    if ($notes === '' || mb_strlen($notes) > 1500) { return [false, 'সারাংশ খালি, অথবা ১৫০০ অক্ষরের বেশি।']; }
    [$safe, $why] = gem_guard($sourceName . "\n" . $title . "\n" . $notes);
    if (!$safe) { return [false, 'এই তথ্য আটকানো হয়েছে: ' . $why]; }
    $id = q_insert("INSERT INTO fb_source_items (source_name, source_url, title, notes, status, created_at) VALUES (?,?,?,?, 'new', ?)",
        [$sourceName, $url, mb_substr(trim($title), 0, 200), $notes, date('Y-m-d H:i:s')]);
    fbs_queue_draft($id);
    return [true, 'যোগ হয়েছে; খসড়া কিউতে গেছে।'];
}

function fbs_prompt(array $row): string {
    return "ফেসবুক পেজের জন্য একটি শিক্ষামূলক পোস্টের খসড়া লিখুন। নিয়ম: প্রথম লাইন আকর্ষণীয় শিরোনাম; ৩–৫টি ছোট লাইন; কোনো লিংক, ফোন নম্বর, ওষুধের মাত্রা বা রোগ নির্ণয় নয়; উৎসের সারাংশের বাইরে কিছু যোগ করবেন না।\nউৎস: " . $row['source_name'] . "\nসারাংশ:\n" . $row['notes'];
}

function fbs_queue_draft(int $id): void {
    $row = q_one('SELECT * FROM fb_source_items WHERE id = ?', [$id]);
    if (!$row) { return; }
    [$ok, $jobId] = gem_submit('short_reply', fbs_prompt($row), 'আপনি একজন স্বাস্থ্য-শিক্ষা লেখক। সহজ বাংলায়, সতর্ক ও তথ্যনির্ভর লিখুন।', 400, 'fbs-' . $id);
    q_exec("UPDATE fb_source_items SET status = ?, draft_job_id = ? WHERE id = ?", [$ok ? 'drafting' : 'needs_owner', $ok ? $jobId : null, $id]);
}

/** Post text as it will be published: no links, credit line and educational line always present. */
function fbs_finish_text(string $draft, string $sourceName): string {
    $t = trim(str_replace(['**', '##', '`'], '', $draft));
    $t = preg_replace('#https?://\S+|www\.\S+#i', '', $t) ?? $t;
    $t = trim((string) preg_replace('/\n{3,}/', "\n\n", $t));
    if (!str_contains($t, 'সূত্র:')) { $t .= "\n\nসূত্র: " . $sourceName; }
    if (!str_contains($t, 'শিক্ষামূলক')) { $t .= "\n\nএই পোস্টটি শুধুমাত্র শিক্ষামূলক উদ্দেশ্যে। ব্যক্তিগত পরামর্শের জন্য চিকিৎসকের সঙ্গে যোগাযোগ করুন।"; }
    return mb_substr($t, 0, FBP_MAX_CHARS);
}

/** Moves finished queue jobs into drafts. Called from cron. */
function fbs_sync(): int {
    fbs_table();
    $rows = q_all("SELECT id, draft_job_id FROM fb_source_items WHERE status = 'drafting' AND draft_job_id IS NOT NULL LIMIT 50");
    $n = 0;
    foreach ($rows as $r) {
        $job = gem_job((int) $r['draft_job_id']);
        if ($job === null) { continue; }
        if ($job['status'] === 'completed') {
            $row = q_one('SELECT source_name FROM fb_source_items WHERE id = ?', [$r['id']]);
            $text = fbs_finish_text((string) $job['result_text'], (string) $row['source_name']);
            [$safe] = gem_guard($text);
            q_exec("UPDATE fb_source_items SET draft = ?, status = ? WHERE id = ?", [$safe ? $text : null, $safe ? 'ready' : 'needs_owner', $r['id']]);
            $n++;
        } elseif (in_array($job['status'], ['failed', 'manual_required', 'cancelled'], true)) {
            q_exec("UPDATE fb_source_items SET status = 'needs_owner', error = ? WHERE id = ?", [mb_substr((string) $job['last_error'], 0, 250), $r['id']]);
            $n++;
        }
    }
    return $n;
}

/** Publishes an approved text to the Page feed, then adds the appointment link as the first comment. */
function fbs_publish(int $id, string $text): array {
    fbs_table();
    $row = q_one('SELECT * FROM fb_source_items WHERE id = ?', [$id]);
    if (!$row || !in_array($row['status'], ['ready', 'needs_owner', 'failed'], true)) { return [false, 'এই আইটেম পাঠানো যাবে না।']; }
    $text = fbs_finish_text($text, (string) $row['source_name']);
    if (preg_match('#https?://|www\.#i', $text) === 1) { return [false, 'পোস্টে লিংক রাখা যাবে না।']; }
    [$safe, $why] = gem_guard($text);
    if (!$safe) { return [false, 'পোস্ট আটকানো হয়েছে: ' . $why]; }
    $c = meta_cfg();
    if ($c['page_token'] === '' || $c['page_id'] === '') { return [false, 'Meta Page ID বা Page token সেট করা নেই।']; }
    // Store the final text first; the queue job reads it from here (so a retry sends the same text).
    q_exec('UPDATE fb_source_items SET draft = ? WHERE id = ?', [$text, $id]);
    [$ok, $msg] = fbq_run_job(fbq_submit('fbs_post', $id));
    if (!$ok) { return [false, $msg]; }
    // The post is up. Run its first-comment job now (it is also retried by cron if it fails).
    [$commentOk] = fbq_run_job(fbq_submit('fbs_comment', $id));
    return [true, $commentOk ? 'পোস্ট ও প্রথম কমেন্ট হয়েছে।' : 'পোস্ট হয়েছে, তবে প্রথম কমেন্ট যায়নি (ম্যানুয়ালি যোগ করুন বা আবার চেষ্টা হবে)।'];
}

function fbs_skip(int $id): void {
    fbs_table();
    q_exec("UPDATE fb_source_items SET status = 'skipped' WHERE id = ? AND status <> 'published'", [$id]);
}
