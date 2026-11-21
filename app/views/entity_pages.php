<?php
declare(strict_types=1);

function entity_keywords(string $title): array {
    $kw = [];
    if (preg_match('/\(([^)]{2,30})\)/u', $title, $m)) {
        $kw[] = trim($m[1]);
    }
    $clean = trim(preg_replace('/\([^)]*\)/u', '', $title) ?? $title);
    $clean = preg_replace('~[/,।|]+~u', ' ', $clean) ?? $clean;
    $words = array_values(array_filter(preg_split('/\s+/u', $clean) ?: [], static fn($w) => mb_strlen($w) >= 4));
    foreach (array_slice($words, 0, 2) as $w) {
        $kw[] = $w;
    }
    return array_values(array_unique($kw));
}

function related_posts(string $title, int $limit = 3): array {
    $kws = entity_keywords($title);
    if (!$kws) {
        return [];
    }
    $where = [];
    $params = [date('Y-m-d H:i:s')];
    foreach ($kws as $k) {
        $where[] = '(title LIKE ? OR focus_keyword LIKE ?)';
        $params[] = '%' . $k . '%';
        $params[] = '%' . $k . '%';
    }
    try {
        return localize_rows(q_all("SELECT slug, title, excerpt, cover_image, title_en, excerpt_en FROM blog_posts WHERE status = 'published' AND published_at <= ? AND (" . implode(' OR ', $where) . ') ORDER BY published_at DESC LIMIT ' . (int) $limit, $params));
    } catch (Throwable $e) {
        return [];
    }
}

function entity_card(string $key, array $r, int $i = 0): void {
    $e = entity($key);
    $hasImg = trim((string) ($r['cover_image'] ?? '')) !== '' && img_url($r['cover_image']) !== '/assets/img/placeholder.svg';
    ?>
    <a class="card reveal" href="<?= h(entity_url($key, $r['slug'])) ?>" style="transition-delay:<?= min($i, 8) * 40 ?>ms">
<?php if ($hasImg): ?>
      <div class="card-img"><?= img_picture(img_url($r['cover_image']), $r['title'], 0, 0, '(max-width:620px) 100vw, (max-width:980px) 50vw, 33vw') ?></div>
<?php else: ?>
      <div class="ico" aria-hidden="true"><?= $key === 'specialties' ? specialty_icon_svg((string) $r['slug']) : h($e['icon']) ?></div>
<?php endif; ?>
<?php if (!empty($r['kicker'])): ?><span class="kicker"><?= h($r['kicker']) ?></span><?php endif; ?>
      <h3><?= h($r['title']) ?></h3>
      <?php if (!empty($r['short_desc'])): ?><p><?= h(trim_text($r['short_desc'], 160)) ?></p><?php else: ?><p><?= h(ui('বিস্তারিত জানতে ও পরামর্শ নিতে দেখুন।')) ?></p><?php endif; ?>
      <span class="more"><?= h(ui('বিস্তারিত দেখুন →')) ?></span>
    </a>
<?php
}

function entity_list_page(string $key, string $kicker, string $heroTitle, string $lead): void {
    $e = entity($key);
    $rows = entity_rows($key);
    // Search title: a fuller phrase than the visible heading (heading and page look stay the same).
    $seoHeads = [
        'conditions' => 'গাইনী রোগসমূহ ও লক্ষণ', 'treatments' => 'গাইনী ও বন্ধ্যাত্ব চিকিৎসা পদ্ধতি',
        'services' => 'গাইনী ও প্রসূতি সেবাসমূহ', 'specialties' => 'প্রসূতি ও স্ত্রীরোগ বিশেষত্ব',
        'procedures' => 'ল্যাপারোস্কোপি ও গাইনী প্রসিডিওর',
    ];
    $seoHead = $seoHeads[$key] ?? $heroTitle;
    $seoFull = $seoHead . ' | ' . setting('doctor_name_bn');
    page_start([
        'title' => $heroTitle, 'full_title' => $seoFull, 'description' => $lead, 'path' => $e['public'],
        'robots' => $rows ? 'index, follow, max-image-preview:large' : 'noindex, follow',
        'breadcrumb' => [[$heroTitle, null]],
        'schema' => [[
            '@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $heroTitle, 'url' => abs_url($e['public']),
            'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(static fn($r, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => abs_url(entity_url($key, $r['slug'])), 'name' => $r['title']], $rows, array_keys($rows))],
        ]],
    ]);
    page_hero($kicker, $heroTitle, $lead, [[$heroTitle, null]]);
    echo '<section class="section"><div class="wrap">';
    if (!$rows) {
        echo '<div class="empty"><p>' . h(ui('এই বিভাগে এখনও কিছু যুক্ত করা হয়নি। অনুগ্রহ করে একটু পরে দেখুন অথবা সরাসরি যোগাযোগ করুন।')) . '</p><a class="btn btn-primary" href="/appointment">' . h(ui('অ্যাপয়েন্টমেন্ট নিন')) . '</a></div>';
    } else {
        echo '<div class="grid c3">';
        foreach ($rows as $i => $r) {
            entity_card($key, $r, $i);
        }
        echo '</div>';
    }
    echo '</div></section>';
    cta_band();
    page_end();
}

