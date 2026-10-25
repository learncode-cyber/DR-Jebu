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

// ---- Search engine ping (IndexNow) ----
define('INDEXNOW_KEY', 'cbe117c7ba4b86a6bfb2eb1707033059');
