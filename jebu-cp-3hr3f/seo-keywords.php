<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('content');
skw_table();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'import') {
        $r = skw_import((string) ($_POST['keywords'] ?? ''), (string) ($_POST['lang'] ?? 'bn'), (int) ($_POST['priority'] ?? 2), (string) ($_POST['intent'] ?? 'info'));
        $msg = $r['added'] . 'টি কিওয়ার্ড যোগ হয়েছে।' . ($r['refused'] ? ' বাদ গেছে: ' . implode(' | ', array_slice($r['refused'], 0, 5)) : '');
        flash($r['added'] > 0 ? 'ok' : 'err', $msg);
    } elseif ($act === 'delete') {
        flash(skw_delete((int) ($_POST['id'] ?? 0)) ? 'ok' : 'err', 'মুছে ফেলা হয়েছে।');
    }
    redirect(a_url('seo-keywords'));
}

admin_head('কিওয়ার্ড পরিকল্পনা');
$rows = skw_report();
$stateBn = ['covered' => '✅ ব্লগ আছে', 'draft_only' => '🟡 শুধু খসড়া', 'gap' => '🔴 নতুন ব্লগ দরকার'];
$counts = array_count_values(array_column($rows, 'state'));
?>
<div class="panel">
  <h2>নতুন কিওয়ার্ড যোগ করুন</h2>
  <p><small>প্রতি লাইনে একটি কিওয়ার্ড। বাইরের কোনো তথ্য আনা হয় না; শুধু আপনার লেখা ব্লগের সাথে মেলানো হয়।</small></p>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="import">
    <label>কিওয়ার্ড (প্রতি লাইনে একটি)<textarea name="keywords" rows="6" required placeholder="পিসিওএস কী&#10;irregular periods causes"></textarea></label>
    <label>ভাষা <select name="lang"><option value="bn">বাংলা</option><option value="en">English</option></select></label>
    <label>গুরুত্ব <select name="priority"><option value="1">উচ্চ</option><option value="2" selected>মাঝারি</option><option value="3">নিম্ন</option></select></label>
    <label>ধরন <select name="intent"><option value="info">তথ্য</option><option value="service">সেবা</option><option value="local">স্থানীয়</option><option value="other">অন্যান্য</option></select></label>
    <button class="btn">যোগ করুন</button>
  </form>
</div>
<div class="panel">
  <h2>কভারেজ</h2>
  <p>মোট <?= count($rows) ?>টি · ব্লগ আছে <?= (int) ($counts['covered'] ?? 0) ?> · শুধু খসড়া <?= (int) ($counts['draft_only'] ?? 0) ?> · নতুন ব্লগ দরকার <?= (int) ($counts['gap'] ?? 0) ?></p>
  <table class="table"><thead><tr><th>কিওয়ার্ড</th><th>ভাষা</th><th>গুরুত্ব</th><th>ধরন</th><th>অবস্থা</th><th>মিল</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['keyword']) ?></td><td><?= h($r['lang']) ?></td><td><?= (int) $r['priority'] ?></td><td><?= h($r['intent']) ?></td>
      <td><?= h($stateBn[$r['state']]) ?></td><td><?= (int) $r['match_count'] ?></td>
      <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn small ghost">মুছুন</button></form></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7">এখনো কোনো কিওয়ার্ড নেই।</td></tr><?php endif; ?>
  </tbody></table>
</div>
<?php admin_foot();
