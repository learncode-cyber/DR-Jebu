<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_perm('settings');

// TikTok returns here after consent. The state must match the one this admin session started with.
$expected = (string) ($_SESSION['tt_state'] ?? '');
unset($_SESSION['tt_state']);
$state = (string) ($_GET['state'] ?? '');
if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
    flash('err', 'TikTok সংযোগ বাতিল হয়েছে (নিরাপত্তা যাচাই ব্যর্থ)। আবার চেষ্টা করুন।');
    redirect(a_url('tiktok'));
}
if (isset($_GET['error'])) {
    flash('err', 'TikTok অনুমতি দেওয়া হয়নি।');
    redirect(a_url('tiktok'));
}
[$ok, $msg] = tt_complete_connect((string) ($_GET['code'] ?? ''));
flash($ok ? 'ok' : 'err', $msg);
redirect(a_url('tiktok'));
