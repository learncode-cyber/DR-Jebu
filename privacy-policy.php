<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
page_start(['title' => tr('গোপনীয়তা নীতি', 'Privacy Policy'), 'description' => tr('এই ওয়েবসাইট কোন তথ্য সংগ্রহ করে এবং কীভাবে ব্যবহার করে।', 'What information this website collects and how it is used.'), 'path' => '/privacy-policy', 'breadcrumb' => [[ui('গোপনীয়তা নীতি'), null]]]);
page_hero('Privacy', tr('গোপনীয়তা নীতি', 'Privacy Policy'), tr('আপনার তথ্য কীভাবে সংগ্রহ ও ব্যবহার করা হয়।', 'How your information is collected and used.'), [[ui('গোপনীয়তা নীতি'), null]]);
?>
<section class="section"><div class="wrap prose">
  <h2><?= h(tr('কোন তথ্য সংগ্রহ করা হয়', 'What information we collect')) ?></h2>
  <p><?= h(tr('অ্যাপয়েন্টমেন্ট বা যোগাযোগ ফর্ম পূরণ করলে আপনার দেওয়া নাম, মোবাইল নম্বর, বয়স, পছন্দের তারিখ, (ঐচ্ছিক) ইমেইল ও বার্তা আমাদের ডাটাবেসে সংরক্ষিত হয়। নিরাপত্তা ও স্প্যাম প্রতিরোধের জন্য আইপি ঠিকানা ও ব্রাউজারের তথ্যও রাখা হয়।', 'When you fill in the appointment or contact form, the name, mobile number, age, preferred date, email (optional) and message you give are saved in our database. For security and spam prevention, we also keep your IP address and browser information.')) ?></p>
  <h2><?= h(tr('কেন ব্যবহার করা হয়', 'Why we use it')) ?></h2>
  <p><?= h(tr('তথ্য শুধুমাত্র আপনার সঙ্গে যোগাযোগ করে সিরিয়াল নিশ্চিত করা এবং সেবার মান উন্নত করার জন্য ব্যবহার করা হয়। আপনার তথ্য বিক্রি করা হয় না।', 'Your information is used only to contact you and confirm your appointment, and to improve our service. We do not sell your information.')) ?></p>
  <h2><?= h(tr('স্বাস্থ্য সংক্রান্ত তথ্য', 'Health information')) ?></h2>
  <p><?= h(tr('অনুগ্রহ করে ফর্মে বিস্তারিত রোগের ইতিহাস, রিপোর্ট বা সংবেদনশীল স্বাস্থ্য তথ্য লিখবেন না। এসব বিষয় সরাসরি চেম্বারে আলোচনা করা হবে।', 'Please do not write detailed medical history, reports or sensitive health information in the form. We will discuss these matters directly at the chamber.')) ?></p>
  <h2><?= h(tr('অ্যানালিটিক্স ও কুকি', 'Analytics and cookies')) ?></h2>
  <p><?= h(tr('সাইটে Google Analytics বা Meta Pixel সক্রিয় থাকলে পেজ ভিউ ও ফর্ম জমা দেওয়ার মতো ঘটনা পরিমাপ করা হয়। এগুলো আপনার ব্রাউজারে কুকি রাখতে পারে। ব্রাউজার সেটিংস থেকে আপনি কুকি বন্ধ করতে পারেন। ভিডিও চালানোর সময় YouTube-এর সেবা ব্যবহৃত হয়।', 'If Google Analytics or Meta Pixel is active on the site, page views and events such as form submissions are measured. These may place cookies in your browser. You can turn cookies off in your browser settings. Videos use the YouTube service when played.')) ?></p>
  <h2><?= h(tr('তথ্য মুছে ফেলা', 'Deleting your information')) ?></h2>
  <p><?= h(tr('আপনার জমা দেওয়া তথ্য সংশোধন বা মুছে ফেলতে চাইলে ', 'To correct or delete the information you have submitted, please let us know through the ')) ?><a href="/contact"><?= h(tr('যোগাযোগ পেজ', 'contact page')) ?></a><?= h(tr(' বা ফোনের মাধ্যমে জানান।', ' or by phone.')) ?></p>
  <p class="note"><?= h(tr('সর্বশেষ হালনাগাদ: ', 'Last updated: ')) ?><?= h(site_lang() === 'en' ? date('j F Y') : bn_date(date('Y-m-d'))) ?></p>
</div></section>
<?php page_end();
