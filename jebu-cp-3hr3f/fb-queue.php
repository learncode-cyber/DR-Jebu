<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
fbq_table();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'run') {
        [$ok, $msg] = fbq_run_job($id);
        flash($ok ? 'ok' : 'err', $ok ? 'কাজটি সম্পন্ন হয়েছে।' : 'সম্পন্ন হয়নি: ' . $msg);
    } elseif ($act === 'requeue') {
        fbq_requeue($id);
        flash('ok', 'আবার কিউতে দেওয়া হয়েছে।');
    } elseif ($act === 'cancel') {
        flash(fbq_cancel($id) ? 'ok' : 'err', 'বাতিল করা হয়েছে।');
    } elseif ($act === 'tick') {
        flash('ok', fbq_worker_tick(10) . 'টি কাজ চালানো হয়েছে।');
    }
    redirect(a_url('fb-queue'));
}

admin_head('Facebook পাঠানোর কিউ');
$tick = setting('sys_fbq_tick_at');
$rows = q_all('SELECT * FROM fb_jobs ORDER BY id DESC LIMIT 80');
$stateBn = ['queued' => 'অপেক্ষায়', 'running' => 'চলছে', 'retry' => 'আবার চেষ্টা হবে', 'done' => 'সম্পন্ন', 'dead' => 'ব্যর্থ (থেমে গেছে)'];
$kindBn = ['fbp_post' => 'AI পোস্ট', 'fbs_post' => 'উৎস-পোস্ট', 'fbs_comment' => 'প্রথম কমেন্ট'];
?>
<div class="panel">
  <h2>Facebook পাঠানোর কিউ</h2>
  <p>সর্বশেষ কিউ-চালানো: <strong><?= $tick !== '' ? h($tick) : 'এখনো চলেনি' ?></strong>
    <?php if ($tick === '' || strtotime($tick) < time() - 900): ?>
      <span class="badge warn">১৫ মিনিটের বেশি সময় ধরে চলেনি — Cron (dispatch.php) যাচাই করুন</span>
    <?php endif; ?></p>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="tick">
    <button class="btn">এখনই কিউ চালান</button></form>
  <p><small>পোস্ট একবারই পাঠানো হয়। অস্থায়ী ত্রুটিতে স্বয়ংক্রিয়ভাবে আবার চেষ্টা হয় (সর্বোচ্চ ৫ বার)। স্থায়ী ত্রুটিতে কাজ থেমে যায় এবং সতর্কবার্তা আসে।</small></p>
</div>
<div class="panel">
  <table class="table">
    <thead><tr><th>#</th><th>ধরন</th><th>আইটেম</th><th>অবস্থা</th><th>চেষ্টা</th><th>শেষ ত্রুটি</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int) $r['id'] ?></td>
        <td><?= h($kindBn[$r['kind']] ?? $r['kind']) ?></td>
        <td><?= (int) $r['ref_id'] ?></td>
        <td><?= h($stateBn[$r['state']] ?? $r['state']) ?></td>
        <td><?= (int) $r['attempts'] ?>/<?= (int) $r['max_attempts'] ?></td>
        <td><small><?= h((string) $r['last_error']) ?></small></td>
        <td>
          <?php if (in_array($r['state'], ['queued', 'retry'], true)): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="act" value="run"><button class="btn small">এখনই চালান</button></form>
          <?php endif; ?>
          <?php if ($r['state'] === 'dead'): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="act" value="requeue"><button class="btn small">আবার পাঠান</button></form>
          <?php endif; ?>
          <?php if (in_array($r['state'], ['queued', 'retry', 'dead'], true)): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="act" value="cancel"><button class="btn small ghost">বাতিল</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7">এখনো কোনো কাজ নেই।</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php admin_foot();
