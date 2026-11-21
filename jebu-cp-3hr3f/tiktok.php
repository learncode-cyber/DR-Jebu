<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
tt_tables();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'connect') {
        if (tt_cfg()['client_key'] === '') {
            flash('err', 'আগে সেটিংসে TikTok Client Key ও Secret বসান।');
            redirect(a_url('tiktok'));
        }
        $_SESSION['tt_state'] = bin2hex(random_bytes(16));
        redirect(tt_authorize_url((string) $_SESSION['tt_state']));
    } elseif ($act === 'disconnect') {
        tt_disconnect();
        flash('ok', 'TikTok সংযোগ মুছে ফেলা হয়েছে।');
    } elseif ($act === 'upload') {
        $id = tt_draft_save((string) ($_POST['title'] ?? ''), $_FILES['video'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        flash($id > 0 ? 'ok' : 'err', $id > 0 ? 'ভিডিও ড্রাফট হিসেবে সংরক্ষিত হয়েছে (ব্যক্তিগত মোডে)।' : 'ভিডিও সংরক্ষণ হয়নি: MP4/MOV/WebM, সর্বোচ্চ ২০ MB হতে হবে।');
    } elseif ($act === 'post') {
        [$ok, $msg] = tt_post((int) ($_POST['id'] ?? 0));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'delete') {
        $row = q_one("SELECT file_name FROM tiktok_posts WHERE id = ? AND status <> 'posted'", [(int) ($_POST['id'] ?? 0)]);
        if ($row) {
            @unlink(tt_dir() . '/' . basename((string) $row['file_name']));
            q_exec('DELETE FROM tiktok_posts WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        }
        flash('ok', 'ভিডিও মুছে ফেলা হয়েছে।');
    }
    redirect(a_url('tiktok'));
}

tt_expiry_check();
admin_head('TikTok ভিডিও');
$connected = tt_connected();
$c = tt_cfg();
$rows = q_all('SELECT * FROM tiktok_posts ORDER BY id DESC LIMIT 50');
?>
<div class="cards">
  <div class="stat <?= $connected ? '' : 'warn' ?>"><b><?= $connected ? 'সংযুক্ত' : 'সংযুক্ত নয়' ?></b><span>TikTok অ্যাকাউন্ট</span></div>
  <div class="stat"><b><?= count(array_filter($rows, static fn($r) => $r['status'] === 'draft')) ?></b><span>ভিডিও ড্রাফট</span></div>
</div>

<div class="panel">
  <h2>সংযোগ</h2>
  <p>TikTok Developer App-এ এই Redirect URL যোগ করুন (হুবহু):</p>
  <pre><code><?= h(tt_redirect_uri()) ?></code></pre>
  <?php if ($connected): ?>
    <p class="muted">সংযোগের মেয়াদ: <?= h(date('d.m.Y', $c['refresh_expires_at'])) ?> পর্যন্ত (টোকেন নিজে থেকে নবায়ন হয়)।</p>
    <form method="post" class="inline"><?= csrf_field() ?><button class="btn ghost" name="act" value="disconnect" type="submit">সংযোগ মুছুন</button></form>
  <?php endif; ?>
  <form method="post" class="inline" style="margin-top:.5rem"><?= csrf_field() ?><button class="btn" name="act" value="connect" type="submit"><?= $connected ? 'আবার সংযুক্ত করুন' : 'TikTok-এ সংযুক্ত করুন' ?></button></form>
  <p class="muted"><b>গুরুত্বপূর্ণ:</b> অনুমোদনহীন অ্যাপ থেকে শুধু ব্যক্তিগত (Only me) ভিডিও যায়। জনসমক্ষে প্রকাশের আগে TikTok-এর অডিট পাস করতে হবে। নিজে থেকে কিছু পোস্ট হয় না।</p>
</div>

<div class="panel">
  <h2>নতুন ভিডিও ড্রাফট</h2>
  <form method="post" class="form" enctype="multipart/form-data"><?= csrf_field() ?>
    <label>শিরোনাম / ক্যাপশন (ঐচ্ছিক)<input type="text" name="title" maxlength="2200"></label>
    <label>ভিডিও ফাইল (MP4, MOV বা WebM, সর্বোচ্চ ২০ MB)<input type="file" name="video" accept="video/mp4,video/quicktime,video/webm" required></label>
    <p class="muted">রোগীর পরিচয় বা চিকিৎসা নির্ণয় দেখায় এমন ভিডিও দেবেন না।</p>
    <button class="btn" name="act" value="upload" type="submit">ড্রাফট সংরক্ষণ</button>
  </form>
</div>

<div class="panel">
  <h2>ভিডিওর তালিকা</h2>
  <?php if ($rows): ?>
  <div class="tbl-wrap"><table><thead><tr><th>তারিখ</th><th>ক্যাপশন</th><th>আকার</th><th>অবস্থা</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h(date('d.m H:i', strtotime((string) $r['created_at']))) ?></td>
      <td><?= h((string) $r['title']) ?></td>
      <td><?= h(number_format($r['file_size'] / 1048576, 1)) ?> MB</td>
      <td><?= h(['draft' => 'ড্রাফট', 'sent' => 'পাঠানো হয়েছে', 'posted' => 'পোস্ট হয়েছে', 'failed' => 'ব্যর্থ'][$r['status']] ?? $r['status']) ?><?= $r['error'] ? '<br><small class="muted">' . h((string) $r['error']) . '</small>' : '' ?></td>
      <td>
        <?php if ($r['status'] !== 'posted'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn" name="act" value="post" type="submit"<?= $connected ? '' : ' disabled' ?>>পোস্ট করুন</button>
          <button class="btn ghost" name="act" value="delete" type="submit">মুছুন</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="muted">এখনো কোনো ভিডিও নেই।</p><?php endif; ?>
</div>
<?php admin_foot();
