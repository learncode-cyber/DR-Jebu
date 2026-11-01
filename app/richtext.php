<?php
declare(strict_types=1);

/**
 * Rich-text content for blog posts, static pages and any long-form field.
 *
 * Stored value = RICH_MARK + sanitised HTML. Every save AND every render passes through the
 * allow-list below (the browser editor is only a convenience). Rows without the marker are
 * read the way they were before: Markdown for old blog posts, HTML or plain text for pages.
 */

const RICH_MARK = '<!--cqblocks-->';
const RICH_MAX_STORED = 60000;      // bytes; the form shows a friendly message above this
const RICH_VIDEO_EMBED = '#^(https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{6,20}(\?[A-Za-z0-9_=&%.-]{0,120})?|https://player\.vimeo\.com/video/\d{5,15})$#';
const RICH_IMAGE_EXTERNAL = '#^https://[^\s"\'<>]{4,500}$#';

const RICH_ALLOWED_TAGS = [
    'p' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'blockquote' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1,
    'strong' => 1, 'em' => 1, 'u' => 1, 'b' => 1, 'i' => 1, 's' => 1, 'sub' => 1, 'sup' => 1,
    'br' => 1, 'hr' => 1, 'a' => 1, 'img' => 1, 'figure' => 1, 'figcaption' => 1, 'span' => 1, 'div' => 1,
    'table' => 1, 'thead' => 1, 'tbody' => 1, 'tr' => 1, 'th' => 1, 'td' => 1,
    'pre' => 1, 'code' => 1, 'iframe' => 1,
];
// removed together with everything inside them
const RICH_DROP_TAGS = [
    'script' => 1, 'style' => 1, 'link' => 1, 'meta' => 1, 'object' => 1, 'embed' => 1, 'form' => 1,
    'input' => 1, 'button' => 1, 'textarea' => 1, 'select' => 1, 'svg' => 1, 'math' => 1,
    'noscript' => 1, 'template' => 1, 'frame' => 1, 'frameset' => 1,
];
const RICH_STYLE_PROPS = [
    'text-align' => 1, 'color' => 1, 'background-color' => 1, 'font-size' => 1, 'font-weight' => 1,
    'text-decoration' => 1, 'margin' => 1, 'padding' => 1, 'border-radius' => 1,
];
const RICH_CQ_BLOCKS = ['gallery' => 1, 'table' => 1, 'video' => 1];

/** Sanitise any HTML string with the allow-list. Returns clean HTML ('' when there is no real content). */
function rich_sanitize(string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $html = mb_substr($html, 0, 120000);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="cqroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = (new DOMXPath($doc))->query('//div[@id="cqroot"]')->item(0);
    if (!$root) {
        return '';
    }
    rich_clean_children($root);
    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    $out = trim($out);
    // nothing but empty paragraphs = empty content (lets the form's "required" rule work)
    $plain = trim(str_replace("\xc2\xa0", ' ', strip_tags($out)));
    if ($plain === '' && !preg_match('/<(img|iframe|table|hr)\b/i', $out)) {
        return '';
    }
    return $out;
}

/** Value to store: marker + sanitised HTML, or '' for empty content. */
function rich_store(string $html): string {
    $clean = rich_sanitize($html);
    return $clean === '' ? '' : RICH_MARK . $clean;
}

function rich_clean_children(DOMNode $node): void {
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            $node->removeChild($child); // comments, processing instructions
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (isset(RICH_DROP_TAGS[$tag])) {
            $node->removeChild($child);
            continue;
        }
        if (!isset(RICH_ALLOWED_TAGS[$tag])) {
            rich_clean_children($child);
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }
        rich_clean_attributes($child, $tag);
        if ($child->parentNode === null) {
            continue; // the attribute step removed this element (bad image or video source)
        }
        if ($tag === 'iframe') {
            while ($child->firstChild) {
                $child->removeChild($child->firstChild); // an iframe never holds content
            }
            continue;
        }
        rich_clean_children($child);
    }
}

