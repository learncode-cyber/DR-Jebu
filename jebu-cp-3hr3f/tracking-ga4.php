<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
$u = require_perm('settings');
tr_handle('ga4', 'tracking-ga4', (int) $u['id']);

$first = tracking_all('ga4')[0]['ext_id'] ?? 'G-XXXXXXXXXX';
$gtagSample = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $first . '"></script>' . "\n"
    . "<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n  gtag('config', '" . $first . "');\n</script>";
$mpSample = json_encode([
    'client_id' => '<_ga cookie থেকে>',
    'events' => [['name' => 'generate_lead', 'params' => ['lead_type' => 'appointment', 'engagement_time_msec' => 100]]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

admin_head('Google Analytics ট্র্যাকিং');
tr_page('ga4', 'Google Analytics (GA4) ট্র্যাকিং', [
    ['Measurement ID নিন', 'analytics.google.com → Admin → Data streams → আপনার ওয়েব স্ট্রিম → Measurement ID (G- দিয়ে শুরু)।'],
    ['API Secret তৈরি করুন', 'একই স্ট্রিমে → Measurement Protocol API secrets → Create। নাম দিয়ে কপি করুন। সার্ভার থেকে ইভেন্ট পাঠাতে লাগে।'],
    ['অ্যাকাউন্ট যোগ ও টেস্ট', 'উপরের ফর্মে Measurement ID ও API Secret দিয়ে যোগ করুন। "টেস্ট" চাপলে Google payload যাচাই করবে, কোনো ডাটা রেকর্ড হবে না।'],
    ['লাইভ চালু করুন', '"সার্ভার-সাইড ইভেন্ট চালু" টিক দিয়ে সেভ করুন। এরপর Analytics → Realtime-এ generate_lead দেখাবে।'],
    ['কনভার্সন হিসেবে চিহ্নিত করুন', 'Analytics → Admin → Events-এ generate_lead-কে Key event হিসেবে চিহ্নিত করুন। তারপর Google Ads-এ এটি ইমপোর্ট করা যাবে।'],
], [
    'ext_label' => 'GA4 Measurement ID', 'ext_help' => 'G- দিয়ে শুরু। ব্রাউজার ট্র্যাকিংয়ের জন্য।', 'ext_ph' => 'G-XXXXXXXXXX',
    'secret_label' => 'GA4 API Secret', 'secret_help' => 'সার্ভার-সাইড ইভেন্ট পাঠাতে লাগে। গোপন রাখুন, পেজে দেখানো হয় না।', 'secret_ph' => 'API secret',
    'extra_label' => null, 'extra_help' => '', 'extra_ph' => '',
    'server' => true, 'test' => true,
], [
    ['tr-ga-head', 'gtag ট্র্যাকিং কোড (স্বয়ংক্রিয়)', $gtagSample,
        'সব সক্রিয় GA4 ও Google Ads অ্যাকাউন্ট একই gtag লোডার দিয়ে সাইটের <head>-এ নিজে থেকেই বসে। নিচে প্রথমটির উদাহরণ।'],
    ['tr-ga-server', 'সার্ভার ইভেন্ট (Measurement Protocol) কাঠামো', (string) $mpSample,
        'Endpoint: https://www.google-analytics.com/mp/collect?measurement_id=…&api_secret=…। সার্ভার চালু থাকলে ব্রাউজার থেকে একই ইভেন্ট যায় না, যাতে ডাবল গণনা না হয়।'],
], 'tracking-ga4');
admin_foot();
