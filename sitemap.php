<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');

// One entry per page (the same URL serves both languages).
// Only pages with real content are listed. Thin pages stay reachable on the site (noindex, follow)
// but are not submitted, so Google does not spend crawl budget on empty placeholders.
$paths = [];
$imageOf = [];
$langsOf = static fn(array $row): array => array_values(array_filter(['bn'], static fn($l) => entity_body_ready($row, $l)));
try {
    $listCounts = [
        '/specialties' => 'specialties', '/services' => 'services', '/conditions' => 'conditions',
        '/treatments' => 'treatments', '/procedures' => 'procedures',
    ];
    $listCounts['/videos'] = 'videos';
    foreach (['/', '/about', '/specialties', '/services', '/conditions', '/treatments', '/procedures', '/blog', '/testimonials', '/faqs', '/videos', '/appointment', '/contact', '/privacy-policy', '/terms', '/gynecologist-khulna'] as $p) {
        if (isset($listCounts[$p]) && (int) q_val('SELECT COUNT(*) FROM ' . entity($listCounts[$p])['table'] . ' WHERE is_active = 1') === 0) {
            continue; // empty category page: noindex on the site, not submitted
        }
        $paths[] = [$p, null, ['bn']];
    }
    if ((int) q_val('SELECT COUNT(*) FROM gallery_items WHERE is_active = 1') > 0) { $paths[] = ['/gallery', null, ['bn']]; }
    foreach (['specialties', 'services', 'conditions', 'treatments', 'procedures'] as $k) {
        foreach (q_all('SELECT * FROM ' . entity($k)['table'] . ' WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
            $langs = $langsOf($r);
            if ($langs) { $paths[] = [entity_url($k, $r['slug']), $r['updated_at'], $langs]; }
        }
    }
    foreach (q_all('SELECT slug, updated_at FROM pages WHERE is_active = 1 ORDER BY menu_order, id') as $r) {
        $paths[] = ['/page/' . $r['slug'], $r['updated_at'], ['bn']];
    }
    foreach (q_all("SELECT slug, title, cover_image, updated_at, published_at FROM blog_posts WHERE status='published' AND robots='index' AND published_at <= ? ORDER BY published_at DESC", [date('Y-m-d H:i:s')]) as $r) {
        $paths[] = ['/blog/' . $r['slug'], $r['updated_at'] ?: $r['published_at'], ['bn']];
        // Image sitemap: the cover photo of each listed post (placeholder and empty covers are skipped).
        $src = img_url((string) $r['cover_image']);
        if ($src !== '/assets/img/placeholder.svg') {
            $alt = img_alt_for($src);
            $imageOf['/blog/' . $r['slug']] = ['loc' => $src, 'caption' => $alt !== '' ? $alt : (string) $r['title']];
        }
    }
} catch (Throwable $e) {
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
foreach ($paths as [$path, $mod]) {
    $loc = abs_url(lang_path($path));
    $lastmod = $mod ? '<lastmod>' . date('c', (int) strtotime((string) $mod)) . '</lastmod>' : '';
    $img = '';
    if (isset($imageOf[$path])) {
        $img = '<image:image><image:loc>' . htmlspecialchars(abs_url($imageOf[$path]['loc']), ENT_XML1) . '</image:loc><image:caption>'
            . htmlspecialchars(mb_substr($imageOf[$path]['caption'], 0, 300), ENT_XML1) . '</image:caption></image:image>';
    }
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . $lastmod . $img . "</url>\n";
}
echo '</urlset>';
