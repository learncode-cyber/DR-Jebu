<?php
declare(strict_types=1);

/**
 * Facebook Page post comments.
 * Safety design: the PUBLIC reply is a fixed neutral line (no medical content, no numbers),
 * and the real answer goes to the commenter by Private Reply (Messenger), once per comment.
 */

const META_COMMENT_HOURLY_LIMIT = 30;   // public + private replies per hour (anti-spam / quota guard)
const META_PRIVATE_REPLY_DAYS = 7;      // Meta allows a private reply to a comment for 7 days

function fb_comments_table(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS fb_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        comment_id VARCHAR(190) NOT NULL,
        post_id VARCHAR(190) NOT NULL DEFAULT '',
        from_id VARCHAR(64) NOT NULL DEFAULT '',
        from_name VARCHAR(120) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        public_status VARCHAR(12) NOT NULL DEFAULT 'queued',
        private_status VARCHAR(12) NOT NULL DEFAULT 'queued',
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_comment (comment_id),
        KEY idx_created (created_at)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Review workflow (approval first): new -> drafting -> ready -> sent; medical_hold and needs_owner go to the owner.
    q_exec("ALTER TABLE fb_comments ADD COLUMN IF NOT EXISTS review_status VARCHAR(16) NOT NULL DEFAULT 'new'");
    q_exec("ALTER TABLE fb_comments ADD COLUMN IF NOT EXISTS category VARCHAR(20) NULL");
    q_exec("ALTER TABLE fb_comments ADD COLUMN IF NOT EXISTS draft_job_id BIGINT NULL");
    q_exec("ALTER TABLE fb_comments ADD COLUMN IF NOT EXISTS draft TEXT NULL");
    q_exec("ALTER TABLE fb_comments ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL");
}

/** New comments on the Page's posts (webhook field "feed"). Edits, deletes, likes and our own comments are skipped. */
function fb_parse_comments(array $payload): array {
    if (($payload['object'] ?? '') !== 'page') { return []; }
    $pageId = meta_cfg()['page_id'];
    $out = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $ch) {
            $v = $ch['value'] ?? [];
            if (($ch['field'] ?? '') !== 'feed' || ($v['item'] ?? '') !== 'comment' || ($v['verb'] ?? '') !== 'add') { continue; }
            $cid = (string) ($v['comment_id'] ?? '');
            $from = (string) ($v['from']['id'] ?? '');
            $text = trim((string) ($v['message'] ?? ''));
            if ($cid === '' || $text === '' || $from === '' || $from === $pageId) { continue; }
            // Links from strangers are a common spam pattern: do not auto-reply to them.
            if (preg_match('#https?://|www\.#i', $text)) { continue; }
            $out[] = ['comment_id' => $cid, 'post_id' => (string) ($v['post_id'] ?? ''), 'from_id' => $from,
                'from_name' => mb_substr((string) ($v['from']['name'] ?? ''), 0, 120), 'body' => mb_substr($text, 0, 1000)];
        }
    }
    return $out;
}

