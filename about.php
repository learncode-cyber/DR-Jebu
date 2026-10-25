<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$creds = array_map(static fn($l) => array_pad(explode('|', $l, 2), 2, ''), setting_lines('credentials'));
$paras = setting_paras('about_text');
$train = setting_lines('training_text');
$addr = trim(setting('chamber_address'));
page_start([
    'title' => 'পরিচিতি ও যোগ্যতা', 'description' => setting('doctor_title_bn') . ' ' . setting('doctor_name_bn') . ' — ' . setting('degrees_line') . '। ' . setting('workplace') . '।',
    'path' => '/about', 'breadcrumb' => [['পরিচিতি', null]],
    'schema' => [[
        '@context' => 'https://schema.org', '@type' => 'Physician', 'name' => setting('doctor_name_bn'), 'alternateName' => setting('doctor_name_en'),
        'jobTitle' => setting('doctor_title_en'), 'medicalSpecialty' => ['Obstetric', 'Gynecologic'], 'url' => abs_url('/about'),
        'image' => abs_url('/assets/img/doctor-portrait.jpg'), 'telephone' => '+' . preg_replace('/\D/', '', setting('whatsapp_intl')),
        'worksFor' => ['@type' => 'Hospital', 'name' => setting('workplace')],
        'hasCredential' => array_map(static fn($c) => ['@type' => 'EducationalOccupationalCredential', 'name' => $c[0]], $creds),
    ]],
]);
page_hero('About', setting('doctor_title_bn') . ' ' . setting('doctor_name_bn'), setting('specialty_line_bn'), [['পরিচিতি', null]]);
?>
<section class="section"><div class="wrap split">
  <div class="photo reveal"><img src="/assets/img/doctor-portrait.jpg" alt="<?= h(setting('doctor_name_bn')) ?>" width="960" height="807"></div>
  <div class="reveal">
    <p class="kicker"><?= h(setting('doctor_name_en')) ?></p>
    <p class="quote"><?= h(setting('about_quote')) ?></p>
<?php foreach ($paras as $p): ?><p><?= h($p) ?></p><?php endforeach; ?>
    <p><a class="btn btn-primary" href="/appointment">অ্যাপয়েন্টমেন্ট নিন</a> <a class="btn btn-outline" href="/contact">যোগাযোগ</a></p>
  </div>
</div></section>

<section class="section alt"><div class="wrap">
  <div class="section-head reveal"><p class="kicker">Qualifications</p><h2 class="display">যোগ্যতা ও স্বীকৃতি</h2><p>দেশীয় ও আন্তর্জাতিক স্বীকৃতিতে গঠিত একটি ক্লিনিক্যাল পথচলা।</p></div>
  <div class="grid c3">
<?php foreach ($creds as $i => $c): ?>
    <div class="card reveal" style="transition-delay:<?= ($i % 3) * 60 ?>ms"><div class="ico" aria-hidden="true">✦</div><h3><?= h($c[0]) ?></h3><p><?= h($c[1]) ?></p></div>
<?php endforeach; ?>
  </div>
</div></section>

<section class="section"><div class="wrap split" style="align-items:start">
  <div class="reveal">
    <p class="kicker">Training & Career</p><h2 class="display">প্রশিক্ষণ ও কর্মজীবন</h2>
    <ul class="info-list">
<?php foreach ($train as $t): ?><li><span aria-hidden="true">◆</span><span><?= h($t) ?></span></li><?php endforeach; ?>
    </ul>
  </div>
  <div class="reveal">
    <p class="kicker">Chamber</p><h2 class="display">চেম্বার ও যোগাযোগ</h2>
    <div class="box"><ul class="info-list">
      <li><b>কর্মস্থল</b><span><?= h(setting('workplace')) ?></span></li>
<?php if ($addr !== ''): ?><li><b>ঠিকানা</b><span><?= nl2br(h($addr), false) ?></span></li><?php endif; ?>
      <li><b>সময়</b><span><?= h(setting('chamber_hours')) ?></span></li>
      <li><b>ফোন</b><a href="<?= h(tel_link()) ?>"><?= h(setting('phone_display')) ?></a></li>
      <li><b>হোয়াটসঅ্যাপ</b><a href="<?= h(wa_link(setting('wa_message'))) ?>" target="_blank" rel="noopener"><?= h(setting('whatsapp_display')) ?></a></li>
    </ul></div>
    <p class="quote" style="margin-top:2rem"><?= h(setting('philosophy_quote')) ?></p>
  </div>
</div></section>
<?php cta_band(); page_end();
