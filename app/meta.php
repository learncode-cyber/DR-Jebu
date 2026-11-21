<?php
declare(strict_types=1);

/**
 * Facebook Page + Messenger AI assistant: configuration, tables and safety rules.
 * Secrets (App Secret, Page Access Token) live only in site_settings, set from the admin panel.
 * Nothing here ever prints a secret; logs use meta_redact().
 */

const META_GRAPH_DEFAULT_VERSION = 'v26.0';
const META_HANDOFF_HOURS_DEFAULT = 12;   // when a human takes over, the bot stays quiet this long
const META_DAILY_REPLY_LIMIT = 300;      // per conversation, per day (anti-loop / cost guard)
const META_MAX_REPLY_CHARS = 900;        // long answers are split before sending
const META_WINDOW_HOURS = 24;            // Messenger standard messaging window

/** All Meta settings in one place. */
function meta_cfg(): array {
    $v = static fn(string $k, string $d = '') => trim(setting($k, $d));
    return [
        'enabled'        => $v('fb_bot_enabled', '0') === '1',
        'app_id'         => $v('meta_app_id'),
        'app_secret'     => $v('meta_app_secret'),
        'page_id'        => $v('meta_page_id'),
        'page_token'     => $v('meta_page_token'),
        'verify_token'   => $v('meta_verify_token'),
        'graph_version'  => $v('meta_graph_version', META_GRAPH_DEFAULT_VERSION),
        'handoff_hours'  => max(1, (int) ($v('fb_handoff_hours', (string) META_HANDOFF_HOURS_DEFAULT))),
        'ig_enabled'     => $v('ig_bot_enabled', '0') === '1',
        'ig_user_id'     => $v('meta_ig_user_id'),
    ];
}

/** The bot answers only when it is switched on AND every credential it needs is present. */
function meta_missing(): array {
    $c = meta_cfg();
    $miss = [];
    if ($c['app_secret'] === '') { $miss[] = 'Meta App Secret'; }
    if ($c['page_id'] === '') { $miss[] = 'Facebook Page ID'; }
    if ($c['page_token'] === '') { $miss[] = 'Page Access Token'; }
    if ($c['verify_token'] === '') { $miss[] = 'Webhook Verify Token'; }
    if (!ai_enabled()) { $miss[] = 'Gemini API Key'; }
    return $miss;
}

function meta_enabled(): bool {
    return meta_cfg()['enabled'] && meta_missing() === [];
}

/** Instagram conversations use the "ig:" prefix on their ID; they need their own switch and the Instagram account ID. */
function meta_channel_enabled(string $psid): bool {
    if (str_starts_with($psid, 'ig:')) {
        $c = meta_cfg();
        return $c['ig_enabled'] && $c['ig_user_id'] !== '' && meta_missing() === [];
    }
    return meta_enabled();
}

/** Strips the channel prefix to get the raw recipient ID. */
function meta_raw_id(string $psid): string {
    return str_starts_with($psid, 'ig:') ? substr($psid, 3) : $psid;
}

/** Mask a secret or token for display or logs: keep only the last 4 characters. */
function meta_redact(string $s): string {
    $s = (string) $s;
    if ($s === '') { return ''; }
    if (strlen($s) <= 8) { return str_repeat('•', strlen($s)); }
    return str_repeat('•', 6) . substr($s, -4);
}

/** Creates the Messenger tables once per request (same pattern as system_alerts). */
function meta_tables(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS fb_conversations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        psid VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL DEFAULT '',
        lang VARCHAR(8) NOT NULL DEFAULT 'bn',
        lead_id INT UNSIGNED NULL,
        paused_until DATETIME NULL,
        last_inbound_at DATETIME NULL,
        last_outbound_at DATETIME NULL,
        day_date DATE NULL,
        day_replies INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_psid (psid)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    q_exec("CREATE TABLE IF NOT EXISTS fb_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT UNSIGNED NOT NULL,
        mid VARCHAR(190) NULL,
        direction VARCHAR(3) NOT NULL,
        body TEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'ok',
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_mid (mid),
        KEY idx_conv (conversation_id, created_at)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Returns the conversation row for a Messenger user (PSID), creating it on first contact. */
function fb_conversation_for(string $psid): array {
    meta_tables();
    $row = q_one('SELECT * FROM fb_conversations WHERE psid = ?', [$psid]);
    if ($row) {
        return $row;
    }
    $now = date('Y-m-d H:i:s');
    q_insert('INSERT INTO fb_conversations (psid, created_at, updated_at) VALUES (?, ?, ?)', [$psid, $now, $now]);
    return q_one('SELECT * FROM fb_conversations WHERE psid = ?', [$psid]) ?: [];
}

/**
 * Safety gate before the bot answers a message.
 * Returns '' when it may reply, otherwise a short reason code (for the log/admin view).
 */
function fb_reply_blocked(array $conv): string {
    if (!empty($conv['paused_until']) && strtotime((string) $conv['paused_until']) > time()) {
        return 'handoff';
    }
    $today = date('Y-m-d');
    $count = ($conv['day_date'] ?? null) === $today ? (int) $conv['day_replies'] : 0;
    if ($count >= META_DAILY_REPLY_LIMIT) {
        return 'daily_limit';
    }
    return '';
}

/** Words a patient or staff can use to hand the chat to a human (case-insensitive). */
function fb_handoff_requested(string $text): bool {
    $t = mb_strtolower($text);
    foreach (['human', 'manush', 'staff', 'মানুষের সাথে', 'কথা বলতে চাই'] as $w) {
        if (mb_strpos($t, mb_strtolower($w)) !== false) {
            return true;
        }
    }
    return false;
}

/** Pause the bot for this conversation so a human can answer. */
function fb_pause(int $convId, int $hours): void {
    $until = date('Y-m-d H:i:s', time() + $hours * 3600);
    q_exec('UPDATE fb_conversations SET paused_until = ?, updated_at = ? WHERE id = ?', [$until, date('Y-m-d H:i:s'), $convId]);
}

/** Safety rules given to the model; kept in code, not in the admin, so the owner cannot accidentally remove them. */
function fb_safety_rules(): string {
    return "Hard rules (never break): "
        . "(1) You are an AI assistant; if asked, say so honestly. "
        . "(2) Never diagnose, never name medicines, doses or treatment plans; advise a doctor visit. "
        . "(3) For emergency signs (heavy bleeding, chest pain, breathing trouble, pregnancy bleeding, severe pain, fits) tell the person to call 999 or go to the nearest hospital at once. "
        . "(4) Never invent fees, discounts, statistics, guarantees, success rates or testimonials. "
        . "(5) Reply like a helpful human receptionist: short, warm, one question at a time, no long lists. "
        . "(6) Never collect a name, phone number or any health detail in this chat (owner decision). To book, send the appointment form link from the site and say they can fill it there. "
        . "(7) If the person asks for a human, say a staff member will reply soon and stop.";
}
