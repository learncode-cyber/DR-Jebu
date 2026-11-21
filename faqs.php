<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$rows = entity_rows('faqs');
$schema = [];
if ($rows) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn($r) => ['@type' => 'Question', 'name' => $r['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $r['answer']]], $rows)];
}
page_start(['title' => tr('সচরাচর জিজ্ঞাসিত প্রশ্ন (FAQ)', 'Frequently Asked Questions (FAQ)'), 'description' => 'অ্যাপয়েন্টমেন্ট, প্রথম ভিজিট, চেম্বারের সময় ও সেবা সংক্রান্ত সচরাচর প্রশ্নের উত্তর। খুলনায় ডাঃ জেবুন্নেসা জেবুর চেম্বারের তথ্য জানুন।', 'path' => '/faqs', 'breadcrumb' => [['FAQ', null]], 'schema' => $schema]);
page_hero('FAQ', ui('সচরাচর জিজ্ঞাসিত প্রশ্ন'), 'অ্যাপয়েন্টমেন্ট, প্রথম ভিজিট ও সেবা নিয়ে আপনার সম্ভাব্য প্রশ্নগুলোর উত্তর।', [['FAQ', null]]);
$groups = [];
foreach ($rows as $r) { $groups[$r['category'] !== '' ? $r['category'] : 'সাধারণ'][] = $r; }
?>
<section class="section"><div class="wrap" style="max-width:860px">
<?php if (!$rows): ?>
  <div class="empty"><p><?= h(ui('প্রশ্নোত্তর শীঘ্রই যুক্ত করা হবে।')) ?></p><a class="btn btn-primary" href="/contact"><?= h(ui('প্রশ্ন পাঠান')) ?></a></div>
<?php endif; ?>
<?php foreach ($groups as $cat => $list): ?>
  <h2 class="display" style="font-size:1.5rem;margin-top:1.6rem"><?= h(ui($cat)) ?></h2>
  <div class="faq">
<?php foreach ($list as $r): ?>
    <details class="reveal"><summary><?= h($r['question']) ?></summary><div class="ans"><?= nl2br(h($r['answer']), false) ?></div></details>
<?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div></section>
<?php cta_band('আপনার প্রশ্নের উত্তর পাননি?', 'সরাসরি জানিয়ে দিন। যত দ্রুত সম্ভব উত্তর দেওয়া হবে।'); page_end();
