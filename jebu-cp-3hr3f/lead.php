<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('leads.view');
$id = (int) ($_GET['id'] ?? 0);
$lead = q_one('SELECT * FROM leads WHERE id = ?', [$id]);
if (!$lead) {
    flash('err', 'লিড পাওয়া যায়নি।');
    redirect(a_url('leads'));
}
if (is_post()) {
    require_perm('leads.edit');
    csrf_require();
    $act = (string) ($_POST['act'] ?? 'save');
    if ($act === 'save') {
        $st = (string) ($_POST['status'] ?? $lead['status']);
        if (!isset(LEAD_STATUSES[$st])) { $st = $lead['status']; }
        $as = (int) ($_POST['assigned_to'] ?? 0);
        $asVal = $as > 0 && q_val('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1', [$as]) ? $as : null;
        $notes = mb_substr((string) ($_POST['notes'] ?? ''), 0, 5000);
        q_exec('UPDATE leads SET status = ?, assigned_to = ?, notes = ?, updated_at = ? WHERE id = ?', [$st, $asVal, $notes, date('Y-m-d H:i:s'), $id]);
        if ($st !== $lead['status']) { lead_event($id, 'status', $lead['status'] . ' → ' . $st, (int) $u['id']); }
        if ((int) ($lead['assigned_to'] ?? 0) !== (int) $asVal) { lead_event($id, 'assigned', 'দায়িত্বে: #' . ($asVal ?? '—'), (int) $u['id']); }
        if ($notes !== (string) $lead['notes']) { lead_event($id, 'note', 'নোট হালনাগাদ', (int) $u['id']); }
        flash('ok', 'সংরক্ষিত হয়েছে।');
    } elseif ($act === 'resend') {
        q_exec("UPDATE notifications SET status = 'queued', attempts = 0 WHERE lead_id = ? AND status IN ('failed','skipped')", [$id]);
        $n = notifications_dispatch(10);
        flash('ok', 'নোটিফিকেশন আবার পাঠানো হয়েছে (সফল: ' . $n . ')।');
    } elseif ($act === 'delete' && can('users')) {
        q_exec('DELETE FROM leads WHERE id = ?', [$id]);
        log_activity('lead.deleted', '#' . $id, (int) $u['id']);
        flash('ok', 'লিড মুছে ফেলা হয়েছে।');
        redirect(a_url('leads'));
    }
    redirect(a_url('lead', ['id' => $id]));
}
$events = q_all('SELECT e.*, u.name uname FROM lead_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.lead_id = ? ORDER BY e.id DESC', [$id]);
$notifs = q_all('SELECT * FROM notifications WHERE lead_id = ? ORDER BY id DESC', [$id]);
$staff = q_all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
admin_head('লিড #' . $id);
$row = static fn(string $l, ?string $v) => $v !== null && $v !== '' ? '<tr><th>' . h($l) . '</th><td>' . nl2br(h($v), false) . '</td></tr>' : '';
?>
<div class="two">
<div class="panel"><h2>তথ্য</h2>
  <table class="kv"><?= $row('ধরন', $lead['kind'] === 'appointment' ? 'অ্যাপয়েন্টমেন্ট' : 'যোগাযোগ বার্তা') ?>
  <tr><th>নাম</th><td><?= h($lead['name']) ?></td></tr>
  <tr><th>ফোন</th><td><a href="tel:<?= h($lead['phone']) ?>"><?= h($lead['phone']) ?></a> · <a target="_blank" rel="noopener" href="https://wa.me/<?= h(preg_replace('/\D/', '', preg_replace('/^0/', '880', $lead['phone']))) ?>">WhatsApp</a></td></tr>
  <?= $row('ইমেইল', $lead['email']) . $row('বয়স', $lead['age']) . $row('পছন্দের তারিখ', $lead['preferred_date']) . $row('দিন', $lead['preferred_day']) . $row('সেবা', $lead['service_interest']) . $row('বার্তা', $lead['message']) . $row('যে পেজ থেকে', $lead['source_page']) . $row('UTM', trim($lead['utm_source'] . ' / ' . $lead['utm_medium'] . ' / ' . $lead['utm_campaign'], ' /')) . $row('IP', $lead['ip']) . $row('সময়', $lead['created_at']) ?></table>
</div>
<div class="panel"><h2>ব্যবস্থাপনা</h2>
<?php if (can('leads.edit')): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <label>স্ট্যাটাস<select name="status"><?php foreach (LEAD_STATUSES as $k => $l): ?><option value="<?= h($k) ?>" <?= $lead['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
    <label>দায়িত্বে<select name="assigned_to"><option value="0">— কেউ নয় —</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $lead['assigned_to'] === (int) $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?></select></label>
    <label>অভ্যন্তরীণ নোট <small class="muted">(রোগের বিস্তারিত চিকিৎসা তথ্য এখানে রাখবেন না)</small><textarea name="notes" rows="5"><?= h((string) $lead['notes']) ?></textarea></label>
    <button class="btn" type="submit">সংরক্ষণ</button>
  </form>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="resend"><button class="btn ghost" type="submit">নোটিফিকেশন আবার পাঠান</button></form>
<?php if (can('users')): ?><form method="post" class="inline" onsubmit="return confirm('লিডটি স্থায়ীভাবে মুছে ফেলবেন?')"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><button class="btn danger" type="submit">মুছুন</button></form><?php endif; ?>
<?php else: ?><p class="muted">আপনার শুধু দেখার অনুমতি আছে।</p><?php endif; ?>
</div></div>
<div class="two">
<div class="panel"><h2>ট্র্যাকিং ইতিহাস</h2><ul class="timeline"><?php foreach ($events as $e): ?><li><b><?= h($e['event_type']) ?></b> <?= h($e['detail']) ?> <small class="muted"><?= h($e['uname'] ?? '') ?> · <?= h((string) $e['created_at']) ?></small></li><?php endforeach; ?></ul></div>
<div class="panel"><h2>নোটিফিকেশন</h2><?php if (!$notifs): ?><p class="muted">কোনো নোটিফিকেশন কিউতে নেই।</p><?php endif; ?><ul class="timeline"><?php foreach ($notifs as $n): ?><li><b><?= h($n['channel']) ?></b> → <?= h(mb_substr((string) $n['recipient'], 0, 60)) ?> <span class="tag nf-<?= h($n['status']) ?>"><?= h($n['status']) ?></span><?php if ($n['last_error']): ?> <small class="muted"><?= h($n['last_error']) ?></small><?php endif; ?></li><?php endforeach; ?></ul></div>
</div>
<?php admin_foot();
