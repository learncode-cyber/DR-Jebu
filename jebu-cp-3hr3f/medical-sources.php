<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('content');
ms_tables();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'add') {
        [$ok, $msg] = ms_add_research((int) ($_POST['source_id'] ?? 0), (string) ($_POST['title'] ?? ''), (string) ($_POST['url'] ?? ''), (string) ($_POST['summary'] ?? ''), (string) ($_POST['research_date'] ?? ''));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'decide') {
        [$ok, $msg] = ms_decide((int) ($_POST['id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($u['name'] ?? 'doctor'));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'terms') {
        $ok = ms_set_terms((int) ($_POST['source_id'] ?? 0), (string) ($_POST['route'] ?? ''));
        flash($ok ? 'ok' : 'err', $ok ? 'উৎসের শর্ত যাচাই হিসেবে চিহ্নিত হয়েছে।' : 'সঠিক হয়নি।');
    }
    redirect(a_url('medical-sources'));
}

admin_head('অনুমোদিত মেডিকেল উৎস');
$sources = q_all('SELECT * FROM med_sources ORDER BY id');
$research = q_all('SELECT r.*, s.code FROM med_research r JOIN med_sources s ON s.id = r.source_id ORDER BY r.id DESC LIMIT 100');
$stBn = ['researched' => 'অনুমোদনের অপেক্ষায়', 'doctor_approved' => 'ডাক্তার অনুমোদিত', 'rejected' => 'বাতিল'];
$routeBn = ['pending_terms_check' => 'শর্ত যাচাই বাকি', 'official_api' => 'সরকারি/অফিসিয়াল API', 'official_rss' => 'অফিসিয়াল RSS', 'public_info' => 'পাবলিক তথ্য (শর্ত মেনে)'];
?>
<div class="panel">
  <h2>অনুমোদিত উৎস</h2>
  <p><small>এই তালিকার বাইরের কোনো উৎস ব্যবহার হবে না। কোনো উৎস থেকে স্বয়ংক্রিয়ভাবে তথ্য টানা হয় না। শর্ত নিজে পড়ে "শর্ত যাচাই হয়েছে" চিহ্নিত করুন।</small></p>
  <table class="table"><thead><tr><th>কোড</th><th>নাম</th><th>ডোমেইন</th><th>ব্যবহারের ধরন</th><th>শর্ত</th></tr></thead><tbody>
  <?php foreach ($sources as $s): ?>
    <tr>
      <td><?= h($s['code']) ?></td><td><?= h($s['name']) ?></td><td><?= h($s['host']) ?></td>
      <td><?= h($routeBn[$s['access_route']] ?? $s['access_route']) ?></td>
      <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="terms"><input type="hidden" name="source_id" value="<?= (int) $s['id'] ?>">
        <select name="route"><?php foreach ($routeBn as $k => $v): ?><option value="<?= h($k) ?>" <?= $s['access_route'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
        <button class="btn small">শর্ত যাচাই হয়েছে</button></form></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<div class="panel">
  <h2>নতুন গবেষণা নথিভুক্ত করুন</h2>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="add">
    <label>উৎস <select name="source_id" required><?php foreach ($sources as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h($s['code']) ?> — <?= h($s['name']) ?></option><?php endforeach; ?></select></label>
    <label>শিরোনাম <input name="title" maxlength="200" required></label>
    <label>লিংক (https, ওই উৎসের ডোমেইন) <input name="url" type="url" required></label>
    <label>সংক্ষিপ্ত সারাংশ (নিজের ভাষায়, উদ্ধৃতি নয়) <textarea name="summary" rows="3"></textarea></label>
    <label>গবেষণার তারিখ <input type="date" name="research_date" value="<?= date('Y-m-d') ?>" required></label>
    <button class="btn">নথিভুক্ত করুন</button>
  </form>
  <p><small>ব্লগ বা পোস্টে ব্যবহার হবে শুধু "ডাক্তার অনুমোদিত" আইটেম।</small></p>
</div>
<div class="panel">
  <h2>গবেষণা ও ডাক্তারের অনুমোদন</h2>
  <table class="table"><thead><tr><th>উৎস</th><th>শিরোনাম</th><th>তারিখ</th><th>অবস্থা</th><th></th></tr></thead><tbody>
  <?php foreach ($research as $r): ?>
    <tr>
      <td><?= h($r['code']) ?></td>
      <td><a href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['title']) ?></a><br><small><?= h(mb_substr((string) $r['summary'], 0, 140)) ?></small></td>
      <td><?= h($r['research_date']) ?></td>
      <td><?= h($stBn[$r['status']] ?? $r['status']) ?></td>
      <td>
        <?php if ($r['status'] !== 'doctor_approved'): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="decide"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="decision" value="doctor_approved"><button class="btn small">অনুমোদন</button></form>
        <?php endif; ?>
        <?php if ($r['status'] !== 'rejected'): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="decide"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="decision" value="rejected"><button class="btn small ghost">বাতিল</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$research): ?><tr><td colspan="5">এখনো কোনো গবেষণা নেই।</td></tr><?php endif; ?>
  </tbody></table>
</div>
<?php admin_foot();
