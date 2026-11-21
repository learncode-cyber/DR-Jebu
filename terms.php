<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
page_start(['title' => tr('ব্যবহারের শর্তাবলি', 'Terms of Use'), 'description' => tr('এই ওয়েবসাইট ব্যবহারের শর্তাবলি ও চিকিৎসা-সংক্রান্ত দাবিত্যাগ।', 'Terms of use of this website and the medical disclaimer.'), 'path' => '/terms', 'breadcrumb' => [[ui('শর্তাবলি'), null]]]);
page_hero('Terms', tr('ব্যবহারের শর্তাবলি', 'Terms of Use'), tr('এই ওয়েবসাইট ব্যবহারের আগে অনুগ্রহ করে পড়ুন।', 'Please read before using this website.'), [[ui('শর্তাবলি'), null]]);
?>
<section class="section"><div class="wrap prose">
  <h2><?= h(tr('তথ্যের উদ্দেশ্য', 'Purpose of the information')) ?></h2>
  <p><?= h(tr('এই ওয়েবসাইটের লেখা, ছবি ও ভিডিও সাধারণ স্বাস্থ্য সচেতনতার জন্য। এগুলো কোনোভাবেই ব্যক্তিগত চিকিৎসা পরামর্শ, রোগ নির্ণয় বা চিকিৎসার বিকল্প নয়।', 'The articles, pictures and videos on this website are for general health awareness only. They are in no way a substitute for personal medical advice, diagnosis or treatment.')) ?></p>
  <h2><?= h(tr('জরুরি অবস্থা', 'Emergencies')) ?></h2>
  <p><?= h(tr('জরুরি স্বাস্থ্য সমস্যায় (তীব্র ব্যথা, অতিরিক্ত রক্তপাত, গর্ভাবস্থায় হঠাৎ জটিলতা) ওয়েবসাইট ফর্মের ওপর নির্ভর না করে নিকটস্থ হাসপাতালের জরুরি বিভাগে যান।', 'For a health emergency (severe pain, heavy bleeding, sudden complications in pregnancy), do not rely on the website form. Go to the emergency department of the nearest hospital.')) ?></p>
  <h2><?= h(tr('অ্যাপয়েন্টমেন্ট', 'Appointments')) ?></h2>
  <p><?= h(tr('ফর্ম জমা দেওয়া মানে অনুরোধ পাঠানো, সিরিয়াল নিশ্চিত হয় আমাদের টিমের সঙ্গে কথা বলার পরই। চিকিৎসকের প্রাপ্যতা অনুযায়ী সময় পরিবর্তিত হতে পারে।', 'Submitting a form sends a request. Your slot is confirmed only after you speak with our team. Times may change depending on the doctor\'s availability.')) ?></p>
  <h2><?= h(tr('বিষয়বস্তুর মালিকানা', 'Ownership of content')) ?></h2>
  <p><?= h(tr('ওয়েবসাইটের লেখা ও ছবি লিখিত অনুমতি ছাড়া বাণিজ্যিকভাবে পুনঃপ্রকাশ করা যাবে না।', 'The articles and pictures on this website may not be republished commercially without written permission.')) ?></p>
  <h2><?= h(tr('পরিবর্তন', 'Changes')) ?></h2>
  <p><?= h(tr('এই শর্তাবলি প্রয়োজন অনুযায়ী পরিবর্তিত হতে পারে। সর্বশেষ সংস্করণ সবসময় এই পেজে পাওয়া যাবে।', 'These terms may change when needed. The latest version will always be available on this page.')) ?></p>
</div></section>
<?php page_end();
