<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('leads.view');

// Opens WhatsApp with the confirmation text; a real click marks a new lead as "contacted".
if (!is_post()) {
    http_response_code(405);
    exit('POST অনুরোধ প্রয়োজন।');
}
csrf_require();
$id = (int) ($_POST['id'] ?? 0);
$lead = $id > 0 ? q_one('SELECT * FROM leads WHERE id = ?', [$id]) : null;
if (!$lead) {
    flash('err', 'লিডটি পাওয়া যায়নি।');
    redirect(a_url('leads'));
}
$url = whatsapp_confirm_url($lead);
if ($url === null) {
    flash('err', 'এই ফোন নম্বরটি সঠিক বাংলাদেশি মোবাইল নম্বর নয়। লিডটি খুলে নম্বর ঠিক করুন।');
    redirect(a_url('lead', ['id' => $id]));
}
if ($lead['status'] === 'new') {
    q_exec("UPDATE leads SET status = 'contacted', updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
}
log_activity('lead.whatsapp', '#' . $id, (int) $u['id']);
redirect($url);
