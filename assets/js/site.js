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
