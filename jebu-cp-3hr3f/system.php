<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'test_telegram') {
        [$ok, $err] = telegram_send_text("✅ টেস্ট বার্তা: সিস্টেম মনিটর থেকে পাঠানো হয়েছে। এটি পেলে Telegram ঠিকভাবে সেট করা আছে।");
        flash($ok ? 'ok' : 'err', $ok ? 'টেলিগ্রামে টেস্ট বার্তা গেছে। আপনার চ্যাটে দেখুন।' : 'টেলিগ্রামে পাঠানো যায়নি: ' . $err);
    } elseif ($act === 'clear_alerts') {
        q_exec('DELETE FROM system_alerts WHERE last_seen < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
        flash('ok', 'সাত দিনের পুরনো সতর্কতা মুছে ফেলা হয়েছে।');
    }
    redirect(a_url('system'));
}

$cron = cron_status();
$network = isset($_GET['net']);
$checks = system_checks($network);
$alerts = system_alerts_recent(60);
$cronLabel = ['ok' => 'ঠিক আছে', 'warn' => 'দেরি হচ্ছে', 'critical' => 'বন্ধ হয়ে গেছে', 'never' => 'কখনো চলেনি'][$cron['state']];
$levelLabel = ['info' => 'তথ্য', 'error' => 'ত্রুটি', 'critical' => 'জরুরি'];

admin_head('সিস্টেম মনিটর');
?>
<div class="cards">
  <div class="stat <?= $cron['state'] === 'ok' ? '' : 'warn' ?>"><b><?= h($cronLabel) ?></b><span>ক্রন (<?= $cron['at'] !== '' ? 'শেষ: ' . h($cron['at']) : 'প্রতি ৫ মিনিটে চলা দরকার' ?>)</span></div>
  <div class="stat <?= telegram_enabled() ? '' : 'warn' ?>"><b><?= telegram_enabled() ? 'সেট করা' : 'খালি' ?></b><span>টেলিগ্রাম</span></div>
</div>

<?php $ec = error_center_snapshot(); ?>
<div class="panel">
  <h2>ত্রুটি কেন্দ্র (সব সংযোগ এক জায়গায়)</h2>
  <div class="cards">
    <div class="stat <?= $ec['dead'] ? 'warn' : '' ?>"><b><?= (int) $ec['dead'] ?></b><span>ব্যর্থ কাজ (dead-letter)</span></div>
    <div class="stat <?= $ec['retry'] ? 'warn' : '' ?>"><b><?= (int) $ec['retry'] ?></b><span>আবার চেষ্টা হবে</span></div>
    <div class="stat <?= $ec['linkedin']['connected'] ? '' : 'warn' ?>"><b><?= $ec['linkedin']['connected'] ? 'সংযুক্ত' : 'নেই / মেয়াদ শেষ' ?></b><span>LinkedIn<?= $ec['linkedin']['expires_at'] ? ' (শেষ: ' . h(date('d.m.Y', $ec['linkedin']['expires_at'])) . ')' : '' ?></span></div>
    <div class="stat <?= $ec['tiktok']['connected'] ? '' : 'warn' ?>"><b><?= $ec['tiktok']['connected'] ? 'সংযুক্ত' : 'নেই / মেয়াদ শেষ' ?></b><span>TikTok<?= $ec['tiktok']['expires_at'] ? ' (শেষ: ' . h(date('d.m.Y', $ec['tiktok']['expires_at'])) . ')' : '' ?></span></div>
  </div>
  <?php if ($ec['jobs']): ?>
  <div class="tbl-wrap"><table><thead><tr><th>সময়</th><th>কাজ</th><th>অবস্থা</th><th>চেষ্টা</th><th>শেষ ত্রুটি</th></tr></thead><tbody>
  <?php foreach ($ec['jobs'] as $j): ?>
    <tr><td><?= h(date('d.m H:i', strtotime((string) $j['updated_at']))) ?></td><td><code><?= h($j['job_key']) ?></code></td><td><?= $j['state'] === 'dead' ? 'ব্যর্থ' : 'আবার চেষ্টা' ?></td><td><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td><td><?= h((string) $j['last_error']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="muted">কোনো ব্যর্থ বা অপেক্ষমাণ কাজ নেই।</p><?php endif; ?>
</div>

<div class="panel">
  <h2>ক্রন (Cron Job) চেক</h2>
  <p>cPanel-এ এই কমান্ডটি প্রতি ৫ মিনিটে চলা দরকার:</p>
  <pre><code>/usr/local/bin/php <?= h(dirname(__DIR__)) ?>/app/cron/dispatch.php &gt;/dev/null 2&gt;&amp;1</code></pre>
  <p class="muted">উপরের "শেষ চলা" সময়টি ৫-১০ মিনিটের মধ্যে থাকলে ক্রন ঠিক আছে। না থাকলে cPanel &gt; Cron Jobs-এ কমান্ডটির পথ আবার মিলিয়ে নিন।</p>
</div>

<div class="panel">
  <h2>টেলিগ্রাম</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="test_telegram"><button class="btn" type="submit"<?= telegram_enabled() ? '' : ' disabled' ?>>টেস্ট বার্তা পাঠান</button></form>
  <?php if (!telegram_enabled()): ?><p class="muted">সেটিংসে "এআই ও টেলিগ্রাম" অংশে Bot Token ও Chat ID বসান।</p><?php endif; ?>
</div>

<div class="panel">
  <h2>হোস্টিং ও কনফিগারেশন চেক</h2>
  <p><a class="btn ghost" href="<?= h(a_url('system', ['net' => 1])) ?>">বাইরের সংযোগ (Telegram, Meta) সহ পুরো চেক চালান</a></p>
  <div class="tbl-wrap"><table><thead><tr><th>চেক</th><th>ফল</th><th>বিবরণ</th></tr></thead><tbody>
  <?php foreach ($checks as $c): ?>
    <tr><td><?= h($c['label']) ?></td><td><?= $c['ok'] === true ? '✅' : ($c['ok'] === false ? '❌' : 'ℹ️') ?></td><td><?= h($c['detail']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="panel">
  <h2>সাম্প্রতিক সতর্কতা</h2>
  <?php if ($alerts): ?>
  <div class="tbl-wrap"><table><thead><tr><th>সময়</th><th>স্তর</th><th>উৎস</th><th>বার্তা</th><th>বার</th></tr></thead><tbody>
  <?php foreach ($alerts as $a): ?>
    <tr><td><?= h(date('d.m H:i', strtotime((string) $a['last_seen']))) ?></td><td><?= h($levelLabel[$a['level']] ?? $a['level']) ?></td><td><code><?= h($a['source']) ?></code></td><td><?= h($a['message']) ?></td><td><?= (int) $a['hits'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <form method="post" class="inline" style="margin-top:.8rem"><?= csrf_field() ?><input type="hidden" name="act" value="clear_alerts"><button class="btn ghost" type="submit">৭ দিনের পুরনো সতর্কতা মুছুন</button></form>
  <?php else: ?><p class="muted">কোনো সমস্যা ধরা পড়েনি।</p><?php endif; ?>
</div>
<?php admin_foot();
