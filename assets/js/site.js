(function () {
  'use strict';
  var d = document;
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };
  function safe(fn) { try { return fn(); } catch (e) { return null; } }

  // Header: solid background after scrolling (matters on the transparent home header)
  var header = $('#siteHeader');
  function onScroll() { if (header) { header.classList.toggle('scrolled', window.scrollY > 40); } }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  // Mobile nav
  var toggle = $('#navToggle'), nav = $('#siteNav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (header) { header.classList.toggle('menu-open', open); }
      d.body.style.overflow = open ? 'hidden' : '';
    });
  }
  $$('.sub-btn').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      var li = b.parentNode;
      // Only one menu open at a time: close the others first, so two mega panels never stack
      $$('.has-sub.open').forEach(function (l) { if (l !== li) { l.classList.remove('open'); var ob = l.querySelector('.sub-btn'); if (ob) { ob.setAttribute('aria-expanded', 'false'); } } });
      var o = li.classList.toggle('open');
      b.setAttribute('aria-expanded', o ? 'true' : 'false');
      // Mouse clicks leave focus on the button, which would keep the panel open after the pointer leaves
      if (ev.detail > 0) { b.blur(); }
    });
  });
  d.addEventListener('click', function (e) {
    if (!e.target.closest('.has-sub')) { $$('.has-sub.open').forEach(function (l) { l.classList.remove('open'); }); }
  });

  // Hovering another menu closes the one that was opened by a click, so two panels never overlap
  $$('.has-sub').forEach(function (li) {
    li.addEventListener('mouseenter', function () {
      $$('.has-sub.open').forEach(function (l) { if (l !== li) { l.classList.remove('open'); } });
    });
  });

  // Reveal on scroll
  var rev = $$('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    rev.forEach(function (el) { io.observe(el); });
  } else { rev.forEach(function (el) { el.classList.add('in'); }); }

  // Click tracking (GA4 / Meta Pixel only if the scripts are present)
  d.addEventListener('click', function (e) {
    var a = e.target.closest('[data-track]');
    if (!a) { return; }
    var name = a.getAttribute('data-track');
    safe(function () { if (window.gtag) { window.gtag('event', name, {}); } });
    safe(function () { if (window.fbq) { window.fbq('track', 'Contact'); } });
  });

  // Lazy YouTube (privacy-friendly: iframe only after click)
  $$('[data-yt]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-yt');
      var f = d.createElement('iframe');
      f.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1&rel=0';
      f.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
      f.allowFullscreen = true; f.title = b.getAttribute('aria-label') || 'video';
      b.parentNode.replaceChild(f, b);
    });
  });

  // Number-only fields: convert Bangla digits, strip anything that is not a digit (a leading + is kept for phones)
  var BNDIGIT = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };
  $$('input[data-digits]').forEach(function (inp) {
    var phone = inp.getAttribute('data-digits') === 'phone';
    inp.addEventListener('input', function () {
      var v = inp.value.replace(/[০-৯]/g, function (c) { return BNDIGIT[c]; });
      var plus = phone && v.charAt(0) === '+' ? '+' : '';
      v = plus + v.replace(/\D/g, '');
      if (inp.value !== v) { inp.value = v; }
    });
    inp.addEventListener('keypress', function (e) {
      if (e.key && e.key.length === 1 && !/[0-9০-৯]/.test(e.key) && !(phone && e.key === '+' && inp.selectionStart === 0)) { e.preventDefault(); }
    });
  });

  // Lead forms: UTM capture + weekday auto-fill
  var KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];
  var qs = new URLSearchParams(location.search);
  KEYS.forEach(function (k) { var v = qs.get(k); if (v) { safe(function () { sessionStorage.setItem(k, v.slice(0, 120)); }); } });
  $$('form[data-lead]').forEach(function (f) {
    KEYS.forEach(function (k) {
      var inp = f.elements[k]; var v = safe(function () { return sessionStorage.getItem(k); });
      if (inp && v) { inp.value = v; }
    });
    var sp = f.elements.source_page;
    if (sp && !sp.value) {
      var ref = ''; safe(function () { var u = new URL(d.referrer); if (u.host === location.host) { ref = u.pathname; } });
      sp.value = ref || location.pathname;
    }
    var dt = f.elements.preferred_date, day = f.elements.preferred_day;
    if (dt && day) {
      dt.addEventListener('change', function () {
        var t = new Date(dt.value + 'T00:00:00');
        if (!isNaN(t)) { day.value = ['রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার'][t.getDay()]; }
      });
      var today = new Date(); today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
      dt.min = today.toISOString().slice(0, 10);
    }
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = 'পাঠানো হচ্ছে…'; }
      safe(function () { if (window.gtag) { window.gtag('event', 'appointment_submit', {}); } });
      safe(function () { if (window.fbq) { window.fbq('track', 'Lead'); } });
    });
  });
})();

/* Statistics: cards rise in one after another, numbers count up, the bar fills and the '+' pops (Bangla digits) */
(function () {
  var tiles = Array.prototype.slice.call(document.querySelectorAll('.stat'));
  var wrap = document.querySelector('.stats');
  if (!wrap || !tiles.length || !tiles[0].querySelector('.cnt[data-count]')) { return; }
  var BN = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
  var isEn = document.documentElement.lang === 'en';
  var toBn = function (n) { return isEn ? String(n) : String(n).replace(/\d/g, function (d) { return BN[+d]; }); };
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var easeOutExpo = function (p) { return p === 1 ? 1 : 1 - Math.pow(2, -10 * p); };
  var pop = function (tile) {
    var s = tile.querySelector('.sfx'); if (!s) { return; }
    s.classList.add('pop'); setTimeout(function () { s.classList.remove('pop'); }, 300);
  };
  var show = function (tile, i) {
    tile.style.setProperty('--d', (i * 150) + 'ms');
    tile.classList.add('in');
    var el = tile.querySelector('.cnt');
    var to = parseInt(el.getAttribute('data-count'), 10) || 0;
    if (reduce || !window.requestAnimationFrame) { el.textContent = toBn(to); return; }
    el.textContent = toBn(0);
    setTimeout(function () {
      var t0 = null, dur = 1800;
      var step = function (ts) {
        if (t0 === null) { t0 = ts; }
        var p = Math.min(1, (ts - t0) / dur);
        el.textContent = toBn(p < 1 ? Math.floor(easeOutExpo(p) * to) : to);
        if (p < 1) { window.requestAnimationFrame(step); } else { pop(tile); }
      };
      window.requestAnimationFrame(step);
    }, i * 150 + 100);
  };
  var playAll = function () { tiles.forEach(function (t, i) { show(t, i); }); };
  // Tiles stay hidden until the section is seen, but only when JavaScript runs
  document.body.classList.add('js-stats');
  if (!('IntersectionObserver' in window)) { playAll(); return; }
  var io = new IntersectionObserver(function (entries) {
    if (entries.some(function (en) { return en.isIntersecting; })) { io.disconnect(); playAll(); }
  }, { threshold: 0.2 });
  io.observe(wrap);
})();

