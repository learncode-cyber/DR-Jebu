<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_login();
if (is_post()) {
    csrf_require();
    $cur = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    $row = q_one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
    if (!$row || !password_verify($cur, $row['password_hash'])) { flash('err', 'বর্তমান পাসওয়ার্ড সঠিক নয়।'); }
    elseif (mb_strlen($new) < 10) { flash('err', 'নতুন পাসওয়ার্ড কমপক্ষে ১০ অক্ষর।'); }
    else {
        q_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        log_activity('password.changed', '', (int) $u['id']);
        flash('ok', 'পাসওয়ার্ড বদলানো হয়েছে।');
    }
    redirect(a_url('account'));
}
admin_head('আমার অ্যাকাউন্ট');
?>
<form method="post" class="panel narrow form"><?= csrf_field() ?>
  <p><?= h($u['name']) ?> · <?= h($u['email']) ?> · <?= h(ROLE_LABELS[$u['role']] ?? '') ?></p>
  <label>বর্তমান পাসওয়ার্ড<input type="password" name="current" required autocomplete="current-password"></label>
  <label>নতুন পাসওয়ার্ড (১০+)<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
  <button class="btn" type="submit">পাসওয়ার্ড বদলান</button></form>
<?php admin_foot();
