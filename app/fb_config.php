<?php
declare(strict_types=1);

/*
 * Validation for the owner's Facebook settings pages. Pure functions: no database, no network.
 * Secrets (App Secret, Page token, verify token) are NOT validated here; they are stored encrypted by save_setting().
 */

/** Official site links only: the AI reply and the first comment must point to the doctor's own domain. */
function fbc_official_url_ok(string $u): bool {
    return preg_match('#^https://drjebunnesa\.online/[A-Za-z0-9/_.\-]*$#', $u) === 1;
}

/** Returns [clean values, error list] for the Meta App Configuration page. */
function fbc_validate_app(array $in): array {
    $errs = [];
    $clean = [];
    $clean['meta_app_name'] = mb_substr(trim((string) ($in['meta_app_name'] ?? '')), 0, 100);
    $id = trim((string) ($in['meta_app_id'] ?? ''));
    if ($id !== '' && preg_match('/^\d{8,20}$/', $id) !== 1) { $errs[] = 'App ID শুধু সংখ্যা (৮–২০ অঙ্ক) হবে।'; }
    $clean['meta_app_id'] = $id;
    $mode = (string) ($in['meta_app_mode'] ?? 'development');
    if (!in_array($mode, ['development', 'live'], true)) { $errs[] = 'App mode শুধু Development বা Live হতে পারে।'; $mode = 'development'; }
    $clean['meta_app_mode'] = $mode;
    $ver = trim((string) ($in['meta_graph_version'] ?? ''));
    if ($ver !== '' && preg_match('/^v\d{1,2}\.\d{1,2}$/', $ver) !== 1) { $errs[] = 'Graph API ভার্সন এমন হবে: v26.0'; }
    $clean['meta_graph_version'] = $ver !== '' ? $ver : 'v26.0';
    $biz = trim((string) ($in['meta_business_id'] ?? ''));
    if ($biz !== '' && preg_match('/^\d{5,20}$/', $biz) !== 1) { $errs[] = 'Business Portfolio ID শুধু সংখ্যা হবে (ঐচ্ছিক)।'; }
    $clean['meta_business_id'] = $biz;
    $email = trim((string) ($in['meta_contact_email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) { $errs[] = 'App contact email সঠিক নয়।'; }
    $clean['meta_contact_email'] = $email;
    foreach (['meta_privacy_url' => 'Privacy Policy URL', 'meta_terms_url' => 'Terms of Service URL', 'meta_deletion_url' => 'Data Deletion URL'] as $k => $label) {
        $u = trim((string) ($in[$k] ?? ''));
        if ($u !== '' && (!str_starts_with($u, 'https://') || filter_var($u, FILTER_VALIDATE_URL) === false)) {
            $errs[] = $label . ' অবশ্যই https:// দিয়ে শুরু হতে হবে।';
        }
        $clean[$k] = $u;
    }
    return [$clean, $errs];
}

/** Returns [clean values, error list] for the AI Reply page. Human approval is always on for medical replies. */
function fbc_validate_ai(array $in): array {
    $errs = [];
    $tone = (string) ($in['ai_reply_tone'] ?? 'friendly');
    if (!in_array($tone, ['friendly', 'formal', 'warm'], true)) { $errs[] = 'টোন সঠিক নয়।'; $tone = 'friendly'; }
    $lang = (string) ($in['ai_reply_language'] ?? 'bn');
    if (!in_array($lang, ['bn', 'en', 'bn_en'], true)) { $errs[] = 'ভাষা সঠিক নয়।'; $lang = 'bn'; }
    $cta = trim((string) ($in['ai_cta_url'] ?? ''));
    if ($cta === '' || !fbc_official_url_ok($cta)) { $errs[] = 'Appointment URL অবশ্যই https://drjebunnesa.online/ দিয়ে শুরু হতে হবে।'; $cta = 'https://drjebunnesa.online/appointment'; }
    $thr = (float) ($in['ai_confidence_threshold'] ?? 0.8);
    if ($thr < 0 || $thr > 1) { $errs[] = 'Confidence threshold 0 থেকে 1 এর মধ্যে হবে।'; $thr = 0.8; }
    $att = (int) ($in['ai_max_reply_attempts'] ?? 2);
    if ($att < 1 || $att > 3) { $errs[] = 'সর্বোচ্চ চেষ্টা ১ থেকে ৩ এর মধ্যে হবে।'; $att = 2; }
    return [[
        'ai_reply_tone' => $tone, 'ai_reply_language' => $lang, 'ai_cta_url' => $cta,
        'ai_confidence_threshold' => (string) $thr, 'ai_max_reply_attempts' => (string) $att,
    ], $errs];
}

/** Returns [clean values, error list] for the Publishing Settings page. */
function fbc_validate_publishing(array $in): array {
    $errs = [];
    $mode = (string) ($in['fb_publish_mode'] ?? 'manual');
    if (!in_array($mode, ['manual', 'scheduled', 'automatic'], true)) { $errs[] = 'প্রকাশের মোড সঠিক নয়।'; $mode = 'manual'; }
    $perDay = (int) ($in['fb_autopost_per_day'] ?? 1);
    if ($perDay < 1 || $perDay > 6) { $errs[] = 'দিনে সর্বোচ্চ পোস্ট ১ থেকে ৬ এর মধ্যে হবে।'; $perDay = 1; }
    $maxQ = (int) ($in['fb_max_queue'] ?? 50);
    if ($maxQ < 1 || $maxQ > 500) { $errs[] = 'সর্বোচ্চ কিউ ১ থেকে ৫০০ এর মধ্যে হবে।'; $maxQ = 50; }
    $fc = (string) ($in['fb_first_comment_enabled'] ?? '0') === '1' ? '1' : '0';
    $tpl = trim((string) ($in['fb_first_comment_template'] ?? ''));
    if (mb_strlen($tpl) > 500) { $errs[] = 'প্রথম কমেন্টের লেখা ৫০০ অক্ষরের বেশি হবে না।'; $tpl = mb_substr($tpl, 0, 500); }
    return [[
        'fb_publish_mode' => $mode,
        // automatic publishing is the only mode that turns on the AI auto-post switch
        'fb_autopost_enabled' => $mode === 'automatic' ? '1' : '0',
        'fb_autopost_per_day' => (string) $perDay,
        'fb_max_queue' => (string) $maxQ,
        'fb_first_comment_enabled' => $fc,
        'fb_first_comment_template' => $tpl,
    ], $errs];
}

/** The first-comment text: the template with the official link. The link is appended when the template has no {link}. */
function fbc_first_comment_text(string $template, string $url): string {
    $tpl = trim($template) !== '' ? $template : "অ্যাপয়েন্টমেন্ট ও ডাক্তার দেখানোর সময় জানতে ভিজিট করুন:\n{link}";
    $out = str_contains($tpl, '{link}') ? str_replace('{link}', $url, $tpl) : rtrim($tpl) . "\n" . $url;
    return trim($out);
}
