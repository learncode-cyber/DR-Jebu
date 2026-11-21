<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
require_once __DIR__ . '/../app/fb_config.php';
$u = require_perm('settings');

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'app_save') {
        [$clean, $errs] = fbc_validate_app($_POST);
        if ($errs) {
            flash('err', implode(' ', $errs));
        } else {
            foreach ($clean as $k => $v) { save_setting($k, $v); }
            log_activity('fb.app_saved', 'মেটা অ্যাপ সেটিংস আপডেট', (int) $u['id']);
            flash('ok', 'Meta App সেটিংস সংরক্ষিত হয়েছে।');
        }
    } elseif ($act === 'secret_save') {
        // The App Secret is replaced only when a new value is typed; it is never shown back.
        $new = trim((string) ($_POST['meta_app_secret'] ?? ''));
        if ($new === '') {
            flash('err', 'নতুন App Secret লিখুন। খালি রাখলে আগেরটা থাকবে।');
        } elseif (strlen($new) < 16 || strlen($new) > 200) {
            flash('err', 'App Secret ১৬–২০০ অক্ষরের মধ্যে হতে হবে।');
        } else {
            save_setting('meta_app_secret', $new);
            log_activity('fb.secret_replaced', 'App Secret বদলানো হয়েছে (মান দেখানো হয় না)', (int) $u['id']);
            flash('ok', 'App Secret নতুন করে সংরক্ষিত হয়েছে।');
        }
    } elseif ($act === 'verify_gen') {
        // A new verify token is a secret shared with Meta; the owner must paste it in Meta too.
        save_setting('meta_verify_token', bin2hex(random_bytes(16)));
        log_activity('fb.verify_token_generated', 'নতুন Verify Token তৈরি (মান দেখানো হয় না)', (int) $u['id']);
        flash('ok', 'নতুন Verify Token তৈরি হয়েছে। এটি Meta-র Webhook সেটিংসে একই মান দিয়ে বসাতে হবে।');
    } else {
        flash('err', 'অজানা অনুরোধ।');
    }
    redirect(a_url('fb-settings'));
}

$callback = abs_url('/fb-webhook.php');
$redirect = abs_url('/jebu-cp-3hr3f/fb-settings');
$verify = trim(setting('meta_verify_token'));
$hasSecret = trim(setting('meta_app_secret')) !== '';
$mode = setting('meta_app_mode', 'development');

admin_head('Meta App সেটিংস');
?>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
  <?php tr_status('App Secret', $hasSecret, $hasSecret ? 'সংরক্ষিত (মান দেখানো হয় না)' : 'নেই'); ?>
  <?php tr_status('Verify Token', $verify !== '', $verify !== '' ? 'সংরক্ষিত' : 'নেই'); ?>
  <?php tr_status('App mode', $mode === 'live', $mode === 'live' ? 'Live' : 'Development'); ?>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>Meta App কনফিগারেশন</h2>
  <p class="muted" style="margin-top:0">এখানে শুধু সাধারণ তথ্য রাখুন। App Secret নিচে আলাদাভাবে বসান। Token বা secret চ্যাটে পাঠাবেন না।</p>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="app_save">
    <?php tr_field('meta_app_name', 'Meta App Name', setting('meta_app_name'), '', 'text', 'যেমন: Dr Jebu Page Manager'); ?>
    <?php tr_field('meta_app_id', 'Meta App ID', setting('meta_app_id'), 'Meta Developer → App settings-এ পাবেন।', 'text', 'সংখ্যা'); ?>
    <?php tr_field('meta_app_mode', 'App mode (development / live)', $mode, 'Live করার আগে App Review ও Business Verification লাগতে পারে।', 'text', 'development'); ?>
    <?php tr_field('meta_graph_version', 'Graph API ভার্সন', setting('meta_graph_version', 'v26.0'), 'যেমন v26.0', 'text', 'v26.0'); ?>
    <?php tr_field('meta_business_id', 'Business Portfolio ID (ঐচ্ছিক)', setting('meta_business_id'), '', 'text', ''); ?>
    <?php tr_field('meta_contact_email', 'App contact email', setting('meta_contact_email'), '', 'text', 'name@example.com'); ?>
    <?php tr_field('meta_privacy_url', 'Privacy Policy URL', setting('meta_privacy_url'), 'https:// দিয়ে শুরু হবে।', 'text', 'https://drjebunnesa.online/privacy-policy'); ?>
    <?php tr_field('meta_terms_url', 'Terms of Service URL', setting('meta_terms_url'), 'https:// দিয়ে শুরু হবে।', 'text', 'https://drjebunnesa.online/terms'); ?>
    <?php tr_field('meta_deletion_url', 'Data Deletion Instructions URL', setting('meta_deletion_url'), 'https:// দিয়ে শুরু হবে।', 'text', 'https://drjebunnesa.online/privacy-policy'); ?>
    <p class="muted">OAuth Redirect URI (Meta-তে এটি বসান): <code><?= h($redirect) ?></code></p>
    <button class="btn" type="submit">Meta App সেটিংস সংরক্ষণ করুন</button>
  </form>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>App Secret</h2>
  <p class="muted" style="margin-top:0">নিরাপত্তার জন্য সংরক্ষিত মান আর দেখানো হয় না। নতুন মান লিখলে আগেরটা বদলে যাবে।</p>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="secret_save">
    <label>নতুন App Secret<input type="password" name="meta_app_secret" value="" autocomplete="new-password" placeholder="<?= $hasSecret ? 'সংরক্ষিত আছে (খালি রাখলে বদলাবে না)' : 'এখানে বসান' ?>"></label>
    <button class="btn" type="submit">App Secret সংরক্ষণ করুন</button>
  </form>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>Webhook</h2>
  <p class="muted" style="margin-top:0">Meta-র Webhook সেটিংসে Callback URL ও Verify Token একই দিন।</p>
  <p>Callback URL: <code id="cb-url"><?= h($callback) ?></code>
    <button type="button" class="btn ghost" onclick="fbCopy('cb-url')">কপি করুন</button></p>
  <p>Verify Token: <code id="vt"><?= h($verify !== '' ? $verify : 'এখনো তৈরি হয়নি') ?></code>
    <?php if ($verify !== ''): ?><button type="button" class="btn ghost" onclick="fbCopy('vt')">কপি করুন</button><?php endif; ?></p>
  <form method="post" class="form" onsubmit="return confirm('নতুন Verify Token তৈরি করলে Meta-তে পুরনোটা আর কাজ করবে না। চালিয়ে যাবেন?');"><?= csrf_field() ?><input type="hidden" name="act" value="verify_gen">
    <button class="btn" type="submit">নতুন Verify Token তৈরি করুন</button>
  </form>
  <p class="muted">Webhook-এর live যাচাই (Meta থেকে ইভেন্ট আসা, subscription) এখনো করা হয়নি। এটি Meta-তে কনফিগার করার পর আলাদাভাবে পরীক্ষা করতে হবে।</p>
</div>
<script>
function fbCopy(id){var t=document.getElementById(id).textContent;if(navigator.clipboard){navigator.clipboard.writeText(t);}}
</script>
<?php admin_foot();
