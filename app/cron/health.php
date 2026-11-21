<?php
declare(strict_types=1);
// Hosting health check from the command line (cPanel Terminal or SSH):
//   /usr/local/bin/php /home/CPUSER/public_html/app/cron/health.php
// Prints every check; exit code 1 when a required check fails.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';
$rows = system_checks(true);
$bad = 0;
foreach ($rows as $r) {
    $tag = $r['ok'] === true ? '[ OK ]' : ($r['ok'] === false ? '[FAIL]' : '[INFO]');
    if ($r['ok'] === false) {
        $bad++;
    }
    echo $tag . ' ' . $r['label'] . ' - ' . $r['detail'] . "\n";
}
echo "\n" . ($bad === 0 ? 'সব প্রয়োজনীয় চেক ঠিক আছে।' : $bad . 'টি চেক ব্যর্থ। উপরের FAIL লাইনগুলো দেখুন।') . "\n";
exit($bad === 0 ? 0 : 1);
