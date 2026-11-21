<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('content');
blog_migrate_v109();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'request') {
        [$ok, $msg] = blog_request_draft((int) ($_POST['research_id'] ?? 0), (string) ($_POST['angle'] ?? ''));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'approve') {
        [$ok, $msg] = blog_doctor_approve((int) ($_POST['post_id'] ?? 0), (string) ($u['name'] ?? 'doctor'));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'collect') {
        $n = blog_collect_drafts();
        flash('ok', $n > 0 ? $n . 'টি নতুন খসড়া তৈরি হয়েছে।' : 'নতুন কোনো তৈরি খসড়া নেই।');
    } elseif ($act === 'cover') {
        [$ok, $msg] = blog_set_cover((int) ($_POST['post_id'] ?? 0), basename((string) ($_POST['file'] ?? '')), (string) ($_POST['cover_source'] ?? ''), (string) ($_POST['cover_credit'] ?? ''));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'fb') {
        [$ok, $msg] = blog_repurpose_fb((int) ($_POST['post_id'] ?? 0));
        flash($ok ? 'ok' : 'err', $msg);
    }
    redirect(a_url('blog-drafts'));
}

admin_head('ব্লগ খসড়া ও অনুমোদন');
$approved = q_all("SELECT r.id, r.title, s.name AS src_name FROM med_research r JOIN med_sources s ON s.id = r.source_id WHERE r.status = 'doctor_approved' ORDER BY r.id DESC");
$requests = q_all('SELECT q.*, r.title AS research_title FROM blog_draft_requests q JOIN med_research r ON r.id = q.med_research_id ORDER BY q.id DESC LIMIT 50');
$drafts = q_all("SELECT id, title, slug, status, doctor_approved_at, doctor_approved_by, body, med_research_id FROM blog_posts WHERE med_research_id IS NOT NULL ORDER BY id DESC LIMIT 100");
$stBn = ['requested' => 'তৈরি হচ্ছে', 'drafted' => 'খসড়া তৈরি', 'failed' => 'ব্যর্থ'];
?>
<div class="panel">
  <h2>নতুন খসড়ার অনুরোধ</h2>
  <?php if ($approved): ?>
  <p><small>শুধু ডাক্তার-অনুমোদিত গবেষণা থেকে খসড়া হবে। খসড়ায় সূত্রের নাম, শিরোনাম, লিংক ও তারিখ নিজে যুক্ত হবে। কিছু প্রকাশ হবে না; ডাক্তার অনুমোদন দিলেই প্রকাশ করা যাবে।</small></p>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="request">
    <label>গবেষণা <select name="research_id" required><?php foreach ($approved as $a): ?><option value="<?= (int) $a['id'] ?>"><?= h($a['src_name']) ?> — <?= h($a['title']) ?></option><?php endforeach; ?></select></label>
    <label>লেখার বিষয় <input name="angle" maxlength="200" required placeholder="যেমন: অনিয়মিত মাসিক: কখন চিকিৎসকের কাছে যাবেন"></label>
    <button class="btn">খসড়ার অনুরোধ দিন</button>
  </form>
  <?php else: ?><p class="muted">এখনো কোনো ডাক্তার-অনুমোদিত গবেষণা নেই। আগে "অনুমোদিত মেডিকেল উৎস" পাতায় অনুমোদন দিন।</p><?php endif; ?>
</div>

