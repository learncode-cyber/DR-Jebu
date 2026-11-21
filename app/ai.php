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

function ai_enabled(): bool {
    gem_tables();
    return gemini_key() !== '' || gem_pick_cred(gem_active_project()) !== null;
}

/** English system prompt, used when the visitor chose EN. */
function ai_system_prompt_en(): string {
    $doc = setting('doctor_title_en') . ' ' . setting('doctor_name_en');
    $place = s_tr('workplace');
    $hours = s_tr('chamber_hours');
    $addr = setting('chamber_address');
    $phone = setting('phone_display');
    return "You are the AI assistant on the website of {$doc}, who works at {$place}. Always reply in simple, polite English. If the patient writes in Bangla, reply in Bangla.\n\n"
        . "Chamber: " . ($addr !== '' ? $addr : 'see the website for the address') . "\n"
        . "Hours: {$hours}\nPhone: {$phone}\n\n"
        . "Rules:\n"
        . "1. You do not diagnose, and you do not give medicine names, doses or treatment decisions. You may give general information and say when the patient should see a doctor.\n"
        . "2. If there are emergency signs (chest pain, heavy bleeding, breathing difficulty, severe abdominal pain, bleeding in pregnancy and similar), tell the patient at once to call 999 or go to the nearest hospital.\n"
        . "3. To book an appointment, ask for the name, mobile number, preferred date (if any) and the problem or service. Call create_appointment only after the patient has confirmed all the details.\n"
        . "4. If the function succeeds, give the patient the request number and say that the chamber will confirm the time by phone.\n"
        . "5. Ask for personal details (name, mobile) only when they are needed for an appointment.";
}

function ai_system_prompt(): string {
    if (site_lang() === 'en') { return ai_system_prompt_en(); }
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

/** English tool description for the model, used when the visitor chose EN. */
function ai_tool_declarations_en(): array {
    return [[
        'functionDeclarations' => [[
            'name' => 'create_appointment',
            'description' => 'Creates an appointment request with the patient consent and sends it to the chamber. Call only when the name and mobile number are confirmed.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING', 'description' => "The patient's name"],
                    'phone' => ['type' => 'STRING', 'description' => 'Mobile number'],
                    'preferred_date' => ['type' => 'STRING', 'description' => 'Preferred date (YYYY-MM-DD), empty if not known'],
                    'service_interest' => ['type' => 'STRING', 'description' => 'Short description of the problem or service'],
                ],
                'required' => ['name', 'phone'],
            ],
        ]],
    ]];
}

