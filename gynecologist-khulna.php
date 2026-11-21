<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$isEn = site_lang() === 'en';
$docName = tr(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn'), setting('doctor_title_en') . ' ' . setting('doctor_name_en'));
$addr = trim(setting('chamber_address'));
$hours = tr(setting('chamber_hours'), setting('chamber_hours_en'));
$faqs = [
    [tr('গাইনি ডাক্তার কখন দেখাব?', 'When should I see a gynecologist?'),
     tr('অনিয়মিত বা অস্বাভাবিক রক্তপাত, কয়েক মাস ধরে মাসিক না হওয়া, সাদা স্রাব যা বদলে যায়, বা গর্ভাবস্থায় রক্তপাত ও তীব্র ব্যথা হলে দেরি না করে পরামর্শ নিন।', 'See a doctor for irregular or abnormal bleeding, missed periods for several months, changing white discharge, or bleeding or severe pain in pregnancy without delay.')],
    [tr('প্রথমবার গেলে কী নিতে হবে?', 'What should I bring on my first visit?'),
     tr('আগের রিপোর্ট, প্রেসক্রিপশন ও আল্ট্রাসনোগ্রাম রিপোর্ট (থাকলে) সঙ্গে নিন। মাসিকের তারিখ লিখে নিলে পরামর্শ সহজ হয়।', 'Bring previous reports, prescriptions and any ultrasound reports you have. Writing down the date of your last period helps the consultation.')],
    [tr('অ্যাপয়েন্টমেন্ট কীভাবে নেব?', 'How do I book an appointment?'),
     tr('ওয়েবসাইটের অ্যাপয়েন্টমেন্ট ফর্ম, ফোন বা WhatsApp-এ যোগাযোগ করুন। চেম্বারে আসার আগে কল করে নিশ্চিত করুন।', 'Use the appointment form on this website, or call or WhatsApp. Please call to confirm before visiting the chamber.')],
];
page_start([
    'title' => tr('খুলনায় গাইনি ডাক্তার', 'Gynecologist in Khulna'),
    'description' => tr('খুলনায় গাইনি ও প্রসূতি সেবা: গর্ভাবস্থার যত্ন, বন্ধ্যাত্ব পরামর্শ, মাসিকের সমস্যা। ' . $docName . ' থেকে অ্যাপয়েন্টমেন্ট নিন।', 'Gynecology and obstetric care in Khulna: pregnancy care, infertility advice and menstrual problems. Book with ' . setting('doctor_name_en') . '.'),
    'path' => '/gynecologist-khulna', 'breadcrumb' => [[ui('খুলনায় গাইনি ডাক্তার'), null]],
    'schema' => [
        ['@context' => 'https://schema.org', '@type' => 'Physician', 'name' => doctor_schema_name(), 'url' => abs_url('/gynecologist-khulna'),
         'medicalSpecialty' => ['Obstetric', 'Gynecologic'], 'worksFor' => ['@type' => 'Hospital', 'name' => s_tr('workplace')],
         'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Khulna', 'addressCountry' => 'BD']],
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faqs)],
    ],
]);
page_hero('Khulna', tr('খুলনায় গাইনি ডাক্তার: নারীর স্বাস্থ্য ও গর্ভাবস্থার যত্ন', 'Gynecologist in Khulna: women\'s health and pregnancy care'), tr(setting('specialty_line_bn'), setting('specialty_line_en')), [[ui('খুলনায় গাইনি ডাক্তার'), null]]);
?>
<section class="section"><div class="wrap">
  <div class="reveal">
    <p><?= h(tr($docName . ' খুলনায় গাইনি ও প্রসূতি বিষয়ে পরামর্শ দেন। ' . setting('degrees_line') . '।', $docName . ' provides gynecology and obstetric consultations in Khulna. ' . setting('degrees_line') . '.')) ?></p>
    <p><?= h(tr('বিএমডিসি রেজিস্ট্রেশন নম্বর: 72244', 'BMDC registration number: 72244')) ?></p>
    <p><?= h(tr('কর্মস্থল: ', 'Workplace: ')) ?><?= h(s_tr('workplace')) ?></p>
  </div>
</div></section>

<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Services</p><h2 class="display"><?= h(tr('যেসব সেবা', 'Services')) ?></h2></div>
  <ul class="reveal">
    <li><a href="<?= h(entity_url('services', 'antenatal-care-service')) ?>"><?= h(tr('গর্ভাবস্থার নিয়মিত পরিচর্যা', 'Antenatal care')) ?></a></li>
    <li><a href="<?= h(entity_url('services', 'pregnancy-delivery-care')) ?>"><?= h(tr('গর্ভাবস্থা ও প্রসব সেবা', 'Pregnancy and delivery care')) ?></a></li>
    <li><a href="<?= h(entity_url('services', 'infertility-treatment-program')) ?>"><?= h(tr('বন্ধ্যাত্ব ব্যবস্থাপনা', 'Infertility management')) ?></a></li>
    <li><a href="<?= h(entity_url('services', 'menstrual-health-care')) ?>"><?= h(tr('মাসিকের স্বাস্থ্য সেবা', 'Menstrual health care')) ?></a></li>
    <li><a href="<?= h(entity_url('services', 'gynae-infection-screening-care')) ?>"><?= h(tr('গাইনি সংক্রমণ ও স্ক্রিনিং', 'Gynecological infection and screening')) ?></a></li>
    <li><a href="<?= h(entity_url('services', 'laparoscopic-surgery-service')) ?>"><?= h(tr('ল্যাপারোস্কোপিক সার্জারি', 'Laparoscopic surgery')) ?></a></li>
  </ul>
</div></section>

<section class="section"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Chamber</p><h2 class="display"><?= h(tr('চেম্বার ও অ্যাপয়েন্টমেন্ট', 'Chamber and appointments')) ?></h2></div>
  <p class="reveal"><?= h(tr('চেম্বার: ', 'Chamber: ')) ?><?= h($addr !== '' ? $addr : tr('ওয়েবসাইটের যোগাযোগ পাতায় দেখুন', 'see the contact page')) ?></p>
  <p class="reveal"><?= h(tr('সময়: ', 'Hours: ')) ?><?= h($hours) ?></p>
  <p class="reveal"><a class="btn btn-primary" href="/appointment"><?= h(ui('অ্যাপয়েন্টমেন্ট নিন')) ?></a> <a class="btn btn-outline" href="/contact"><?= h(ui('যোগাযোগ')) ?></a></p>
</div></section>

<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">FAQ</p><h2 class="display"><?= h(tr('প্রশ্নোত্তর', 'Questions and answers')) ?></h2></div>
<?php foreach ($faqs as $f): ?>
  <div class="card reveal"><h3><?= h($f[0]) ?></h3><p><?= h($f[1]) ?></p></div>
<?php endforeach; ?>
</div></section>
<?php cta_band(); page_end();
