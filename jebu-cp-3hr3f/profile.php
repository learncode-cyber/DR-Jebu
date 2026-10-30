<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_login();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? 'save');
    if ($act === 'remove_avatar') {
        q_exec('UPDATE users SET avatar = NULL WHERE id = ?', [(int) $u['id']]);
        log_activity('profile.avatar_removed', '', (int) $u['id']);
        flash('ok', 'প্রোফাইল ছবি সরানো হয়েছে।');
        redirect(a_url('profile'));
    }
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
    $err = '';
    $newAvatar = save_upload('avatar', $err);
    if ($name === '') { $err = $err !== '' ? $err : 'নাম লিখুন।'; }
    if ($err !== '') {
        flash('err', $err);
        redirect(a_url('profile'));
    }
    $sets = 'name = ?';
    $params = [$name];
    if ($newAvatar !== null) { $sets .= ', avatar = ?'; $params[] = $newAvatar; }
    $params[] = (int) $u['id'];
    q_exec('UPDATE users SET ' . $sets . ' WHERE id = ?', $params);
    log_activity('profile.updated', $newAvatar !== null ? 'avatar' : '', (int) $u['id']);
    flash('ok', 'প্রোফাইল আপডেট হয়েছে।');
    redirect(a_url('profile'));
}

admin_head('আমার প্রোফাইল');
?>
<div class="panel narrow" style="max-width:560px">
  <div class="tools" style="align-items:center;gap:1rem">
    <?php if (!empty($u['avatar'])): ?>
      <img src="<?= h(img_url((string) $u['avatar'])) ?>" alt="প্রোফাইল ছবি" style="width:96px;height:96px;border-radius:999px;object-fit:cover;border:2px solid var(--line)">
    <?php else: ?>
      <span style="width:96px;height:96px;border-radius:999px;background:var(--plum);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:700"><?= h(mb_strtoupper(mb_substr((string) $u['name'], 0, 2))) ?></span>
    <?php endif; ?>
    <div>
      <div style="font-weight:700;font-size:1.1rem"><?= h($u['name']) ?></div>
      <div class="muted"><?= h($u['email']) ?> · <?= h(ROLE_LABELS[$u['role']] ?? '') ?></div>
    </div>
  </div>
</div>
<form method="post" enctype="multipart/form-data" class="panel narrow form" style="max-width:560px"><?= csrf_field() ?>
  <label>নাম *<input type="text" name="name" value="<?= h((string) $u['name']) ?>" maxlength="100" required></label>
  <label>প্রোফাইল ছবি (JPG, PNG, WEBP বা GIF; সর্বোচ্চ ১০MB)
    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif"></label>
  <button class="btn" type="submit">সংরক্ষণ</button>
</form>
<?php if (!empty($u['avatar'])): ?>
<form method="post" class="panel narrow" style="max-width:560px" onsubmit="return confirm('ছবি সরাবেন?')"><?= csrf_field() ?>
  <input type="hidden" name="act" value="remove_avatar">
  <button class="btn ghost" type="submit">ছবি সরিয়ে ফেলুন</button>
</form>
<?php endif; ?>
<p><a class="abtn" href="<?= h(a_url('account')) ?>"><i class="fa-solid fa-key" aria-hidden="true"></i>পাসওয়ার্ড বদলান</a></p>
<?php admin_foot();
