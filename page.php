<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

// /page/{slug} : owner-created static pages (published only)
$slug = trim((string) ($_GET['slug'] ?? ''));
$page = preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)
    ? q_one('SELECT * FROM pages WHERE slug = ? AND is_active = 1', [$slug])
    : null;
if (!$page) {
    render_404();
}

$title = $page['meta_title'] !== '' ? $page['meta_title'] : $page['title'];
$desc = $page['meta_description'] !== '' ? $page['meta_description'] : $page['short_desc'];
$path = '/page/' . $page['slug'];
$schema = [[
    '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $page['title'], 'url' => abs_url($path),
    'inLanguage' => 'bn', 'publisher' => ['@type' => 'Organization', 'name' => setting('doctor_name_bn')],
] + ($desc !== '' ? ['description' => $desc] : [])];
page_start([
    'title' => $title, 'description' => $desc, 'path' => $path, 'image' => $page['cover_image'],
    'breadcrumb' => [[$page['title'], null]], 'schema' => $schema,
]);
page_hero('', $page['title'], $page['short_desc'], [[$page['title'], null]]);
?>
<section class="section"><div class="wrap">
  <article class="prose cq-content">
<?php if ($page['cover_image'] !== ''): ?>
    <div class="cover"><img src="<?= h(img_url($page['cover_image'])) ?>" alt="<?= h($page['title']) ?>" width="1200" height="600" loading="lazy"></div>
<?php endif; ?>
    <?= rich_render((string) $page['body']) ?>
  </article>
</div></section>
<?php cta_band(); page_end();
