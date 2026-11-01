<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$icons = [
    '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 54 38 26"/><path d="M38 26l12-14c2-2 5 1 3 3L41 31z" fill="currentColor" fill-opacity=".28"/><path d="M44 46l8 8M50 40l5 5M20 14v8M16 18h8"/></svg>',
    '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M32 55S8 41 8 24a12 12 0 0 1 24-5 12 12 0 0 1 24 5c0 17-24 31-24 31z" fill="currentColor" fill-opacity=".18"/><circle cx="32" cy="28" r="6"/><path d="M25 41c2.5-4.5 11.5-4.5 14 0"/></svg>',
    '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" aria-hidden="true"><circle cx="24" cy="32" r="15" fill="currentColor" fill-opacity=".15"/><circle cx="42" cy="32" r="15" fill="currentColor" fill-opacity=".15"/><circle cx="24" cy="32" r="4.5" fill="currentColor"/><path d="M8 10l6 6M56 10l-6 6M32 6v8"/></svg>',
];
$themes = [
    ['kicker' => 'Advanced Laparoscopic Surgery', 'title' => 'অত্যাধুনিক ল্যাপারোস্কোপিক সার্জারি', 'sub' => 'ফাইব্রয়েড, সিস্ট ও প্রজনন অঙ্গের জটিল অস্ত্রোপচারে অভিজ্ঞ সেবা — প্রতিটি রোগীর জন্য সুনির্দিষ্ট পরিকল্পনা।', 'link' => '/procedures/laparoscopic-surgery', 'linkLabel' => 'ল্যাপারোস্কোপিক সার্জারি সম্পর্কে জানুন', 'bg1' => '#801B4B', 'bg2' => '#3d0a24', 'glow' => '#f8bbd0', 'card' => 'ল্যাপারোস্কোপিক সার্জারি'],
    ['kicker' => 'Complete Antenatal Care', 'title' => 'সম্পূর্ণ গর্ভকালীন ও প্রসব সেবা', 'sub' => 'গর্ভধারণের প্রথম দিন থেকে প্রসব-পরবর্তী পরিচর্যা পর্যন্ত সম্পূর্ণ মাতৃযত্ন।', 'link' => '/services/antenatal-care-service', 'linkLabel' => 'গর্ভকালীন সেবা সম্পর্কে জানুন', 'bg1' => '#4b2a7b', 'bg2' => '#1e0f3a', 'glow' => '#d9c2f5', 'card' => 'অ্যান্টিনেটাল কেয়ার'],
    ['kicker' => 'Infertility & IVF', 'title' => 'বন্ধ্যাত্ব ও IVF চিকিৎসা', 'sub' => 'দম্পতির সম্মিলিত মূল্যায়ন এবং প্রমাণভিত্তিক চিকিৎসা পরিকল্পনা।', 'link' => '/services/infertility-treatment-program', 'linkLabel' => 'বন্ধ্যাত্ব চিকিৎসা সম্পর্কে জানুন', 'bg1' => '#b0356f', 'bg2' => '#5a1238', 'glow' => '#fce4ec', 'card' => 'বন্ধ্যাত্ব ও IVF'],
];
$t0 = $themes[0];
$degrees = setting_lines('degrees');

