<?php
declare(strict_types=1);
// Approved Medical Source Library + research log + doctor approval gate.
//
// Rules (owner decisions):
// - Only the owner-approved sources below can be recorded.
// - A research item is recorded by the owner (title, URL, a short summary). Nothing is fetched or scraped.
// - A URL must be https and on the approved source's own host.
// - A research item can be used in a blog or a post only after the doctor approves it (status 'doctor_approved').
// - Each source has a terms check status. Nothing is pulled from a source until its terms are checked
//   (status 'terms_ok'). Until then the access route stays 'pending_terms_check'.

const MS_SEED = [
    // code, name (English), host, access route
    ['WHO', 'World Health Organization', 'who.int', 'pending_terms_check'],
    ['NHS', 'NHS (England)', 'nhs.uk', 'pending_terms_check'],
    ['CDC', 'Centers for Disease Control and Prevention', 'cdc.gov', 'pending_terms_check'],
    ['NICE', 'National Institute for Health and Care Excellence', 'nice.org.uk', 'pending_terms_check'],
    ['ACOG', 'American College of Obstetricians and Gynecologists', 'acog.org', 'pending_terms_check'],
    ['RCOG', 'Royal College of Obstetricians and Gynaecologists', 'rcog.org.uk', 'pending_terms_check'],
    ['FIGO', 'International Federation of Gynecology and Obstetrics', 'figo.org', 'pending_terms_check'],
    ['PUBMED', 'PubMed (NLM / NCBI)', 'pubmed.ncbi.nlm.nih.gov', 'pending_terms_check'],
];

function ms_tables(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS med_sources (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(160) NOT NULL,
        host VARCHAR(120) NOT NULL,
        access_route VARCHAR(24) NOT NULL DEFAULT 'pending_terms_check',
        terms_status VARCHAR(16) NOT NULL DEFAULT 'unchecked',
        enabled TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    q_exec("CREATE TABLE IF NOT EXISTS med_research (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL,
        url VARCHAR(500) NOT NULL,
        summary TEXT NULL,
        research_date DATE NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'researched',
        approved_by VARCHAR(80) NULL,
        approved_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY ms_status (status),
        KEY ms_source (source_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Seed the approved list once. Existing rows are never changed.
    $now = date('Y-m-d H:i:s');
    foreach (MS_SEED as [$code, $name, $host, $route]) {
        if ((int) q_val('SELECT COUNT(*) FROM med_sources WHERE code = ?', [$code]) === 0) {
            q_exec('INSERT INTO med_sources (code, name, host, access_route, terms_status, enabled, created_at) VALUES (?,?,?,?,?,?,?)',
                [$code, $name, $host, $route, 'unchecked', 1, $now]);
        }
    }
}

/** True when the URL is https and its host is the source's host or a subdomain of it. */
function ms_url_ok(string $url, string $host): bool {
    if (!preg_match('#^https://#i', $url)) {
        return false;
    }
    $h = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $h !== '' && ($h === $host || str_ends_with($h, '.' . $host));
}

/** Records a research item from the owner. Returns [ok, message]. Never fetches the URL. */
function ms_add_research(int $sourceId, string $title, string $url, string $summary, string $date): array {
    ms_tables();
    $src = q_one('SELECT * FROM med_sources WHERE id = ?', [$sourceId]);
    if (!$src || (int) $src['enabled'] !== 1) { return [false, 'এই উৎসটি সক্রিয় নেই।']; }
    $title = trim($title);
    $url = trim($url);
    if ($title === '' || mb_strlen($title) > 200) { return [false, 'শিরোনাম লিখুন (২০০ অক্ষরের মধ্যে)।']; }
    if (!ms_url_ok($url, (string) $src['host'])) { return [false, 'URL অবশ্যই https দিয়ে শুরু হবে এবং ' . $src['host'] . ' ডোমেইনের হতে হবে।']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { return [false, 'গবেষণার তারিখ (YYYY-MM-DD) সঠিক নয়।']; }
    [$safe, $why] = gem_guard($title . ' ' . $summary);
    if (!$safe) { return [false, 'লেখায় ব্যক্তিগত তথ্য আছে: ' . $why]; }
    q_insert('INSERT INTO med_research (source_id, title, url, summary, research_date, status, created_at) VALUES (?,?,?,?,?,?,?)',
        [$sourceId, mb_substr($title, 0, 200), mb_substr($url, 0, 500), mb_substr(trim($summary), 0, 4000), $date, 'researched', date('Y-m-d H:i:s')]);
    return [true, 'গবেষণা নথিভুক্ত হয়েছে। ডাক্তারের অনুমোদনের পর ব্যবহার করা যাবে।'];
}

/** The doctor approves or rejects a research item. Returns [ok, message]. */
function ms_decide(int $id, string $decision, string $who): array {
    ms_tables();
    if (!in_array($decision, ['doctor_approved', 'rejected'], true)) { return [false, 'সিদ্ধান্ত সঠিক নয়।']; }
    $n = q_exec('UPDATE med_research SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?',
        [$decision, $decision === 'doctor_approved' ? mb_substr($who, 0, 80) : null, $decision === 'doctor_approved' ? date('Y-m-d H:i:s') : null, $id]);
    return $n === 1 ? [true, $decision === 'doctor_approved' ? 'অনুমোদিত হয়েছে।' : 'বাতিল করা হয়েছে।'] : [false, 'আইটেম পাওয়া যায়নি।'];
}

/** Gate used by blog and post code: only an approved research item can be used. */
function ms_can_use(int $researchId): bool {
    ms_tables();
    return (string) q_val('SELECT status FROM med_research WHERE id = ?', [$researchId]) === 'doctor_approved';
}

/** Owner action: mark a source's terms as checked (after the owner has read them). */
function ms_set_terms(int $sourceId, string $route): bool {
    ms_tables();
    if (!in_array($route, ['pending_terms_check', 'official_api', 'official_rss', 'public_info'], true)) { return false; }
    return q_exec("UPDATE med_sources SET access_route = ?, terms_status = 'terms_ok' WHERE id = ?", [$route, $sourceId]) === 1;
}
