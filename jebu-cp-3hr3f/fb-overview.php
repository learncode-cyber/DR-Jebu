<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
require_once __DIR__ . '/../app/fb_overview.php';
require_perm('settings');

$c = fbo_counts(time());
$p = $c['posts'];
$cm = $c['comments'];
$j = $c['jobs'];

admin_head('Facebook ওভারভিউ');
?>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
  <?php tr_status('প্রকাশিত পোস্ট', ($p['posted'] ?? 0) > 0, (string) ($p['posted'] ?? 0)); ?>
  <?php tr_status('সময়সূচিতে', $c['scheduled'] > 0, (string) $c['scheduled']); ?>
  <?php tr_status('ব্যর্থ পোস্ট', ($p['failed'] ?? 0) === 0, (string) ($p['failed'] ?? 0)); ?>
  <?php tr_status('কমেন্ট (মোট)', array_sum($cm) > 0, (string) array_sum($cm)); ?>
  <?php tr_status('Messenger কথোপকথন', $c['conversations'] > 0, (string) $c['conversations']); ?>
  <?php tr_status('ব্যর্থ কাজ (Queue)', ($j['dead'] ?? 0) === 0, (string) ($j['dead'] ?? 0)); ?>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>এখনো যাচাই হয়নি (Live)</h2>
  <p class="muted" style="margin-top:0">Webhook health, Meta API status ও Page insights এই পাতা Meta-তে কল করে দেখায় না। Live যাচাইয়ের পরেই দেখানো হবে। এখন: <b>NOT CONFIGURED</b>।</p>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>সাম্প্রতিক ব্যর্থ কাজ</h2>
<?php if (!$c['failed_jobs']): ?>
  <p class="muted">কোনো ব্যর্থ কাজ নেই।</p>
<?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>ধরন</th><th>রেফারেন্স</th><th>চেষ্টা</th><th>শেষ ত্রুটি</th><th>সময়</th></tr></thead><tbody>
<?php foreach ($c['failed_jobs'] as $r): ?>
    <tr><td><?= h((string) $r['kind']) ?></td><td><?= h((string) $r['ref_id']) ?></td><td><?= (int) $r['attempts'] ?>/<?= (int) $r['max_attempts'] ?></td><td><?= h((string) $r['last_error']) ?></td><td><?= h((string) $r['updated_at']) ?></td></tr>
<?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div>
<?php admin_foot();
