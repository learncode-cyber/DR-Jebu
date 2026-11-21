<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
fb_comments_table();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'approve') {
        [$ok, $msg] = fb_comment_approve($id, (string) ($_POST['reply'] ?? ''));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'skip') {
        fb_comment_skip($id);
        flash('ok', 'মন্তব্য বাদ দেওয়া হয়েছে।');
    } elseif ($act === 'sync') {
        $n = fb_comment_sync_drafts();
        flash('ok', $n . 'টি খসড়া আপডেট হয়েছে।');
    }
    redirect(a_url('fb-comments'));
}

admin_head('Facebook কমেন্ট');
$rows = q_all("SELECT * FROM fb_comments WHERE review_status IN ('new','drafting','ready','needs_owner','medical_hold','failed') ORDER BY id DESC LIMIT 80");
$label = ['new' => 'নতুন', 'drafting' => 'খসড়া হচ্ছে', 'ready' => 'অনুমোদনের অপেক্ষায়', 'needs_owner' => 'আপনি লিখুন', 'medical_hold' => 'চিকিৎসা-সংক্রান্ত: আপনি লিখুন', 'failed' => 'পাঠাতে ব্যর্থ'];
?>
<div class="panel">
  <h2>কমেন্ট অনুমোদন</h2>
  <p class="muted">এখান থেকে যা অনুমোদন করবেন, শুধু তাই যাবে। চিকিৎসা-সংক্রান্ত কমেন্টে এআই খসড়া লেখে না; আপনি নিজে লিখুন। একজন কমেন্টকারীকে একটিই ইনবক্স বার্তা যায় (Meta-র নিয়ম), এবং মন্তব্যের ৭ দিনের মধ্যে।</p>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="sync"><button class="btn ghost" type="submit">খসড়া আপডেট করুন</button></form>
</div>

<?php if (!$rows): ?><div class="panel"><p class="muted">অপেক্ষায় কোনো কমেন্ট নেই।</p></div><?php endif; ?>
<?php foreach ($rows as $r): ?>
<div class="panel">
  <p><b><?= h($r['from_name'] !== '' ? $r['from_name'] : 'অজানা') ?></b> <small class="muted">· <?= h($label[$r['review_status']] ?? $r['review_status']) ?></small></p>
  <p><?= nl2br(h(mb_substr((string) $r['body'], 0, 600))) ?></p>
  <?php if (in_array($r['review_status'], ['ready', 'needs_owner', 'medical_hold', 'failed'], true)): ?>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <label>ইনবক্সের উত্তর<textarea name="reply" rows="3" maxlength="900"><?= h((string) ($r['draft'] ?? '')) ?></textarea></label>
    <?php if ((string) $r['error'] !== ''): ?><small class="muted"><?= h((string) $r['error']) ?></small><br><?php endif; ?>
    <button class="btn" type="submit" name="act" value="approve">অনুমোদন ও পাঠান</button>
    <button class="btn ghost" type="submit" name="act" value="skip">বাদ দিন</button>
  </form>
  <?php else: ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="skip"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn ghost" type="submit">বাদ দিন</button></form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php admin_foot();
