<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
// Served as /robots.txt through .htaccess. Production allows crawling and points to the sitemap;
// any staging/development copy blocks everything so it can never be indexed by accident.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
if (!defined('APP_ENV') || APP_ENV === 'production') {
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /jebu-cp-3hr3f/\n";
    echo "Disallow: /app/\n";
    echo "Disallow: /cron/\n";
    echo "Disallow: /database/\n\n";
    echo 'Sitemap: ' . abs_url('/sitemap.xml') . "\n";
} else {
    echo "User-agent: *\nDisallow: /\n";
}
