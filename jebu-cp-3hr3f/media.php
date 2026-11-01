<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('content');

/**
 * Where each uploaded file is referenced: filename => [label, ...].
 * Scans every image / text-body column of every content type plus site settings.
 */
function media_usage_map(): array {
    $map = [];
    $scan = static function (string $text, string $label) use (&$map): void {
        if (!str_contains($text, 'uploads/')) {
            return;
        }
        if (preg_match_all('#uploads/([A-Za-z0-9._-]+)#', $text, $m)) {
            foreach (array_unique($m[1]) as $file) {
                $map[$file][] = $label;
            }
        }
    };
    foreach (entities() as $k => $e) {
        $cols = [];
        foreach ($e['fields'] as $f) {
            if (in_array($f['type'], ['image', 'md', 'rich', 'textarea'], true)) {
                $cols[] = $f['name'];
            }
        }
        if (!$cols) {
            continue;
        }
        $where = implode(' OR ', array_map(static fn(string $c): string => $c . " LIKE '%uploads/%'", $cols));
        try {
            $usageRows = q_all('SELECT * FROM ' . $e['table'] . ' WHERE ' . $where);
        } catch (Throwable $ex) {
            error_log('[jebu] media usage ' . $e['table'] . ': ' . $ex->getMessage());
            continue;
        }
        foreach ($usageRows as $r) {
            $label = $e['singular'] . ' #' . $r['id'] . ' — ' . mb_substr((string) ($r[$e['title_col'] ?? 'title'] ?? ''), 0, 60);
            foreach ($cols as $c) {
                $scan((string) ($r[$c] ?? ''), $label);
            }
        }
    }
    try {
        foreach (q_all("SELECT skey, svalue FROM site_settings WHERE svalue LIKE '%uploads/%'") as $r) {
            $scan((string) $r['svalue'], 'সাইট সেটিংস: ' . $r['skey']);
        }
    } catch (Throwable $ex) {
        error_log('[jebu] media usage site_settings: ' . $ex->getMessage());
    }
    foreach ($map as $file => $labels) {
        $map[$file] = array_values(array_unique($labels));
    }
    return $map;
}

// ---------------- POST ----------------
if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'upload') {
        $err = '';
        $new = save_upload('upfile', $err);
        if ($err !== '') {
            flash('err', $err);
        } elseif ($new === null) {
            flash('err', 'কোনো ছবি বাছাই করা হয়নি।');
        } else {
            log_activity('media.uploaded', $new, (int) $u['id']);
            flash('ok', 'ছবি আপলোড হয়েছে। ব্যবহার করতে লিংক কপি করুন: ' . $new);
        }
        redirect(a_url('media'));
    }
    if ($act === 'delete') {
        $name = basename((string) ($_POST['file'] ?? ''));
        $files = upload_files();
        if (!isset($files[$name])) {
            flash('err', 'ফাইলটি পাওয়া যায়নি।');
            redirect(a_url('media'));
        }
        $usage = media_usage_map();
        if (!empty($usage[$name])) {
            flash('err', 'এই ছবিটি ব্যবহার হচ্ছে, তাই মোছা যাবে না। আগে সেখান থেকে ছবিটি সরান।');
            redirect(a_url('media'));
        }
        if (@unlink($files[$name]['path'])) {
            log_activity('media.deleted', $name, (int) $u['id']);
            flash('ok', 'ফাইলটি মুছে ফেলা হয়েছে।');
        } else {
            flash('err', 'ফাইলটি মোছা যায়নি। uploads ফোল্ডারের অনুমতি দেখুন।');
        }
        redirect(a_url('media'));
    }
    redirect(a_url('media'));
}

// ---------------- View ----------------
$files = upload_files();
$usage = media_usage_map();
$filterUsed = (string) ($_GET['used'] ?? 'all');
$filterQ = mb_strtolower(trim((string) ($_GET['q'] ?? '')));

