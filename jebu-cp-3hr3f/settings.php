<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
$schema = settings_schema();
if (is_post()) {
    csrf_require();
    foreach ($schema as $k => [$label, $type, $group]) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if (in_array($k, ['facebook_url', 'youtube_url', 'instagram_url'], true) && $v !== '' && !preg_match('#^https?://#i', $v)) {
            continue;
        }
        if ($k === 'map_embed_src' && $v !== '' && !preg_match('#^https://www\.google\.com/maps/embed#', $v)) {
            flash('err', 'Map URL অবশ্যই https://www.google.com/maps/embed… দিয়ে শুরু হতে হবে।');
            continue;
        }
        if ($k === 'ga_measurement_id' && $v !== '' && !preg_match('/^G-[A-Z0-9]+$/', $v)) { flash('err', 'GA4 ID সঠিক নয়।'); continue; }
        if ($k === 'meta_pixel_id' && $v !== '' && !preg_match('/^\d+$/', $v)) { flash('err', 'Meta Pixel ID শুধু সংখ্যা।'); continue; }
        if ($k === 'notify_email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { flash('err', 'নোটিফিকেশন ইমেইল সঠিক নয়।'); continue; }
        $defaults = site_defaults();
        save_setting($k, $v === ($defaults[$k] ?? '') ? '' : $v);
    }
    log_activity('settings.saved', '', (int) $u['id']);
    flash('ok', 'সেটিংস সংরক্ষিত হয়েছে।');
    redirect(a_url('settings'));
}
admin_head('সাইট সেটিংস');
$groups = [];
foreach ($schema as $k => $def) { $groups[$def[2]][$k] = $def; }
?>
<form method="post" class="form"><?= csrf_field() ?>
<?php foreach ($groups as $g => $items): ?>
<div class="panel"><h2><?= h($g) ?></h2>
<?php foreach ($items as $k => [$label, $type]): ?>
  <label><?= h($label) ?>
  <?php if ($type === 'textarea'): ?><textarea name="<?= h($k) ?>" rows="<?= in_array($k, ['about_text', 'credentials', 'training_text'], true) ? 8 : 3 ?>"><?= h(setting($k)) ?></textarea>
  <?php else: ?><input type="text" name="<?= h($k) ?>" value="<?= h(setting($k)) ?>"><?php endif; ?></label>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<button class="btn" type="submit">সব সংরক্ষণ করুন</button></form>
<?php admin_foot();
