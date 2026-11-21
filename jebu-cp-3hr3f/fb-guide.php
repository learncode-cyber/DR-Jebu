<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_perm('settings');
$steps = [
    ['Meta Developer অ্যাকাউন্ট খুলুন', 'developers.facebook.com-এ আপনার Facebook অ্যাকাউন্ট দিয়ে Developer হিসেবে নিবন্ধন করুন।', 'https://developers.facebook.com/'],
    ['Meta App তৈরি করুন', 'Business use case বেছে নতুন App বানান। App ID ও App Secret App settings-এ পাবেন।', 'https://developers.facebook.com/docs/development/create-an-app/'],
    ['App ID বসান', 'এই পাতার "Meta App কনফিগারেশন"-এ App ID ও নাম লিখে সংরক্ষণ করুন।', ''],
    ['App Secret বসান', 'এই পাতার "App Secret" অংশে লিখুন। সংরক্ষিত হওয়ার পর মান আর দেখা যাবে না।', ''],
    ['OAuth Redirect URI দিন', 'এই পাতায় দেখানো Redirect URI Meta-র সেটিংসে বসান।', ''],
    ['Webhook যোগ করুন', 'App-এর Webhooks অংশে Page সাবস্ক্রিপশন চালু করুন।', 'https://developers.facebook.com/docs/graph-api/webhooks/'],
    ['Callback URL ও Verify Token দিন', 'এই পাতার Webhook অংশের Callback URL ও Verify Token কপি করে Meta-তে বসান।', ''],
    ['Page ফিল্ড সাবস্ক্রাইব করুন', 'feed ও messages সাবস্ক্রিপশন চালু করুন।', 'https://developers.facebook.com/docs/graph-api/webhooks/getting-started/webhooks-for-pages/'],
    ['Permission যোগ করুন', 'pages_show_list, pages_read_engagement, pages_manage_posts, pages_read_user_content, pages_manage_engagement, pages_manage_metadata, pages_messaging। প্রয়োজন অনুযায়ী App Review লাগবে।', 'https://developers.facebook.com/docs/permissions/reference/'],
    ['Page Access Token নিন', 'Page admin হিসেবে Page-এর token নিন। এটি Settings → Facebook Page ID ও token-এ বসান।', 'https://developers.facebook.com/docs/pages-api/getting-started/'],
    ['Test করুন', 'Permissions & Diagnostics পাতায় প্রতিটি পরীক্ষা চালান। PASS না হলে Errors & Logs দেখুন।', ''],
    ['App Review ও Business Verification', 'প্রয়োজনে Meta-র নির্দেশ অনুযায়ী সম্পূর্ণ করুন। তারপর App mode Live করুন।', 'https://developers.facebook.com/docs/app-review/'],
];
admin_head('Facebook সেটআপ গাইড');
?>
<div class="panel">
  <h2>Facebook সেটআপ গাইড</h2>
  <p class="muted" style="margin-top:0">Meta-র মেনুর নাম বদলাতে পারে। মেনু না মিললে Meta-র অফিসিয়াল ডকুমেন্টেশনের লিংক ব্যবহার করুন।</p>
  <ol style="line-height:1.9">
<?php foreach ($steps as [$t, $d, $link]): ?>
    <li><b><?= h($t) ?></b><br><span class="muted"><?= h($d) ?></span><?php if ($link !== ''): ?><br><a href="<?= h($link) ?>" target="_blank" rel="noopener">অফিসিয়াল ডকুমেন্ট</a><?php endif; ?></li>
<?php endforeach; ?>
  </ol>
</div>
<?php admin_foot();
