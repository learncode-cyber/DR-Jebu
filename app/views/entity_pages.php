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
        return q_all("SELECT slug, title, excerpt, cover_image FROM blog_posts WHERE status = 'published' AND published_at <= ? AND (" . implode(' OR ', $where) . ') ORDER BY published_at DESC LIMIT ' . (int) $limit, $params);
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
      <div class="card-img"><img src="<?= h(img_url($r['cover_image'])) ?>" alt="<?= h($r['title']) ?>" loading="lazy"></div>
<?php else: ?>
      <div class="ico" aria-hidden="true"><?= $key === 'specialties' ? specialty_icon_svg((string) $r['slug']) : h($e['icon']) ?></div>
<?php endif; ?>
<?php if (!empty($r['kicker'])): ?><span class="kicker"><?= h($r['kicker']) ?></span><?php endif; ?>
      <h3><?= h($r['title']) ?></h3>
      <?php if (!empty($r['short_desc'])): ?><p><?= h(trim_text($r['short_desc'], 160)) ?></p><?php else: ?><p>বিস্তারিত জানতে ও পরামর্শ নিতে দেখুন।</p><?php endif; ?>
      <span class="more">বিস্তারিত দেখুন →</span>
    </a>
<?php
}

function entity_list_page(string $key, string $kicker, string $heroTitle, string $lead): void {
    $e = entity($key);
    $rows = entity_rows($key);
    page_start([
        'title' => $heroTitle, 'description' => $lead, 'path' => $e['public'],
        'breadcrumb' => [[$heroTitle, null]],
        'schema' => [[
            '@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $heroTitle, 'url' => abs_url($e['public']),
            'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(static fn($r, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => abs_url(entity_url($key, $r['slug'])), 'name' => $r['title']], $rows, array_keys($rows))],
        ]],
    ]);
    page_hero($kicker, $heroTitle, $lead, [[$heroTitle, null]]);
    echo '<section class="section"><div class="wrap">';
    if (!$rows) {
        echo '<div class="empty"><p>এই বিভাগে এখনও কিছু যুক্ত করা হয়নি। অনুগ্রহ করে একটু পরে দেখুন অথবা সরাসরি যোগাযোগ করুন।</p><a class="btn btn-primary" href="/appointment">অ্যাপয়েন্টমেন্ট নিন</a></div>';
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

function entity_detail_page(string $key): void {
    $e = entity($key);
    $slug = (string) ($_GET['slug'] ?? '');
    $r = preg_match('/^[a-z0-9-]+$/', $slug) ? q_one('SELECT * FROM ' . $e['table'] . ' WHERE slug = ? AND is_active = 1', [$slug]) : null;
    if (!$r) {
        render_404();
    }
    $path = entity_url($key, $r['slug']);
    $title = $r['meta_title'] !== '' ? $r['meta_title'] : $r['title'];
    $desc = $r['meta_description'] !== '' ? $r['meta_description'] : ($r['short_desc'] !== '' ? $r['short_desc'] : trim_text((string) ($r['overview_body'] ?? ''), 160));
    if ($desc === '') {
        $desc = $r['title'] . ' — ' . setting('doctor_title_bn') . ' ' . setting('doctor_name_bn') . ', ' . setting('workplace');
    }
    $typeMap = ['conditions' => 'MedicalCondition', 'treatments' => 'MedicalTherapy', 'procedures' => 'MedicalProcedure'];
    $ld = ['@context' => 'https://schema.org', '@type' => $typeMap[$key] ?? 'Service', 'name' => $r['title'], 'url' => abs_url($path), 'description' => $desc];
    if (!isset($typeMap[$key])) {
        $ld['provider'] = ['@type' => 'Physician', 'name' => setting('doctor_name_bn')];
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
        $sp = q_one('SELECT slug, title FROM specialties WHERE id = ? AND is_active = 1', [$r['specialty_id']]);
        if ($sp) {
            array_unshift($relBlocks, ['মূল বিশেষত্ব', 'specialties', [$sp]]);
        }
    }
    if ($key === 'specialties') {
        $kids = q_all('SELECT slug, title FROM services WHERE specialty_id = ? AND is_active = 1 ORDER BY sort_order, id', [$r['id']]);
        if ($kids) {
            $relBlocks[] = ['এই বিশেষত্বের সেবাসমূহ', 'services', $kids];
        }
    }
    $posts = related_posts($r['title']);
    $others = q_all('SELECT slug, title FROM ' . $e['table'] . ' WHERE is_active = 1 AND id <> ? ORDER BY sort_order, id LIMIT 8', [$r['id']]);

    page_start([
        'title' => $title, 'description' => $desc, 'path' => $path, 'image' => $r['cover_image'] ?? '',
        'breadcrumb' => [[$e['label'], $e['public']], [$r['title'], null]], 'schema' => [$ld],
    ]);
    page_hero($r['kicker'] !== '' ? $r['kicker'] : $e['singular'], $r['title'], (string) $r['short_desc'], [[$e['label'], $e['public']], [$r['title'], null]]);
    ?>
<section class="section">
  <div class="wrap layout-article">
    <article class="prose">
<?php if (!empty($r['cover_image']) && img_url($r['cover_image']) !== '/assets/img/placeholder.svg'): ?>
      <div class="cover"><img src="<?= h(img_url($r['cover_image'])) ?>" alt="<?= h($r['title']) ?>"></div>
<?php endif; ?>
<?php
    $any = false;
    $sections = [['overview_body', '']];
    if ($key === 'conditions') {
        $sections = [['overview_body', ''], ['symptoms_body', 'লক্ষণ'], ['causes_body', 'কারণ'], ['diagnosis_body', 'রোগ নির্ণয়']];
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
        <p><strong><?= h($r['title']) ?></strong> সম্পর্কে বিস্তারিত তথ্য শীঘ্রই যুক্ত করা হবে। আপনার নির্দিষ্ট অবস্থার জন্য সঠিক পরামর্শ পেতে সরাসরি চিকিৎসকের সঙ্গে যোগাযোগ করুন।</p>
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
      <h2>সম্পর্কিত লেখা</h2>
      <div class="grid c2">
<?php foreach ($posts as $p): ?>
        <a class="card" href="/blog/<?= h($p['slug']) ?>"><h3><?= h($p['title']) ?></h3><p><?= h(trim_text((string) $p['excerpt'], 120)) ?></p><span class="more">পড়ুন →</span></a>
<?php endforeach; ?>
      </div>
<?php endif; ?>
      <p class="note" style="margin-top:2rem"><?= h(setting('footer_disclaimer')) ?></p>
    </article>
    <aside class="aside-box">
      <div class="box">
        <h3>অ্যাপয়েন্টমেন্ট</h3>
        <p><?= h(setting('doctor_title_bn') . ' ' . setting('doctor_name_bn')) ?>-এর সঙ্গে পরামর্শের জন্য সিরিয়াল নিন।</p>
        <p><a class="btn btn-primary btn-block" href="/appointment">সিরিয়াল নিন</a></p>
        <p><a class="btn btn-outline btn-block" href="<?= h(tel_link()) ?>" data-track="phone_click">কল: <?= h(setting('phone_display')) ?></a></p>
      </div>
<?php if ($others): ?>
      <div class="box">
        <h3>আরও দেখুন</h3>
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
