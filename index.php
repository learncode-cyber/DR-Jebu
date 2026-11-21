<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$icons = [
    '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 54 38 26"/><path d="M38 26l12-14c2-2 5 1 3 3L41 31z" fill="currentColor" fill-opacity=".28"/><path d="M44 46l8 8M50 40l5 5M20 14v8M16 18h8"/></svg>',
    '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M32 55S8 41 8 24a12 12 0 0 1 24-5 12 12 0 0 1 24 5c0 17-24 31-24 31z" fill="currentColor" fill-opacity=".18"/><circle cx="32" cy="28" r="6"/><path d="M25 41c2.5-4.5 11.5-4.5 14 0"/></svg>',
    '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" aria-hidden="true"><circle cx="24" cy="32" r="15" fill="currentColor" fill-opacity=".15"/><circle cx="42" cy="32" r="15" fill="currentColor" fill-opacity=".15"/><circle cx="24" cy="32" r="4.5" fill="currentColor"/><path d="M8 10l6 6M56 10l-6 6M32 6v8"/></svg>',
];
// Hero slides: the Bangla and English texts are both kept here; the visible slide follows the chosen language
$themes = [
    ['kicker' => 'Advanced Laparoscopic Surgery', 'title' => 'ল্যাপারোস্কোপিক সার্জারি', 'title_en' => 'Laparoscopic Surgery', 'sub' => 'ফাইব্রয়েড, সিস্ট ও প্রজনন অঙ্গের অস্ত্রোপচার। প্রতিটি রোগীর জন্য আলাদা পরিকল্পনা করা হয়।', 'sub_en' => 'Surgery for fibroids, cysts and reproductive organs. Each patient gets an individual plan.', 'link' => '/procedures/laparoscopic-surgery', 'linkLabel' => 'ল্যাপারোস্কোপিক সার্জারি সম্পর্কে জানুন', 'linkLabel_en' => 'Learn about laparoscopic surgery', 'bg1' => '#801B4B', 'bg2' => '#3d0a24', 'glow' => '#f8bbd0', 'card' => 'ল্যাপারোস্কোপিক সার্জারি', 'card_en' => 'Laparoscopic surgery'],
    ['kicker' => 'Complete Antenatal Care', 'title' => 'সম্পূর্ণ গর্ভকালীন ও প্রসব সেবা', 'title_en' => 'Complete Antenatal and Delivery Care', 'sub' => 'গর্ভধারণের প্রথম দিন থেকে প্রসব-পরবর্তী পরিচর্যা পর্যন্ত সম্পূর্ণ মাতৃযত্ন।', 'sub_en' => 'Full maternity care, from the first day of pregnancy through postnatal care.', 'link' => '/services/antenatal-care-service', 'linkLabel' => 'গর্ভকালীন সেবা সম্পর্কে জানুন', 'linkLabel_en' => 'Learn about antenatal care', 'bg1' => '#4b2a7b', 'bg2' => '#1e0f3a', 'glow' => '#d9c2f5', 'card' => 'অ্যান্টিনেটাল কেয়ার', 'card_en' => 'Antenatal care'],
    ['kicker' => 'Infertility & IVF', 'title' => 'বন্ধ্যাত্ব ও IVF চিকিৎসা', 'title_en' => 'Infertility and IVF Treatment', 'sub' => 'দম্পতির সম্মিলিত মূল্যায়ন এবং প্রমাণভিত্তিক চিকিৎসা পরিকল্পনা।', 'sub_en' => 'A joint assessment of the couple and an evidence-based treatment plan.', 'link' => '/services/infertility-treatment-program', 'linkLabel' => 'বন্ধ্যাত্ব চিকিৎসা সম্পর্কে জানুন', 'linkLabel_en' => 'Learn about infertility treatment', 'bg1' => '#b0356f', 'bg2' => '#5a1238', 'glow' => '#fce4ec', 'card' => 'বন্ধ্যাত্ব ও IVF', 'card_en' => 'Infertility & IVF'],
];
$isEn = site_lang() === 'en';
// Slide texts for the chosen language (the JavaScript reads these keys from data-themes)
$themes = array_map(static fn($t) => [
    'kicker' => $t['kicker'], 'title' => $isEn ? $t['title_en'] : $t['title'], 'sub' => $isEn ? $t['sub_en'] : $t['sub'],
    'link' => $t['link'], 'linkLabel' => $isEn ? $t['linkLabel_en'] : $t['linkLabel'], 'bg1' => $t['bg1'], 'bg2' => $t['bg2'],
    'glow' => $t['glow'], 'card' => $isEn ? $t['card_en'] : $t['card'],
], $themes);
$t0 = $themes[0];
$degrees = s_lines('degrees');
// Numbers: Western digits in English, Bangla digits in Bangla
$num = static fn($n) => $isEn ? (string) $n : bn_num((int) $n);
$docName = tr(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn'), setting('doctor_title_en') . ' ' . setting('doctor_name_en'));

// Alternate spelling (জেবুন্নেছা) that patients search for; shown in title, description, H1 and About
$altBn = trim((string) preg_replace('/^(ডাঃ|ডা\.?|Dr\.?)\s*/u', '', trim(setting('doctor_name_alt_bn'))));
$altPart = $altBn !== '' ? ' (' . $altBn . ')' : '';

$specialties = $services = $conditions = $posts = $tests = $faqs = [];
$counts = ['sp' => 0, 'sv' => 0, 'cd' => 0, 'bl' => 0];
try {
    $specialties = entity_rows('specialties', 6);
    $services = entity_rows('services', 6);
    $conditions = entity_rows('conditions');
    $posts = localize_rows(q_all("SELECT slug, title, title_en, excerpt, excerpt_en, cover_image, category, published_at FROM blog_posts WHERE status='published' AND published_at <= ? ORDER BY published_at DESC LIMIT 3", [date('Y-m-d H:i:s')]));
    $tests = entity_rows('testimonials', 3);
    $faqs = entity_rows('faqs', 4);
    $counts = [
        'sp' => (int) q_val('SELECT COUNT(*) FROM specialties WHERE is_active = 1'), 'sv' => (int) q_val('SELECT COUNT(*) FROM services WHERE is_active = 1'),
        'cd' => (int) q_val('SELECT COUNT(*) FROM conditions WHERE is_active = 1'), 'bl' => (int) q_val("SELECT COUNT(*) FROM blog_posts WHERE status='published'"),
    ];
} catch (Throwable $e) {
    error_log('[jebu] home: ' . $e->getMessage());
}

page_start([
    'title' => tr('খুলনায় গাইনী ও বন্ধ্যাত্ব বিশেষজ্ঞ', 'Infertility Specialist in Khulna'), 'full_title' => tr(setting('doctor_name_bn') . $altPart . ' | খুলনার গাইনী বিশেষজ্ঞ',setting('doctor_name_en') . ' | Gynecologist in Khulna'), 'home' => true, 'gsap' => true, 'hero' => true, 'path' => '/',
    'description' => trim_text(tr(
        setting('doctor_title_bn') . ' ' . setting('doctor_name_bn') . $altPart . ' | ' . setting('specialty_line_bn') . '। কর্মস্থল: ' . s_tr('workplace') . '। বন্ধ্যাত্ব, IVF, গর্ভকালীন সেবা ও ল্যাপারোস্কোপি। অ্যাপয়েন্টমেন্ট নিন।',
        setting('doctor_title_en') . ' ' . setting('doctor_name_en') . ' | ' . setting('specialty_line_en') . '. Workplace: ' . s_tr('workplace') . '. Infertility, IVF, antenatal care and laparoscopic surgery in Khulna.'
    ), 155),
    'schema' => [
        (static function () use ($isEn) {
            // Only facts the owner has entered; address/map/social appear only when set
            $physician = ['@context' => 'https://schema.org', '@type' => 'Physician', 'name' => doctor_schema_name(), 'alternateName' => doctor_schema_alt_names(), 'jobTitle' => setting('doctor_title_en'), 'url' => abs_url(lang_path('/')), 'image' => abs_url('/assets/img/doctor-portrait.jpg'), 'telephone' => '+' . preg_replace('/\D/', '', setting('whatsapp_intl')), 'medicalSpecialty' => ['Obstetric', 'Gynecologic'], 'worksFor' => ['@type' => 'Hospital', 'name' => s_tr('workplace')], 'availableService' => array_map(static fn($n) => ['@type' => 'Service', 'name' => $n], ['Infertility treatment', 'Laparoscopic surgery', 'Antenatal care'])];
            // Credentials and topics come only from the owner's settings (no invented degrees)
            $creds = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) setting('degrees')) ?: []), static fn($l) => $l !== ''));
            if ($creds) { $physician['hasCredential'] = array_map(static fn($c) => ['@type' => 'EducationalOccupationalCredential', 'credentialCategory' => 'degree', 'name' => $c], $creds); }
            $physician['knowsAbout'] = $isEn ? ['Infertility', 'IVF', 'Obstetrics', 'Gynaecology', 'Laparoscopic surgery'] : ['বন্ধ্যাত্ব', 'IVF', 'প্রসূতি', 'স্ত্রীরোগ', 'ল্যাপারোস্কোপি'];
            $sameAs = array_values(array_filter([setting('facebook_url'), setting('youtube_url'), setting('instagram_url')], static fn($u) => preg_match('#^https://#i', $u) === 1));
            if ($sameAs) { $physician['sameAs'] = $sameAs; }
            if (trim(setting('chamber_address')) !== '') {
                $physician['address'] = ['@type' => 'PostalAddress', 'streetAddress' => trim(setting('chamber_address')), 'addressLocality' => 'Khulna', 'addressCountry' => 'BD'];
            }
            $places = [];
            foreach (chamber_rows() as $c) {
                $places[] = array_filter(['@type' => 'MedicalClinic', 'name' => (string) $c['name'], 'address' => (string) $c['location'],
                    'openingHours' => (string) $c['visiting_hours'], 'telephone' => (string) $c['phone']], static fn($v) => $v !== '');
            }
            if ($places) { $physician['workLocation'] = $places; }
            return $physician;
        })(),
        ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => doctor_schema_name(), 'url' => abs_url(lang_path('/')), 'inLanguage' => $isEn ? 'en' : 'bn'],
    ],
]);
?>
<section class="hero" id="hero" data-themes="<?= h(json_encode($themes, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="hero-field" id="heroField" aria-hidden="true"></div>
<?php foreach ($icons as $i => $svg): ?>
  <template id="icon-<?= $i ?>"><?= $svg ?></template>
