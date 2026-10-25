<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('content');

$key = (string) ($_GET['e'] ?? '');
if (!isset(entities()[$key])) {
    redirect(a_url(''));
}
$e = entity($key);
$T = $e['table'];
$titleCol = $e['title_col'];
$fields = $e['fields'];
$hasActive = empty($e['no_active']);

function resize_uploaded_image(string $src, string $dest, array $info, int $max): bool {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) {
        return false;
    }
    [$w, $h, $type] = $info;
    if ($w <= $max && $h <= $max) {
        return false; // already small: keep the original file untouched
    }
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if (!$img) {
        return false;
    }
    // Re-encoding strips EXIF, so apply phone orientation ourselves
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($src);
        $deg = match ((int) ($ex['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($deg !== 0) {
            $img = imagerotate($img, $deg, 0);
            [$w, $h] = [$h, $w];
        }
    }
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, imagesx($img), imagesy($img));
    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $dest, 88),
        IMAGETYPE_PNG => imagepng($dst, $dest, 6),
        IMAGETYPE_WEBP => imagewebp($dst, $dest, 88),
        default => imagegif($dst, $dest),
    };
    return (bool) $ok;
}

function save_upload(string $inputName, string &$err): ?string {
    if (empty($_FILES[$inputName]) || ($_FILES[$inputName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$inputName];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $err = match ((int) $f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ছবিটি সার্ভারের সীমার চেয়ে বড়। ছবি ছোট করে আবার দিন (সর্বোচ্চ ১০MB)।',
            UPLOAD_ERR_PARTIAL => 'ছবিটি পুরো আপলোড হয়নি। ইন্টারনেট দেখে আবার চেষ্টা করুন।',
            default => 'ছবি আপলোড ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
        };
        return null;
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        $err = 'ছবি ১০MB-এর বেশি। ছবি ছোট করে আবার দিন।';
        return null;
    }
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($map[$info[2]])) {
        $err = 'শুধু JPG, PNG, WEBP বা GIF ছবি দেওয়া যাবে। iPhone-এর HEIC ছবি হলে JPG করে নিন।';
        return null;
    }
    $dir = WEB_ROOT . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $err = 'uploads ফোল্ডারে লেখার অনুমতি নেই। File Manager-এ uploads ফোল্ডারের Permission 755 করুন।';
        return null;
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $map[$info[2]];
    $dest = $dir . '/' . $name;
    $resized = resize_uploaded_image($f['tmp_name'], $dest, $info, 1800);
    if (!$resized && !move_uploaded_file($f['tmp_name'], $dest)) {
        $err = 'ছবি সংরক্ষণ করা যায়নি।';
        return null;
    }
    return '/uploads/' . $name;
}

