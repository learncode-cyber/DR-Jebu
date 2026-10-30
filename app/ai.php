<?php
declare(strict_types=1);

/* ============ Telegram (token + chat id come from admin settings; config.php is a fallback) ============ */

function telegram_cfg(): array {
    $t = trim(setting('telegram_bot_token'));
    if ($t === '' && defined('TELEGRAM_BOT_TOKEN')) { $t = (string) TELEGRAM_BOT_TOKEN; }
    $c = trim(setting('telegram_chat_id'));
    if ($c === '' && defined('TELEGRAM_CHAT_ID')) { $c = (string) TELEGRAM_CHAT_ID; }
    return ['token' => $t, 'chat' => $c];
}

function telegram_enabled(): bool {
    $c = telegram_cfg();
    return $c['token'] !== '' && $c['chat'] !== '';
}

/** Sends a .txt file (e.g. an AI conversation transcript) to the Telegram chat/channel. */
function telegram_send_document(string $caption, string $filename, string $content): array {
    if (!telegram_enabled() || !function_exists('curl_init')) {
        return [false, 'Telegram সেট করা নেই'];
    }
    $cfg = telegram_cfg();
    $tmp = tempnam(sys_get_temp_dir(), 'tg');
    if ($tmp === false) { return [false, 'temp ফাইল তৈরি ব্যর্থ']; }
    file_put_contents($tmp, $content);
    $ch = curl_init('https://api.telegram.org/bot' . $cfg['token'] . '/sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $cfg['chat'],
            'caption' => mb_substr($caption, 0, 1000),
            'document' => new CURLFile($tmp, 'text/plain', $filename),
        ],
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);
    $ok = $res !== false && $code === 200;
    return [$ok, $ok ? '' : ($err !== '' ? $err : 'Telegram HTTP ' . $code)];
}

/* ============ AI assistant (Google Gemini, free tier key from Google AI Studio) ============ */

function gemini_key(): string { return trim(setting('gemini_api_key')); }

function gemini_model(): string {
    $m = trim(setting('gemini_model'));
    return $m !== '' ? $m : 'gemini-2.5-flash';
}

function ai_enabled(): bool { return gemini_key() !== ''; }

function ai_system_prompt(): string {
    $doc = setting('doctor_name_bn');
    $place = setting('workplace');
    $hours = setting('chamber_hours');
    $addr = setting('chamber_address');
    $phone = setting('phone_display');
    return "তুমি {$doc}-এর ওয়েবসাইটের এআই সহকারী। {$place}-এ কর্মরত। সবসময় সহজ, ভদ্র বাংলায় উত্তর দাও (রোগী ইংরেজিতে লিখলে ইংরেজিতে)।\n\n"
        . "চেম্বার: " . ($addr !== '' ? $addr : 'ঠিকানা ওয়েবসাইটে দেখুন') . "\n"
        . "সময়: {$hours}\nফোন: {$phone}\n\n"
        . "নিয়ম:\n"
        . "1. তুমি রোগ নির্ণয় করবে না, ওষুধের নাম, ডোজ বা চিকিৎসার সিদ্ধান্ত দেবে না। সাধারণ তথ্য ও কখন ডাক্তার দেখাতে হবে তা বলতে পারো।\n"
        . "2. জরুরি লক্ষণ (বুকে ব্যথা, অতিরিক্ত রক্তপাত, শ্বাসকষ্ট, তীব্র পেটব্যথা, গর্ভাবস্থায় রক্তপাত ইত্যাদি) থাকলে সাথে সাথে ৯৯৯ বা নিকটস্থ হাসপাতালে যেতে বলো।\n"
        . "3. অ্যাপয়েন্টমেন্ট বুক করতে: নাম, মোবাইল নম্বর, পছন্দের তারিখ (থাকলে) এবং সমস্যা/সেবা জেনে নাও। রোগী সব তথ্য নিশ্চিত করলে তবেই create_appointment ফাংশন কল করবে।\n"
        . "4. ফাংশনের ফল সফল হলে রোগীকে অনুরোধ নম্বর জানিয়ে বলো যে চেম্বার থেকে ফোনে সময় নিশ্চিত করা হবে।\n"
        . "5. ব্যক্তিগত তথ্য (মোবাইল, নাম) শুধু অ্যাপয়েন্টমেন্টের প্রয়োজনেই চাইবে।";
}

function ai_tool_declarations(): array {
    return [[
        'functionDeclarations' => [[
            'name' => 'create_appointment',
            'description' => 'রোগীর সম্মতিতে অ্যাপয়েন্টমেন্ট অনুরোধ তৈরি করে চেম্বারে পাঠায়। শুধু তখনই কল করবে যখন নাম ও মোবাইল নম্বর নিশ্চিত।',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING', 'description' => 'রোগীর নাম'],
                    'phone' => ['type' => 'STRING', 'description' => 'মোবাইল নম্বর'],
                    'preferred_date' => ['type' => 'STRING', 'description' => 'পছন্দের তারিখ (YYYY-MM-DD), না জানলে ফাঁকা'],
                    'service_interest' => ['type' => 'STRING', 'description' => 'সমস্যা বা সেবার সংক্ষিপ্ত বিবরণ'],
                ],
                'required' => ['name', 'phone'],
            ],
        ]],
    ]];
}

