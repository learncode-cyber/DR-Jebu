<?php
declare(strict_types=1);

/**
 * Rich-text content (static pages and any future long-form field).
 * The stored value is sanitised with an allow-list on every save AND every render.
 * The browser editor is only a convenience; this file is the source of truth.
 */

const RICH_ALLOWED_TAGS = [
    'p' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'blockquote' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1,
    'strong' => 1, 'em' => 1, 'u' => 1, 'b' => 1, 'i' => 1, 's' => 1, 'sub' => 1, 'sup' => 1,
    'br' => 1, 'hr' => 1, 'a' => 1, 'img' => 1, 'figure' => 1, 'figcaption' => 1, 'span' => 1, 'div' => 1,
    'table' => 1, 'thead' => 1, 'tbody' => 1, 'tr' => 1, 'th' => 1, 'td' => 1,
];
// removed together with everything inside them
const RICH_DROP_TAGS = [
    'script' => 1, 'style' => 1, 'link' => 1, 'meta' => 1, 'object' => 1, 'embed' => 1, 'form' => 1,
    'input' => 1, 'button' => 1, 'textarea' => 1, 'select' => 1, 'svg' => 1, 'math' => 1,
    'noscript' => 1, 'template' => 1, 'iframe' => 1, 'frame' => 1, 'frameset' => 1,
];
const RICH_STYLE_PROPS = ['text-align' => 1, 'color' => 1, 'background-color' => 1, 'font-size' => 1, 'font-weight' => 1, 'text-decoration' => 1];

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
    if ($plain === '' && !str_contains($out, '<img')) {
        return '';
    }
    return $out;
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
        rich_clean_children($child);
    }
}

function rich_clean_attributes(DOMElement $el, string $tag): void {
    $keep = [];
    if ($tag === 'a') {
        $keep = ['href', 'target', 'rel'];
    } elseif ($tag === 'img') {
        $keep = ['src', 'alt', 'width', 'height'];
    } elseif (in_array($tag, ['p', 'h2', 'h3', 'h4', 'span', 'div', 'td', 'th', 'blockquote', 'li', 'figure'], true)) {
        $keep = ['style'];
    }
    foreach (iterator_to_array($el->attributes) as $attr) {
        $name = strtolower($attr->nodeName);
        $val = (string) $attr->nodeValue;
        if (!in_array($name, $keep, true)) {
            $el->removeAttribute($attr->nodeName);
            continue;
        }
        if ($name === 'style') {
            $clean = rich_clean_style($val);
            $clean === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $clean);
        }
    }
    if ($tag === 'a') {
        $href = trim((string) $el->getAttribute('href'));
        if (!rich_safe_href($href)) {
            // a link without a safe address is just text
            $el->removeAttribute('href');
            $el->removeAttribute('target');
            $el->removeAttribute('rel');
            $el->parentNode?->replaceChild($el->ownerDocument->createTextNode($el->textContent), $el);
            return;
        } else {
            $el->setAttribute('href', $href);
        }
        if ($el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        } else {
            $el->removeAttribute('target');
            $el->removeAttribute('rel');
        }
    }
    if ($tag === 'img') {
        $src = trim((string) $el->getAttribute('src'));
        // only images uploaded through the admin, and only if the file still exists
        if (!preg_match('#^/uploads/[A-Za-z0-9._-]+$#', $src) || !is_file(WEB_ROOT . $src)) {
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
    }
}

function rich_safe_href(string $href): bool {
    if ($href === '' || strlen($href) > 500) {
        return false;
    }
    if (str_starts_with($href, '//')) {
        return false;
    }
    return (bool) preg_match('#^(https://|http://|mailto:|tel:|/(?!/)|\#)#i', $href);
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
    return rich_sanitize($html);
}

/** Admin editor markup (the textarea named $name receives the HTML on submit). */
function rich_editor_html(string $name, string $value, string $label, string $csrf): string {
    $clean = rich_sanitize($value);
    $ph = 'এখানে লেখা শুরু করুন… (অনুচ্ছেদে Enter, বোল্ড/ইটালিক/লিংক ও ছবি উপরের বার থেকে)';
    return '<div class="rich" data-rich data-upload="' . h('/' . ADMIN_PATH_URL() . '/rich-upload.php') . '" data-csrf="' . h($csrf) . '">'
        . '<div class="rich-bar" role="toolbar" aria-label="ফরম্যাট বার">'
        . '<button type="button" data-cmd="undo" title="আগের অবস্থায় ফিরুন (Ctrl+Z)" aria-label="আনডু">↶</button>'
        . '<button type="button" data-cmd="redo" title="আবার করুন (Ctrl+Y)" aria-label="রিডু">↷</button>'
        . '<span class="sep"></span>'
        . '<select data-block aria-label="অনুচ্ছেদের ধরন" title="অনুচ্ছেদের ধরন">'
        . '<option value="p">অনুচ্ছেদ</option><option value="h2">শিরোনাম ২</option><option value="h3">শিরোনাম ৩</option><option value="h4">শিরোনাম ৪</option><option value="blockquote">উদ্ধৃতি</option></select>'
        . '<span class="sep"></span>'
        . '<button type="button" data-cmd="bold" title="বোল্ড (Ctrl+B)" aria-label="বোল্ড"><b>B</b></button>'
        . '<button type="button" data-cmd="italic" title="ইটালিক (Ctrl+I)" aria-label="ইটালিক"><i>I</i></button>'
        . '<button type="button" data-cmd="underline" title="আন্ডারলাইন (Ctrl+U)" aria-label="আন্ডারলাইন"><u>U</u></button>'
        . '<span class="sep"></span>'
        . '<button type="button" data-cmd="insertUnorderedList" title="বুলেট তালিকা" aria-label="বুলেট তালিকা">• তালিকা</button>'
        . '<button type="button" data-cmd="insertOrderedList" title="ক্রমিক তালিকা" aria-label="ক্রমিক তালিকা">১. তালিকা</button>'
        . '<span class="sep"></span>'
        . '<button type="button" data-act="link" title="লিংক যোগ (Ctrl+K)" aria-label="লিংক যোগ করুন">🔗 লিংক</button>'
        . '<button type="button" data-cmd="unlink" title="লিংক সরান" aria-label="লিংক সরান">লিংক সরান</button>'
        . '<button type="button" data-act="image" title="ছবি আপলোড" aria-label="ছবি আপলোড করুন">🖼 ছবি</button>'
        . '<button type="button" data-cmd="insertHorizontalRule" title="বিভাজক রেখা" aria-label="বিভাজক রেখা">—</button>'
        . '<button type="button" data-cmd="removeFormat" title="ফরম্যাট মুছুন" aria-label="ফরম্যাট মুছুন">ফরম্যাট মুছুন</button>'
        . '<span class="sep"></span>'
        . '<button type="button" data-act="code" aria-pressed="false" title="HTML কোড দেখুন" aria-label="HTML কোড">&lt;/&gt; কোড</button>'
        . '</div>'
        . '<div class="rich-canvas" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' . h($label) . '" data-placeholder="' . h($ph) . '">' . $clean . '</div>'
        . '<textarea class="rich-code" hidden rows="14" aria-label="HTML কোড (সম্পাদনা)"></textarea>'
        . '<textarea name="' . h($name) . '" hidden>' . h($clean) . '</textarea>'
        . '<div class="rich-foot"><span class="rich-count" aria-live="polite"></span><span class="rich-msg" aria-live="polite"></span></div>'
        . '<div class="rich-pop" hidden></div>'
        . '<input type="file" class="rich-file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>'
        . '</div>';
}
