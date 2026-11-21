<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
$u = require_perm('settings');

/*
 * Facebook Page preflight. Reads what is saved and reports honestly.
 * Nothing here calls Meta: a value is only PASS after a live check that has actually run, and that check is not in this version.
 * Tokens and secrets are never printed; the page shows only whether they are saved.
 */
$pageId = trim(setting('meta_page_id'));
$hasToken = trim(setting('meta_page_token')) !== '';
$cleared = setting('meta_pixel_privacy_cleared') === '1';

$rows = [
    ['Business Access', 'NOT CONFIGURED', 'Business Manager-এ admin access, live check এখনো নেই।'],
    ['Domain Verification', 'NOT CONFIGURED', 'Business Settings → Brand safety → Domains থেকে দেখতে হবে।'],
    ['App Status', 'NOT CONFIGURED', 'Meta Developer app কি Development না Live, সেটা live check ছাড়া জানা যায় না।'],
    ['App Review Status', 'NOT CONFIGURED', 'কোন permission অনুমোদিত, সেটা Meta Dashboard থেকে দেখতে হবে।'],
    ['Page ID', $pageId !== '' && ctype_digit($pageId) ? 'WARNING' : 'NOT CONFIGURED', $pageId !== '' ? 'সংরক্ষিত, কিন্তু Meta-তে যাচাই হয়নি।' : 'Facebook Page ID বসানো হয়নি।'],
    ['Page Token', $hasToken ? 'WARNING' : 'NOT CONFIGURED', $hasToken ? 'সংরক্ষিত (encrypted), কিন্তু Meta-তে যাচাই হয়নি।' : 'Page access token বসানো হয়নি।'],
    ['Granted Permissions', 'NOT CONFIGURED', 'pages_show_list, pages_read_engagement, pages_read_user_content, pages_manage_posts, pages_manage_engagement, pages_manage_metadata, pages_messaging, এগুলোর live status নেই।'],
    ['Page Task Access', 'NOT CONFIGURED', 'CREATE_CONTENT, MODERATE, MESSAGING এর live status নেই।'],
    ['Webhook App Subscription', 'NOT CONFIGURED', 'App Dashboard-এ feed subscription দেখতে হবে।'],
    ['Webhook Page Subscription', 'NOT CONFIGURED', '/{page_id}/subscribed_apps দিয়ে যাচাই করতে হবে।'],
    ['Facebook Publishing Test', $hasToken ? 'NOT CONFIGURED' : 'BLOCKED', $hasToken ? 'Live পোস্ট পরীক্ষা এখনো চালানো হয়নি।' : 'Page token ছাড়া পরীক্ষা হবে না।'],
    ['Comment Reply Test', $hasToken ? 'NOT CONFIGURED' : 'BLOCKED', 'একটি বাস্তব test comment দিয়ে পরীক্ষা করতে হবে।'],
    ['Messenger Test', $hasToken ? 'NOT CONFIGURED' : 'BLOCKED', 'pages_messaging ও MESSAGING task access লাগবে।'],
    ['Pixel/CAPI Privacy Review', $cleared ? 'WARNING' : 'BLOCKED', $cleared ? 'Owner পরিষ্কার করেছেন। Event পাঠানোর আগে আলাদাভাবে যাচাই করুন।' : 'Medical privacy review শেষ না হওয়া পর্যন্ত Pixel ও CAPI বন্ধ থাকবে।'],
];

function fbp_tone(string $s): string {
    return match ($s) { 'PASS' => 'ok', 'WARNING' => 'warn', 'BLOCKED' => 'bad', default => 'off' };
}

admin_head('Facebook Preflight');
?>
<div class="panel">
  <h2>Facebook Page যাচাই</h2>
  <p class="muted" style="margin-top:0">এই পাতা শুধু যা সংরক্ষিত আছে তা দেখায়। Meta-তে কোনো কল করে না। কোনো status PASS দেখাবে না যতক্ষণ না live পরীক্ষা চলেছে। Token বা secret চ্যাটে পাঠাবেন না; সেটিংস পাতায় সরাসরি বসান।</p>
  <table class="table">
    <thead><tr><th>বিষয়</th><th>Status</th><th>বিবরণ</th></tr></thead>
    <tbody>
<?php foreach ($rows as [$label, $st, $note]): ?>
      <tr><td><?= h($label) ?></td><td><?= tr_badge($st, fbp_tone($st)) ?></td><td class="muted"><?= h($note) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php admin_foot();