$list = [];
$totalSize = 0;
$unusedCount = 0;
foreach ($files as $name => $f) {
    $totalSize += $f['size'];
    $used = $usage[$name] ?? [];
    if (!$used) { $unusedCount++; }
    if ($filterUsed === 'yes' && !$used) { continue; }
    if ($filterUsed === 'no' && $used) { continue; }
    if ($filterQ !== '' && !str_contains(mb_strtolower($name), $filterQ)) { continue; }
    $list[] = ['name' => $name, 'size' => $f['size'], 'mtime' => $f['mtime'], 'path' => $f['path'], 'used' => $used];
}
usort($list, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$list = array_slice($list, 0, 300);

$base = static fn(array $q): string => a_url('media', $q);

admin_head('মিডিয়া লাইব্রেরি');
?>
<div class="cards">
  <div class="stat"><b><?= count($files) ?></b><span>মোট ছবি</span></div>
  <div class="stat"><b><?= number_format($totalSize / 1048576, 1) ?> MB</b><span>মোট আকার</span></div>
  <a class="stat" href="<?= h($base(['used' => 'no'])) ?>"><b><?= $unusedCount ?></b><span>কোথাও ব্যবহার হয় না</span></a>
</div>

<div class="panel">
  <h2>নতুন ছবি আপলোড</h2>
  <form method="post" enctype="multipart/form-data" class="tools">
    <?= csrf_field() ?><input type="hidden" name="act" value="upload">
    <input type="file" name="upfile" accept="image/jpeg,image/png,image/webp,image/gif" required>
    <button class="btn" type="submit">আপলোড</button>
  </form>
  <p class="muted">JPG, PNG, WEBP বা GIF; সর্বোচ্চ ১০MB। বড় ছবি স্বয়ংক্রিয়ভাবে ১৮০০px-এ ছোট হয়।</p>
</div>

<div class="panel">
  <form method="get" action="<?= h(a_url('media')) ?>" class="tools">
    <input type="search" name="q" value="<?= h($filterQ) ?>" placeholder="ফাইলের নাম খুঁজুন" style="width:240px">
    <select name="used" style="width:auto;margin:0">
      <option value="all" <?= $filterUsed === 'all' ? 'selected' : '' ?>>সব ছবি</option>
      <option value="yes" <?= $filterUsed === 'yes' ? 'selected' : '' ?>>ব্যবহৃত</option>
      <option value="no" <?= $filterUsed === 'no' ? 'selected' : '' ?>>অব্যবহৃত</option>
    </select>
    <button class="btn ghost" type="submit">ফিল্টার</button>
  </form>

<?php if (!$list): ?>
  <p class="muted">এই ফিল্টারে কোনো ছবি নেই।</p>
<?php else: ?>
  <div class="mgrid">
<?php foreach ($list as $f): $url = '/uploads/' . $f['name']; $info = @getimagesize($f['path']); ?>
    <div class="mcard">
      <a href="<?= h($url) ?>" target="_blank" rel="noopener"><img src="<?= h($url) ?>" alt="" loading="lazy" decoding="async"></a>
      <div class="meta"><b><?= h($f['name']) ?></b><br>
        <?= h(number_format($f['size'] / 1024, 0)) ?> KB<?php if ($info): ?> · <?= (int) $info[0] ?>×<?= (int) $info[1] ?><?php endif; ?> · <?= h(date('d M Y', $f['mtime'])) ?></div>
      <input type="text" readonly value="<?= h($url) ?>" onclick="this.select()" aria-label="ছবির লিংক" style="font-size:.8rem;padding:.3rem .5rem">
      <div class="meta">
<?php if ($f['used']): ?>
        <b>ব্যবহৃত:</b> <?= h(implode(' · ', array_slice($f['used'], 0, 3))) ?><?= count($f['used']) > 3 ? ' · +' . (count($f['used']) - 3) : '' ?>
<?php else: ?>
        <span class="tag off">কোথাও ব্যবহার হয় না</span>
        <form method="post" class="inline" onsubmit="return confirm('ফাইলটি স্থায়ীভাবে মুছবেন?')">
          <?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="file" value="<?= h($f['name']) ?>">
          <button class="btn-link danger" type="submit">মুছুন</button>
        </form>
<?php endif; ?>
      </div>
    </div>
<?php endforeach; ?>
  </div>
<?php if (count($list) === 300): ?><p class="muted">প্রথম ৩০০টি দেখানো হচ্ছে। খুঁজে বের করতে নাম ব্যবহার করুন।</p><?php endif; ?>
<?php endif; ?>
</div>
<?php admin_foot();
