<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';

if (admin_user()) {
    redirect(a_url(''));
}
if ((int) q_val('SELECT COUNT(*) FROM users') === 0) {
    redirect(a_url('setup'));
}
$error = '';
if (is_post()) {
    csrf_require();
    $ip = client_ip();
    $since = date('Y-m-d H:i:s', time() - 900);
    $recent = (int) q_val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?', [$ip, $since]);
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    if ($recent >= 5) {
        $error = 'অনেকবার ভুল চেষ্টা হয়েছে। ১৫ মিনিট পর আবার চেষ্টা করুন।';
    } else {
        $u = q_one('SELECT * FROM users WHERE email = ?', [$email]);
        $hash = $u['password_hash'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
        $ok = password_verify($pass, $hash) && $u && (int) $u['is_active'] === 1;
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            q_exec('UPDATE users SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
            q_exec('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            log_activity('login', $u['email'], (int) $u['id']);
            redirect(a_url(''));
        }
        q_exec('INSERT INTO login_attempts (ip, email) VALUES (?, ?)', [$ip, mb_substr($email, 0, 190)]);
        log_activity('login.failed', $email);
        $error = 'ইমেইল বা পাসওয়ার্ড সঠিক নয়।';
    }
}
admin_head('লগইন');
?>
<form method="post" class="panel narrow" autocomplete="on">
<?= csrf_field() ?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <label>ইমেইল<input type="email" name="email" required autofocus autocomplete="username"></label>
  <label>পাসওয়ার্ড<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn" type="submit">প্রবেশ করুন</button>
</form>
<?php admin_foot();
