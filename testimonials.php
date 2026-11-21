<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$rows = entity_rows('testimonials');
$schema = [];
if ($rows) {
    $reviews = [];
    foreach ($rows as $r) {
        if (!empty($r['rating'])) {
            $reviews[] = ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r['name']], 'reviewBody' => $r['quote'], 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $r['rating'], 'bestRating' => 5]];
        }
    }
    if ($reviews) {
        $schema[] = ['@context' => 'https://schema.org', '@type' => 'Physician', 'name' => doctor_schema_name(), 'url' => abs_url(lang_path('/')), 'review' => $reviews];
    }
}
page_start(['title' => 'রোগীদের অভিজ্ঞতা', 'description' => 'রোগী ও পরিবারের অভিজ্ঞতা ও মতামত। খুলনায় ডাঃ জেবুন্নেসা জেবুর চিকিৎসা নিয়ে রোগীদের বাস্তব অভিজ্ঞতা পড়ুন।', 'path' => '/testimonials', 'breadcrumb' => [['রোগীদের অভিজ্ঞতা', null]], 'schema' => $schema]);
page_hero('Testimonials', 'রোগীদের অভিজ্ঞতা', 'যাঁরা আমাদের সেবা নিয়েছেন, তাঁদের নিজের কথায়।', [['রোগীদের অভিজ্ঞতা', null]]);
?>
<section class="section"><div class="wrap">
<?php if (!$rows): ?>
  <div class="empty"><p><?= h(ui('রোগীদের মতামত শীঘ্রই এখানে প্রকাশ করা হবে।')) ?></p><a class="btn btn-primary" href="/appointment"><?= h(ui('অ্যাপয়েন্টমেন্ট নিন')) ?></a></div>
<?php else: ?>
  <div class="grid c3">
<?php foreach ($rows as $i => $r): ?>
    <figure class="card tcard reveal" style="margin:0">
<?php if (!empty($r['rating'])): ?><div class="stars" aria-label="<?= (int) $r['rating'] ?> out of 5"><?= str_repeat('★', max(1, min(5, (int) $r['rating']))) ?></div><?php endif; ?>
      <blockquote>“<?= h($r['quote']) ?>”</blockquote>
      <figcaption class="who">
<?php if (!empty($r['photo'])): ?><?= img_picture(img_url($r['photo']), '', 0, 0, '120px') ?><?php endif; ?>
        <span><strong><?= h($r['name']) ?></strong><br><small class="note"><?= h($r['context']) ?></small></span>
      </figcaption>
    </figure>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</div></section>
<?php cta_band(); page_end();
