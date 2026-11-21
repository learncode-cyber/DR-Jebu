<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
require_perm('settings');

// Facebook queue failures, read from the real job table. Tokens and message bodies are not stored in last_error.
$rows = q_all("SELECT id, kind, ref_id, state, attempts, max_attempts, last_error, updated_at FROM fb_jobs WHERE last_error IS NOT NULL AND last_error <> '' ORDER BY updated_at DESC LIMIT 100");

admin_head('Errors & Logs');
?>
<div class="panel">
  <h2>Facebook: ব্যর্থতা ও ত্রুটি</h2>
  <p class="muted" style="margin-top:0">এখানে শুধু Facebook queue-এর ব্যর্থ বা পুনঃচেষ্টার কাজ দেখা যায়। প্রতিটা ত্রুটির পর কাজটি retry হবে কিনা, তা "অবস্থা" কলামে দেখুন। Token বা secret এখানে দেখানো হয় না।</p>
<?php if (!$rows): ?>
  <p class="muted">এখন কোনো ত্রুটি নেই।</p>
<?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>ধরন</th><th>রেফারেন্স</th><th>অবস্থা</th><th>চেষ্টা</th><th>শেষ ত্রুটি</th><th>সময়</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h((string) $r['kind']) ?></td>
      <td><?= h((string) $r['ref_id']) ?></td>
      <td><?= tr_badge($r['state'] === 'dead' ? 'বন্ধ (ম্যানুয়াল দরকার)' : ($r['state'] === 'retry' ? 'পুনরায় চেষ্টা হবে' : (string) $r['state']), $r['state'] === 'dead' ? 'bad' : 'warn') ?></td>
      <td><?= (int) $r['attempts'] ?>/<?= (int) $r['max_attempts'] ?></td>
      <td><?= h((string) $r['last_error']) ?></td>
      <td><?= h((string) $r['updated_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div>
<?php admin_foot();
