<?php
require_once __DIR__ . '/_tracking_ui.php';
// Shared view for Facebook pages that are not built yet. It states the real status and what is still needed; it shows no fake numbers.
function fb_soon_render(string $title, string $status, array $needs): void {
    admin_head($title);
    ?>
<div class="panel">
  <h2><?= h($title) ?></h2>
  <p><?= tr_badge($status, 'bad') ?></p>
  <p class="muted">এই অংশ এখনো বানানো হয়নি। নিচে দেখুন কী কী লাগবে। এখানে কোনো নকল সংখ্যা দেখানো হয় না।</p>
  <ul style="line-height:1.9">
<?php foreach ($needs as $n): ?>
    <li><?= h($n) ?></li>
<?php endforeach; ?>
  </ul>
</div>
    <?php
    admin_foot();
}
