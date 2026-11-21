<?php
declare(strict_types=1);

/** Bangla messages for each HTTP error we show. */
function error_messages(): array {
    return [
        400 => ['অনুরোধটি বুঝতে পারিনি', 'অনুগ্রহ করে ঠিকানাটি আবার যাচাই করে দেখুন।'],
        403 => ['এই পাতাটি দেখার অনুমতি নেই', 'এই অংশটি সুরক্ষিত রাখা হয়েছে।'],
        404 => ['দুঃখিত, এই পাতাটি পাওয়া যাচ্ছে না', 'লিংকটি সরানো হয়েছে, ঠিকানাটি ভুল, অথবা এই বিভাগে এখন কোনো তথ্য যুক্ত করা হয়নি।'],
        405 => ['এই অনুরোধের ধরন সমর্থিত নয়', 'অনুগ্রহ করে সাইটের মেনু বা বাটন ব্যবহার করুন।'],
        429 => ['অনেক বেশি অনুরোধ এসেছে', 'কিছুক্ষণ পর আবার চেষ্টা করুন।'],
        500 => ['সাময়িক একটি সমস্যা হয়েছে', 'আমরা বিষয়টি দেখছি। কিছুক্ষণ পর আবার চেষ্টা করুন।'],
        503 => ['সাইটটি সাময়িকভাবে বন্ধ আছে', 'রক্ষণাবেক্ষণের কাজ চলছে। একটু পরে আবার আসুন।'],
    ];
}

/** The animated woman doctor (same artwork on every error page). */
function error_art_svg(string $glyph): string {
    $g = htmlspecialchars($glyph, ENT_QUOTES, 'UTF-8');
    return '<svg viewBox="0 0 220 260" role="img" aria-label="একজন মহিলা চিকিৎসক হাত নাড়ছেন">'
    . '<circle class="nf-blob" cx="110" cy="130" r="100"/>'
    . '<g class="nf-bob">'
    . '<path class="nf-coat" d="M58 150 Q110 128 162 150 L172 252 L48 252 Z"/>'
    . '<path class="nf-coat-line" d="M110 150 L110 252"/>'
    . '<rect class="nf-skin" x="98" y="118" width="24" height="18" rx="6"/>'
    . '<circle class="nf-skin" cx="110" cy="92" r="32"/>'
    . '<path class="nf-hair" d="M76 90 Q74 54 110 52 Q146 54 144 90 Q136 66 110 66 Q88 66 76 90 Z"/>'
    . '<circle class="nf-hair" cx="110" cy="50" r="13"/>'
    . '<g class="nf-eyes"><ellipse cx="99" cy="94" rx="3.2" ry="3.8"/><ellipse cx="121" cy="94" rx="3.2" ry="3.8"/></g>'
    . '<path class="nf-smile" d="M100 106 Q110 115 120 106"/>'
    . '<circle class="nf-cheek" cx="92" cy="106" r="4"/><circle class="nf-cheek" cx="128" cy="106" r="4"/>'
    . '<path class="nf-steth" d="M92 150 Q92 178 110 178 Q128 178 128 150"/>'
    . '<circle class="nf-steth-end" cx="110" cy="184" r="5"/>'
    . '<path class="nf-heart" d="M150 190 c-4-6-12-3-9 3 l9 8 l9-8 c3-6-5-9-9-3z"/>'
    . '<g class="nf-wave"><path class="nf-coat" d="M160 152 Q182 150 186 120" fill="none" stroke-width="16" stroke-linecap="round"/><circle class="nf-skin" cx="186" cy="112" r="8"/></g>'
    . '<path class="nf-coat" d="M60 160 Q48 196 56 212" fill="none" stroke-width="14" stroke-linecap="round"/>'
    . '</g>'
    . '<g class="nf-think"><circle cx="186" cy="48" r="16"/><text x="186" y="55" text-anchor="middle">' . $g . '</text></g>'
    . '</svg>';
}

