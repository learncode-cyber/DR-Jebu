<?php
declare(strict_types=1);

/**
 * Language support for the public site.
 * The page URL never changes with the language. The visitor picks বাংলা / EN with the switch in the
 * header (?lang=bn|en), which stores the choice in a cookie ('jlang') and returns to the same page.
 * Any string without an English entry falls back to Bangla, so a missing translation never breaks a page.
 */

/** Request path without the /en prefix, always starting with '/'. Home is '/'. */
function lang_base_path(): string {
    $p = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $p = (string) preg_replace('#^/en(?=/|$)#', '', $p);
    return '/' . trim($p, '/');
}

function site_lang(): string {
    static $lang = null;
    if ($lang === null) {
        $lang = (($_COOKIE['jlang'] ?? '') === 'en') ? 'en' : 'bn';
    }
    return $lang;
}

/** Internal URL of a path. The URL is the same in both languages (the language is in the cookie). */
function lang_path(string $path, string $lang = ''): string {
    [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
    $p = '/' . trim((string) preg_replace('#^/en(?=/|$)#', '', $p), '/');
    return $p . ($q !== '' ? '?' . $q : '');
}

/** Bilingual text for a single value: English when the visitor chose EN and an English text exists. */
function tr(string $bn, string $en = ''): string {
    return (site_lang() === 'en' && $en !== '') ? $en : $bn;
}

/** Translate a site-chrome string (menu, buttons, footer labels). The Bangla text is the key. */
function ui(string $bn): string {
    if (site_lang() !== 'en') {
        return $bn;
    }
    return ui_dictionary()[$bn] ?? $bn;
}

/** Owner-entered text with an English twin in settings (key_en). Falls back to the Bangla value. */
function s_tr(string $key): string {
    $en = trim(setting($key . '_en'));
    return (site_lang() === 'en' && $en !== '') ? $en : setting($key);
}

/** Owner-entered list (one item per line) with an English twin in settings (key_en). */
function s_lines(string $key): array {
    $en = trim(setting($key . '_en'));
    $src = (site_lang() === 'en' && $en !== '') ? $en : setting($key);
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $src) ?: []), static fn($l) => $l !== ''));
}

/** Owner-entered paragraphs (blank line between) with an English twin in settings (key_en). */
function s_paras(string $key): array {
    $en = trim(setting($key . '_en'));
    $src = (site_lang() === 'en' && $en !== '') ? $en : setting($key);
    return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', $src) ?: []), static fn($l) => $l !== ''));
}

/** Switch link: the same page in the other language (sets the cookie, see bootstrap.php). */
function lang_url(string $lang): string {
    $lang = $lang === 'en' ? 'en' : 'bn';
    $q = $_GET;
    unset($q['__l'], $q['lang']);
    $q['lang'] = $lang;
    return lang_base_path() . '?' . http_build_query($q);
}

