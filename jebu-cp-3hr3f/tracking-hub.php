<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_tracking_ui.php';
$u = require_perm('settings');
$uid = (int) $u['id'];

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'test_acc') {
        $platform = (string) ($_POST['platform'] ?? '');
        $acc = in_array($platform, ['meta', 'ga4'], true) ? tracking_find($platform, (int) ($_POST['id'] ?? 0)) : null;
        if ($acc === null) {
            flash('err', 'এই অ্যাকাউন্টে সার্ভার টেস্ট নেই।');
        } else {
            $r = $platform === 'meta' ? tracking_meta_test($acc) : tracking_ga4_test($acc);
            log_activity('tracking.server_test', $platform . ':' . (string) $acc['ext_id'], $uid);
            flash($r['ok'] ? 'ok' : 'err', $r['msg']);
        }
    } elseif ($act === 'test_on') {
        save_setting('tracking_test_token', bin2hex(random_bytes(16)));
        save_setting('tracking_test_until', (string) (time() + 600));
        log_activity('tracking.test_on', '', $uid);
        flash('ok', 'ব্রাউজার টেস্ট মোড ১০ মিনিটের জন্য চালু হয়েছে।');
    } elseif ($act === 'test_off') {
        save_setting('tracking_test_token', '');
        save_setting('tracking_test_until', '0');
        log_activity('tracking.test_off', '', $uid);
        flash('ok', 'ব্রাউজার টেস্ট মোড বন্ধ করা হয়েছে।');
    } else {
        flash('err', 'অজানা অনুরোধ।');
    }
    redirect(a_url('tracking-hub'));
}

$gtm = tracking_gtm_id();
$testTok = (string) setting('tracking_test_token');
$testUntil = (int) setting('tracking_test_until', '0');
$testOn = $testTok !== '' && $testUntil > time();
$testLeft = max(0, (int) ceil(($testUntil - time()) / 60));
$all = [];
foreach (['meta' => 'Meta Ads', 'ga4' => 'Google Analytics', 'gads' => 'Google Ads'] as $p => $pl) {
    foreach (tracking_all($p) as $a) {
        $all[] = [$p, $pl, $a];
    }
}
$serverCount = count(array_filter($all, static fn($x) => $x[0] !== 'gads' && (int) $x[2]['server_on'] === 1 && (string) $x[2]['secret'] !== ''));
$metaTestOn = array_filter($all, static fn($x) => $x[0] === 'meta' && (string) $x[2]['extra'] !== '' && (int) $x[2]['active'] === 1);
$toneStyle = ['ok' => 'ok', 'warn' => 'warn', 'info' => 'off'];

admin_head('ট্র্যাকিং — সব এক জায়গায়');
?>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
  <?php tr_status('GTM', $gtm !== '', $gtm !== '' ? $gtm : 'বন্ধ — অ্যাকাউন্টগুলো সরাসরি বসছে'); ?>
  <?php tr_status('সার্ভার-সাইড ইভেন্ট', $serverCount > 0, $serverCount . 'টি অ্যাকাউন্ট সার্ভার থেকেও পাঠাচ্ছে'); ?>
  <?php tr_status('ব্রাউজার টেস্ট মোড', $testOn, $testOn ? 'চালু — আর প্রায় ' . $testLeft . ' মিনিট' : 'বন্ধ'); ?>
  <?php tr_status('Meta test code', $metaTestOn === [], $metaTestOn === [] ? 'কোনো test code নেই' : 'চালু আছে — সতর্ক থাকুন'); ?>
</div>

