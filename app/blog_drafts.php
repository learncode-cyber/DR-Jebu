<?php
declare(strict_types=1);

/*
 * Blog drafts from approved research (Phase C items 3, 4, 5).
 *  - A draft is requested ONLY from research the doctor approved (ms_can_use). It goes through the Gemini router
 *    (task blog_draft, privacy guard, usage tracking). Nothing is published from here.
 *  - Every draft gets a source block (source name, title, URL, research date). It is never removed by the AI.
 *  - Claim check: a fixed list of absolute or guaranteed-outcome phrases blocks the doctor's approval until the
 *    text is edited. This is a lexical check, not a medical review; the doctor's approval is the real gate.
 *  - Publishing a blog post needs doctor_approved_at (blog_can_publish). Posts published before this gate were
 *    marked approved by the migration so the live site keeps working.
 *  - Cover image: a cover needs a recorded source (owner library, licensed source, or AI educational image).
 *    No automatic image search or copying.
 */

const BLOG_RISK_PHRASES = ['100%', '১০০%', 'নিশ্চিত নিরাময়', 'সম্পূর্ণ নিরাময়', 'সম্পূর্ণ ভালো হবে', 'গ্যারান্টি', 'guaranteed', 'guarantee', 'cure ', 'definitely cure'];
const BLOG_COVER_SOURCES = [
    'owner' => 'নিজের লাইব্রেরি',
    'licensed' => 'লাইসেন্সকৃত উৎস',
    'ai_educational' => 'AI-তৈরি শিক্ষামূলক ছবি',
];

function blog_migrate_v109(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (setting('blog_v109') === '1') {
        return;
    }
    q_exec("CREATE TABLE IF NOT EXISTS blog_draft_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        med_research_id INT UNSIGNED NOT NULL,
        gem_job_id BIGINT NOT NULL,
        angle VARCHAR(200) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'requested',
        post_id INT UNSIGNED NULL,
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_job (gem_job_id)
    ) DEFAULT CHARSET=utf8mb4");
    $cols = array_column(q_all("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts'"), 'c');
    if (!in_array('doctor_approved_at', $cols, true)) {
        q_exec("ALTER TABLE blog_posts ADD COLUMN doctor_approved_at DATETIME NULL, ADD COLUMN doctor_approved_by VARCHAR(80) NOT NULL DEFAULT '', "
            . "ADD COLUMN med_research_id INT UNSIGNED NULL, ADD COLUMN cover_source VARCHAR(20) NOT NULL DEFAULT '', ADD COLUMN cover_credit VARCHAR(200) NOT NULL DEFAULT ''");
    }
    // Posts already live before this gate: marked as approved so nothing live is unpublished.
    q_exec("UPDATE blog_posts SET doctor_approved_at = COALESCE(published_at, created_at), doctor_approved_by = 'আগে থেকেই প্রকাশিত' "
        . "WHERE status = 'published' AND doctor_approved_at IS NULL");
    save_setting('blog_v109', '1');
}

/** Lists the risk phrases found in a text (case-insensitive). Empty array when the text is clean. */
function blog_risk_check(string $text): array {
    $found = [];
    foreach (BLOG_RISK_PHRASES as $p) {
        if (mb_stripos($text, $p) !== false) {
            $found[] = $p;
        }
    }
    return $found;
}

/** A cover image needs a recorded source. No cover needs nothing. */
function blog_cover_ok(string $coverImage, string $coverSource): bool {
    return trim($coverImage) === '' || array_key_exists($coverSource, BLOG_COVER_SOURCES);
}

function blog_can_publish(int $postId): bool {
    blog_migrate_v109();
    return (string) q_val('SELECT COALESCE(doctor_approved_at, \'\') FROM blog_posts WHERE id = ?', [$postId]) !== '';
}

