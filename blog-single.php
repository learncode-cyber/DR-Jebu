<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$now = date('Y-m-d H:i:s');
$post = preg_match('/^[a-z0-9-]+$/', $slug)
    ? q_one("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' AND published_at <= ?", [$slug, $now])
    : null;
if (!$post && $slug !== '') {
    $new = q_val("SELECT bp.slug FROM old_slugs os JOIN blog_posts bp ON bp.id = os.post_id WHERE os.old_slug = ? AND bp.status = 'published'", [$slug]);
    if ($new) {
        redirect('/blog/' . $new, 301);
    }
}
if (!$post) {
    render_404();
}

$blocks = md_blocks((string) $post['body']);
$faq = [];
foreach ($blocks as $i => $b) {
    if (preg_match('/^\*\*(.+)\*\*$/s', $b, $m)) {
        $ans = $blocks[$i + 1] ?? '';
        if ($ans !== '' && $ans[0] !== '#' && !preg_match('/^\*\*(.+)\*\*$/s', $ans)) {
            $faq[] = ['q' => trim($m[1]), 'a' => trim_text($ans, 600)];
        }
    }
}
$author = $post['author_name'] !== '' ? $post['author_name'] : setting('doctor_name_bn');
$reviewer = $post['medically_reviewed_by'] !== '' ? $post['medically_reviewed_by'] : $author;
$tags = array_values(array_filter(array_map('trim', explode(',', (string) $post['tags']))));
$related = $post['category'] !== '' ? q_all("SELECT slug, title, cover_image FROM blog_posts WHERE status='published' AND published_at <= ? AND category = ? AND id <> ? ORDER BY published_at DESC LIMIT 3", [$now, $post['category'], $post['id']]) : [];
$path = '/blog/' . $post['slug'];
$title = $post['meta_title'] !== '' ? $post['meta_title'] : $post['title'];
$desc = $post['meta_description'] !== '' ? $post['meta_description'] : $post['excerpt'];
$schema = [[
    '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'], 'description' => $desc,
    'datePublished' => str_replace(' ', 'T', (string) $post['published_at']) . '+06:00', 'dateModified' => str_replace(' ', 'T', (string) ($post['updated_at'] ?: $post['published_at'])) . '+06:00',
    'mainEntityOfPage' => abs_url($path), 'image' => abs_url(img_url($post['cover_image'])),
    'author' => ['@type' => 'Person', 'name' => $author], 'reviewedBy' => ['@type' => 'Person', 'name' => $reviewer],
    'publisher' => ['@type' => 'Organization', 'name' => setting('doctor_name_bn')], 'inLanguage' => 'bn',
] + ($post['category'] !== '' ? ['articleSection' => $post['category']] : []) + ($tags ? ['keywords' => implode(', ', $tags)] : [])];
if ($faq) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faq)];
}
page_start([
    'title' => $title, 'description' => $desc, 'path' => $path, 'image' => $post['cover_image'], 'og_type' => 'article',
    'robots' => $post['robots'] === 'noindex' ? 'noindex, nofollow' : 'index, follow, max-image-preview:large',
    'breadcrumb' => [['ব্লগ', '/blog'], [$post['title'], null]], 'schema' => $schema,
]);
page_hero($post['category'] !== '' ? $post['category'] : 'Blog', $post['title'], '', [['ব্লগ', '/blog'], [trim_text($post['title'], 40), null]]);
?>
<section class="section"><div class="wrap layout-article">
  <article class="prose">
    <div class="cover"><img src="<?= h(img_url($post['cover_image'])) ?>" alt="<?= h($post['title']) ?>" width="1200" height="600"></div>
    <div class="meta">
      <span>লেখক: <?= h($author) ?></span>
      <span><?= h(bn_date($post['published_at'])) ?></span>
      <span><?= h(bn_num(reading_minutes((string) $post['body']))) ?> মিনিট পড়া</span>
<?php if ($reviewer !== ''): ?><span>মেডিকেলি রিভিউড: <?= h($reviewer) ?></span><?php endif; ?>
    </div>
    <?= md((string) $post['body']) ?>
<?php if ($tags): ?><p style="margin-top:2rem"><?php foreach ($tags as $t): ?><span class="chip">#<?= h($t) ?></span><?php endforeach; ?></p><?php endif; ?>
    <p class="note" style="margin-top:1.5rem"><?= h(setting('footer_disclaimer')) ?></p>
  </article>
  <aside class="aside-box">
    <div class="box">
      <h3>অ্যাপয়েন্টমেন্ট নিতে চান?</h3>
      <p><?= h(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn')) ?>-এর সিরিয়ালের জন্য আমাদের জানান।</p>
      <p><a class="btn btn-primary btn-block" href="/appointment">সিরিয়াল নিন</a></p>
      <p><a class="btn btn-outline btn-block" href="<?= h(wa_link('আসসালামু আলাইকুম, "' . $post['title'] . '" লেখাটি পড়ে অ্যাপয়েন্টমেন্ট নিতে চাই।')) ?>" target="_blank" rel="noopener" data-track="whatsapp_click">হোয়াটসঅ্যাপ</a></p>
    </div>
<?php if ($related): ?>
    <div class="box"><h3>সম্পর্কিত লেখা</h3><ul><?php foreach ($related as $r): ?><li><a href="/blog/<?= h($r['slug']) ?>"><?= h($r['title']) ?></a></li><?php endforeach; ?></ul></div>
<?php endif; ?>
  </aside>
</div></section>
<?php cta_band(); page_end();
