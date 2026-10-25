<?php
declare(strict_types=1);

/** Default site copy. Anything saved in the site_settings table (admin > সেটিংস) overrides these. */
function site_defaults(): array {
    return [
        'doctor_name_bn' => 'ডাঃ জেবুন্নেসা জেবু',
        'doctor_name_en' => 'Dr. Jebunnesa Jebu',
        'doctor_title_bn' => 'সহকারী অধ্যাপক',
        'doctor_title_en' => 'Assistant Professor',
        'specialty_line_bn' => 'স্ত্রী, প্রসূতি ও বন্ধ্যাত্ব রোগ বিশেষজ্ঞ ও সার্জন',
        'specialty_line_en' => 'Obs & Gynae Specialist · Infertility & Surgeon',
        'workplace' => 'খুলনা মেডিকেল কলেজ হাসপাতাল, খুলনা',
        'degrees_line' => 'MBBS (DMC) · BCS (Health) · DGO · MCPS · FCPS (Obs & Gynae) · MRCOG (London)',
        'degrees' => "MBBS (DMC)\nBCS (Health)\nDGO\nMCPS\nFCPS (Obstetrics & Gynecology)\nMRCOG (UK)",

        'phone_display' => '01410-158133',
        'whatsapp_intl' => '8801410158133',
        'whatsapp_display' => '+880 1410-158133',
        'wa_message' => "আসসালামু আলাইকুম,\nঅ্যাপয়েন্টমেন্ট চাই।\n(ডাঃ জেবুন্নেসা জেবুর ওয়েবসাইট থেকে)",
        'email' => '',
        'chamber_address' => '',
        'chamber_hours' => 'অগ্রিম যোগাযোগের মাধ্যমে নির্ধারিত — অনুগ্রহ করে কল করে নিশ্চিত করুন।',
        'map_embed_src' => '',
        'facebook_url' => '',
        'youtube_url' => '',
        'instagram_url' => '',
        'gsc_verification' => '',
        'bing_verification' => '',

        'about_quote' => 'প্রতিটি রোগীর গল্প আলাদা — চিকিৎসা শুরু হয় মন দিয়ে শোনা থেকে।',
        'about_text' => "সহকারী অধ্যাপক ডাঃ জেবুন্নেসা জেবু একজন বন্ধ্যাত্ব, স্ত্রী ও প্রসূতি রোগ বিশেষজ্ঞ, IVF ও ল্যাপারোস্কোপিক সার্জন — বর্তমানে খুলনা মেডিকেল কলেজ হাসপাতালে সহকারী অধ্যাপক হিসেবে কর্মরত। ঢাকা মেডিকেল কলেজ থেকে এমবিবিএস সম্পন্ন করার পর তিনি বাংলাদেশ সিভিল সার্ভিস (স্বাস্থ্য) ক্যাডারে যোগ দিয়ে দেশের সরকারি চিকিৎসা সেবার গুরুত্বপূর্ণ অংশ হয়ে ওঠেন।\n\nস্ত্রী ও প্রসূতি বিদ্যায় ডিজিও, এমসিপিএস ও এফসিপিএস ডিগ্রি অর্জনের পর তিনি লন্ডনের রয়্যাল কলেজ অব অবস্টেট্রিশিয়ানস অ্যান্ড গাইনোকোলজিস্টস (MRCOG) পরীক্ষা সম্পন্ন করেন — যা আন্তর্জাতিক মানের প্রজনন স্বাস্থ্য সেবার স্বীকৃতি।\n\nতাঁর অনুশীলনের কেন্দ্রবিন্দু গর্ভাবস্থা ও প্রসব সেবা, বন্ধ্যাত্ব ব্যবস্থাপনা এবং গাইনোকোলজিকাল সার্জারি। দীর্ঘ অভিজ্ঞতার সঙ্গে যুক্ত হয়েছে রোগীর প্রতি গভীর সহমর্মিতা — এই দুইয়ের সমন্বয়ে গড়ে উঠেছে তাঁর চিকিৎসা দর্শন।",
        'philosophy_quote' => 'চিকিৎসা শুধু রোগ নিরাময় নয় — এটি বিশ্বাস ও মর্যাদার একটি সম্পর্ক, যেখানে নারী তাঁর সবচেয়ে ব্যক্তিগত প্রশ্নটিও নির্ভয়ে রাখতে পারেন।',
        'credentials' => "MBBS|ঢাকা মেডিকেল কলেজ থেকে চিকিৎসাবিজ্ঞানের স্নাতক ডিগ্রি।\nBCS (Health)|বাংলাদেশ সিভিল সার্ভিস (স্বাস্থ্য) ক্যাডারের নিয়মিত সদস্য।\nDGO|ডিপ্লোমা ইন গাইনোকোলজি অ্যান্ড অবস্টেট্রিক্স — প্রসূতি বিদ্যার বিশেষায়িত প্রশিক্ষণ।\nMCPS|মেম্বার অব দ্য কলেজ অব ফিজিশিয়ানস অ্যান্ড সার্জনস বাংলাদেশ।\nFCPS|ফেলো অব দ্য কলেজ অব ফিজিশিয়ানস অ্যান্ড সার্জনস — অবস্টেট্রিক্স ও গাইনোকোলজি।\nMRCOG (UK)|মেম্বার অব দ্য রয়্যাল কলেজ অব অবস্টেট্রিশিয়ানস অ্যান্ড গাইনোকোলজিস্টস, লন্ডন।",
        'training_text' => "ঢাকা মেডিকেল কলেজে এমবিবিএস\nবিসিএস (স্বাস্থ্য) ক্যাডারে সরকারি চিকিৎসা সেবা\nডিজিও, এমসিপিএস ও এফসিপিএস (অবস্টেট্রিক্স ও গাইনোকোলজি)\nএমআরসিওজি (যুক্তরাজ্য) — রয়্যাল কলেজ অব অবস্টেট্রিশিয়ানস অ্যান্ড গাইনোকোলজিস্টস\nখুলনা মেডিকেল কলেজ হাসপাতালে সহকারী অধ্যাপক হিসেবে কর্মরত",
        'footer_disclaimer' => 'এই ওয়েবসাইটের তথ্য কেবল সাধারণ সচেতনতার জন্য। চিকিৎসা সংক্রান্ত পরামর্শের জন্য সরাসরি যোগাযোগ করুন।',

        'ga_measurement_id' => '',
        'meta_pixel_id' => '',
        'notify_email' => '',
    ];
}

