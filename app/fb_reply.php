<?php
declare(strict_types=1);

/* ---------- Graph API sending (#24) ---------- */

/** Sends one text message to a Messenger user. Retries transient failures; reports real errors to admin alerts. */
function fb_graph_send(string $psid, string $text): array {
    $c = meta_cfg();
    if ($c['page_token'] === '' || $c['page_id'] === '') {
        return ['ok' => false, 'mid' => '', 'error' => 'page token missing'];
    }
    // Instagram DMs go through the Instagram account ID; Messenger through the Page.
    $isIg = str_starts_with($psid, 'ig:');
    if ($isIg && $c['ig_user_id'] === '') {
        return ['ok' => false, 'mid' => '', 'error' => 'instagram account id missing'];
    }
    $owner = $isIg ? rawurlencode($c['ig_user_id']) : 'me';
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . $owner . '/messages';
    $body = json_encode([
        'recipient' => ['id' => meta_raw_id($psid)],
        'messaging_type' => 'RESPONSE',
        'message' => ['text' => $text],
    ], JSON_UNESCAPED_UNICODE);
    $last = ['ok' => false, 'mid' => '', 'error' => 'no attempt'];
    for ($try = 0; $try < 3; $try++) {
        if ($try > 0) {
            usleep($try * 700000);
        }
        [$code, $res] = fb_http_post_json($url, $body, $c['page_token']);
        $json = json_decode($res, true);
        if ($code === 200 && is_array($json) && isset($json['message_id'])) {
            return ['ok' => true, 'mid' => (string) $json['message_id'], 'error' => ''];
        }
        $err = is_array($json['error'] ?? null) ? $json['error'] : [];
        $errCode = (int) ($err['code'] ?? 0);
        $last = ['ok' => false, 'mid' => '', 'error' => 'HTTP ' . $code . ' code ' . $errCode];
        if ($errCode === 190) {
            system_alert('critical', 'meta', 'Page Access Token invalid or expired. Update it in admin settings.');
            break;
        }
        // 4xx (except rate limits) will not succeed on retry, so stop early.
        if ($code >= 400 && $code < 500 && !in_array($errCode, [4, 17, 613], true)) {
            break;
        }
    }
    if (!$last['ok']) {
        system_alert('error', 'meta', 'Messenger send failed: ' . $last['error']);
    }
    return $last;
}

/** Shows the typing bubble. Best effort: a failure here never blocks the reply. */
function fb_typing(string $psid, bool $on = true): void {
    $c = meta_cfg();
    if ($c['page_token'] === '') { return; }
    $owner = str_starts_with($psid, 'ig:') ? rawurlencode($c['ig_user_id']) : 'me';
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . $owner . '/messages';
    fb_http_post_json($url, json_encode([
        'recipient' => ['id' => meta_raw_id($psid)],
        'sender_action' => $on ? 'typing_on' : 'typing_off',
    ]), $c['page_token'], 5);
}

/** Low-level POST with bearer auth (token stays out of the URL and out of logs). */
function fb_http_post_json(string $url, string $body, string $token, int $timeout = 15): array {
    if (isset($GLOBALS['fb_http_stub']) && is_callable($GLOBALS['fb_http_stub'])) { return ($GLOBALS['fb_http_stub'])($url, $body, $token, $timeout); }
    if (!function_exists('curl_init')) { return [0, '']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => $body,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res === false ? '' : (string) $res];
}

/** Splits a long answer into Messenger-sized pieces on sentence/line boundaries. */
function fb_split_reply(string $text, int $max = META_MAX_REPLY_CHARS): array {
    $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    $parts = [];
    while (mb_strlen($text) > $max) {
        $cut = null;
        foreach (["\n", '।', '. ', '? ', '! '] as $sep) {
            $pos = mb_strrpos(mb_substr($text, 0, $max), $sep);
            if ($pos !== false && $pos > $max * 0.5) { $cut = $pos + mb_strlen($sep); break; }
        }
        $cut = $cut ?? $max;
        $parts[] = trim(mb_substr($text, 0, $cut));
        $text = trim(mb_substr($text, $cut));
    }
    if ($text !== '') { $parts[] = $text; }
    return $parts;
}

/* ---------- Reply engine (#23) ---------- */

/** Bangla script → Bangla; otherwise the customer wrote in Roman letters (Banglish or English). */
function fb_detect_lang(string $text): string {
    return preg_match('/\p{Bengali}/u', $text) ? 'bn' : 'roman';
}

function fb_system_prompt(string $lang): string {
    // The bot always writes its instructions in English, so it reads the English fields first (not the visitor's cookie).
    $doc = setting('doctor_name_en') !== '' ? setting('doctor_name_en') : setting('doctor_name_bn');
    $place = setting('workplace_en') !== '' ? setting('workplace_en') : setting('workplace');
    $hours = setting('chamber_hours_en') !== '' ? setting('chamber_hours_en') : setting('chamber_hours');
    $phone = setting('phone_display');
    $addr = setting('chamber_address');
    $link = abs_url('/appointment');
    $style = $lang === 'bn'
        ? 'The customer writes in Bangla script: reply in natural, friendly Bangla.'
        : 'The customer writes in Roman letters: reply in natural Banglish (Bangla in English letters) like a Bangladeshi clinic receptionist, unless the customer is clearly writing English, then reply in simple English.';
    return "You are the Messenger assistant for {$doc}, {$place}. You work like a warm, sharp sales-minded receptionist who helps people book appointments. "
        . "{$style} Keep each reply short (2-4 sentences), one question at a time, no markdown, no bullet lists, no emojis overload. "
        . "Chamber: " . ($addr !== '' ? $addr : 'see the website') . ". Hours: {$hours}. Phone: {$phone}. "
        . "Booking: when the customer wants to book, do not ask for any personal detail or health detail in this chat; send this appointment form link and say they can fill it there: {$link} . "
        . "Never say the link is a live booking confirmation; the staff confirms by phone. "
        . fb_safety_rules();
}

