<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');

function admin_boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('jsid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    // idle timeout: 2 hours
    if (isset($_SESSION['last']) && time() - (int) $_SESSION['last'] > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last'] = time();
}
admin_boot_session();

const ROLE_PERMS = [
    'admin'     => ['*'],
    'manager'   => ['leads.view', 'leads.edit', 'content', 'logs'],
    'editor'    => ['content'],
    'doctor'    => ['leads.view', 'leads.edit'],
    'reception' => ['leads.view', 'leads.edit'],
];
const ROLE_LABELS = ['admin' => 'অ্যাডমিন', 'manager' => 'ম্যানেজার', 'editor' => 'এডিটর', 'doctor' => 'চিকিৎসক', 'reception' => 'রিসিপশন'];

function admin_ensure_avatar_column(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $has = (int) q_val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'");
        if ($has === 0) { q_exec('ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL DEFAULT NULL'); }
    } catch (Throwable $ex) {
        error_log('[jebu] avatar column: ' . $ex->getMessage());
    }
}
function admin_user(): ?array {
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (!empty($_SESSION['uid'])) {
        admin_ensure_avatar_column();
        $row = q_one('SELECT id, name, email, role, is_active, avatar FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
        if ($row && (int) $row['is_active'] === 1) {
            $u = $row;
        } else {
            unset($_SESSION['uid']);
        }
    }
    return $u;
}
function can(string $perm): bool {
    $u = admin_user();
    if (!$u) {
        return false;
    }
    $p = ROLE_PERMS[$u['role']] ?? [];
    return in_array('*', $p, true) || in_array($perm, $p, true);
}
function require_login(): array {
    $u = admin_user();
    if (!$u) {
        redirect('/' . ADMIN_PATH_URL() . '/login');
    }
    return $u;
}
function ADMIN_PATH_URL(): string { return trim(ADMIN_PATH, '/'); }
function a_url(string $page, array $q = []): string {
    return '/' . ADMIN_PATH_URL() . '/' . $page . ($q ? '?' . http_build_query($q) : '');
}
function require_perm(string $perm): array {
    $u = require_login();
    if (!can($perm)) {
        http_response_code(403);
        admin_head('অনুমতি নেই');
        echo '<div class="panel"><h2>এই পেজে আপনার অনুমতি নেই</h2><p><a href="' . h(a_url('')) . '">ড্যাশবোর্ডে ফিরুন</a></p></div>';
        admin_foot();
        exit;
    }
    return $u;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function csrf_require(): void {
    $t = (string) ($_POST['_csrf'] ?? '');
    if ($t === '' || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('অনুরোধটি যাচাই করা যায়নি (CSRF)। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
    }
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }

/** Sidebar: top-level links, then categories. Content items carry a "+ create" target as [4],[5]. */
function admin_nav_groups(): array {
    $icons = [
        'specialties' => 'fa-stethoscope', 'services' => 'fa-kit-medical', 'conditions' => 'fa-virus',
        'treatments' => 'fa-pills', 'procedures' => 'fa-syringe', 'blog' => 'fa-blog', 'pages' => 'fa-file-lines',
        'testimonials' => 'fa-star', 'faqs' => 'fa-circle-question', 'chambers' => 'fa-hospital',
        'gallery' => 'fa-images', 'videos' => 'fa-video',
    ];
    $content = [];
    foreach (entities() as $k => $e) {
        $content[] = ['entity?e=' . $k, $e['label'], can('content'), $icons[$k] ?? 'fa-folder',
            'entity?e=' . $k . '&a=edit', 'নতুন ' . $e['singular']];
    }
    $system = [
        ['media', 'মিডিয়া লাইব্রেরি', can('content'), 'fa-photo-film'],
        ['seo-content', 'কনটেন্ট SEO চেকার', can('content'), 'fa-spell-check'],
        ['settings', 'সাইট সেটিংস', can('settings'), 'fa-gear'],
        ['users', 'ইউজার', can('users'), 'fa-users-gear'],
        ['logs', 'লগ', can('logs'), 'fa-clipboard-list'],
        ['seo', 'SEO স্বাস্থ্য', can('settings'), 'fa-chart-line'],
        ['account', 'পাসওয়ার্ড বদলান', true, 'fa-key'],
    ];
    return [
        ['', '', [
            ['', 'ড্যাশবোর্ড', true, 'fa-chart-pie'],
            ['leads', 'লিডস / সিরিয়াল', can('leads.view'), 'fa-calendar-check'],
        ]],
        ['কনটেন্ট', 'fa-layer-group', $content],
        ['সিস্টেম ও এসইও', 'fa-screwdriver-wrench', $system],
    ];
}

/** Whether a sidebar item matches the current admin page. */
function admin_nav_active(string $href): bool {
    $cur = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    $base = ADMIN_PATH_URL();
    if ($href === '') {
        return $cur === $base || $cur === $base . '/index';
    }
    if (str_starts_with($href, 'entity?e=')) {
        parse_str(substr($href, 7), $q); // "KEY" or "KEY&a=edit"
        $sameEntity = str_starts_with($cur, $base . '/entity') && ($_GET['e'] ?? '') === ($q['e'] ?? '');
        if (!$sameEntity) { return false; }
        $isEdit = ($_GET['a'] ?? '') === 'edit';
        return isset($q['a']) ? ($isEdit && empty($_GET['id'])) : !$isEdit;
    }
    return $cur === $base . '/' . $href || str_starts_with($cur, $base . '/' . $href . '/');
}

function admin_head(string $title): void {
    $u = admin_user();
    header('Content-Type: text/html; charset=utf-8');
    $cur = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    ?>
<!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= h($title) ?> — অ্যাডমিন</title>
<?php if ($u): ?><meta name="csrf-token" content="<?= h(csrf_token()) ?>"><?php endif; ?>
<script src="https://cdn.tailwindcss.com"></script>
<script>
if (window.tailwind) { tailwind.config = {
  corePlugins: { preflight: false },
  theme: { extend: {
    colors: {
      maroon: { 50:'rgb(var(--m-50) / <alpha-value>)',100:'rgb(var(--m-100) / <alpha-value>)',200:'rgb(var(--m-200) / <alpha-value>)',300:'rgb(var(--m-300) / <alpha-value>)',400:'rgb(var(--m-400) / <alpha-value>)',500:'rgb(var(--m-500) / <alpha-value>)',600:'rgb(var(--m-600) / <alpha-value>)',700:'rgb(var(--m-700) / <alpha-value>)',800:'rgb(var(--m-800) / <alpha-value>)',900:'rgb(var(--m-900) / <alpha-value>)',950:'rgb(var(--m-950) / <alpha-value>)' },
      gold: { 400:'#fbbf24', 500:'#f59e0b', 600:'#d97706' }
    },
    fontFamily: { sans: ['"Hind Siliguri"', 'system-ui', 'sans-serif'] }
  } }
}; }
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap">
<script>try{var __t=localStorage.getItem('adm-theme');if(__t){document.documentElement.setAttribute('data-theme',__t);}}catch(e){}</script>
<link rel="stylesheet" href="<?= h(asset('assets/css/admin.css')) ?>">
<script src="<?= h(asset('assets/js/admin.js')) ?>" defer></script>
</head><body>
<?php if ($u): ?>
<div class="min-h-screen flex">
  <div id="adm-overlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden backdrop-blur-sm" data-adm="close-nav"></div>
  <div class="adm-rail">
  <aside id="adm-side" class="fixed md:absolute inset-y-0 left-0 z-50 bg-gradient-to-b from-maroon-900 to-maroon-950 text-maroon-100 flex flex-col shadow-2xl -translate-x-full md:translate-x-0 transition-transform duration-300 overflow-hidden">
    <div class="adm-top">
      <button type="button" class="adm-burger" data-adm="pin-side" aria-expanded="false" aria-controls="adm-side" aria-label="মেনু খুলুন বা বন্ধ করুন" title="মেনু"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
      <button type="button" class="ml-auto md:hidden bg-transparent border-0 text-maroon-300 hover:text-white p-1" data-adm="close-nav" aria-label="মেনু বন্ধ করুন"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="adm-brand">
      <div class="adm-logo"><i class="fa-solid fa-user-doctor"></i></div>
      <div class="min-w-0 adm-lbl">
        <div class="adm-name"><?= h(setting('doctor_name_bn')) ?></div>
        <div class="adm-sub">অ্যাডমিন প্যানেল</div>
      </div>
    </div>
    <nav class="adm-nav" aria-label="মূল মেনু">
<?php foreach (admin_nav_groups() as [$gLabel, $gIcon, $items]):
    $visible = array_values(array_filter($items, static fn($it) => $it[2]));
    if (!$visible) { continue; }
    if ($gLabel === '') {
        foreach ($visible as $it):
            $on = admin_nav_active($it[0]); ?>
      <a href="<?= h('/' . ADMIN_PATH_URL() . ($it[0] !== '' ? '/' . $it[0] : '')) ?>" title="<?= h($it[1]) ?>" class="adm-item<?= $on ? ' on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><i class="fa-solid <?= h($it[3]) ?> adm-ic"></i><span class="adm-lbl"><?= h($it[1]) ?></span></a>
<?php   endforeach;
        continue;
    }
    $anyActive = false;
    foreach ($visible as $it) { $anyActive = $anyActive || admin_nav_active($it[0]); }
    $cid = 'c' . substr(md5($gLabel), 0, 8); ?>
      <div class="adm-cat<?= $anyActive ? ' open' : '' ?>">
        <button type="button" class="adm-cat-btn" data-adm="toggle-cat" aria-expanded="<?= $anyActive ? 'true' : 'false' ?>" aria-controls="<?= h($cid) ?>" title="<?= h($gLabel) ?>"><i class="fa-solid <?= h($gIcon) ?> adm-ic"></i><span class="adm-lbl"><?= h($gLabel) ?></span><i class="fa-solid fa-chevron-down adm-chev adm-lbl" aria-hidden="true"></i></button>
        <div id="<?= h($cid) ?>" class="adm-tree">
<?php foreach ($visible as $it):
    $on = admin_nav_active($it[0]); ?>
          <div class="adm-row">
            <a href="<?= h('/' . ADMIN_PATH_URL() . '/' . $it[0]) ?>" title="<?= h($it[1]) ?>" class="adm-item<?= $on ? ' on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><i class="fa-solid <?= h($it[3]) ?> adm-ic" style="font-size:12px"></i><span class="adm-lbl"><?= h($it[1]) ?></span></a>
<?php if (!empty($it[4])): ?>
            <a href="<?= h('/' . ADMIN_PATH_URL() . '/' . $it[4]) ?>" class="adm-add" title="<?= h($it[5]) ?>" aria-label="<?= h($it[5]) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i></a>
<?php endif; ?>
          </div>
<?php endforeach; ?>
        </div>
      </div>
<?php endforeach; ?>
    </nav>
    <div class="adm-foot">
      <a class="adm-me" href="<?= h(a_url('profile')) ?>" title="আমার প্রোফাইল">
<?php if (!empty($u['avatar'])): ?>
        <img class="adm-avatar" src="<?= h(img_url((string) $u['avatar'])) ?>" alt="">
<?php else: ?>
        <span class="adm-avatar"><?= h(mb_strtoupper(mb_substr((string) $u['name'], 0, 2))) ?></span>
<?php endif; ?>
        <span class="min-w-0 adm-lbl">
          <span class="block text-xs font-semibold text-white truncate"><?= h($u['name']) ?></span>
          <span class="block text-[10px] text-maroon-300"><?= h(ROLE_LABELS[$u['role']] ?? $u['role']) ?></span>
        </span>
      </a>
      <form method="post" action="<?= h(a_url('logout')) ?>" class="shrink-0">
        <?= csrf_field() ?>
        <button type="submit" class="adm-logout" title="লগআউট" aria-label="লগআউট"><i class="fa-solid fa-power-off text-sm"></i></button>
      </form>
    </div>
  </aside>
  </div>

  <div class="flex-1 min-w-0 flex flex-col min-h-screen">
    <header class="sticky top-0 z-30 h-16 bg-white/85 backdrop-blur-md border-b border-maroon-200 flex items-center justify-between px-4 md:px-8">
      <div class="flex items-center gap-3 min-w-0">
        <button type="button" class="bg-transparent border-0 md:hidden text-maroon-700 hover:text-maroon-900 text-lg p-1" data-adm="open-nav" aria-label="মেনু খুলুন"><i class="fa-solid fa-bars"></i></button>
        <span class="text-base font-bold text-maroon-900 truncate"><?= h($title) ?></span>
      </div>
      <div class="flex items-center gap-2">
        <div class="adm-themes" role="group" aria-label="থিম বাছাই">
          <button type="button" class="adm-sw" data-adm="theme" data-theme-name="maroon" style="background:#832839;color:#832839" aria-label="ম্যারন থিম" title="ম্যারন" aria-pressed="false"></button>
          <button type="button" class="adm-sw" data-adm="theme" data-theme-name="ocean" style="background:#1d4ed8;color:#1d4ed8" aria-label="ওশন থিম" title="ওশন" aria-pressed="false"></button>
          <button type="button" class="adm-sw" data-adm="theme" data-theme-name="emerald" style="background:#047857;color:#047857" aria-label="এমেরাল্ড থিম" title="এমেরাল্ড" aria-pressed="false"></button>
        </div>
<?php if (can('leads.view')): ?>
        <button type="button" data-adm="open-ai" aria-controls="ai-drawer" class="border-0 flex items-center gap-2 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold shadow-md shadow-amber-500/20 active:scale-95 transition">
          <i class="fa-solid fa-wand-magic-sparkles"></i><span class="hidden sm:inline">ডাঃ এআই প্যানেল</span>
        </button>
<?php endif; ?>
        <a href="/" target="_blank" rel="noopener" class="hidden md:inline-flex items-center gap-1.5 text-xs font-semibold text-maroon-700 bg-maroon-100 hover:bg-maroon-200 px-3 py-2 rounded-xl transition"><i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>সাইট দেখুন</a>
      </div>
    </header>
    <main class="flex-1 min-w-0 p-4 md:p-8">
<?php else: ?>
<div class="auth-wrap">
<?php endif; ?>
<?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?><div class="flash <?= h($t) ?>"><?= h($m) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<h1 class="pt"><?= h($title) ?></h1>
<?php
}

/** Right-side AI slide-over. Clinician-only; answers are decision support and are never saved to a record. */
function admin_ai_drawer(): void {
    $enabled = function_exists('ai_enabled') && ai_enabled();
    ?>
<div id="ai-overlay" class="fixed inset-0 bg-black/40 z-50 hidden backdrop-blur-sm" data-adm="close-ai"></div>
<aside id="ai-drawer" data-url="<?= h(a_url('ai-assist')) ?>" role="dialog" aria-modal="true" aria-labelledby="ai-title" aria-hidden="true"
  class="fixed inset-y-0 right-0 z-[60] w-full sm:w-[26rem] bg-white shadow-2xl flex flex-col translate-x-full transition-transform duration-300">
  <div class="p-4 bg-maroon-900 text-white flex items-center justify-between border-b border-maroon-800">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-amber-500 to-gold-400 flex items-center justify-center shadow-lg"><i class="fa-solid fa-wand-magic-sparkles text-sm"></i></div>
      <div>
        <h2 id="ai-title" class="font-bold text-sm">ডাঃ এআই প্যানেল</h2>
        <p class="text-[10px] text-amber-300">ক্লিনিক্যাল সিদ্ধান্ত-সহায়ক</p>
      </div>
    </div>
    <button type="button" data-adm="close-ai" class="bg-transparent border-0 text-maroon-200 hover:text-white p-1.5 rounded-lg" aria-label="বন্ধ করুন"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="px-4 py-2 bg-amber-50 border-b border-amber-200 text-[11px] text-amber-900 leading-relaxed">
    এআই-এর উত্তর শুধু সহায়ক। রোগীর নাম, ফোন বা পরিচয়সূচক তথ্য লিখবেন না। চিকিৎসকের যাচাই ছাড়া কোনো রোগ নির্ণয় বা ওষুধে ব্যবহার করবেন না।
  </div>
<?php if (!$enabled): ?>
  <div class="px-4 py-2 bg-rose-50 border-b border-rose-200 text-[11px] text-rose-800">এআই এখনও চালু নেই। সাইট সেটিংসে Gemini API Key দিলে এটি কাজ করবে।</div>
<?php endif; ?>

  <div class="flex gap-1.5 p-2 border-b border-maroon-200 bg-maroon-50/60" role="tablist">
    <button type="button" role="tab" class="border-0" data-ai-tab="chat" aria-selected="true" class="flex-1 py-1.5 text-xs font-bold rounded-lg bg-maroon-800 text-white">চ্যাট</button>
    <button type="button" role="tab" class="border-0" data-ai-tab="triage" aria-selected="false" class="flex-1 py-1.5 text-xs font-bold rounded-lg text-maroon-700 hover:bg-maroon-200/60">ট্রায়াজ</button>
    <button type="button" role="tab" class="border-0" data-ai-tab="summary" aria-selected="false" class="flex-1 py-1.5 text-xs font-bold rounded-lg text-maroon-700 hover:bg-maroon-200/60">সামারি</button>
  </div>

  <div class="flex-1 overflow-y-auto p-4 space-y-4">
    <section data-ai-panel="chat" class="space-y-3">
      <div id="ai-chat-log" class="space-y-3 min-h-[8rem]" aria-live="polite">
        <div class="flex justify-start"><div class="bg-maroon-100 text-maroon-900 p-3 rounded-2xl text-xs max-w-[85%] leading-relaxed">নমস্কার ডাক্তার। ক্লিনিক্যাল যেকোনো প্রশ্ন করুন। রোগীর নাম বা ফোন নম্বর লিখবেন না।</div></div>
      </div>
      <button type="button" class="border-0" data-ai-action="clear-chat" class="text-[11px] text-maroon-600 hover:text-maroon-900 underline">কথোপকথন মুছুন</button>
    </section>

    <section data-ai-panel="triage" class="hidden space-y-2">
      <label class="block text-xs font-bold text-maroon-900" for="ai-triage-input">লক্ষণ ও তথ্য</label>
      <textarea id="ai-triage-input" rows="5" maxlength="4000" class="w-full p-2.5 rounded-lg border border-maroon-300 text-xs focus:ring-2 focus:ring-amber-500 outline-none" placeholder="বয়স, গর্ভাবস্থার সপ্তাহ, লক্ষণ, কতদিন ধরে, ভাইটাল (নাম ও ফোন বাদ দিন)"></textarea>
      <button type="button" class="border-0" data-ai-action="triage" class="w-full bg-maroon-800 hover:bg-maroon-700 text-white py-2 rounded-lg text-xs font-bold">ট্রায়াজ বিশ্লেষণ করুন</button>
    </section>

    <section data-ai-panel="summary" class="hidden space-y-2">
      <label class="block text-xs font-bold text-maroon-900" for="ai-summary-input">ক্লিনিক্যাল নোট</label>
      <textarea id="ai-summary-input" rows="5" maxlength="4000" class="w-full p-2.5 rounded-lg border border-maroon-300 text-xs focus:ring-2 focus:ring-amber-500 outline-none" placeholder="ভিজিটের নোট লিখুন (নাম ও ফোন বাদ দিন)"></textarea>
      <button type="button" class="border-0" data-ai-action="summary" class="w-full bg-maroon-800 hover:bg-maroon-700 text-white py-2 rounded-lg text-xs font-bold">সামারি তৈরি করুন</button>
    </section>

    <div id="ai-output" class="hidden p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-xs text-maroon-900" aria-live="polite">
      <div class="font-bold text-amber-700 mb-1" id="ai-output-title">এআই আউটপুট</div>
      <div id="ai-output-body" class="whitespace-pre-wrap leading-relaxed"></div>
    </div>
  </div>

  <div class="p-3 border-t border-maroon-200 bg-maroon-50/60" data-ai-panel-footer="chat">
    <form id="ai-chat-form" class="flex gap-2">
      <label for="ai-chat-input" class="sr-only">এআইকে প্রশ্ন</label>
      <input id="ai-chat-input" type="text" maxlength="4000" autocomplete="off" class="flex-1 p-2.5 rounded-xl border border-maroon-300 text-xs focus:ring-2 focus:ring-amber-500 outline-none" placeholder="চিকিৎসা-সংক্রান্ত প্রশ্ন লিখুন...">
      <button type="submit" class="border-0 bg-amber-500 hover:bg-amber-600 text-white px-3.5 rounded-xl text-xs font-bold" aria-label="পাঠান"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
  </div>
</aside>
<?php
}

function admin_foot(): void {
    $u = admin_user();
    if ($u) {
        echo '</main></div></div>';
        if (can('leads.view')) {
            admin_ai_drawer();
        }
    } else {
        echo '</div>';
    }
    echo '</body></html>';
}
