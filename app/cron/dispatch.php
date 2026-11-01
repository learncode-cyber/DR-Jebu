<?php
declare(strict_types=1);
// Retry queued/failed lead notifications. Run from cPanel > Cron Jobs every 5-10 minutes:
//   */10 * * * *  /usr/local/bin/php /home/CPUSER/jebu_medical/app/cron/dispatch.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';
$n = notifications_dispatch(50);
echo date('c') . " dispatched: $n\n";
