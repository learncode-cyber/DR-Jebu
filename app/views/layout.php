<?php
declare(strict_types=1);

function current_path(): string {
    return lang_base_path();
}

function nav_items(): array {
    return [
        ['/', 'হোম', null],
        ['/about', 'পরিচিতি', null],
        ['/specialties', 'বিশেষত্ব', null],
        ['/services', 'সেবা', null],
        [null, 'রোগ ও চিকিৎসা', [['/conditions', 'রোগসমূহ', 'স্ত্রী রোগের সাধারণ সমস্যা ও লক্ষণ'], ['/treatments', 'চিকিৎসা', 'ওষুধ ও পরিকল্পিত চিকিৎসা পদ্ধতি'], ['/procedures', 'প্রসিডিওর', 'ল্যাপারোস্কোপি ও অস্ত্রোপচার']]],
        ['/blog', 'ব্লগ', null],
        [null, 'আরও', array_merge([['/testimonials', 'রোগীর অভিজ্ঞতা', 'রোগীদের মতামত ও অভিজ্ঞতা'], ['/faqs', 'সচরাচর প্রশ্ন', 'সাধারণ প্রশ্নের উত্তর']], gallery_has_items() ? [['/gallery', 'গ্যালারি', 'চেম্বার ও কার্যক্রমের ছবি']] : [], [['/videos', 'ভিডিও', 'স্বাস্থ্য বিষয়ক ভিডিও'], ['/contact', 'যোগাযোগ', 'ফোন, ঠিকানা ও চেম্বার']], array_map(static fn($p) => ['/page/' . $p['slug'], $p['title'], (string) $p['short_desc']], menu_pages()))],
    ];
}