/** Asks Gemini for a draft from ONE doctor-approved research item. Returns [ok, message]. */
function blog_request_draft(int $researchId, string $angle): array {
    blog_migrate_v109();
    if (!ms_can_use($researchId)) {
        return [false, 'শুধু ডাক্তার-অনুমোদিত গবেষণা থেকে ব্লগ লেখা যাবে।'];
    }
    $angle = trim($angle);
    if ($angle === '' || mb_strlen($angle) > 200) {
        return [false, 'লেখার বিষয় (১–২০০ অক্ষর) লিখুন।'];
    }
    $r = q_one('SELECT r.*, s.name AS src_name FROM med_research r JOIN med_sources s ON s.id = r.source_id WHERE r.id = ?', [$researchId]);
    if (!$r) {
        return [false, 'গবেষণা পাওয়া যায়নি।'];
    }
    $system = "তুমি বাংলায় সাধারণ পাঠকের জন্য একটি স্বাস্থ্য ব্লগের খসড়া লিখবে। শুধু নিচে দেওয়া সূত্রের তথ্য ব্যবহার করো; "
        . "নতুন তথ্য বানাবে না। নিশ্চিত ফল, '১০০%', 'সম্পূর্ণ নিরাময়' বা গ্যারান্টির মতো দাবি লিখবে না। "
        . "শেষে চিকিৎসকের সাথে পরামর্শের কথা লিখবে। ডাক্তারের নাম বা ফোন নম্বর বসাবে না।";
    $prompt = "লেখার বিষয়: " . $angle . "\n"
        . "সূত্র: " . $r['src_name'] . "\n"
        . "গবেষণার শিরোনাম: " . $r['title'] . "\n"
        . "গবেষণার তারিখ: " . $r['research_date'] . "\n"
        . "সংক্ষিপ্ত সারাংশ: " . (string) $r['summary'];
    [$ok, $jobId, $msg] = gem_submit('blog_draft', $prompt, $system, 1500, 'research:' . $researchId);
    if (!$ok || $jobId <= 0) {
        return [false, $msg];
    }
    if (!q_val('SELECT id FROM blog_draft_requests WHERE gem_job_id = ?', [$jobId])) {
        q_exec('INSERT INTO blog_draft_requests (med_research_id, gem_job_id, angle, status, created_at) VALUES (?,?,?,?,?)',
            [$researchId, $jobId, $angle, 'requested', date('Y-m-d H:i:s')]);
    }
    return [true, 'খসড়ার অনুরোধ কিউতে গেছে। তৈরি হলে এখানে দেখাবে।'];
}

/**
 * Turns finished Gemini drafts into blog posts (status draft, never published). Idempotent: a request is
 * collected once. Returns how many posts were created. Called from cron and the admin page.
 */
