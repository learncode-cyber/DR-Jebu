<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/rss+xml; charset=utf-8');
$x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');
$posts = [];
try {
    $posts = q_all("SELECT slug, title, excerpt, published_at FROM blog_posts WHERE status='published' AND published_at <= ? ORDER BY published_at DESC LIMIT 30", [date('Y-m-d H:i:s')]);
} catch (Throwable $e) {
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"><channel>
<title><?= $x(setting('doctor_name_bn') . ' — ব্লগ') ?></title>
<link><?= $x(abs_url('/blog')) ?></link>
<description><?= $x('স্বাস্থ্য সচেতনতার লেখা') ?></description>
<language>bn</language>
<?php foreach ($posts as $p): ?>
<item><title><?= $x($p['title']) ?></title><link><?= $x(abs_url('/blog/' . $p['slug'])) ?></link><guid><?= $x(abs_url('/blog/' . $p['slug'])) ?></guid><pubDate><?= date('r', (int) strtotime((string) $p['published_at'])) ?></pubDate><description><?= $x((string) $p['excerpt']) ?></description></item>
<?php endforeach; ?>
</channel></rss>