/** Stores each comment once. Returns only the new ones. */
function fb_store_comments(array $rows): array {
    fb_comments_table();
    $fresh = [];
    foreach ($rows as $r) {
        try {
            q_exec('INSERT INTO fb_comments (comment_id, post_id, from_id, from_name, body, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$r['comment_id'], $r['post_id'], $r['from_id'], $r['from_name'], $r['body'], date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            continue; // Meta re-sent this comment
        }
        $fresh[] = $r;
    }
    return $fresh;
}

function fb_comment_hourly_count(): int {
    fb_comments_table();
    return (int) q_val('SELECT COUNT(*) FROM fb_comments WHERE created_at >= ? AND (public_status = ? OR private_status = ?)',
        [date('Y-m-d H:i:s', time() - 3600), 'ok', 'ok']);
}

function fb_comments_enabled(): bool {
    return setting('fb_comments_enabled', '0') === '1' && meta_enabled();
}

/** Public reply under the comment: short, fixed, admin-editable, never medical. */
function fb_graph_public_reply(string $commentId, string $text): array {
    $c = meta_cfg();
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . rawurlencode($commentId) . '/comments';
    [$code, $res] = fb_http_post_json($url, json_encode(['message' => $text], JSON_UNESCAPED_UNICODE), $c['page_token']);
    $json = json_decode($res, true);
    if ($code === 200 && is_array($json) && isset($json['id'])) { return ['ok' => true, 'error' => '']; }
    return ['ok' => false, 'error' => 'public HTTP ' . $code . ' code ' . (int) ($json['error']['code'] ?? 0)];
}

/** Private reply: a Messenger message to the commenter, addressed by comment ID. */
function fb_graph_private_reply(string $commentId, string $text): array {
    $c = meta_cfg();
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/me/messages';
    $body = json_encode(['recipient' => ['comment_id' => $commentId], 'message' => ['text' => $text]], JSON_UNESCAPED_UNICODE);
    [$code, $res] = fb_http_post_json($url, $body, $c['page_token']);
    $json = json_decode($res, true);
    if ($code === 200 && is_array($json) && isset($json['message_id'])) { return ['ok' => true, 'error' => '']; }
    return ['ok' => false, 'error' => 'private HTTP ' . $code . ' code ' . (int) ($json['error']['code'] ?? 0)];
}

/** Medical or health-risk words: never drafted by AI; the owner answers these by hand. */
function fb_comment_is_medical(string $text): bool {
    return preg_match('/ব্যথা|রক্ত|ওষুধ|ঔষধ|ডোজ|গর্ভ|প্রেগন্যান্ট|সিস্ট|পিসিওএস|ফাইব্রয়েড|বন্ধ্যা|চিকিৎসা|উপসর্গ|লক্ষণ|pain|bleed|blood|medic|dose|dosage|pregnan|pcos|cyst|fibroid|infertil|symptom|diagnos|tablet|pill|surgery|cancer|miscarr|abortion/iu', $text) === 1;
}

/**
 * Comment categories (owner list). Rule-based and deterministic, so it can be tested offline.
 * Order matters: a safety category wins over a friendly one (e.g. "ধন্যবাদ, রক্তপাত হচ্ছে" is emergency, not appreciation).
 */
const FB_COMMENT_CATEGORIES = ['emergency', 'medical_advice', 'pregnancy_question', 'spam', 'negative', 'appointment', 'greeting', 'appreciation', 'general_question', 'other'];
/** Only these may get an automatic reply (owner policy). Everything else waits for a person. */
const FB_COMMENT_AUTO_OK = ['greeting', 'appreciation'];
/** These always stop for doctor review and never get an automatic or AI-written medical answer. */
const FB_COMMENT_HOLD = ['emergency', 'medical_advice', 'pregnancy_question'];

function fb_comment_category(string $text): string {
    $t = mb_strtolower(trim($text));
    if ($t === '') { return 'other'; }
    if (preg_match('/জরুরি|জরুরী|অজ্ঞান|শ্বাস|রক্তপাত|রক্ত যাচ্ছে|তীব্র ব্যথা|emergency|unconscious|bleeding heavily/u', $t)) { return 'emergency'; }
    if (preg_match('/গর্ভ|প্রেগন্যান্ট|pregnan|প্রসব|বাচ্চা হচ্ছে/u', $t)) { return 'pregnancy_question'; }
    if (fb_comment_is_medical($t)) { return 'medical_advice'; }
    if (preg_match('/লোন|বিনিয়োগ|ইনকাম|ক্লিক করুন|বিটকয়েন|bitcoin|crypto|inbox me|earn money|guaranteed/iu', $t)) { return 'spam'; }
    if (preg_match('/প্রতারক|ভুয়া|বাজে|খারাপ সেবা|scam|fraud|worst/iu', $t)) { return 'negative'; }
    if (preg_match('/অ্যাপয়েন্টমেন্ট|appointment|চেম্বার|কখন বসেন|সময় দিন|booking|কোথায় বসেন|chamber/iu', $t)) { return 'appointment'; }
    if (preg_match('/আসসালামু|সালাম|হ্যালো|হাই |^hi\b|^hello\b|শুভেচ্ছা|assalam|salam/iu', $t)) { return 'greeting'; }
    if (preg_match('/ধন্যবাদ|শুকরিয়া|চমৎকার|দারুণ|ভালো লাগল|খুব ভালো|thank|great post|helpful/iu', $t)) { return 'appreciation'; }
    if (str_contains($t, '?') || preg_match('/কী|কি |কেন|কিভাবে|কীভাবে|কত|how|what|why/iu', $t)) { return 'general_question'; }
    return 'other';
}

/** Comment reply draft prompt: short, neutral, no medical advice, no numbers, no links. */
function fb_comment_draft_prompt(string $comment): string {
    return "একটি ফেসবুক কমেন্টের উত্তরের খসড়া লিখুন। নিয়ম: ২ বাক্যের বেশি নয়; কোনো চিকিৎসা পরামর্শ, ওষুধ বা রোগ নির্ণয় নয়; কোনো ফোন নম্বর বা লিংক নয়; বিনীত ও সহায়ক ভাষা; শেষে ইনবক্সে লিখতে বলুন। কমেন্ট:\n" . $comment;
}

/** Queues the draft through the Gemini queue (privacy guard, quota, retries apply). */
function fb_handle_comment(array $c): void {
    fb_comments_table();
    $row = q_one('SELECT review_status FROM fb_comments WHERE comment_id = ?', [$c['comment_id']]);
    if (!$row || $row['review_status'] !== 'new') { return; }
    $category = fb_comment_category($c['body']);
    q_exec('UPDATE fb_comments SET category = ? WHERE comment_id = ?', [$category, $c['comment_id']]);
    if (in_array($category, FB_COMMENT_HOLD, true)) {
        q_exec("UPDATE fb_comments SET review_status = 'medical_hold' WHERE comment_id = ?", [$c['comment_id']]);
        return;
    }
    if (in_array($category, ['spam', 'negative'], true)) {
        q_exec("UPDATE fb_comments SET review_status = 'owner_review' WHERE comment_id = ?", [$c['comment_id']]);
        return;
    }
    $sys = 'টোন: ' . setting('ai_reply_tone', 'friendly') . '। ভাষা: ' . setting('ai_reply_language', 'bn') . '। আপনি একজন স্বাস্থ্য ক্লিনিকের সহায়ক। শুধু সৌজন্যমূলক, সংক্ষিপ্ত উত্তর লিখুন। চিকিৎসা-সংক্রান্ত কিছু লিখবেন না।';
    [$ok, $jobId] = gem_submit('short_reply', fb_comment_draft_prompt($c['body']), $sys, 160, 'fbc-' . $c['comment_id']);
    q_exec('UPDATE fb_comments SET review_status = ?, draft_job_id = ? WHERE comment_id = ?',
        [$ok ? 'drafting' : 'needs_owner', $ok ? $jobId : null, $c['comment_id']]);
}

/** Moves finished queue jobs into the comment drafts. Called from cron. Returns how many changed. */
function fb_comment_sync_drafts(): int {
    fb_comments_table();
    $rows = q_all("SELECT comment_id, draft_job_id FROM fb_comments WHERE review_status = 'drafting' AND draft_job_id IS NOT NULL LIMIT 50");
    $n = 0;
    foreach ($rows as $r) {
        $job = gem_job((int) $r['draft_job_id']);
        if ($job === null) { continue; }
        if ($job['status'] === 'completed') {
            $text = trim(str_replace(['**', '##', '`'], '', (string) $job['result_text']));
            [$safe] = gem_guard($text);
            if ($text !== '' && $safe) {
                q_exec("UPDATE fb_comments SET draft = ?, review_status = 'ready' WHERE comment_id = ?", [mb_substr($text, 0, 1000), $r['comment_id']]);
            } else {
                q_exec("UPDATE fb_comments SET review_status = 'needs_owner', error = 'draft empty or blocked' WHERE comment_id = ?", [$r['comment_id']]);
            }
            $n++;
        } elseif (in_array($job['status'], ['failed', 'manual_required', 'cancelled'], true)) {
            q_exec("UPDATE fb_comments SET review_status = 'needs_owner', error = ? WHERE comment_id = ?", [mb_substr((string) $job['last_error'], 0, 250), $r['comment_id']]);
            $n++;
        }
    }
    return $n;
}

/**
 * Sends one approved reply: fixed public line + the approved private reply (once per commenter, within 7 days).
 * Nothing is sent without this call (the admin button). Returns [ok, message].
 */
function fb_comment_approve(int $id, string $privateText): array {
    fb_comments_table();
    $row = q_one('SELECT * FROM fb_comments WHERE id = ?', [$id]);
    if (!$row) { return [false, 'মন্তব্য পাওয়া যায়নি।']; }
    if (in_array($row['review_status'], ['sent', 'skipped', 'expired'], true)) { return [false, 'এটি আগেই শেষ হয়েছে।']; }
    $privateText = trim($privateText);
    if ($privateText === '' || mb_strlen($privateText) > 900) { return [false, 'উত্তর খালি, অথবা অনেক বড়।']; }
    if ((int) q_val("SELECT COUNT(*) FROM fb_comments WHERE from_id = ? AND private_status = 'ok' AND id <> ?", [(string) $row['from_id'], $id]) > 0) {
        return [false, 'এই ব্যক্তিকে আগে ইনবক্সে বার্তা পাঠানো হয়েছে; Meta-র নিয়মে একবারের বেশি নয়।'];
    }
    [$safe, $why] = gem_guard($privateText);
    if (!$safe) { return [false, 'উত্তর আটকানো হয়েছে: ' . $why]; }
    if (fb_comment_hourly_count() >= META_COMMENT_HOURLY_LIMIT) { return [false, 'ঘণ্টার সীমা শেষ; একটু পরে চেষ্টা করুন।']; }
    if (strtotime($row['created_at'] . ' UTC') < time() - META_PRIVATE_REPLY_DAYS * 86400) {
        q_exec("UPDATE fb_comments SET review_status = 'expired' WHERE id = ?", [$id]);
        return [false, 'মন্তব্যের ৭ দিন পার হয়ে গেছে; ইনবক্সে পাঠানো যাবে না।'];
    }
    // The send itself runs as a queue job (kind fbc_reply): retried with backoff, dead-letter alert,
    // and a retry sends only the step that failed (never a second public reply).
    q_exec('UPDATE fb_comments SET draft = ?, error = NULL WHERE id = ?', [mb_substr($privateText, 0, 1000), $id]);
    $jobId = fbq_submit('fbc_reply', $id);
    [$ok, $msg] = fbq_run_job($jobId);
    return $ok ? [true, 'উত্তর পাঠানো হয়েছে।'] : [false, 'পাঠানো যায়নি: ' . $msg];
}

/** The short public reply under a comment: owner's setting, or a default by the comment's language. */
function fb_comment_public_text(string $commentBody): string {
    $publicText = trim((string) setting('fb_comment_public_reply', ''));
    if ($publicText === '') {
        $publicText = fb_detect_lang($commentBody) === 'bn' ? 'ধন্যবাদ! বিস্তারিত জানতে আমরা আপনার ইনবক্সে বার্তা পাঠিয়েছি।' : 'Thank you! We have sent you a message with details.';
    }
    return $publicText;
}

/**
 * Queue handler for kind 'fbc_reply' (ref = fb_comments.id). Sends the public reply once, then the
 * private reply. Each step is recorded, so a later attempt skips a step that already succeeded.
 * Meta codes that can never succeed (permission, bad parameter, expired token, 7-day window) are permanent.
 */
function fbq_do_fbc_reply(int $id): array {
    fb_comments_table();
    $row = q_one('SELECT * FROM fb_comments WHERE id = ?', [$id]);
    if (!$row) { return ['permanent', 'মন্তব্য পাওয়া যায়নি।', '']; }
    if ($row['review_status'] === 'sent') { return ['ok', 'আগেই পাঠানো হয়েছে।', '']; }
    $privateText = trim((string) $row['draft']);
    if ($privateText === '') { return ['permanent', 'উত্তরের লেখা নেই।', '']; }
    $permanentCodes = [10, 100, 190, 200];
    $classify = static function (string $err) use ($permanentCodes): string {
        preg_match('/code (\d+)/', $err, $m);
        return isset($m[1]) && in_array((int) $m[1], $permanentCodes, true) ? 'permanent' : 'transient';
    };

    if ($row['public_status'] !== 'ok') {
        $pub = fb_graph_public_reply((string) $row['comment_id'], fb_comment_public_text((string) $row['body']));
        if (!$pub['ok']) {
            q_exec("UPDATE fb_comments SET public_status = 'failed', error = ? WHERE id = ?", [mb_substr($pub['error'], 0, 250), $id]);
            return [$classify($pub['error']), $pub['error'], ''];
        }
        q_exec("UPDATE fb_comments SET public_status = 'ok' WHERE id = ?", [$id]);
    }
    if ($row['private_status'] !== 'ok') {
        $priv = fb_graph_private_reply((string) $row['comment_id'], $privateText);
        if (!$priv['ok']) {
            q_exec("UPDATE fb_comments SET private_status = 'failed', review_status = 'failed', error = ? WHERE id = ?", [mb_substr($priv['error'], 0, 250), $id]);
            return [$classify($priv['error']), $priv['error'], ''];
        }
        q_exec("UPDATE fb_comments SET private_status = 'ok' WHERE id = ?", [$id]);
    }
    q_exec("UPDATE fb_comments SET review_status = 'sent', sent_at = ?, error = NULL WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
    return ['ok', 'উত্তর পাঠানো হয়েছে।', ''];
}

function fb_comment_skip(int $id): void {
    fb_comments_table();
    q_exec("UPDATE fb_comments SET review_status = 'skipped' WHERE id = ? AND review_status <> 'sent'", [$id]);
}

/**
 * Automatic sending, OFF by default. Runs only when the owner turned it on AND the start date has come.
 * It sends only 'ready' drafts of non-medical comments, through the same approval checks as the button.
 * Returns how many were sent.
 */
function fb_comment_autosend_run(): int {
    fb_comments_table();
    if (setting('fb_comments_auto_send', '0') !== '1') { return 0; }
    $from = trim(setting('fb_auto_from', ''));
    if ($from === '' || date('Y-m-d') < $from) { return 0; }
    $sent = 0;
    $autoList = "'" . implode("','", FB_COMMENT_AUTO_OK) . "'";
    // Only greeting and appreciation are sent automatically; a comment with no category yet is never sent.
    foreach (q_all("SELECT id, body, draft FROM fb_comments WHERE review_status = 'ready' AND draft IS NOT NULL AND draft <> '' AND category IN ($autoList) ORDER BY id LIMIT 10") as $r) {
        if (fb_comment_is_medical((string) $r['body'])) { continue; }
        [$ok] = fb_comment_approve((int) $r['id'], (string) $r['draft']);
        if ($ok) { $sent++; }
    }
    return $sent;
}