/** Published static pages that the owner chose to show in the menu (inside the 'আরও' menu). */
/** The gallery page shows 404 when it has no items, so menu and sitemap only link to it when it has some. */
function gallery_has_items(): bool {
    try {
        return (int) q_val('SELECT COUNT(*) FROM gallery_items WHERE is_active = 1') > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function menu_pages(): array {
    try {
        return q_all('SELECT slug, title, short_desc FROM pages WHERE is_active = 1 AND show_in_menu = 1 ORDER BY menu_order ASC, id ASC');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Thumbnail for a mega-menu card: assets/img/mega/<slug>.jpg, where slug is the item path without the slash
 * (/conditions -> conditions). Returns '' when no file exists, so the menu keeps its current look until photos are added.
 */
function mega_thumb(string $href, string $label): string {
    $slug = strtolower(trim($href, '/'));
    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) { return ''; }
    $rel = 'assets/img/mega/' . $slug . '.jpg';
    if (!is_file(WEB_ROOT . '/' . $rel)) { return ''; }
    return '<span class="mg-thumb"><img src="' . h(asset($rel)) . '" alt="" width="320" height="180" loading="lazy" decoding="async"></span>';
}

/** One-line description shown in the mega panel for each menu group. */
function mega_blurb(string $label): string {
    return match ($label) {
        'রোগ ও চিকিৎসা' => 'স্ত্রীরোগের সাধারণ সমস্যা, চিকিৎসা পদ্ধতি ও অস্ত্রোপচার, এক জায়গায়।',
        'আরও' => 'রোগীর মতামত, সচরাচর প্রশ্ন, ভিডিও, ছবি ও যোগাযোগের তথ্য।',
        default => 'বিস্তারিত তথ্য ও সেবা।',
    };
}

function nav_active(string $href): bool {
    $c = current_path();
    return $href === '/' ? $c === '/' : ($c === $href || str_starts_with($c, $href . '/'));
}

/**
 * $m keys: title, description, path, image, robots, schema[], breadcrumb[[label,url]], home(bool), gsap(bool), body_class
 */
function page_start(array $m): void {
    $name = tr(setting('doctor_name_bn'), setting('doctor_name_en'));
    $title = ui((string) ($m['title'] ?? $name));
    $fullTitle = isset($m['full_title']) ? (string) $m['full_title'] : ($title === $name ? $name . ' | ' . tr(setting('specialty_line_bn'), setting('specialty_line_en')) : (str_contains($title, $name) ? trim_words($title, 60) : trim_words($title, 37) . ' | ' . $name)); // keeps the whole <title> under 60 characters; a title that already carries the doctor's name is not given it twice
    $desc = ui((string) ($m['description'] ?? (setting('specialty_line_bn') . ' | ' . setting('workplace'))));
    $path = (string) ($m['path'] ?? current_path());
    // One URL per page for both languages; the canonical is that URL
    $canon = abs_url(lang_path($path));
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
<html lang="<?= site_lang() === 'en' ? 'en' : 'bn' ?>" class="latin-<?= setting('latin_font') === 'system' ? 'system' : 'redaction' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($fullTitle) ?></title>
<meta name="description" content="<?= h(trim_text($desc, 155)) ?>">
<meta name="robots" content="<?= h($robots) ?>">
<link rel="canonical" href="<?= h($canon) ?>">
<meta name="theme-color" content="#801B4B">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="ডাঃ জেবু">
<link rel="apple-touch-icon" href="/assets/img/icons/apple-touch-icon.png">
<?php if (preg_match('/^[A-Za-z0-9_-]{8,}$/', setting('gsc_verification'))): ?><meta name="google-site-verification" content="<?= h(setting('gsc_verification')) ?>">
<?php endif; ?>
<?php if (preg_match('/^[A-Za-z0-9_-]{8,}$/', setting('bing_verification'))): ?><meta name="msvalidate.01" content="<?= h(setting('bing_verification')) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= h((string) ($m['og_type'] ?? 'website')) ?>">
<meta property="og:locale" content="<?= site_lang() === 'en' ? 'en_US' : 'bn_BD' ?>">
<meta property="og:locale:alternate" content="<?= site_lang() === 'en' ? 'bn_BD' : 'en_US' ?>">
<meta property="og:site_name" content="<?= h($name) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h(trim_text($desc, 155)) ?>">
<meta property="og:url" content="<?= h($canon) ?>">
<meta property="og:image" content="<?= h($img) ?>">
<?php $__og = og_image_meta($img, $title); ?>
<?php if ($__og['w'] > 0): ?><meta property="og:image:width" content="<?= (int) $__og['w'] ?>">
<meta property="og:image:height" content="<?= (int) $__og['h'] ?>"><?php endif; ?>
<meta property="og:image:alt" content="<?= h($__og['alt']) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="alternate" type="application/rss+xml" title="<?= h($name) ?> | ব্লগ" href="/feed.xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tiro+Bangla:ital@0;1&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(asset('assets/css/site.css')) ?>">
<script>document.documentElement.className+=' js';</script>
<?php foreach ($schema as $s) { echo json_ld($s), "\n"; } ?>
<?= tracking_head_html() ?>
</head>
<body class="<?= $home ? 'is-home' : 'is-inner' ?> <?= h((string) ($m['body_class'] ?? '')) ?>">
<?= tracking_body_html() ?>
<a class="skip" href="#main"><?= h(ui('মূল কনটেন্টে যান')) ?></a>
<header class="site-header" id="siteHeader">
  <div class="wrap header-row">
    <a class="brand" href="/" aria-label="<?= h($name) ?> | হোম">
      <img class="brand-mark" src="/assets/img/doctor-portrait.jpg" alt="" width="46" height="46" decoding="async">
      <span class="brand-text"><small><?= h(tr(setting('doctor_title_bn'), setting('doctor_title_en'))) ?></small><strong><?= h($name) ?></strong></span>
    </a>
    <?php
    // One switch button: on Bangla pages it shows the UK flag and goes to EN; on English pages it shows the Bangladesh flag and goes to বাংলা
    $__other = site_lang() === 'en' ? 'bn' : 'en';
    $__uk = '<svg class="lang-flag" viewBox="0 0 60 30" aria-hidden="true" focusable="false"><clipPath id="lfS"><path d="M0,0v30h60v-30z"/></clipPath><clipPath id="lfT"><path d="M30,15h30v15zv15h-30zh-30v-15zv-15h30z"/></clipPath><g clip-path="url(#lfS)"><path d="M0,0v30h60v-30z" fill="#012169"/><path d="M0,0L60,30M60,0L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0L60,30M60,0L0,30" clip-path="url(#lfT)" stroke="#C8102E" stroke-width="4"/><path d="M30,0v30M0,15h60" stroke="#fff" stroke-width="10"/><path d="M30,0v30M0,15h60" stroke="#C8102E" stroke-width="6"/></g></svg>';
    $__bd = '<svg class="lang-flag" viewBox="0 0 20 12" aria-hidden="true" focusable="false"><rect width="20" height="12" fill="#006A4E"/><circle cx="8.2" cy="6" r="3.6" fill="#F42A41"/></svg>';
    ?>
    <nav class="lang-switch" aria-label="Language / ভাষা"><a href="<?= h(lang_url($__other)) ?>" lang="<?= $__other ?>" hreflang="<?= $__other ?>" title="<?= $__other === 'en' ? 'Switch to English' : 'বাংলায় দেখুন' ?>"><?= $__other === 'en' ? $__uk . 'EN' : $__bd . 'বাংলা' ?></a></nav>
    <button class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="siteNav" aria-label="<?= h(ui('মেনু খুলুন')) ?>"><span></span><span></span><span></span></button>
    <nav class="site-nav" id="siteNav" aria-label="<?= h(ui('প্রধান মেনু')) ?>">
      <ul>
<?php foreach (nav_items() as [$href, $label, $sub]): ?>
<?php if ($sub === null): ?>
        <li><a href="<?= h($href) ?>" class="<?= nav_active($href) ? 'active' : '' ?>"><?= h(ui($label)) ?></a></li>
<?php else:
    $open = false;
    foreach ($sub as $s) { if (nav_active($s[0])) { $open = true; } } ?>
        <li class="has-sub <?= $open ? 'active' : '' ?>">
          <button type="button" class="sub-btn" aria-expanded="false"><?= h(ui($label)) ?> <span aria-hidden="true">▾</span></button>
          <div class="sub mega" role="menu">
            <div class="mega-inner">
              <aside class="mega-aside">
                <span class="mega-kicker"><?= h(ui('মেনু')) ?></span>
                <strong class="mega-title"><?= h(ui($label)) ?></strong>
                <p class="mega-blurb"><?= h(ui(mega_blurb($label))) ?></p>
                <a class="btn btn-primary btn-sm" href="/appointment"><?= h(ui('অ্যাপয়েন্টমেন্ট নিন')) ?></a>
              </aside>
              <ul class="mega-grid">
<?php foreach ($sub as [$sh, $sl, $sd]): ?>
                <li><a href="<?= h($sh) ?>" class="<?= nav_active($sh) ? 'active' : '' ?>"><?= mega_thumb($sh, $sl) ?><span class="mg-title"><?= h(ui($sl)) ?></span><?php if (!empty($sd)): ?><span class="mg-desc"><?= h(ui($sd)) ?></span><?php endif; ?></a></li>
<?php endforeach; ?>
              </ul>
            </div>
          </div>
        </li>
<?php endif; ?>
<?php endforeach; ?>
        <li class="nav-cta"><a class="btn btn-primary btn-sm" href="/appointment"><?= h(ui('অ্যাপয়েন্টমেন্ট নিন')) ?></a></li>
      </ul>
    </nav>
  </div>
</header>
<main id="main">
<?php
}

function page_hero(string $kicker, string $title, string $lead = '', array $crumbs = []): void {
    // Page headings are Bangla keys; ui() gives the English text when the visitor chose EN
    $title = ui($title);
    $lead = ui($lead);
    foreach ($crumbs as $ci => $c) { $crumbs[$ci][0] = ui((string) $c[0]); }
    ?>
<section class="page-hero">
  <div class="page-hero-bg" aria-hidden="true"></div>
  <div class="wrap">
<?php if ($crumbs): ?>
    <nav class="crumbs" aria-label="breadcrumb"><a href="/"><?= h(ui('হোম')) ?></a><?php foreach ($crumbs as $c): ?> <span aria-hidden="true">/</span> <?php if (!empty($c[1])): ?><a href="<?= h($c[1]) ?>"><?= h($c[0]) ?></a><?php else: ?><span><?= h($c[0]) ?></span><?php endif; ?><?php endforeach; ?></nav>
<?php endif; ?>
<?php if ($kicker !== ''): ?><p class="kicker"><?= h(ui($kicker)) ?></p><?php endif; ?>
    <h1 class="display"><?= h($title) ?></h1>
<?php if ($lead !== ''): ?><p class="lead"><?= h($lead) ?></p><?php endif; ?>
  </div>
</section>
<?php
}

function cta_band(string $heading = 'আপনার জন্য সঠিক পরামর্শ নিন', string $text = 'নাম ও মোবাইল নম্বর দিন, আমাদের টিম যোগাযোগ করে সিরিয়াল নিশ্চিত করবে।'): void {
    ?>
<section class="cta-band reveal">
  <div class="wrap cta-inner glass">
    <div>
      <h2 class="display"><?= h(ui($heading)) ?></h2>
      <p><?= h(ui($text)) ?></p>
    </div>
    <div class="cta-actions">
      <a class="btn btn-light" href="/appointment"><?= h(ui('অ্যাপয়েন্টমেন্ট নিন')) ?></a>
      <a class="btn btn-ghost" href="<?= h(tel_link()) ?>"><?= h(ui('কল')) ?>: <?= h(setting('phone_display')) ?></a>
    </div>
  </div>
</section>
<?php
}

function page_end(array $m = []): void {
    $services = [];
    try {
        $services = localize_rows(q_all('SELECT slug, title, title_en FROM services WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 6'));
    } catch (Throwable $e) {
    }
    $social = array_filter(['Facebook' => setting('facebook_url'), 'YouTube' => setting('youtube_url'), 'Instagram' => setting('instagram_url')], static fn($u) => preg_match('#^https?://#i', $u) === 1);
    $addr = trim(setting('chamber_address'));
    ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div class="f-brand">
      <p class="f-name display"><?= h(tr(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn'), setting('doctor_title_en') . ' ' . setting('doctor_name_en'))) ?></p>
      <p class="f-sub"><?= h(setting('doctor_name_en')) ?> · <?= h(setting('degrees_line')) ?></p>
      <p class="f-sub"><?= h(s_tr('workplace')) ?></p>
<?php if ($social): ?>
      <p class="f-social"><?php foreach ($social as $label => $u): ?><a href="<?= h($u) ?>" rel="noopener" target="_blank"><?= h($label) ?></a> <?php endforeach; ?></p>
<?php endif; ?>
    </div>
    <div>
      <h3><?= h(ui('দ্রুত লিংক')) ?></h3>
      <ul>
        <li><a href="/about"><?= h(ui('পরিচিতি')) ?></a></li><li><a href="/specialties"><?= h(ui('বিশেষত্ব')) ?></a></li><li><a href="/conditions"><?= h(ui('রোগসমূহ')) ?></a></li>
        <li><a href="/treatments"><?= h(ui('চিকিৎসা')) ?></a></li><li><a href="/procedures"><?= h(ui('প্রসিডিওর')) ?></a></li><li><a href="/blog"><?= h(ui('ব্লগ')) ?></a></li>
      </ul>
    </div>
    <div>
      <h3><?= h(ui('সেবা')) ?></h3>
      <ul>
<?php foreach ($services as $s): ?>
        <li><a href="/services/<?= h($s['slug']) ?>"><?= h($s['title']) ?></a></li>
<?php endforeach; ?>
        <li><a href="/services"><?= h(ui('সব সেবা দেখুন →')) ?></a></li>
      </ul>
    </div>
    <div>
      <h3><?= h(ui('যোগাযোগ')) ?></h3>
      <ul>
        <li><a href="<?= h(tel_link()) ?>"><?= h(ui('ফোন')) ?>: <?= h(setting('phone_display')) ?></a></li>
        <li><a href="<?= h(wa_link(setting('wa_message'))) ?>" rel="noopener" target="_blank"><?= h(ui('হোয়াটসঅ্যাপ')) ?>: <?= h(setting('whatsapp_display')) ?></a></li>
<?php if (setting('email') !== ''): ?><li><a href="mailto:<?= h(setting('email')) ?>"><?= h(setting('email')) ?></a></li><?php endif; ?>
<?php if (chamber_rows()): ?>
<?php foreach (chamber_rows() as $c): ?><li class="plain"><b><?= h((string) $c['name']) ?></b><?= $c['visiting_hours'] !== '' ? ' · ' . h((string) $c['visiting_hours']) : '' ?></li><?php endforeach; ?>
<?php else: ?>
<?php if ($addr !== ''): ?><li class="plain"><?= nl2br(h($addr), false) ?></li><?php endif; ?>
        <li class="plain"><?= h(s_tr('chamber_hours')) ?></li>
<?php endif; ?>
        <li><a href="/appointment"><?= h(ui('অ্যাপয়েন্টমেন্ট ফর্ম')) ?></a></li><li><a href="/contact"><?= h(ui('যোগাযোগ পেজ')) ?></a></li>
      </ul>
    </div>
  </div>
  <div class="wrap footer-base">
    <p class="disclaimer"><?= h(s_tr('footer_disclaimer')) ?></p>
    <p class="legal">
      <?= h(tr('এটি ' . setting('doctor_name_bn') . '-এর অফিশিয়াল ওয়েবসাইট। চেম্বারের সময়, ফোন ও ঠিকানার সর্বশেষ তথ্য এই সাইটেই পাওয়া যাবে।', 'This is the official website of ' . setting('doctor_name_en') . '. The latest chamber hours, phone and address are always published on this site.')) ?> · © <?= date('Y') ?> <?= h(tr(setting('doctor_name_bn'), setting('doctor_name_en'))) ?> ·
      <a href="/privacy-policy"><?= h(ui('গোপনীয়তা নীতি')) ?></a> · <a href="/terms"><?= h(ui('শর্তাবলি')) ?></a> · <a href="/feed.xml">RSS</a>
    </p>
    <p class="agency-credit">Designed &amp; Developed by <a href="https://arvanceagency.com" rel="noopener" target="_blank">AR Vance Agency</a> <span class="ac-sep" aria-hidden="true">·</span> A Digital &amp; Technology Company by <a href="https://arqudrix.com" rel="noopener" target="_blank">AR Qudrix</a></p>
  </div>
</footer>
<div class="sticky-actions" id="stickyActions" aria-label="<?= h(ui('দ্রুত যোগাযোগ')) ?>">
  <button type="button" class="sa-ai" id="aiOpen" aria-haspopup="dialog" aria-controls="aiPop" aria-label="<?= h(ui('এআই সহকারীর সাথে কথা বলুন')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg><span><?= h(ui('এআই সহকারী')) ?></span></button>
  <a class="sa-appt" href="/appointment" aria-label="অ্যাপয়েন্টমেন্ট নিন" data-track="appointment_click"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4M12 14v4M10 16h4"/></svg><span><?= h(ui('অ্যাপয়েন্টমেন্ট')) ?></span></a>
  <a class="sa-wa" href="<?= h(wa_link(setting('wa_message'))) ?>" rel="noopener" target="_blank" aria-label="<?= h(ui('হোয়াটসঅ্যাপে মেসেজ')) ?>" data-track="whatsapp_click"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l1.3-4A8 8 0 1 1 8 18.7z"/><path d="M9 10c0 2.5 2.5 5 5 5l1-1.2-2-1-.8.8c-1-.4-2-1.4-2.4-2.4l.8-.8-1-2z"/></svg><span><?= h(ui('হোয়াটসঅ্যাপ')) ?></span></a>
</div>
<div class="ai-pop" id="aiPop" role="dialog" aria-modal="false" aria-labelledby="aiTitle" data-lang="<?= site_lang() === 'en' ? 'en-US' : 'bn-BD' ?>" data-greeting="<?= h(tr('আসসালামু আলাইকুম! আমি ডাঃ জেবুন্নেসা জেবুর এআই সহকারী। আমি অ্যাপয়েন্টমেন্ট নিতে, চেম্বারের তথ্য জানাতে বা সাধারণ প্রশ্নের উত্তর দিতে পারি। আপনার কী দরকার?', 'Assalamu alaikum! I am the AI assistant of Dr. Jebunnesa Jebu. I can help you book an appointment, share chamber information or answer general questions. How can I help you?')) ?>" data-booked="<?= h(tr('✔ আপনার অ্যাপয়েন্টমেন্ট অনুরোধ নম্বর #{id} নেওয়া হয়েছে। চেম্বার থেকে ফোনে সময় নিশ্চিত করা হবে।', '✔ Your appointment request number #{id} has been received. The chamber will confirm the time by phone.')) ?>" data-err="<?= h(tr('সমস্যা হয়েছে। অনুগ্রহ করে অ্যাপয়েন্টমেন্ট বা হোয়াটসঅ্যাপ ব্যবহার করুন।', 'Something went wrong. Please use the appointment form or WhatsApp.')) ?>" data-net="<?= h(tr('ইন্টারনেট সংযোগে সমস্যা। অনুগ্রহ করে আবার চেষ্টা করুন।', 'Connection problem. Please try again.')) ?>" hidden>
  <div class="ai-head">
    <div><strong id="aiTitle"><?= h(ui('এআই সহকারী')) ?></strong><span><?= h(ui('অ্যাপয়েন্টমেন্ট ও তথ্যের জন্য')) ?></span></div>
    <div class="ai-head-btns">
      <button type="button" id="aiSpeak" aria-pressed="false" title="<?= h(ui('উত্তর শুনুন')) ?>" hidden>🔈</button>
      <button type="button" id="aiClose" aria-label="<?= h(ui('বন্ধ করুন')) ?>">✕</button>
    </div>
  </div>
  <div class="ai-log" id="aiLog" aria-live="polite"></div>
  <div class="ai-chips">
    <button type="button" data-q="<?= h(ui('অ্যাপয়েন্টমেন্ট নিতে চাই')) ?>"><?= h(ui('অ্যাপয়েন্টমেন্ট নিতে চাই')) ?></button>
    <button type="button" data-q="<?= h(ui('চেম্বারের সময় কখন?')) ?>"><?= h(ui('চেম্বারের সময়')) ?></button>
    <button type="button" data-q="<?= h(ui('কোন সমস্যায় পরামর্শ নেওয়া যায়?')) ?>"><?= h(ui('কোন সমস্যায় পরামর্শ?')) ?></button>
  </div>
  <form class="ai-form" id="aiForm" autocomplete="off">
    <button type="button" id="aiMic" aria-label="<?= h(ui('কথা বলে লিখুন')) ?>" title="<?= h(ui('কথা বলে লিখুন')) ?>" hidden>🎤</button>
    <input type="text" id="aiInput" maxlength="1000" placeholder="<?= h(ui('এখানে লিখুন…')) ?>" aria-label="<?= h(ui('বার্তা লিখুন')) ?>">
    <button type="submit" id="aiSend"><?= h(ui('পাঠান')) ?></button>
  </form>
  <p class="ai-note"><?= h(ui('এআই সহায়ক রোগ নির্ণয় বা ওষুধের পরামর্শ দেয় না। জরুরি অবস্থায় ৯৯৯ বা নিকটস্থ হাসপাতালে যান।')) ?></p>
</div>
<script src="<?= h(asset('assets/js/ai.js')) ?>" defer></script>
<?php if (!empty($m['gsap'])): ?>
<script src="<?= h(asset('assets/js/motion.min.js')) ?>"></script>
<script src="<?= h(asset('assets/js/scroll.min.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(asset('assets/js/site.js')) ?>"></script>
<?php if (!empty($m['hero'])): ?><script src="<?= h(asset('assets/js/hero.js')) ?>"></script><?php endif; ?>
<script src="<?= h(asset('assets/js/install.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Shared 404 renderer (used by every detail page and the catch-all). */