function ui_dictionary(): array {
    static $d = [
        'হোম' => 'Home',
        'লেখক' => 'By',
        'মিনিট পড়া' => 'min read',
        'মেডিকেলি রিভিউড' => 'Medically reviewed by',
        'অ্যাপয়েন্টমেন্ট নিতে চান?' => 'Want to book an appointment?',
        'সিরিয়ালের জন্য আমাদের জানান।' => 'contact us to book a slot.',
        'সিরিয়াল নিন' => 'Book a slot',
        'সম্পর্কিত লেখা' => 'Related articles',
        'অ্যাপয়েন্টমেন্ট' => 'Appointments',
        'প্রথম ভিজিট' => 'First visit',
        'জরুরি অবস্থা' => 'Emergencies',
        'ওয়েবসাইট' => 'Website',
        'সাধারণ' => 'General',
        'সচরাচর জিজ্ঞাসিত প্রশ্ন' => 'Frequently Asked Questions',
        'সেবা ও রোগের বিস্তারিত' => 'Services and conditions',
        'পরিচিতি' => 'About',
        'বিশেষত্ব' => 'Specialties',
        'সেবা' => 'Services',
        'রোগ ও চিকিৎসা' => 'Conditions & Treatment',
        'রোগসমূহ' => 'Conditions',
        'চিকিৎসা' => 'Treatments',
        'প্রসিডিওর' => 'Procedures',
        'ব্লগ' => 'Blog',
        'আরও' => 'More',
        'রোগীর অভিজ্ঞতা' => 'Patient stories',
        'সচরাচর প্রশ্ন' => 'FAQ',
        'গ্যালারি' => 'Gallery',
        'ভিডিও' => 'Videos',
        'যোগাযোগ' => 'Contact',
        'মেনু' => 'Menu',
        'মেনু খুলুন' => 'Open menu',
        'প্রধান মেনু' => 'Main menu',
        'মূল কনটেন্টে যান' => 'Skip to content',
        'অ্যাপয়েন্টমেন্ট নিন' => 'Book an appointment',
        'দ্রুত লিংক' => 'Quick links',
        'সব সেবা দেখুন →' => 'View all services →',
        'অ্যাপয়েন্টমেন্ট ফর্ম' => 'Appointment form',
        'যোগাযোগ পেজ' => 'Contact page',
        'গোপনীয়তা নীতি' => 'Privacy policy',
        'শর্তাবলি' => 'Terms',
        'ফোন' => 'Phone',
        'হোয়াটসঅ্যাপ' => 'WhatsApp',
        'কল' => 'Call',
        'আপনার জন্য সঠিক পরামর্শ নিন' => 'Get the right advice for you',
        'নাম ও মোবাইল নম্বর দিন, আমাদের টিম যোগাযোগ করবে।' => 'Leave your name and mobile number, and our team will contact you.',
        'স্ত্রীরোগের সাধারণ সমস্যা, চিকিৎসা পদ্ধতি ও অস্ত্রোপচার, এক জায়গায়।' => 'Common gynaecology problems, treatment methods and surgery, in one place.',
        'রোগীর মতামত, সচরাচর প্রশ্ন, ভিডিও, ছবি ও যোগাযোগের তথ্য।' => 'Patient stories, FAQs, videos, photos and contact details.',
        'বিস্তারিত তথ্য ও সেবা।' => 'Detailed information and services.',
        'স্ত্রী রোগের সাধারণ সমস্যা ও লক্ষণ' => 'Common gynaecology problems and symptoms',
        'ওষুধ ও পরিকল্পিত চিকিৎসা পদ্ধতি' => 'Medicines and planned treatment methods',
        'ল্যাপারোস্কোপি ও অস্ত্রোপচার' => 'Laparoscopy and surgery',
        'রোগীদের মতামত ও অভিজ্ঞতা' => 'Patient views and experiences',
        'সাধারণ প্রশ্নের উত্তর' => 'Answers to common questions',
        'চেম্বার ও কার্যক্রমের ছবি' => 'Chamber and activity photos',
        'স্বাস্থ্য বিষয়ক ভিডিও' => 'Health videos',
        'ফোন, ঠিকানা ও চেম্বার' => 'Phone, address and chamber',
        'বিস্তারিত দেখুন →' => 'View details →',
        'বিস্তারিত জানতে ও পরামর্শ নিতে দেখুন।' => 'See the details and book a consultation.',
        'পড়ুন →' => 'Read →',
        'সব লেখা দেখুন' => 'View all articles',
        'সব বিশেষত্ব দেখুন' => 'View all specialties',
        'এআই সহকারী' => 'AI assistant',
        'অ্যাপয়েন্টমেন্ট ও তথ্যের জন্য' => 'For appointments and information',
        'এখানে লিখুন…' => 'Type here…',
        'পাঠান' => 'Send',
        'বন্ধ করুন' => 'Close',
        'উত্তর শুনুন' => 'Listen to the answer',
        'কথা বলে লিখুন' => 'Speak to type',
        'বার্তা লিখুন' => 'Write a message',
        'অ্যাপয়েন্টমেন্ট নিতে চাই' => 'I want to book an appointment',
        'চেম্বারের সময় কখন?' => 'When is the chamber open?',
        'কোন সমস্যায় পরামর্শ?' => 'Which problems can I consult about?',
        'চেম্বারের সময়' => 'Chamber hours',
        'কোন সমস্যায় পরামর্শ নেওয়া যায়?' => 'Which problems can I get advice for?',
        'জরুরি অবস্থায় ৯৯৯ বা নিকটস্থ হাসপাতালে যান।' => 'In an emergency, call 999 or go to the nearest hospital.',
        'এই ওয়েবসাইটের তথ্য শুধু জানার জন্য। নিজের শরীর নিয়ে সিদ্ধান্ত নেওয়ার আগে সরাসরি কথা বলে নিন।' => 'The information on this website is for general knowledge only. Speak to a doctor directly before making decisions about your health.',
        'দ্রুত যোগাযোগ' => 'Quick contact',
        'বাটনগুলো টেনে সরান' => 'Drag the buttons',
        'এআই সহকারীর সাথে কথা বলুন' => 'Talk to the AI assistant',
        'হোয়াটসঅ্যাপে মেসেজ' => 'Message on WhatsApp',
        'ক্যাটাগরি' => 'Categories',
        'সব লেখা' => 'All articles',
        'এই বিভাগে এখনও কোনো লেখা প্রকাশিত হয়নি।' => 'No articles have been published in this category yet.',
        'আইভিএফ চিকিৎসা' => 'IVF treatment',
        'গর্ভকালীন ডায়াবেটিস' => 'Gestational diabetes',
        'গর্ভধারণের আগে প্রস্তুতি' => 'Preparing before pregnancy',
        'গর্ভাবস্থা ও প্রসব' => 'Pregnancy and delivery',
        'গর্ভাবস্থার প্রথম ৩ মাস' => 'First 3 months of pregnancy',
        'গাইনী সমস্যা' => 'Gynaecology problems',
        'জরায়ুমুখ ক্যান্সার প্রতিরোধ' => 'Cervical cancer prevention',
        'বন্ধ্যাত্ব' => 'Infertility',
        'মেনোপজ' => 'Menopause',
        'স্বাস্থ্য সচেতনতা ব্লগ' => 'Health Awareness Blog',
        'স্বাস্থ্য সচেতনতার ব্লগ' => 'Health Awareness Blog',
        'সহজ বাংলায় গর্ভাবস্থা, বন্ধ্যাত্ব ও নারী স্বাস্থ্য বিষয়ক নির্ভরযোগ্য লেখা।' => 'Reliable, easy-to-read articles on pregnancy, infertility and women\'s health.',
        'যেসব গাইনী ও প্রসূতি সংক্রান্ত সমস্যায় চিকিৎসা ও পরামর্শ দেওয়া হয়।' => 'Gynaecology and obstetric problems for which we provide treatment and advice.',
        'অ্যাপয়েন্টমেন্ট, প্রথম ভিজিট ও সেবা নিয়ে আপনার সম্ভাব্য প্রশ্নগুলোর উত্তর।' => 'Answers to likely questions about appointments, your first visit and our services.',
        'ল্যাপারোস্কোপি, ডেলিভারি ও অন্যান্য প্রসিডিওর সংক্রান্ত তথ্য।' => 'Information on laparoscopy, delivery and other procedures.',
        'গর্ভাবস্থা, প্রসব, বন্ধ্যাত্ব, সার্জারি ও গাইনী পরিচর্যার ওয়ান-স্টপ সেবা।' => 'One-stop services for pregnancy, delivery, infertility, surgery and gynaecare.',
        'যেসব ক্ষেত্রে নিবেদিত পরামর্শ ও চিকিৎসা প্রদান করা হয়।' => 'Areas where we provide focused consultation and treatment.',
        'সেবাসমূহ' => 'Services',
        'রোগীদের অভিজ্ঞতা' => 'Patient Experiences',
        'যাঁরা আমাদের সেবা নিয়েছেন, তাঁদের নিজের কথায়।' => 'In the words of those who have received our care.',
        'রোগী ও পরিবারের অভিজ্ঞতা ও মতামত।' => 'Experiences and views of patients and their families.',
        'অ্যাপয়েন্টমেন্ট, প্রথম ভিজিট, চেম্বারের সময় ও সেবা সংক্রান্ত সচরাচর প্রশ্নের উত্তর। খুলনায় ডাঃ জেবুন্নেসা জেবুর চেম্বারের তথ্য জানুন।' => 'Answers to common questions on appointments, first visits, chamber timings and services. Find chamber details for Dr. Jebunnesa Jebu in Khulna.',
        'গাইনী ও প্রসূতি রোগসমূহ: পিসিওএস, অনিয়মিত মাসিক, ফাইব্রয়েড, এন্ডোমেট্রিওসিস ও ওভারিয়ান সিস্টের লক্ষণ, কারণ ও চিকিৎসা। খুলনায় ডাঃ জেবুন্নেসা জেবুর পরামর্শ।' => 'Gynaecology and obstetric conditions: PCOS, irregular periods, fibroids, endometriosis and ovarian cysts, with symptoms, causes and treatment. Advice from Dr. Jebunnesa Jebu in Khulna.',
        'ল্যাপারোস্কোপি, ডেলিভারি ও গাইনী প্রসিডিওর: প্রতিটি পদ্ধতির উদ্দেশ্য, প্রস্তুতি ও পুনরুদ্ধার সম্পর্কে সহজ বাংলায় তথ্য। খুলনায় পরামর্শের জন্য সিরিয়াল নিন।' => 'Laparoscopy, delivery and gynaecology procedures explained in simple language: purpose, preparation and recovery. Book an appointment in Khulna.',
        'প্রসূতি, স্ত্রীরোগ, বন্ধ্যাত্ব, ল্যাপারোস্কোপিক সার্জারি ও নারী স্বাস্থ্যের বিশেষায়িত ক্ষেত্রসমূহ। খুলনায় ডাঃ জেবুন্নেসা জেবুর চেম্বারে পরামর্শ।' => 'Obstetrics, gynaecology, infertility, laparoscopic surgery and women\'s health: focused advice and treatment from Dr. Jebunnesa Jebu at her Khulna chamber.',
        'গর্ভাবস্থা, বন্ধ্যাত্ব ও গাইনী স্বাস্থ্য বিষয়ে ডাঃ জেবুন্নেসা জেবুর শিক্ষামূলক ভিডিও। সহজ বাংলায় সঠিক তথ্য জানুন।' => 'Educational videos on pregnancy, infertility and gynaecology health by Dr. Jebunnesa Jebu. Learn accurate information in simple language.',
        'রোগী ও পরিবারের অভিজ্ঞতা ও মতামত। খুলনায় ডাঃ জেবুন্নেসা জেবুর চিকিৎসা নিয়ে রোগীদের বাস্তব অভিজ্ঞতা পড়ুন।' => 'Experiences and views of patients and their families. Read real patient experiences of care with Dr. Jebunnesa Jebu in Khulna.',
        'প্রমাণভিত্তিক চিকিৎসা পদ্ধতি ও পরামর্শ। ওষুধ, অস্ত্রোপচার ও নন-সার্জিক্যাল চিকিৎসার তথ্য খুলনায় ডাঃ জেবুন্নেসা জেবুর চেম্বার থেকে।' => 'Evidence-based treatment methods and advice. Medicines, surgery and non-surgical options, explained at Dr. Jebunnesa Jebu\'s chamber in Khulna.',
        'প্রমাণভিত্তিক চিকিৎসা পদ্ধতি ও পরামর্শ।' => 'Evidence-based treatment methods and advice.',
        'স্বাস্থ্য সচেতনতার ভিডিও' => 'Health Awareness Videos',
        'গর্ভাবস্থা, বন্ধ্যাত্ব ও গাইনী স্বাস্থ্য বিষয়ে শিক্ষামূলক ভিডিও।' => 'Educational videos on pregnancy, infertility and gynaecological health.',
        'সহজ ভাষায় শিক্ষামূলক ভিডিও ও স্বাস্থ্য পরামর্শ।' => 'Educational videos and health advice in simple language.',
        'এই বিভাগে এখনও কিছু যুক্ত করা হয়নি। অনুগ্রহ করে একটু পরে দেখুন অথবা সরাসরি যোগাযোগ করুন।' => 'Nothing has been added in this section yet. Please check back later or contact us directly.',
        'মূল বিশেষত্ব' => 'Main specialty',
        'এই বিশেষত্বের সেবাসমূহ' => 'Services in this specialty',
        'লক্ষণ' => 'Symptoms',
        'কারণ' => 'Causes',
        'রোগ নির্ণয়' => 'Diagnosis',
        'আরও দেখুন' => 'See more',
        'প্রশ্নোত্তর শীঘ্রই যুক্ত করা হবে।' => 'Questions and answers will be added soon.',
        'প্রশ্ন পাঠান' => 'Send a question',
        'রোগীদের মতামত শীঘ্রই এখানে প্রকাশ করা হবে।' => 'Patient views will be published here soon.',
        'ভিডিও শীঘ্রই যুক্ত করা হবে।' => 'Videos will be added soon.',
        'ব্লগ পড়ুন' => 'Read the blog',
        'চালান' => 'Play',
        'আপনার প্রশ্নের উত্তর পাননি?' => 'Did not find your answer?',
        'সরাসরি জানিয়ে দিন। যত দ্রুত সম্ভব উত্তর দেওয়া হবে।' => 'Let us know directly. We will answer as soon as possible.',
        'নাম ও মোবাইল নম্বর দিন, আমাদের টিম যোগাযোগ করে সিরিয়াল নিশ্চিত করবে।' => 'Leave your name and mobile number. Our team will contact you to confirm your slot.',
        'টেনে সরান (দুইবার ক্লিক করলে আগের জায়গায়)' => 'Drag to move (double-click to reset)',
        'এআই সহায়ক রোগ নির্ণয় বা ওষুধের পরামর্শ দেয় না। জরুরি অবস্থায় ৯৯৯ বা নিকটস্থ হাসপাতালে যান।' => 'The AI assistant does not diagnose or prescribe medicines. In an emergency, call 999 or go to the nearest hospital.',
    ];
    return $d;
}

