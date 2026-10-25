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
define('WEB_ROOT', rtrim($__docroot !== '' ? $__docroot : dirname(__DIR__) . '/public_html', '/'));

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
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/entities.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/views/layout.php';
require_once __DIR__ . '/views/entity_pages.php';
require_once __DIR__ . '/views/lead_form.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('[jebu] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = APP_CONFIGURED
        ? 'সাময়িক সমস্যা হয়েছে। অনুগ্রহ করে কিছুক্ষণ পর আবার চেষ্টা করুন।'
        : 'সাইটটি এখনও কনফিগার করা হয়নি। app/config.sample.php কপি করে app/config.php বানান এবং ডাটাবেস তথ্য দিন।';
    echo '<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>সাময়িক সমস্যা</title><body style="font-family:sans-serif;text-align:center;padding:4rem 1rem;color:#4a0f2e">'
       . '<h1>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</h1>'
       . (APP_DEBUG ? '<pre style="text-align:left;white-space:pre-wrap">' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>' : '')
       . '</body></html>';
    exit;
});