<?php endforeach; ?>
  <div class="wrap hero-inner">
    <div class="hero-copy">
      <h1 class="sr-only"><?= h(tr('ডাঃ জেবুন্নেসা জেবু' . $altPart . ', খুলনায় স্ত্রী রোগ, বন্ধ্যাত্ব ও IVF বিশেষজ্ঞ', 'Dr. Jebunnesa Jebu, Obstetrics, Gynaecology, Infertility and IVF Specialist in Khulna')) ?></h1>
      <p class="kicker reveal-h" id="heroKicker"><?= h($t0['kicker']) ?></p>
      <h2 class="hero-title display reveal-h" id="heroTitle"><?= h($t0['title']) ?></h2>
      <p class="hero-sub reveal-h" id="heroSub"><?= h($t0['sub']) ?></p>
      <p class="reveal-h" style="margin:0 0 .4rem;color:#fff"><strong><?= h($docName) ?></strong><?php if (!$isEn): ?> · <?= h(setting('doctor_name_en')) ?><?php endif; ?></p>
      <ul class="hero-creds reveal-h"><?php foreach ($degrees as $d): ?><li><?= h($d) ?></li><?php endforeach; ?></ul>
      <div class="hero-actions reveal-h">
        <a class="btn btn-light" href="/appointment"><?= h(tr('অ্যাপয়েন্টমেন্ট নিন', 'Book an appointment')) ?></a>
        <a class="btn btn-ghost" id="heroMore" href="<?= h($t0['link']) ?>"><?= h($t0['linkLabel']) ?></a>
      </div>
    </div>
    <div class="hero-stage">
      <div class="orb-wrap" id="orbWrap" aria-hidden="true">
        <span class="orb-ring"></span><div class="orb"></div>
        <div class="orb-photo" aria-hidden="true"><?= img_picture('/assets/img/doctor-portrait.jpg', '', 960, 807, '(max-width:980px) 280px, 380px', false, ' fetchpriority="high"') ?></div>
      </div>
      <div class="switcher" role="group" aria-label="<?= h(tr('সেবা বেছে নিন', 'Choose a service')) ?>">