function ai_tool_declarations(): array {
    if (site_lang() === 'en') { return ai_tool_declarations_en(); }
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

/**
 * Every direct Gemini call goes through here (chat, Facebook post generator, clinical helpers).
 * It uses the Gemini credential manager (project ledger, quota blocks, daily cap) and the privacy guard.
 * Patient text is never sent: a blocked message returns null and sets $GLOBALS['gem_last_block'].
 */
function gemini_generate(array $body): ?array {
    gem_tables();
    unset($GLOBALS['gem_last_block']);
    $sent = '';
    foreach (($body['contents'] ?? []) as $c) {
        foreach (($c['parts'] ?? []) as $p) { $sent .= ' ' . (string) ($p['text'] ?? ''); }
    }
    [$safe, $why] = gem_guard($sent);
    if (!$safe) {
        $GLOBALS['gem_last_block'] = $why;
        gem_log('warn', 'privacy_block', '', '', 0, $why . ' (direct call)');
        return null;
    }
    $project = gem_active_project();
    $cred = $project !== '' ? gem_pick_cred($project) : null;
    if ($cred !== null) {
        $key = gem_plain_key($cred);
    } else {
        $key = gemini_key();
        $project = $project !== '' ? $project : 'legacy-setting';
    }
    if ($key === '') { return null; }
    $model = gemini_model();
    if (gem_requests_today($project) >= (int) setting('gemini_daily_cap', '200')) {
        gem_log('warn', 'cap', $project, $model, 0, 'daily safety cap (direct call)');
        return null;
    }
    if (gem_block_until($project, $model) !== null) { return null; }
    [$code, $headers, $raw, $err] = gem_http('POST', GEM_BASE . '/models/' . rawurlencode($model) . ':generateContent', $body, $key);
    $c = gem_classify($code, $headers, $raw, $err);
    gem_log($c['kind'] === 'ok' ? 'info' : 'warn', 'call', $project, $model, $code, $c['kind'] === 'ok' ? 'direct' : $c['kind'] . ': ' . $c['message']);
    if ($c['kind'] === 'ok') {
        $j = json_decode($raw, true);
        gem_usage_add($project, $model, true, (int) ($j['usageMetadata']['promptTokenCount'] ?? 0), (int) ($j['usageMetadata']['candidatesTokenCount'] ?? 0), '');
        return is_array($j) ? $j : null;
    }
    if ($c['kind'] === 'rate_daily') {
        gem_usage_add($project, $model, false, 0, 0, 'daily');
        gem_block($project, $model, 'daily', gem_next_pt_midnight(), 'daily quota');
    } elseif ($c['kind'] === 'rate_minute' || $c['kind'] === 'rate_unknown') {
        gem_usage_add($project, $model, false, 0, 0, 'minute');
        gem_block($project, $model, 'minute', gem_plus(gem_backoff(1, $c['retry_after'])), 'per-minute limit');
    } else {
        gem_usage_add($project, $model, false, 0, 0, '');
    }
    error_log('[jebu] gemini ' . $c['kind'] . ' HTTP ' . $code);
    return null;
}

function ai_transcript_text(array $history, int $leadId): string {
    $out = "এআই সহকারীর কথোপকথন, অনুরোধ #{$leadId}\nসময়: " . date('Y-m-d H:i') . "\n" . str_repeat('-', 40) . "\n";
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
        [$ok, $err] = telegram_send_document('এআই সহকারীর কথোপকথন, অনুরোধ #' . $id, 'conversation-' . $id . '.txt', $text);
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
        return ['ok' => false, 'reply' => '', 'error' => tr('এআই সহকারী এখন চালু নেই। অনুগ্রহ করে অ্যাপয়েন্টমেন্ট বা হোয়াটসঅ্যাপ ব্যবহার করুন।', 'The AI assistant is not available right now. Please use the appointment form or WhatsApp.')];
    }
    $history = [];
    foreach (array_slice($messages, -20) as $m) {
        if (!is_array($m)) { continue; }
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = mb_substr(trim((string) ($m['text'] ?? '')), 0, 1000);
        if ($text !== '') { $history[] = ['role' => $role, 'text' => $text]; }
    }
    if (!$history || end($history)['role'] !== 'user') {
        return ['ok' => false, 'reply' => '', 'error' => tr('একটি প্রশ্ন লিখুন।', 'Please type a question.')];
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
        if (!is_array($parts) && !empty($GLOBALS['gem_last_block'])) {
            return ['ok' => false, 'reply' => '', 'error' => tr('আপনার বার্তায় ব্যক্তিগত তথ্য (যেমন ফোন নম্বর, ইমেইল বা রোগীর নাম) থাকতে পারে। নিরাপত্তার জন্য সেটি এআইতে পাঠানো হয়নি। অ্যাপয়েন্টমেন্টের জন্য এই ফর্মটি ব্যবহার করুন: ' . abs_url('/appointment') . ' ', 'For your privacy, your message was not sent to the AI. To book, please use this form: ' . abs_url('/appointment') . ' ')];
        }
        if (!is_array($parts)) {
            return ['ok' => false, 'reply' => '', 'error' => tr('এআই সহকারী এখন উত্তর দিতে পারছে না। একটু পরে আবার চেষ্টা করুন, অথবা অ্যাপয়েন্টমেন্ট/হোয়াটসঅ্যাপ ব্যবহার করুন।', 'The AI assistant cannot answer right now. Please try again shortly, or use the appointment form or WhatsApp.')];
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
    return ['ok' => false, 'reply' => '', 'error' => tr('উত্তর তৈরি করা যায়নি। অনুগ্রহ করে আবার চেষ্টা করুন।', 'Could not create an answer. Please try again.')];
}