/** Calls Gemini generateContent. Returns decoded JSON or null on failure. */
function gemini_generate(array $body): ?array {
    if (!function_exists('curl_init')) { return null; }
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(gemini_model()) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . gemini_key()],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) {
        error_log('[jebu] gemini HTTP ' . $code);
        return null;
    }
    $json = json_decode((string) $res, true);
    return is_array($json) ? $json : null;
}

function ai_transcript_text(array $history, int $leadId): string {
    $out = "এআই সহকারীর কথোপকথন — অনুরোধ #{$leadId}\nসময়: " . date('Y-m-d H:i') . "\n" . str_repeat('-', 40) . "\n";
    foreach ($history as $m) {
        $who = ($m['role'] ?? '') === 'assistant' ? 'সহকারী' : 'রোগী';
        $out .= $who . ': ' . trim((string) ($m['text'] ?? '')) . "\n\n";
    }
    return $out;
}

/** Books an appointment requested by the AI, saves the transcript and sends it to Telegram. */
function ai_book(array $args, array $history): array {
    $lead = lead_create([
        'name' => (string) ($args['name'] ?? ''),
        'phone' => (string) ($args['phone'] ?? ''),
        'preferred_date' => (string) ($args['preferred_date'] ?? ''),
        'service_interest' => (string) ($args['service_interest'] ?? ''),
        'message' => '(এআই সহকারীর মাধ্যমে বুক করা)',
        'source_page' => '/ai-chat',
        'utm_source' => 'ai_assistant',
    ], 'appointment');
    if (empty($lead['ok'])) {
        return ['ok' => false, 'error' => (string) ($lead['error'] ?? 'তথ্য সঠিক নয়')];
    }
    $id = (int) $lead['id'];
    $text = ai_transcript_text($history, $id);
    q_exec('INSERT INTO ai_conversations (lead_id, transcript, ip) VALUES (?,?,?)', [$id, mb_substr($text, 0, 60000), client_ip()]);
    if (telegram_enabled()) {
        [$ok, $err] = telegram_send_document('এআই সহকারীর কথোপকথন — অনুরোধ #' . $id, 'conversation-' . $id . '.txt', $text);
        lead_event($id, $ok ? 'telegram_transcript_sent' : 'telegram_transcript_failed', $ok ? 'কথোপকথন টেলিগ্রামে গেছে' : mb_substr($err, 0, 200));
    }
    return ['ok' => true, 'lead_id' => $id, 'duplicate' => !empty($lead['duplicate'])];
}

/**
 * One chat turn. $messages = [{role: user|assistant, text}] (full history from the browser).
 * Returns ['ok' => bool, 'reply' => string, 'lead_id' => ?int, 'error' => string].
 */
function ai_chat_turn(array $messages): array {
    if (!ai_enabled()) {
        return ['ok' => false, 'reply' => '', 'error' => 'এআই সহকারী এখন চালু নেই। অনুগ্রহ করে অ্যাপয়েন্টমেন্ট বা হোয়াটসঅ্যাপ ব্যবহার করুন।'];
    }
    $history = [];
    foreach (array_slice($messages, -20) as $m) {
        if (!is_array($m)) { continue; }
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = mb_substr(trim((string) ($m['text'] ?? '')), 0, 1000);
        if ($text !== '') { $history[] = ['role' => $role, 'text' => $text]; }
    }
    if (!$history || end($history)['role'] !== 'user') {
        return ['ok' => false, 'reply' => '', 'error' => 'একটি প্রশ্ন লিখুন।'];
    }

    $contents = [];
    foreach ($history as $m) {
        $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['text']]]];
    }
    $leadId = null;
    for ($step = 0; $step < 4; $step++) {
        $resp = gemini_generate([
            'systemInstruction' => ['parts' => [['text' => ai_system_prompt()]]],
            'contents' => $contents,
            'tools' => ai_tool_declarations(),
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 700],
        ]);
        $parts = $resp['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            return ['ok' => false, 'reply' => '', 'error' => 'এআই সহকারী এখন উত্তর দিতে পারছে না। একটু পরে আবার চেষ্টা করুন, অথবা অ্যাপয়েন্টমেন্ট/হোয়াটসঅ্যাপ ব্যবহার করুন।'];
        }
        $call = null;
        $text = '';
        foreach ($parts as $p) {
            if (isset($p['functionCall'])) { $call = $p['functionCall']; }
            if (isset($p['text'])) { $text .= $p['text']; }
        }
        if ($call !== null && ($call['name'] ?? '') === 'create_appointment') {
            $args = is_array($call['args'] ?? null) ? $call['args'] : [];
            $booked = ai_book($args, $history);
            if (!empty($booked['ok'])) { $leadId = (int) $booked['lead_id']; }
            $contents[] = ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'create_appointment', 'args' => $args]]]];
            $contents[] = ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'create_appointment', 'response' => $booked]]]];
            continue;
        }
        if (trim($text) !== '') {
            return ['ok' => true, 'reply' => trim($text), 'lead_id' => $leadId, 'error' => ''];
        }
    }
    return ['ok' => false, 'reply' => '', 'error' => 'উত্তর তৈরি করা যায়নি। অনুগ্রহ করে আবার চেষ্টা করুন।'];
}