<?php foreach ($themes as $i => $t): ?>
        <button type="button" class="svc-card" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><small><?= h($t['kicker']) ?></small><strong><?= h($t['card']) ?></strong></button>
<?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="hero-scroll" aria-hidden="true">SCROLL</div>
  <div class="hero-wave" aria-hidden="true"></div>
</section>

<?php
// Statistics: owner-entered values first (empty = hidden), then live counts from the content
$statTiles = [];
for ($n = 1; $n <= 4; $n++) {
    [$statNum, $sfx] = stat_parse(setting("stat{$n}_value"));
    if ($statNum > 0) { $statTiles[] = [$statNum, $sfx, setting("stat{$n}_label"), 'spark']; }
}
// Each tile gets its own icon (the same icon is the faint watermark behind it)
$statPaths = [
    'cap' => 'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5',
    'heart' => 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z',
    'spark' => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z',
    'clip' => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z',
];
$svgOf = static fn(string $k): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $statPaths[$k] . '"/></svg>';
foreach ([[count($degrees), '+', tr('ডিগ্রি ও স্বীকৃতি', 'Degrees and recognitions'), 'cap'], [$counts['sp'], '+', tr('বিশেষত্বের ক্ষেত্র', 'Specialty areas'), 'heart'], [$counts['sv'], '+', tr('সেবা', 'Services'), 'spark'], [$counts['cd'], '+', tr('রোগ নিয়ে পরামর্শ', 'Conditions we consult on'), 'clip']] as $t) {
    if ($t[0] > 0) { $statTiles[] = $t; }
}
$statTiles = array_slice($statTiles, 0, 8);
?>
<?php if ($statTiles): ?>
<section class="section"><div class="wrap">
  <div class="stats reveal">
