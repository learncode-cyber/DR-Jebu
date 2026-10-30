<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_login();

$stats = ['new' => 0, 'today' => 0, 'week' => 0, 'total' => 0, 'failed' => 0];
$byStatus = $recent = $top404 = [];
if (can('leads.view')) {
    $stats['new'] = (int) q_val("SELECT COUNT(*) FROM leads WHERE status = 'new'");
    $stats['today'] = (int) q_val('SELECT COUNT(*) FROM leads WHERE created_at >= ?', [date('Y-m-d 00:00:00')]);
    $stats['week'] = (int) q_val('SELECT COUNT(*) FROM leads WHERE created_at >= ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $stats['total'] = (int) q_val('SELECT COUNT(*) FROM leads');
    $stats['failed'] = (int) q_val("SELECT COUNT(*) FROM notifications WHERE status = 'failed'");
    foreach (q_all('SELECT status, COUNT(*) n FROM leads GROUP BY status') as $r) { $byStatus[$r['status']] = (int) $r['n']; }
    $recent = q_all('SELECT id, kind, name, phone, status, created_at FROM leads ORDER BY id DESC LIMIT 8');
}
if (can('logs')) {
    $top404 = q_all('SELECT url, hits FROM not_found_log ORDER BY hits DESC LIMIT 5');
}
// ---- content overview (published / draft / total per content type) ----
$contentRows = [];
$contentErrors = [];
$contentMax = 1;
$seoSummary = ['warn_items' => 0, 'tip_items' => 0, 'checked' => 0];
$mediaCount = 0;
$mediaSize = 0;
$activity = [];
if (can('content')) {
    foreach (entities() as $k => $e) {
        $live = $k === 'blog' ? "status = 'published'" : (empty($e['no_active']) ? 'is_active = 1' : '1 = 1');
        try {
            $cr = q_one('SELECT COUNT(*) AS t, SUM(CASE WHEN ' . $live . ' THEN 1 ELSE 0 END) AS l FROM ' . $e['table']);
        } catch (Throwable $ex) {
            error_log('[jebu] dashboard count ' . $e['table'] . ': ' . $ex->getMessage());
            $contentErrors[] = $e['table'];
            $cr = ['t' => 0, 'l' => 0];
        }
        $row = ['k' => $k, 'label' => $e['label'], 'icon' => $e['icon'], 'total' => (int) $cr['t'], 'live' => (int) $cr['l']];
        $row['draft'] = $row['total'] - $row['live'];
        $contentMax = max($contentMax, $row['total']);
        $contentRows[] = $row;
    }
    $seoSummary = seo_content_issues();
    $files = upload_files();
    $mediaCount = count($files);
    foreach ($files as $f) { $mediaSize += $f['size']; }
}
if (can('logs')) {
    $activity = q_all('SELECT a.action, a.detail, a.created_at, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 12');
}
admin_head('ড্যাশবোর্ড');
?>
<?php if (can('leads.view')): ?>
<div class="cards">
  <a class="stat" href="<?= h(a_url('leads', ['status' => 'new'])) ?>"><b><?= $stats['new'] ?></b><span>নতুন লিড</span></a>
  <div class="stat"><b><?= $stats['today'] ?></b><span>আজকের অনুরোধ</span></div>
  <div class="stat"><b><?= $stats['week'] ?></b><span>গত ৭ দিন</span></div>
  <div class="stat"><b><?= $stats['total'] ?></b><span>মোট লিড</span></div>
  <?php if ($stats['failed'] > 0 && can('logs')): ?><a class="stat warn" href="<?= h(a_url('logs', ['t' => 'notifications'])) ?>"><b><?= $stats['failed'] ?></b><span>ব্যর্থ নোটিফিকেশন</span></a><?php endif; ?>
</div>
<div class="panel"><h2>সাম্প্রতিক লিড</h2>
<?php if (!$recent): ?><p class="muted">এখনও কোনো লিড আসেনি।</p><?php else: ?>
<table><thead><tr><th>#</th><th>ধরন</th><th>নাম</th><th>ফোন</th><th>স্ট্যাটাস</th><th>সময়</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?>
<tr><td><a href="<?= h(a_url('lead', ['id' => $r['id']])) ?>">#<?= (int) $r['id'] ?></a></td><td><?= $r['kind'] === 'appointment' ? 'সিরিয়াল' : 'বার্তা' ?></td><td><?= h($r['name']) ?></td><td><a href="tel:<?= h($r['phone']) ?>"><?= h($r['phone']) ?></a></td><td><span class="tag st-<?= h($r['status']) ?>"><?= h(LEAD_STATUSES[$r['status']] ?? $r['status']) ?></span></td><td><?= h(date('d M, h:i A', (int) strtotime((string) $r['created_at']))) ?></td></tr>
<?php endforeach; ?></tbody></table><?php endif; ?>
<p><a class="abtn" href="<?= h(a_url('leads')) ?>"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i>সব লিড দেখুন</a></p></div>
<?php endif; ?>
<?php if (can('content')): ?>
<div class="cards">
  <div class="stat"><b><?= (int) array_sum(array_column($contentRows, 'live')) ?></b><span>প্রকাশিত কনটেন্ট</span></div>
  <div class="stat"><b><?= (int) array_sum(array_column($contentRows, 'draft')) ?></b><span>খসড়া কনটেন্ট</span></div>
  <a class="stat <?= $seoSummary['warn_items'] ? 'warn' : '' ?>" href="<?= h(a_url('seo-content')) ?>"><b><?= (int) $seoSummary['warn_items'] ?></b><span>SEO সতর্কতা আছে এমন পেজ</span></a>
  <a class="stat" href="<?= h(a_url('media')) ?>"><b><?= (int) $mediaCount ?></b><span>মিডিয়া ফাইল (<?= h(number_format($mediaSize / 1048576, 1)) ?> MB)</span></a>
</div>
<?php if ($contentErrors): ?><div class="flash err">কিছু টেবিল পাওয়া যায়নি: <?= h(implode(', ', array_unique($contentErrors))) ?>। live database-এ <code>app/database/jebu_medical_full.sql</code> ইমপোর্ট করুন (এটি বারবার চালালেও ক্ষতি নেই)।</div><?php endif; ?>
<div class="panel"><h2>কনটেন্ট পরিসংখ্যান</h2>
<table class="stats-tbl"><thead><tr><th>ধরন</th><th>প্রকাশিত</th><th>খসড়া</th><th>মোট</th><th style="width:38%">অনুপাত</th></tr></thead><tbody>
<?php foreach ($contentRows as $r): ?>
<tr><td><div class="acts"><a class="abtn" href="<?= h(a_url('entity', ['e' => $r['k']])) ?>"><?= h($r['icon']) ?> <?= h($r['label']) ?></a><a class="abtn" href="<?= h(a_url('entity', ['e' => $r['k'], 'a' => 'edit'])) ?>" title="নতুন যোগ করুন"><i class="fa-solid fa-plus" aria-hidden="true"></i>নতুন</a></div></td><td><?= $r['live'] ?></td><td><?= $r['draft'] ?></td><td><b><?= $r['total'] ?></b></td>
<td><div class="bar" title="মোট <?= $r['total'] ?>"><i style="width:<?= $r['total'] > 0 ? max(3, round($r['total'] / $contentMax * 100)) : 0 ?>%"></i></div></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($activity): ?><div class="panel"><h2>সাম্প্রতিক কার্যকলাপ</h2>
<ul class="timeline"><?php foreach ($activity as $a): ?><li><small class="muted"><?= h(date('d M, h:i A', (int) strtotime((string) $a['created_at']))) ?></small> · <b><?= h((string) ($a['name'] ?? 'সিস্টেম')) ?></b> — <code><?= h($a['action']) ?></code> <?= h(mb_substr((string) $a['detail'], 0, 90)) ?></li><?php endforeach; ?></ul>
<p><a class="abtn" href="<?= h(a_url('logs')) ?>"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>সব লগ দেখুন</a></p></div><?php endif; ?>
<?php endif; ?>
<?php if (can('content')): ?>
<div class="panel"><h2>কনটেন্ট দ্রুত লিংক</h2><p>
<?php foreach (entities() as $k => $e): ?><a class="chip" href="<?= h(a_url('entity', ['e' => $k])) ?>"><?= h($e['label']) ?></a> <?php endforeach; ?></p></div>
<?php endif; ?>
<?php if ($top404): ?><div class="panel"><h2>সবচেয়ে বেশি ৪০৪ হওয়া URL</h2><ul><?php foreach ($top404 as $r): ?><li><code><?= h($r['url']) ?></code> — <?= (int) $r['hits'] ?> বার</li><?php endforeach; ?></ul></div><?php endif; ?>
<?php admin_foot();
