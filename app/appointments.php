<?php
declare(strict_types=1);

/**
 * Appointment reminders.
 * The admin sets a real appointment time (leads.appt_at). The cron queues ONE reminder per appointment
 * within 24 hours before it. Patients with an email get an email; patients without one are listed once
 * in a Telegram digest so the owner can call them.
 */

const APPT_REMINDER_HOURS = 24;

function appt_schema(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    q_exec('ALTER TABLE leads ADD COLUMN IF NOT EXISTS appt_at DATETIME NULL');
    q_exec('ALTER TABLE leads ADD COLUMN IF NOT EXISTS reminder_sent_at DATETIME NULL');
    q_exec('ALTER TABLE leads ADD COLUMN IF NOT EXISTS confirm_sent_at DATETIME NULL');
    q_exec('ALTER TABLE leads ADD COLUMN IF NOT EXISTS wa_done_at DATETIME NULL');
}

/** Parses the admin's datetime-local input (Asia/Dhaka). Returns 'Y-m-d H:i:s' or null. */
function appt_parse_input(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') { return null; }
    $ts = strtotime(str_replace('T', ' ', $raw));
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Saves a structured appointment time. Changing the time re-arms the reminder. */
function appt_set(int $leadId, ?string $at): void {
    appt_schema();
    q_exec('UPDATE leads SET appt_at = ?, reminder_sent_at = NULL, wa_done_at = NULL, updated_at = ? WHERE id = ?', [$at, date('Y-m-d H:i:s'), $leadId]);
}

function appt_reminder_body(string $name, string $when): string {
    return 'প্রিয় ' . $name . ",\n\n"
        . "আগামীকাল (২৪ ঘণ্টার মধ্যে) আপনার অ্যাপয়েন্টমেন্ট রয়েছে।\n"
        . 'সময়: ' . $when . "\n\n"
        . "সময়মতো চেম্বারে আসার জন্য অনুরোধ রইল। সময় পরিবর্তন করতে হলে ফোন করুন: " . setting('phone_display') . "\n"
        . 'এই বার্তাটি স্বয়ংক্রিয়ভাবে পাঠানো হয়েছে।';
}

/** The free click-to-chat reminder text. The owner taps it, WhatsApp opens prefilled, and the owner presses send. */
function appt_wa_reminder_text(array $lead): string {
    $when = date('d.m.Y H:i', strtotime((string) $lead['appt_at']));
    return 'আসসালামু আলাইকুম ' . trim((string) $lead['name']) . ',' . "\n"
        . setting('doctor_name_bn', 'ডাঃ জেবুন্নেসা জেবু') . '-এর চেম্বারে আপনার অ্যাপয়েন্টমেন্ট আগামী ২৪ ঘণ্টার মধ্যে।' . "\n"
        . 'সময়: ' . $when . "\n"
        . 'সময়মতো আসার অনুরোধ রইল। পরিবর্তন লাগলে জানাবেন। ধন্যবাদ।';
}

/** wa.me link for a lead, or null when the phone number is not a valid Bangladeshi mobile. */
function appt_wa_url(array $lead): ?string {
    $num = bd_whatsapp_number((string) ($lead['phone'] ?? ''));
    if ($num === null || empty($lead['appt_at'])) { return null; }
    return 'https://wa.me/' . $num . '?text=' . rawurlencode(appt_wa_reminder_text($lead));
}

/** Upcoming appointments (next 24h) whose WhatsApp reminder has not been marked as sent. */
function appt_wa_due(): array {
    appt_schema();
    $now = date('Y-m-d H:i:s');
    $until = date('Y-m-d H:i:s', time() + APPT_REMINDER_HOURS * 3600);
    return q_all("SELECT id, name, phone, email, appt_at FROM leads
        WHERE appt_at IS NOT NULL AND wa_done_at IS NULL AND appt_at > ? AND appt_at <= ?
          AND status IN ('new','contacted','booked') ORDER BY appt_at ASC LIMIT 100", [$now, $until]);
}

/**
 * Automatic confirmation when a lead becomes "booked" (সিরিয়াল নিশ্চিত).
 * Runs only when the switch is on, the appointment time is set, the email is valid,
 * and no confirmation was sent before. Returns true when an email was queued.
 */
function appt_auto_confirm(int $leadId): bool {
    appt_schema();
    if (setting('auto_confirm_booked', '0') !== '1') { return false; }
    $lead = q_one('SELECT id, name, email, appt_at, confirm_sent_at FROM leads WHERE id = ?', [$leadId]);
    if (!$lead || empty($lead['appt_at']) || !empty($lead['confirm_sent_at'])) { return false; }
    if (!filter_var((string) $lead['email'], FILTER_VALIDATE_EMAIL) || MAIL_FROM === '') { return false; }
    $when = date('d.m.Y H:i', strtotime((string) $lead['appt_at']));
    $body = 'প্রিয় ' . $lead['name'] . ",\n\n"
        . "আপনার অ্যাপয়েন্টমেন্ট নিশ্চিত করা হয়েছে।\n"
        . 'সময়: ' . $when . "\n\n"
        . 'চেম্বারে আসার আগে কোনো প্রশ্ন থাকলে ' . setting('phone_display') . " নম্বরে যোগাযোগ করুন।\n"
        . 'এই বার্তাটি স্বয়ংক্রিয়ভাবে পাঠানো হয়েছে।';
    q_exec('INSERT INTO notifications (lead_id, channel, recipient, subject, payload) VALUES (?,?,?,?,?)',
        [$leadId, 'email', (string) $lead['email'], 'আপনার অ্যাপয়েন্টমেন্ট নিশ্চিত হয়েছে', $body]);
    q_exec('UPDATE leads SET confirm_sent_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $leadId]);
    return true;
}

/** Queues reminders that are due. Returns [emails queued, telegram digests sent]. */
function appt_reminders_run(int $limit = 50): array {
    appt_schema();
    $now = date('Y-m-d H:i:s');
    $until = date('Y-m-d H:i:s', time() + APPT_REMINDER_HOURS * 3600);
    $rows = q_all("SELECT id, name, email, phone, appt_at FROM leads
        WHERE appt_at IS NOT NULL AND reminder_sent_at IS NULL AND appt_at > ? AND appt_at <= ?
          AND status IN ('new','contacted','booked') ORDER BY appt_at ASC LIMIT " . (int) $limit, [$now, $until]);
    $queued = 0;
    $digest = [];
    $digestIds = [];
    foreach ($rows as $r) {
        $when = date('d.m.Y H:i', strtotime((string) $r['appt_at']));
        $hasEmail = filter_var((string) $r['email'], FILTER_VALIDATE_EMAIL) && MAIL_FROM !== '';
        if ($hasEmail) {
            q_exec('INSERT INTO notifications (lead_id, channel, recipient, subject, payload) VALUES (?,?,?,?,?)',
                [(int) $r['id'], 'email', (string) $r['email'], 'আগামীকালের অ্যাপয়েন্টমেন্ট মনে করিয়ে দিচ্ছি', appt_reminder_body((string) $r['name'], $when)]);
            q_exec('UPDATE leads SET reminder_sent_at = ? WHERE id = ?', [$now, (int) $r['id']]);
            $queued++;
        } else {
            $wa = appt_wa_url($r);
            $digest[] = (string) $r['name'] . ' · ' . (string) $r['phone'] . ' · ' . $when . ($wa ? "\n  " . $wa : '');
            $digestIds[] = (int) $r['id'];
        }
    }
    $sent = 0;
    if ($digest) {
        $text = "📞 ইমেইল নেই, ফোনে বা হোয়াটসঅ্যাপে মনে করিয়ে দিন (আগামী ২৪ ঘণ্টা):\n" . implode("\n", $digest);
        [$ok, $err] = telegram_send_text($text);
        if ($ok) {
            // Marked only after Telegram accepted the digest, so a failed send is retried on the next run.
            foreach ($digestIds as $id) { q_exec('UPDATE leads SET reminder_sent_at = ? WHERE id = ?', [$now, $id]); }
            $sent = count($digest);
        } else {
            system_alert('warning', 'reminder', 'Telegram digest failed: ' . $err);
        }
    }
    return [$queued, $sent];
}
