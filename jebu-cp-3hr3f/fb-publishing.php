<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
require_once __DIR__ . '/../app/fb_config.php';
$u = require_perm('settings');

if (is_post()) {
    csrf_require();
    [$clean, $errs] = fbc_validate_publishing($_POST);
    if ($errs) {
        flash('err', implode(' ', $errs));
    } else {
        foreach ($clean as $k => $v) { save_setting($k, $v); }
        log_activity('fb.publishing_saved', 'প্রকাশনার সেটিংস আপডেট', (int) $u['id']);
        flash('ok', 'প্রকাশনার সেটিংস সংরক্ষিত হয়েছে।');
    }
    redirect(a_url('fb-publishing'));
}

$mode = setting('fb_publish_mode', 'manual');
admin_head('Publishing Settings');
?>
<div class="panel">
  <h2>Facebook প্রকাশনা সেটিংস</h2>
  <p class="muted" style="margin-top:0">সব অটোমেশন ডিফল্টে বন্ধ। "স্বয়ংক্রিয়" বেছে নিলেই AI পোস্ট নিজে থেকে যাবে। সময় সবসময় বাংলাদেশ সময় (Asia/Dhaka)।</p>
  <form method="post" class="form"><?= csrf_field() ?>
    <label>প্রকাশের মোড
      <select name="fb_publish_mode">
        <option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>ম্যানুয়াল (আপনি পাঠান)</option>
        <option value="scheduled" <?= $mode === 'scheduled' ? 'selected' : '' ?>>সময়সূচি (কিউতে সময় দিয়ে রাখুন)</option>
        <option value="automatic" <?= $mode === 'automatic' ? 'selected' : '' ?>>স্বয়ংক্রিয় (AI পোস্ট নিজে যাবে)</option>
      </select></label>
    <?php tr_field('fb_autopost_per_day', 'দিনে সর্বোচ্চ AI পোস্ট (১–৬)', setting('fb_autopost_per_day', '1'), 'শুধু "স্বয়ংক্রিয়" মোডে কার্যকর।', 'text', '1'); ?>
    <?php tr_field('fb_max_queue', 'সর্বোচ্চ কিউ সংখ্যা (১–৫০০)', setting('fb_max_queue', '50'), 'এর বেশি পোস্ট কিউতে যোগ হবে না।', 'text', '50'); ?>
    <label>প্রথম কমেন্ট চালু
      <select name="fb_first_comment_enabled">
        <option value="1" <?= setting('fb_first_comment_enabled', '1') === '1' ? 'selected' : '' ?>>হ্যাঁ</option>
        <option value="0" <?= setting('fb_first_comment_enabled', '1') !== '1' ? 'selected' : '' ?>>না</option>
      </select></label>
    <label>প্রথম কমেন্টের লেখা (<code>{link}</code> জায়গায় অ্যাপয়েন্টমেন্ট লিংক বসবে)
      <textarea name="fb_first_comment_template" rows="3" maxlength="500"><?= h(setting('fb_first_comment_template')) ?></textarea></label>
    <p class="muted">অ্যাপয়েন্টমেন্ট লিংক (AI সেটিংসে বদলানো যাবে): <code><?= h(setting('ai_cta_url')) ?></code></p>
    <p class="muted">প্রথম কমেন্ট শুধু পোস্ট সফলভাবে প্রকাশের পরেই যাবে এবং একবারই যাবে।</p>
    <button class="btn" type="submit">প্রকাশনার সেটিংস সংরক্ষণ করুন</button>
  </form>
</div>
<?php admin_foot();