/** Single-source list of editable settings for the admin form: key => [label, type, group]. */
function settings_schema(): array {
    return [
        'doctor_name_bn' => ['চিকিৎসকের নাম (বাংলা)', 'text', 'পরিচিতি'],
        'doctor_name_en' => ['চিকিৎসকের নাম (English)', 'text', 'পরিচিতি'],
        'doctor_title_bn' => ['পদবি (বাংলা)', 'text', 'পরিচিতি'],
        'doctor_title_en' => ['পদবি (English)', 'text', 'পরিচিতি'],
        'specialty_line_bn' => ['বিশেষত্ব লাইন (বাংলা)', 'text', 'পরিচিতি'],
        'specialty_line_en' => ['বিশেষত্ব লাইন (English)', 'text', 'পরিচিতি'],
        'workplace' => ['কর্মস্থল', 'text', 'পরিচিতি'],
        'degrees_line' => ['ডিগ্রি (এক লাইনে)', 'text', 'পরিচিতি'],
        'degrees' => ['ডিগ্রি তালিকা (প্রতি লাইনে একটি)', 'textarea', 'পরিচিতি'],
        'about_quote' => ['পরিচিতি উক্তি', 'text', 'পরিচিতি'],
        'about_text' => ['পরিচিতি লেখা (অনুচ্ছেদের মাঝে ফাঁকা লাইন)', 'textarea', 'পরিচিতি'],
        'credentials' => ['যোগ্যতা (কোড|বিবরণ — প্রতি লাইনে একটি)', 'textarea', 'পরিচিতি'],
        'training_text' => ['প্রশিক্ষণ ও কর্মজীবন (প্রতি লাইনে একটি)', 'textarea', 'পরিচিতি'],
        'philosophy_quote' => ['চিকিৎসা দর্শন', 'textarea', 'পরিচিতি'],
        'phone_display' => ['ফোন নম্বর (দেখানোর জন্য)', 'text', 'যোগাযোগ'],
        'whatsapp_intl' => ['হোয়াটসঅ্যাপ নম্বর (দেশকোডসহ, যেমন 8801XXXXXXXXX)', 'text', 'যোগাযোগ'],
        'whatsapp_display' => ['হোয়াটসঅ্যাপ নম্বর (দেখানোর জন্য)', 'text', 'যোগাযোগ'],
        'wa_message' => ['হোয়াটসঅ্যাপ প্রি-ফিল্ড বার্তা', 'textarea', 'যোগাযোগ'],
        'email' => ['ইমেইল (পাবলিক)', 'text', 'যোগাযোগ'],
        'chamber_address' => ['চেম্বারের ঠিকানা', 'textarea', 'যোগাযোগ'],
        'chamber_hours' => ['চেম্বারের সময়', 'text', 'যোগাযোগ'],
        'map_embed_src' => ['Google Map embed URL (src)', 'text', 'যোগাযোগ'],
        'facebook_url' => ['Facebook URL', 'text', 'সোশ্যাল'],
        'youtube_url' => ['YouTube URL', 'text', 'সোশ্যাল'],
        'instagram_url' => ['Instagram URL', 'text', 'সোশ্যাল'],
        'gsc_verification' => ['Google Search Console verification code (শুধু কোডটি)', 'text', 'SEO'],
        'bing_verification' => ['Bing Webmaster verification code (শুধু কোডটি)', 'text', 'SEO'],
        'footer_disclaimer' => ['ফুটার ডিসক্লেইমার', 'textarea', 'সাইট'],
        'notify_email' => ['নতুন লিডের নোটিফিকেশন ইমেইল (config.php-এর NOTIFY_EMAIL_TO-এর বদলে)', 'text', 'অটোমেশন'],
        'ga_measurement_id' => ['Google Analytics 4 ID (G-XXXX)', 'text', 'ট্র্যাকিং'],
        'meta_pixel_id' => ['Meta Pixel ID', 'text', 'ট্র্যাকিং'],
    ];
}