$specialties = $services = $conditions = $posts = $tests = $faqs = [];
$counts = ['sp' => 0, 'sv' => 0, 'cd' => 0, 'bl' => 0];
try {
    $specialties = entity_rows('specialties', 6);
    $services = entity_rows('services', 6);
    $conditions = entity_rows('conditions');
    $posts = q_all("SELECT slug, title, excerpt, cover_image, category, published_at FROM blog_posts WHERE status='published' AND published_at <= ? ORDER BY published_at DESC LIMIT 3", [date('Y-m-d H:i:s')]);
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
    'title' => 'খুলনায় স্ত্রী রোগ ও বন্ধ্যাত্ব বিশেষজ্ঞ', 'home' => true, 'gsap' => true, 'hero' => true, 'path' => '/',
    'description' => setting('doctor_title_bn') . ' ' . setting('doctor_name_bn') . ' — ' . setting('specialty_line_bn') . '। কর্মস্থল: ' . setting('workplace') . '। বন্ধ্যাত্ব, IVF, গর্ভকালীন সেবা ও ল্যাপারোস্কোপিক সার্জারি। (Obs & Gynae, Infertility & IVF, Laparoscopic surgery, Khulna)',
    'schema' => [
        (static function () {
            // Only facts the owner has entered; address/map/social appear only when set
            $physician = ['@context' => 'https://schema.org', '@type' => 'Physician', 'name' => setting('doctor_name_bn'), 'alternateName' => setting('doctor_name_en'), 'jobTitle' => setting('doctor_title_en'), 'url' => abs_url('/'), 'image' => abs_url('/assets/img/doctor-portrait.jpg'), 'telephone' => '+' . preg_replace('/\D/', '', setting('whatsapp_intl')), 'medicalSpecialty' => ['Obstetric', 'Gynecologic'], 'worksFor' => ['@type' => 'Hospital', 'name' => setting('workplace')], 'availableService' => ['Infertility treatment', 'Laparoscopic surgery', 'Antenatal care']];
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
        ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => setting('doctor_name_bn'), 'url' => abs_url('/'), 'inLanguage' => 'bn'],
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
      <h1 class="sr-only">ডাঃ জেবুন্নেসা জেবু — খুলনায় স্ত্রী রোগ, বন্ধ্যাত্ব ও IVF বিশেষজ্ঞ</h1>
      <p class="kicker reveal-h" id="heroKicker"><?= h($t0['kicker']) ?></p>
      <h2 class="hero-title display reveal-h" id="heroTitle"><?= h($t0['title']) ?></h2>
      <p class="hero-sub reveal-h" id="heroSub"><?= h($t0['sub']) ?></p>
      <p class="reveal-h" style="margin:0 0 .4rem;color:#fff"><strong><?= h(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn')) ?></strong> · <?= h(setting('doctor_name_en')) ?></p>
      <ul class="hero-creds reveal-h"><?php foreach ($degrees as $d): ?><li><?= h($d) ?></li><?php endforeach; ?></ul>
      <div class="hero-actions reveal-h">
        <a class="btn btn-light" href="/appointment">অ্যাপয়েন্টমেন্ট নিন</a>
        <a class="btn btn-ghost" id="heroMore" href="<?= h($t0['link']) ?>"><?= h($t0['linkLabel']) ?></a>
      </div>
    </div>
    <div class="hero-stage">
      <div class="orb-wrap" id="orbWrap" aria-hidden="true">
        <span class="orb-ring"></span><div class="orb"></div>
        <div class="orb-photo" aria-hidden="true"><img src="/assets/img/doctor-portrait.jpg" alt="" width="960" height="807" decoding="async"></div>
      </div>
      <div class="switcher" role="group" aria-label="সেবা বেছে নিন">
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
    [$num, $sfx] = stat_parse(setting("stat{$n}_value"));
    if ($num > 0) { $statTiles[] = [$num, $sfx, setting("stat{$n}_label")]; }
}
foreach ([[count($degrees), '+', 'ডিগ্রি ও স্বীকৃতি'], [$counts['sp'], '+', 'বিশেষত্বের ক্ষেত্র'], [$counts['sv'], '+', 'সেবা'], [$counts['cd'], '+', 'রোগ নিয়ে পরামর্শ']] as $t) {
    if ($t[0] > 0) { $statTiles[] = $t; }
}
$statTiles = array_slice($statTiles, 0, 8);
?>
<?php if ($statTiles): ?>
<section class="section"><div class="wrap">
  <div class="stats reveal">
<?php foreach ($statTiles as [$num, $sfx, $label]): ?>
    <div class="stat"><b><span class="cnt" data-count="<?= (int) $num ?>"><?= h(bn_num((int) $num)) ?></span><span class="sfx"><?= h($sfx) ?></span></b><span><?= h($label) ?></span></div>
<?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<section class="section"><div class="wrap split">
  <div class="photo reveal"><img src="/assets/img/doctor-portrait.jpg" alt="<?= h(setting('doctor_name_bn')) ?>" width="960" height="807" loading="lazy"></div>
  <div class="reveal">
    <p class="kicker">About</p>
    <h2 class="display"><?= h(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn')) ?></h2>
    <p class="quote"><?= h(setting('about_quote')) ?></p>
<?php foreach (array_slice(setting_paras('about_text'), 0, 2) as $para): ?><p><?= h($para) ?></p><?php endforeach; ?>
    <p><a class="btn btn-primary" href="/about">পূর্ণ পরিচিতি →</a></p>
  </div>
</div></section>

<section class="section alt"><div class="wrap split">
  <div class="reveal">
    <p class="kicker">Education</p>
    <h2 class="display">শিক্ষা, প্রশিক্ষণ ও স্বীকৃতি</h2>
    <p>ডাঃ জেবুন্নেসা জেবুর শিক্ষাগত যোগ্যতা ও পেশাগত স্বীকৃতি:</p>
    <ul class="creds-list">
<?php foreach ($degrees as $d): ?>
      <li><?= h($d) ?></li>
<?php endforeach; ?>
    </ul>
  </div>
  <div class="photo photo-short reveal"><img src="/assets/img/doctor-graduation.jpg" alt="<?= h(setting('doctor_name_bn')) ?> — শিক্ষাগত সনদ গ্রহণের ছবি" width="1065" height="1072" loading="lazy"></div>
</div></section>

<?php if ($specialties): ?>
<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Specialties</p><h2 class="display">যে ক্ষেত্রগুলিতে নিবেদিত পরামর্শ ও চিকিৎসা</h2></div>
  <div class="grid c3"><?php foreach ($specialties as $i => $r) { entity_card('specialties', $r, $i); } ?></div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/specialties">সব বিশেষত্ব দেখুন</a></p>
</div></section>
<?php endif; ?>

<?php if ($services): ?>
<section class="section"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">One-stop Gynae Care</p><h2 class="display">ওয়ান-স্টপ গাইনী কেয়ার সেবা</h2><p>গর্ভাবস্থা থেকে সার্জারি — প্রয়োজনীয় সেবা এক জায়গায়।</p></div>
  <div class="grid c3"><?php foreach ($services as $i => $r) { entity_card('services', $r, $i); } ?></div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/services">সব সেবা দেখুন</a></p>
</div></section>
<?php endif; ?>

<?php if ($conditions): ?>
<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Conditions</p><h2 class="display">যেসব সমস্যায় পরামর্শ ও চিকিৎসা</h2></div>
  <ul class="pill-list reveal"><?php foreach ($conditions as $c): ?><li><a href="/conditions/<?= h($c['slug']) ?>"><?= h($c['title']) ?></a></li><?php endforeach; ?></ul>
</div></section>
<?php endif; ?>

<section class="section"><div class="wrap">
  <div class="cta-inner glass reveal" style="display:block;text-align:center;background:linear-gradient(120deg,var(--violet),var(--plum))">
    <p class="quote" style="color:#fff;border:0;padding:0;margin:0 auto;max-width:48rem"><?= h(setting('philosophy_quote')) ?></p>
    <p style="color:var(--pink-2);margin:1rem 0 0">— <?= h(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn')) ?></p>
  </div>
</div></section>

<section class="section"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Appointment</p><h2 class="display">কীভাবে সিরিয়াল নেবেন</h2><p>তিন ধাপে সহজে অ্যাপয়েন্টমেন্ট নিশ্চিত করুন।</p></div>
  <div class="visit-grid">
    <ol class="steps reveal">
      <li><strong>ফর্ম পূরণ করুন বা হোয়াটসঅ্যাপ করুন</strong><span>নাম, মোবাইল নম্বর ও সুবিধাজনক তারিখ জানান।</span></li>
      <li><strong>আমাদের টিম যোগাযোগ করবে</strong><span>সময়সূচি নিশ্চিত করে আপনাকে জানানো হবে।</span></li>
      <li><strong>নির্ধারিত সময়ে চেম্বারে আসুন</strong><span>আগের রিপোর্ট ও প্রেসক্রিপশন সঙ্গে আনুন।</span></li>
    </ol>
    <div class="visit-card reveal">
      <h3>চেম্বার ও যোগাযোগ</h3>
      <ul>
        <li><b>কর্মস্থল:</b> <?= h(setting('workplace')) ?></li>
<?php if (!chamber_rows() && trim(setting('chamber_address')) !== ''): ?>
        <li><b>ঠিকানা:</b> <?= nl2br(h(setting('chamber_address')), false) ?></li>
<?php endif; ?>
<?php if (!chamber_rows()): ?>
        <li><b>সময়:</b> <?= h(setting('chamber_hours')) ?></li>
<?php endif; ?>
        <li><b>ফোন:</b> <a href="<?= h(tel_link()) ?>"><?= h(setting('phone_display')) ?></a></li>
        <li><b>হোয়াটসঅ্যাপ:</b> <a href="<?= h(wa_link(setting('wa_message'))) ?>" rel="noopener" target="_blank"><?= h(setting('whatsapp_display')) ?></a></li>
      </ul>
      <a class="btn btn-primary" href="/appointment">অ্যাপয়েন্টমেন্ট ফর্ম</a>
    </div>
  </div>
<?php if (chamber_rows()): ?>
  <h3 class="chamber-title reveal">চেম্বার ও বসার স্থান</h3>
  <?= chamber_cards_html() ?>
<?php endif; ?>
<?php if (chamber_map_src() !== ''): ?>
  <div class="map reveal" style="margin-top:1.4rem"><iframe src="<?= h(chamber_map_src()) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="চেম্বারের ম্যাপ" style="width:100%;height:340px;border:0;border-radius:var(--radius)"></iframe></div>
<?php endif; ?>
</div></section>

<?php if ($posts): ?>
<section class="section"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Blog</p><h2 class="display">স্বাস্থ্য সচেতনতার লেখা</h2></div>
  <div class="grid c3">
<?php foreach ($posts as $i => $p): ?>
    <a class="card reveal" href="/blog/<?= h($p['slug']) ?>">
      <div class="card-img"><img src="<?= h(img_url($p['cover_image'])) ?>" alt="<?= h($p['title']) ?>" loading="lazy" width="800" height="500"></div>
<?php if ($p['category'] !== ''): ?><span class="chip"><?= h($p['category']) ?></span><?php endif; ?>
      <h3><?= h($p['title']) ?></h3><p><?= h(trim_text((string) $p['excerpt'], 130)) ?></p><span class="more">পড়ুন →</span>
    </a>
<?php endforeach; ?>
  </div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/blog">সব লেখা দেখুন</a></p>
</div></section>
<?php endif; ?>

<?php if ($tests): ?>
<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Testimonials</p><h2 class="display">রোগীদের অভিজ্ঞতা</h2></div>
  <div class="grid c3">
<?php foreach ($tests as $r): ?>
    <figure class="card tcard reveal" style="margin:0"><blockquote>“<?= h($r['quote']) ?>”</blockquote><figcaption><strong><?= h($r['name']) ?></strong> <small class="note"><?= h($r['context']) ?></small></figcaption></figure>
<?php endforeach; ?>
  </div>
  <p style="margin-top:1.6rem"><a class="btn btn-outline" href="/testimonials">সব অভিজ্ঞতা পড়ুন</a></p>
</div></section>
<?php endif; ?>

<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Warning signs</p><h2 class="display">কখন দেরি না করে যোগাযোগ করবেন</h2><p>নিচের লক্ষণগুলো দেখা দিলে অপেক্ষা না করে চিকিৎসকের সাথে যোগাযোগ করুন বা নিকটস্থ হাসপাতালে যান।</p></div>
  <div class="grid c2">
    <div class="card warn reveal"><h3>অবিলম্বে যোগাযোগ করুন</h3><ul class="plain-list">
      <li>অতিরিক্ত রক্তস্রাব বা বড় জমাট রক্ত</li>
      <li>তীব্র তলপেটের ব্যথা, হঠাৎ শুরু হওয়া ব্যথা</li>
      <li>গর্ভাবস্থায় রক্তক্ষরণ, পানি ভাঙা বা শিশুর নড়াচড়া কমে যাওয়া</li>
    </ul></div>
    <div class="card reveal"><h3>সাধারণ পরামর্শের বিষয়</h3><ul class="plain-list">
      <li>মাসিকের অনিয়ম বা দীর্ঘদিন ধরে বন্ধ্যাত্বের সমস্যা</li>
      <li>গর্ভকালীন নিয়মিত চেকআপ ও পরিকল্পনা</li>
      <li>সার্জারি বা অস্ত্রোপচারের আগে বিস্তারিত আলোচনা</li>
    </ul></div>
  </div>
  <p class="note" style="margin-top:1rem">এই তথ্য সাধারণ ধারণার জন্য। ব্যক্তিগত চিকিৎসার জন্য চিকিৎসকের সরাসরি পরামর্শ নিন।</p>
</div></section>

<?php if ($faqs): ?>
<section class="section"><div class="wrap" style="max-width:860px">
  <div class="section-head reveal"><p class="kicker">FAQ</p><h2 class="display">সচরাচর জিজ্ঞাসিত প্রশ্ন</h2></div>
  <div class="faq"><?php foreach ($faqs as $r): ?><details class="reveal"><summary><?= h($r['question']) ?></summary><div class="ans"><?= nl2br(h($r['answer']), false) ?></div></details><?php endforeach; ?></div>
  <p><a class="btn btn-outline" href="/faqs">আরও প্রশ্নোত্তর</a></p>
</div></section>
<?php endif; ?>

<?php cta_band(); page_end(['gsap' => true, 'hero' => true]);