<?php foreach ($statTiles as [$statNum, $sfx, $label, $ico]): ?>
    <div class="stat"><i class="stat-wm" aria-hidden="true"><?= $svgOf($ico) ?></i><i class="stat-ico" aria-hidden="true"><?= $svgOf($ico) ?></i><b><span class="cnt" data-count="<?= (int) $statNum ?>"><?= h($num((int) $statNum)) ?></span><span class="sfx"><?= h($sfx) ?></span></b><span><?= h($label) ?></span><i class="stat-bar" aria-hidden="true"><em></em></i></div>
<?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<section class="section"><div class="wrap split">
  <div class="photo reveal"><?= img_picture('/assets/img/doctor-portrait.jpg', $docName, 960, 807, '(max-width:980px) 100vw, 45vw') ?></div>
  <div class="reveal">
    <p class="kicker">About</p>
    <h2 class="display"><?= h($docName) ?></h2>
<?php if ($altBn !== '' && !$isEn): ?>    <p class="muted" style="margin-top:-.4rem">নামের অন্য বানান: <?= h($altBn) ?></p>
<?php endif; ?>
    <p class="quote"><?= h(s_tr('about_quote')) ?></p>
<?php foreach (array_slice(s_paras('about_text'), 0, 2) as $para): ?><p><?= h($para) ?></p><?php endforeach; ?>
    <p><a class="btn btn-primary" href="/about"><?= h(tr('পূর্ণ পরিচিতি →', 'Full profile →')) ?></a></p>
  </div>
