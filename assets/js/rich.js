/* Rich-text editor for admin content (no dependencies). Sanitising happens on the server; this is a convenience. */
(function () {
  'use strict';
  var ALLOWED_HREF = /^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i;
  var DOMAIN = /^[\w.-]+\.[a-z]{2,}(\/\S*)?$/i;

  function toURL(raw) {
    var v = (raw || '').trim();
    if (!v) { return null; }
    if (ALLOWED_HREF.test(v)) { return v; }
    if (DOMAIN.test(v)) { return 'https://' + v; }
    return null;
  }
  function words(el) {
    var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
    return t ? t.split(' ').length : 0;
  }
  function bnNum(n) { return String(n).replace(/\d/g, function (d) { return '০১২৩৪৫৬৭৮৯'[+d]; }); }

  function mount(root) {
    var canvas = root.querySelector('.rich-canvas');
    var hidden = root.querySelector('textarea[name]');
    var code = root.querySelector('.rich-code');
    var pop = root.querySelector('.rich-pop');
    var fileIn = root.querySelector('.rich-file');
    var count = root.querySelector('.rich-count');
    var msg = root.querySelector('.rich-msg');
    var uploadUrl = root.getAttribute('data-upload');
    var csrf = root.getAttribute('data-csrf');
    var saved = null, codeMode = false, timer = null, popKind = null;

    function say(text, bad) { msg.textContent = text || ''; msg.className = 'rich-msg' + (bad ? ' bad' : ''); }
    function sync() {
      hidden.value = codeMode ? code.value : canvas.innerHTML;
      count.textContent = bnNum(words(canvas)) + ' শব্দ';
    }
    function later() { clearTimeout(timer); timer = setTimeout(sync, 200); }
    function keepSelection() { var s = window.getSelection(); saved = s.rangeCount ? s.getRangeAt(0).cloneRange() : null; }
    function restoreSelection() {
      canvas.focus();
      var s = window.getSelection();
      if (saved && canvas.contains(saved.commonAncestorContainer)) {
        s.removeAllRanges(); s.addRange(saved); return;
      }
      // caret was outside the editor: continue at the end of the content
      var r = document.createRange(); r.selectNodeContents(canvas); r.collapse(false);
      s.removeAllRanges(); s.addRange(r);
    }
    function closePop() { pop.hidden = true; pop.innerHTML = ''; popKind = null; }
    function openPop(anchor, html, kind) {
      popKind = kind;
      pop.innerHTML = html;
      pop.hidden = false;
      var r = anchor.getBoundingClientRect(), root2 = root.getBoundingClientRect();
      pop.style.top = (r.bottom - root2.top + 6) + 'px';
      pop.style.left = Math.max(0, Math.min(r.left - root2.left, root2.width - 300)) + 'px';
      var first = pop.querySelector('input[type=text],input[type=url]');
      if (first) { first.focus(); }
    }

    // ---- toolbar ----
    root.querySelector('.rich-bar').addEventListener('mousedown', function (e) {
      if (e.target.closest('select')) { return; }
      e.preventDefault(); // keep the text selection
    });
    root.querySelector('.rich-bar').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b || codeMode) { return; }
      if (b.dataset.cmd) {
        canvas.focus();
        document.execCommand(b.dataset.cmd, false, null);
        later();
      } else if (b.dataset.act === 'link') { keepSelection(); openLink(b); }
      else if (b.dataset.act === 'image') { keepSelection(); fileIn.click(); }
      else if (b.dataset.act === 'code') { toggleCode(b); }
    });
    root.querySelector('select[data-block]').addEventListener('change', function (e) {
      canvas.focus();
      document.execCommand('formatBlock', false, '<' + e.target.value + '>');
      e.target.value = 'p';
      later();
    });

    // ---- link popover ----
    function openLink(anchorBtn) {
      var a = null, sel = window.getSelection();
      if (sel.anchorNode) { var el = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement; a = el && el.closest('a'); }
      var href = a ? a.getAttribute('href') : '';
      var blank = a && a.getAttribute('target') === '_blank';
      openPop(anchorBtn, '<label class="rp-row">লিংক (URL বা লেখা খুঁজুন)<input type="text" value="' + (href || '').replace(/"/g, '&quot;') + '" placeholder="https://… / mailto: / tel: / /page"></label>'
        + '<label class="rp-check"><input type="checkbox"' + (blank ? ' checked' : '') + '> নতুন ট্যাবে খুলুন</label>'
        + '<div class="rp-actions"><button type="button" data-ok>প্রয়োগ করুন</button><button type="button" data-rm>লিংক সরান</button></div>', 'link');
      pop.querySelector('input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
        if (e.key === 'Escape') { e.preventDefault(); closePop(); restoreSelection(); }
      });
    }
    function applyLink() {
      var input = pop.querySelector('input[type=text]');
      var url = toURL(input.value);
      if (!url) { say('এই লিংকটি গ্রহণযোগ্য নয়। https://…, mailto:, tel: বা /পেজ লিখুন।', true); input.focus(); return; }
      var blank = pop.querySelector('input[type=checkbox]').checked;
      restoreSelection();
      document.execCommand('createLink', false, url);
      var s = window.getSelection(), el = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement);
      var a = el && el.closest('a');
      if (a) { if (blank) { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener noreferrer'); } else { a.removeAttribute('target'); a.removeAttribute('rel'); } }
      closePop(); say(''); later();
    }
    pop.addEventListener('mousedown', function (e) { if (!e.target.closest('input')) { e.preventDefault(); } });
    pop.addEventListener('click', function (e) {
      if (e.target.closest('[data-ok]')) { if (popKind === 'link') { applyLink(); } else { applyAlt(); } }
      if (e.target.closest('[data-rm]')) {
        if (popKind === 'link') { restoreSelection(); document.execCommand('unlink', false, null); closePop(); later(); }
        else { var img = pop.__img; if (img) { img.remove(); later(); } closePop(); }
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.target.closest && e.target.closest('.rich') !== root && !popKind) { return; }
      if (e.key === 'Escape' && popKind) { closePop(); }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && e.target.closest && e.target.closest('.rich-canvas') === canvas) {
        e.preventDefault(); keepSelection(); openLink(root.querySelector('[data-act=link]'));
      }
    });

    // ---- alt text for images (click an image) ----
    canvas.addEventListener('click', function (e) {
      if (e.target.tagName !== 'IMG') { return; }
      pop.__img = e.target;
      openPop(e.target, '<label class="rp-row">ছবির বিবরণ (Alt) — SEO ও পাঠকের জন্য জরুরি<input type="text" value="' + (e.target.getAttribute('alt') || '').replace(/"/g, '&quot;') + '" maxlength="300"></label>'
        + '<div class="rp-actions"><button type="button" data-ok>সংরক্ষণ</button><button type="button" data-rm>ছবি সরান</button></div>', 'alt');
    });
    function applyAlt() {
      var img = pop.__img, v = pop.querySelector('input[type=text]').value.trim();
      if (img) { if (v) { img.setAttribute('alt', v); } else { img.setAttribute('alt', ''); say('ছবির বিবরণ (Alt) খালি রাখবেন না।', true); } later(); }
      closePop();
    }

    // ---- images: upload, drop, paste ----
    function upload(files) {
      var list = Array.prototype.filter.call(files, function (f) { return /^image\/(jpeg|png|webp|gif)$/.test(f.type); });
      if (!list.length) { say('শুধু JPG, PNG, WEBP বা GIF ছবি দিন।', true); return; }
      keepSelection();
      say('ছবি আপলোড হচ্ছে… (' + bnNum(list.length) + ')');
      list.reduce(function (p, file) {
        return p.then(function () {
          if (file.size > 10 * 1024 * 1024) { say('ছবি ১০MB-এর বেশি। ছোট করে আবার দিন।', true); return; }
          var fd = new FormData(); fd.append('file', file); fd.append('_csrf', csrf);
          return fetch(uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
              if (!res.ok || !res.j.url) { say(res.j.error || 'ছবি আপলোড ব্যর্থ হয়েছে।', true); return; }
              var html = '<p><img src="' + res.j.url + '" alt=""></p>';
              if (saved && canvas.contains(saved.commonAncestorContainer)) {
                restoreSelection();
                document.execCommand('insertHTML', false, html);
              } else {
                // no caret inside the editor: add the image as its own paragraph at the end
                canvas.insertAdjacentHTML('beforeend', html);
              }
              later(); say('ছবি যোগ হয়েছে। ছবিতে ক্লিক করে বিবরণ (Alt) লিখুন।');
            });
        });
      }, Promise.resolve());
    }
    fileIn.addEventListener('change', function () { upload(fileIn.files); fileIn.value = ''; });
    canvas.addEventListener('paste', function (e) {
      var cd = e.clipboardData || window.clipboardData; if (!cd) { return; }
      var imgs = Array.prototype.filter.call(cd.files || [], function (f) { return f.type.indexOf('image/') === 0; });
      e.preventDefault();
      if (imgs.length) { upload(imgs); return; }
      var text = cd.getData('text/plain');
      document.execCommand('insertText', false, text);
      later();
    });
    canvas.addEventListener('dragover', function (e) { e.preventDefault(); });
    canvas.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); upload(e.dataTransfer.files); }
    });

    // ---- code view ----
    function toggleCode(btn) {
      if (!codeMode) {
        sync(); code.value = hidden.value; canvas.hidden = true; code.hidden = false; codeMode = true;
        btn.setAttribute('aria-pressed', 'true'); btn.textContent = 'ভিজ্যুয়াল';
        code.focus();
      } else {
        canvas.innerHTML = code.value; code.hidden = true; canvas.hidden = false; codeMode = false;
        btn.setAttribute('aria-pressed', 'false'); btn.textContent = '</> কোড';
        sync(); canvas.focus();
      }
    }

    // ---- sync ----
    canvas.addEventListener('input', later);
    code.addEventListener('input', later);
    canvas.addEventListener('blur', sync);
    var form = root.closest('form');
    if (form) { form.addEventListener('submit', sync); }
    sync();
  }

  document.querySelectorAll('[data-rich]').forEach(mount);
})();