/** A detail page is indexable only when it has real body text in the page's language (EN pages need English text). */
function entity_body_ready(array $raw, string $lang): bool {
    foreach (['overview_body', 'symptoms_body', 'causes_body', 'diagnosis_body'] as $c) {
        $v = $lang === 'en' ? (string) ($raw[$c . '_en'] ?? '') : (string) ($raw[$c] ?? '');
        if (trim($v) !== '') {
            return true;
        }
    }
    return false;
}

function entity_detail_page(string $key): void {
    $e = entity($key);
    $slug = (string) ($_GET['slug'] ?? '');
    $r = preg_match('/^[a-z0-9-]+$/', $slug) ? q_one('SELECT * FROM ' . $e['table'] . ' WHERE slug = ? AND is_active = 1', [$slug]) : null;
    $ready = false;
    if (is_array($r)) { $ready = entity_body_ready($r, site_lang()); $r = localize_row($r); }
    if (!$r) {
        render_404();
    }
    $path = entity_url($key, $r['slug']);
    $title = $r['meta_title'] !== '' ? $r['meta_title'] : $r['title'];
    $desc = $r['meta_description'] !== '' ? $r['meta_description'] : ($r['short_desc'] !== '' ? $r['short_desc'] : trim_text((string) ($r['overview_body'] ?? ''), 160));
    if ($desc === '') {
        $desc = $r['title'] . ' | ' . setting('doctor_title_bn') . ' ' . setting('doctor_name_bn') . ', ' . setting('workplace');
    }
    $typeMap = ['conditions' => 'MedicalCondition', 'treatments' => 'MedicalTherapy', 'procedures' => 'MedicalProcedure'];
    $ld = ['@context' => 'https://schema.org', '@type' => $typeMap[$key] ?? 'Service', 'name' => $r['title'], 'url' => abs_url($path), 'description' => $desc];
    if (!isset($typeMap[$key])) {
        $ld['provider'] = ['@type' => 'Physician', 'name' => doctor_schema_name()];
    }

    // Relations
    $relBlocks = [];
    foreach ($e['relations'] as $rel) {
        $rows = entity_related($rel['junction'], $rel['self'], (int) $r['id'], $rel['other'], $rel['other_table']);
        if ($rows) {
            $otherKey = array_search($rel['other_table'], array_map(static fn($x) => $x['table'], entities()), true);
            $relBlocks[] = [$rel['label'], $otherKey, $rows];
        }
    }
    if ($key === 'services' && !empty($r['specialty_id'])) {
        $sp = localize_row(q_one('SELECT slug, title, title_en FROM specialties WHERE id = ? AND is_active = 1', [$r['specialty_id']]) ?: []);
        if ($sp) {
            array_unshift($relBlocks, [ui('মূল বিশেষত্ব'), 'specialties', [$sp]]);
        }
    }
    if ($key === 'specialties') {
        $kids = localize_rows(q_all('SELECT slug, title, title_en FROM services WHERE specialty_id = ? AND is_active = 1 ORDER BY sort_order, id', [$r['id']]));
        if ($kids) {
            $relBlocks[] = [ui('এই বিশেষত্বের সেবাসমূহ'), 'services', $kids];
        }
    }
    $posts = related_posts($r['title']);
    $others = localize_rows(q_all('SELECT slug, title, title_en FROM ' . $e['table'] . ' WHERE is_active = 1 AND id <> ? ORDER BY sort_order, id LIMIT 8', [$r['id']]));

    page_start([
        'title' => $title, 'description' => $desc, 'path' => $path, 'image' => $r['cover_image'] ?? '',
        'breadcrumb' => [[$e['label'], $e['public']], [$r['title'], null]], 'schema' => [$ld],
        // Thin page (no real text yet): visible and linked, but kept out of the index until the content is added
        'robots' => $ready ? 'index, follow, max-image-preview:large' : 'noindex, follow',
    ]);
    page_hero($r['kicker'] !== '' ? $r['kicker'] : $e['singular'], $r['title'], (string) $r['short_desc'], [[$e['label'], $e['public']], [$r['title'], null]]);
    ?>
<section class="section">
  <div class="wrap layout-article">
    <article class="prose">
<?php if (!empty($r['cover_image']) && img_url($r['cover_image']) !== '/assets/img/placeholder.svg'): ?>
      <div class="cover"><?= img_picture(img_url($r['cover_image']), $r['title'], 0, 0, '100vw', false) ?></div>
<?php endif; ?>
<?php
    $any = false;
    $sections = [['overview_body', '']];
    if ($key === 'conditions') {
        $sections = [['overview_body', ''], ['symptoms_body', ui('লক্ষণ')], ['causes_body', ui('কারণ')], ['diagnosis_body', ui('রোগ নির্ণয়')]];
    }
    foreach ($sections as [$col, $label]) {
        $html = md($r[$col] ?? '');
        if ($html !== '') {
            $any = true;
            echo $label !== '' ? '<h2>' . h($label) . '</h2>' : '';
            echo $html;
        }
    }
    if (!$any): ?>
      <div class="box">
        <p><strong><?= h($r['title']) ?></strong> <?= h(tr('সম্পর্কে বিস্তারিত তথ্য শীঘ্রই যুক্ত করা হবে। আপনার নির্দিষ্ট অবস্থার জন্য সঠিক পরামর্শ পেতে সরাসরি চিকিৎসকের সঙ্গে যোগাযোগ করুন।', 'Detailed information will be added soon. For advice on your own condition, contact the doctor directly.')) ?></p>
        <p><a class="btn btn-primary" href="/appointment">অ্যাপয়েন্টমেন্ট নিন</a></p>
      </div>
<?php endif; ?>
<?php foreach ($relBlocks as [$label, $otherKey, $rows]): ?>
      <h2><?= h($label) ?></h2>
      <ul class="pill-list">
<?php foreach ($rows as $x): ?>
        <li><a href="<?= h(entity_url((string) $otherKey, $x['slug'])) ?>"><?= h($x['title']) ?></a></li>
<?php endforeach; ?>
      </ul>
<?php endforeach; ?>
<?php if ($posts): ?>
      <h2><?= h(ui('সম্পর্কিত লেখা')) ?></h2>
      <div class="grid c2">
<?php foreach ($posts as $p): ?>
        <a class="card" href="/blog/<?= h($p['slug']) ?>"><h3><?= h($p['title']) ?></h3><p><?= h(trim_text((string) $p['excerpt'], 120)) ?></p><span class="more"><?= h(ui('পড়ুন →')) ?></span></a>
<?php endforeach; ?>
      </div>
<?php endif; ?>
      <p class="note" style="margin-top:2rem"><?= h(setting('footer_disclaimer')) ?></p>
    </article>
    <aside class="aside-box">
      <div class="box">
        <h3><?= h(ui('অ্যাপয়েন্টমেন্ট')) ?></h3>
        <p><?= h(tr(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn') . '-এর সঙ্গে পরামর্শের জন্য সিরিয়াল নিন।', 'Book a consultation with ' . setting('doctor_title_en') . ' ' . setting('doctor_name_en') . '.')) ?></p>
        <p><a class="btn btn-primary btn-block" href="/appointment"><?= h(ui('সিরিয়াল নিন')) ?></a></p>
        <p><a class="btn btn-outline btn-block" href="<?= h(tel_link()) ?>" data-track="phone_click"><?= h(ui('কল')) ?>: <?= h(setting('phone_display')) ?></a></p>
      </div>
<?php if ($others): ?>
      <div class="box">
        <h3><?= h(ui('আরও দেখুন')) ?></h3>
        <ul><?php foreach ($others as $o): ?><li><a href="<?= h(entity_url($key, $o['slug'])) ?>"><?= h($o['title']) ?></a></li><?php endforeach; ?></ul>
      </div>
<?php endif; ?>
    </aside>
  </div>
</section>
<?php
    cta_band();
    page_end();
}
