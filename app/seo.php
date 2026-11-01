<?php
declare(strict_types=1);

/*
 * SEO engine (automatic part):
 *  - seo_record(): every meaningful URL change (publish/update/unpublish/delete) is logged and sent to IndexNow.
 *  - seo_audit(): health checks over settings + content; used by the admin SEO page and app/cron/seo-check.php.
 *  - chamber_map_src(): Google Map for the chamber, built from the address when no embed URL is given.
 * Sitemap, canonical, schema and breadcrumbs are already computed from the database on every request.
 */

function seo_ensure_table(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        q_exec('CREATE TABLE IF NOT EXISTS seo_change_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            url VARCHAR(255) NOT NULL,
            change_type VARCHAR(40) NOT NULL,
            detail VARCHAR(500) NULL,
            indexnow TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_seo_created (created_at)
        ) DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) {
        error_log('[jebu] seo_change_log: ' . $e->getMessage());
    }
}

/** Logs a meaningful URL change and notifies IndexNow (no-op until INDEXNOW_KEY is set). */
function seo_record(string $url, string $type, string $detail = ''): void {
    seo_ensure_table();
    $sent = 0;
    if (INDEXNOW_KEY !== '' && SITE_URL !== '') {
        indexnow_ping($url);
        $sent = 1;
    }
    try {
        q_exec('INSERT INTO seo_change_log (url, change_type, detail, indexnow, created_at) VALUES (?, ?, ?, ?, ?)',
            [$url, $type, mb_substr($detail, 0, 500), $sent, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('[jebu] seo_record: ' . $e->getMessage());
    }
}

/** Public detail URL of a content row, or null when that content type has no detail page. */
function seo_detail_url(string $key, string $slug): ?string {
    $e = entity($key);
    return empty($e['detail_file']) || $slug === '' ? null : abs_url(entity_url($key, $slug));
}

/** Google Map embed: owner-supplied embed URL first, otherwise generated from the chamber address (no API key needed). */
function chamber_map_src(): string {
    $src = setting('map_embed_src');
    if (preg_match('#^https://www\.google\.com/maps/embed#', $src) === 1) {
        return $src;
    }
    $addr = trim(setting('chamber_address'));
    if ($addr === '') {
        return '';
    }
    $query = $addr . (stripos($addr, 'bangladesh') === false ? ', Bangladesh' : '');
    return 'https://www.google.com/maps?q=' . rawurlencode($query) . '&output=embed';
}

/** Health checks. Returns ['critical' => [], 'warn' => [], 'ok' => [], 'urls' => int]. */
function seo_audit(): array {
    $crit = $warn = $ok = [];

    if (preg_match('#^https://[a-z0-9.-]+#i', SITE_URL) !== 1) {
        $crit[] = 'SITE_URL (config.php) https:// দিয়ে শুরু হওয়া উচিত।';
    } else {
        $ok[] = 'SITE_URL: ' . SITE_URL;
    }
    if (!defined('APP_ENV') || APP_ENV !== 'production') {
        $crit[] = 'APP_ENV production নয় — সব পেজ noindex থাকবে। লাইভে APP_ENV = production দিন।';
    } else {
        $ok[] = 'APP_ENV = production (ইনডেক্স করার অনুমতি আছে)';
    }
    if (is_file(dirname(APP_ROOT) . '/robots.php') === false) {
        $crit[] = 'robots.txt নেই।';
    }
    if (setting('gsc_verification') === '') {
        $warn[] = 'Google Search Console ভেরিফিকেশন কোড দেওয়া হয়নি (অ্যাডমিন → সেটিংস → SEO)।';
    } else {
        $ok[] = 'Google Search Console ভেরিফিকেশন কোড বসানো আছে';
    }
    if (setting('bing_verification') === '') {
        $warn[] = 'Bing Webmaster ভেরিফিকেশন কোড দেওয়া হয়নি।';
    }
    if (INDEXNOW_KEY === '') {
        $warn[] = 'INDEXNOW_KEY নেই — নতুন/বদলানো পেজ Bing-এ স্বয়ংক্রিয়ভাবে জানানো হবে না।';
    } else {
        $ok[] = 'IndexNow সক্রিয়';
    }
    if (trim(setting('chamber_address')) === '') {
        $warn[] = 'চেম্বারের ঠিকানা নেই — লোকাল SEO ও ম্যাপের জন্য ঠিকানা দিন।';
    } else {
        $ok[] = 'চেম্বারের ঠিকানা আছে, ম্যাপ ' . (chamber_map_src() !== '' ? 'বসেছে' : 'বসেনি');
    }
    if (trim(setting('facebook_url') . setting('youtube_url') . setting('instagram_url')) === '') {
        $warn[] = 'কোনো সোশ্যাল প্রোফাইল লিংক নেই (Facebook/YouTube/Instagram) — স্কিমায় sameAs যোগ হবে না।';
    }

    $urls = 15; // static public pages in sitemap.php
    try {
        foreach (['specialties', 'services', 'conditions', 'treatments', 'procedures'] as $k) {
            $t = entity($k);
            foreach (q_all('SELECT slug, title FROM ' . $t['table'] . ' WHERE is_active = 1') as $r) {
                $urls++;
                if (trim((string) $r['slug']) === '') {
                    $crit[] = $k . ' এর একটি রেকর্ডে slug নেই।';
                }
            }
        }
        $blogs = q_all("SELECT slug, title, excerpt, robots FROM blog_posts WHERE status = 'published'");
        foreach ($blogs as $b) {
            if ($b['robots'] === 'index') { $urls++; }
            if (trim((string) $b['excerpt']) === '') {
                $warn[] = 'ব্লগ "' . $b['slug'] . '": মেটা বিবরণ (excerpt) নেই।';
            }
            if (mb_strlen((string) $b['title']) > 65) {
                $warn[] = 'ব্লগ "' . $b['slug'] . '": শিরোনাম ৬৫ অক্ষরের বেশি, সার্চে কেটে যেতে পারে।';
            }
        }
        $dups = q_all('SELECT slug, COUNT(*) n FROM blog_posts GROUP BY slug HAVING n > 1');
        foreach ($dups as $d) {
            $crit[] = 'ডুপ্লিকেট ব্লগ slug: ' . $d['slug'];
        }
        if (!$blogs) {
            $warn[] = 'কোনো প্রকাশিত ব্লগ নেই — নিয়মিত লেখা সার্চ দৃশ্যমানতা বাড়ায়।';
        }
    } catch (Throwable $e) {
        $crit[] = 'ডাটাবেস পড়া যাচ্ছে না: ' . $e->getMessage();
    }

    return ['critical' => $crit, 'warn' => $warn, 'ok' => $ok, 'urls' => $urls];
}

/**
 * Content SEO checks for every published item (warn-only, never blocks a save).
 * Returns ['checked' => int, 'warn_items' => int, 'tip_items' => int, 'items' => [...]]
 * where each item: key, label, id, title, url, worst ('warn'|'tip'), issues [[level, text], ...].
 */
function seo_content_issues(): array {
    $rows = [];
    foreach (entities() as $key => $e) {
        if (empty($e['detail_file'])) {
            continue;
        }
        $live = $key === 'blog' ? "status = 'published'" : (empty($e['no_active']) ? 'is_active = 1' : '1 = 1');
        $bodyFields = array_values(array_filter($e['fields'], static fn($f) => in_array($f['type'], ['md', 'rich'], true)));
        $names = array_column($e['fields'], 'name');
        try {
            $allRows = q_all('SELECT * FROM ' . $e['table'] . ' WHERE ' . $live);
        } catch (Throwable $ex) {
            error_log('[jebu] seo content ' . $e['table'] . ': ' . $ex->getMessage());
            continue;
        }
        foreach ($allRows as $r) {
            $titleCol = $e['title_col'] ?? 'title';
            $slug = (string) ($r['slug'] ?? '');
            $url = $key === 'blog' ? abs_url('/blog/' . $slug) : seo_detail_url($key, $slug);
            $mt = trim((string) ($r['meta_title'] ?? ''));
            $md = trim((string) ($r['meta_description'] ?? ''));
            $issues = [];

            if ($mt === '') {
                $issues[] = ['warn', 'SEO title নেই'];
            } elseif (mb_strlen($mt) > 70) {
                $issues[] = ['warn', 'SEO title ' . mb_strlen($mt) . ' অক্ষর (৭০-এর বেশি)'];
            }
            if ($md === '') {
                $issues[] = ['warn', 'SEO description নেই'];
            } elseif (mb_strlen($md) > 170) {
                $issues[] = ['warn', 'SEO description ' . mb_strlen($md) . ' অক্ষর (১৭০-এর বেশি)'];
            } elseif (mb_strlen($md) < 50) {
                $issues[] = ['warn', 'SEO description খুব ছোট (' . mb_strlen($md) . ' অক্ষর, অন্তত ৫০)'];
            }
            if (in_array('cover_image', $names, true) && trim((string) ($r['cover_image'] ?? '')) === '') {
                $issues[] = ['tip', 'কভার ছবি নেই'];
            }
            if ($bodyFields) {
                $words = 0;
                $noAlt = 0;
                foreach ($bodyFields as $bf) {
                    $v = (string) ($r[$bf['name']] ?? '');
                    if ($bf['type'] === 'md') {
                        $noAlt += preg_match_all('/!\[\s*\]\(/', $v);
                        $plain = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $v) ?? $v;
                    } else {
                        $noAlt += preg_match_all('/<img\b(?![^>]*\balt\s*=\s*"[^"]+")[^>]*>/i', $v);
                        $plain = $v;
                    }
                    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $words += count(preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                }
                if ($words < 300) {
                    $issues[] = ['tip', 'লেখা মাত্র ' . $words . ' শব্দ (অন্তত ৩০০ শব্দ ভালো)'];
                }
                if ($noAlt > 0) {
                    $issues[] = ['warn', 'alt-টেক্সট ছাড়া ছবি: ' . $noAlt . 'টি'];
                }
            }
            $kw = trim((string) ($r['focus_keyword'] ?? ''));
            if ($kw !== '' && mb_stripos((string) ($r[$titleCol] ?? '') . ' ' . $mt, $kw) === false) {
                $issues[] = ['tip', 'Focus keyword "' . $kw . '" শিরোনাম বা SEO title-এ নেই'];
            }
            if (mb_strlen($slug) > 75) {
                $issues[] = ['tip', 'URL slug খুব লম্বা (' . mb_strlen($slug) . ' অক্ষর)'];
            }
            $rows[] = [
                'key' => $key, 'label' => $e['label'], 'id' => (int) $r['id'],
                'title' => mb_substr((string) ($r[$titleCol] ?? ''), 0, 120), 'url' => $url,
                'mt' => mb_strtolower($mt), 'md' => mb_strtolower($md), 'issues' => $issues,
            ];
        }
    }
    // duplicates across all published items
    $countMt = array_count_values(array_filter(array_column($rows, 'mt'), static fn($v) => $v !== ''));
    $countMd = array_count_values(array_filter(array_column($rows, 'md'), static fn($v) => $v !== ''));
    $out = ['checked' => count($rows), 'warn_items' => 0, 'tip_items' => 0, 'items' => []];
    foreach ($rows as $row) {
        if ($row['mt'] !== '' && ($countMt[$row['mt']] ?? 0) > 1) {
            $row['issues'][] = ['warn', 'একই SEO title অন্তত আরও একটি পেজে আছে'];
        }
        if ($row['md'] !== '' && ($countMd[$row['md']] ?? 0) > 1) {
            $row['issues'][] = ['warn', 'একই SEO description অন্তত আরও একটি পেজে আছে'];
        }
        if (!$row['issues']) {
            continue;
        }
        $worst = 'tip';
        foreach ($row['issues'] as [$lv]) {
            if ($lv === 'warn') { $worst = 'warn'; }
        }
        $row['worst'] = $worst;
        $worst === 'warn' ? $out['warn_items']++ : $out['tip_items']++;
        unset($row['mt'], $row['md']);
        $out['items'][] = $row;
    }
    usort($out['items'], static fn($a, $b) => [$a['worst'] === 'warn' ? 0 : 1] <=> [$b['worst'] === 'warn' ? 0 : 1]);
    return $out;
}
