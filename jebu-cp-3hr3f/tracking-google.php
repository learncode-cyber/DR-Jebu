<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
$u = require_perm('settings');
tr_handle('gads', 'tracking-google', (int) $u['id']);

$acc = tracking_all('gads')[0] ?? null;
$convCode = ($acc !== null && (string) $acc['extra'] !== '')
    ? "gtag('event', 'conversion', {\n  'send_to': '" . $acc['ext_id'] . '/' . $acc['extra'] . "',\n  'value': 0,\n  'currency': 'BDT'\n});"
    : '// Google Ads ID ও Conversion label সংরক্ষণ করলে কোড দেখাবে';

admin_head('Google Ads ট্র্যাকিং');
tr_page('gads', 'Google Ads ট্র্যাকিং', [
    ['Google Ads ID নিন', 'ads.google.com → উপরের ডানদিকে আপনার অ্যাকাউন্ট ID (১০ অঙ্ক, AW- যোগ করুন)। সাধারণত AW-1234567890 আকারে।'],
    ['Conversion label নিন', 'Goals → Conversions → আপনার লিড কনভার্সন → Tag setup → "Use Google tag" → Conversion label (যেমন AbCdEfGhIjKl)।'],
    ['অ্যাকাউন্ট যোগ করুন', 'উপরের ফর্মে ID ও label দিয়ে যোগ করুন। একাধিক Google Ads অ্যাকাউন্ট থাকলে প্রতিটির জন্য আলাদা যোগ করুন।'],
    ['যাচাই করুন', 'সাইটে ফর্ম জমা দিয়ে ধন্যবাদ পেজ খুলুন। Google Ads → Conversions-এ কিছুক্ষণ পর কনভার্সন দেখাবে (Status: Recording conversions)।'],
], [
    'ext_label' => 'Google Ads ID', 'ext_help' => 'AW- দিয়ে শুরু। কনভার্সন ট্র্যাকিংয়ের জন্য।', 'ext_ph' => 'AW-1234567890',
    'secret_label' => null, 'secret_help' => '', 'secret_ph' => '',
    'extra_label' => 'লিড কনভার্সন Label', 'extra_help' => 'Google Ads → Conversion → Tag setup-এর label। ফর্ম জমার পর এই কনভার্সন যায়।', 'extra_ph' => 'AbCdEfGhIjKl',
    'server' => false, 'test' => false,
], [
    ['tr-ads-conv', 'লিড কনভার্সন কোড (ধন্যবাদ পেজে স্বয়ংক্রিয়)', $convCode,
        'ফর্ম জমা হওয়ার পর ধন্যবাদ পেজে এই কনভার্সন নিজে থেকে যায়। প্রতিটি অ্যাকাউন্টের label আলাদাভাবে যায়। কপি করার দরকার নেই।'],
], 'tracking-google');
admin_foot();
