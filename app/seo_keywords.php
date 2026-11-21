<?php
declare(strict_types=1);
// SEO keyword plan: the owner enters keywords; the system checks which blog posts already cover them.
// No external data, no API, no AI. Coverage is a plain text match on the post's own fields.
//
// Coverage states per keyword:
//   covered     - at least one PUBLISHED post matches
//   draft_only  - only draft posts match (publish or finish them)
//   gap         - no post matches (a new blog is needed)

function skw_table(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS seo_keywords (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kw_norm VARCHAR(120) NOT NULL UNIQUE,
        keyword VARCHAR(120) NOT NULL,
        lang VARCHAR(4) NOT NULL DEFAULT 'bn',
        priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
        intent VARCHAR(12) NOT NULL DEFAULT 'info',
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
}

/** Normal form used for matching: lower case, no punctuation, single spaces. */
function skw_norm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $s) ?? $s; // \p{M}: Bangla vowel signs and virama must stay
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** Pure: does one post (its text fields) mention the keyword? */
function skw_post_matches(array $post, string $kwNorm): bool {
    if ($kwNorm === '') {
        return false;
    }
    foreach (['title', 'meta_title', 'focus_keyword', 'tags', 'excerpt'] as $f) {
        $v = skw_norm((string) ($post[$f] ?? ''));
        if ($v !== '' && str_contains(' ' . $v . ' ', ' ' . $kwNorm . ' ')) {
            return true;
        }
    }
    return false;
}

/** Pure: classifies a keyword from the posts that match it. */
function skw_state(array $matchingStatuses): string {
    if (in_array('published', $matchingStatuses, true)) {
        return 'covered';
    }
    return $matchingStatuses ? 'draft_only' : 'gap';
}

/** Adds one keyword. Returns [ok, message]. */
function skw_add(string $raw, string $lang = 'bn', int $priority = 2, string $intent = 'info', string $note = ''): array {
    skw_table();
    $kw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    $norm = skw_norm($kw);
    $len = mb_strlen($norm);
    if ($len < 2 || $len > 80) { return [false, 'কিওয়ার্ড ২ থেকে ৮০ অক্ষরের হতে হবে: ' . $raw]; }
    if (preg_match('#https?://|www\.#i', $kw) === 1) { return [false, 'কিওয়ার্ডে লিংক দেবেন না: ' . $raw]; }
    if (!in_array($lang, ['bn', 'en'], true)) { $lang = 'bn'; }
    if (!in_array($intent, ['info', 'service', 'local', 'other'], true)) { $intent = 'other'; }
    $priority = max(1, min(3, $priority));
    if ((int) q_val('SELECT COUNT(*) FROM seo_keywords WHERE kw_norm = ?', [$norm]) > 0) {
        return [false, 'আগে থেকেই আছে: ' . $kw];
    }
    q_insert('INSERT INTO seo_keywords (kw_norm, keyword, lang, priority, intent, note, created_at) VALUES (?,?,?,?,?,?,?)',
        [$norm, mb_substr($kw, 0, 120), $lang, $priority, $intent, mb_substr(trim($note), 0, 255), date('Y-m-d H:i:s')]);
    return [true, 'যোগ হয়েছে: ' . $kw];
}

/** Bulk add: one keyword per line. Returns counts and the refused lines. */
function skw_import(string $text, string $lang, int $priority, string $intent): array {
    $added = 0; $skipped = []; 
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        [$ok, $msg] = skw_add($line, $lang, $priority, $intent);
        $ok ? $added++ : $skipped[] = $msg;
    }
    return ['added' => $added, 'refused' => $skipped];
}

/** Coverage report for every keyword. Reads blog_posts only. */
function skw_report(): array {
    skw_table();
    $posts = q_all("SELECT id, title, meta_title, focus_keyword, tags, excerpt, status FROM blog_posts");
    $out = [];
    foreach (q_all('SELECT * FROM seo_keywords ORDER BY priority ASC, keyword ASC') as $k) {
        $statuses = [];
        foreach ($posts as $p) {
            if (skw_post_matches($p, (string) $k['kw_norm'])) { $statuses[] = (string) $p['status']; }
        }
        $k['state'] = skw_state($statuses);
        $k['match_count'] = count($statuses);
        $out[] = $k;
    }
    return $out;
}

function skw_delete(int $id): bool {
    skw_table();
    return q_exec('DELETE FROM seo_keywords WHERE id = ?', [$id]) === 1;
}
