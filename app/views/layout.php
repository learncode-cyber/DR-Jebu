<?php
declare(strict_types=1);

function current_path(): string {
    $p = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    return '/' . trim($p, '/');
}

function nav_items(): array {
    return [
        ['/', 'হোম', null],
        ['/about', 'পরিচিতি', null],
        ['/specialties', 'বিশেষত্ব', null],
        ['/services', 'সেবা', null],
        [null, 'রোগ ও চিকিৎসা', [['/conditions', 'রোগসমূহ'], ['/treatments', 'চিকিৎসা'], ['/procedures', 'প্রসিডিওর']]],
        ['/blog', 'ব্লগ', null],
        [null, 'আরও', [['/testimonials', 'রোগীর অভিজ্ঞতা'], ['/faqs', 'সচরাচর প্রশ্ন'], ['/gallery', 'গ্যালারি'], ['/videos', 'ভিডিও'], ['/contact', 'যোগাযোগ']]],
    ];
}

function nav_active(string $href): bool {
    $c = current_path();
    return $href === '/' ? $c === '/' : ($c === $href || str_starts_with($c, $href . '/'));
}

/**
 * $m keys: title, description, path, image, robots, schema[], breadcrumb[[label,url]], home(bool), gsap(bool), body_class
 */
function page_start(array $m): void {
    $name = setting('doctor_name_bn');
    $title = (string) ($m['title'] ?? $name);
    $fullTitle = $title === $name ? $name . ' — ' . setting('doctor_title_bn') . ', ' . setting('workplace') : $title . ' | ' . $name;
    $desc = (string) ($m['description'] ?? (setting('specialty_line_bn') . ' — ' . setting('workplace')));
    $path = (string) ($m['path'] ?? current_path());
    $canon = abs_url($path === '/' ? '/' : $path);
    $img = abs_url(parse_url(img_url($m['image'] ?? '', '/assets/img/doctor-portrait.jpg'), PHP_URL_PATH) ?: '/assets/img/doctor-portrait.jpg');
    $isProd = !defined('APP_ENV') || APP_ENV === 'production';
    // Non-production copies (staging/dev) must never be indexed, whatever the page asked for
    $robots = $isProd ? (string) ($m['robots'] ?? 'index, follow, max-image-preview:large') : 'noindex, nofollow';
    $home = !empty($m['home']);
    $schema = $m['schema'] ?? [];
    if (!empty($m['breadcrumb'])) {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'হোম', 'item' => abs_url('/')]];
        foreach ($m['breadcrumb'] as $i => $b) {
            $it = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $b[0]];
            if (!empty($b[1])) {
                $it['item'] = abs_url($b[1]);
            }
            $items[] = $it;
        }
        $schema[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($fullTitle) ?></title>
<meta name="description" content="<?= h(trim_text($desc, 170)) ?>">
<meta name="robots" content="<?= h($robots) ?>">
<link rel="canonical" href="<?= h($canon) ?>">
<meta name="theme-color" content="#801B4B">
<?php if (preg_match('/^[A-Za-z0-9_-]{8,}$/', setting('gsc_verification'))): ?><meta name="google-site-verification" content="<?= h(setting('gsc_verification')) ?>">
<?php endif; ?>
<?php if (preg_match('/^[A-Za-z0-9_-]{8,}$/', setting('bing_verification'))): ?><meta name="msvalidate.01" content="<?= h(setting('bing_verification')) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= h((string) ($m['og_type'] ?? 'website')) ?>">
<meta property="og:locale" content="bn_BD">
<meta property="og:site_name" content="<?= h($name) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h(trim_text($desc, 200)) ?>">
<meta property="og:url" content="<?= h($canon) ?>">
<meta property="og:image" content="<?= h($img) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="alternate" type="application/rss+xml" title="<?= h($name) ?> — ব্লগ" href="/feed.xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tiro+Bangla:ital@0;1&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(asset('assets/css/site.css')) ?>">
<script>document.documentElement.className+=' js';</script>
<?php foreach ($schema as $s) { echo json_ld($s), "\n"; } ?>
<?php if (setting('ga_measurement_id') !== '' && preg_match('/^G-[A-Z0-9]+$/', setting('ga_measurement_id'))): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= h(setting('ga_measurement_id')) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= h(setting('ga_measurement_id')) ?>');</script>
<?php endif; ?>
<?php if (setting('meta_pixel_id') !== '' && preg_match('/^[0-9]+$/', setting('meta_pixel_id'))): ?>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','<?= h(setting('meta_pixel_id')) ?>');fbq('track','PageView');</script>
<?php endif; ?>
</head>
<body class="<?= $home ? 'is-home' : 'is-inner' ?> <?= h((string) ($m['body_class'] ?? '')) ?>">
<a class="skip" href="#main">মূল কনটেন্টে যান</a>
<header class="site-header" id="siteHeader">
  <div class="wrap header-row">
    <a class="brand" href="/" aria-label="<?= h($name) ?> — হোম">
      <img class="brand-mark" src="/assets/img/doctor-portrait.jpg" alt="" width="46" height="46" decoding="async">
      <span class="brand-text"><small><?= h(setting('doctor_title_bn')) ?></small><strong><?= h($name) ?></strong></span>
    </a>
    <button class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="siteNav" aria-label="মেনু খুলুন"><span></span><span></span><span></span></button>
    <nav class="site-nav" id="siteNav" aria-label="প্রধান মেনু">
      <ul>
<?php foreach (nav_items() as [$href, $label, $sub]): ?>
<?php if ($sub === null): ?>
        <li><a href="<?= h($href) ?>" class="<?= nav_active($href) ? 'active' : '' ?>"><?= h($label) ?></a></li>
<?php else:
    $open = false;
    foreach ($sub as $s) { if (nav_active($s[0])) { $open = true; } } ?>
        <li class="has-sub <?= $open ? 'active' : '' ?>">
          <button type="button" class="sub-btn" aria-expanded="false"><?= h($label) ?> <span aria-hidden="true">▾</span></button>
          <ul class="sub">
<?php foreach ($sub as [$sh, $sl]): ?>
            <li><a href="<?= h($sh) ?>" class="<?= nav_active($sh) ? 'active' : '' ?>"><?= h($sl) ?></a></li>
<?php endforeach; ?>
          </ul>
        </li>
<?php endif; ?>
<?php endforeach; ?>
        <li class="nav-cta"><a class="btn btn-primary btn-sm" href="/appointment">অ্যাপয়েন্টমেন্ট নিন</a></li>
      </ul>
    </nav>
  </div>
</header>
<main id="main">
<?php
}

