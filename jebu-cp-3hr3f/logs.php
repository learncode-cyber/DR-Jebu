<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_perm('logs');
$t = (string) ($_GET['t'] ?? 'activity');
admin_head('লগ');
?>
<p class="tabs"><?php foreach (['activity' => 'অ্যাক্টিভিটি', '404' => '৪০৪ লগ', 'notifications' => 'নোটিফিকেশন'] as $k => $l): ?><a class="chip <?= $t === $k ? 'on' : '' ?>" href="<?= h(a_url('logs', ['t' => $k])) ?>"><?= h($l) ?></a> <?php endforeach; ?></p>
<div class="panel"><div class="tbl-wrap">
<?php if ($t === '404'): $rows = q_all('SELECT * FROM not_found_log ORDER BY last_seen DESC LIMIT 200'); ?>
<table><thead><tr><th>URL</th><th>বার</th><th>Referrer</th><th>শেষ</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><code><?= h($r['url']) ?></code></td><td><?= (int) $r['hits'] ?></td><td><?= h($r['referrer']) ?></td><td><?= h((string) $r['last_seen']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php elseif ($t === 'notifications'): $rows = q_all('SELECT * FROM notifications ORDER BY id DESC LIMIT 200'); ?>
<table><thead><tr><th>#</th><th>লিড</th><th>চ্যানেল</th><th>প্রাপক</th><th>স্ট্যাটাস</th><th>চেষ্টা</th><th>ত্রুটি</th><th>সময়</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= (int) $r['id'] ?></td><td><a href="<?= h(a_url('lead', ['id' => $r['lead_id']])) ?>">#<?= (int) $r['lead_id'] ?></a></td><td><?= h($r['channel']) ?></td><td><?= h(mb_substr((string) $r['recipient'], 0, 50)) ?></td><td><span class="tag nf-<?= h($r['status']) ?>"><?= h($r['status']) ?></span></td><td><?= (int) $r['attempts'] ?></td><td><?= h($r['last_error']) ?></td><td><?= h((string) $r['created_at']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php else: $rows = q_all('SELECT a.*, u.name uname FROM activity_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300'); ?>
<table><thead><tr><th>সময়</th><th>ইউজার</th><th>ঘটনা</th><th>বিবরণ</th><th>IP</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= h((string) $r['created_at']) ?></td><td><?= h((string) ($r['uname'] ?? '—')) ?></td><td><?= h($r['action']) ?></td><td><?= h($r['detail']) ?></td><td><?= h($r['ip']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
</div></div>
<?php admin_foot();
