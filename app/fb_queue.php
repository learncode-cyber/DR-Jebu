<?php
declare(strict_types=1);
// Database queue for Facebook PAGE POSTS and their FIRST COMMENTS.
//
// Every publish (AI posts from fb_posts, source-note posts from fb_source_items) becomes a row in fb_jobs.
// - Idempotent: job_key = kind:ref_id is UNIQUE. Enqueuing the same post twice returns the same job.
// - Duplicate prevention: a job that already has its Facebook id is never sent again.
// - Retry: transient errors (network, HTTP 5xx, Graph rate-limit codes) retry with backoff.
// - Dead-letter: permanent errors, or too many attempts, set state 'dead' and raise an alert.
// - First comment is its own job (kind fbs_comment), queued after the post succeeds.
// - Worker: cron dispatch.php calls fbq_worker_tick(). A publish button also runs its job at once.
//
// Job states: queued -> running -> done | retry (back to queued when due) | dead.

const FBQ_MAX_ATTEMPTS = 5;
const FBQ_STALE_MINUTES = 10;

function fbq_table(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS fb_jobs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_key VARCHAR(80) NOT NULL,
        kind VARCHAR(20) NOT NULL,
        ref_id INT UNSIGNED NOT NULL,
        state VARCHAR(10) NOT NULL DEFAULT 'queued',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
        run_after DATETIME NOT NULL,
        lock_token VARCHAR(40) NULL,
        locked_at DATETIME NULL,
        result_id VARCHAR(190) NULL,
        last_error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY fbj_key (job_key),
        KEY fbj_due (state, run_after)
    ) DEFAULT CHARSET=utf8mb4");
}

function fbq_now(): string { return date('Y-m-d H:i:s'); }

/**
 * Returns the job id for kind:ref. Creates it if missing. A job in 'retry' or 'dead' is re-queued
 * (this is what the owner's "publish again" button does). 'done' and 'queued' jobs are returned as they are.
 */
