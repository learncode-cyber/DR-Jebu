<?php
declare(strict_types=1);

/** Verifies Meta's subscription handshake (GET). Returns the challenge string, or null when refused. */
function fb_verify_handshake(array $query): ?string {
    $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
    $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
    $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');
    $expected = meta_cfg()['verify_token'];
    if ($mode !== 'subscribe' || $expected === '' || $challenge === '' || !hash_equals($expected, $token)) {
        return null;
    }
    return $challenge;
}

/** Checks X-Hub-Signature-256 against the raw body with the App Secret. */
function fb_signature_ok(string $rawBody, string $header): bool {
    $secret = meta_cfg()['app_secret'];
    if ($secret === '' || !str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $header);
}

/**
 * Turns a webhook payload into inbound text messages.
 * Returns [{psid, mid, text, ts}]. Echoes, read/delivery receipts and comments are skipped on purpose.
 */
function fb_parse_messages(array $payload): array {
    $object = (string) ($payload['object'] ?? '');
    if ($object !== 'page' && $object !== 'instagram') {
        return [];
    }
    $prefix = $object === 'instagram' ? 'ig:' : '';
    $ownIg = meta_cfg()['ig_user_id'];
    $out = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['messaging'] ?? []) as $ev) {
            $rawSender = (string) ($ev['sender']['id'] ?? '');
            if ($object === 'instagram' && $rawSender === $ownIg) { continue; } // our own account
            $psid = $rawSender !== '' ? $prefix . $rawSender : '';
            $msg = $ev['message'] ?? null;
            if ($psid === '' || !is_array($msg) || !empty($msg['is_echo'])) {
                continue;
            }
            $mid = (string) ($msg['mid'] ?? '');
            $text = trim((string) ($msg['text'] ?? ''));
            if ($mid === '' || $text === '') {
                continue; // attachments/stickers: handled later with a polite fallback
            }
            $out[] = ['psid' => $psid, 'mid' => $mid, 'text' => mb_substr($text, 0, 1000), 'ts' => (int) ($ev['timestamp'] ?? 0)];
        }
    }
    return $out;
}

/**
 * Stores inbound messages once (unique mid). Returns the rows that are new, ready to answer.
 */
function fb_store_inbound(array $msgs): array {
    meta_tables();
    $fresh = [];
    $now = date('Y-m-d H:i:s');
    foreach ($msgs as $m) {
        $conv = fb_conversation_for($m['psid']);
        try {
            q_exec('INSERT INTO fb_messages (conversation_id, mid, direction, body, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [(int) $conv['id'], $m['mid'], 'in', $m['text'], 'ok', $now]);
        } catch (PDOException $e) {
            continue; // duplicate mid: Meta re-sent this event, already handled
        }
        q_exec('UPDATE fb_conversations SET last_inbound_at = ?, updated_at = ? WHERE id = ?', [$now, $now, (int) $conv['id']]);
        $fresh[] = $m + ['conv_id' => (int) $conv['id']];
    }
    return $fresh;
}

/** Sends the 200 to Meta first, then answers the messages, so Meta never times out or retries. */
function fb_finish_response_then(callable $work): void {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }
    $work();
}
