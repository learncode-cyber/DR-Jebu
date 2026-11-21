<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$me = require_perm('users');
if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'reception');
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($pass) < 10 || !isset(ROLE_LABELS[$role])) {
            flash('err', 'নাম, সঠিক ইমেইল, ১০+ অক্ষরের পাসওয়ার্ড ও রোল দিন।');
        } elseif (q_val('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
            flash('err', 'এই ইমেইল আগে থেকেই আছে।');
        } else {
            $id = q_insert('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
            log_activity('user.created', $email . ' (' . $role . ')', (int) $me['id']);
            flash('ok', 'ইউজার তৈরি হয়েছে।');
        }
    } elseif (in_array($act, ['toggle', 'role', 'reset'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $me['id'] && $act !== 'reset') {
            flash('err', 'নিজের অ্যাকাউন্টের রোল/স্ট্যাটাস এখান থেকে বদলানো যাবে না।');
        } elseif ($act === 'toggle') {
            q_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
            log_activity('user.toggled', '#' . $id, (int) $me['id']);
        } elseif ($act === 'role' && isset(ROLE_LABELS[(string) ($_POST['role'] ?? '')])) {
            q_exec('UPDATE users SET role = ? WHERE id = ?', [(string) $_POST['role'], $id]);
            log_activity('user.role', '#' . $id . ' → ' . $_POST['role'], (int) $me['id']);
        } elseif ($act === 'reset') {
            $pass = (string) ($_POST['password'] ?? '');
            if (mb_strlen($pass) < 10) { flash('err', 'পাসওয়ার্ড কমপক্ষে ১০ অক্ষর।'); }
            else { q_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]); log_activity('user.password_reset', '#' . $id, (int) $me['id']); flash('ok', 'পাসওয়ার্ড বদলানো হয়েছে।'); }
        }
    }
    redirect(a_url('users'));
}
$users = q_all('SELECT * FROM users ORDER BY id');
admin_head('ইউজার ও রোল');
?>
<div class="panel"><div class="tbl-wrap"><table><thead><tr><th>নাম</th><th>ইমেইল</th><th>রোল</th><th>সক্রিয়</th><th>সর্বশেষ লগইন</th><th>পাসওয়ার্ড রিসেট</th></tr></thead><tbody>
<?php foreach ($users as $r): ?>
<tr><td><?= h($r['name']) ?></td><td><?= h($r['email']) ?></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="role"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><select name="role" onchange="this.form.submit()"><?php foreach (ROLE_LABELS as $k => $l): ?><option value="<?= h($k) ?>" <?= $r['role'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></form></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="tag <?= $r['is_active'] ? 'on' : 'off' ?>" type="submit"><?= $r['is_active'] ? 'হ্যাঁ' : 'না' ?></button></form></td>
<td><?= h((string) $r['last_login_at']) ?></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="reset"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="password" name="password" placeholder="নতুন পাসওয়ার্ড" minlength="10" autocomplete="new-password"> <button class="btn sm" type="submit">বদলান</button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<form method="post" class="panel form"><?= csrf_field() ?><input type="hidden" name="act" value="create"><h2>নতুন ইউজার</h2>
  <label>নাম<input type="text" name="name" required></label>
  <label>ইমেইল<input type="email" name="email" required autocomplete="off"></label>
  <label>পাসওয়ার্ড (১০+)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <label>রোল<select name="role"><?php foreach (ROLE_LABELS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
  <p class="muted">অ্যাডমিন: সব কিছু · ম্যানেজার: লিড+কনটেন্ট+লগ · এডিটর: শুধু কনটেন্ট · চিকিৎসক/রিসিপশন: লিড</p>
  <button class="btn" type="submit">ইউজার যোগ করুন</button></form>
<?php admin_foot();