</div></section>

<section class="section alt"><div class="wrap split">
  <div class="reveal">
    <p class="kicker">Education</p>
    <h2 class="display"><?= h(tr('শিক্ষা, প্রশিক্ষণ ও স্বীকৃতি', 'Education, training and recognition')) ?></h2>
    <p><?= h(tr('ডাঃ জেবুন্নেসা জেবুর শিক্ষাগত যোগ্যতা ও পেশাগত স্বীকৃতি:', 'Academic qualifications and professional recognition of Dr. Jebunnesa Jebu:')) ?></p>
    <ul class="creds-list">
<?php foreach ($degrees as $d): ?>
      <li><?= h($d) ?></li>
<?php endforeach; ?>
    </ul>
  </div>
  <div class="photo photo-short reveal"><?= img_picture('/assets/img/doctor-graduation.jpg', $docName . ', ' . tr('শিক্ষাগত সনদ গ্রহণের ছবি', 'receiving an academic certificate'), 1065, 1072, '(max-width:980px) 100vw, 45vw') ?></div>
</div></section>

<?php if ($specialties): ?>
<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Specialties</p><h2 class="display"><?= h(tr('যে ক্ষেত্রগুলিতে নিবেদিত পরামর্শ ও চিকিৎসা', 'Areas of focused consultation and treatment')) ?></h2></div>
  <div class="grid c3"><?php foreach ($specialties as $i => $r) { entity_card('specialties', $r, $i); } ?></div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/specialties"><?= h(tr('সব বিশেষত্ব দেখুন', 'View all specialties')) ?></a></p>
</div></section>
<?php endif; ?>

<?php if ($services): ?>
<section class="section"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">One-stop Gynae Care</p><h2 class="display"><?= h(tr('ওয়ান-স্টপ গাইনী কেয়ার সেবা', 'One-stop gynaecare services')) ?></h2><p><?= h(tr('গর্ভাবস্থা থেকে সার্জারি, প্রয়োজনীয় সেবা এক জায়গায়।', 'From pregnancy to surgery, the care you need in one place.')) ?></p></div>
  <div class="grid c3"><?php foreach ($services as $i => $r) { entity_card('services', $r, $i); } ?></div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/services"><?= h(tr('সব সেবা দেখুন', 'View all services')) ?></a></p>
</div></section>
<?php endif; ?>

<?php if ($conditions): ?>
<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Conditions</p><h2 class="display"><?= h(tr('যেসব সমস্যায় পরামর্শ ও চিকিৎসা', 'Conditions we consult and treat')) ?></h2></div>
  <ul class="pill-list reveal"><?php foreach ($conditions as $c): ?><li><a href="/conditions/<?= h($c['slug']) ?>"><?= h($c['title']) ?></a></li><?php endforeach; ?></ul>
</div></section>
<?php endif; ?>

<section class="section"><div class="wrap">
  <div class="cta-inner glass reveal" style="display:block;text-align:center;background:linear-gradient(120deg,var(--violet),var(--plum))">
    <p class="quote" style="color:#fff;border:0;padding:0;margin:0 auto;max-width:48rem"><?= h(s_tr('philosophy_quote')) ?></p>
    <p style="color:var(--pink-2);margin:1rem 0 0">-<?= h($docName) ?></p>
  </div>
</div></section>

