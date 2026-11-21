/* Rich-text editor for admin content. Single flow (no boxes), floating format bar, hover insert controls.
 * No runtime dependencies. The server sanitises every save and every render; the cleaner here only keeps the DOM tidy.
 * Stored value = '<!--cqblocks-->' + HTML (the marker is added by the server). */
(function () {
  'use strict';

  var MARK = '<!--cqblocks-->';
  var MAX_MB = 4;
  var BLOCK_TAGS = { P: 1, H2: 1, H3: 1, H4: 1, BLOCKQUOTE: 1, UL: 1, OL: 1, DIV: 1, FIGURE: 1, TABLE: 1, HR: 1, PRE: 1 };
  var ALLOWED = { p: 1, h2: 1, h3: 1, h4: 1, blockquote: 1, ul: 1, ol: 1, li: 1, strong: 1, em: 1, u: 1, b: 1, i: 1, s: 1,
    sub: 1, sup: 1, br: 1, hr: 1, a: 1, img: 1, figure: 1, figcaption: 1, span: 1, div: 1, table: 1, thead: 1, tbody: 1,
    tr: 1, th: 1, td: 1, pre: 1, code: 1, iframe: 1 };
  var DROP = { script: 1, style: 1, link: 1, meta: 1, object: 1, embed: 1, form: 1, input: 1, button: 1, textarea: 1,
    select: 1, svg: 1, math: 1, noscript: 1, template: 1, frame: 1, frameset: 1 };
  var STYLE_PROP = /^(text-align|color|background-color|font-size|font-weight|text-decoration|margin|padding|border-radius)$/;
  var STYLE_VALUE = /^[#a-zA-Z0-9\s.,%()\-]*$/;
  var SAFE_HREF = /^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i;
  var DOMAIN = /^[\w.-]+\.[a-z]{2,}(\/\S*)?$/i;
  var IMG_EXTERNAL = /^https:\/\/[^\s"'<>]{4,500}$/;
  var IMG_LOCAL = /^\/uploads\/[A-Za-z0-9._-]+$/;
  var VIDEO_OK = /^(https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{6,20}(\?[A-Za-z0-9_=&%.-]{0,120})?|https:\/\/player\.vimeo\.com\/video\/\d{5,15})$/;

  var TXT = {
    bn: {
      undo: 'আগের অবস্থায় ফিরুন', redo: 'আবার করুন', visual: 'লেখা', code: 'HTML কোড',
      block: 'অনুচ্ছেদের ধরন', para: 'অনুচ্ছেদ', h2: 'শিরোনাম ২', h3: 'শিরোনাম ৩', h4: 'শিরোনাম ৪', quote: 'উদ্ধৃতি',
      bold: 'বোল্ড', italic: 'ইটালিক', underline: 'আন্ডারলাইন', strike: 'কাটা', textColor: 'লেখার রং', highlight: 'হাইলাইট রং',
      size: 'লেখার আকার', alignL: 'বাম', alignC: 'মাঝখানে', alignR: 'ডান', alignJ: 'দুই পাশে',
      bullets: 'বুলেট তালিকা', numbers: 'ক্রমিক তালিকা', outdent: 'বাইরে আনুন', indent: 'ভেতরে নিন',
      link: 'লিংক', unlink: 'লিংক সরান', image: 'ছবি যোগ করুন', table: 'টেবিল যোগ করুন', tableOpt: 'টেবিলের অপশন',
      video: 'ভিডিও যোগ করুন', divider: 'বিভাজক রেখা', clear: 'ফরম্যাট মুছুন',
      linkPh: 'URL পেস্ট করুন অথবা পেজের নাম লিখুন', newTab: 'নতুন ট্যাবে খুলুন', apply: 'প্রয়োগ', remove: 'মুছুন',
      cancel: 'বাতিল', alt: 'ছবির বিবরণ (Alt text)', altMissing: 'ছবির বিবরণ (Alt) লিখলে SEO ও স্ক্রিন রিডারের জন্য ভালো হয়।',
      uploading: 'ছবি আপলোড হচ্ছে', uploaded: 'ছবি যোগ হয়েছে', uploadFail: 'ছবি আপলোড হয়নি',
      tooBig: 'ছবি ৪MB-এর বেশি। ছোট করে দিন।', badType: 'শুধু JPG, PNG, WEBP বা GIF ছবি দেওয়া যাবে।',
      badUrl: 'সঠিক লিংক লিখুন (https://…, mailto:, tel:, /পাথ বা #অংশ)।', videoPh: 'YouTube বা Vimeo লিংক পেস্ট করুন',
      badVideo: 'শুধু YouTube বা Vimeo লিংক যোগ করা যাবে।', words: 'শব্দ', chars: 'অক্ষর',
      codeErr: 'HTML কোডে সমস্যা আছে। আগের লেখাটি রাখা হয়েছে।', noSel: 'আগে লেখাটির উপর ক্লিক করুন।',
      rowAbove: 'উপরে সারি', rowBelow: 'নিচে সারি', colLeft: 'বামে কলাম', colRight: 'ডানে কলাম',
      delRow: 'সারি মুছুন', delCol: 'কলাম মুছুন', header: 'শিরোনাম সারি চালু/বন্ধ', delTable: 'টেবিল মুছুন',
      pick: 'টেবিলের আকার বাছুন', pasted: 'লেখা পেস্ট হয়েছে', rateLimit: 'অনেক বেশি আপলোড হয়েছে। একটু পরে আবার চেষ্টা করুন।',
      placeholder: 'এখানে লেখা শুরু করুন… অনুচ্ছেদে Enter, ফরম্যাট লাগাতে লেখাটি সিলেক্ট করুন।'
    }
  };

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function bnNum(n) { return String(n).replace(/\d/g, function (d) { return '০১২৩৪৫৬৭৮৯'[+d]; }); }
  function el(tag, attrs, html) {
    var e = document.createElement(tag);
    if (attrs) { Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); }); }
    if (html !== undefined) { e.innerHTML = html; }
    return e;
  }

  /* ---------- URL and HTML helpers ---------- */
  function toURL(raw) {
    var v = (raw || '').trim();
    if (!v || v.length > 500) { return null; }
    if (/^javascript:/i.test(v) || /^\/\//.test(v)) { return null; }
    if (SAFE_HREF.test(v)) { return v; }
    if (DOMAIN.test(v)) { return 'https://' + v; }
    return null;
  }

  function videoEmbed(raw) {
    var v = (raw || '').trim();
    var m = v.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{6,20})/);
    if (m) { return 'https://www.youtube-nocookie.com/embed/' + m[1]; }
    m = v.match(/vimeo\.com\/(?:video\/)?(\d{5,15})/);
    if (m) { return 'https://player.vimeo.com/video/' + m[1]; }
    return null;
  }

  function cleanStyle(style) {
    var out = [];
    (style || '').split(';').forEach(function (d) {
      var i = d.indexOf(':');
      if (i < 0) { return; }
      var p = d.slice(0, i).trim().toLowerCase(), v = d.slice(i + 1).trim();
      if (!STYLE_PROP.test(p) || !v || v.length > 60 || !STYLE_VALUE.test(v) || /url\(|expression\(|javascript:|@import/i.test(v)) { return; }
      out.push(p + ':' + v);
    });
    return out.join(';');
  }

  /* Client cleaner for paste and the Code tab. Parsed in an inert document: no image loads, no scripts. */
  function cleanHTML(html) {
    var inert = new DOMParser().parseFromString('<!doctype html><body>' + (html || ''), 'text/html');
    var out = document.implementation.createHTMLDocument('').createElement('div');
    walk(inert.body, out, out.ownerDocument);
    return out.innerHTML;
  }
  function walk(src, dst, doc) {
    Array.prototype.slice.call(src.childNodes).forEach(function (n) {
      if (n.nodeType === 3) { dst.appendChild(doc.createTextNode(n.nodeValue)); return; }
      if (n.nodeType !== 1) { return; }
      var tag = n.nodeName.toLowerCase();
      if (DROP[tag]) { return; }
      if (!ALLOWED[tag]) { walk(n, dst, doc); return; }
      if (tag === 'a') {
        var href = (n.getAttribute('href') || '').trim();
        if (!SAFE_HREF.test(href) || href.length > 500 || /^\/\//.test(href)) { walk(n, dst, doc); return; }
      }
      if (tag === 'img') {
        var src2 = (n.getAttribute('src') || '').trim();
        if (!IMG_EXTERNAL.test(src2) && !IMG_LOCAL.test(src2)) { return; }
      }
      if (tag === 'iframe') {
        var fs = (n.getAttribute('src') || '').trim();
        if (!VIDEO_OK.test(fs)) { return; }
      }
      var e = doc.createElement(tag);
      copyAttrs(n, e, tag);
      if (tag !== 'img' && tag !== 'iframe') { walk(n, e, doc); }
      dst.appendChild(e);
    });
  }
  function copyAttrs(src, dst, tag) {
    var keep = {
      a: ['href', 'target'], img: ['src', 'alt', 'width', 'height', 'srcset'],
      iframe: ['src', 'title', 'loading', 'referrerpolicy'], div: ['style', 'data-cq', 'data-cq-cols']
    }[tag] || ['style'];
    keep.forEach(function (name) {
      if (!src.hasAttribute(name)) { return; }
      var v = src.getAttribute(name);
      if (name === 'style') { v = cleanStyle(v); if (!v) { return; } }
      if (name === 'target' && v !== '_blank') { return; }
      if ((name === 'width' || name === 'height') && !/^\d+$/.test(v)) { return; }
      if (name === 'data-cq' && !{ gallery: 1, table: 1, video: 1 }[v]) { return; }
      if (name === 'data-cq-cols' && !{ '2': 1, '3': 1 }[v]) { return; }
      if (name === 'alt') { v = v.slice(0, 300); }
      if (name === 'srcset' && !/^(\s*\/uploads\/[A-Za-z0-9._-]+\s+\d{2,4}w\s*)(,\s*\/uploads\/[A-Za-z0-9._-]+\s+\d{2,4}w\s*)*$/.test(v)) { return; }
      dst.setAttribute(name, v);
    });
    if (tag === 'a' && dst.getAttribute('target') === '_blank') { dst.setAttribute('rel', 'noopener noreferrer'); }
    if (tag === 'img') { dst.setAttribute('loading', 'lazy'); dst.setAttribute('decoding', 'async'); }
    if (tag === 'div' && dst.getAttribute('data-cq') !== 'gallery') { dst.removeAttribute('data-cq-cols'); }
  }

  /* ---------- Mount ---------- */
  function mount(root) {
    var canvas = $('.rich-canvas', root);
    var hidden = $('textarea[name]', root);
    var form = root.closest('form');
    var locale = TXT[root.getAttribute('data-locale')] ? root.getAttribute('data-locale') : 'bn';
    var T = TXT[locale];
    var uploadUrl = root.getAttribute('data-upload');
    var csrf = root.getAttribute('data-csrf');
    var links = [];
    try { links = JSON.parse(root.getAttribute('data-links') || '[]'); } catch (e) { links = []; }
    var maxChars = parseInt(root.getAttribute('data-max') || '60000', 10);
    var saved = null, codeMode = false, syncTimer = null, popOpen = null, hoverBlock = null, hoverTimer = null, hoverLeaveTimer = null;
    var anchorForFile = null;
    var lastValid = '';
    var hist = [], hi = -1, histTimer = null;

    try {
      document.execCommand('defaultParagraphSeparator', false, 'p');
      document.execCommand('styleWithCSS', false, true);
    } catch (e) { /* older browsers: defaults are fine */ }
    canvas.setAttribute('data-placeholder', T.placeholder);
    canvas.style.position = 'relative';
    wrapLooseText(canvas);

    /* ---- top strip ---- */
    var strip = el('div', { 'class': 'rich-top', role: 'toolbar', 'aria-label': 'সম্পাদনার নিয়ন্ত্রণ' });
    strip.innerHTML = '<div class="rich-top-l">'
      + '<button type="button" data-top="undo" title="' + T.undo + ' (Ctrl+Z)" aria-label="' + T.undo + '">↶</button>'
      + '<button type="button" data-top="redo" title="' + T.redo + ' (Ctrl+Y)" aria-label="' + T.redo + '">↷</button></div>'
      + '<div class="rich-top-r" role="tablist">'
      + '<button type="button" role="tab" data-top="visual" aria-selected="true">' + T.visual + '</button>'
      + '<button type="button" role="tab" data-top="code" aria-selected="false">' + T.code + '</button></div>';
    root.insertBefore(strip, canvas);

    var code = el('textarea', { 'class': 'rich-code', hidden: 'hidden', rows: '16', 'aria-label': T.code, spellcheck: 'false' });
    root.insertBefore(code, hidden);

    var foot = el('div', { 'class': 'rich-foot' }, '<span class="rich-count" aria-live="polite"></span><span class="rich-msg" aria-live="polite"></span>');
    root.appendChild(foot);
    var count = $('.rich-count', foot), msg = $('.rich-msg', foot);

    var pop = el('div', { 'class': 'rich-pop', hidden: 'hidden', role: 'dialog', 'aria-modal': 'false' });
    root.appendChild(pop);
    var fileIn = el('input', { type: 'file', 'class': 'rich-file', accept: 'image/jpeg,image/png,image/webp,image/gif', multiple: 'multiple', hidden: 'hidden' });
    root.appendChild(fileIn);

    /* ---- floating format bar ---- */
    var bar = el('div', { 'class': 'rich-float', role: 'toolbar', 'aria-label': 'ফরম্যাট বার', hidden: 'hidden' });
    bar.innerHTML =
      '<select data-f="block" aria-label="' + T.block + '" title="' + T.block + '">'
      + '<option value="p">' + T.para + '</option><option value="h2">' + T.h2 + '</option><option value="h3">' + T.h3 + '</option>'
      + '<option value="h4">' + T.h4 + '</option><option value="blockquote">' + T.quote + '</option></select>'
      + '<span class="sep"></span>'
      + '<button type="button" data-f="bold" title="' + T.bold + ' (Ctrl+B)" aria-label="' + T.bold + '"><b>B</b></button>'
      + '<button type="button" data-f="italic" title="' + T.italic + ' (Ctrl+I)" aria-label="' + T.italic + '"><i>I</i></button>'
      + '<button type="button" data-f="underline" title="' + T.underline + ' (Ctrl+U)" aria-label="' + T.underline + '"><u>U</u></button>'
      + '<button type="button" data-f="strike" title="' + T.strike + '" aria-label="' + T.strike + '"><s>S</s></button>'
      + '<span class="sep"></span>'
      + '<label class="rich-color" title="' + T.textColor + '"><span aria-hidden="true">A</span><input type="color" data-f="fore" value="#2a1020" aria-label="' + T.textColor + '"></label>'
      + '<label class="rich-color" title="' + T.highlight + '"><span aria-hidden="true">▮</span><input type="color" data-f="hilite" value="#fff3a3" aria-label="' + T.highlight + '"></label>'
      + '<select data-f="size" aria-label="' + T.size + '" title="' + T.size + '">'
      + '<option value="14">ছোট</option><option value="16" selected>সাধারণ</option><option value="20">বড়</option>'
      + '<option value="26">আরও বড়</option><option value="34">সবচেয়ে বড়</option></select>'
      + '<span class="sep"></span>'
      + '<button type="button" data-f="alignLeft" title="' + T.alignL + '" aria-label="' + T.alignL + '">⇤</button>'
      + '<button type="button" data-f="alignCenter" title="' + T.alignC + '" aria-label="' + T.alignC + '">≡</button>'
      + '<button type="button" data-f="alignRight" title="' + T.alignR + '" aria-label="' + T.alignR + '">⇥</button>'
      + '<button type="button" data-f="alignJustify" title="' + T.alignJ + '" aria-label="' + T.alignJ + '">☰</button>'
      + '<span class="sep"></span>'
      + '<button type="button" data-f="ul" title="' + T.bullets + '" aria-label="' + T.bullets + '">• তালিকা</button>'
      + '<button type="button" data-f="ol" title="' + T.numbers + '" aria-label="' + T.numbers + '">১. তালিকা</button>'
      + '<button type="button" data-f="outdent" title="' + T.outdent + '" aria-label="' + T.outdent + '">⇠</button>'
      + '<button type="button" data-f="indent" title="' + T.indent + '" aria-label="' + T.indent + '">⇢</button>'
      + '<span class="sep"></span>'
      + '<button type="button" data-f="link" title="' + T.link + ' (Ctrl+K)" aria-label="' + T.link + '">🔗</button>'
      + '<button type="button" data-f="unlink" title="' + T.unlink + '" aria-label="' + T.unlink + '">⛓</button>'
      + '<button type="button" data-f="image" title="' + T.image + '" aria-label="' + T.image + '">🖼</button>'
      + '<button type="button" data-f="table" title="' + T.table + '" aria-label="' + T.table + '">▦</button>'
      + '<button type="button" data-f="tableopt" title="' + T.tableOpt + '" aria-label="' + T.tableOpt + '" disabled>▤</button>'
      + '<button type="button" data-f="video" title="' + T.video + '" aria-label="' + T.video + '">▶</button>'
      + '<button type="button" data-f="hr" title="' + T.divider + '" aria-label="' + T.divider + '">-</button>'
      + '<button type="button" data-f="clear" title="' + T.clear + '" aria-label="' + T.clear + '">Tx</button>';
    root.appendChild(bar);

    /* ---- hover insert controls (beside the line under the pointer) ---- */
    var hover = el('div', { 'class': 'rich-hover', role: 'toolbar', 'aria-label': 'নতুন যোগ করুন', hidden: 'hidden' });
    hover.innerHTML = '<span class="rich-plus" aria-hidden="true">+</span>'
      + '<button type="button" data-h="image" title="' + T.image + '" aria-label="' + T.image + '">🖼</button>'
      + '<button type="button" data-h="table" title="' + T.table + '" aria-label="' + T.table + '">▦</button>'
      + '<button type="button" data-h="video" title="' + T.video + '" aria-label="' + T.video + '">▶</button>'
      + '<button type="button" data-h="hr" title="' + T.divider + '" aria-label="' + T.divider + '">-</button>';
    root.appendChild(hover);

    /* ---------- helpers ---------- */
    function say(text, bad) { msg.textContent = text || ''; msg.className = 'rich-msg' + (bad ? ' bad' : ''); }

    function wrapLooseText(c) {
      Array.prototype.slice.call(c.childNodes).forEach(function (n) {
        if (n.nodeType === 3 && n.nodeValue.trim() !== '') {
          var p = document.createElement('p');
          c.insertBefore(p, n);
          p.appendChild(n);
        }
      });
    }

    function hasContent(c) {
      var txt = (c.textContent || '').replace(/ /g, ' ').trim();
      return txt !== '' || c.querySelector('img,iframe,table,hr') !== null;
    }

    function serialize() {
      var clone = canvas.cloneNode(true);
      $$('[contenteditable]', clone).forEach(function (n) { n.removeAttribute('contenteditable'); });
      $$('[class]', clone).forEach(function (n) { n.removeAttribute('class'); });
      if (!hasContent(clone)) { return ''; }
      return MARK + clone.innerHTML;
    }

    function plainLength(c) { return (c.textContent || '').replace(/ /g, ' ').trim().length; }

    function updateCount() {
      var t = (canvas.textContent || '').replace(/ /g, ' ').trim();
      var w = t ? t.split(/\s+/).length : 0;
      count.textContent = bnNum(w) + ' ' + T.words + ' · ' + bnNum(t.replace(/\s/g, '').length) + ' ' + T.chars;
    }

    function sync() {
      var html;
      if (codeMode) {
        var c = cleanHTML(code.value);
        var hasText = c.replace(/<[^>]*>/g, '').trim() !== '' || /<(img|iframe|table|hr)\b/i.test(c);
        html = hasText ? MARK + c : '';
      } else {
        html = serialize();
      }
      hidden.value = html;
      if (html.length > maxChars) { say('লেখাটি অনেক বড় (সীমা প্রায় ৬০ KB)।', true); }
      updateCount();
    }
    function later() { clearTimeout(syncTimer); syncTimer = setTimeout(sync, 200); scheduleSnap(); }

    /* undo / redo: snapshots of the whole document, so every action (typing, tables, uploads, video) can be undone */
    function snap() {
      if (codeMode) { return; }
      var h = canvas.innerHTML;
      if (hi >= 0 && hist[hi] === h) { return; }
      hist = hist.slice(0, hi + 1);
      hist.push(h);
      if (hist.length > 120) { hist.shift(); }
      hi = hist.length - 1;
    }
    function scheduleSnap() { clearTimeout(histTimer); histTimer = setTimeout(snap, 350); }
    function restoreSnap(i) {
      canvas.innerHTML = hist[i];
      hi = i;
      placeCaretEnd();
      sync();
    }
    function undo() { snap(); if (hi > 0) { restoreSnap(hi - 1); } }
    function redo() { if (hi < hist.length - 1) { restoreSnap(hi + 1); } }
    function placeCaretEnd() {
      var last = canvas.lastElementChild;
      if (last) { placeCaret(last, false); } else { canvas.focus(); }
    }

    function saveRange() {
      var s = window.getSelection();
      if (s.rangeCount && canvas.contains(s.getRangeAt(0).commonAncestorContainer)) {
        saved = s.getRangeAt(0).cloneRange();
      }
    }
    function restoreRange() {
      /* A live selection inside the canvas wins over the last saved one. */
      var s0 = window.getSelection();
      if (s0.rangeCount && canvas.contains(s0.getRangeAt(0).commonAncestorContainer)) {
        saved = s0.getRangeAt(0).cloneRange();
        canvas.focus();
        s0.removeAllRanges();
        s0.addRange(saved);
        return;
      }
      canvas.focus();
      var s = window.getSelection();
      s.removeAllRanges();
      if (saved && canvas.contains(saved.commonAncestorContainer)) {
        s.addRange(saved);
      } else {
        var r = document.createRange();
        r.selectNodeContents(canvas);
        r.collapse(false);
        s.addRange(r);
      }
    }
    function currentRange() {
      var s = window.getSelection();
      if (s.rangeCount && canvas.contains(s.getRangeAt(0).commonAncestorContainer)) { return s.getRangeAt(0); }
      return null;
    }
    function blockOf(node) {
      while (node && node !== canvas && node.parentNode !== canvas) { node = node.parentNode; }
      return node && node !== canvas ? node : null;
    }
    function caretBlock() {
      var r = currentRange();
      return r ? blockOf(r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode) : null;
    }

    function rel(rect) {
      var rr = root.getBoundingClientRect();
      return { top: rect.top - rr.top, bottom: rect.bottom - rr.top, left: rect.left - rr.left, right: rect.right - rr.left, width: rect.width, height: rect.height };
    }

    function placePop(anchorEl, node) {
      var a = rel(anchorEl.getBoundingClientRect());
      pop.hidden = false;
      pop.appendChild(node);
      var w = Math.min(340, root.clientWidth - 16);
      pop.style.width = w + 'px';
      pop.style.left = Math.max(0, Math.min(a.left, root.clientWidth - w - 8)) + 'px';
      var h = pop.offsetHeight;
      var top = a.bottom + 8;
      if (top + h > root.clientHeight + 200 && a.top - h - 8 > 0) { top = a.top - h - 8; }
      pop.style.top = top + 'px';
      var first = pop.querySelector('input[type=text],input[type=url],select,button');
      if (first) { setTimeout(function () { first.focus(); }, 0); }
    }
    function closePop(refocus) {
      pop.hidden = true;
      pop.innerHTML = '';
      popOpen = null;
      if (refocus && saved) { restoreRange(); }
    }

    function placeBar() {
      var r = currentRange();
      var tb = bar.querySelector('[data-f=tableopt]');
      if (tb) { tb.disabled = !(r && cellOf(r.startContainer)); }
      /* Keyboard users (Alt+F10) keep the bar open while focus is inside it, even with a collapsed caret. */
      if (!r || r.collapsed) { if (!bar.contains(document.activeElement)) { bar.hidden = true; } return; }
      var rect = rel(r.getBoundingClientRect());
      bar.hidden = false;
      var bw = bar.offsetWidth, bh = bar.offsetHeight;
      var above = rect.top - bh - 8;
      var top = above >= 0 ? above : rect.bottom + 8;
      var left = rect.left + rect.width / 2 - bw / 2;
      left = Math.max(0, Math.min(left, root.clientWidth - bw));
      bar.style.top = top + 'px';
      bar.style.left = left + 'px';
    }

    function placeHover(block) {
      if (!block) { hover.hidden = true; return; }
      var b = rel(block.getBoundingClientRect());
      var c = rel(canvas.getBoundingClientRect());
      if (b.bottom < c.top || b.top > c.bottom) { hover.hidden = true; return; }
      hover.hidden = false;
      hover.style.top = Math.max(c.top, b.top) + 'px';
      hover.style.left = Math.max(0, c.left + 4) + 'px';
    }

    /* ---------- commands ---------- */
    function run(cmd, value) {
      snap();
      restoreRange();
      /* Text emphasis must come out as <b>/<i>/<u>/<s>, not inline style spans, so the sanitiser keeps it. */
      var asTags = (cmd === 'bold' || cmd === 'italic' || cmd === 'underline' || cmd === 'strikeThrough');
      try {
        if (asTags) { document.execCommand('styleWithCSS', false, false); }
        document.execCommand(cmd, false, value);
      } catch (e) { /* unsupported command: ignore */ }
      finally { if (asTags) { try { document.execCommand('styleWithCSS', false, true); } catch (e2) { /* ignore */ } } }
      later();
    }

    function insertAfter(anchor, nodes) {
      var last = null;
      nodes.forEach(function (n) {
        if (last) { canvas.insertBefore(n, last.nextSibling); } else if (anchor && anchor.parentNode === canvas) { canvas.insertBefore(n, anchor.nextSibling); } else { canvas.appendChild(n); }
        last = n;
      });
      // always keep a paragraph after a media block so the writer can continue typing
      var after = last && last.nextSibling;
      if (!after || after.nodeType !== 1 || after.nodeName !== 'P') {
        var p = document.createElement('p');
        p.appendChild(document.createElement('br'));
        canvas.insertBefore(p, after || null);
        last = p;
      }
      placeCaret(last, true);
      later();
    }
    function placeCaret(node, start) {
      canvas.focus();
      var r = document.createRange();
      r.selectNodeContents(node);
      r.collapse(start);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      saveRange();
    }
    function fromHTML(html) {
      var t = document.createElement('template');
      t.innerHTML = html;
      return Array.prototype.slice.call(t.content.childNodes).filter(function (n) { return n.nodeType === 1; });
    }
    function anchorBlock(hint) {
      if (hint && hint.parentNode === canvas) { return hint; }
      return caretBlock();
    }

    function setBlock(tag) { restoreRange(); document.execCommand('formatBlock', false, tag); later(); }

    function applyFontSize(px) {
      restoreRange();
      var r = currentRange();
      if (!r || r.collapsed) { say(T.noSel, true); return; }
      var span = document.createElement('span');
      span.style.fontSize = px + 'px';
      span.appendChild(r.extractContents());
      r.insertNode(span);
      var nr = document.createRange(); nr.selectNodeContents(span);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(nr);
      saveRange(); later();
    }

    /* ---------- link popover ---------- */
    function openLink(anchorEl) {
      saveRange();
      var r = currentRange();
      var existing = null;
      if (r) {
        var node = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode;
        existing = node && node.closest ? node.closest('a') : null;
        if (existing && !canvas.contains(existing)) { existing = null; }
      }
      var box = el('div', { 'class': 'rp-box' });
      box.innerHTML = '<label class="rp-row">' + T.link
        + '<input type="text" class="rp-url" placeholder="' + T.linkPh + '" autocomplete="off" value="' + (existing ? existing.getAttribute('href') : '') + '"></label>'
        + '<ul class="rp-sugg" role="listbox" aria-label="' + T.link + '" hidden></ul>'
        + '<label class="rp-check"><input type="checkbox" class="rp-tab"' + (existing && existing.getAttribute('target') === '_blank' ? ' checked' : '') + '> ' + T.newTab + '</label>'
        + '<div class="rp-actions"><button type="button" class="rp-apply">' + T.apply + '</button>'
        + (existing ? '<button type="button" class="rp-rm" data-rm>' + T.unlink + '</button>' : '')
        + '<button type="button" class="rp-cancel">' + T.cancel + '</button></div><p class="rp-err" hidden></p>';
      popOpen = 'link';
      placePop(anchorEl || bar.querySelector('[data-f=link]') || canvas, box);
      var input = $('.rp-url', box), list = $('.rp-sugg', box), err = $('.rp-err', box);
      var chosen = null, idx = -1, hits = [];

      function renderSugg() {
        var q = input.value.trim().toLowerCase();
        chosen = null; idx = -1;
        if (!q || SAFE_HREF.test(q) || DOMAIN.test(q)) { hits = []; list.hidden = true; list.innerHTML = ''; return; }
        hits = links.filter(function (l) { return l.t.toLowerCase().indexOf(q) >= 0; }).slice(0, 6);
        list.innerHTML = '';
        hits.forEach(function (h, i) {
          var li = el('li', { role: 'option', 'data-i': String(i), 'class': 'rp-item' }, '');
          li.textContent = h.t + ', ' + h.u;
          list.appendChild(li);
        });
        list.hidden = hits.length === 0;
      }
      function highlight(i) {
        var items = $$('.rp-item', list);
        items.forEach(function (x, k) { x.classList.toggle('on', k === i); });
        idx = i;
        chosen = i >= 0 && i < hits.length ? hits[i] : null;
      }
      function apply(urlText) {
        var url = chosen ? chosen.u : toURL(urlText);
        if (!url) { err.textContent = T.badUrl; err.hidden = false; return; }
        restoreRange();
        var openNew = $('.rp-tab', box).checked;
        if (existing) {
          existing.setAttribute('href', url);
          if (openNew) { existing.setAttribute('target', '_blank'); existing.setAttribute('rel', 'noopener noreferrer'); }
          else { existing.removeAttribute('target'); existing.removeAttribute('rel'); }
        } else {
          var rr = currentRange();
          if (!rr || rr.collapsed) {
            var a = document.createElement('a');
            a.setAttribute('href', url);
            a.textContent = chosen ? chosen.t : url;
            if (openNew) { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener noreferrer'); }
            if (rr) { rr.insertNode(a); } else { canvas.appendChild(a); }
          } else {
            document.execCommand('createLink', false, url);
            var made = currentRange();
            if (made) {
              var n = made.commonAncestorContainer.nodeType === 1 ? made.commonAncestorContainer : made.commonAncestorContainer.parentNode;
              var link = n && n.closest ? n.closest('a') : null;
              if (link && openNew) { link.setAttribute('target', '_blank'); link.setAttribute('rel', 'noopener noreferrer'); }
            }
          }
        }
        closePop(false); later();
      }
      input.addEventListener('input', renderSugg);
      input.addEventListener('keydown', function (e) {
        var items = $$('.rp-item', list);
        if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); highlight(Math.min(idx + 1, items.length - 1)); }
        else if (e.key === 'ArrowUp' && items.length) { e.preventDefault(); highlight(Math.max(idx - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); apply(input.value); }
        else if (e.key === 'Escape') { e.preventDefault(); closePop(true); }
      });
      list.addEventListener('mousedown', function (e) {
        var li = e.target.closest('.rp-item');
        if (li) { e.preventDefault(); highlight(+li.getAttribute('data-i')); apply(input.value); }
      });
      $('.rp-apply', box).addEventListener('click', function () { apply(input.value); });
      $('.rp-cancel', box).addEventListener('click', function () { closePop(true); });
      var rm = $('.rp-rm', box);
      if (rm) { rm.addEventListener('click', function () { restoreRange(); if (existing) { var t = document.createTextNode(existing.textContent); existing.parentNode.replaceChild(t, existing); } closePop(false); later(); }); }
      renderSugg();
    }

    /* ---------- image popover (alt text, remove) ---------- */
    function openImage(img) {
      var box = el('div', { 'class': 'rp-box' });
      box.innerHTML = '<label class="rp-row">' + T.alt + '<input type="text" class="rp-alt" maxlength="300" value="' + (img.getAttribute('alt') || '').replace(/"/g, '&quot;') + '"></label>'
        + '<div class="rp-actions"><button type="button" class="rp-apply">' + T.apply + '</button>'
        + '<button type="button" class="rp-rm" data-rm>' + T.remove + '</button>'
        + '<button type="button" class="rp-cancel">' + T.cancel + '</button></div>';
      popOpen = 'image';
      placePop(img, box);
      $('.rp-apply', box).addEventListener('click', function () {
        var alt = $('.rp-alt', box).value.trim();
        img.setAttribute('alt', alt.slice(0, 300));
        if (!alt) { say(T.altMissing, true); }
        closePop(false); later();
      });
      $('.rp-rm', box).addEventListener('click', function () {
        var fig = img.closest('figure');
        var p = img.parentNode;
        img.parentNode.removeChild(img);
        if (fig && !fig.querySelector('img')) { fig.parentNode.removeChild(fig); }
        if (p && p.nodeName === 'P' && !p.textContent.trim() && !p.querySelector('img,iframe,hr,table')) { p.parentNode.removeChild(p); }
        closePop(false); later();
      });
      $('.rp-cancel', box).addEventListener('click', function () { closePop(false); });
    }

    /* ---------- images: upload and insert ---------- */
    function uploadFiles(files, anchor) {
      var list = Array.prototype.slice.call(files).filter(function (f) { return /^image\/(jpeg|png|webp|gif)$/.test(f.type); });
      if (!list.length) { say(T.badType, true); return; }
      list = list.filter(function (f) { return f.size <= MAX_MB * 1024 * 1024 || (say(T.tooBig, true), false); });
      if (!list.length) { return; }
      var at = anchorBlock(anchor);
      say(T.uploading + ' (' + bnNum(list.length) + ')…');
      var jobs = list.map(function (f) {
        var fd = new FormData();
        fd.append('file', f, f.name);
        return fetch(uploadUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' }, body: fd })
          .then(function (r) { return r.json().catch(function () { return { error: T.uploadFail }; }); })
          .catch(function () { return { error: T.uploadFail }; });
      });
      Promise.all(jobs).then(function (rs) {
        var ok = rs.filter(function (d) { return d && d.url; });
        var bad = rs.filter(function (d) { return !d || !d.url; });
        if (bad.length) { say((bad[0] && bad[0].error) || T.uploadFail, true); }
        if (!ok.length) { return; }
        say(T.uploaded);
        var nodes;
        if (ok.length === 1) {
          nodes = fromHTML('<p>' + imgHTML(ok[0], '') + '</p>');
        } else {
          var cols = ok.length >= 3 ? 3 : 2;
          var inner = ok.map(function (d) { return '<figure>' + imgHTML(d, '') + '</figure>'; }).join('');
          nodes = fromHTML('<div data-cq="gallery" data-cq-cols="' + cols + '">' + inner + '</div>');
        }
        insertAfter(at, nodes);
        var firstImg = canvas.querySelector('img[alt=""]');
        if (firstImg) { say(T.altMissing); }
      });
    }
    function imgHTML(d, alt) {
      var srcset = d.small && d.small !== d.url ? ' srcset="' + d.small + ' 800w, ' + d.url + ' 1600w"' : '';
      return '<img src="' + d.url + '"' + srcset + ' alt="' + (alt || '') + '" width="' + (d.width || 800) + '" height="' + (d.height || 600) + '" loading="lazy" decoding="async">';
    }

    /* ---------- tables ---------- */
    function tableHTML(rows, cols) {
      var t = '<div data-cq="table"><table><tbody>';
      for (var i = 0; i < rows; i++) {
        t += '<tr>';
        for (var j = 0; j < cols; j++) { t += '<td><br></td>'; }
        t += '</tr>';
      }
      return t + '</tbody></table></div>';
    }
    function insertTable(rows, cols, anchor) {
      var at = anchorBlock(anchor);
      insertAfter(at, fromHTML(tableHTML(rows, cols)));
    }
    function cellOf(node) {
      var n = node && node.nodeType === 1 ? node : node && node.parentNode;
      return n && n.closest ? n.closest('td,th') : null;
    }
    function tableOf(cell) { return cell ? cell.closest('table') : null; }
    function openGrid(anchorEl, anchorBlk) {
      var box = el('div', { 'class': 'rp-box rp-grid-box' });
      box.innerHTML = '<p class="rp-row">' + T.pick + ' <span class="rp-size">৩×৩</span></p><div class="rp-grid" role="grid"></div>';
      var g = $('.rp-grid', box), size = $('.rp-size', box);
      for (var r = 1; r <= 6; r++) {
        for (var c = 1; c <= 6; c++) {
          var cell = el('button', { type: 'button', 'data-r': String(r), 'data-c': String(c), 'aria-label': bnNum(r) + '×' + bnNum(c) });
          g.appendChild(cell);
        }
      }
      g.addEventListener('mouseover', function (e) {
        var b = e.target.closest('button'); if (!b) { return; }
        var rr = +b.getAttribute('data-r'), cc = +b.getAttribute('data-c');
        $$('button', g).forEach(function (x) { x.classList.toggle('on', +x.getAttribute('data-r') <= rr && +x.getAttribute('data-c') <= cc); });
        size.textContent = bnNum(rr) + '×' + bnNum(cc);
      });
      g.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) { return; }
        closePop(false);
        restoreRange();
        insertTable(+b.getAttribute('data-r'), +b.getAttribute('data-c'), anchorBlk);
      });
      popOpen = 'grid';
      placePop(anchorEl, box);
    }
    function openTableOptions(anchorEl, cell) {
      var box = el('div', { 'class': 'rp-box' });
      box.innerHTML = '<div class="rp-actions rp-wrap">'
        + '<button type="button" data-t="rowAbove">' + T.rowAbove + '</button>'
        + '<button type="button" data-t="rowBelow">' + T.rowBelow + '</button>'
        + '<button type="button" data-t="colLeft">' + T.colLeft + '</button>'
        + '<button type="button" data-t="colRight">' + T.colRight + '</button>'
        + '<button type="button" data-t="delRow">' + T.delRow + '</button>'
        + '<button type="button" data-t="delCol">' + T.delCol + '</button>'
        + '<button type="button" data-t="header">' + T.header + '</button>'
        + '<button type="button" data-t="delTable" class="rp-danger">' + T.delTable + '</button></div>';
      popOpen = 'table';
      placePop(anchorEl, box);
      box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-t]'); if (!b) { return; }
        tableAction(b.getAttribute('data-t'), cell);
        closePop(false);
        later();
      });
    }
    function rowsOf(table) { return Array.prototype.slice.call(table.querySelectorAll('tr')); }
    function tableAction(act, cell) {
      var table = tableOf(cell); if (!table) { return; }
      var tr = cell.parentNode, idx = Array.prototype.indexOf.call(tr.children, cell);
      var rows = rowsOf(table);
      function newCell(tag) { var td = document.createElement(tag); td.appendChild(document.createElement('br')); return td; }
      if (act === 'rowAbove' || act === 'rowBelow') {
        var nr = document.createElement('tr');
        for (var i = 0; i < tr.children.length; i++) { nr.appendChild(newCell(tr.children[i].nodeName.toLowerCase())); }
        tr.parentNode.insertBefore(nr, act === 'rowAbove' ? tr : tr.nextSibling);
      } else if (act === 'colLeft' || act === 'colRight') {
        rows.forEach(function (row) {
          var ref = row.children[act === 'colLeft' ? idx : idx + 1] || null;
          row.insertBefore(newCell(row.children[idx] ? row.children[idx].nodeName.toLowerCase() : 'td'), ref);
        });
      } else if (act === 'delRow') {
        if (rows.length > 1) { tr.parentNode.removeChild(tr); } else { table.closest('div[data-cq]').remove(); }
      } else if (act === 'delCol') {
        if (tr.children.length > 1) { rows.forEach(function (row) { if (row.children[idx]) { row.removeChild(row.children[idx]); } }); }
        else { table.closest('div[data-cq]').remove(); }
      } else if (act === 'header') {
        var first = rows[0];
        var isHead = first.parentNode.nodeName === 'THEAD';
        if (isHead) {
          var tb = table.querySelector('tbody') || table.appendChild(document.createElement('tbody'));
          Array.prototype.slice.call(first.children).forEach(function (c) { c.outerHTML = c.outerHTML.replace(/^<th/i, '<td').replace(/<\/th>$/i, '</td>'); });
          tb.insertBefore(first, tb.firstChild);
          table.querySelector('thead') && table.querySelector('thead').remove();
        } else {
          var thead = document.createElement('thead');
          Array.prototype.slice.call(first.children).forEach(function (c) { var th = document.createElement('th'); th.innerHTML = c.innerHTML; first.replaceChild(th, c); });
          thead.appendChild(first);
          table.insertBefore(thead, table.firstChild);
        }
      } else if (act === 'delTable') {
        var wrap = table.closest('div[data-cq="table"]') || table;
        var sib = wrap.nextSibling;
        wrap.parentNode.removeChild(wrap);
        if (sib) { placeCaret(sib, true); }
      }
    }

    /* ---------- video ---------- */
    function openVideo(anchorEl, anchorBlk) {
      var box = el('div', { 'class': 'rp-box' });
      box.innerHTML = '<label class="rp-row">' + T.video + '<input type="url" class="rp-vid" placeholder="' + T.videoPh + '" autocomplete="off"></label>'
        + '<div class="rp-actions"><button type="button" class="rp-apply">' + T.apply + '</button><button type="button" class="rp-cancel">' + T.cancel + '</button></div>'
        + '<p class="rp-err" hidden></p>';
      popOpen = 'video';
      placePop(anchorEl, box);
      var input = $('.rp-vid', box), err = $('.rp-err', box);
      function go() {
        var src = videoEmbed(input.value);
        if (!src || !VIDEO_OK.test(src)) { err.textContent = T.badVideo; err.hidden = false; return; }
        closePop(false);
        var html = '<div data-cq="video" contenteditable="false"><iframe src="' + src + '" title="ভিডিও" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>';
        insertAfter(anchorBlk, fromHTML(html));
      }
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } if (e.key === 'Escape') { closePop(true); } });
      $('.rp-apply', box).addEventListener('click', go);
      $('.rp-cancel', box).addEventListener('click', function () { closePop(true); });
    }

    /* ---------- code tab ---------- */
    function prettify(html) { return html.replace(/<\/(p|h2|h3|h4|blockquote|ul|ol|li|div|figure|table|tr)>/g, '</$1>\n').replace(/\n\s*\n/g, '\n').trim(); }
    function showCode(on) {
      var tabs = $$('[role=tab]', strip);
      tabs.forEach(function (t) { t.setAttribute('aria-selected', String((t.getAttribute('data-top') === 'code') === on)); });
      if (on) {
        if (!codeMode) {
          code.value = prettify(serialize().replace(MARK, ''));
        }
        codeMode = true;
        canvas.hidden = true; code.hidden = false; bar.hidden = true; hover.hidden = true; closePop(false);
        code.focus();
      } else {
        if (codeMode) {
          var cleaned = cleanHTML(code.value);
          var looksBroken = code.value.replace(/<[^>]*>/g, '').trim() !== '' && cleaned.replace(/<[^>]*>/g, '').trim() === '' && !/<(img|iframe|table|hr)\b/i.test(cleaned);
          if (looksBroken) { say(T.codeErr, true); code.focus(); return; }
          canvas.innerHTML = cleaned;
          wrapLooseText(canvas);
        }
        codeMode = false;
        code.hidden = true; canvas.hidden = false;
        canvas.focus();
      }
      sync();
    }

    /* ---------- events ---------- */
    strip.addEventListener('mousedown', function (e) { if (e.target.closest('button')) { e.preventDefault(); } });
    strip.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      var act = b.getAttribute('data-top');
      if (act === 'undo') { if (!codeMode) { undo(); } }
      else if (act === 'redo') { if (!codeMode) { redo(); } }
      else if (act === 'visual') { showCode(false); }
      else if (act === 'code') { showCode(true); }
    });

    bar.addEventListener('mousedown', function (e) {
      var t = e.target;
      if (t.tagName === 'SELECT' || t.tagName === 'INPUT') { saveRange(); return; }
      if (t.closest('button')) { e.preventDefault(); }
    });
    bar.addEventListener('change', function (e) {
      var f = e.target.getAttribute('data-f');
      if (f === 'block') { setBlock(e.target.value); }
      else if (f === 'size') { applyFontSize(+e.target.value); }
    });
    bar.addEventListener('input', function (e) {
      var f = e.target.getAttribute('data-f');
      if (f === 'fore') { run('foreColor', e.target.value); }
      else if (f === 'hilite') { run('hiliteColor', e.target.value); }
    });
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      var f = b.getAttribute('data-f');
      var map = { bold: ['bold'], italic: ['italic'], underline: ['underline'], strike: ['strikeThrough'], alignLeft: ['justifyLeft'],
        alignCenter: ['justifyCenter'], alignRight: ['justifyRight'], alignJustify: ['justifyFull'], ul: ['insertUnorderedList'],
        ol: ['insertOrderedList'], outdent: ['outdent'], indent: ['indent'], unlink: ['unlink'], hr: ['insertHorizontalRule'], clear: ['removeFormat'] };
      if (map[f]) { run(map[f][0]); return; }
      if (f === 'link') { openLink(b); }
      else if (f === 'image') { saveRange(); fileIn.setAttribute('data-where', 'selection'); fileIn.click(); }
      else if (f === 'table') { saveRange(); openGrid(b, caretBlock()); }
      else if (f === 'tableopt') { var cell = cellOf(currentRange() && currentRange().startContainer); if (cell) { openTableOptions(b, cell); } }
      else if (f === 'video') { saveRange(); openVideo(b, caretBlock()); }
    });

    hover.addEventListener('mouseenter', function () { clearTimeout(hoverLeaveTimer); });
    hover.addEventListener('mouseleave', function () { scheduleHoverHide(); });
    hover.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      var h = b.getAttribute('data-h');
      var block = hoverBlock;
      if (h === 'image') { fileIn.setAttribute('data-where', 'block'); fileIn.setAttribute('data-anchor', 'hover'); anchorForFile = block; fileIn.click(); }
      else if (h === 'table') { insertTable(3, 3, block); }
      else if (h === 'video') { saveRange(); openVideo(b, block); }
      else if (h === 'hr') { insertAfter(block, [document.createElement('hr')]); }
      hover.hidden = true;
    });
    fileIn.addEventListener('change', function () {
      var where = fileIn.getAttribute('data-where');
      var anchor = where === 'block' ? anchorForFile : null;
      if (fileIn.files && fileIn.files.length) { if (where !== 'block') { restoreRange(); } uploadFiles(fileIn.files, anchor); }
      fileIn.value = '';
      anchorForFile = null;
    });

    canvas.addEventListener('input', function () {
      /* Text typed straight into the canvas (empty post, or after select-all + type) must sit in a paragraph. */
      var s = window.getSelection(), inNode = null, off = 0;
      if (s && s.rangeCount) { var cr = s.getRangeAt(0); inNode = cr.startContainer; off = cr.startOffset; }
      var fixed = false;
      Array.prototype.slice.call(canvas.childNodes).forEach(function (n) {
        if (n.nodeType === 3 && n.nodeValue.trim() !== '') {
          var p = document.createElement('p');
          canvas.insertBefore(p, n);
          p.appendChild(n);
          fixed = true;
        }
      });
      if (fixed && inNode && inNode.parentNode && s) {
        var nr = document.createRange();
        nr.setStart(inNode, Math.min(off, inNode.length || 0));
        nr.collapse(true);
        s.removeAllRanges();
        s.addRange(nr);
      }
      later();
    });
    canvas.addEventListener('keyup', function (e) { if (e.key === 'Shift' || e.key === 'Control' || e.key === 'Alt') { return; } later(); });

    canvas.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); openLink(null); return; }
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'b' || e.key === 'B')) { e.preventDefault(); run('bold'); return; }
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'i' || e.key === 'I')) { e.preventDefault(); run('italic'); return; }
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'u' || e.key === 'U')) { e.preventDefault(); run('underline'); return; }
      if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) { e.preventDefault(); undo(); return; }
      if ((e.ctrlKey || e.metaKey) && !e.altKey && ((e.key === 'y' || e.key === 'Y') || ((e.key === 'z' || e.key === 'Z') && e.shiftKey))) { e.preventDefault(); redo(); return; }
      if (e.key === 'Escape') { closePop(true); bar.hidden = true; return; }
      if (e.key === 'Tab') {
        var cell = cellOf(currentRange() && currentRange().startContainer);
        if (cell) {
          e.preventDefault();
          var table = tableOf(cell);
          var cells = Array.prototype.slice.call(table.querySelectorAll('td,th'));
          var k = cells.indexOf(cell) + (e.shiftKey ? -1 : 1);
          if (k < 0) { placeCaret(cells[0], true); return; }
          if (k >= cells.length) {
            if (e.shiftKey) { return; }
            tableAction('rowBelow', cell);
            var newCells = table.querySelectorAll('td,th');
            placeCaret(newCells[newCells.length - table.querySelector('tr').children.length], true);
            later();
            return;
          }
          placeCaret(cells[k], true);
          return;
        }
      }
      if (e.key === 'Enter' && cellOf(currentRange() && currentRange().startContainer) && !e.shiftKey) {
        e.preventDefault();
        document.execCommand('insertLineBreak');
        later();
      }
      if (e.altKey && e.key === 'F10') { e.preventDefault(); showBarAtCaret(); }
      if (e.shiftKey && e.key === 'F10') { e.preventDefault(); placeBar(); bar.hidden = false; }
    });

    canvas.addEventListener('click', function (e) {
      var img = e.target.closest && e.target.closest('img');
      if (img && canvas.contains(img)) { saveRange(); openImage(img); }
    });

    canvas.addEventListener('mousemove', function (e) {
      if (codeMode) { return; }
      var block = blockOf(e.target);
      if (!block || block === hoverBlock) { return; }
      clearTimeout(hoverTimer);
      hoverTimer = setTimeout(function () {
        hoverBlock = block;
        placeHover(block);
      }, 220);
    });
    canvas.addEventListener('mouseleave', function () { scheduleHoverHide(); });
    canvas.addEventListener('scroll', function () { if (!hover.hidden) { placeHover(hoverBlock); } });
    function scheduleHoverHide() {
      clearTimeout(hoverLeaveTimer);
      hoverLeaveTimer = setTimeout(function () { if (!hover.matches(':hover')) { hover.hidden = true; } }, 320);
    }

    function showBarAtCaret() {
      saveRange();
      placeBar();
      bar.hidden = false;
      var first = bar.querySelector('select,button');
      if (first) { first.focus(); }
    }

    /* selection: show the bar for selected text, hide it when the selection collapses or the user clicks away */
    var selTimer = null;
    document.addEventListener('selectionchange', function () {
      clearTimeout(selTimer);
      selTimer = setTimeout(function () {
        if (codeMode) { return; }
        var r = currentRange();
        if (r && !r.collapsed && !popOpen) { saveRange(); placeBar(); }
        else if (!bar.contains(document.activeElement)) { if (!popOpen) { bar.hidden = true; } }
        // small screens: the "+" control follows the caret
        if (r && r.collapsed && window.matchMedia && window.matchMedia('(max-width: 640px)').matches) {
          var b = caretBlock();
          if (b) { hoverBlock = b; placeHover(b); }
        }
      }, 40);
    });
    window.addEventListener('scroll', function () { if (!bar.hidden) { placeBar(); } }, true);
    window.addEventListener('resize', function () { if (!bar.hidden) { placeBar(); } });
    document.addEventListener('mousedown', function (e) {
      if (!root.contains(e.target)) { bar.hidden = true; hover.hidden = true; if (popOpen) { closePop(false); } }
    });

    /* paste: images upload, HTML goes through the cleaner, plain text stays plain */
    canvas.addEventListener('paste', function (e) {
      var cd = e.clipboardData; if (!cd) { return; }
      var files = Array.prototype.slice.call(cd.files || []).filter(function (f) { return /^image\//.test(f.type); });
      if (files.length) { e.preventDefault(); saveRange(); uploadFiles(files, null); return; }
      var html = cd.getData('text/html');
      e.preventDefault();
      if (html) {
        var cleaned = cleanHTML(html);
        document.execCommand('insertHTML', false, cleaned);
        say(T.pasted);
      } else {
        document.execCommand('insertText', false, cd.getData('text/plain'));
      }
      later();
    });

    /* drop: image files upload at the drop point; an image dragged from another page uses its https address */
    canvas.addEventListener('dragover', function (e) { e.preventDefault(); });
    canvas.addEventListener('drop', function (e) {
      e.preventDefault();
      var dt = e.dataTransfer; if (!dt) { return; }
      var r = document.caretRangeFromPoint ? document.caretRangeFromPoint(e.clientX, e.clientY) : null;
      if (r) { saved = r.cloneRange(); }
      var files = Array.prototype.slice.call(dt.files || []);
      if (files.length) { uploadFiles(files, r ? blockOf(r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode) : null); return; }
      var html = dt.getData('text/html');
      var m = html && html.match(/<img[^>]+src="(https:\/\/[^"]+)"/i);
      if (m && IMG_EXTERNAL.test(m[1])) {
        insertAfter(r ? blockOf(r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode) : null,
          fromHTML('<p><img src="' + m[1] + '" alt="" loading="lazy" decoding="async"></p>'));
      }
    });

    /* images: click on any picture edits its alt text */
    form && form.addEventListener('submit', function (ev) {
      if (codeMode) {
        /* Never save an empty body because of a broken Code-tab edit: stop, show the error, keep the content. */
        var c = cleanHTML(code.value);
        var broken = code.value.replace(/<[^>]*>/g, '').trim() !== '' && c.replace(/<[^>]*>/g, '').trim() === '' && !/<(img|iframe|table|hr)\b/i.test(c);
        if (broken) { ev.preventDefault(); say(T.codeErr, true); code.focus(); return; }
        canvas.innerHTML = c;
        wrapLooseText(canvas);
        codeMode = false; code.hidden = true; canvas.hidden = false;
      }
      sync();
    });

    /* initial state */
    snap();
    hidden.value = serialize();
    updateCount();
    sync();
    if (!canvas.innerHTML.trim()) { canvas.innerHTML = ''; }
  }

  function init() {
    $$('[data-rich]').forEach(function (r) {
      try { mount(r); } catch (e) { console.error('[editor]', e); }
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
