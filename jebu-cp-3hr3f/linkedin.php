<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
li_tables();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'connect') {
        $_SESSION['li_state'] = bin2hex(random_bytes(16));
        if (li_cfg()['client_id'] === '') {
            flash('err', 'আগে সেটিংসে LinkedIn Client ID ও Secret বসান।');
            redirect(a_url('linkedin'));
        }
        redirect(li_authorize_url((string) $_SESSION['li_state']));
    } elseif ($act === 'disconnect') {
        li_disconnect();
        flash('ok', 'LinkedIn সংযোগ মুছে ফেলা হয়েছে।');
    } elseif ($act === 'draft') {
        $id = li_draft_save((string) ($_POST['title'] ?? ''), (string) ($_POST['commentary'] ?? ''), (string) ($_POST['link_url'] ?? ''));
        flash($id > 0 ? 'ok' : 'err', $id > 0 ? 'ড্রাফট সংরক্ষিত হয়েছে। পর্যালোচনা করে পোস্ট করুন।' : 'ড্রাফট সংরক্ষণ হয়নি: লেখা খালি, অতিরিক্ত লম্বা বা লিংক সঠিক নয়।');
    } elseif ($act === 'post') {
        [$ok, $msg] = li_post((int) ($_POST['id'] ?? 0));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'delete') {
        q_exec("DELETE FROM linkedin_posts WHERE id = ? AND status <> 'posted'", [(int) ($_POST['id'] ?? 0)]);
        flash('ok', 'ড্রাফট মুছে ফেলা হয়েছে।');
    }
    redirect(a_url('linkedin'));
}

li_expiry_check();
admin_head('LinkedIn পোস্ট');
$c = li_cfg();
$connected = li_connected();
$rows = q_all('SELECT * FROM linkedin_posts ORDER BY id DESC LIMIT 50');
?>
<div class="cards">
  <div class="stat <?= $connected ? '' : 'warn' ?>"><b><?= $connected ? 'সংযুক্ত' : 'সংযুক্ত নয়' ?></b><span>LinkedIn প্রোফাইল</span></div>
  <div class="stat"><b><?= count(array_filter($rows, static fn($r) => $r['status'] === 'draft')) ?></b><span>ড্রাফট অপেক্ষায়</span></div>
</div>

<div class="panel">
  <h2>সংযোগ</h2>
  <p>LinkedIn Developer App-এ এই Redirect URL যোগ করুন (হুবহু):</p>
  <pre><code><?= h(li_redirect_uri()) ?></code></pre>
  <?php if ($connected): ?>
    <p class="muted">মেয়াদ শেষ: <?= h(date('d.m.Y', $c['expires_at'])) ?> (প্রতি ৬০ দিনে আবার সংযুক্ত করতে হবে)।</p>
    <form method="post" class="inline"><?= csrf_field() ?><button class="btn ghost" name="act" value="disconnect" type="submit">সংযোগ মুছুন</button></form>
  <?php endif; ?>
  <form method="post" class="inline" style="margin-top:.5rem"><?= csrf_field() ?><button class="btn" name="act" value="connect" type="submit"><?= $connected ? 'আবার সংযুক্ত করুন' : 'LinkedIn-এ সংযুক্ত করুন' ?></button></form>
  <p class="muted">এখানে কিছুই নিজে থেকে পোস্ট হয় না। প্রতিটি পোস্ট আপনি ড্রাফট দেখে নিজে "পোস্ট করুন" চাপলে যাবে।</p>
</div>

<div class="panel">
  <h2>নতুন ড্রাফট</h2>
  <form method="post" class="form"><?= csrf_field() ?>
    <label>শিরোনাম (ঐচ্ছিক)<input type="text" name="title" maxlength="200"></label>
    <label>লেখা (সর্বোচ্চ ২৯০০ অক্ষর)<textarea name="commentary" rows="6" maxlength="2900" required></textarea></label>
    <label>লিংক (ঐচ্ছিক, যেমন ব্লগের ঠিকানা)<input type="url" name="link_url" maxlength="500"></label>
    <p class="muted">চিকিৎসা বিষয়ক লেখায় রোগের নির্ণয় বা ওষুধের নাম দেবেন না।</p>
    <button class="btn" name="act" value="draft" type="submit">ড্রাফট সংরক্ষণ</button>
  </form>
</div>

<div class="panel">
  <h2>পোস্টের তালিকা</h2>
  <?php if ($rows): ?>
  <div class="tbl-wrap"><table><thead><tr><th>তারিখ</th><th>শিরোনাম / লেখা</th><th>অবস্থা</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h(date('d.m H:i', strtotime((string) $r['created_at']))) ?></td>
      <td><b><?= h((string) $r['title']) ?></b><br><small><?= h(mb_substr((string) $r['commentary'], 0, 120)) ?></small></td>
      <td><?= h(['draft' => 'ড্রাফট', 'posted' => 'পোস্ট হয়েছে', 'failed' => 'ব্যর্থ'][$r['status']] ?? $r['status']) ?><?= $r['error'] ? '<br><small class="muted">' . h((string) $r['error']) . '</small>' : '' ?></td>
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
  <?php else: ?><p class="muted">এখনো কোনো ড্রাফট নেই।</p><?php endif; ?>
</div>
<?php admin_foot();