function page_hero(string $kicker, string $title, string $lead = '', array $crumbs = []): void {
    ?>
<section class="page-hero">
  <div class="page-hero-bg" aria-hidden="true"></div>
  <div class="wrap">
<?php if ($crumbs): ?>
    <nav class="crumbs" aria-label="breadcrumb"><a href="/">হোম</a><?php foreach ($crumbs as $c): ?> <span aria-hidden="true">/</span> <?php if (!empty($c[1])): ?><a href="<?= h($c[1]) ?>"><?= h($c[0]) ?></a><?php else: ?><span><?= h($c[0]) ?></span><?php endif; ?><?php endforeach; ?></nav>
<?php endif; ?>
<?php if ($kicker !== ''): ?><p class="kicker"><?= h($kicker) ?></p><?php endif; ?>
    <h1 class="display"><?= h($title) ?></h1>
<?php if ($lead !== ''): ?><p class="lead"><?= h($lead) ?></p><?php endif; ?>
  </div>
</section>
<?php
}

function cta_band(string $heading = 'আপনার জন্য সঠিক পরামর্শ নিন', string $text = 'নাম ও মোবাইল নম্বর দিন — আমাদের টিম যোগাযোগ করে সিরিয়াল নিশ্চিত করবে।'): void {
    ?>
<section class="cta-band reveal">
  <div class="wrap cta-inner glass">
    <div>
      <h2 class="display"><?= h($heading) ?></h2>
      <p><?= h($text) ?></p>
    </div>
    <div class="cta-actions">
      <a class="btn btn-light" href="/appointment">অ্যাপয়েন্টমেন্ট নিন</a>
      <a class="btn btn-ghost" href="<?= h(tel_link()) ?>">কল: <?= h(setting('phone_display')) ?></a>
    </div>
  </div>
</section>
<?php
}

