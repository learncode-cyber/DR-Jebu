<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
fbs_table();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'add') {
        [$ok, $msg] = fbs_add((string) ($_POST['source_name'] ?? ''), (string) ($_POST['source_url'] ?? ''), (string) ($_POST['title'] ?? ''), (string) ($_POST['notes'] ?? ''));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'publish') {
        [$ok, $msg] = fbs_publish($id, (string) ($_POST['text'] ?? ''));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'skip') {
        fbs_skip($id);
        flash('ok', 'বাদ দেওয়া হয়েছে।');
    } elseif ($act === 'sync') {
        flash('ok', fbs_sync() . 'টি খসড়া আপডেট হয়েছে।');
    }
    redirect(a_url('fb-sources'));
}

admin_head('Facebook পোস্ট (উৎস থেকে)');
$rows = q_all('SELECT * FROM fb_source_items ORDER BY id DESC LIMIT 60');
$label = ['new' => 'নতুন', 'drafting' => 'খসড়া হচ্ছে', 'ready' => 'অনুমোদনের অপেক্ষায়', 'needs_owner' => 'আপনি লিখুন', 'published' => 'প্রকাশিত', 'failed' => 'ব্যর্থ', 'skipped' => 'বাদ'];
?>
<div class="panel">
  <h2>নতুন উৎস-নোট যোগ করুন</h2>
  <p class="muted">উৎস থেকে নিজে সারাংশ লিখে দিন (পুরো লেখা কপি করবেন না)। খসড়া লেখা হবে, আপনি অনুমোদন করলে পেজে যাবে। লিংক পোস্টে যায় না; অ্যাপয়েন্টমেন্টের লিংক প্রথম কমেন্টে যাবে।</p>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="add">
    <label>উৎসের নাম (ক্রেডিট হিসেবে যাবে)<input type="text" name="source_name" maxlength="120" required></label>
    <label>উৎসের লিংক (ঐচ্ছিক, শুধু আপনার রেকর্ডের জন্য)<input type="url" name="source_url" maxlength="500"></label>
    <label>শিরোনাম (ঐচ্ছিক)<input type="text" name="title" maxlength="200"></label>
    <label>সারাংশ (আপনার নিজের ভাষায়, সর্বোচ্চ ১৫০০ অক্ষর)<textarea name="notes" rows="5" maxlength="1500" required></textarea></label>
    <button class="btn" type="submit">যোগ করে খসড়া বানান</button>
  </form>
  <form method="post" class="inline" style="margin-top:.8rem"><?= csrf_field() ?><input type="hidden" name="act" value="sync"><button class="btn ghost" type="submit">খসড়া আপডেট করুন</button></form>
</div>

<?php if (!$rows): ?><div class="panel"><p class="muted">এখনো কোনো উৎস-নোট নেই।</p></div><?php endif; ?>
<?php foreach ($rows as $r): ?>
<div class="panel">
  <p><b><?= h($r['source_name']) ?></b> <small class="muted">· <?= h($label[$r['status']] ?? $r['status']) ?><?= $r['published_at'] ? ' · ' . h(gem_show_dhaka(gmdate('Y-m-d H:i:s', strtotime($r['published_at'] . ' +0600')))) : '' ?></small></p>
  <?php if ($r['title'] !== ''): ?><p class="muted"><?= h($r['title']) ?></p><?php endif; ?>
  <?php if (in_array($r['status'], ['ready', 'needs_owner', 'failed'], true)): ?>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <label>পোস্টের লেখা (প্রকাশের আগে দেখে নিন)<textarea name="text" rows="6" maxlength="<?= FBP_MAX_CHARS ?>"><?= h((string) ($r['draft'] ?? $r['notes'])) ?></textarea></label>
    <?php if ((string) $r['error'] !== ''): ?><small class="muted"><?= h((string) $r['error']) ?></small><br><?php endif; ?>
    <button class="btn" type="submit" name="act" value="publish">অনুমোদন ও পেজে পাঠান</button>
    <button class="btn ghost" type="submit" name="act" value="skip">বাদ দিন</button>
  </form>
  <?php elseif ($r['status'] === 'published'): ?>
  <p class="muted">প্রথম কমেন্ট: <?= $r['first_comment_status'] === 'ok' ? 'হয়েছে' : 'যায়নি (ম্যানুয়ালি যোগ করুন)' ?></p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php admin_foot();
