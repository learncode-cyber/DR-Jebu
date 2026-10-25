<?php
declare(strict_types=1);

/**
 * Lead orchestration: validate -> store -> event trail -> queue notifications -> dispatch.
 */

const LEAD_STATUSES = [
    'new' => 'নতুন', 'contacted' => 'যোগাযোগ হয়েছে', 'booked' => 'সিরিয়াল নিশ্চিত',
    'visited' => 'চেম্বারে এসেছেন', 'closed' => 'বন্ধ', 'spam' => 'স্প্যাম',
];

function lead_event(int $leadId, string $type, string $detail = '', ?int $userId = null): void {
    q_exec('INSERT INTO lead_events (lead_id, user_id, event_type, detail) VALUES (?,?,?,?)', [$leadId, $userId, $type, mb_substr($detail, 0, 500)]);
}

/**
 * @return array{ok:bool,error?:string,id?:int,duplicate?:bool}
 */
function lead_create(array $in, string $kind = 'appointment'): array {
    $clip = static fn(string $k, int $n): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $n);
    $name = $clip('name', 100);
    $phone = mb_substr(phone_digits($clip('phone', 30)), 0, 25);
    $email = $clip('email', 190);
    $age = preg_replace('/\D/', '', strtr($clip('age', 6), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'])) ?? '';
    $age = mb_substr($age, 0, 3);

    if (mb_strlen($name) < 2) {
        return ['ok' => false, 'error' => 'নাম লিখুন (কমপক্ষে ২ অক্ষর)।'];
    }
    if (!preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
        return ['ok' => false, 'error' => 'সঠিক মোবাইল নম্বর দিন।'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'ইমেইল ঠিকানাটি সঠিক নয়।'];
    }
    if ($kind === 'contact' && mb_strlen($clip('message', 1500)) < 3) {
        return ['ok' => false, 'error' => 'আপনার বার্তাটি লিখুন।'];
    }

    $ip = client_ip();
    // Basic flood control: max 5 submissions per IP per 10 minutes.
    if ($ip !== '') {
        $since = date('Y-m-d H:i:s', time() - 600);
        if ((int) q_val('SELECT COUNT(*) FROM leads WHERE ip = ? AND created_at >= ?', [$ip, $since]) >= 5) {
            return ['ok' => false, 'error' => 'অল্প সময়ে অনেকগুলো অনুরোধ এসেছে। কিছুক্ষণ পর আবার চেষ্টা করুন বা সরাসরি ফোন করুন।'];
        }
    }

    // Duplicate (same phone + kind within 15 minutes): acknowledge, don't re-notify.
    $dupSince = date('Y-m-d H:i:s', time() - 900);
    $dup = q_val('SELECT id FROM leads WHERE phone = ? AND kind = ? AND created_at >= ? ORDER BY id DESC LIMIT 1', [$phone, $kind, $dupSince]);
    if ($dup) {
        lead_event((int) $dup, 'duplicate_submit', 'একই নম্বর থেকে আবার অনুরোধ');
        return ['ok' => true, 'id' => (int) $dup, 'duplicate' => true];
    }

    $id = q_insert(
        'INSERT INTO leads (kind, name, phone, email, age, preferred_date, preferred_day, service_interest, message, source_page, utm_source, utm_medium, utm_campaign, ip, user_agent)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $kind, $name, $phone, $email, $age !== '' ? $age : null,
            $clip('preferred_date', 40) ?: null, $clip('preferred_day', 20) ?: null,
            $clip('service_interest', 160), $clip('message', 1500), $clip('source_page', 190),
            $clip('utm_source', 100), $clip('utm_medium', 100), $clip('utm_campaign', 150),
            $ip, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        ]
    );
    lead_event($id, 'created', $kind === 'appointment' ? 'অ্যাপয়েন্টমেন্ট অনুরোধ (ওয়েবসাইট)' : 'যোগাযোগ বার্তা (ওয়েবসাইট)');
    log_activity('lead.created', '#' . $id . ' ' . $kind);
    lead_enqueue_notifications($id);
    return ['ok' => true, 'id' => $id];
}

function notify_target_email(): string {
    $e = trim(setting('notify_email'));
    return $e !== '' ? $e : (string) NOTIFY_EMAIL_TO;
}

function lead_payload(array $lead): array {
    return [
        'event' => 'lead.created',
        'lead_id' => (int) $lead['id'], 'kind' => $lead['kind'], 'name' => $lead['name'], 'phone' => $lead['phone'],
        'email' => $lead['email'], 'age' => $lead['age'], 'preferred_date' => $lead['preferred_date'], 'preferred_day' => $lead['preferred_day'],
        'service_interest' => $lead['service_interest'], 'message' => $lead['message'], 'source_page' => $lead['source_page'],
        'utm' => ['source' => $lead['utm_source'], 'medium' => $lead['utm_medium'], 'campaign' => $lead['utm_campaign']],
        'created_at' => $lead['created_at'],
    ];
}