function page_end(array $m = []): void {
    $services = [];
    try {
        $services = q_all('SELECT slug, title FROM services WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 6');
    } catch (Throwable $e) {
    }
    $social = array_filter(['Facebook' => setting('facebook_url'), 'YouTube' => setting('youtube_url'), 'Instagram' => setting('instagram_url')], static fn($u) => preg_match('#^https?://#i', $u) === 1);
    $addr = trim(setting('chamber_address'));
    ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div class="f-brand">
      <p class="f-name display"><?= h(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn')) ?></p>
      <p class="f-sub"><?= h(setting('doctor_name_en')) ?> · <?= h(setting('degrees_line')) ?></p>
      <p class="f-sub"><?= h(setting('workplace')) ?></p>
<?php if ($social): ?>
      <p class="f-social"><?php foreach ($social as $label => $u): ?><a href="<?= h($u) ?>" rel="noopener" target="_blank"><?= h($label) ?></a> <?php endforeach; ?></p>
<?php endif; ?>
    </div>
    <div>
      <h3>দ্রুত লিংক</h3>
      <ul>
        <li><a href="/about">পরিচিতি</a></li><li><a href="/specialties">বিশেষত্ব</a></li><li><a href="/conditions">রোগসমূহ</a></li>
        <li><a href="/treatments">চিকিৎসা</a></li><li><a href="/procedures">প্রসিডিওর</a></li><li><a href="/blog">ব্লগ</a></li>
      </ul>
    </div>
    <div>
      <h3>সেবা</h3>
      <ul>
<?php foreach ($services as $s): ?>
        <li><a href="/services/<?= h($s['slug']) ?>"><?= h($s['title']) ?></a></li>
<?php endforeach; ?>
        <li><a href="/services">সব সেবা দেখুন →</a></li>
      </ul>
    </div>
    <div>
      <h3>যোগাযোগ</h3>
      <ul>
        <li><a href="<?= h(tel_link()) ?>">ফোন: <?= h(setting('phone_display')) ?></a></li>
        <li><a href="<?= h(wa_link(setting('wa_message'))) ?>" rel="noopener" target="_blank">হোয়াটসঅ্যাপ: <?= h(setting('whatsapp_display')) ?></a></li>
<?php if (setting('email') !== ''): ?><li><a href="mailto:<?= h(setting('email')) ?>"><?= h(setting('email')) ?></a></li><?php endif; ?>
<?php if (chamber_rows()): ?>
<?php foreach (chamber_rows() as $c): ?><li class="plain"><b><?= h((string) $c['name']) ?></b><?= $c['visiting_hours'] !== '' ? ' · ' . h((string) $c['visiting_hours']) : '' ?></li><?php endforeach; ?>
<?php else: ?>
<?php if ($addr !== ''): ?><li class="plain"><?= nl2br(h($addr), false) ?></li><?php endif; ?>
        <li class="plain"><?= h(setting('chamber_hours')) ?></li>
<?php endif; ?>
        <li><a href="/appointment">অ্যাপয়েন্টমেন্ট ফর্ম</a></li><li><a href="/contact">যোগাযোগ পেজ</a></li>
      </ul>
    </div>
  </div>
  <div class="wrap footer-base">
    <p class="disclaimer"><?= h(setting('footer_disclaimer')) ?></p>
    <p class="legal">
      এটি <?= h(setting('doctor_name_bn')) ?>-এর অফিশিয়াল ওয়েবসাইট। চেম্বারের সময়, ফোন ও ঠিকানার সর্বশেষ তথ্য এই সাইটেই পাওয়া যাবে। · © <?= date('Y') ?> <?= h(setting('doctor_name_bn')) ?> ·
      <a href="/privacy-policy">গোপনীয়তা নীতি</a> · <a href="/terms">শর্তাবলি</a> · <a href="/feed.xml">RSS</a>
    </p>
    <p class="agency-credit">Designed &amp; Developed by <a href="https://arvanceagency.com" rel="noopener" target="_blank">AR Vance Agency</a><br>A Digital &amp; Technology Company by <a href="https://arqudrix.com" rel="noopener" target="_blank">AR Qudrix</a></p>
  </div>
</footer>
<div class="sticky-actions" aria-label="দ্রুত যোগাযোগ">
  <button type="button" class="sa-ai" id="aiOpen" aria-haspopup="dialog" aria-controls="aiPop" aria-label="এআই সহকারীর সাথে কথা বলুন"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg><span>এআই সহকারী</span></button>
  <a class="sa-appt" href="/appointment" aria-label="অ্যাপয়েন্টমেন্ট নিন" data-track="appointment_click"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4M12 14v4M10 16h4"/></svg><span>অ্যাপয়েন্টমেন্ট</span></a>
  <a class="sa-wa" href="<?= h(wa_link(setting('wa_message'))) ?>" rel="noopener" target="_blank" aria-label="হোয়াটসঅ্যাপে মেসেজ" data-track="whatsapp_click"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l1.3-4A8 8 0 1 1 8 18.7z"/><path d="M9 10c0 2.5 2.5 5 5 5l1-1.2-2-1-.8.8c-1-.4-2-1.4-2.4-2.4l.8-.8-1-2z"/></svg><span>হোয়াটসঅ্যাপ</span></a>
</div>
<div class="ai-pop" id="aiPop" role="dialog" aria-modal="false" aria-labelledby="aiTitle" hidden>
  <div class="ai-head">
    <div><strong id="aiTitle">এআই সহকারী</strong><span>অ্যাপয়েন্টমেন্ট ও তথ্যের জন্য</span></div>
    <div class="ai-head-btns">
      <button type="button" id="aiSpeak" aria-pressed="false" title="উত্তর শুনুন" hidden>🔈</button>
      <button type="button" id="aiClose" aria-label="বন্ধ করুন">✕</button>
    </div>
  </div>
  <div class="ai-log" id="aiLog" aria-live="polite"></div>
  <div class="ai-chips">
    <button type="button" data-q="অ্যাপয়েন্টমেন্ট নিতে চাই">অ্যাপয়েন্টমেন্ট নিতে চাই</button>
    <button type="button" data-q="চেম্বারের সময় কখন?">চেম্বারের সময়</button>
    <button type="button" data-q="কোন সমস্যায় পরামর্শ নেওয়া যায়?">কোন সমস্যায় পরামর্শ?</button>
  </div>
  <form class="ai-form" id="aiForm" autocomplete="off">
    <button type="button" id="aiMic" aria-label="কথা বলে লিখুন" title="কথা বলে লিখুন" hidden>🎤</button>
    <input type="text" id="aiInput" maxlength="1000" placeholder="এখানে লিখুন…" aria-label="বার্তা লিখুন">
    <button type="submit" id="aiSend">পাঠান</button>
  </form>
  <p class="ai-note">এআই সহায়ক রোগ নির্ণয় বা ওষুধের পরামর্শ দেয় না। জরুরি অবস্থায় ৯৯৯ বা নিকটস্থ হাসপাতালে যান।</p>
</div>
<script src="<?= h(asset('assets/js/ai.js')) ?>" defer></script>
<?php if (!empty($m['gsap'])): ?>
<script src="<?= h(asset('assets/js/motion.min.js')) ?>"></script>
<script src="<?= h(asset('assets/js/scroll.min.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(asset('assets/js/site.js')) ?>"></script>
<?php if (!empty($m['hero'])): ?><script src="<?= h(asset('assets/js/hero.js')) ?>"></script><?php endif; ?>
</body>
</html>
<?php
}

/** Shared 404 renderer (used by every detail page and the catch-all). */
function render_404(): void {
    http_response_code(404);
    log_404();
    page_start(['title' => 'পেজটি পাওয়া যায়নি', 'robots' => 'noindex, nofollow']);
    page_hero('৪০৪', 'পেজটি খুঁজে পাওয়া যায়নি', 'আপনি যে লিংকটি খুঁজছেন সেটি সরানো হয়েছে বা ঠিকানাটি ভুল।');
    echo '<section class="section"><div class="wrap center"><p><a class="btn btn-primary" href="/">হোমে ফিরুন</a> <a class="btn btn-outline" href="/blog">ব্লগ দেখুন</a> <a class="btn btn-outline" href="/appointment">অ্যাপয়েন্টমেন্ট</a></p></div></section>';
    page_end();
    exit;
}