<?php if ($metaTestOn !== []): ?>
<div class="panel" style="margin-top:1rem;border-left:4px solid #b45309">
  <b>সতর্কতা:</b> Meta test code চালু আছে। আসল লিড Test Events-এ যাচ্ছে, অ্যাডের রিপোর্ট ও অপটিমাইজেশনে যাচ্ছে না। টেস্ট শেষ হলে Meta অ্যাকাউন্টের সম্পাদনা থেকে test code মুছে দিন।
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
  <h2>Google Tag Manager (GTM)</h2>
  <p class="muted" style="margin-top:0">GTM-এর ID ও সেটআপ আলাদা পেজে আছে।</p>
  <a class="abtn" href="<?= h(a_url('tracking-gtm')) ?>">GTM পেজে যান</a>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>ব্রাউজার টেস্ট (শুধু আপনার ব্রাউজার)</h2>
  <p class="muted" style="margin-top:0">টেস্ট মোড চালু থাকলে শুধু এই ব্রাউজারে সাইটের ধন্যবাদ পেজ ও ফোন-ক্লিকের ইভেন্ট কনসোলে দেখাবে (F12 → Console, "[jebu-test]")। এগুলো Meta, GA4 বা GTM-এ যায় না। ফর্ম জমা দিলে সার্ভার ইভেন্টও যায় না। অন্য কোনো ব্রাউজার বা রোগীর ওপর এর কোনো প্রভাব নেই। ১০ মিনিট পর নিজে থেকে বন্ধ হবে।</p>
  <?php if ($testOn): ?>
    <p>এই লিংকটি একবার আপনার ব্রাউজারে খুলুন (এরপর ১০ মিনিট চলবে):<br><code class="mono" style="word-break:break-all"><?= h(abs_url('/?jebu_tt=' . $testTok)) ?></code></p>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="test_off"><button class="btn ghost">টেস্ট মোড বন্ধ করুন</button></form>
  <?php else: ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="test_on"><button class="btn">ব্রাউজার টেস্ট মোড চালু করুন (১০ মিনিট)</button></form>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>সার্ভার টেস্ট ও সব অ্যাকাউন্ট</h2>
  <p class="muted" style="margin-top:0">Meta-তে "টেস্ট" চাপলে test code থাকলে Test Events-এ যায়। GA4-তে "টেস্ট" চাপলে Google শুধু payload যাচাই করে, কোনো ডাটা রেকর্ড হয় না। Google Ads-এ সরাসরি টেস্ট নেই, ধন্যবাদ পেজে কনভার্সন যাচাই করুন।</p>
  <?php if ($all === []): ?>
    <p class="muted">এখনো কোনো অ্যাকাউন্ট যোগ করা হয়নি। প্ল্যাটফর্ম পেজ থেকে যোগ করুন।</p>
  <?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>প্ল্যাটফর্ম</th><th>নাম</th><th>ID</th><th>অবস্থা</th><th>সার্ভার</th><th></th><th></th></tr></thead><tbody>
  <?php foreach ($all as [$p, $pl, $a]): $page = ['meta' => 'tracking-meta', 'ga4' => 'tracking-ga4', 'gads' => 'tracking-google'][$p]; ?>
    <tr>
      <td><?= h($pl) ?></td>
      <td><?= h((string) $a['label'] !== '' ? (string) $a['label'] : 'নামহীন') ?></td>
      <td><code class="mono"><?= h((string) $a['ext_id']) ?></code></td>
      <td><?= tr_badge((int) $a['active'] === 1 ? 'সক্রিয়' : 'বন্ধ', (int) $a['active'] === 1 ? 'ok' : 'off') ?></td>
      <td><?= $p === 'gads' ? '—' : tr_badge((int) $a['server_on'] === 1 && (string) $a['secret'] !== '' ? 'চালু' : 'শুধু ব্রাউজার', (int) $a['server_on'] === 1 && (string) $a['secret'] !== '' ? 'ok' : 'warn') ?></td>
      <td><?php if ($p !== 'gads'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="test_acc"><input type="hidden" name="platform" value="<?= h($p) ?>"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn small ghost">সার্ভার টেস্ট</button></form>
      <?php endif; ?></td>
      <td><a class="abtn" href="<?= h(a_url($page)) ?>">সম্পাদনা</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>ডুপ্লিকেট ও নিরাপত্তা যাচাই</h2>
  <ul style="margin:0;padding-left:0;list-style:none;display:grid;gap:.6rem">
  <?php foreach (tracking_dup_report() as [$tone, $text]): ?>
    <li><?= tr_badge($tone === 'ok' ? 'ঠিক আছে' : ($tone === 'warn' ? 'সতর্কতা' : 'তথ্য'), $toneStyle[$tone] ?? 'off') ?> <?= h($text) ?></li>
  <?php endforeach; ?>
  </ul>
</div>

<div class="panel" style="margin-top:1rem">
  <h2>ইভেন্ট তালিকা (এই ক্লিনিকের জন্য)</h2>
  <div class="tbl-wrap"><table><thead><tr><th>ইভেন্ট</th><th>কখন</th><th>কোথায় যায়</th></tr></thead><tbody>
    <tr><td><code>Lead / generate_lead</code></td><td>অ্যাপয়েন্টমেন্ট ফর্ম জমা</td><td>ধন্যবাদ পেজ (ব্রাউজার) + সার্ভার (Meta CAPI, GA4)</td></tr>
    <tr><td><code>Lead / generate_lead</code> (type: contact)</td><td>যোগাযোগ ফর্ম জমা</td><td>একই</td></tr>
    <tr><td><code>phone_call_click</code></td><td>ফোন নম্বরে ক্লিক (tel:)</td><td>শুধু ব্রাউজার</td></tr>
    <tr><td><code>view_item, add_to_cart, purchase, begin_checkout</code></td><td>—</td><td>এই সাইটে কার্ট বা চেকআউট নেই, তাই প্রযোজ্য নয়</td></tr>
  </tbody></table></div>
</div>
<?php
admin_foot();
