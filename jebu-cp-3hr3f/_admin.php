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

function admin_user(): ?array {
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $row = q_one('SELECT id, name, email, role, is_active FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
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

function admin_head(string $title): void {
    $u = admin_user();
    header('Content-Type: text/html; charset=utf-8');
    $nav = [];
    if ($u) {
        $nav[] = ['', 'ড্যাশবোর্ড', true];
        $nav[] = ['leads', 'লিডস / সিরিয়াল', can('leads.view')];
        foreach (entities() as $k => $e) {
            $nav[] = ['entity?e=' . $k, $e['label'], can('content')];
        }
        $nav[] = ['settings', 'সাইট সেটিংস', can('settings')];
        $nav[] = ['users', 'ইউজার', can('users')];
        $nav[] = ['logs', 'লগ', can('logs')];
        $nav[] = ['seo', 'SEO স্বাস্থ্য', can('settings')];
        $nav[] = ['account', 'আমার অ্যাকাউন্ট', true];
    }
    $cur = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    ?>
<!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= h($title) ?> — অ্যাডমিন</title>
<link rel="stylesheet" href="<?= h(asset('assets/css/admin.css')) ?>">
</head><body>
<?php if ($u): ?>
<div class="shell">
  <aside class="side">
    <div class="logo"><span>✚</span> <?= h(setting('doctor_name_bn')) ?></div>
    <nav>
<?php foreach ($nav as [$href, $label, $show]): if (!$show) { continue; }
    $full = ADMIN_PATH_URL() . ($href !== '' ? '/' . $href : '');
    $active = $href === '' ? ($cur === ADMIN_PATH_URL() || $cur === ADMIN_PATH_URL() . '/index') : (str_contains($href, '?') ? ($cur . '?' . ($_SERVER['QUERY_STRING'] ?? '')) === $full || (str_starts_with($cur, ADMIN_PATH_URL() . '/entity') && (($_GET['e'] ?? '') === substr($href, strpos($href, '=') + 1))) : str_starts_with($cur, ADMIN_PATH_URL() . '/' . $href)); ?>
      <a href="<?= h('/' . $full) ?>" class="<?= $active ? 'on' : '' ?>"><?= h($label) ?></a>
<?php endforeach; ?>
    </nav>
    <div class="who"><?= h($u['name']) ?><br><small><?= h(ROLE_LABELS[$u['role']] ?? $u['role']) ?></small>
      <form method="post" action="<?= h(a_url('logout')) ?>"><?= csrf_field() ?><button class="btn-link" type="submit">লগআউট</button></form>
      <a class="btn-link" href="/" target="_blank" rel="noopener">সাইট দেখুন ↗</a></div>
  </aside>
  <main class="main">
<?php else: ?>
<div class="auth-wrap">
<?php endif; ?>
<?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?><div class="flash <?= h($t) ?>"><?= h($m) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<h1 class="pt"><?= h($title) ?></h1>
<?php
}
function admin_foot(): void {
    echo admin_user() ? '</main></div>' : '</div>';
    echo '</body></html>';
}
