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
    b.addEventListener('click', function () {
      var li = b.parentNode, o = li.classList.toggle('open');
      b.setAttribute('aria-expanded', o ? 'true' : 'false');
    });
  });
  d.addEventListener('click', function (e) {
    if (!e.target.closest('.has-sub')) { $$('.has-sub.open').forEach(function (l) { l.classList.remove('open'); }); }
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

/* Statistics: count up from 0 when the numbers scroll into view (Bangla digits) */
(function () {
  var items = Array.prototype.slice.call(document.querySelectorAll('.stat .cnt[data-count]'));
  if (!items.length) { return; }
  var BN = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
  var toBn = function (n) { return String(n).replace(/\d/g, function (d) { return BN[+d]; }); };
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var run = function (el) {
    var to = parseInt(el.getAttribute('data-count'), 10) || 0;
    if (reduce || !window.requestAnimationFrame) { el.textContent = toBn(to); return; }
    var t0 = null, dur = 1400;
    el.textContent = toBn(0);
    var step = function (ts) {
      if (t0 === null) { t0 = ts; }
      var p = Math.min(1, (ts - t0) / dur);
      el.textContent = toBn(Math.round(to * (1 - Math.pow(1 - p, 3))));
      if (p < 1) { window.requestAnimationFrame(step); }
    };
    window.requestAnimationFrame(step);
  };
  if (!('IntersectionObserver' in window)) { items.forEach(run); return; }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { run(en.target); io.unobserve(en.target); } });
  }, { threshold: 0.15, rootMargin: '0px 0px -8% 0px' });
  items.forEach(function (el) { el.textContent = toBn(0); io.observe(el); });
})();

/* Sticky action buttons: drag to move; position is remembered in this browser */
(function () {
  var box = document.getElementById('stickyActions');
  var grip = document.getElementById('saGrip');
  if (!box || !grip) { return; }
  var KEY = 'jebu_sa_pos';
  var store = function (v) { try { if (v === null) { window.localStorage.removeItem(KEY); } else { window.localStorage.setItem(KEY, JSON.stringify(v)); } } catch (e) {} };
  var load = function () { try { var v = JSON.parse(window.localStorage.getItem(KEY) || 'null'); return v && typeof v.x === 'number' && typeof v.y === 'number' ? v : null; } catch (e) { return null; } };
  var saved = load();
  var place = function (x, y) {
    var r = box.getBoundingClientRect();
    x = Math.max(6, Math.min(x, window.innerWidth - r.width - 6));
    y = Math.max(6, Math.min(y, window.innerHeight - r.height - 6));
    box.style.left = x + 'px'; box.style.top = y + 'px'; box.style.right = 'auto'; box.style.bottom = 'auto';
    return { x: x, y: y };
  };
  var reset = function () {
    box.style.left = ''; box.style.top = ''; box.style.right = ''; box.style.bottom = '';
    store(null); saved = null;
  };
  if (saved) { place(saved.x, saved.y); }
  var drag = null, moved = false, suppressClick = false;
  var startDrag = function (e) {
    var r = box.getBoundingClientRect();
    drag = { dx: e.clientX - r.left, dy: e.clientY - r.top, sx: e.clientX, sy: e.clientY };
    moved = false;
  };
  box.addEventListener('pointerdown', function (e) {
    if (e.button !== undefined && e.button !== 0) { return; }
    startDrag(e);
  });
  box.addEventListener('pointermove', function (e) {
    if (!drag) { return; }
    if (!moved) {
      if (Math.abs(e.clientX - drag.sx) + Math.abs(e.clientY - drag.sy) < 8) { return; }
      moved = true;
      box.classList.add('dragging');
      try { box.setPointerCapture(e.pointerId); } catch (err) {}
    }
    e.preventDefault();
    place(e.clientX - drag.dx, e.clientY - drag.dy);
  });
  var end = function () {
    if (!drag) { return; }
    var wasMoved = moved;
    drag = null; moved = false;
    box.classList.remove('dragging');
    if (wasMoved) {
      suppressClick = true;
      var r = box.getBoundingClientRect();
      saved = { x: r.left, y: r.top };
      store(saved);
    }
  };
  box.addEventListener('pointerup', end);
  box.addEventListener('pointercancel', end);
  // a drag must not also open a button
  box.addEventListener('click', function (e) {
    if (suppressClick) { e.preventDefault(); e.stopPropagation(); suppressClick = false; }
  }, true);
  grip.addEventListener('dblclick', reset);
  window.addEventListener('resize', function () { if (saved) { var p = place(saved.x, saved.y); saved = p; } });
})();