<div class="panel">
  <h2>খসড়ার অবস্থা</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="collect"><button class="btn ghost small">তৈরি খসড়াগুলো এখনই নিন</button></form>
  <?php if ($requests): ?>
  <div class="tbl-wrap"><table><thead><tr><th>গবেষণা</th><th>বিষয়</th><th>অবস্থা</th><th>ত্রুটি</th></tr></thead><tbody>
  <?php foreach ($requests as $q): ?>
    <tr><td><?= h($q['research_title']) ?></td><td><?= h($q['angle']) ?></td><td><?= h($stBn[$q['status']] ?? $q['status']) ?></td><td><?= h((string) $q['error']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="muted">এখনো কোনো অনুরোধ নেই।</p><?php endif; ?>
</div>

<div class="panel">
  <h2>খসড়া ও ডাক্তারের অনুমোদন</h2>
  <?php if ($drafts): ?>
  <div class="tbl-wrap"><table><thead><tr><th>লেখা</th><th>স্ট্যাটাস</th><th>অনুমোদন</th><th>বাকি দাবি (সম্পাদনা করুন)</th><th></th></tr></thead><tbody>
  <?php foreach ($drafts as $d): $risk = blog_risk_check((string) $d['body'] . ' ' . (string) $d['title']); $isApproved = (string) $d['doctor_approved_at'] !== ''; ?>
    <tr>
      <td><?= h($d['title']) ?></td>
      <td><?= $d['status'] === 'published' ? 'প্রকাশিত' : 'খসড়া' ?></td>
      <td><?= $isApproved ? '✅ ' . h($d['doctor_approved_by']) . ' (' . h(date('d.m.Y', strtotime((string) $d['doctor_approved_at']))) . ')' : 'অপেক্ষমাণ' ?></td>
      <td><?= $risk ? h(implode(', ', $risk)) : '—' ?></td>
      <td><?php if (!$isApproved): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="approve"><input type="hidden" name="post_id" value="<?= (int) $d['id'] ?>"><button class="btn small">ডাক্তার হিসেবে অনুমোদন</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p><small>অনুমোদন দিলে লেখাটি প্রকাশ করা যাবে। প্রকাশ "ব্লগ" পাতা থেকে করুন।</small></p>
  <?php else: ?><p class="muted">এখনো কোনো খসড়া নেই।</p><?php endif; ?>
</div>
<?php
$library = upload_files();
$approvedPosts = q_all("SELECT id, title, cover_image, cover_source, cover_credit FROM blog_posts WHERE doctor_approved_at IS NOT NULL ORDER BY id DESC LIMIT 100");
?>
<div class="panel">
  <h2>কভার ছবি (নিজের লাইব্রেরি থেকে)</h2>
  <p><small>এখানে শুধু আপনার আপলোড করা ছবি দেখাচ্ছে। ছবি খোঁজা বা অন্য সাইট থেকে নেওয়া হয় না। লাইসেন্সকৃত বা AI ছবির জন্য সূত্র লিখতেই হবে।</small></p>
  <?php if ($library): ?>
  <div class="cards">
    <?php foreach (array_slice(array_keys($library), 0, 24) as $fn): ?>
      <div class="stat"><img src="/uploads/<?= h($fn) ?>" alt="" loading="lazy" style="max-width:120px;height:auto;display:block;margin:0 auto"><span><code><?= h($fn) ?></code></span></div>
    <?php endforeach; ?>
  </div>
  <?php else: ?><p class="muted">লাইব্রেরিতে এখনো কোনো ছবি নেই।</p><?php endif; ?>
  <?php if ($approvedPosts && $library): ?>
  <div class="tbl-wrap"><table><thead><tr><th>লেখা</th><th>বর্তমান কভার</th><th colspan="3">নতুন কভার</th></tr></thead><tbody>
  <?php foreach ($approvedPosts as $p): ?>
    <tr>
      <td><?= h($p['title']) ?></td>
      <td><?= $p['cover_image'] !== '' ? h(basename((string) $p['cover_image'])) : '—' ?></td>
      <td colspan="3"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="cover"><input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>">
        <select name="file" required><?php foreach (array_keys($library) as $fn): ?><option value="<?= h($fn) ?>"><?= h($fn) ?></option><?php endforeach; ?></select>
        <select name="cover_source" required><?php foreach (BLOG_COVER_SOURCES as $k => $v): ?><option value="<?= h($k) ?>" <?= $p['cover_source'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
        <input name="cover_credit" maxlength="200" value="<?= h((string) $p['cover_credit']) ?>" placeholder="লাইসেন্স/সূত্র">
        <button class="btn small">কভার সেট করুন</button></form></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>ফেসবুকের জন্য খসড়া (অনুমোদিত লেখা থেকে)</h2>
  <p><small>এটি ফেসবুক কিউতে যাবে, কিন্তু নিজে থেকে পাঠানো হবে না। ফেসবুক কিউ পাতা থেকে আপনি নিজে পাঠাবেন।</small></p>
  <?php if ($approvedPosts): ?>
  <div class="tbl-wrap"><table><thead><tr><th>লেখা</th><th></th></tr></thead><tbody>
  <?php foreach ($approvedPosts as $p): ?>
    <tr><td><?= h($p['title']) ?></td><td>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="fb"><input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>"><button class="btn small ghost">ফেসবুক কিউতে যোগ করুন</button></form>
    </td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="muted">এখনো কোনো অনুমোদিত লেখা নেই।</p><?php endif; ?>
</div>
<?php admin_foot();
