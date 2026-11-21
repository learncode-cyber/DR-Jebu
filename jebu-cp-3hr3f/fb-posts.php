<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
fbp_tables();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'add') {
        $topic = trim((string) ($_POST['topic'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $at = appt_parse_input((string) ($_POST['at'] ?? '')) ?? date('Y-m-d H:i:s');
        if ($body === '' || mb_strlen($body) > FBP_MAX_CHARS) {
            flash('err', 'লেখা খালি, অথবা ' . FBP_MAX_CHARS . ' অক্ষরের বেশি।');
        } elseif ((int) q_val("SELECT COUNT(*) FROM fb_posts WHERE status = 'queued'") >= (int) setting('fb_max_queue', '50')) {
            flash('err', 'কিউ ভরা। সর্বোচ্চ ' . setting('fb_max_queue', '50') . 'টি পোস্ট কিউতে থাকতে পারে।');
        } elseif (!fbp_schedule_ok($at, time(), fbp_schedule_max_days())) {
            flash('err', 'সময় সঠিক নয়, অথবা ' . fbp_schedule_max_days() . ' দিনের বেশি দূরে।');
        } else {
            fbp_add($topic, $body, 'manual', $at);
            flash('ok', 'পোস্ট সময়সূচিতে যোগ হয়েছে।');
        }
    } elseif ($act === 'gen') {
        [$ok, $msg] = fbp_generate_one();
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'publish') {
        // "Publish now" (queued or failed post)
        q_exec('UPDATE fb_posts SET scheduled_at = ? WHERE id = ? AND status IN (\'queued\',\'failed\')', [date('Y-m-d H:i:s'), $id]);
        q_exec("UPDATE fb_posts SET status = 'queued' WHERE id = ? AND status = 'failed'", [$id]);
        [$ok, $msg] = fbp_publish($id);
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'delete') {
        [$ok, $msg] = fbp_delete($id);
        flash($ok ? 'ok' : 'err', $msg);
    }
    redirect(a_url('fb-posts'));
}

admin_head('Facebook পোস্ট');
$on = fbp_enabled();
$cap = fbp_daily_cap();
$cfgOk = meta_cfg()['page_token'] !== '' && meta_cfg()['page_id'] !== '';
?>
<div class="cards">
  <div class="stat <?= $on ? '' : 'warn' ?>"><b><?= $on ? 'চালু' : 'বন্ধ' ?></b><span>স্বয়ংক্রিয় পোস্ট</span></div>
  <div class="stat"><b><?= $cap ?></b><span>দিনে সর্বোচ্চ এআই পোস্ট</span></div>
  <div class="stat <?= ai_enabled() ? '' : 'warn' ?>"><b><?= ai_enabled() ? 'চালু' : 'বন্ধ' ?></b><span>এআই লেখা</span></div>
  <div class="stat <?= $cfgOk ? '' : 'warn' ?>"><b><?= $cfgOk ? 'সংযুক্ত' : 'বাকি' ?></b><span>Facebook Page সংযোগ</span></div>
</div>

<div class="panel">
  <h2>সেটআপ</h2>
  <p class="muted">স্বয়ংক্রিয় পোস্ট চালু বা বন্ধ করতে সেটিংস › "Facebook পোস্ট" › "অটো-পোস্ট চালু" (1 বা 0)। বিষয়সূচি ও দিনের সীমাও সেখানে বদলান। Facebook অ্যাপে <code>pages_manage_posts</code> অনুমতি থাকতে হবে।</p>
  <?php if (!$on): ?><p class="muted">এখন বন্ধ আছে, তাই নতুন কোনো পোস্ট যাবে না।</p><?php endif; ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="gen"><button class="btn ghost" type="submit"<?= ai_enabled() ? '' : ' disabled' ?>>এখনই একটি এআই পোস্ট লিখে সারিতে রাখুন</button></form>
</div>

<div class="panel">
  <h2>নতুন পোস্ট (নিজে লিখুন)</h2>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="add">
    <label>বিষয় (ঐচ্ছিক)<input type="text" name="topic" maxlength="200"></label>
    <label>লেখা (সর্বোচ্চ <?= FBP_MAX_CHARS ?> অক্ষর)<textarea name="body" rows="4" maxlength="<?= FBP_MAX_CHARS ?>" required></textarea></label>
    <label>কখন যাবে (খালি রাখলে এখনই)<input type="datetime-local" name="at"></label>
    <button class="btn" type="submit">সারিতে যোগ করুন</button>
  </form>
</div>

<div class="panel">
  <h2>পোস্টের তালিকা</h2>
  <?php $rows = q_all("SELECT * FROM fb_posts WHERE status <> 'deleted' ORDER BY id DESC LIMIT 60"); ?>
  <?php if (!$rows): ?><p class="muted">এখনো কোনো পোস্ট নেই।</p><?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>সময়</th><th>উৎস</th><th>লেখা</th><th>অবস্থা</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h(date('d.m H:i', strtotime((string) ($r['posted_at'] ?: $r['scheduled_at'] ?: $r['created_at'])))) ?></td>
      <td><?= $r['source'] === 'ai' ? 'এআই' : 'নিজে' ?></td>
      <td><?= nl2br(h(mb_substr((string) $r['body'], 0, 240))) ?><?php if ($r['topic'] !== ''): ?><br><small class="muted"><?= h($r['topic']) ?></small><?php endif; ?></td>
      <td><?= h($r['status'] === 'failed' ? 'যায়নি: ' . (string) $r['error'] : $r['status']) ?></td>
      <td class="acts">
        <?php if (in_array($r['status'], ['queued', 'failed'], true)): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="abtn" name="act" value="publish" type="submit">এখনই পাঠান</button></form>
        <?php endif; ?>
        <form method="post" class="inline" onsubmit="return confirm('মুছে ফেলবেন? পেজে পাঠানো হয়ে থাকলে সেখান থেকেও মুছবে।')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="abtn danger" name="act" value="delete" type="submit">মুছুন</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php admin_foot();
