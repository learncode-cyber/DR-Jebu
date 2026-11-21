<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
meta_tables();

$convId = (int) ($_GET['c'] ?? 0);
$conv = $convId > 0 ? q_one('SELECT * FROM fb_conversations WHERE id = ?', [$convId]) : null;

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $postConv = (int) ($_POST['conv_id'] ?? 0);
    $target = $postConv > 0 ? q_one('SELECT * FROM fb_conversations WHERE id = ?', [$postConv]) : null;

    if ($act === 'test_connection') {
        [$ok, $msg] = fb_test_connection();
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'pause' && $target) {
        fb_pause((int) $target['id'], meta_cfg()['handoff_hours']);
        flash('ok', 'বট এই কথোপকথনে বিরতিতে গেছে। আপনি নিজে উত্তর দিতে পারেন।');
    } elseif ($act === 'resume' && $target) {
        q_exec('UPDATE fb_conversations SET paused_until = NULL, updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $target['id']]);
        flash('ok', 'বট আবার চালু হয়েছে।');
    } elseif ($act === 'staff_reply' && $target) {
        $text = trim((string) ($_POST['body'] ?? ''));
        $lastIn = strtotime((string) ($target['last_inbound_at'] ?? '')) ?: 0;
        if ($text === '') {
            flash('err', 'বার্তা খালি।');
        } elseif ($lastIn === 0 || time() - $lastIn > META_WINDOW_HOURS * 3600) {
            flash('err', 'শেষ বার্তার ২৪ ঘণ্টা পার হয়ে গেছে। Messenger এখন উত্তর পাঠাতে দেয় না।');
        } else {
            $parts = fb_split_reply(mb_substr($text, 0, 4000));
            foreach ($parts as $i => $part) {
                if ($i > 0) { usleep(600000); }
                $res = fb_graph_send((string) $target['psid'], $part);
                fb_log_outbound((int) $target['id'], $part, $res['mid'], $res['ok'] ? 'staff' : 'failed', $res['error']);
            }
            flash('ok', 'বার্তা পাঠানো হয়েছে।');
        }
    }
    redirect(a_url('fb-inbox', $postConv > 0 ? ['c' => $postConv] : []));
}

admin_head('Facebook ইনবক্স');

$cfg = meta_cfg();
$missing = meta_missing();
$webhookUrl = abs_url('/fb-webhook.php');
?>
<div class="cards">
  <div class="stat <?= meta_enabled() ? '' : 'warn' ?>"><b><?= meta_enabled() ? 'চালু' : 'বন্ধ' ?></b><span>Messenger এআই বট</span></div>
  <div class="stat <?= $missing ? 'warn' : '' ?>"><b><?= $missing ? count($missing) . 'টি বাকি' : 'সব সেট' ?></b><span>প্রয়োজনীয় সেটিংস</span></div>
</div>

<div class="panel">
  <h2>সেটআপ ও সংযোগ</h2>
  <p>Meta Developer-এ Webhook URL হিসেবে এটি বসান (Verify Token সেটিংসে যা লিখেছেন, হুবহু সেটাই):</p>
  <pre><code><?= h($webhookUrl) ?></code></pre>
  <?php if ($missing): ?><p class="muted">এখনো বাকি: <?= h(implode(', ', $missing)) ?>। সেটিংস › "Facebook Messenger" অংশে বসান।</p><?php endif; ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="test_connection"><button class="btn ghost" type="submit"<?= $cfg['page_token'] === '' ? ' disabled' : '' ?>>Meta সংযোগ পরীক্ষা করুন</button></form>
  <p class="muted">বট চালু বা বন্ধ করতে সেটিংস › "Facebook Messenger" › "বট চালু" (1 বা 0)।</p>
</div>

