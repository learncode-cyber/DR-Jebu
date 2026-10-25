<?php
declare(strict_types=1);
// Deployment gate: php app/cron/seo-check.php [https://your-domain]
// Exit code 1 = critical problem, do not go live until fixed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

$base = rtrim((string) ($argv[1] ?? SITE_URL), '/');
$failed = [];
$line = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $msg . "\n";
    if (!$ok) { $failed[] = $msg; }
};

// 1) Configuration + content checks
$audit = seo_audit();
foreach ($audit['critical'] as $m) { $line(false, $m); }
foreach ($audit['warn'] as $m) { echo "[WARN] $m\n"; }
foreach ($audit['ok'] as $m) { $line(true, $m); }

// 2) Live checks against the public site
$fetch = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'jebu-seo-check/1.0']);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
};
[$c, $home] = $fetch($base . '/');
$line($c === 200, "Home page HTTP $c");
$line(str_contains($home, '<link rel="canonical"'), 'Home page has canonical URL');
$line(!preg_match('/<meta name="robots" content="[^"]*noindex/i', $home), 'Home page is indexable (no noindex)');
$line(substr_count($home, 'type="application/ld+json"') >= 1, 'Home page has JSON-LD schema');

[$c, $robots] = $fetch($base . '/robots.txt');
$line($c === 200 && str_contains($robots, 'Sitemap:'), 'robots.txt reachable and lists sitemap');
$line(!preg_match('#^Disallow: /\s*$#m', $robots), 'robots.txt does not block the whole site');

[$c, $sitemap] = $fetch($base . '/sitemap.xml');
$count = substr_count($sitemap, '<loc>');
$line($c === 200 && $count > 0, "sitemap.xml reachable with $count URLs");

[$c] = $fetch($base . '/does-not-exist-' . bin2hex(random_bytes(4)));
$line($c === 404, "Unknown URL returns 404 (got $c)");

[$c2] = $fetch($base . '/.well-known/security.txt');
$line($c2 === 200, "security.txt reachable (got $c2)");

echo "\n" . (count($failed) ? count($failed) . " critical problem(s). Do not deploy." : "All critical checks passed.") . "\n";
exit(count($failed) ? 1 : 0);
