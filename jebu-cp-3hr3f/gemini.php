<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('settings');
gem_tables();

if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'cred_add') {
        [$ok, $msg] = gem_cred_add(
            (string) ($_POST['label'] ?? ''), (string) ($_POST['api_key'] ?? ''), (string) ($_POST['project_id'] ?? ''),
            (string) ($_POST['environment'] ?? 'production'), (string) ($_POST['billing'] ?? 'free')
        );
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'cred_verify') {
        [$ok, $msg] = gem_cred_verify($id);
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'cred_status') {
        gem_cred_set_status($id, (string) ($_POST['status'] ?? ''));
        flash('ok', 'Credential অবস্থা বদলানো হয়েছে।');
    } elseif ($act === 'cred_rotate') {
        [$ok, $msg] = gem_cred_rotate($id, (string) ($_POST['api_key'] ?? ''));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'cred_delete') {
        gem_cred_delete($id);
        flash('ok', 'Credential মুছে ফেলা হয়েছে।');
    } elseif ($act === 'settings_save') {
        save_setting('gemini_active_project', trim((string) ($_POST['active_project'] ?? '')));
        save_setting('gemini_daily_cap', (string) max(1, min(5000, (int) ($_POST['daily_cap'] ?? 200))));
        save_setting('gemini_rpm_cap', (string) max(1, min(60, (int) ($_POST['rpm_cap'] ?? 8))));
        save_setting('gemini_max_attempts', (string) max(1, min(20, (int) ($_POST['max_attempts'] ?? 6))));
        save_setting('gemini_paid_allowed', ((string) ($_POST['paid_allowed'] ?? '') === '1' && (string) ($_POST['paid_confirm'] ?? '') === 'yes') ? '1' : '0');
        flash('ok', 'Gemini সেটিংস সংরক্ষিত হয়েছে।');
    } elseif ($act === 'job_retry') {
        [$ok, $msg] = gem_job_retry($id);
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'job_cancel') {
        [$ok, $msg] = gem_job_cancel($id);
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($act === 'run_worker') {
        $r = gem_worker_tick(3);
        flash('ok', 'ওয়ার্কার চালানো হয়েছে: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    redirect(a_url('gemini'));
}

admin_head('Gemini এআই');
$creds = gem_cred_list();
$health = gem_health();
$active = gem_active_project();
$activeOk = $active !== '' && count(array_filter($creds, static fn($c) => $c['project_id'] === $active && $c['status'] === 'active' && $c['verify_status'] === 'ok')) > 0;
$jobs = q_all('SELECT * FROM gem_jobs ORDER BY id DESC LIMIT 40');
$usage = q_all('SELECT model_id, SUM(requests) r, SUM(ok_count) ok, SUM(fail_count) f, SUM(rl_minute + rl_daily) rl, SUM(tokens_in) ti, SUM(tokens_out) tos FROM gem_usage WHERE project_id = ? AND day_pt = ? GROUP BY model_id', [$active, gem_pt_day()]);
$errors = q_all("SELECT at, event, model_id, http_code, message FROM gem_log WHERE level IN ('warn','error') ORDER BY id DESC LIMIT 25");
$cap = (int) setting('gemini_daily_cap', '200');
$todayCount = (int) $health['requests_today'];
$models = q_all('SELECT project_id, model_id, generates, available, unavailable_reason, seen_at FROM gem_models ORDER BY project_id, model_id LIMIT 200');
$statusLabel = ['pending' => 'অপেক্ষায়', 'processing' => 'চলছে', 'completed' => 'সম্পন্ন', 'failed' => 'ব্যর্থ', 'retry_scheduled' => 'পুনরায় চেষ্টা নির্ধারিত', 'waiting_quota' => 'কোটা রিসেটের অপেক্ষায়', 'cancelled' => 'বাতিল', 'manual_required' => 'আপনার হস্তক্ষেপ দরকার'];
?>
<div class="cards">
  <div class="stat <?= $activeOk ? '' : 'warn' ?>"><b><?= $active !== '' ? h($active) : 'নেই' ?></b><span>সক্রিয় প্রজেক্ট</span></div>
  <div class="stat"><b><?= $todayCount ?> / <?= $cap ?></b><span>আজকের অনুরোধ (প্রজেক্ট)</span></div>
  <div class="stat <?= $health['jobs']['manual_required'] > 0 ? 'warn' : '' ?>"><b><?= (int) $health['jobs']['pending'] + (int) $health['jobs']['retry_scheduled'] + (int) $health['jobs']['waiting_quota'] ?></b><span>কিউতে বাকি কাজ</span></div>
  <div class="stat"><b><?= (int) $health['rate_limits_24h'] ?></b><span>২৪ ঘণ্টায় রেট-লিমিট (429)</span></div>
</div>

<div class="panel">
  <h2>সতর্কতা ও নীতি</h2>
  <p class="muted">রেট-লিমিট Google প্রজেক্টের উপর হয়, কী-এর উপর নয়। একই প্রজেক্টের সব key একই কোটা ভাগ করে। দৈনিক কোটা Pacific সময় মধ্যরাতে রিসেট হয়; নিচে সময় ঢাকার সময়ে দেখানো হয়েছে।
  কোটা শেষ হলে অন্য প্রজেক্টে সরানো হয় না। পেইড ব্যবহার ডিফল্টে বন্ধ; রোগীর তথ্য সম্পর্কিত কাজ গোপনীয়তা যাচাই ছাড়া যায় না।</p>
  <?php if ($health['blocks']): ?>
    <p><b>এখন সীমাবদ্ধ:</b></p>
    <ul class="info-list">
    <?php foreach ($health['blocks'] as $b): ?><li><?= h($b['model_id']) ?> (<?= h($b['kind'] === 'daily' ? 'দৈনিক' : 'প্রতি মিনিট') ?>) — ঢাকা সময় <?= h(gem_show_dhaka($b['blocked_until'])) ?> পর্যন্ত</li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Credential (API key)</h2>
  <p class="muted">Key একবার সেভ হলে পুরো মান আর দেখানো হয় না। একই প্রজেক্টের একাধিক key রাখা যায়, তবে কোটা একটাই।</p>
  <?php if (!$creds): ?><p class="muted">এখনো কোনো credential নেই।</p><?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>নাম</th><th>প্রজেক্ট</th><th>Key</th><th>Billing</th><th>অবস্থা</th><th>যাচাই</th><th>শেষ ত্রুটি</th><th></th></tr></thead><tbody>
  <?php foreach ($creds as $c): ?>
    <tr>
      <td><?= h($c['label']) ?><br><small class="muted"><?= h($c['environment']) ?></small></td>
      <td><?= h($c['project_id']) ?></td>
      <td><code><?= h($c['masked']) ?></code></td>
      <td><?= $c['billing'] === 'paid' ? '<b>পেইড</b>' : 'ফ্রি' ?></td>
      <td><?= $c['status'] === 'active' ? 'সক্রিয়' : 'বন্ধ' ?></td>
      <td><?= h($c['verify_status']) ?><br><small class="muted"><?= $c['verified_at'] ? h(gem_show_dhaka($c['verified_at'])) : 'এখনো যাচাই হয়নি' ?></small></td>
      <td><small><?= h((string) $c['last_error']) ?></small></td>
      <td class="acts">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="cred_verify"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn ghost" type="submit">যাচাই</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="cred_status"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="<?= $c['status'] === 'active' ? 'disabled' : 'active' ?>"><button class="btn ghost" type="submit"><?= $c['status'] === 'active' ? 'বন্ধ করুন' : 'চালু করুন' ?></button></form>
        <form method="post" class="inline" onsubmit="return confirm('এই credential মুছবেন?');"><?= csrf_field() ?><input type="hidden" name="act" value="cred_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn ghost" type="submit">মুছুন</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>

  <h2 style="margin-top:1.2rem">নতুন credential যোগ করুন</h2>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="cred_add">
    <label>নাম<input type="text" name="label" maxlength="80" placeholder="যেমন: মূল ফ্রি কী"></label>
    <label>Google Cloud Project ID<input type="text" name="project_id" maxlength="40" required pattern="[a-z][a-z0-9\-]{4,28}[a-z0-9]"></label>
    <label>API key (AIza…)<input type="password" name="api_key" autocomplete="off" required></label>
    <label>Environment<select name="environment"><option value="production">Production</option><option value="staging">Staging</option><option value="development">Development</option></select></label>
    <label>Billing<select name="billing"><option value="free">Free tier</option><option value="paid">Paid (শুধু অনুমোদিত হলে ব্যবহৃত হবে)</option></select></label>
    <button class="btn" type="submit">যোগ করুন</button>
  </form>
  <form method="post" class="form" style="margin-top:1rem" onsubmit="return confirm('পুরোনো key বাতিল করে নতুন key বসাবেন?');"><?= csrf_field() ?><input type="hidden" name="act" value="cred_rotate">
    <label>কোন credential (ID)<input type="number" name="id" min="1" required></label>
    <label>নতুন API key<input type="password" name="api_key" autocomplete="off" required></label>
    <button class="btn ghost" type="submit">Key বদলান</button>
  </form>
</div>

<div class="panel">
  <h2>মডেল ও রুট</h2>
  <p class="muted">মডেলের ক্রম কোডে ঠিক করা আছে (app/gemini_router.php)। প্রথমে যেটি অনুমোদিত, উপলব্ধ ও কোটামুক্ত, সেটি ব্যবহৃত হয়। মেডিক্যাল কাজে lite মডেল কখনো ব্যবহৃত হয় না।</p>
  <?php if ($models): ?>
  <div class="tbl-wrap"><table><thead><tr><th>প্রজেক্ট</th><th>মডেল</th><th>generateContent</th><th>উপলব্ধ</th><th>শেষ দেখা</th></tr></thead><tbody>
  <?php foreach ($models as $m): ?>
    <tr><td><?= h($m['project_id']) ?></td><td><code><?= h($m['model_id']) ?></code></td><td><?= (int) $m['generates'] ? 'হ্যাঁ' : 'না' ?></td><td><?= (int) $m['available'] ? 'হ্যাঁ' : 'না: ' . h((string) $m['unavailable_reason']) ?></td><td><small><?= h(gem_show_dhaka($m['seen_at'])) ?></small></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="muted">মডেল তালিকা এখনো আনা হয়নি। একটি credential "যাচাই" করলে তালিকা আসবে।</p><?php endif; ?>
</div>

<div class="panel">
  <h2>সেটিংস</h2>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="act" value="settings_save">
    <label>সক্রিয় প্রজেক্ট ID (খালি = প্রথম ফ্রি key-এর প্রজেক্ট)<input type="text" name="active_project" value="<?= h(trim(setting('gemini_active_project'))) ?>" maxlength="40"></label>
    <label>দিনে সর্বোচ্চ অনুরোধ (নিরাপত্তা সীমা)<input type="number" name="daily_cap" min="1" max="5000" value="<?= $cap ?>"></label>
    <label>প্রতি মিনিটে সর্বোচ্চ অনুরোধ (স্থানীয়)<input type="number" name="rpm_cap" min="1" max="60" value="<?= (int) setting('gemini_rpm_cap', '8') ?>"></label>
    <label>সর্বোচ্চ চেষ্টা প্রতি কাজে<input type="number" name="max_attempts" min="1" max="20" value="<?= (int) setting('gemini_max_attempts', '6') ?>"></label>
    <label><input type="checkbox" name="paid_allowed" value="1" <?= gem_paid_allowed() ? 'checked' : '' ?>> পেইড ব্যবহার অনুমোদন</label>
    <label><input type="checkbox" name="paid_confirm" value="yes"> আমি বুঝি পেইড ব্যবহারে খরচ হতে পারে এবং নিজে সীমা নির্ধারণ করেছি</label>
    <button class="btn" type="submit">সেটিংস সংরক্ষণ</button>
  </form>
</div>

<div class="panel">
  <h2>আজকের ব্যবহার (মডেল অনুযায়ী)</h2>
  <?php if (!$usage): ?><p class="muted">আজ এখনো কোনো অনুরোধ যায়নি।</p><?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>মডেল</th><th>অনুরোধ</th><th>সফল</th><th>ব্যর্থ</th><th>রেট-লিমিট</th><th>ইনপুট টোকেন</th><th>আউটপুট টোকেন</th></tr></thead><tbody>
  <?php foreach ($usage as $r): ?>
    <tr><td><code><?= h($r['model_id']) ?></code></td><td><?= (int) $r['r'] ?></td><td><?= (int) $r['ok'] ?></td><td><?= (int) $r['f'] ?></td><td><?= (int) $r['rl'] ?></td><td><?= (int) $r['ti'] ?></td><td><?= (int) $r['tos'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <p class="muted" style="margin-top:.6rem">টোকেন সংখ্যা API উত্তর থেকে নেওয়া। অনুরোধ সংখ্যা এই সার্ভারের হিসাব; Google-এর AI Studio-র প্রকৃত বাকি কোটা এখান থেকে জানা যায় না।</p>
</div>

<div class="panel">
  <h2>কাজের কিউ</h2>
  <form method="post" class="inline" style="margin-bottom:.8rem"><?= csrf_field() ?><input type="hidden" name="act" value="run_worker"><button class="btn ghost" type="submit">এখনই কিউ চালান</button></form>
  <?php if (!$jobs): ?><p class="muted">কোনো কাজ নেই।</p><?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>ID</th><th>কাজ</th><th>অবস্থা</th><th>চেষ্টা</th><th>পরের চেষ্টা (ঢাকা)</th><th>শেষ বার্তা</th><th></th></tr></thead><tbody>
  <?php foreach ($jobs as $j): ?>
    <tr>
      <td><?= (int) $j['id'] ?></td>
      <td><?= h($j['task']) ?><br><small class="muted"><?= h($j['model_id']) ?></small></td>
      <td><?= h($statusLabel[$j['status']] ?? $j['status']) ?></td>
      <td><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td>
      <td><small><?= in_array($j['status'], ['completed', 'failed', 'cancelled'], true) ? '—' : h(gem_show_dhaka($j['next_run_at'])) ?></small></td>
      <td><small><?= h((string) $j['last_error']) ?></small></td>
      <td class="acts">
        <?php if (in_array($j['status'], ['failed', 'manual_required', 'cancelled', 'waiting_quota', 'retry_scheduled'], true)): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="job_retry"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button class="btn ghost" type="submit">আবার চালান</button></form>
        <?php endif; ?>
        <?php if (in_array($j['status'], ['pending', 'retry_scheduled', 'waiting_quota', 'manual_required'], true)): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="job_cancel"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button class="btn ghost" type="submit">বাতিল</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>সাম্প্রতিক ত্রুটি</h2>
  <?php if (!$errors): ?><p class="muted">কোনো ত্রুটি নেই।</p><?php else: ?>
  <div class="tbl-wrap"><table><thead><tr><th>সময় (ঢাকা)</th><th>ঘটনা</th><th>মডেল</th><th>কোড</th><th>বার্তা</th></tr></thead><tbody>
  <?php foreach ($errors as $e): ?>
    <tr><td><small><?= h(gem_show_dhaka($e['at'])) ?></small></td><td><?= h($e['event']) ?></td><td><code><?= h((string) $e['model_id']) ?></code></td><td><?= (int) $e['http_code'] ?></td><td><small><?= h((string) $e['message']) ?></small></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php admin_foot();
