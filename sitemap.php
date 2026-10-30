<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
$urls = [];
foreach (['/', '/about', '/specialties', '/services', '/conditions', '/treatments', '/procedures', '/blog', '/testimonials', '/faqs', '/videos', '/appointment', '/contact', '/privacy-policy', '/terms'] as $p) {
    $urls[] = [abs_url($p), null];
}
try {
    if ((int) q_val('SELECT COUNT(*) FROM gallery_items WHERE is_active = 1') > 0) { $urls[] = [abs_url('/gallery'), null]; }
    foreach (['specialties', 'services', 'conditions', 'treatments', 'procedures'] as $k) {
        foreach (q_all('SELECT slug, updated_at FROM ' . entity($k)['table'] . ' WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
            $urls[] = [abs_url(entity_url($k, $r['slug'])), $r['updated_at']];
        }
    }
    foreach (q_all('SELECT slug, updated_at FROM pages WHERE is_active = 1 ORDER BY menu_order, id') as $r) {
        $urls[] = [abs_url('/page/' . $r['slug']), $r['updated_at']];
    }
    foreach (q_all("SELECT slug, updated_at, published_at FROM blog_posts WHERE status='published' AND robots='index' AND published_at <= ? ORDER BY published_at DESC", [date('Y-m-d H:i:s')]) as $r) {
        $urls[] = [abs_url('/blog/' . $r['slug']), $r['updated_at'] ?: $r['published_at']];
    }
} catch (Throwable $e) {
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $mod]) {
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . date('c', (int) strtotime((string) $mod)) . '</lastmod>' : '') . "</url>\n";
}
echo '</urlset>';
