<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
$u = require_perm('settings');
tr_handle('meta', 'tracking-meta', (int) $u['id']);

$first = tracking_all('meta')[0]['ext_id'] ?? '123456789012345';
$serverSample = json_encode([
    'data' => [[
        'event_name' => 'Lead', 'event_time' => 1730000000, 'event_id' => '<ব্রাউজার ও সার্ভার একই ID>',
        'action_source' => 'website', 'event_source_url' => (string) abs_url('/appointment'),
        'user_data' => ['em' => ['<sha256(email)>'], 'ph' => ['<sha256(8801XXXXXXXXX)>'], 'client_ip_address' => '<ip>', 'client_user_agent' => '<ua>'],
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

admin_head('Meta Ads ট্র্যাকিং');
tr_page('meta', 'Meta Ads ট্র্যাকিং', [
    ['Pixel ID নিন', 'Meta Business Suite → Events Manager → Data sources → আপনার Pixel → Settings-এর উপরে Pixel ID (১০-২০ সংখ্যা)।'],
    ['Access Token তৈরি করুন', 'Events Manager → Pixel → Settings → Conversions API → "Generate access token"। টোকেন একবারই দেখানো হয়, সাথে সাথে কপি করুন।'],
    ['Test event code (ঐচ্ছিক)', 'Events Manager → Test events-এ "TEST..." কোড দেখাবে। এটি দিলে ইভেন্ট শুধু Test events-এ যাবে। লাইভে যাওয়ার আগে খালি করুন।'],
    ['অ্যাকাউন্ট যোগ ও টেস্ট', 'উপরের ফর্মে Pixel ID, Token দিয়ে যোগ করুন। তারপর "টেস্ট" চাপলে Meta-র প্রতিক্রিয়া দেখাবে।'],
    ['লাইভ চালু করুন', '"সার্ভার-সাইড ইভেন্ট চালু" টিক দিয়ে সেভ করুন। লিড তখন ব্রাউজার ও সার্ভার দুদিক থেকে যাবে, একই event_id-তে ডুপ্লিকেট হবে না।'],
], [
    'ext_label' => 'Pixel ID', 'ext_help' => 'শুধু সংখ্যা। ব্রাউজারে পিক্সেল বসাতে ও সার্ভার ইভেন্টের ঠিকানায় লাগে।', 'ext_ph' => 'যেমন 123456789012345',
    'secret_label' => 'Conversions API Access Token', 'secret_help' => 'সার্ভার থেকে ইভেন্ট পাঠাতে লাগে। গোপন রাখুন, পেজে দেখানো হয় না।', 'secret_ph' => 'EAAG…',
    'extra_label' => 'Test event code (ঐচ্ছিক)', 'extra_help' => 'Test events থেকে কপি করা TEST… কোড। লাইভে খালি রাখুন।', 'extra_ph' => 'TEST12345',
    'server' => true, 'test' => true,
], [
    ['tr-meta-server', 'সার্ভার ইভেন্ট (Conversions API) কাঠামো', (string) $serverSample,
        'প্রতিটি সক্রিয় ও সার্ভার-চালু Pixel-এর জন্য আলাদা করে একই event_id-তে ইভেন্ট যায়। ইমেইল ও ফোন SHA-256 দিয়ে হ্যাশ করে পাঠানো হয়। Endpoint: https://graph.facebook.com/' . TRACK_META_GRAPH_VERSION . '/{pixel-id}/events'],
    ['tr-meta-head', 'ব্রাউজার পিক্সেল কোড (স্বয়ংক্রিয়)', $first === '' ? '' : "fbq('init', '" . $first . "');\nfbq('track', 'PageView');",
        'সব সক্রিয় Pixel সাইটের <head>-এ নিজে থেকেই বসে। নিচে প্রথম Pixel-এর উদাহরণ দেখানো হলো।'],
], 'tracking-meta');
admin_foot();
