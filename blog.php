<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$per = 9;
$page = max(1, (int) ($_GET['page'] ?? 1));
$cat = trim((string) ($_GET['cat'] ?? ''));
$now = date('Y-m-d H:i:s');
$cats = q_all("SELECT category, COUNT(*) n FROM blog_posts WHERE status='published' AND published_at <= ? AND category <> '' GROUP BY category ORDER BY n DESC", [$now]);
$where = "status = 'published' AND published_at <= ?";
$params = [$now];
if ($cat !== '') {
    $where .= ' AND category = ?';
    $params[] = $cat;
}
$total = (int) q_val("SELECT COUNT(*) FROM blog_posts WHERE $where", $params);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$posts = localize_rows(q_all("SELECT slug, title, title_en, excerpt, excerpt_en, cover_image, category, published_at, body, body_en FROM blog_posts WHERE $where ORDER BY published_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params));

$qs = static fn(array $extra): string => '/blog' . (($q = http_build_query(array_filter($extra, static fn($v) => $v !== '' && $v !== 1 && $v !== null))) ? '?' . $q : '');
page_start([
    'title' => $cat !== '' ? $cat . ' | ব্লগ' : 'স্বাস্থ্য সচেতনতা ব্লগ',
    'description' => 'গর্ভাবস্থা, বন্ধ্যাত্ব, PCOS, মাসিক ও নারী স্বাস্থ্য বিষয়ে সহজ বাংলায় তথ্যবহুল লেখা।',
    'path' => $qs(['cat' => $cat, 'page' => $page]), 'breadcrumb' => [['ব্লগ', null]],
    'robots' => ($page > 1 || $cat !== '') ? 'noindex, follow' : 'index, follow, max-image-preview:large',
    'schema' => [['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => 'ব্লগ | ' . setting('doctor_name_bn'), 'url' => abs_url('/blog')]],
]);
page_hero('Blog', 'স্বাস্থ্য সচেতনতার ব্লগ', 'সহজ বাংলায় গর্ভাবস্থা, বন্ধ্যাত্ব ও নারী স্বাস্থ্য বিষয়ক নির্ভরযোগ্য লেখা।', [['ব্লগ', null]]);
?>
<section class="section"><div class="wrap">
<?php if ($cats): ?>
  <div class="filter-pills" role="navigation" aria-label="<?= h(ui('ক্যাটাগরি')) ?>">
    <a href="/blog" class="<?= $cat === '' ? 'on' : '' ?>"><?= h(ui('সব লেখা')) ?></a>
<?php foreach ($cats as $c): ?><a href="<?= h($qs(['cat' => $c['category']])) ?>" class="<?= $cat === $c['category'] ? 'on' : '' ?>"><?= h(ui($c['category'])) ?> (<?= h(site_lang() === 'en' ? (string) $c['n'] : bn_num($c['n'])) ?>)</a><?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if (!$posts): ?>
  <div class="empty"><p><?= h(ui('এই বিভাগে এখনও কোনো লেখা প্রকাশিত হয়নি।')) ?></p><a class="btn btn-primary" href="/blog"><?= h(ui('সব লেখা দেখুন')) ?></a></div>
<?php else: ?>
  <div class="grid c3">
<?php foreach ($posts as $i => $p): ?>
    <a class="card reveal" href="/blog/<?= h($p['slug']) ?>" style="transition-delay:<?= ($i % 3) * 60 ?>ms">
      <div class="card-img"><?= img_picture(img_url($p['cover_image']), $p['title'], 0, 0, '(max-width:620px) 100vw, (max-width:980px) 50vw, 33vw') ?></div>
<?php if ($p['category'] !== ''): ?><span class="chip"><?= h(ui($p['category'])) ?></span><?php endif; ?>
      <h3><?= h($p['title']) ?></h3>
      <p><?= h(trim_text((string) $p['excerpt'], 150)) ?></p>
      <span class="more"><?= h(site_lang() === 'en' ? date('j F Y', strtotime((string) $p['published_at'])) : bn_date($p['published_at'])) ?> · <?= h(site_lang() === 'en' ? (string) max(1, reading_minutes((string) $p['body'])) : bn_num(reading_minutes((string) $p['body']))) ?> <?= h(ui('মিনিট পড়া')) ?> →</span>
    </a>
<?php endforeach; ?>
  </div>
<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="পৃষ্ঠা">
<?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php $pgNum = site_lang() === 'en' ? (string) $i : bn_num($i); ?><?php if ($i === $page): ?><span class="cur"><?= h($pgNum) ?></span><?php else: ?><a href="<?= h($qs(['cat' => $cat, 'page' => $i])) ?>"><?= h($pgNum) ?></a><?php endif; ?>
<?php endfor; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</div></section>
<?php cta_band(); page_end();