<?php if ($conv): ?>
<div class="panel">
  <h2>কথোপকথন: <?= h($conv['name'] !== '' ? $conv['name'] : 'নামহীন') ?> <small class="muted">#<?= (int) $conv['id'] ?></small></h2>
  <p class="muted">
    অবস্থা: <?= !empty($conv['paused_until']) && strtotime((string) $conv['paused_until']) > time() ? 'বট বিরতিতে (মানুষ উত্তর দিচ্ছে) — ' . h(date('d.m H:i', strtotime((string) $conv['paused_until']))) . ' পর্যন্ত' : 'বট সক্রিয়' ?>
    · আজ বটের উত্তর: <?= ($conv['day_date'] ?? '') === date('Y-m-d') ? (int) $conv['day_replies'] : 0 ?>
  </p>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="conv_id" value="<?= (int) $conv['id'] ?>">
    <?php if (!empty($conv['paused_until']) && strtotime((string) $conv['paused_until']) > time()): ?>
      <button class="btn" name="act" value="resume" type="submit">বট আবার চালু করুন</button>
    <?php else: ?>
      <button class="btn ghost" name="act" value="pause" type="submit">বট বিরতি দিন (আমি উত্তর দেব)</button>
    <?php endif; ?>
  </form>

  <div class="tbl-wrap" style="margin-top:.8rem"><table><thead><tr><th>সময়</th><th>কে</th><th>বার্তা</th><th>অবস্থা</th></tr></thead><tbody>
  <?php foreach (q_all('SELECT * FROM fb_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 100', [(int) $conv['id']]) as $m): ?>
    <tr>
      <td><?= h(date('d.m H:i', strtotime((string) $m['created_at']))) ?></td>
      <td><?= $m['direction'] === 'in' ? 'রোগী' : ($m['status'] === 'staff' ? 'স্টাফ' : 'বট') ?></td>
      <td><?= nl2br(h((string) $m['body'])) ?></td>
      <td><?= h($m['status'] === 'failed' ? 'পাঠানো যায়নি: ' . (string) ($m['error'] ?? '') : $m['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>

  <form method="post" class="form" style="margin-top:1rem"><?= csrf_field() ?><input type="hidden" name="conv_id" value="<?= (int) $conv['id'] ?>">
    <label>স্টাফের উত্তর (শেষ বার্তার ২৪ ঘণ্টার মধ্যে)<textarea name="body" rows="3" maxlength="4000"></textarea></label>
    <button class="btn" name="act" value="staff_reply" type="submit">পাঠান</button>
  </form>
  <p><a href="<?= h(a_url('fb-inbox')) ?>">← সব কথোপকথনে ফিরুন</a></p>
</div>
<?php else: ?>
<div class="panel">
  <h2>কথোপকথন</h2>
  <?php $list = q_all('SELECT * FROM fb_conversations ORDER BY COALESCE(last_inbound_at, created_at) DESC LIMIT 100'); ?>
  <?php if ($list): ?>
  <div class="tbl-wrap"><table><thead><tr><th>শেষ বার্তা</th><th>নাম</th><th>ভাষা</th><th>অবস্থা</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $c): $paused = !empty($c['paused_until']) && strtotime((string) $c['paused_until']) > time(); ?>
    <tr>
      <td><?= h($c['last_inbound_at'] ? date('d.m H:i', strtotime((string) $c['last_inbound_at'])) : '—') ?></td>
      <td><?= h($c['name'] !== '' ? $c['name'] : (str_starts_with((string) $c['psid'], 'ig:') ? 'Instagram ব্যবহারকারী' : 'Messenger ব্যবহারকারী')) ?></td>
      <td><?= h($c['lang']) ?></td>
      <td><?= $paused ? 'বিরতিতে' : 'বট সক্রিয়' ?></td>
      <td><a href="<?= h(a_url('fb-inbox', ['c' => (int) $c['id']])) ?>">খুলুন</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="muted">এখনো কোনো Messenger বার্তা আসেনি।</p><?php endif; ?>
</div>
<?php endif; ?>
<?php admin_foot();
