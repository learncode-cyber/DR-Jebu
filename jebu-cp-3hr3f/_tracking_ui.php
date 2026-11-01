<?php
declare(strict_types=1);

/*
 * Shared admin screen for the tracking pages (Meta Ads, Google Ads, Google Analytics).
 * Each platform page lists its accounts and handles add / edit / test / delete for any number of them.
 * Every POST needs the CSRF token and the 'settings' permission (checked by the page before this runs).
 */

function tr_badge(string $text, string $tone): string {
    $styles = [
        'ok' => 'background:#e8f6ee;color:#14633d',
        'off' => 'background:#f3f4f6;color:#555',
        'warn' => 'background:#fff4e5;color:#8a4b00',
    ];
    return '<span style="display:inline-block;padding:.15rem .6rem;border-radius:999px;font-size:.8rem;' . ($styles[$tone] ?? $styles['off']) . '">' . h($text) . '</span>';
}

function tr_status(string $label, bool $ok, string $hint = ''): void {
    echo '<div class="stat' . ($ok ? '' : ' warn') . '" style="min-width:0">'
        . '<b style="font-size:1.3rem">' . ($ok ? '✓' : '○') . '</b>'
        . '<span><strong style="color:var(--ink)">' . h($label) . '</strong>'
        . ($hint !== '' ? '<br><small>' . h($hint) . '</small>' : '') . '</span></div>';
}

function tr_steps(array $steps): void {
    echo '<ol class="tr-steps" style="margin:0;padding-left:1.3rem;display:grid;gap:.7rem">';
    foreach ($steps as [$title, $desc]) {
        echo '<li><b>' . h($title) . '</b><br><span class="muted">' . h($desc) . '</span></li>';
    }
    echo '</ol>';
}

function tr_copy(string $id, string $title, string $code, string $note = ''): void {
    echo '<div class="panel" style="margin-bottom:1rem">'
        . '<div class="tools" style="justify-content:space-between"><b>' . h($title) . '</b>'
        . '<button type="button" class="abtn" data-copy="' . h($id) . '"><i class="fa-regular fa-copy" aria-hidden="true"></i>কপি করুন</button></div>'
        . ($note !== '' ? '<p class="muted" style="margin-top:0">' . h($note) . '</p>' : '')
        . '<textarea id="' . h($id) . '" readonly rows="' . max(3, min(14, substr_count($code, "\n") + 2)) . '" class="mono" style="font-size:.85rem">' . h($code) . '</textarea>'
        . '</div>';
}

function tr_field(string $name, string $label, string $value, string $help = '', string $type = 'text', string $ph = ''): void {
    echo '<label>' . h($label)
        . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"'
        . ($ph !== '' ? ' placeholder="' . h($ph) . '"' : '') . ' autocomplete="off">'
        . ($help !== '' ? '<small class="muted" style="display:block;margin-top:.25rem">' . h($help) . '</small>' : '')
        . '</label>';
}

function tr_check(string $name, string $label, bool $on): void {
    echo '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . ($on ? ' checked' : '') . '> ' . h($label) . '</label>';
}

/** Copy-button handler for every tracking page. */
function tr_copy_script(): void {
    echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-copy]");if(!b)return;var t=document.getElementById(b.getAttribute("data-copy"));if(!t)return;t.select();var ok=function(){b.innerHTML="কপি হয়েছে";setTimeout(function(){b.innerHTML="কপি করুন";},1600);};if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(ok,function(){document.execCommand("copy");ok();});}else{document.execCommand("copy");ok();}});</script>';
}