<section class="section"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Appointment</p><h2 class="display"><?= h(tr('কীভাবে সিরিয়াল নেবেন', 'How to book a slot')) ?></h2><p><?= h(tr('তিন ধাপে সহজে অ্যাপয়েন্টমেন্ট নিশ্চিত করুন।', 'Confirm your appointment in three simple steps.')) ?></p></div>
  <div class="visit-grid">
    <ol class="steps reveal">
      <li><strong><?= h(tr('ফর্ম পূরণ করুন বা হোয়াটসঅ্যাপ করুন', 'Fill in the form or message us on WhatsApp')) ?></strong><span><?= h(tr('নাম, মোবাইল নম্বর ও সুবিধাজনক তারিখ জানান।', 'Share your name, mobile number and a convenient date.')) ?></span></li>
      <li><strong><?= h(tr('আমাদের টিম যোগাযোগ করবে', 'Our team will contact you')) ?></strong><span><?= h(tr('সময়সূচি নিশ্চিত করে আপনাকে জানানো হবে।', 'We will confirm the schedule with you.')) ?></span></li>
      <li><strong><?= h(tr('নির্ধারিত সময়ে চেম্বারে আসুন', 'Come to the chamber at the scheduled time')) ?></strong><span><?= h(tr('আগের রিপোর্ট ও প্রেসক্রিপশন সঙ্গে আনুন।', 'Bring your previous reports and prescriptions.')) ?></span></li>
    </ol>
    <div class="visit-card reveal">
      <h3><?= h(tr('চেম্বার ও যোগাযোগ', 'Chamber and contact')) ?></h3>
      <ul>
        <li><b><?= h(tr('কর্মস্থল:', 'Workplace:')) ?></b> <?= h(s_tr('workplace')) ?></li>
<?php if (!chamber_rows() && trim(setting('chamber_address')) !== ''): ?>
        <li><b><?= h(tr('ঠিকানা:', 'Address:')) ?></b> <?= nl2br(h(setting('chamber_address')), false) ?></li>
<?php endif; ?>
<?php if (!chamber_rows()): ?>
        <li><b><?= h(tr('সময়:', 'Hours:')) ?></b> <?= h(s_tr('chamber_hours')) ?></li>
<?php endif; ?>
        <li><b><?= h(tr('ফোন:', 'Phone:')) ?></b> <a href="<?= h(tel_link()) ?>"><?= h(setting('phone_display')) ?></a></li>
        <li><b><?= h(tr('হোয়াটসঅ্যাপ:', 'WhatsApp:')) ?></b> <a href="<?= h(wa_link(setting('wa_message'))) ?>" rel="noopener" target="_blank"><?= h(setting('whatsapp_display')) ?></a></li>
      </ul>
      <a class="btn btn-primary" href="/appointment"><?= h(tr('অ্যাপয়েন্টমেন্ট ফর্ম', 'Appointment form')) ?></a>
    </div>
  </div>
<?php if (chamber_rows()): ?>
  <h3 class="chamber-title reveal"><?= h(tr('চেম্বার ও বসার স্থান', 'Chambers and locations')) ?></h3>
  <?= chamber_cards_html() ?>
<?php endif; ?>
<?php if (chamber_map_src() !== ''): ?>
  <div class="map reveal" style="margin-top:1.4rem"><iframe src="<?= h(chamber_map_src()) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?= h(tr('চেম্বারের ম্যাপ', 'Chamber map')) ?>" style="width:100%;height:340px;border:0;border-radius:var(--radius)"></iframe></div>
<?php endif; ?>
</div></section>

<?php if ($posts): ?>
<section class="section"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Blog</p><h2 class="display"><?= h(tr('স্বাস্থ্য সচেতনতার লেখা', 'Health awareness articles')) ?></h2></div>
  <div class="grid c3">
<?php foreach ($posts as $i => $p): ?>
    <a class="card reveal" href="/blog/<?= h($p['slug']) ?>">
      <div class="card-img"><?= img_picture(img_url($p['cover_image']), $p['title'], 0, 0, '(max-width:620px) 100vw, (max-width:980px) 50vw, 33vw') ?></div>
<?php if ($p['category'] !== ''): ?><span class="chip"><?= h(ui($p['category'])) ?></span><?php endif; ?>
      <h3><?= h($p['title']) ?></h3><p><?= h(trim_text((string) $p['excerpt'], 130)) ?></p><span class="more"><?= h(tr('পড়ুন →', 'Read →')) ?></span>
    </a>
<?php endforeach; ?>
  </div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/blog"><?= h(tr('সব লেখা দেখুন', 'View all articles')) ?></a></p>
</div></section>
<?php endif; ?>

