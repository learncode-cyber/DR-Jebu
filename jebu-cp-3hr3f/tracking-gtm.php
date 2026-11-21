<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
$u = require_perm('settings');

if (is_post()) {
    csrf_require();
    if ((string) ($_POST['act'] ?? '') === 'gtm_save') {
        $gtm = strtoupper(trim((string) ($_POST['gtm_container_id'] ?? '')));
        if ($gtm !== '' && preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm) !== 1) {
            flash('err', 'GTM Container ID সঠিক নয়। ফরম্যাট: GTM-XXXXXXX');
        } else {
            save_setting('gtm_container_id', $gtm);
            log_activity('tracking.gtm_saved', $gtm === '' ? 'off' : 'on', (int) $u['id']);
            flash('ok', $gtm === '' ? 'GTM বন্ধ করা হয়েছে।' : 'GTM Container ID সংরক্ষিত হয়েছে।');
        }
    } else {
        flash('err', 'অজানা অনুরোধ।');
    }
    redirect(a_url('tracking-gtm'));
}

$gtm = tracking_gtm_id();
admin_head('Google Tag Manager (GTM)');
?>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
  <?php tr_status('GTM', $gtm !== '', $gtm !== '' ? $gtm . ' — চালু' : 'বন্ধ'); ?>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>GTM Container ID</h2>
  <p class="muted" style="margin-top:0">GTM ID দিলে GTM-ই ব্রাউজারের একমাত্র ট্যাগ ম্যানেজার হবে: সাইটে সরাসরি gtag ও Meta Pixel বসবে না। GA4, Google Ads ও Meta-র ব্রাউজার ট্যাগ তখন GTM-এর ভেতরে বানাতে হবে। খালি রাখলে GTM বন্ধ থাকবে এবং অ্যাকাউন্টগুলো আগের মতো কাজ করবে।</p>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="gtm_save">
    <?php tr_field('gtm_container_id', 'GTM Container ID', $gtm, 'যেমন GTM-XXXXXXX। ফাঁকা রাখলে GTM বন্ধ।', 'text', 'GTM-XXXXXXX'); ?>
    <button class="btn">সেভ করুন</button>
  </form>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>GTM চালু করার আগে</h2>
  <?php tr_steps([
      ['GTM কন্টেইনার বানান', 'tagmanager.google.com-এ নতুন Web container বানান। সেখান থেকে GTM-XXXXXXX ID কপি করুন।'],
      ['GA4 ট্যাগ যোগ করুন', 'GTM-এ GA4 Configuration tag বসান (Measurement ID G- দিয়ে)। এরপর generate_lead ইভেন্ট ট্রিগার করুন dataLayer-এর "generate_lead" থেকে।'],
      ['Meta ট্যাগ যোগ করুন', 'GTM-এ Meta Pixel ট্যাগ বসান। Lead ইভেন্টে dataLayer-এর event_id পাস করুন, যাতে সার্ভারের সাথে মিলে এক হিসেবে গোনা হয়।'],
      ['ডুপ্লিকেট এড়ান', 'সার্ভার থেকে GA4 generate_lead আগেই যায়। GTM-এর GA4 ট্যাগে এই ইভেন্টটি বন্ধ রাখুন, নয়তো GA4-তে দুইবার গোনা হবে।'],
      ['Preview দিয়ে যাচাই', 'GTM Preview চালু করে সাইটে ফর্ম জমা দিন। ট্যাগগুলো ঠিক ক্রমে ফায়ার হচ্ছে কিনা দেখুন। তারপর GTM Publish করুন।'],
  ]); ?>
  <p><small class="muted">সরাসরি টেস্ট: "সব ট্র্যাকিং ও টেস্ট" পেজের ব্রাউজার টেস্ট মোড চালু করলে ধন্যবাদ পেজের ইভেন্ট কনসোলে দেখা যাবে।</small></p>
</div>
<?php
admin_foot();