// ---------------- POST actions ----------------
if (is_post()) {
    csrf_require();
    $act = (string) ($_POST['act'] ?? 'save');
    $id = (int) ($_POST['id'] ?? 0);

    if ($act === 'delete' && $id > 0) {
        $gone = q_one('SELECT * FROM ' . $T . ' WHERE id = ?', [$id]);
        q_exec('DELETE FROM ' . $T . ' WHERE id = ?', [$id]);
        log_activity('content.deleted', $key . ' #' . $id, (int) $u['id']);
        if ($gone) {
            $goneUrl = $key === 'blog' ? abs_url('/blog/' . $gone['slug']) : seo_detail_url($key, (string) ($gone['slug'] ?? ''));
            if ($goneUrl) { seo_record($goneUrl, 'deleted', $e['label'] . ' #' . $id); }
        }
        flash('ok', 'মুছে ফেলা হয়েছে।');
        redirect(a_url('entity', ['e' => $key]));
    }
    if ($act === 'toggle' && $id > 0 && $hasActive) {
        q_exec('UPDATE ' . $T . ' SET is_active = 1 - is_active, updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
        redirect(a_url('entity', ['e' => $key]));
    }

    // ---- save ----
    $errors = [];
    $vals = [];
    $old = $id > 0 ? q_one('SELECT * FROM ' . $T . ' WHERE id = ?', [$id]) : null;
    foreach ($fields as $f) {
        $n = $f['name'];
        $raw = $_POST[$n] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        switch ($f['type']) {
            case 'bool':
                $vals[$n] = isset($_POST[$n]) && $_POST[$n] === '1' ? 1 : 0;
                break;
            case 'int':
                $vals[$n] = (int) $raw;
                break;
            case 'rating':
                $vals[$n] = ($raw !== '' && (int) $raw >= 1 && (int) $raw <= 5) ? (int) $raw : null;
                break;
            case 'relation_id':
                $vals[$n] = (int) $raw > 0 ? (int) $raw : null;
                break;
            case 'slug':
                $s = $raw !== '' ? strtolower($raw) : slugify((string) ($_POST['title'] ?? ''));
                if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $s)) {
                    $errors[] = 'URL slug শুধু ইংরেজি ছোট হাতের অক্ষর, সংখ্যা ও হাইফেন (-) দিয়ে লিখুন (যেমন: pcos-treatment)।';
                }
                $vals[$n] = $s;
                break;
            case 'youtube':
                $yid = youtube_id($raw);
                if ($yid === '') { $errors[] = 'সঠিক YouTube লিংক বা ১১-অক্ষরের ID দিন।'; }
                $vals[$n] = $yid;
                break;
            case 'datetime':
                $vals[$n] = $raw !== '' ? date('Y-m-d H:i:s', (int) strtotime(str_replace('T', ' ', $raw))) : null;
                break;
            case 'image':
                $cur = (string) ($old[$n] ?? '');
                if (!empty($_POST[$n . '__remove'])) { $cur = ''; }
                $err = '';
                $new = save_upload($n . '__file', $err);
                if ($err !== '') { $errors[] = $err; }
                $vals[$n] = $new ?? $cur;
                break;
            case 'select':
                $vals[$n] = isset($f['options'][$raw]) ? $raw : array_key_first($f['options']);
                break;
            default:
                $max = (int) ($f['max'] ?? 0);
                $vals[$n] = $max > 0 ? mb_substr($raw, 0, $max) : $raw;
        }
        if (!empty($f['required']) && ($vals[$n] === '' || $vals[$n] === null)) {
            $errors[] = '"' . $f['label'] . '" আবশ্যক।';
        }
    }
    // slug uniqueness
    if (isset($vals['slug']) && !$errors) {
        $dup = q_val('SELECT id FROM ' . $T . ' WHERE slug = ? AND id <> ?', [$vals['slug'], $id]);
        if ($dup) { $errors[] = 'এই slug আগে থেকেই ব্যবহার হচ্ছে।'; }
    }
    // blog specifics
    if ($key === 'blog' && !$errors) {
        if ($vals['status'] === 'published' && empty($vals['published_at'])) { $vals['published_at'] = date('Y-m-d H:i:s'); }
        if ($vals['author_name'] === '') { $vals['author_name'] = setting('doctor_name_bn'); }
    }
    if ($errors) {
        foreach ($errors as $er) { flash('err', $er); }
        $_SESSION['form_old'] = $vals;
        redirect(a_url('entity', ['e' => $key, 'a' => 'edit'] + ($id ? ['id' => $id] : [])));
    }

    $now = date('Y-m-d H:i:s');
    $isNew = !($id > 0 && $old);
    if ($id > 0 && $old) {
        $sets = [];
        $params = [];
        foreach ($vals as $c => $v) { $sets[] = $c . ' = ?'; $params[] = $v; }
        $sets[] = 'updated_at = ?'; $params[] = $now; $params[] = $id;
        q_exec('UPDATE ' . $T . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        if ($key === 'blog' && $old['slug'] !== $vals['slug']) {
            q_exec('DELETE FROM old_slugs WHERE old_slug = ?', [$old['slug']]);
            q_exec('INSERT INTO old_slugs (old_slug, post_id) VALUES (?, ?)', [$old['slug'], $id]);
        }
    } else {
        if ($hasActive && !array_key_exists('is_active', $vals)) { $vals['is_active'] = 1; }
        $cols = array_keys($vals);
        $id = q_insert('INSERT INTO ' . $T . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($vals));
    }
    // relations (junction tables)
    foreach ($e['relations'] as $ri => $rel) {
        $ids = array_unique(array_map('intval', (array) ($_POST['rel'][$ri] ?? [])));
        q_exec('DELETE FROM ' . $rel['junction'] . ' WHERE ' . $rel['self'] . ' = ?', [$id]);
        foreach ($ids as $oid) {
            if ($oid > 0) {
                q_exec('INSERT INTO ' . $rel['junction'] . ' (' . $rel['self'] . ', ' . $rel['other'] . ') VALUES (?, ?)', [$id, $oid]);
            }
        }
    }
    log_activity('content.saved', $key . ' #' . $id, (int) $u['id']);
    // SEO: only publicly visible URLs are logged and pinged, and only on a real change
    $liveUrl = null;
    if ($key === 'blog') {
        $liveUrl = ($vals['status'] === 'published' && $vals['robots'] === 'index') ? abs_url('/blog/' . $vals['slug']) : null;
    } elseif ($hasActive && (int) ($vals['is_active'] ?? 1) === 1) {
        $liveUrl = seo_detail_url($key, (string) ($vals['slug'] ?? ''));
    }
    $changed = $isNew || (bool) array_filter($vals, static fn($v, $k) => (string) ($old[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
    if ($liveUrl !== null && $changed) {
        seo_record($liveUrl, $isNew ? 'published' : 'updated', $e['label'] . ' · ' . (string) ($vals[$titleCol] ?? ''));
    }
    flash('ok', 'সংরক্ষিত হয়েছে।');
    redirect(a_url('entity', ['e' => $key, 'a' => 'edit', 'id' => $id]));
}

// ---------------- Views ----------------
$a = (string) ($_GET['a'] ?? 'list');
if ($a === 'edit') {
    $id = (int) ($_GET['id'] ?? 0);
    $row = $id > 0 ? q_one('SELECT * FROM ' . $T . ' WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$row) { redirect(a_url('entity', ['e' => $key])); }
    $defaults = ['is_active' => 1, 'sort_order' => 0, 'status' => 'draft', 'robots' => 'index', 'author_name' => setting('doctor_name_bn')];
    $row = $row ?: $defaults;
    if (!empty($_SESSION['form_old'])) { $row = array_merge($row, $_SESSION['form_old']); unset($_SESSION['form_old']); }
    admin_head(($id ? 'সম্পাদনা: ' : 'নতুন ') . $e['singular']);
    ?>
<p><a href="<?= h(a_url('entity', ['e' => $key])) ?>">← <?= h($e['label']) ?> তালিকা</a>
<?php if ($id && !empty($row['slug']) && ($key !== 'blog' || ($row['status'] ?? '') === 'published') && $e['detail_file']): ?> · <a href="<?= h(entity_url($key, $row['slug'])) ?>" target="_blank" rel="noopener">সাইটে দেখুন ↗</a><?php endif; ?></p>
<form method="post" enctype="multipart/form-data" class="panel form">
<?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $id ?>">
<?php foreach ($fields as $f): $n = $f['name']; $v = $row[$n] ?? ''; ?>
  <?php if ($f['type'] === 'bool'): ?>
    <label class="check"><input type="hidden" name="<?= h($n) ?>" value="0"><input type="checkbox" name="<?= h($n) ?>" value="1" <?= (int) $v === 1 ? 'checked' : '' ?>> <?= h($f['label']) ?></label>
  <?php else: ?>
  <label><?= h($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?>
  <?php switch ($f['type']):
      case 'textarea': ?><textarea name="<?= h($n) ?>" rows="4" <?= !empty($f['max']) ? 'maxlength="' . (int) $f['max'] . '"' : '' ?>><?= h((string) $v) ?></textarea><?php break;
      case 'md': ?><textarea name="<?= h($n) ?>" rows="<?= $key === 'blog' ? 22 : 9 ?>" class="mono"><?= h((string) $v) ?></textarea><?php break;
      case 'int': ?><input type="number" name="<?= h($n) ?>" value="<?= h((string) $v) ?>"><?php break;
      case 'rating': ?><select name="<?= h($n) ?>"><option value="">—</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>" <?= (int) $v === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select><?php break;
      case 'select': ?><select name="<?= h($n) ?>"><?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= h((string) $ov) ?>" <?= (string) $v === (string) $ov ? 'selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?></select><?php break;
      case 'relation_id': $opts = q_all('SELECT id, title FROM ' . $f['table'] . ' ORDER BY sort_order, id'); ?><select name="<?= h($n) ?>"><option value="">— নেই —</option><?php foreach ($opts as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) $v === (int) $o['id'] ? 'selected' : '' ?>><?= h($o['title']) ?></option><?php endforeach; ?></select><?php break;
      case 'datetime': ?><input type="datetime-local" name="<?= h($n) ?>" value="<?= h($v ? date('Y-m-d\TH:i', (int) strtotime((string) $v)) : '') ?>"><?php break;
      case 'image': ?>
        <?php if ($v): ?><span class="thumb"><img src="<?= h(img_url((string) $v)) ?>" alt=""> <label class="check"><input type="checkbox" name="<?= h($n) ?>__remove" value="1"> ছবি সরান</label></span><?php endif; ?>
        <input type="file" name="<?= h($n) ?>__file" accept="image/jpeg,image/png,image/webp,image/gif"><?php break;
      default: ?><input type="text" name="<?= h($n) ?>" value="<?= h((string) $v) ?>" <?= $f['type'] === 'slug' ? 'pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="auto-generated if left blank (Latin titles only)"' : '' ?> <?= !empty($f['max']) ? 'maxlength="' . (int) $f['max'] . '"' : '' ?>><?php endswitch; ?>
  </label>
  <?php endif; ?>
<?php endforeach; ?>
<?php foreach ($e['relations'] as $ri => $rel):
    $all = q_all('SELECT id, title FROM ' . $rel['other_table'] . ' ORDER BY sort_order, id');
    $sel = $id ? array_map('intval', array_column(q_all('SELECT ' . $rel['other'] . ' AS oid FROM ' . $rel['junction'] . ' WHERE ' . $rel['self'] . ' = ?', [$id]), 'oid')) : []; ?>
  <fieldset><legend><?= h($rel['label']) ?></legend><div class="checks">
<?php foreach ($all as $o): ?><label class="check"><input type="checkbox" name="rel[<?= $ri ?>][]" value="<?= (int) $o['id'] ?>" <?= in_array((int) $o['id'], $sel, true) ? 'checked' : '' ?>> <?= h($o['title']) ?></label><?php endforeach; ?>
  </div></fieldset>
<?php endforeach; ?>
  <button class="btn" type="submit">সংরক্ষণ</button>
</form>
<?php
    admin_foot();
    exit;
}

// list
$rows = q_all('SELECT * FROM ' . $T . ' ORDER BY ' . $e['order'] . ' LIMIT 500');
admin_head($e['label']);
?>
<p><a class="btn" href="<?= h(a_url('entity', ['e' => $key, 'a' => 'edit'])) ?>">+ নতুন <?= h($e['singular']) ?></a></p>
<div class="panel"><div class="tbl-wrap"><table><thead><tr><th>#</th><th>শিরোনাম</th><?php if (!empty($e['list_extra'])): foreach ($e['list_extra'] as $c): ?><th><?= h($c) ?></th><?php endforeach; endif; ?><?php if ($hasActive): ?><th>সক্রিয়</th><?php endif; ?><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= (int) $r['id'] ?></td><td><a href="<?= h(a_url('entity', ['e' => $key, 'a' => 'edit', 'id' => $r['id']])) ?>"><?= h(mb_substr((string) $r[$titleCol], 0, 90)) ?></a></td>
<?php if (!empty($e['list_extra'])): foreach ($e['list_extra'] as $c): ?><td><?= h((string) $r[$c]) ?></td><?php endforeach; endif; ?>
<?php if ($hasActive): ?><td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="tag <?= $r['is_active'] ? 'on' : 'off' ?>" type="submit"><?= $r['is_active'] ? 'হ্যাঁ' : 'না' ?></button></form></td><?php endif; ?>
<td class="act"><a href="<?= h(a_url('entity', ['e' => $key, 'a' => 'edit', 'id' => $r['id']])) ?>">সম্পাদনা</a>
<form method="post" class="inline" onsubmit="return confirm('মুছে ফেলবেন?')"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn-link danger" type="submit">মুছুন</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">এখনও কিছু নেই।</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php admin_foot();
