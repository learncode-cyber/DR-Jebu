<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
require_once __DIR__ . '/../app/fb_config.php';
$u = require_perm('settings');

if (is_post()) {
    csrf_require();
    [$clean, $errs] = fbc_validate_ai($_POST);
    if ($errs) {
        flash('err', implode(' ', $errs));
    } else {
        foreach ($clean as $k => $v) { save_setting($k, $v); }
        log_activity('fb.ai_saved', 'AI উত্তরের সেটিংস আপডেট', (int) $u['id']);
        flash('ok', 'AI উত্তরের সেটিংস সংরক্ষিত হয়েছে।');
    }
    redirect(a_url('fb-ai'));
}

admin_head('AI Automation');
?>
<div class="panel">
  <h2>AI কমেন্ট উত্তরের সেটিংস</h2>
  <p class="muted" style="margin-top:0">নিরাপত্তা নিয়ম (বদলানো যাবে না): চিকিৎসা, গর্ভাবস্থা ও জরুরি প্রশ্ন সবসময় ডাক্তারের কাছে যাবে। AI রোগ নির্ণয়, ওষুধ বা প্রেসক্রিপশন দেবে না। মানুষের অনুমোদন সবসময় চালু থাকবে।</p>
  <p class="muted">উত্তর স্বয়ংক্রিয়ভাবে পাঠানো চালু/বন্ধ করা হয় "সোশ্যাল মিডিয়া → Comments Overview"-তে। এখানে উত্তরের ধরন ঠিক করুন।</p>
  <form method="post" class="form"><?= csrf_field() ?>
    <label>টোন
      <select name="ai_reply_tone">
        <?php foreach (['friendly' => 'বন্ধুসুলভ', 'formal' => 'আনুষ্ঠানিক', 'warm' => 'আন্তরিক'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= setting('ai_reply_tone', 'friendly') === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>ভাষা
      <select name="ai_reply_language">
        <?php foreach (['bn' => 'বাংলা', 'en' => 'English', 'bn_en' => 'বাংলা ও English'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= setting('ai_reply_language', 'bn') === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></label>
    <?php tr_field('ai_cta_url', 'অ্যাপয়েন্টমেন্ট লিংক (শুধু drjebunnesa.online)', setting('ai_cta_url'), 'উত্তর ও প্রথম কমেন্টে এই লিংক যাবে।', 'text', 'https://drjebunnesa.online/appointment'); ?>
    <?php tr_field('ai_confidence_threshold', 'Confidence threshold (0–1)', setting('ai_confidence_threshold', '0.8'), 'এর নিচের উত্তর ম্যানুয়াল রিভিউয়ে যাবে।', 'text', '0.8'); ?>
    <?php tr_field('ai_max_reply_attempts', 'সর্বোচ্চ চেষ্টা (১–৩)', setting('ai_max_reply_attempts', '2'), '', 'text', '2'); ?>
    <p class="muted">মানুষের অনুমোদন: <b>সবসময় চালু</b> (এটি বন্ধ করা যায় না)।</p>
    <button class="btn" type="submit">AI সেটিংস সংরক্ষণ করুন</button>
  </form>
</div>
<?php admin_foot();
