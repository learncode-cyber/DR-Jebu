<?php
declare(strict_types=1);

/*
 * Clinician-only AI helper for the admin drawer (chat, triage support, note summary).
 * Decision support only: never sent to patients, never saved to a record, never logs the input text.
 */

require_once __DIR__ . '/ai.php';

const AI_CLINICAL_MAX_CHARS = 4000;

function ai_clinical_system_prompt(): string {
    $doc = setting('doctor_name_bn', 'ডাক্তার');
    return "তুমি {$doc}-এর ক্লিনিক্যাল সিদ্ধান্ত-সহায়ক (স্ত্রীরোগ, প্রসূতি ও বন্ধ্যাত্ব বিষয়ক)। নিয়ম:\n"
        . "1) সবসময় বাংলায় উত্তর দাও।\n"
        . "2) নিশ্চিত রোগ নির্ণয় বা চূড়ান্ত ওষুধের ডোজ দেবে না। সম্ভাব্য কারণ, ঝুঁকির লক্ষণ, প্রস্তাবিত পরীক্ষা ও সাধারণ নির্দেশিকা উল্লেখ করবে।\n"
        . "3) জরুরি লক্ষণ (অতিরিক্ত রক্তস্রাব, তীব্র ব্যথা, গর্ভাবস্থায় খিঁচুনি, শ্বাসকষ্ট, বুকে ব্যথা, জ্ঞান হারানো) থাকলে প্রথমেই জরুরি চিকিৎসার কথা বলবে।\n"
        . "4) রোগীর নাম, ফোন বা অন্য পরিচয় চাইবে না, অনুমানও করবে না।\n"
        . "5) তথ্য না থাকলে অনুমান না করে কী তথ্য দরকার তা জিজ্ঞেস করবে।\n"
        . "6) উত্তরের শেষে লিখবে: এটি শুধু সহায়ক তথ্য; চূড়ান্ত সিদ্ধান্ত চিকিৎসক নেবেন।";
}

/** Joins the text parts of the first Gemini candidate. */
function ai_clinical_text(?array $resp): string {
    $parts = $resp['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        return '';
    }
    $out = '';
    foreach ($parts as $p) {
        if (isset($p['text'])) {
            $out .= (string) $p['text'];
        }
    }
    return trim($out);
}

function ai_clinical_clean(string $s): string {
    $s = str_replace("\0", '', $s);
    return mb_substr(trim($s), 0, AI_CLINICAL_MAX_CHARS);
}

/**
 * Runs one clinician request. $body: ['mode' => chat|triage|summary, 'text' => string] or ['messages' => [...]] for chat.
 * Returns ['ok' => bool, 'text' => string, 'error' => string, 'enabled' => bool].
 */
function ai_clinical_run(string $mode, array $body): array {
    if (!ai_enabled()) {
        return ['ok' => false, 'text' => '', 'enabled' => false,
            'error' => 'এআই এখনও চালু নেই। অ্যাডমিন সেটিংসে Gemini API Key দিন।'];
    }
    $system = ['parts' => [['text' => ai_clinical_system_prompt()]]];

    if ($mode === 'chat') {
        $contents = [];
        foreach (array_slice((array) ($body['messages'] ?? []), -12) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $text = ai_clinical_clean((string) ($m['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $role = ($m['role'] ?? '') === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
        if (!$contents || end($contents)['role'] !== 'user') {
            return ['ok' => false, 'text' => '', 'enabled' => true, 'error' => 'একটি প্রশ্ন লিখুন।'];
        }
    } elseif (in_array($mode, ['triage', 'summary'], true)) {
        $input = ai_clinical_clean((string) ($body['text'] ?? ''));
        if ($input === '') {
            return ['ok' => false, 'text' => '', 'enabled' => true, 'error' => 'প্রথমে লক্ষণ বা নোট লিখুন।'];
        }
        $instruction = $mode === 'triage'
            ? 'নিচের লক্ষণ থেকে ঝুঁকির মাত্রা (জরুরি / মাঝারি / সাধারণ), সম্ভাব্য কারণ, প্রস্তাবিত পরীক্ষা ও পরবর্তী ধাপ বলো।'
            : 'নিচের ক্লিনিক্যাল নোট থেকে একটি সংক্ষিপ্ত ভিজিট সামারি বানাও: ভিজিটের কারণ, ভাইটাল, সিদ্ধান্ত ও ফলোআপ। নোটে যা নেই তা যোগ করবে না।';
        $contents = [['role' => 'user', 'parts' => [['text' => $instruction . "\n\n---\n" . $input]]]];
    } else {
        return ['ok' => false, 'text' => '', 'enabled' => true, 'error' => 'অজানা অনুরোধ।'];
    }

    $resp = gemini_generate([
        'systemInstruction' => $system,
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 900],
    ]);
    $text = ai_clinical_text($resp);
    if ($text === '') {
        return ['ok' => false, 'text' => '', 'enabled' => true, 'error' => 'এআই এখন উত্তর দিতে পারছে না। একটু পরে আবার চেষ্টা করুন।'];
    }
    return ['ok' => true, 'text' => $text, 'enabled' => true, 'error' => ''];
}