function fbq_submit(string $kind, int $refId): int {
    fbq_table();
    $key = $kind . ':' . $refId;
    $row = q_one('SELECT id, state FROM fb_jobs WHERE job_key = ?', [$key]);
    if ($row) {
        if (in_array($row['state'], ['retry', 'dead'], true)) {
            q_exec("UPDATE fb_jobs SET state = 'queued', attempts = 0, run_after = ?, last_error = NULL, updated_at = ? WHERE id = ?",
                [fbq_now(), fbq_now(), (int) $row['id']]);
        }
        return (int) $row['id'];
    }
    $now = fbq_now();
    try {
        return q_insert('INSERT INTO fb_jobs (job_key, kind, ref_id, state, attempts, max_attempts, run_after, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$key, $kind, $refId, 'queued', 0, FBQ_MAX_ATTEMPTS, $now, $now, $now]);
    } catch (Throwable $e) {
        // Another request inserted the same key first (UNIQUE): return that row.
        return (int) q_val('SELECT id FROM fb_jobs WHERE job_key = ?', [$key]);
    }
}

/** Takes the job for this run. Returns the fresh row, or null when another run already has it. */
function fbq_claim(int $id): ?array {
    $tok = bin2hex(random_bytes(10));
    $now = fbq_now();
    $n = q_exec("UPDATE fb_jobs SET state = 'running', lock_token = ?, locked_at = ?, attempts = attempts + 1, updated_at = ?
                 WHERE id = ? AND state IN ('queued','retry') AND run_after <= ?", [$tok, $now, $now, $id, $now]);
    if ($n !== 1) {
        return null;
    }
    $row = q_one('SELECT * FROM fb_jobs WHERE id = ? AND lock_token = ?', [$id, $tok]);
    return $row ?: null;
}

/** Backoff after a transient failure: 1, 2, 4, 8 minutes (+ up to 15 s jitter), at most 1 hour. */
function fbq_backoff(int $attempts): int {
    $sec = min(3600, 60 * (2 ** max(0, $attempts - 1)));
    return $sec + random_int(0, 15);
}

/**
 * Classifies a Graph response. Returns [outcome, message] where outcome is ok | transient | permanent.
 * Codes: 190 token problem, 100 bad parameter and others are permanent; 1, 2, 4, 17, 32, 613 and HTTP 5xx/0 are transient.
 */
function fbq_classify(int $httpCode, string $raw): array {
    $json = json_decode($raw, true);
    $ec = is_array($json) ? (int) ($json['error']['code'] ?? 0) : 0;
    $em = is_array($json) ? mb_substr((string) ($json['error']['message'] ?? ''), 0, 150) : '';
    $msg = 'HTTP ' . $httpCode . ($ec ? ' code ' . $ec : '') . ($em !== '' ? ' ' . $em : '');
    if ($httpCode === 200 && is_array($json) && !empty($json['id'])) {
        return ['ok', $msg];
    }
    if ($httpCode === 0 || $httpCode >= 500 || in_array($ec, [1, 2, 4, 17, 32, 613], true)) {
        return ['transient', $msg];
    }
    return ['permanent', $msg];
}

/** Runs one job now (claim -> handler -> state). Returns [ok, message]. */
function fbq_run_job(int $id): array {
    fbq_table();
    $job = fbq_claim($id);
    if ($job === null) {
        $state = (string) q_val('SELECT state FROM fb_jobs WHERE id = ?', [$id]);
        return [$state === 'done', $state === 'done' ? 'আগেই পাঠানো হয়েছে।' : 'কাজটি এখন চলছে বা এখন চালানো যাবে না।'];
    }
    try {
        [$outcome, $msg, $resultId] = fbq_handle($job);
    } catch (Throwable $e) {
        [$outcome, $msg, $resultId] = ['transient', mb_substr($e->getMessage(), 0, 150), ''];
    }
    $now = fbq_now();
    if ($outcome === 'ok') {
        q_exec("UPDATE fb_jobs SET state = 'done', lock_token = NULL, result_id = ?, last_error = NULL, updated_at = ? WHERE id = ?",
            [$resultId !== '' ? $resultId : null, $now, $id]);
        return [true, $msg];
    }
    $attempts = (int) $job['attempts'];
    if ($outcome === 'permanent' || $attempts >= (int) $job['max_attempts']) {
        q_exec("UPDATE fb_jobs SET state = 'dead', lock_token = NULL, last_error = ?, updated_at = ? WHERE id = ?",
            [mb_substr($msg, 0, 250), $now, $id]);
        system_alert('error', 'facebook-queue', 'কাজ ' . $job['job_key'] . ' ব্যর্থ (dead-letter): ' . $msg);
        return [false, $msg];
    }
    $runAfter = date('Y-m-d H:i:s', time() + fbq_backoff($attempts));
    q_exec("UPDATE fb_jobs SET state = 'retry', lock_token = NULL, last_error = ?, run_after = ?, updated_at = ? WHERE id = ?",
        [mb_substr($msg, 0, 250), $runAfter, $now, $id]);
    return [false, $msg . ' — আবার চেষ্টা হবে'];
}

/** Dispatches by kind. Returns [outcome, message, result_id]. Outcome: ok | transient | permanent. */
function fbq_handle(array $job): array {
    return match ((string) $job['kind']) {
        'fbp_post' => fbq_do_fbp_post((int) $job['ref_id']),
        'fbs_post' => fbq_do_fbs_post((int) $job['ref_id']),
        'fbs_comment' => fbq_do_fbs_comment((int) $job['ref_id']),
        'fbc_reply' => fbq_do_fbc_reply((int) $job['ref_id']),
        'li_post' => fbq_do_li_post((int) $job['ref_id']),
        default => ['permanent', 'অজানা কাজের ধরন: ' . $job['kind'], ''],
    };
}

/** Post from the AI post table (fb_posts). */
function fbq_do_fbp_post(int $id): array {
    fbp_tables();
    $row = q_one('SELECT * FROM fb_posts WHERE id = ?', [$id]);
    if (!$row) { return ['permanent', 'পোস্ট পাওয়া যায়নি।', '']; }
    if ((string) $row['fb_post_id'] !== '' && $row['status'] === 'posted') {
        return ['ok', 'আগেই পাঠানো হয়েছে।', (string) $row['fb_post_id']];
    }
    $c = meta_cfg();
    if ($c['page_token'] === '' || $c['page_id'] === '') {
        return ['permanent', 'Meta Page ID বা Page token সেট করা নেই।', ''];
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . rawurlencode($c['page_id']) . '/feed';
    [$code, $res] = fb_http_post_json($url, (string) json_encode(['message' => (string) $row['body']], JSON_UNESCAPED_UNICODE), $c['page_token'], 20);
    [$outcome, $msg] = fbq_classify($code, (string) $res);
    $json = json_decode((string) $res, true);
    if ($outcome === 'ok') {
        $postId = (string) $json['id'];
        q_exec("UPDATE fb_posts SET status = 'posted', fb_post_id = ?, posted_at = ?, error = NULL WHERE id = ?", [$postId, fbq_now(), $id]);
        fbp_notify('✅ Facebook পেজে পোস্ট হয়েছে: ' . mb_substr((string) $row['body'], 0, 80) . '…');
        return ['ok', 'Facebook পেজে পোস্ট হয়েছে।', $postId];
    }
    q_exec("UPDATE fb_posts SET status = 'failed', error = ? WHERE id = ?", [mb_substr($msg, 0, 250), $id]);
    if ((int) ($json['error']['code'] ?? 0) === 190) {
        system_alert('critical', 'facebook-post', 'Page Access Token মেয়াদোত্তীর্ণ। সেটিংস থেকে নতুন টোকেন দিন।');
    }
    return [$outcome, 'পোস্ট করা যায়নি: ' . $msg, ''];
}

/** Post from a source note (fb_source_items). The first comment is queued as its own job on success. */
function fbq_do_fbs_post(int $id): array {
    fbs_table();
    $row = q_one('SELECT * FROM fb_source_items WHERE id = ?', [$id]);
    if (!$row) { return ['permanent', 'আইটেম পাওয়া যায়নি।', '']; }
    if ((string) $row['fb_post_id'] !== '') {
        // Already on the Page: make sure the first comment job exists, then stop. No second post.
        fbq_submit('fbs_comment', $id);
        return ['ok', 'আগেই পাঠানো হয়েছে।', (string) $row['fb_post_id']];
    }
    $text = (string) $row['draft'];
    if ($text === '') { return ['permanent', 'পোস্টের লেখা খালি।', '']; }
    $c = meta_cfg();
    if ($c['page_token'] === '' || $c['page_id'] === '') {
        return ['permanent', 'Meta Page ID বা Page token সেট করা নেই।', ''];
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . rawurlencode($c['page_id']) . '/feed';
    [$code, $res] = fb_http_post_json($url, (string) json_encode(['message' => $text], JSON_UNESCAPED_UNICODE), $c['page_token'], 20);
    [$outcome, $msg] = fbq_classify($code, (string) $res);
    $json = json_decode((string) $res, true);
    if ($outcome !== 'ok') {
        q_exec("UPDATE fb_source_items SET status = 'failed', error = ? WHERE id = ?", [mb_substr('পোস্ট ব্যর্থ: ' . $msg, 0, 250), $id]);
        if ((int) ($json['error']['code'] ?? 0) === 190) {
            system_alert('critical', 'facebook-post', 'Page Access Token মেয়াদোত্তীর্ণ। সেটিংস থেকে নতুন টোকেন দিন।');
        }
        return [$outcome, 'পোস্ট ব্যর্থ: ' . $msg, ''];
    }
    $postId = (string) $json['id'];
    q_exec("UPDATE fb_source_items SET status = 'published', fb_post_id = ?, published_at = ?, error = NULL WHERE id = ?", [$postId, fbq_now(), $id]);
    // First comment: separate job, so a failure there never re-sends the post.
    fbq_submit('fbs_comment', $id);
    return ['ok', 'পোস্ট হয়েছে।', $postId];
}

/** First comment with the appointment link (fb_source_items). Retries only this step. */
function fbq_do_fbs_comment(int $id): array {
    fbs_table();
    $row = q_one('SELECT * FROM fb_source_items WHERE id = ?', [$id]);
    if (!$row) { return ['permanent', 'আইটেম পাওয়া যায়নি।', '']; }
    if ((string) $row['first_comment_status'] === 'ok') {
        return ['ok', 'প্রথম কমেন্ট আগেই গেছে।', ''];
    }
    if ((string) $row['fb_post_id'] === '') { return ['permanent', 'পোস্ট আইডি নেই; প্রথম কমেন্ট দেওয়া যাবে না।', '']; }
    $c = meta_cfg();
    if ($c['page_token'] === '') { return ['permanent', 'Page token সেট করা নেই।', '']; }
    if (setting('fb_first_comment_enabled', '1') !== '1') {
        q_exec("UPDATE fb_source_items SET first_comment_status = 'skipped', error = NULL WHERE id = ?", [$id]);
        return ['ok', 'প্রথম কমেন্ট বন্ধ আছে।', ''];
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . rawurlencode((string) $row['fb_post_id']) . '/comments';
    $text = fbc_first_comment_text(setting('fb_first_comment_template', ''), setting('ai_cta_url', 'https://drjebunnesa.online/appointment'));
    [$code, $res] = fb_http_post_json($url, (string) json_encode(['message' => $text], JSON_UNESCAPED_UNICODE), $c['page_token'], 20);
    [$outcome, $msg] = fbq_classify($code, (string) $res);
    if ($outcome === 'ok') {
        q_exec("UPDATE fb_source_items SET first_comment_status = 'ok', error = NULL WHERE id = ?", [$id]);
        return ['ok', 'প্রথম কমেন্ট গেছে।', ''];
    }
    if ($outcome === 'permanent') {
        q_exec("UPDATE fb_source_items SET first_comment_status = 'failed', error = ? WHERE id = ?", [mb_substr('প্রথম কমেন্ট যায়নি: ' . $msg, 0, 250), $id]);
        system_alert('warning', 'facebook-post', 'পোস্ট হয়েছে কিন্তু প্রথম কমেন্ট যায়নি; ম্যানুয়ালি যোগ করুন।');
    }
    return [$outcome, 'প্রথম কমেন্ট: ' . $msg, ''];
}

/** Cron entry: recovers stale locks, then runs due jobs. Records the heartbeat for the Error Center. */
function fbq_worker_tick(int $max = 10): int {
    fbq_table();
    $stale = date('Y-m-d H:i:s', time() - FBQ_STALE_MINUTES * 60);
    q_exec("UPDATE fb_jobs SET state = 'retry', lock_token = NULL, run_after = ?, last_error = 'সময়সীমা পেরিয়েছে; পুনরায়' WHERE state = 'running' AND locked_at < ?", [fbq_now(), $stale]);
    $due = q_all("SELECT id FROM fb_jobs WHERE state IN ('queued','retry') AND run_after <= ? ORDER BY run_after, id LIMIT " . max(1, $max), [fbq_now()]);
    $ran = 0;
    foreach ($due as $r) {
        fbq_run_job((int) $r['id']);
        $ran++;
    }
    save_setting('sys_fbq_tick_at', date('c'));
    return $ran;
}

/** Owner action: put a dead or retrying job back in the queue now. Returns true when it was re-queued. */
function fbq_requeue(int $id): bool {
    fbq_table();
    return q_exec("UPDATE fb_jobs SET state = 'queued', attempts = 0, run_after = ?, last_error = NULL, updated_at = ? WHERE id = ? AND state IN ('retry','dead')", [fbq_now(), fbq_now(), $id]) === 1;
}

/** Owner action: cancel a job that is waiting or failed. */
function fbq_cancel(int $id): bool {
    fbq_table();
    return q_exec("UPDATE fb_jobs SET state = 'dead', last_error = 'বাতিল (owner)', lock_token = NULL, updated_at = ? WHERE id = ? AND state IN ('queued','retry','dead')", [fbq_now(), $id]) === 1;
}
