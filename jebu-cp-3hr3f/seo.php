<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
seo_ensure_table();
$audit = seo_audit();
$log = q_all('SELECT url, change_type, detail, indexnow, created_at FROM seo_change_log ORDER BY id DESC LIMIT 100');
admin_head('SEO স্বাস্থ্য');
?>
<div class="panel">
  <h2>SEO স্বাস্থ্য পরীক্ষা</h2>
  <p class="muted">সাইটম্যাপে মোট URL: <b><?= (int) $audit['urls'] ?></b> · সাইটম্যাপ, canonical, স্কিমা ও ম্যাপ স্বয়ংক্রিয়ভাবে আপডেট হয়। প্রতিটি সেভে বদল হলে IndexNow ও লগ হয়।</p>
<?php if ($audit['critical']): ?><h3 style="color:#b00020">গুরুতর সমস্যা (ঠিক করা জরুরি)</h3><ul><?php foreach ($audit['critical'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($audit['warn']): ?><h3>সতর্কতা / আপনার তথ্য লাগবে</h3><ul><?php foreach ($audit['warn'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($audit['ok']): ?><h3>ঠিক আছে</h3><ul><?php foreach ($audit['ok'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <p>দেখুন: <a href="/sitemap.xml" target="_blank" rel="noopener">sitemap.xml</a> · <a href="/robots.txt" target="_blank" rel="noopener">robots.txt</a> · <a href="/feed.xml" target="_blank" rel="noopener">feed.xml</a></p>
</div>
<div class="panel">
  <h2>পরিবর্তনের লগ (সর্বশেষ ১০০)</h2>
<?php if (!$log): ?><p class="muted">এখনও কোনো পরিবর্তন লগ হয়নি।</p><?php else: ?>
  <table><thead><tr><th>সময়</th><th>ধরন</th><th>URL</th><th>বিবরণ</th><th>IndexNow</th></tr></thead><tbody>
<?php foreach ($log as $r): ?>
    <tr><td><?= h($r['created_at']) ?></td><td><?= h($r['change_type']) ?></td><td><a href="<?= h($r['url']) ?>" target="_blank" rel="noopener"><?= h($r['url']) ?></a></td><td><?= h($r['detail']) ?></td><td><?= $r['indexnow'] ? 'পাঠানো' : '—' ?></td></tr>
<?php endforeach; ?>
  </tbody></table>
<?php endif; ?>
</div>
<?php admin_foot();
