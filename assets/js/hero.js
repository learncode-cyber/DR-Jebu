/* Hero: floating medical icons + bubbles with parallax & pointer repulsion,
   and a 3-card service switcher (720° spin, motion blur, colour morph, particle re-layout). */
(function () {
  'use strict';
  var hero = document.getElementById('hero');
  if (!hero) { return; }
  var G = window.gsap;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var themes = [];
  try { themes = JSON.parse(hero.getAttribute('data-themes') || '[]'); } catch (e) { themes = []; }
  if (!themes.length) { return; }

  var field = document.getElementById('heroField');
  var orbWrap = document.getElementById('orbWrap');
  var orbIcon = document.getElementById('orbIcon');
  var cards = Array.prototype.slice.call(hero.querySelectorAll('.svc-card'));
  var tKicker = document.getElementById('heroKicker'), tTitle = document.getElementById('heroTitle'),
      tSub = document.getElementById('heroSub'), tMore = document.getElementById('heroMore');
  var copyEls = [tKicker, tTitle, tSub, tMore].filter(Boolean);
  var cur = 0, busy = false;

  function iconHTML(i) {
    var t = document.getElementById('icon-' + i);
    return t ? t.innerHTML : '';
  }

  // ---------- floating shapes ----------
  var SHAPES = [
    '<svg viewBox="0 0 64 64"><rect x="8" y="22" width="48" height="20" rx="10" fill="#fff" fill-opacity=".92" transform="rotate(-35 32 32)"/><path d="M32 22h0" /><rect x="8" y="22" width="24" height="20" rx="10" fill="#f8bbd0" transform="rotate(-35 32 32)"/></svg>', // capsule
    '<svg viewBox="0 0 64 64"><path d="M26 8h12v18h18v12H38v18H26V38H8V26h18z" fill="#fff" fill-opacity=".92"/></svg>', // cross
    '<svg viewBox="0 0 64 64"><path d="M32 56S8 41 8 24a13 13 0 0 1 24-7 13 13 0 0 1 24 7c0 17-24 32-24 32z" fill="#f8bbd0"/></svg>', // heart
    '<svg viewBox="0 0 64 64"><path d="M32 6C24 18 14 28 14 40a18 18 0 0 0 36 0C50 28 40 18 32 6z" fill="#fff" fill-opacity=".9"/></svg>', // drop
    '<svg viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round"><path d="M18 6c0 14 28 14 28 26S18 46 18 58M46 6c0 14-28 14-28 26s28 14 28 26"/><path d="M22 14h20M20 32h24M22 50h20" stroke-opacity=".7"/></svg>', // DNA
    '<svg viewBox="0 0 64 64" fill="none"><circle cx="32" cy="24" r="16" stroke="#fff" stroke-width="3.5" fill="#f8bbd0" fill-opacity=".85"/><circle cx="32" cy="24" r="5" fill="#fff"/><path d="M32 40v12" stroke="#fff" stroke-width="4" stroke-linecap="round"/><circle cx="32" cy="56" r="4" fill="#fff" fill-opacity=".9"/></svg>', // rattle
    '<svg viewBox="0 0 64 64"><circle cx="32" cy="28" r="18" fill="#fff" fill-opacity=".9"/><circle cx="32" cy="34" r="9" fill="#f8bbd0"/><circle cx="22" cy="22" r="3" fill="#f8bbd0"/><circle cx="42" cy="22" r="3" fill="#f8bbd0"/></svg>' // soft teddy-ear motif
  ];
  var mobile = window.innerWidth < 720;
  var nIcons = mobile ? 8 : 15, nBub = mobile ? 10 : 22;
  var items = [];
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function make(kind, i) {
    var el = document.createElement('div');
    var size;
    if (kind === 'bub') { el.className = 'bub'; size = rnd(10, 46); }
    else { el.className = 'fl'; size = rnd(26, 58); el.innerHTML = SHAPES[i % SHAPES.length]; }
    el.style.width = el.style.height = size + 'px';
    field.appendChild(el);
    return { el: el, kind: kind, size: size, fx: Math.random(), fy: Math.random(), x: 0, y: 0, vx: 0, vy: 0,
      depth: rnd(0.3, 1.6), ph: rnd(0, 6.28), amp: rnd(6, 20), sp: rnd(0.4, 1.1), rot: rnd(0, 360), vr: rnd(-12, 12) * (kind === 'bub' ? 0 : 1) };
  }
  for (var a = 0; a < nIcons; a++) { items.push(make('fl', a)); }
  for (var b = 0; b < nBub; b++) { items.push(make('bub', b)); }

  var layouts = [
    function (it, i, n) { it.tx = Math.random(); it.ty = Math.random(); },                                  // scatter
    function (it, i, n) { var ang = (i / n) * 6.283; it.tx = 0.74 + Math.cos(ang) * 0.2; it.ty = 0.5 + Math.sin(ang) * 0.34; }, // ring around the orb
    function (it, i, n) { it.tx = (i / n); it.ty = 0.18 + 0.64 * Math.abs(Math.sin(i * 0.9)); }               // wave band
  ];
  function relayout(k, animate) {
    var fn = layouts[k % layouts.length];
    items.forEach(function (it, i) {
      fn(it, i, items.length);
      if (animate && G) { G.to(it, { fx: it.tx, fy: it.ty, duration: 1.6, ease: 'power3.inOut', delay: (i % 9) * 0.03 }); }
      else { it.fx = it.tx; it.fy = it.ty; }
    });
  }
  relayout(0, false);

  // ---------- pointer force-field + parallax ----------
  var W = 0, H = 0, mx = -9999, my = -9999, px = 0, py = 0, tpx = 0, tpy = 0, R = 150;
  function measure() { var r = hero.getBoundingClientRect(); W = r.width; H = r.height; }
  measure();
  window.addEventListener('resize', measure);
  hero.addEventListener('pointermove', function (e) {
    var r = hero.getBoundingClientRect();
    mx = e.clientX - r.left; my = e.clientY - r.top;
    tpx = (mx / r.width - 0.5) * 2; tpy = (my / r.height - 0.5) * 2;
  });
  hero.addEventListener('pointerleave', function () { mx = my = -9999; tpx = tpy = 0; });

  var AMP = reduce ? 0.35 : 1;
  var t0 = performance.now();
  function frame() {
    var t = (performance.now() - t0) / 1000;
    px += (tpx - px) * 0.06; py += (tpy - py) * 0.06;
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      var tx = it.fx * W + px * it.depth * 38 - it.size / 2;
      var ty = it.fy * H + py * it.depth * 38 - it.size / 2 + Math.sin(t * it.sp + it.ph) * it.amp * AMP;
      if (it.x === 0 && it.y === 0) { it.x = tx; it.y = ty; }
      var dx = it.x + it.size / 2 - mx, dy = it.y + it.size / 2 - my, dist = Math.sqrt(dx * dx + dy * dy);
      if (!reduce && dist < R && dist > 0.1) {
        var f = Math.pow(1 - dist / R, 2) * 5.2;
        it.vx += (dx / dist) * f; it.vy += (dy / dist) * f;
      }
      it.vx += (tx - it.x) * 0.018; it.vy += (ty - it.y) * 0.018;
      it.vx *= 0.9; it.vy *= 0.9;
      it.x += it.vx; it.y += it.vy;
      it.rot += it.vr * 0.016 * AMP;
      it.el.style.transform = 'translate3d(' + it.x.toFixed(1) + 'px,' + it.y.toFixed(1) + 'px,0) rotate(' + it.rot.toFixed(1) + 'deg)';
    }
  }
  // Own rAF loop: runs only while the hero is on screen and the tab is visible (saves battery on phones)
  var raf = 0, inView = true;
  function tick() { raf = 0; if (!inView || document.hidden) { return; } frame(); raf = requestAnimationFrame(tick); }
  function startLoop() { if (!raf && inView && !document.hidden) { raf = requestAnimationFrame(tick); } }
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (es) { inView = es[0].isIntersecting; startLoop(); }, { threshold: 0 }).observe(hero);
  }
  document.addEventListener('visibilitychange', startLoop);
  startLoop();

  // ---------- switcher ----------
  function setCopy(t) {
    if (tKicker) { tKicker.textContent = t.kicker; }
    if (tTitle) { tTitle.textContent = t.title; }
    if (tSub) { tSub.textContent = t.sub; }
    if (tMore) { tMore.textContent = t.linkLabel; tMore.setAttribute('href', t.link); }
  }
  function setPressed(i) { cards.forEach(function (c, k) { c.setAttribute('aria-pressed', k === i ? 'true' : 'false'); }); }

  function go(i) {
    if (busy || i === cur) { return; }
    var t = themes[i];
    cur = i; setPressed(i);
    if (!G || reduce) {
      setCopy(t);
      hero.style.setProperty('--bg1', t.bg1); hero.style.setProperty('--bg2', t.bg2); hero.style.setProperty('--glow', t.glow);
      relayout(i, false); return;
    }
    busy = true;
    var tl = G.timeline({ onComplete: function () { busy = false; G.set(orbWrap, { filter: 'none' }); } });
    // 720° spin with motion blur at peak speed
    tl.to(orbWrap, { rotationY: '+=720', duration: 1.5, ease: 'power3.inOut' }, 0)
      .to(orbWrap, { filter: 'blur(11px)', scale: 0.92, duration: 0.55, ease: 'power2.in' }, 0)
      .to(orbWrap, { filter: 'blur(0px)', scale: 1, duration: 0.7, ease: 'power2.out' }, 0.85)
      // background colour morph
      .to(hero, { '--bg1': t.bg1, '--bg2': t.bg2, '--glow': t.glow, duration: 1.3, ease: 'power2.inOut' }, 0)
      // copy cross-fade
      .to(copyEls, { opacity: 0, y: -16, duration: 0.3, stagger: 0.04, ease: 'power2.in' }, 0)
      .add(function () { setCopy(t); }, 0.5)
      .fromTo(copyEls, { opacity: 0, y: 22 }, { opacity: 1, y: 0, duration: 0.55, stagger: 0.07, ease: 'power3.out' }, 0.52);
    // particles regroup
    relayout(i, true);
  }
  cards.forEach(function (c, i) {
    c.addEventListener('click', function () { go(i); });
  });

  // ---------- intro + scroll parallax ----------
  if (G && !reduce) {
    G.set(orbWrap, { transformPerspective: 1100 });
    G.fromTo(orbWrap, { scale: 0.6, opacity: 0, rotationY: -140 }, { scale: 1, opacity: 1, rotationY: 0, duration: 1.4, ease: 'power3.out', delay: 0.15 });
    G.to('.hero-copy .reveal-h', { opacity: 1, y: 0, duration: 0.9, stagger: 0.1, ease: 'power3.out', delay: 0.2 });
    G.from('.svc-card', { y: 30, opacity: 0, duration: 0.8, stagger: 0.1, ease: 'power3.out', delay: 0.7 });
    if (window.ScrollTrigger) {
      G.registerPlugin(window.ScrollTrigger);
      // Desktop only: a gentle parallax on the orb. The text is never faded or moved by scroll,
      // so buttons stay solid and titles never slide over the service cards.
      if (window.matchMedia && window.matchMedia('(min-width: 981px)').matches) {
        G.to('.hero-stage', { yPercent: -10, ease: 'none', scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: true } });
      }
    }
  } else {
    G && G.set('.hero-copy .reveal-h', { opacity: 1, y: 0 });
    var rs = hero.querySelectorAll('.reveal-h'); for (var k = 0; k < rs.length; k++) { rs[k].style.opacity = 1; rs[k].style.transform = 'none'; }
  }
})();
