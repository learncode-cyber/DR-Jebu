<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$now = date('Y-m-d H:i:s');
$post = preg_match('/^[a-z0-9-]+$/', $slug)
    ? q_one("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' AND published_at <= ?", [$slug, $now])
    : null;
if (is_array($post)) { $post = localize_row($post); }
if (!$post && $slug !== '') {
    $new = q_val("SELECT bp.slug FROM old_slugs os JOIN blog_posts bp ON bp.id = os.post_id WHERE os.old_slug = ? AND bp.status = 'published'", [$slug]);
    if ($new) {
        redirect(lang_path('/blog/' . $new), 301);
    }
}
if (!$post) {
    render_404();
}

$isRich = str_starts_with((string) $post['body'], RICH_MARK);
$bodyHtml = blog_body_html((string) $post['body']);
$faq = [];
if ($isRich) {
    // questions written as headings or lines ending with "?", each followed by its answer (AEO)
    foreach (rich_faq_pairs($bodyHtml) as $pair) {
        $faq[] = ['q' => $pair['q'], 'a' => $pair['a']];
    }
} else {
    $blocks = md_blocks((string) $post['body']);
    foreach ($blocks as $i => $b) {
        if (preg_match('/^\*\*(.+)\*\*$/s', $b, $m)) {
            $ans = $blocks[$i + 1] ?? '';
            if ($ans !== '' && $ans[0] !== '#' && !preg_match('/^\*\*(.+)\*\*$/s', $ans)) {
                $faq[] = ['q' => trim($m[1]), 'a' => trim_text($ans, 600)];
            }
        }
    }
}
$author = $post['author_name'] !== '' ? $post['author_name'] : tr(setting('doctor_name_bn'), setting('doctor_name_en'));
if (site_lang() === 'en' && $author === 'ডাঃ জেবুন্নেসা জেবু') { $author = setting('doctor_name_en'); }
$reviewer = $post['medically_reviewed_by'] !== '' ? $post['medically_reviewed_by'] : $author;
if (site_lang() === 'en' && $reviewer === 'ডাঃ জেবুন্নেসা জেবু') { $reviewer = setting('doctor_name_en'); }
$tags = array_values(array_filter(array_map('trim', explode(',', (string) $post['tags']))));
$related = $post['category'] !== '' ? localize_rows(q_all("SELECT slug, title, title_en, cover_image FROM blog_posts WHERE status='published' AND published_at <= ? AND category = ? AND id <> ? ORDER BY published_at DESC LIMIT 3", [$now, $post['category'], $post['id']])) : [];
$path = '/blog/' . $post['slug'];
$title = $post['meta_title'] !== '' ? $post['meta_title'] : $post['title'];
$descFallback = trim_text(trim((string) preg_replace('/\s+/u', ' ', preg_replace('/[#*_`>\[\]]+/u', '', strip_tags((string) $post['body'])))), 155);
$desc = $post['meta_description'] !== '' ? $post['meta_description'] : ($post['excerpt'] !== '' ? $post['excerpt'] : $descFallback);
// Schema image must be a real photo: a blog post without a cover uses the doctor's portrait, never the placeholder graphic.
$schemaImg = img_url($post['cover_image']);
if ($schemaImg === '/assets/img/placeholder.svg') {
    $schemaImg = '/assets/img/doctor-portrait.jpg';
}
$schema = [[
    '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'], 'description' => $desc,
    'datePublished' => str_replace(' ', 'T', (string) $post['published_at']) . '+06:00', 'dateModified' => str_replace(' ', 'T', (string) ($post['updated_at'] ?: $post['published_at'])) . '+06:00',
    'mainEntityOfPage' => abs_url($path), 'image' => ['@type' => 'ImageObject', 'url' => abs_url($schemaImg), 'contentUrl' => abs_url($schemaImg), 'caption' => (img_alt_for($schemaImg) ?: $post['title'])],
    'author' => ['@type' => 'Person', 'name' => $author], 'reviewedBy' => ['@type' => 'Person', 'name' => $reviewer],
    'publisher' => ['@type' => 'Organization', 'name' => doctor_schema_name()], 'inLanguage' => site_lang(),
] + ($post['category'] !== '' ? ['articleSection' => $post['category']] : []) + ($tags ? ['keywords' => implode(', ', $tags)] : [])];
if ($faq) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faq)];
}
page_start([
    'title' => $title, 'description' => $desc, 'path' => $path, 'image' => $post['cover_image'], 'og_type' => 'article',
    'robots' => $post['robots'] === 'noindex' ? 'noindex, nofollow' : 'index, follow, max-image-preview:large',
    'breadcrumb' => [[ui('ব্লগ'), '/blog'], [$post['title'], null]], 'schema' => $schema,
]);
page_hero($post['category'] !== '' ? $post['category'] : 'Blog', $post['title'], '', [[ui('ব্লগ'), '/blog'], [trim_text($post['title'], 40), null]]);
?>
<section class="section"><div class="wrap layout-article">
  <article class="prose">
    <div class="cover"><?= img_picture(img_url($post['cover_image']), $post['title'], 0, 0, '(max-width:980px) 100vw, 900px', false) ?></div>
    <div class="meta">
      <span><?= h(ui('লেখক')) ?>: <?= h($author) ?></span>
      <span><?= h(site_lang() === 'en' ? date('j F Y', strtotime((string) $post['published_at'])) : bn_date($post['published_at'])) ?></span>
      <span><?= h(site_lang() === 'en' ? (string) max(1, reading_minutes((string) $post['body'])) : bn_num(reading_minutes((string) $post['body']))) ?> <?= h(ui('মিনিট পড়া')) ?></span>
<?php if ($reviewer !== ''): ?><span><?= h(ui('মেডিকেলি রিভিউড')) ?>: <?= h($reviewer) ?></span><?php endif; ?>
    </div>
    <div class="cq-content"><?= $bodyHtml ?></div>
<?php if ($tags): ?><p style="margin-top:2rem"><?php foreach ($tags as $t): ?><span class="chip">#<?= h($t) ?></span><?php endforeach; ?></p><?php endif; ?>
    <p class="note" style="margin-top:1.5rem"><?= h(s_tr('footer_disclaimer')) ?></p>
  </article>
  <aside class="aside-box">
    <div class="box">
      <h3><?= h(ui('অ্যাপয়েন্টমেন্ট নিতে চান?')) ?></h3>
      <p><?= h(tr(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn'), setting('doctor_title_en') . ' ' . setting('doctor_name_en'))) ?> <?= h(ui('সিরিয়ালের জন্য আমাদের জানান।')) ?></p>
      <p><a class="btn btn-primary btn-block" href="/appointment"><?= h(ui('সিরিয়াল নিন')) ?></a></p>
      <p><a class="btn btn-outline btn-block" href="<?= h(wa_link('আসসালামু আলাইকুম, "' . $post['title'] . '" লেখাটি পড়ে অ্যাপয়েন্টমেন্ট নিতে চাই।')) ?>" target="_blank" rel="noopener" data-track="whatsapp_click"><?= h(ui('হোয়াটসঅ্যাপ')) ?></a></p>
    </div>
<?php if ($related): ?>
    <div class="box"><h3><?= h(ui('সম্পর্কিত লেখা')) ?></h3><ul><?php foreach ($related as $r): ?><li><a href="/blog/<?= h($r['slug']) ?>"><?= h($r['title']) ?></a></li><?php endforeach; ?></ul></div>
<?php endif; ?>
  </aside>
</div></section>
<?php cta_band(); page_end();
