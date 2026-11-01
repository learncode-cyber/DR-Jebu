<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_perm('leads.view');

$status = (string) ($_GET['status'] ?? '');
$kind = (string) ($_GET['kind'] ?? '');
$qstr = trim((string) ($_GET['q'] ?? ''));
$where = ['1=1'];
$params = [];
if (isset(LEAD_STATUSES[$status])) { $where[] = 'status = ?'; $params[] = $status; }
if (in_array($kind, ['appointment', 'contact'], true)) { $where[] = 'kind = ?'; $params[] = $kind; }
if ($qstr !== '') { $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)'; $like = '%' . $qstr . '%'; array_push($params, $like, $like, $like); }
$W = implode(' AND ', $where);

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'ধরন', 'নাম', 'ফোন', 'ইমেইল', 'বয়স', 'তারিখ', 'দিন', 'সেবা', 'বার্তা', 'স্ট্যাটাস', 'UTM source', 'UTM medium', 'UTM campaign', 'সময়']);
    foreach (q_all("SELECT * FROM leads WHERE $W ORDER BY id DESC", $params) as $r) {
        $cell = static fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v; // CSV-injection guard
        fputcsv($out, array_map($cell, [$r['id'], $r['kind'], $r['name'], $r['phone'], $r['email'], $r['age'], $r['preferred_date'], $r['preferred_day'], $r['service_interest'], $r['message'], $r['status'], $r['utm_source'], $r['utm_medium'], $r['utm_campaign'], $r['created_at']]));
    }
    exit;
}

$per = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) q_val("SELECT COUNT(*) FROM leads WHERE $W", $params);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$rows = q_all("SELECT * FROM leads WHERE $W ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$base = ['status' => $status, 'kind' => $kind, 'q' => $qstr];

admin_head('লিডস / সিরিয়াল অনুরোধ');
?>
<form class="filters" method="get">
  <input type="text" name="q" value="<?= h($qstr) ?>" placeholder="নাম / ফোন / ইমেইল">
  <select name="status"><option value="">সব স্ট্যাটাস</option><?php foreach (LEAD_STATUSES as $k => $l): ?><option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <select name="kind"><option value="">সব ধরন</option><option value="appointment" <?= $kind === 'appointment' ? 'selected' : '' ?>>সিরিয়াল</option><option value="contact" <?= $kind === 'contact' ? 'selected' : '' ?>>বার্তা</option></select>
  <button class="btn" type="submit">ফিল্টার</button>
  <a class="btn ghost" href="<?= h(a_url('leads', $base + ['export' => 1])) ?>">CSV এক্সপোর্ট</a>
</form>
<div class="panel">
<p class="muted">মোট <?= $total ?>টি</p>
<div class="tbl-wrap"><table><thead><tr><th>#</th><th>ধরন</th><th>নাম</th><th>ফোন</th><th>পছন্দের তারিখ</th><th>সেবা</th><th>স্ট্যাটাস</th><th>সময়</th><th>যোগাযোগ</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td><a href="<?= h(a_url('lead', ['id' => $r['id']])) ?>">#<?= (int) $r['id'] ?></a></td><td><?= $r['kind'] === 'appointment' ? 'সিরিয়াল' : 'বার্তা' ?></td><td><?= h($r['name']) ?></td><td><a href="tel:<?= h($r['phone']) ?>"><?= h($r['phone']) ?></a></td><td><?= h(trim(($r['preferred_date'] ?? '') . ' ' . ($r['preferred_day'] ?? ''))) ?></td><td><?= h($r['service_interest']) ?></td><td><span class="tag st-<?= h($r['status']) ?>"><?= h(LEAD_STATUSES[$r['status']] ?? $r['status']) ?></span></td><td><?= h(date('d M Y, h:i A', (int) strtotime((string) $r['created_at']))) ?></td><td><?php if (whatsapp_confirm_url($r)): ?><form method="post" action="<?= h(a_url('wa')) ?>" class="inline" target="_blank" rel="noopener"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="abtn wa" title="WhatsApp-এ সিরিয়াল নিশ্চিত করার মেসেজ"><i class="fa-brands fa-whatsapp"></i> WhatsApp</button></form><?php else: ?><span class="muted">নম্বর ঠিক নেই</span><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="9" class="muted">কোনো লিড পাওয়া যায়নি।</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($pages > 1): ?><div class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'cur' : '' ?>" href="<?= h(a_url('leads', $base + ['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
</div>
<?php admin_foot();