<?php if ($tests): ?>
<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Testimonials</p><h2 class="display"><?= h(tr('রোগীদের অভিজ্ঞতা', 'Patient experiences')) ?></h2></div>
  <div class="grid c3">
<?php foreach ($tests as $r): ?>
    <figure class="card tcard reveal" style="margin:0"><blockquote>“<?= h($r['quote']) ?>”</blockquote><figcaption><strong><?= h($r['name']) ?></strong> <small class="note"><?= h($r['context']) ?></small></figcaption></figure>
<?php endforeach; ?>
  </div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/testimonials"><?= h(tr('সব অভিজ্ঞতা পড়ুন', 'Read all experiences')) ?></a></p>
</div></section>
<?php endif; ?>

<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Warning signs</p><h2 class="display"><?= h(tr('কখন দেরি না করে যোগাযোগ করবেন', 'When to contact us without delay')) ?></h2><p><?= h(tr('নিচের লক্ষণগুলো দেখা দিলে অপেক্ষা না করে চিকিৎসকের সাথে যোগাযোগ করুন বা নিকটস্থ হাসপাতালে যান।', 'If any of these signs appear, do not wait. Contact the doctor or go to the nearest hospital.')) ?></p></div>
  <div class="grid c2">
    <div class="card warn reveal"><h3><?= h(tr('অবিলম্বে যোগাযোগ করুন', 'Contact us immediately')) ?></h3><ul class="plain-list">
      <li><?= h(tr('অতিরিক্ত রক্তস্রাব বা বড় জমাট রক্ত', 'Heavy bleeding or large clots')) ?></li>
      <li><?= h(tr('তীব্র তলপেটের ব্যথা, হঠাৎ শুরু হওয়া ব্যথা', 'Severe lower abdominal pain or sudden pain')) ?></li>
      <li><?= h(tr('গর্ভাবস্থায় রক্তক্ষরণ, পানি ভাঙা বা শিশুর নড়াচড়া কমে যাওয়া', 'Bleeding in pregnancy, waters breaking, or reduced baby movement')) ?></li>
    </ul></div>
    <div class="card reveal"><h3><?= h(tr('সাধারণ পরামর্শের বিষয়', 'Topics for routine consultation')) ?></h3><ul class="plain-list">
      <li><?= h(tr('মাসিকের অনিয়ম বা দীর্ঘদিন ধরে বন্ধ্যাত্বের সমস্যা', 'Irregular periods or long-standing infertility')) ?></li>
      <li><?= h(tr('গর্ভকালীন নিয়মিত চেকআপ ও পরিকল্পনা', 'Regular antenatal check-ups and planning')) ?></li>
      <li><?= h(tr('সার্জারি বা অস্ত্রোপচারের আগে বিস্তারিত আলোচনা', 'Detailed discussion before surgery')) ?></li>
    </ul></div>
  </div>
  <p class="note" style="margin-top:1rem"><?= h(tr('এই তথ্য সাধারণ ধারণার জন্য। ব্যক্তিগত চিকিৎসার জন্য চিকিৎসকের সরাসরি পরামর্শ নিন।', 'This information is general. For personal treatment, consult the doctor directly.')) ?></p>
</div></section>

<?php if ($faqs): ?>
<section class="section"><div class="wrap" style="max-width:860px">
  <div class="section-head reveal"><p class="kicker">FAQ</p><h2 class="display"><?= h(tr('সচরাচর জিজ্ঞাসিত প্রশ্ন', 'Frequently asked questions')) ?></h2></div>
  <div class="faq"><?php foreach ($faqs as $r): ?><details class="reveal"><summary><?= h($r['question']) ?></summary><div class="ans"><?= nl2br(h($r['answer']), false) ?></div></details><?php endforeach; ?></div>
  <p><a class="btn btn-outline" href="/faqs"><?= h(tr('আরও প্রশ্নোত্তর', 'More questions and answers')) ?></a></p>
</div></section>
<?php endif; ?>

<?php cta_band(); page_end(['gsap' => true, 'hero' => true]);