function rich_clean_attributes(DOMElement $el, string $tag): void {
    $keep = [];
    if ($tag === 'a') {
        $keep = ['href', 'target', 'rel'];
    } elseif ($tag === 'img') {
        $keep = ['src', 'alt', 'width', 'height', 'srcset'];
    } elseif ($tag === 'iframe') {
        $keep = ['src', 'title', 'allowfullscreen', 'loading', 'referrerpolicy'];
    } elseif ($tag === 'div') {
        $keep = ['style', 'data-cq', 'data-cq-cols'];
    } elseif (in_array($tag, ['p', 'h2', 'h3', 'h4', 'span', 'td', 'th', 'blockquote', 'li', 'pre', 'figure'], true)) {
        $keep = ['style'];
    }
    foreach (iterator_to_array($el->attributes) as $attr) {
        $name = strtolower($attr->nodeName);
        if (!in_array($name, $keep, true)) {
            $el->removeAttribute($attr->nodeName);
            continue;
        }
        if ($name === 'style') {
            $clean = rich_clean_style((string) $attr->nodeValue);
            $clean === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $clean);
        }
    }

    if ($tag === 'a') {
        $href = trim((string) $el->getAttribute('href'));
        if (!rich_safe_href($href)) {
            // a link without a safe address is just text
            $text = $el->ownerDocument->createTextNode($el->textContent);
            $el->parentNode?->replaceChild($text, $el);
            return;
        }
        $el->setAttribute('href', $href);
        if ($el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        } else {
            $el->removeAttribute('target');
            $el->removeAttribute('rel');
        }
        return;
    }

    if ($tag === 'img') {
        $src = trim((string) $el->getAttribute('src'));
        if (!rich_image_src_ok($src)) {
            // only https images or uploads that still exist on disk (no broken images)
            $el->parentNode?->removeChild($el);
            return;
        }
        $el->setAttribute('src', $src);
        $el->setAttribute('loading', 'lazy');
        $el->setAttribute('decoding', 'async');
        $el->setAttribute('alt', mb_substr((string) $el->getAttribute('alt'), 0, 300));
        foreach (['width', 'height'] as $dim) {
            if ($el->hasAttribute($dim) && !ctype_digit((string) $el->getAttribute($dim))) {
                $el->removeAttribute($dim);
            }
        }
        if ($el->hasAttribute('srcset') && !rich_srcset_ok((string) $el->getAttribute('srcset'))) {
            $el->removeAttribute('srcset');
        }
        return;
    }

    if ($tag === 'iframe') {
        $src = trim((string) $el->getAttribute('src'));
        if (preg_match(RICH_VIDEO_EMBED, $src) !== 1) {
            $el->parentNode?->removeChild($el);
            return;
        }
        $el->setAttribute('src', $src);
        $el->setAttribute('loading', 'lazy');
        $el->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        $el->setAttribute('allowfullscreen', 'allowfullscreen');
        $el->setAttribute('title', mb_substr((string) $el->getAttribute('title'), 0, 200) ?: 'ভিডিও');
        return;
    }

    if ($tag === 'div' && $el->hasAttribute('data-cq')) {
        $kind = (string) $el->getAttribute('data-cq');
        if (!isset(RICH_CQ_BLOCKS[$kind])) {
            $el->removeAttribute('data-cq');
            $el->removeAttribute('data-cq-cols');
            return;
        }
        if ($kind === 'gallery') {
            $cols = (string) $el->getAttribute('data-cq-cols');
            $el->setAttribute('data-cq-cols', in_array($cols, ['2', '3'], true) ? $cols : '2');
        } else {
            $el->removeAttribute('data-cq-cols');
        }
    } elseif ($tag === 'div') {
        $el->removeAttribute('data-cq-cols');
    }
}

/** Link targets: https, http, mailto, tel, site-relative paths and #anchors. */
function rich_safe_href(string $href): bool {
    if ($href === '' || strlen($href) > 500) {
        return false;
    }
    if (str_starts_with($href, '//')) {
        return false;
    }
    return (bool) preg_match('#^(https://|http://|mailto:|tel:|/(?!/)|\#)#i', $href);
}

function rich_image_src_ok(string $src): bool {
    if (preg_match('#^/uploads/[A-Za-z0-9._-]+$#', $src) === 1) {
        return is_file(WEB_ROOT . $src);
    }
    return preg_match(RICH_IMAGE_EXTERNAL, $src) === 1;
}

/** srcset: "/uploads/a-800.webp 800w, /uploads/a-1600.webp 1600w" (local files only). */
function rich_srcset_ok(string $set): bool {
    if (strlen($set) > 400) {
        return false;
    }
    foreach (explode(',', $set) as $part) {
        if (preg_match('#^\s*(/uploads/[A-Za-z0-9._-]+)\s+(\d{2,4})w\s*$#', $part, $m) !== 1 || !is_file(WEB_ROOT . $m[1])) {
            return false;
        }
    }
    return true;
}

function rich_clean_style(string $style): string {
    $out = [];
    foreach (explode(';', $style) as $decl) {
        if (!str_contains($decl, ':')) {
            continue;
        }
        [$prop, $value] = array_map('trim', explode(':', $decl, 2));
        $prop = strtolower($prop);
        if (!isset(RICH_STYLE_PROPS[$prop]) || $value === '' || strlen($value) > 60) {
            continue;
        }
        if (!preg_match('/^[#a-zA-Z0-9\s.,%()\-]*$/', $value) || preg_match('/url\(|expression\(|javascript:|@import/i', $value)) {
            continue;
        }
        $out[] = $prop . ':' . $value;
    }
    return implode(';', $out);
}

/** Public output: sanitised again at render time, so old rows stay safe too. */
function rich_render(string $html): string {
    return rich_sanitize(str_starts_with($html, RICH_MARK) ? substr($html, strlen(RICH_MARK)) : $html);
}

