<?php
// Copy this file to app/config.php and fill in real values.
// app/config.php is never overwritten by uploads of this package.

define('DB_HOST', 'localhost');
define('DB_NAME', 'cpuser_jebu_medical_db');
define('DB_USER', 'cpuser_jebu');
define('DB_PASSWORD', 'change_me');
// define('DB_DSN', 'mysql:host=localhost;dbname=...;charset=utf8mb4'); // optional full DSN override

// Long random string (40+ chars). Used to sign form tokens and webhooks.
define('APP_KEY', 'change-me-to-a-long-random-string');

define('SITE_URL', 'https://drjebunnesa.online');   // no trailing slash
define('ADMIN_PATH', 'jebu-cp-3hr3f');               // folder name of the admin area (informational)
define('APP_DEBUG', false);                          // true = show errors (never on production)

// ---- Lead automation ----
define('NOTIFY_EMAIL_TO', '');       // clinic inbox that receives every new lead ('' = skip)
define('MAIL_FROM', '');             // e.g. no-reply@drjebunnesa.online (must be a mailbox on your domain)
define('LEAD_WEBHOOK_URL', '');      // optional: n8n / Make / Zapier / own CRM endpoint ('' = skip)
define('LEAD_WEBHOOK_SECRET', '');   // HMAC-SHA256 signing secret for the webhook ('' = unsigned)

// ---- Telegram: every new appointment/message goes straight to your chat or channel ----
// 1) Telegram-এ @BotFather → /newbot → token নিন (যেমন 123456:ABC...)
// 2) ওই বট-কে আপনার গ্রুপ/চ্যানেলে অ্যাডমিন করুন
// 3) chat id বের করতে: https://api.telegram.org/bot<TOKEN>/getUpdates
define('TELEGRAM_BOT_TOKEN', '');    // '' = Telegram off
define('TELEGRAM_CHAT_ID', '');      // গ্রুপ/চ্যানেলের id (চ্যানেলে -100… দিয়ে শুরু)

// ---- Search engine ping (IndexNow) ----
define('INDEXNOW_KEY', 'cbe117c7ba4b86a6bfb2eb1707033059');