/** POST handler: save / delete / test for one platform. Always redirects back to the page. */
function tr_handle(string $platform, string $slug, int $userId): void {
    if (!is_post()) {
        return;
    }
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'save') {
        $r = tracking_save($platform, $id, $_POST);
        if ($r['ok']) {
            flash('ok', $id > 0 ? 'অ্যাকাউন্ট আপডেট হয়েছে।' : 'নতুন অ্যাকাউন্ট যোগ হয়েছে।');
            log_activity('tracking.' . $platform . '_saved', (string) ($r['id'] ?? ''), $userId);
        } else {
            foreach ($r['errors'] ?? [] as $er) {
                flash('err', $er);
            }
        }
    } elseif ($act === 'delete') {
        $ok = tracking_delete($platform, $id);
        flash($ok ? 'ok' : 'err', $ok ? 'অ্যাকাউন্ট মুছে ফেলা হয়েছে।' : 'অ্যাকাউন্টটি পাওয়া যায়নি।');
        if ($ok) {
            log_activity('tracking.' . $platform . '_deleted', (string) $id, $userId);
        }
    } elseif ($act === 'test') {
        $acc = $platform === 'gads' ? null : tracking_find($platform, $id);
        if ($acc === null) {
            flash('err', $platform === 'gads' ? 'Google Ads-এর জন্য সরাসরি টেস্ট নেই। ধন্যবাদ পেজে কনভার্সন যাচাই করুন।' : 'অ্যাকাউন্টটি পাওয়া যায়নি।');
        } else {
            $r = $platform === 'meta' ? tracking_meta_test($acc) : tracking_ga4_test($acc);
            flash($r['ok'] ? 'ok' : 'err', $r['msg']);
        }
    } else {
        flash('err', 'অজানা অনুরোধ।');
    }
    redirect(a_url($slug));
}

/** One account's form. $acc = null for the "add" form. */
function tr_form(string $platform, array $cfg, ?array $acc): void {
    $isEdit = $acc !== null;
    echo '<form method="post" class="form" style="max-width:820px">' . csrf_field()
        . '<input type="hidden" name="act" value="save">'
        . ($isEdit ? '<input type="hidden" name="id" value="' . (int) $acc['id'] . '">' : '');
    tr_field('label', 'অ্যাকাউন্টের নাম', (string) ($acc['label'] ?? ''), 'নিজের সুবিধার জন্য, যেমন: ক্লিনিক মেইন বা ক্লায়েন্ট-এক্স।', 'text', 'যেমন: ক্লিনিক মেইন');
    tr_field('ext_id', $cfg['ext_label'], (string) ($acc['ext_id'] ?? ''), $cfg['ext_help'], 'text', $cfg['ext_ph']);
    if ($cfg['secret_label'] !== null) {
        echo '<label>' . h($cfg['secret_label'])
            . '<input type="password" name="secret" value="" placeholder="' . h($cfg['secret_ph']) . '" autocomplete="new-password">'
            . '<small class="muted" style="display:block;margin-top:.25rem">' . h($cfg['secret_help']) . '</small>'
            . ($isEdit && (string) $acc['secret'] !== '' ? '<small style="display:block;color:#14633d">সংরক্ষিত আছে। বদলাতে নতুন মান দিন, না হলে আগেরটাই থাকবে।</small>' : '')
            . '</label>';
        if ($isEdit && (string) $acc['secret'] !== '') {
            tr_check('secret_clear', 'এই অ্যাকাউন্টের গোপন মান মুছে ফেলুন', false);
        }
    }
    if ($cfg['extra_label'] !== null) {
        tr_field('extra', $cfg['extra_label'], (string) ($acc['extra'] ?? ''), $cfg['extra_help'], 'text', $cfg['extra_ph']);
    }
    if ($cfg['server']) {
        tr_check('server_on', 'সার্ভার-সাইড ইভেন্ট চালু (Conversions API / Measurement Protocol)', $isEdit ? (int) $acc['server_on'] === 1 : false);
    }
    tr_check('active', 'এই অ্যাকাউন্ট সক্রিয় (সাইটে বসবে)', $isEdit ? (int) $acc['active'] === 1 : true);
    echo '<div class="tools" style="margin-top:.8rem"><button class="btn" type="submit">' . ($isEdit ? 'সংরক্ষণ করুন' : 'অ্যাকাউন্ট যোগ করুন') . '</button></div>'
        . '</form>';
}

