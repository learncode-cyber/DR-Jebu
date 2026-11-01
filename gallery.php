<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
try {
    $rows = entity_rows('gallery');
} catch (Throwable $e) {
    error_log('[jebu] gallery: ' . $e->getMessage());
    render_404();
}
if (!$rows) {
    render_404();
}
page_start(['title' => 'গ্যালারি', 'description' => 'ডাঃ জেবুন্নেসা জেবুর চেম্বার, সেমিনার ও কর্মক্ষেত্রের ছবি।', 'path' => '/gallery', 'breadcrumb' => [['গ্যালারি', null]]]);
page_hero('গ্যালারি', 'আমাদের কর্মক্ষেত্রের ছবি', 'চেম্বার, সেমিনার ও কর্মক্ষেত্রের কিছু মুহূর্ত।', [['গ্যালারি', null]]);
?>
<section class="section"><div class="wrap">
<?php
$cats = [];
foreach ($rows as $r) { $cats[$r['category'] !== '' ? $r['category'] : 'সাধারণ'][] = $r; }
foreach ($cats as $cat => $list): ?>
  <h2 class="display" style="font-size:1.5rem;margin:1.6rem 0 1rem"><?= h($cat) ?></h2>
  <div class="gallery-grid">
<?php foreach ($list as $r): ?>
    <figure class="gallery-item reveal"><a href="<?= h($r['image']) ?>" target="_blank" rel="noopener"><img src="<?= h($r['image']) ?>" alt="<?= h($r['title']) ?>" loading="lazy" width="800" height="600"></a><figcaption><strong><?= h($r['title']) ?></strong><?php if ((string) $r['caption'] !== ''): ?><span><?= h($r['caption']) ?></span><?php endif; ?></figcaption></figure>
<?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div></section>
<?php cta_band(); page_end();