/** Recent history for the model, oldest first. */
function fb_history(int $convId, int $limit = 12): array {
    $rows = q_all('SELECT direction, body FROM fb_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$convId]);
    $rows = array_reverse($rows);
    $out = [];
    foreach ($rows as $r) {
        if ($out === [] && $r['direction'] !== 'in') { continue; } // Gemini expects the history to open with the customer
        $out[] = ['role' => $r['direction'] === 'in' ? 'user' : 'model', 'parts' => [['text' => (string) $r['body']]]];
    }
    return $out;
}

/** Records an outbound message and updates the daily counter. */
function fb_log_outbound(int $convId, string $body, string $mid, string $status, string $error = ''): void {
    $now = date('Y-m-d H:i:s');
    try {
        q_exec('INSERT INTO fb_messages (conversation_id, mid, direction, body, status, error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$convId, $mid !== '' ? $mid : null, 'out', $body, $status, $error !== '' ? mb_substr($error, 0, 250) : null, $now]);
    } catch (PDOException $e) {
        // duplicate mid from Meta: nothing to add
    }
    $conv = q_one('SELECT day_date, day_replies FROM fb_conversations WHERE id = ?', [$convId]) ?: [];
    $today = date('Y-m-d');
    $count = ($conv['day_date'] ?? null) === $today ? (int) $conv['day_replies'] + 1 : 1;
    q_exec('UPDATE fb_conversations SET day_date = ?, day_replies = ?, last_outbound_at = ?, updated_at = ? WHERE id = ?',
        [$today, $count, $now, $now, $convId]);
}

/** Sends a list of text parts to one user and logs each one. */
function fb_send_parts(array $conv, array $parts): void {
    $psid = (string) $conv['psid'];
    foreach ($parts as $i => $part) {
        if ($i > 0) { usleep(600000); }
        $res = fb_graph_send($psid, $part);
        fb_log_outbound((int) $conv['id'], $part, $res['mid'], $res['ok'] ? 'ok' : 'failed', $res['error']);
    }
}

/** Handles one stored inbound message: handoff check, safety gate, Gemini reply, send. */
function fb_handle_inbound(array $m): void {
    if (!meta_channel_enabled($m['psid'])) { return; }
    $conv = fb_conversation_for($m['psid']);
    $convId = (int) $conv['id'];
    $cfg = meta_cfg();

    if (fb_handoff_requested($m['text'])) {
        fb_pause($convId, $cfg['handoff_hours']);
        $lang = fb_detect_lang($m['text']);
        $msg = $lang === 'bn'
            ? 'অবশ্যই। আমাদের একজন স্টাফ শীঘ্রই আপনার সাথে যোগাযোগ করবেন। জরুরি হলে সরাসরি ফোন করুন: ' . setting('phone_display')
            : 'Sure, a staff member will reply to you soon. For urgent matters, please call: ' . setting('phone_display');
        fb_send_parts($conv, [$msg]);
        return;
    }
    if (fb_reply_blocked($conv) !== '') {
        return; // paused for a human, or daily limit reached: stay silent
    }

    $lang = fb_detect_lang($m['text']);
    if ($lang !== ($conv['lang'] ?? '')) {
        q_exec('UPDATE fb_conversations SET lang = ? WHERE id = ?', [$lang === 'bn' ? 'bn' : 'roman', $convId]);
    }

    fb_typing($m['psid'], true);
    $resp = gemini_generate([
        'systemInstruction' => ['parts' => [['text' => fb_system_prompt($lang)]]],
        'contents' => fb_history($convId),
        'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 400],
    ]);
    $text = '';
    foreach (($resp['candidates'][0]['content']['parts'] ?? []) as $p) {
        $text .= (string) ($p['text'] ?? '');
    }
    $text = trim(str_replace(['**', '##', '`'], '', $text));
    if ($text === '') {
        $text = $lang === 'bn'
            ? 'দুঃখিত, এখন একটু সমস্যা হচ্ছে। একটু পরে আবার লিখুন অথবা ফোন করুন: ' . setting('phone_display')
            : 'Sorry, I am having a small issue right now. Please try again shortly or call: ' . setting('phone_display');
        system_alert('warning', 'meta', 'Gemini returned no reply for a Messenger message');
    }
    fb_send_parts($conv, fb_split_reply($text));
}

/** Checks the Page token by reading the Page name. Used by the admin "test connection" button. */
function fb_test_connection(): array {
    $c = meta_cfg();
    if ($c['page_token'] === '' || $c['page_id'] === '') {
        return [false, 'Page ID বা Page Access Token সেট করা নেই'];
    }
    if (!function_exists('curl_init')) { return [false, 'cURL চালু নেই']; }
    $url = 'https://graph.facebook.com/' . rawurlencode($c['graph_version']) . '/' . rawurlencode($c['page_id']) . '?fields=id,name';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['page_token']]]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res === false ? '' : (string) $res, true);
    if ($code === 200 && is_array($json) && isset($json['id'])) {
        return [true, 'সংযোগ ঠিক আছে: ' . (string) ($json['name'] ?? 'Page') . ' (ID ' . (string) $json['id'] . ')'];
    }
    $msg = (string) ($json['error']['message'] ?? 'HTTP ' . $code);
    return [false, 'Meta সংযোগ ব্যর্থ: ' . mb_substr($msg, 0, 200)];
}