/** Full platform screen: summary cards, setup steps, account list with edit/test/delete, add form, code boxes. */
function tr_page(string $platform, string $title, array $steps, array $cfg, array $copy, string $slug): void {
    $accounts = tracking_all($platform);
    $active = array_values(array_filter($accounts, static fn($a) => (int) $a['active'] === 1));
    $serverOn = array_values(array_filter($active, static fn($a) => (int) $a['server_on'] === 1 && (string) $a['secret'] !== ''));
    echo '<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">';
    tr_status('মোট অ্যাকাউন্ট', $accounts !== [], count($accounts) . 'টি যোগ করা আছে');
    tr_status('সক্রিয় অ্যাকাউন্ট', $active !== [], count($active) . 'টি সাইটে বসছে');
    if ($cfg['server']) {
        tr_status('সার্ভার-সাইড চালু', $serverOn !== [], count($serverOn) . 'টি অ্যাকাউন্ট সার্ভার থেকেও ইভেন্ট পাঠাচ্ছে');
    }
    echo '</div>';

    echo '<div class="panel"><h2>কীভাবে সেটআপ করবেন</h2>';
    tr_steps($steps);
    echo '</div>';

    echo '<div class="panel"><h2>নতুন অ্যাকাউন্ট যোগ করুন</h2>'
        . '<p class="muted" style="margin-top:0">একই পেজে যত খুশি অ্যাকাউন্ট যোগ করতে পারবেন। প্রতিটি অ্যাকাউন্টের ডাটা শুধু সেই অ্যাকাউন্টেই যাবে।</p>';
    tr_form($platform, $cfg, null);
    echo '</div>';

    echo '<h2 style="margin:1.4rem 0 .6rem">যোগ করা অ্যাকাউন্টসমূহ</h2>';
    if ($accounts === []) {
        echo '<div class="panel"><p class="muted" style="margin:0">এখনো কোনো অ্যাকাউন্ট যোগ করা হয়নি।</p></div>';
    }
    foreach ($accounts as $a) {
        $isOn = (int) $a['active'] === 1;
        $srv = (int) $a['server_on'] === 1;
        echo '<div class="panel" style="margin-bottom:1rem">';
        echo '<div class="tools" style="justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.6rem">'
            . '<div><b style="font-size:1.05rem">' . h((string) $a['label'] !== '' ? (string) $a['label'] : 'নামহীন') . '</b><br>'
            . '<code class="mono">' . h((string) $a['ext_id']) . '</code></div>'
            . '<div class="tools" style="gap:.4rem;flex-wrap:wrap">'
            . tr_badge($isOn ? 'সক্রিয়' : 'বন্ধ', $isOn ? 'ok' : 'off')
            . ($cfg['server'] ? tr_badge($srv && (string) $a['secret'] !== '' ? 'সার্ভার চালু' : 'শুধু ব্রাউজার', $srv ? 'ok' : 'warn') : '')
            . '</div></div>';
        echo '<div class="tools" style="margin-top:.7rem;flex-wrap:wrap;gap:.5rem">';
        echo '<details><summary class="abtn"><i class="fa-solid fa-pen" aria-hidden="true"></i>&nbsp;সম্পাদনা</summary>'
            . '<div style="margin-top:.8rem">';
        tr_form($platform, $cfg, $a);
        echo '</div></details>';
        if ($cfg['test']) {
            echo '<form method="post" style="display:inline">' . csrf_field()
                . '<input type="hidden" name="act" value="test"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
                . '<button class="abtn" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i>&nbsp;টেস্ট</button></form>';
        }
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'এই অ্যাকাউন্ট মুছে ফেলতে চান?\')">' . csrf_field()
            . '<input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
            . '<button class="abtn danger" type="submit"><i class="fa-solid fa-trash" aria-hidden="true"></i>&nbsp;মুছুন</button></form>';
        echo '</div></div>';
    }

    echo '<h2 style="margin:1.6rem 0 .6rem">কোড ও নমুনা</h2>';
    foreach ($copy as [$id, $ttl, $code, $note]) {
        tr_copy($id, $ttl, $code, $note);
    }
    tr_copy_script();
}
