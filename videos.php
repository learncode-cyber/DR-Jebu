<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$rows = entity_rows('videos');
$schema = [];
foreach ($rows as $r) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => $r['title'], 'description' => $r['description'] ?: $r['title'], 'thumbnailUrl' => 'https://i.ytimg.com/vi/' . $r['youtube_id'] . '/hqdefault.jpg', 'embedUrl' => 'https://www.youtube.com/embed/' . $r['youtube_id'], 'uploadDate' => substr((string) $r['created_at'], 0, 10)];
}
page_start(['title' => 'স্বাস্থ্য সচেতনতার ভিডিও', 'description' => 'গর্ভাবস্থা, বন্ধ্যাত্ব ও গাইনী স্বাস্থ্য বিষয়ে শিক্ষামূলক ভিডিও।', 'path' => '/videos', 'breadcrumb' => [['ভিডিও', null]], 'schema' => $schema]);
page_hero('Videos', 'স্বাস্থ্য সচেতনতার ভিডিও', 'সহজ ভাষায় শিক্ষামূলক ভিডিও ও স্বাস্থ্য পরামর্শ।', [['ভিডিও', null]]);
?>
<section class="section"><div class="wrap">
<?php if (!$rows): ?>
  <div class="empty"><p>ভিডিও শীঘ্রই যুক্ত করা হবে।</p><a class="btn btn-primary" href="/blog">ব্লগ পড়ুন</a></div>
<?php else: ?>
  <div class="grid c3">
<?php foreach ($rows as $r): ?>
    <div class="card reveal">
      <div class="video-frame"><button type="button" data-yt="<?= h($r['youtube_id']) ?>" aria-label="<?= h($r['title']) ?> চালান" style="background-image:url('https://i.ytimg.com/vi/<?= h($r['youtube_id']) ?>/hqdefault.jpg')"><span class="play">▶</span></button></div>
      <h3 style="margin-top:1rem"><?= h($r['title']) ?></h3>
<?php if ($r['category'] !== ''): ?><span class="chip"><?= h($r['category']) ?></span><?php endif; ?>
<?php if ($r['description'] !== ''): ?><p><?= h($r['description']) ?></p><?php endif; ?>
    </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</div></section>
<?php cta_band(); page_end();