/** Full-layout error page (uses site header/footer). Needs the database. */
function render_error_layout(int $code): void {
    [$title, $lead] = error_messages()[$code] ?? error_messages()[500];
    page_start(['title' => $title, 'robots' => 'noindex, nofollow']);
    echo '<section class="section nf"><div class="wrap nf-wrap">'
       . '<div class="nf-art" aria-hidden="true">' . error_art_svg($code === 404 ? '?' : '!') . '</div>'
       . '<p class="nf-code">' . h(bn_num($code)) . '</p>'
       . '<h1 class="display nf-title">' . h($title) . '</h1>'
       . '<p class="nf-lead">' . h($lead) . '</p>'
       . '<p class="nf-actions"><a class="btn btn-primary" href="/">হোমে ফিরুন</a>'
       . '<a class="btn btn-outline" href="/appointment">অ্যাপয়েন্টমেন্ট নিন</a>'
       . '<a class="btn btn-outline" href="/blog">ব্লগ দেখুন</a></p>'
       . '</div></section>';
    page_end();
}

/** Standalone error page: no database, no includes. Used when the layout itself cannot render. */
function render_error_standalone(int $code): void {
    [$title, $lead] = error_messages()[$code] ?? error_messages()[500];
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>' . h($title) . '</title>'
       . '<style>body{margin:0;background:#fdf5f9;color:#3b1d2a;font-family:system-ui,"Noto Sans Bengali",sans-serif;text-align:center;padding:2.5rem 16px}'
       . '.nf-art{width:min(240px,70vw);margin:0 auto}.nf-art svg{width:100%;height:auto}'
       . '.nf-blob{fill:#fbe3ec}.nf-coat{fill:#fff;stroke:#e9c9d8;stroke-width:2}.nf-coat-line{stroke:#e9c9d8;stroke-width:2}'
       . '.nf-skin{fill:#f3c9b0}.nf-hair{fill:#3b1d2a}.nf-smile{fill:none;stroke:#7a3b57;stroke-width:2.4;stroke-linecap:round}'
       . '.nf-cheek{fill:#f2a7bf;opacity:.55}.nf-steth{fill:none;stroke:#801b4b;stroke-width:3;stroke-linecap:round}.nf-steth-end{fill:#801b4b}'
       . '.nf-heart{fill:#e0457b}.nf-eyes ellipse{fill:#2a1020}.nf-think{fill:#fff;stroke:#e9c9d8;stroke-width:2}.nf-think text{fill:#801b4b;font-size:20px;font-weight:700}'
       . '.nf-code{font-size:3.2rem;font-weight:700;color:#801b4b;margin:.6rem 0 0}h1{font-size:1.5rem;margin:.3rem 0}'
       . 'p{line-height:1.7;color:#6b5560;max-width:460px;margin:.4rem auto}a.b{display:inline-block;margin:1rem .3rem 0;padding:.7rem 1.2rem;border-radius:999px;background:#801b4b;color:#fff;text-decoration:none;font-weight:600}'
       . '@media (prefers-reduced-motion:no-preference){.nf-bob{animation:b 3.6s ease-in-out infinite}.nf-wave{transform-origin:160px 152px;animation:w 2.4s ease-in-out infinite}@keyframes b{50%{transform:translateY(-5px)}}@keyframes w{50%{transform:rotate(-14deg)}}}'
       . '</style></head><body>'
       . '<div class="nf-art" aria-hidden="true">' . error_art_svg($code === 404 ? '?' : '!') . '</div>'
       . '<p class="nf-code">' . h((string) $code) . '</p>'
       . '<h1>' . h($title) . '</h1><p>' . h($lead) . '</p>'
       . '<a class="b" href="/">হোমে ফিরুন</a>'
       . '</body></html>';
}

/** Show an error page: full layout when possible, otherwise the standalone page. Never returns. */
function render_error(int $code): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($code === 404 && function_exists('log_404')) {
        try { log_404(); } catch (Throwable $e) { /* ignore */ }
    }
    ob_start();
    try {
        render_error_layout($code);
        ob_end_flush();
    } catch (Throwable $e) {
        error_log('[jebu] error page layout failed: ' . $e->getMessage());
        ob_end_clean();
        render_error_standalone($code);
    }
    exit;
}

/** Keeps the old name used by 404.php and the router. */
function render_404(): void {
    render_error(404);
}
