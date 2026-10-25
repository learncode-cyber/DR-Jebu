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
<p><a href="<?= h(a_url('leads')) ?>">সব লিড →</a></p></div>
<?php endif; ?>
<?php if (can('content')): ?>
<div class="panel"><h2>কনটেন্ট দ্রুত লিংক</h2><p>
<?php foreach (entities() as $k => $e): ?><a class="chip" href="<?= h(a_url('entity', ['e' => $k])) ?>"><?= h($e['label']) ?></a> <?php endforeach; ?></p></div>
<?php endif; ?>
<?php if ($top404): ?><div class="panel"><h2>সবচেয়ে বেশি ৪০৪ হওয়া URL</h2><ul><?php foreach ($top404 as $r): ?><li><code><?= h($r['url']) ?></code> — <?= (int) $r['hits'] ?> বার</li><?php endforeach; ?></ul></div><?php endif; ?>
<?php admin_foot();
