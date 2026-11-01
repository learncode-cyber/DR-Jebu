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

// A content table missing on this database (SQL not imported yet): show a clear message instead of a 500.
try {
    q_val('SELECT 1 FROM ' . $T . ' LIMIT 1');
} catch (Throwable $ex) {
    error_log('[jebu] entity ' . $T . ': ' . $ex->getMessage());
    admin_head($e['label']);
    echo '<div class="panel"><h2>টেবিল পাওয়া যায়নি</h2><p>এই ডাটাবেসে <code>' . h($T) . '</code> টেবিল নেই। live database-এ <code>app/database/jebu_medical_full.sql</code> ফাইলটি phpMyAdmin-এ ইমপোর্ট করুন। এটি বারবার চালালেও কোনো ডেটা নষ্ট হবে না।</p><p><a href="' . h(a_url('')) . '">← ড্যাশবোর্ডে ফিরুন</a></p></div>';
    admin_foot();
    exit;
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
    if ($act === 'bulk') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn($v) => $v > 0)));
        $do = (string) ($_POST['bulk_do'] ?? '');
        $back = ['e' => $key, 's' => (string) ($_POST['s'] ?? 'all'), 'q' => trim((string) ($_POST['q'] ?? ''))];
        if (!$ids || !in_array($do, ['publish', 'unpublish'], true)) {
            flash('err', 'আইটেম ও কাজ (প্রকাশ/খসড়া) নির্বাচন করুন।');
            redirect(a_url('entity', $back));
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $now = date('Y-m-d H:i:s');
        $rowsBulk = q_all('SELECT * FROM ' . $T . ' WHERE id IN (' . $ph . ')', $ids);
        if ($key === 'blog') {
            if ($do === 'publish') {
                q_exec('UPDATE blog_posts SET status = \'published\', published_at = COALESCE(published_at, ?), updated_at = ? WHERE id IN (' . $ph . ')', array_merge([$now, $now], $ids));
            } else {
                q_exec('UPDATE blog_posts SET status = \'draft\', updated_at = ? WHERE id IN (' . $ph . ')', array_merge([$now], $ids));
            }
        } else {
            q_exec('UPDATE ' . $T . ' SET is_active = ?, updated_at = ? WHERE id IN (' . $ph . ')', array_merge([$do === 'publish' ? 1 : 0, $now], $ids));
        }
        // SEO: newly public URLs are logged and pinged (same rule as a single save)
        if ($do === 'publish') {
            foreach ($rowsBulk as $rb) {
                $u2 = $key === 'blog' ? abs_url('/blog/' . $rb['slug']) : seo_detail_url($key, (string) ($rb['slug'] ?? ''));
                if ($u2 !== null) {
                    seo_record($u2, 'published', $e['label'] . ' · ' . (string) ($rb[$titleCol] ?? '') . ' (বাল্ক)');
                }
            }
        }
        log_activity('content.bulk', $do . ' ' . $key . ': ' . implode(',', $ids), (int) $u['id']);
        flash('ok', count($ids) . 'টি আইটেম ' . ($do === 'publish' ? 'প্রকাশ' : 'খসড়া') . ' করা হয়েছে।');
        redirect(a_url('entity', $back));
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
            case 'rich':
                $vals[$n] = rich_sanitize((string) ($_POST[$n] ?? ''));
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
    $defaults = ['is_active' => 1, 'show_in_menu' => 0, 'menu_order' => 0, 'sort_order' => 0, 'status' => 'draft', 'robots' => 'index', 'author_name' => setting('doctor_name_bn')];
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
  <?php elseif ($f['type'] === 'rich'): ?>
  <div class="field"><span class="flabel"><?= h($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?></span>
    <?= rich_editor_html($n, (string) $v, $f['label'], csrf_token()) ?>
  </div>
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
<?php if (in_array('rich', array_column($fields, 'type'), true)): ?><script src="<?= h(asset('assets/js/rich.js')) ?>" defer></script><?php endif; ?>
<?php
    admin_foot();
    exit;
}

// list: filters (status + search), counts, last editor
$filterS = (string) ($_GET['s'] ?? 'all');
if (!in_array($filterS, ['all', 'pub', 'draft'], true)) { $filterS = 'all'; }
$filterQ = trim((string) ($_GET['q'] ?? ''));
$liveSql = $key === 'blog' ? "status = 'published'" : ($hasActive ? 'is_active = 1' : null);
$where = [];
$params = [];
if ($liveSql !== null && $filterS === 'pub') { $where[] = $liveSql; }
if ($liveSql !== null && $filterS === 'draft') { $where[] = 'NOT (' . $liveSql . ')'; }
if ($filterQ !== '') {
    $searchCols = [$titleCol];
    if (in_array('slug', array_column($fields, 'name'), true)) { $searchCols[] = 'slug'; }
    $like = '%' . addcslashes($filterQ, '%_\\') . '%';
    $where[] = '(' . implode(' OR ', array_map(static fn(string $c): string => $c . ' LIKE ?', $searchCols)) . ')';
    foreach ($searchCols as $_c) { $params[] = $like; }
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$rows = q_all('SELECT * FROM ' . $T . $whereSql . ' ORDER BY ' . $e['order'] . ' LIMIT 500', $params);

$counts = ['all' => 0, 'pub' => 0, 'draft' => 0];
if ($liveSql !== null) {
    $cr = q_one('SELECT COUNT(*) AS t, SUM(CASE WHEN ' . $liveSql . ' THEN 1 ELSE 0 END) AS l FROM ' . $T);
    $counts = ['all' => (int) $cr['t'], 'pub' => (int) $cr['l'], 'draft' => (int) $cr['t'] - (int) $cr['l']];
} else {
    $counts['all'] = (int) q_val('SELECT COUNT(*) FROM ' . $T);
}

// last editor per row, from the activity log (content.saved, detail "<key> #<id>")
$editors = [];
foreach (q_all("SELECT a.detail, a.created_at, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.action = 'content.saved' AND a.detail LIKE ? ORDER BY a.id DESC LIMIT 3000", [$key . ' #%']) as $ed) {
    if (preg_match('/ #(\d+)$/', (string) $ed['detail'], $m) === 1 && !isset($editors[(int) $m[1]])) {
        $editors[(int) $m[1]] = ['name' => (string) ($ed['name'] ?? '—'), 'at' => (string) $ed['created_at']];
    }
}
$colCount = 5 + count($e['list_extra'] ?? []) + ($hasActive ? 1 : 0);

admin_head($e['label']);
$filterLink = static fn(string $s): string => a_url('entity', ['e' => $key, 's' => $s, 'q' => $filterQ]);
?>
<p><a class="btn" href="<?= h(a_url('entity', ['e' => $key, 'a' => 'edit'])) ?>">+ নতুন <?= h($e['singular']) ?></a></p>
<div class="panel">
  <div class="tools">
    <form method="get" action="<?= h(a_url('entity')) ?>" class="inline-search">
      <input type="hidden" name="e" value="<?= h($key) ?>">
      <input type="hidden" name="s" value="<?= h($filterS) ?>">
      <input type="search" name="q" value="<?= h($filterQ) ?>" placeholder="শিরোনাম বা slug খুঁজুন" style="width:260px">
      <button class="btn ghost" type="submit">খুঁজুন</button>
      <?php if ($filterQ !== ''): ?><a href="<?= h($filterLink($filterS)) ?>" class="btn-link" style="display:inline">খোঁজা মুছুন</a><?php endif; ?>
    </form>
    <?php if ($liveSql !== null): ?>
    <span class="chips-f">
      <a class="chip <?= $filterS === 'all' ? 'on' : '' ?>" href="<?= h($filterLink('all')) ?>">সব (<?= $counts['all'] ?>)</a>
      <a class="chip <?= $filterS === 'pub' ? 'on' : '' ?>" href="<?= h($filterLink('pub')) ?>">প্রকাশিত (<?= $counts['pub'] ?>)</a>
      <a class="chip <?= $filterS === 'draft' ? 'on' : '' ?>" href="<?= h($filterLink('draft')) ?>">খসড়া (<?= $counts['draft'] ?>)</a>
    </span>
    <?php endif; ?>
  </div>

  <form method="post" id="bulkf" class="bulkbar" onsubmit="return confirm('নির্বাচিত আইটেমগুলোতে কাজটি প্রয়োগ করবেন?')">
    <?= csrf_field() ?><input type="hidden" name="act" value="bulk">
    <input type="hidden" name="s" value="<?= h($filterS) ?>"><input type="hidden" name="q" value="<?= h($filterQ) ?>">
    <span class="muted">নির্বাচিত আইটেমে:</span>
    <select name="bulk_do" style="width:auto;margin:0">
      <option value="publish">প্রকাশ করুন</option>
      <option value="unpublish">খসড়ায় নিন</option>
    </select>
    <button class="btn ghost" type="submit">প্রয়োগ</button>
  </form>
</div>
<div class="panel"><div class="tbl-wrap"><table><thead><tr><th style="width:34px"><input type="checkbox" form="bulkf" id="bulkall" aria-label="সব নির্বাচন"></th><th>#</th><th>শিরোনাম</th><?php if (!empty($e['list_extra'])): foreach ($e['list_extra'] as $c): ?><th><?= h($c) ?></th><?php endforeach; endif; ?><?php if ($hasActive): ?><th>সক্রিয়</th><?php endif; ?><th>সর্বশেষ সম্পাদক</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $ed = $editors[(int) $r['id']] ?? null; ?>
<tr><td><input type="checkbox" form="bulkf" name="ids[]" value="<?= (int) $r['id'] ?>" class="bulk-one" aria-label="নির্বাচন"></td><td><?= (int) $r['id'] ?></td><td><a href="<?= h(a_url('entity', ['e' => $key, 'a' => 'edit', 'id' => $r['id']])) ?>"><?= h(mb_substr((string) $r[$titleCol], 0, 90)) ?></a></td>
<?php if (!empty($e['list_extra'])): foreach ($e['list_extra'] as $c): ?><td><?= h((string) $r[$c]) ?></td><?php endforeach; endif; ?>
<?php if ($hasActive): ?><td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="tag <?= $r['is_active'] ? 'on' : 'off' ?>" type="submit"><?= $r['is_active'] ? 'হ্যাঁ' : 'না' ?></button></form></td><?php endif; ?>
<td class="ed"><?= $ed ? h($ed['name']) . '<br><small>' . h(date('d M Y, h:i A', (int) strtotime($ed['at']))) . '</small>' : '—' ?></td>
<td class="act"><div class="acts"><a class="abtn" href="<?= h(a_url('entity', ['e' => $key, 'a' => 'edit', 'id' => $r['id']])) ?>" title="সম্পাদনা করুন"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>সম্পাদনা</a>
<form method="post" class="inline" onsubmit="return confirm('মুছে ফেলবেন?')"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="abtn danger" type="submit" title="মুছে ফেলুন"><i class="fa-solid fa-trash-can" aria-hidden="true"></i>মুছুন</button></form></div></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="<?= $colCount ?>" class="muted">কোনো আইটেম পাওয়া যায়নি।</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>
(function () {
  var all = document.getElementById('bulkall');
  if (!all) { return; }
  all.addEventListener('change', function () {
    document.querySelectorAll('.bulk-one').forEach(function (cb) { cb.checked = all.checked; });
  });
})();
</script>
<?php admin_foot();
