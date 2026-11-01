<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('content');

$res = seo_content_issues();
$filterKey = (string) ($_GET['e'] ?? '');
$onlyWarn = (string) ($_GET['lv'] ?? '') === 'warn';

// per-type counts for the filter chips
$byType = [];
foreach ($res['items'] as $it) {
    $byType[$it['key']] = ($byType[$it['key']] ?? 0) + 1;
}
$items = array_values(array_filter($res['items'], static function (array $it) use ($filterKey, $onlyWarn): bool {
    if ($filterKey !== '' && $it['key'] !== $filterKey) { return false; }
    if ($onlyWarn && $it['worst'] !== 'warn') { return false; }
    return true;
}));
$linkFor = static fn(array $q): string => a_url('seo-content', $q);

admin_head('কনটেন্ট SEO চেকার');
?>
<div class="cards">
  <div class="stat"><b><?= (int) $res['checked'] ?></b><span>প্রকাশিত আইটেম পরীক্ষা হয়েছে</span></div>
  <a class="stat <?= $res['warn_items'] ? 'warn' : '' ?>" href="<?= h($linkFor(['lv' => 'warn'])) ?>"><b><?= (int) $res['warn_items'] ?></b><span>সতর্কতা (ঠিক করা উচিত)</span></a>
  <div class="stat"><b><?= (int) $res['tip_items'] ?></b><span>পরামর্শ (থাকলে ভালো)</span></div>
</div>
<div class="panel">
  <p class="muted">এই পরীক্ষা শুধু সতর্ক করে, সেভ আটকায় না। SEO title ৭০ অক্ষর, SEO description ৫০–১৭০ অক্ষর, লেখা অন্তত ৩০০ শব্দ, প্রতিটি ছবিতে alt-টেক্সট এবং একই title/description একাধিক পেজে না থাকা — এগুলো দেখা হয়।</p>
  <p class="chips-f">
    <a class="chip <?= $filterKey === '' && !$onlyWarn ? 'on' : '' ?>" href="<?= h($linkFor([])) ?>">সব (<?= count($res['items']) ?>)</a>
    <?php foreach (entities() as $k => $e): if (empty($byType[$k])) { continue; } ?>
    <a class="chip <?= $filterKey === $k ? 'on' : '' ?>" href="<?= h($linkFor(['e' => $k])) ?>"><?= h($e['label']) ?> (<?= (int) $byType[$k] ?>)</a>
    <?php endforeach; ?>
    <a class="chip <?= $onlyWarn ? 'on' : '' ?>" href="<?= h($linkFor(['lv' => 'warn'] + ($filterKey !== '' ? ['e' => $filterKey] : []))) ?>">শুধু সতর্কতা</a>
  </p>
</div>
<div class="panel">
<?php if (!$items): ?>
  <p class="muted"><?= $res['checked'] ? 'এই ফিল্টারে কোনো সমস্যা নেই। ভালো কাজ!' : 'এখনও কোনো প্রকাশিত আইটেম নেই।' ?></p>
<?php else: ?>
  <div class="tbl-wrap"><table>
    <thead><tr><th>ধরন</th><th>শিরোনাম</th><th>সমস্যা / পরামর্শ</th><th></th></tr></thead>
    <tbody>
<?php foreach ($items as $it): ?>
      <tr>
        <td><?= h($it['label']) ?></td>
        <td><?= h($it['title']) ?><?php if ($it['url']): ?><br><a href="<?= h($it['url']) ?>" target="_blank" rel="noopener" class="muted"><small><?= h($it['url']) ?></small></a><?php endif; ?></td>
        <td><ul class="issues">
<?php foreach ($it['issues'] as [$lv, $txt]): ?>
          <li class="iss-<?= h($lv) ?>"><?= $lv === 'warn' ? '⚠' : '💡' ?> <?= h($txt) ?></li>
<?php endforeach; ?>
        </ul></td>
        <td><a class="btn ghost" href="<?= h(a_url('entity', ['e' => $it['key'], 'a' => 'edit', 'id' => $it['id']])) ?>">সম্পাদনা</a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php admin_foot();