function blog_collect_drafts(): int {
    blog_migrate_v109();
    $made = 0;
    foreach (q_all("SELECT * FROM blog_draft_requests WHERE status = 'requested' ORDER BY id LIMIT 20") as $req) {
        $job = gem_job((int) $req['gem_job_id']);
        if (!$job) {
            continue;
        }
        if (in_array((string) $job['status'], ['failed', 'manual_required', 'cancelled'], true)) {
            q_exec("UPDATE blog_draft_requests SET status = 'failed', error = ? WHERE id = ?", [mb_substr((string) $job['last_error'], 0, 250), (int) $req['id']]);
            continue;
        }
        if ((string) $job['status'] !== 'completed' || trim((string) $job['result_text']) === '') {
            continue;
        }
        $r = q_one('SELECT r.*, s.name AS src_name FROM med_research r JOIN med_sources s ON s.id = r.source_id WHERE r.id = ?', [(int) $req['med_research_id']]);
        if (!$r) {
            q_exec("UPDATE blog_draft_requests SET status = 'failed', error = ? WHERE id = ?", ['গবেষণা পাওয়া যায়নি।', (int) $req['id']]);
            continue;
        }
        $body = trim((string) $job['result_text'])
            . "\n\n## সূত্র\n- " . $r['src_name'] . ': ' . $r['title'] . ' — ' . $r['url'] . ' (গবেষণার তারিখ: ' . $r['research_date'] . ')';
        $title = mb_substr((string) $req['angle'], 0, 200);
        $base = slugify($title) ?: 'blog-draft';
        $slug = $base;
        for ($i = 2; q_val('SELECT id FROM blog_posts WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        $now = date('Y-m-d H:i:s');
        $postId = q_insert("INSERT INTO blog_posts (slug, title, excerpt, body, status, robots, author_name, med_research_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$slug, $title, trim_text(strip_tags($body), 160), $body, 'draft', 'index', setting('doctor_name_bn'), (int) $r['id'], $now, $now]);
        q_exec("UPDATE blog_draft_requests SET status = 'drafted', post_id = ? WHERE id = ?", [$postId, (int) $req['id']]);
        $made++;
    }
    return $made;
}

/** Doctor's approval. Refused while the text still has risk phrases, or while the research is no longer approved. */
function blog_doctor_approve(int $postId, string $who): array {
    blog_migrate_v109();
    $p = q_one('SELECT * FROM blog_posts WHERE id = ?', [$postId]);
    if (!$p) {
        return [false, 'লেখা পাওয়া যায়নি।'];
    }
    if ((int) ($p['med_research_id'] ?? 0) > 0 && !ms_can_use((int) $p['med_research_id'])) {
        return [false, 'এই লেখার সূত্র গবেষণাটি আর অনুমোদিত নেই।'];
    }
    $risk = blog_risk_check((string) $p['body'] . ' ' . (string) $p['title']);
    if ($risk) {
        return [false, 'লেখায় এই দাবিগুলো আছে, আগে সম্পাদনা করে বাদ দিন: ' . implode(', ', $risk)];
    }
    q_exec('UPDATE blog_posts SET doctor_approved_at = ?, doctor_approved_by = ? WHERE id = ?', [date('Y-m-d H:i:s'), mb_substr($who, 0, 80), $postId]);
    return [true, 'অনুমোদন দেওয়া হয়েছে। এখন প্রকাশ করা যাবে।'];
}

/**
 * Cover from the owner's own library (the uploads folder). The file must already be there; nothing is fetched.
 * Licensed and AI educational covers need a credit text. Returns [ok, message].
 */
function blog_set_cover(int $postId, string $fileName, string $source, string $credit): array {
    blog_migrate_v109();
    $p = q_one('SELECT id FROM blog_posts WHERE id = ?', [$postId]);
    if (!$p) {
        return [false, 'লেখা পাওয়া যায়নি।'];
    }
    $lib = upload_files();
    if (!isset($lib[$fileName])) {
        return [false, 'ছবিটি লাইব্রেরিতে নেই।'];
    }
    if (!array_key_exists($source, BLOG_COVER_SOURCES)) {
        return [false, 'ছবির উৎস বেছে নিন।'];
    }
    $credit = trim($credit);
    if ($source !== 'owner' && $credit === '') {
        return [false, 'লাইসেন্সকৃত বা AI ছবির জন্য সূত্র/ক্রেডিট লিখুন।'];
    }
    q_exec('UPDATE blog_posts SET cover_image = ?, cover_source = ?, cover_credit = ?, updated_at = ? WHERE id = ?',
        ['/uploads/' . $fileName, $source, mb_substr($credit, 0, 200), date('Y-m-d H:i:s'), $postId]);
    return [true, 'কভার ছবি সেট হয়েছে।'];
}

/**
 * Facebook draft from a doctor-approved blog post. It goes to the Facebook queue with no schedule, so the cron
 * never sends it: the owner publishes it by hand from the Facebook queue. Returns [ok, message].
 */
function blog_repurpose_fb(int $postId): array {
    blog_migrate_v109();
    fbp_tables();
    $p = q_one('SELECT * FROM blog_posts WHERE id = ?', [$postId]);
    if (!$p) {
        return [false, 'লেখা পাওয়া যায়নি।'];
    }
    if ((string) $p['doctor_approved_at'] === '') {
        return [false, 'আগে ডাক্তারের অনুমোদন নিন।'];
    }
    $topic = 'blog:' . $postId;
    if (q_val('SELECT id FROM fb_posts WHERE topic = ?', [$topic])) {
        return [false, 'এই লেখার পোস্ট-খসড়া আগেই আছে।'];
    }
    $body = trim((string) $p['title']) . "\n\n" . (trim((string) $p['excerpt']) !== '' ? trim((string) $p['excerpt']) : trim_text(strip_tags((string) $p['body']), 300));
    $risk = blog_risk_check($body . ' ' . (string) $p['body']);
    if ($risk) {
        return [false, 'পোস্টে এই দাবিগুলো আছে, আগে লেখায় সম্পাদনা করুন: ' . implode(', ', $risk)];
    }
    q_insert("INSERT INTO fb_posts (topic, body, source, status, scheduled_at, created_at) VALUES (?,?,?,?,?,?)",
        [$topic, $body, 'blog', 'queued', null, date('Y-m-d H:i:s')]);
    return [true, 'ফেসবুক কিউতে যোগ হয়েছে (নিজে পাঠাতে হবে; স্বয়ংক্রিয় পাঠানো হবে না)।'];
}