function lead_enqueue_notifications(int $id): void {
    $lead = q_one('SELECT * FROM leads WHERE id = ?', [$id]);
    if (!$lead) {
        return;
    }
    $kindBn = $lead['kind'] === 'appointment' ? 'অ্যাপয়েন্টমেন্ট অনুরোধ' : 'যোগাযোগ বার্তা';
    $lines = [
        'নতুন ' . $kindBn . ' (#' . $id . ')', '',
        'নাম: ' . $lead['name'], 'মোবাইল: ' . $lead['phone'],
    ];
    foreach (['email' => 'ইমেইল', 'age' => 'বয়স', 'preferred_date' => 'পছন্দের তারিখ', 'preferred_day' => 'দিন', 'service_interest' => 'সেবা', 'message' => 'বার্তা', 'source_page' => 'যে পেজ থেকে'] as $k => $label) {
        if (!empty($lead[$k])) {
            $lines[] = $label . ': ' . $lead[$k];
        }
    }
    $lines[] = '';
    $lines[] = 'অ্যাডমিন: ' . abs_url('/' . ADMIN_PATH . '/lead?id=' . $id);

    $to = notify_target_email();
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        q_exec('INSERT INTO notifications (lead_id, channel, recipient, subject, payload) VALUES (?,?,?,?,?)', [$id, 'email', $to, 'নতুন ' . $kindBn . ' — ' . $lead['name'], implode("\n", $lines)]);
    } else {
        lead_event($id, 'notify_skipped', 'ক্লিনিক ইমেইল সেট করা নেই');
    }
    if (!empty($lead['email']) && MAIL_FROM !== '') {
        $ack = "প্রিয় " . $lead['name'] . ",\n\nআপনার " . $kindBn . " আমরা পেয়েছি। আমাদের টিম শীঘ্রই " . $lead['phone'] . " নম্বরে যোগাযোগ করে সময় নিশ্চিত করবে।\n\n— " . setting('doctor_name_bn') . "\n" . abs_url('/');
        q_exec('INSERT INTO notifications (lead_id, channel, recipient, subject, payload) VALUES (?,?,?,?,?)', [$id, 'email', $lead['email'], 'আপনার অনুরোধ পেয়েছি — ' . setting('doctor_name_bn'), $ack]);
    }
    if (TELEGRAM_BOT_TOKEN !== '' && TELEGRAM_CHAT_ID !== '') {
        q_exec('INSERT INTO notifications (lead_id, channel, recipient, subject, payload) VALUES (?,?,?,?,?)', [$id, 'telegram', TELEGRAM_CHAT_ID, 'lead', implode("\n", $lines)]);
    }
    if (LEAD_WEBHOOK_URL !== '') {
        q_exec('INSERT INTO notifications (lead_id, channel, recipient, subject, payload) VALUES (?,?,?,?,?)', [$id, 'webhook', LEAD_WEBHOOK_URL, 'lead.created', json_encode(lead_payload($lead), JSON_UNESCAPED_UNICODE)]);
    }
}

function notification_send(array $n): array {
    if ($n['channel'] === 'email') {
        if (MAIL_FROM === '') {
            return [false, 'MAIL_FROM সেট করা নেই'];
        }
        $headers = [
            'From: ' . mb_encode_mimeheader(setting('doctor_name_bn'), 'UTF-8') . ' <' . MAIL_FROM . '>',
            'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
        ];
        $ok = @mail($n['recipient'], mb_encode_mimeheader($n['subject'], 'UTF-8'), $n['payload'], implode("\r\n", $headers));
        return [$ok, $ok ? '' : 'mail() ব্যর্থ'];
    }
    if ($n['channel'] === 'telegram') {
        if (!function_exists('curl_init') || TELEGRAM_BOT_TOKEN === '') {
            return [false, 'Telegram সেট করা নেই'];
        }
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_POSTFIELDS => ['chat_id' => $n['recipient'], 'text' => mb_substr((string) $n['payload'], 0, 4000), 'disable_web_page_preview' => 'true'],
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ok = $res !== false && $code === 200;
        return [$ok, $ok ? '' : ($err !== '' ? $err : 'Telegram HTTP ' . $code)];
    }
    if ($n['channel'] === 'webhook') {
        if (!function_exists('curl_init')) {
            return [false, 'curl নেই'];
        }
        $hdr = ['Content-Type: application/json; charset=utf-8'];
        if (LEAD_WEBHOOK_SECRET !== '') {
            $hdr[] = 'X-Signature: sha256=' . hash_hmac('sha256', $n['payload'], LEAD_WEBHOOK_SECRET);
        }
        $ch = curl_init($n['recipient']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $n['payload'], CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ok = $res !== false && $code >= 200 && $code < 300;
        return [$ok, $ok ? '' : ($err !== '' ? $err : 'HTTP ' . $code)];
    }
    return [false, 'unknown channel'];
}

/** Send queued/failed notifications (max 5 attempts each). Returns number sent. */
function notifications_dispatch(int $limit = 20): int {
    $rows = q_all("SELECT * FROM notifications WHERE status IN ('queued','failed') AND attempts < 5 ORDER BY id ASC LIMIT " . (int) $limit);
    $sent = 0;
    foreach ($rows as $n) {
        [$ok, $err] = notification_send($n);
        if ($ok) {
            q_exec("UPDATE notifications SET status = 'sent', attempts = attempts + 1, last_error = '', sent_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $n['id']]);
            lead_event((int) $n['lead_id'], 'notified_' . $n['channel'], 'প্রাপক: ' . mb_substr((string) $n['recipient'], 0, 80));
            $sent++;
        } else {
            q_exec("UPDATE notifications SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?", [mb_substr($err, 0, 300), $n['id']]);
            lead_event((int) $n['lead_id'], 'notify_failed_' . $n['channel'], mb_substr($err, 0, 200));
        }
    }
    return $sent;
}

/** Run dispatch after the HTTP response has been flushed to the visitor. */
function notifications_dispatch_after_response(): void {
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            notifications_dispatch(10);
        } catch (Throwable $e) {
            error_log('[jebu] dispatch: ' . $e->getMessage());
        }
    });
}
