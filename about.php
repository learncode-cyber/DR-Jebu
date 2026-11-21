<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$isEn = site_lang() === 'en';
$altBn = trim((string) preg_replace('/^(ডাঃ|ডা\.?|Dr\.?)\s*/u', '', trim(setting('doctor_name_alt_bn'))));
$altPart = (!$isEn && $altBn !== '') ? ' (' . $altBn . ')' : '';
$docName = tr(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn'), setting('doctor_title_en') . ' ' . setting('doctor_name_en'));
$creds = array_map(static fn($l) => array_pad(explode('|', $l, 2), 2, ''), s_lines('credentials'));
$paras = s_paras('about_text');
$train = s_lines('training_text');
$addr = trim(setting('chamber_address'));
page_start([
    'title' => tr('পরিচিতি ও যোগ্যতা', 'About and qualifications'),
    'description' => trim_text($docName . $altPart . ' | ' . tr(setting('degrees_line'), setting('degrees_line')) . '। ' . s_tr('workplace') . '।', 155),
    'path' => '/about', 'breadcrumb' => [[ui('পরিচিতি'), null]],
    'schema' => [[
        '@context' => 'https://schema.org', '@type' => 'Physician', 'name' => doctor_schema_name(), 'alternateName' => doctor_schema_alt_names(),
        'jobTitle' => setting('doctor_title_en'), 'medicalSpecialty' => ['Obstetric', 'Gynecologic'], 'url' => abs_url('/about'),
        'image' => abs_url('/assets/img/doctor-portrait.jpg'), 'telephone' => '+' . preg_replace('/\D/', '', setting('whatsapp_intl')),
        'worksFor' => ['@type' => 'Hospital', 'name' => setting('workplace')],
        'hasCredential' => array_map(static fn($c) => ['@type' => 'EducationalOccupationalCredential', 'name' => $c[0]], $creds),
    ]],
]);
page_hero('About', $docName, tr(setting('specialty_line_bn'), setting('specialty_line_en')), [[ui('পরিচিতি'), null]]);
?>
<section class="section"><div class="wrap split">
  <div class="photo reveal"><?= img_picture('/assets/img/doctor-portrait.jpg', $docName, 960, 807, '(max-width:980px) 100vw, 45vw', false) ?></div>
  <div class="reveal">
    <p class="kicker"><?= h(setting('doctor_name_en')) ?></p>
    <p class="quote"><?= h(s_tr('about_quote')) ?></p>
<?php foreach ($paras as $p): ?><p><?= h($p) ?></p><?php endforeach; ?>
    <p><a class="btn btn-primary" href="/appointment"><?= h(ui('অ্যাপয়েন্টমেন্ট নিন')) ?></a> <a class="btn btn-outline" href="/contact"><?= h(ui('যোগাযোগ')) ?></a></p>
  </div>
</div></section>

<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Qualifications</p><h2 class="display"><?= h(tr('যোগ্যতা ও স্বীকৃতি', 'Qualifications and recognition')) ?></h2><p><?= h(tr('দেশীয় ও আন্তর্জাতিক স্বীকৃতিতে গঠিত একটি ক্লিনিক্যাল পথচলা।', 'A clinical career built on national and international recognition.')) ?></p></div>
  <div class="grid c3">
<?php foreach ($creds as $i => $c): ?>
    <div class="card reveal" style="transition-delay:<?= ($i % 3) * 60 ?>ms"><div class="ico" aria-hidden="true">✦</div><h3><?= h($c[0]) ?></h3><p><?= h($c[1]) ?></p></div>
<?php endforeach; ?>
  </div>
</div></section>

<section class="section"><div class="wrap split" style="align-items:start">
  <div class="reveal">
    <p class="kicker">Training & Career</p><h2 class="display"><?= h(tr('প্রশিক্ষণ ও কর্মজীবন', 'Training and career')) ?></h2>
    <ul class="info-list">
<?php foreach ($train as $t): ?><li><span aria-hidden="true">◆</span><span><?= h($t) ?></span></li><?php endforeach; ?>
    </ul>
  </div>
  <div class="reveal">
    <p class="kicker">Chamber</p><h2 class="display"><?= h(tr('চেম্বার ও যোগাযোগ', 'Chamber and contact')) ?></h2>
    <div class="box"><ul class="info-list">
      <li><b><?= h(tr('কর্মস্থল', 'Workplace')) ?></b><span><?= h(s_tr('workplace')) ?></span></li>
<?php if ($addr !== ''): ?><li><b><?= h(tr('ঠিকানা', 'Address')) ?></b><span><?= nl2br(h($addr), false) ?></span></li><?php endif; ?>
      <li><b><?= h(tr('সময়', 'Hours')) ?></b><span><?= h(s_tr('chamber_hours')) ?></span></li>
      <li><b><?= h(tr('ফোন', 'Phone')) ?></b><a href="<?= h(tel_link()) ?>"><?= h(setting('phone_display')) ?></a></li>
      <li><b><?= h(tr('হোয়াটসঅ্যাপ', 'WhatsApp')) ?></b><a href="<?= h(wa_link(setting('wa_message'))) ?>" target="_blank" rel="noopener"><?= h(setting('whatsapp_display')) ?></a></li>
    </ul></div>
    <p class="quote" style="margin-top:2rem"><?= h(s_tr('philosophy_quote')) ?></p>
  </div>
</div></section>
<?php cta_band(); page_end();