/**
 * Swap each Bangla field for its English twin (field_en) when the visitor chose EN and the English text is filled in.
 * Works on rows read with SELECT * or with the *_en columns listed in the query.
 */
function localize_row(array $row): array {
    if (site_lang() !== 'en') {
        return $row;
    }
    foreach ($row as $k => $v) {
        if (!is_string($k) || str_ends_with($k, '_en') || !array_key_exists($k . '_en', $row)) {
            continue;
        }
        if (trim((string) $row[$k . '_en']) !== '') {
            $row[$k] = (string) $row[$k . '_en'];
        }
    }
    return $row;
}

function localize_rows(array $rows): array {
    return array_map('localize_row', $rows);
}

/** Doctor's name for structured data: the page language first, the other language as alternateName. */
function doctor_schema_name(): string {
    return tr(setting('doctor_name_bn'), setting('doctor_name_en'));
}

function doctor_schema_alt_name(): string {
    return site_lang() === 'en' ? setting('doctor_name_bn') : setting('doctor_name_en');
}

/** All alternate spellings of the doctor's name (other language + the alternate Bangla spelling people search for). */
function doctor_schema_alt_names(): array {
    $list = array_values(array_unique(array_filter([doctor_schema_alt_name(), trim(setting('doctor_name_alt_bn'))])));
    return $list;
}
