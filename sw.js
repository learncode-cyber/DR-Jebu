/* Service worker: lets the site install as an app and keeps static files available when the network drops.
   Pages, forms, the admin panel and API calls are never cached, so bookings and content are always live. */
var CACHE = 'jebu-app-v42';
var OFFLINE_HTML = '<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
  '<title>অফলাইন</title><body style="font-family:system-ui,sans-serif;background:#fdf5f9;color:#4a0f2e;padding:2rem;text-align:center">' +
  '<h1 style="font-size:1.3rem">ইন্টারনেট সংযোগ নেই</h1><p>সংযোগ ফিরে এলে আবার চেষ্টা করুন।</p></body></html>';

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (cache) {
    return Promise.all(['/assets/img/icons/icon-192.png', '/assets/img/icons/icon-512.png', '/assets/img/doctor-portrait.jpg']
      .map(function (u) { return cache.add(u).catch(function () {}); }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }

  // Static files: network first (so updates show at once), cache when offline.
  if (url.pathname.indexOf('/assets/') === 0) {
    event.respondWith(fetch(req).then(function (res) {
      if (res && res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
      return res;
    }).catch(function () { return caches.match(req); }));
    return;
  }

  // Page navigations: always the live page; offline, show the last cached home page or a short notice.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(function () {
      return caches.match('/').then(function (r) {
        return r || new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      });
    }));
  }
});