/** Blog body for the public page: new rich rows as HTML, old rows through the Markdown subset. */
function blog_body_html(string $raw): string {
    return str_starts_with($raw, RICH_MARK) ? rich_render($raw) : md($raw);
}

/**
 * Turns any stored value into HTML for the editor.
 * $markdown = true for old blog posts written in Markdown (rows without the marker).
 */
function rich_prepare_for_editor(string $raw, bool $markdown = false): string {
    if (str_starts_with($raw, RICH_MARK)) {
        return substr($raw, strlen(RICH_MARK));
    }
    if (preg_match('/<\/?[a-z][^>]*>/i', $raw) === 1) {
        return $raw; // already HTML (pages and earlier rich rows)
    }
    if ($markdown) {
        return md($raw);
    }
    $out = '';
    foreach (preg_split('/\R{2,}/u', trim($raw)) ?: [] as $para) {
        if (trim($para) !== '') {
            $out .= '<p>' . nl2br(h(trim($para)), false) . '</p>';
        }
    }
    return $out;
}

/**
 * Question-and-answer pairs for FAQPage schema: a heading or paragraph ending with "?" followed by its answer paragraph.
 * Only text that is visible on the page is returned.
 * @return list<array{q:string,a:string}>
 */
function rich_faq_pairs(string $html): array {
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="cqroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = (new DOMXPath($doc))->query('//div[@id="cqroot"]')->item(0);
    if (!$root) {
        return [];
    }
    $pairs = [];
    $nodes = [];
    foreach ($root->childNodes as $n) {
        if ($n instanceof DOMElement) {
            $nodes[] = $n;
        }
    }
    foreach ($nodes as $i => $el) {
        $q = trim(preg_replace('/\s+/u', ' ', $el->textContent) ?? '');
        if (!in_array(strtolower($el->nodeName), ['p', 'h2', 'h3', 'h4'], true) || !str_ends_with($q, '?') || mb_strlen($q) > 220) {
            continue;
        }
        $next = $nodes[$i + 1] ?? null;
        if ($next instanceof DOMElement && strtolower($next->nodeName) === 'p') {
            $a = trim(preg_replace('/\s+/u', ' ', $next->textContent) ?? '');
            if ($a !== '') {
                $pairs[] = ['q' => $q, 'a' => trim_text($a, 600)];
            }
        }
    }
    return $pairs;
}

/** Internal link suggestions for the link popover: site pages, services, conditions and blog posts. */
function rich_link_suggestions(): array {
    $sources = [
        ['blog', "SELECT title, slug FROM blog_posts WHERE status = 'published' AND published_at <= NOW() ORDER BY published_at DESC LIMIT 150"],
        ['pages', "SELECT title, slug FROM pages WHERE status = 'published' ORDER BY menu_order, id LIMIT 100"],
        ['services', 'SELECT title, slug FROM services WHERE is_active = 1 ORDER BY sort_order, id LIMIT 100'],
        ['conditions', 'SELECT title, slug FROM conditions WHERE is_active = 1 ORDER BY sort_order, id LIMIT 100'],
        ['treatments', 'SELECT title, slug FROM treatments WHERE is_active = 1 ORDER BY sort_order, id LIMIT 100'],
        ['procedures', 'SELECT title, slug FROM procedures WHERE is_active = 1 ORDER BY sort_order, id LIMIT 100'],
    ];
    $out = [];
    foreach ($sources as [$key, $sql]) {
        try {
            foreach (q_all($sql) as $r) {
                $out[] = ['t' => (string) $r['title'], 'u' => entity_url($key, (string) $r['slug']), 'k' => $key];
            }
        } catch (Throwable $e) {
            error_log('[jebu] link suggestions ' . $key . ': ' . $e->getMessage());
        }
    }
    return $out;
}

/** Admin editor markup. The hidden textarea $name receives the marker + HTML on submit. */
function rich_editor_html(string $name, string $value, string $label, string $csrf): string {
    $clean = rich_sanitize($value);
    $links = json_encode(rich_link_suggestions(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
    $ph = 'এখানে লেখা শুরু করুন… অনুচ্ছেদে Enter, ফরম্যাট লাগাতে লেখাটি সিলেক্ট করুন।';
    return '<div class="rich" data-rich data-upload="' . h('/' . ADMIN_PATH_URL() . '/rich-upload.php') . '" data-csrf="' . h($csrf)
        . '" data-links="' . h($links) . '" data-locale="bn" data-max="' . RICH_MAX_STORED . '">'
        . '<div class="rich-canvas" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' . h($label) . '" data-placeholder="' . h($ph) . '">' . $clean . '</div>'
        . '<textarea name="' . h($name) . '" hidden>' . h($clean === '' ? '' : RICH_MARK . $clean) . '</textarea>'
        . '<noscript><p class="muted">এই এডিটর চালাতে JavaScript লাগবে। এখন HTML সরাসরি সম্পাদনা করতে পারবেন না।</p></noscript>'
        . '</div>';
}
