<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_perm('settings');

// LinkedIn returns here after consent. The state must match the one this admin session started with.
$expected = (string) ($_SESSION['li_state'] ?? '');
unset($_SESSION['li_state']);
$state = (string) ($_GET['state'] ?? '');
if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
    flash('err', 'LinkedIn সংযোগ বাতিল হয়েছে (নিরাপত্তা যাচাই ব্যর্থ)। আবার চেষ্টা করুন।');
    redirect(a_url('linkedin'));
}
if (isset($_GET['error'])) {
    flash('err', 'LinkedIn অনুমতি দেওয়া হয়নি।');
    redirect(a_url('linkedin'));
}
[$ok, $msg] = li_complete_connect((string) ($_GET['code'] ?? ''));
flash($ok ? 'ok' : 'err', $msg);
redirect(a_url('linkedin'));
