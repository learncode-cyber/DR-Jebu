<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';

// One-time first-admin creation. Disabled forever once any user exists.
if ((int) q_val('SELECT COUNT(*) FROM users') > 0) {
    redirect(a_url('login'));
}
$error = '';
if (is_post()) {
    csrf_require();
    $key = (string) ($_POST['app_key'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    if (!hash_equals(APP_KEY, $key)) {
        $error = 'APP_KEY মেলেনি (app/config.php-এর মান দিন)।';
    } elseif (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'নাম ও সঠিক ইমেইল দিন।';
    } elseif (mb_strlen($pass) < 10) {
        $error = 'পাসওয়ার্ড কমপক্ষে ১০ অক্ষরের হতে হবে।';
    } else {
        $id = q_insert('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
        log_activity('setup.admin_created', $email, $id);
        flash('ok', 'অ্যাডমিন অ্যাকাউন্ট তৈরি হয়েছে। এখন লগইন করুন।');
        redirect(a_url('login'));
    }
}
admin_head('প্রথম অ্যাডমিন তৈরি');
?>
<form method="post" class="panel narrow">
<?= csrf_field() ?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <p class="muted">নিরাপত্তার জন্য <code>app/config.php</code>-এ দেওয়া <code>APP_KEY</code> লাগবে। এই পেজ প্রথম অ্যাডমিন তৈরির পর নিজে থেকেই বন্ধ হয়ে যাবে।</p>
  <label>APP_KEY<input type="password" name="app_key" required autocomplete="off"></label>
  <label>নাম<input type="text" name="name" required></label>
  <label>ইমেইল<input type="email" name="email" required autocomplete="username"></label>
  <label>পাসওয়ার্ড (১০+ অক্ষর)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <button class="btn" type="submit">অ্যাডমিন তৈরি করুন</button>
</form>
<?php admin_foot();
