/* App install: registers the service worker and shows a small popup inviting the visitor to install the site as an app.
   - Android / desktop Chrome: a real "Install" button (uses the browser's install prompt).
   - iPhone / iPad (Safari has no prompt): a short "Share > Add to Home Screen" note.
   Hidden when already installed/standalone, and for 14 days after the visitor closes it. */
(function () {
  'use strict';

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () { /* offline support is optional */ });
    });
  }

  var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  if (standalone) { return; }

  var KEY = 'jebu_install_dismiss', DAYS = 14;
  function dismissedRecently() {
    try { var t = parseInt(window.localStorage.getItem(KEY) || '0', 10); return !!t && (Date.now() - t) < DAYS * 864e5; } catch (e) { return false; }
  }
  function remember() { try { window.localStorage.setItem(KEY, String(Date.now())); } catch (e) { /* private mode: it may show again */ } }
  if (dismissedRecently()) { return; }

  var ua = navigator.userAgent || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var deferred = null, pop = null, timer = null;

  function hide() { if (pop) { pop.parentNode && pop.parentNode.removeChild(pop); pop = null; } }
  function close() { remember(); hide(); }

  function build() {
    pop = document.createElement('div');
    pop.className = 'ins-pop';
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-labelledby', 'insTitle');
    pop.innerHTML =
      '<button type="button" class="ins-x" aria-label="বন্ধ করুন">×</button>' +
      '<div class="ins-row"><img src="/assets/img/icons/icon-192.png" alt="" width="52" height="52">' +
      '<div><p class="ins-t" id="insTitle">অ্যাপ হিসেবে ইনস্টল করুন</p>' +
      '<p class="ins-d" id="insDesc"></p></div></div>' +
      '<div class="ins-act"><button type="button" class="ins-later">পরে</button><button type="button" class="ins-go"></button></div>';
    var desc = pop.querySelector('#insDesc'), go = pop.querySelector('.ins-go');
    if (isIOS && !deferred) {
      desc.textContent = 'Safari-র শেয়ার বাটনে (↑) চাপুন, তারপর “Add to Home Screen” বেছে নিন। এরপর হোম স্ক্রিন থেকে এক ট্যাপে অ্যাপয়েন্টমেন্ট নিন।';
      go.textContent = 'বুঝেছি';
    } else {
      desc.textContent = 'হোম স্ক্রিন থেকে এক ট্যাপে অ্যাপয়েন্টমেন্ট নিন — বারবার Google-এ খুঁজতে হবে না।';
      go.textContent = 'ইনস্টল করুন';
    }
    go.addEventListener('click', function () {
      if (!deferred) { close(); return; }
      deferred.prompt();
      deferred.userChoice.then(function (c) { if (c.outcome !== 'accepted') { remember(); } hide(); deferred = null; }).catch(hide);
    });
    pop.querySelector('.ins-later').addEventListener('click', close);
    pop.querySelector('.ins-x').addEventListener('click', close);
    document.body.appendChild(pop);
    go.focus({ preventScroll: true });
  }

  function show() {
    timer = null;
    if (pop || dismissedRecently()) { return; }
    if (!deferred && !isIOS) { return; }
    build();
  }
  function schedule() { if (!timer && !pop) { timer = setTimeout(show, 6000); } }

  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; schedule(); });
  window.addEventListener('appinstalled', function () { remember(); hide(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pop) { close(); } });
  if (isIOS) { schedule(); }
})();
