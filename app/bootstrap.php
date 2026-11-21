<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('APP_BOOTED', true);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Dhaka');

$__cfg = __DIR__ . '/config.php';
define('APP_CONFIGURED', is_file($__cfg));
if (APP_CONFIGURED) {
    require $__cfg;
}
foreach ([
    'DB_HOST' => 'localhost', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => '',
    'APP_KEY' => 'unset-app-key', 'SITE_URL' => '', 'ADMIN_PATH' => 'jebu-cp-3hr3f', 'APP_DEBUG' => false,
    'NOTIFY_EMAIL_TO' => '', 'MAIL_FROM' => '', 'LEAD_WEBHOOK_URL' => '', 'LEAD_WEBHOOK_SECRET' => '',
    'INDEXNOW_KEY' => '', 'TELEGRAM_BOT_TOKEN' => '', 'TELEGRAM_CHAT_ID' => '',
    'APP_ENV' => 'production', // set 'staging' or 'development' in config.php to add noindex everywhere
] as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

$__docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
define('WEB_ROOT', rtrim($__docroot !== '' ? $__docroot : dirname(__DIR__), '/')); // app/ sits inside the public folder

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// --- Tech-stack hygiene: no framework/generator fingerprints in responses ---
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/entities.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/tracking.php';
// Browser test mode: sets the admin's test cookie before any output is sent (only when ?jebu_tt= is present).
if (isset($_GET['jebu_tt']) && defined('APP_CONFIGURED') && APP_CONFIGURED) {
    try { tracking_test_active(); } catch (Throwable $e) { error_log('[jebu] test mode: ' . $e->getMessage()); }
}
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/fb_webhook.php';
require_once __DIR__ . '/fb_reply.php';
require_once __DIR__ . '/fb_comments.php';
require_once __DIR__ . '/appointments.php';
require_once __DIR__ . '/fb_posts.php';
require_once __DIR__ . '/gemini_router.php';
require_once __DIR__ . '/fb_sources.php';
require_once __DIR__ . '/fb_queue.php';
require_once __DIR__ . '/fb_config.php';
require_once __DIR__ . '/medical_sources.php';
require_once __DIR__ . '/seo_keywords.php';
require_once __DIR__ . '/linkedin.php';
require_once __DIR__ . '/tiktok.php';
require_once __DIR__ . '/blog_drafts.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/richtext.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/views/layout.php';
require_once __DIR__ . '/views/entity_pages.php';
require_once __DIR__ . '/views/lead_form.php';
require_once __DIR__ . '/views/error_page.php';

// Language switch: ?lang=en|bn (and the old /en/ links, which arrive as ?__l=en).
// The choice is stored in a cookie and the visitor is sent back to the same page, so the URL never changes.
$__langReq = (string) ($_GET['lang'] ?? ($_GET['__l'] ?? ''));
if (PHP_SAPI !== 'cli' && in_array($__langReq, ['en', 'bn'], true)) {
    setcookie('jlang', $__langReq, ['expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false, 'samesite' => 'Lax']);
    $__rest = $_GET;
    unset($__rest['lang'], $__rest['__l']);
    $__qs = http_build_query($__rest);
    header('Location: ' . lang_base_path() . ($__qs !== '' ? '?' . $__qs : ''), true, 302);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[jebu] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    system_alert('error', 'exception', $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (APP_DEBUG) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
        echo '<pre style="white-space:pre-wrap">' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
        exit;
    }
    render_error(500);
});

// Fatal errors (not exceptions) also get the friendly 500 page
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && PHP_SAPI !== 'cli') {
        error_log('[jebu] fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        system_alert('critical', 'fatal', $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
        render_error_standalone(500);
    }
});
