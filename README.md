# Dr. Jebunnesa Jebu — Medical Website (Plain PHP)

Plain PHP (no framework, no build step). Multi-page, clean URLs, MySQL via PDO prepared statements,
GSAP hero animation, lead automation and a role-based admin panel.

## Folder layout
```
jebu_medical/
├─ app/                      <- PRIVATE (blocked by .htaccess; upload it ABOVE public_html if you can)
│  ├─ bootstrap.php          boot, security headers, error handler
│  ├─ config.sample.php      COPY to config.php and fill in
│  ├─ db.php                 PDO connection + q()/q_one()/q_all() helpers
│  ├─ lib.php                helpers: settings, markdown, CSRF-less form tokens, logging, IndexNow
│  ├─ defaults.php           default copy + settings schema (editable in admin)
│  ├─ entities.php           content types (services, conditions, ...) — one definition drives public pages AND admin CRUD
│  ├─ leads.php              LEAD AUTOMATION: store -> event trail -> notifications queue -> dispatch
│  ├─ views/                 layout, entity pages, lead form
│  ├─ database/jebu_medical_db.sql   single merged schema + seed data
│  └─ cron/dispatch.php      retries queued notifications (cPanel cron)
└─ public_html/              <- upload CONTENTS of this folder to the web root
   ├─ index.php, about.php, specialties.php, services.php, conditions.php, treatments.php, procedures.php,
   ├─ specialty-detail.php, service-detail.php, condition-detail.php, treatment-detail.php, procedure-detail.php,
   ├─ blog.php, blog-single.php, testimonials.php, faqs.php, videos.php, appointment.php, contact.php,
   ├─ privacy-policy.php, terms.php, sitemap.php, feed.php, 404.php
   ├─ .htaccess              clean URLs (/about not /about.php), security headers, dotfile block
   ├─ assets/css/site.css    theme variables on :root (plum palette), responsive layout
   ├─ assets/css/admin.css
   ├─ assets/js/site.js      nav, reveal, tracking, lead form helpers
   ├─ assets/js/hero.js      GSAP hero: floating icons/bubbles, pointer repulsion, 720° switcher
   ├─ assets/js/motion.min.js, scroll.min.js   GSAP 3.12.5 + ScrollTrigger (local copy)
   ├─ assets/fonts/          Tiro Bangla (OFL, self-hosted fallback)
   ├─ uploads/               blog covers & doctor images (uploads/.htaccess blocks PHP execution)
   └─ jebu-cp-3hr3f/         ADMIN panel (login, setup, leads, lead detail, content CRUD, settings, users, logs)
```

## Install (cPanel)
1. **Database**: cPanel > MySQL Databases → create DB + user, grant ALL PRIVILEGES.
2. **Schema**: phpMyAdmin → select DB → Import → `app/database/jebu_medical_db.sql`.
3. **Config**: copy `app/config.sample.php` → `app/config.php`; set DB name/user/password, `APP_KEY` (40+ random chars), `SITE_URL`, `NOTIFY_EMAIL_TO`, `MAIL_FROM` (a mailbox on your domain).
4. **Upload**: `app/` and `public_html/` (contents go into the web root).
5. **First admin**: open `/jebu-cp-3hr3f/setup` once, enter APP_KEY + your details. The page locks itself after the first account.
6. **Cron** (cPanel > Cron Jobs, every 10 min):
   `*/10 * * * * /usr/local/bin/php /home/CPUSER/jebu_medical/app/cron/dispatch.php >/dev/null 2>&1`
7. **Content**: log in → সাইট সেটিংস (phone, WhatsApp, about, credentials, social, map) → then বিশেষত্ব / সেবা / রোগ / চিকিৎসা / প্রসিডিওর / ব্লগ / রিভিউ / FAQ / ভিডিও.

## Lead automation (appointment.php / contact.php)
Every submission: validate → honeypot + signed time-token (bot filter) → rate limit (5 / IP / 10 min) → duplicate guard (same phone within 15 min) → `leads` row → `lead_events` trail → queued notifications
(clinic email, patient acknowledgement email if they gave one, optional webhook with HMAC `X-Signature`) → dispatched after the response is sent.
Admin: লিডস list with filters, CSV export (formula-injection guarded), status / owner / internal notes, full tracking history, notification log, resend.

## Security notes
- All SQL via PDO prepared statements; output escaped with `h()`.
- Admin: password_hash (bcrypt), session cookie httponly + samesite, CSRF token on every POST, login lockout (5 fails / 15 min), roles (admin, manager, editor, doctor, reception), activity log.
- Uploads: MIME verified with getimagesize, random filenames, PHP execution blocked in `uploads/`.
- `app/` is denied via `.htaccess`; `config.php`, `*.sql`, `*.md` files are not served.
- Tech-stack hygiene: `X-Powered-By` removed, no generator meta tags, no framework fingerprints.
- Medical content: forms ask patients not to submit reports/sensitive details.

## Known limits (be honest with the client)
- Not yet verified on a live MySQL/MariaDB server in this build environment; smoke-tested with PHP 8.3 + SQLite (same code paths, prepared statements). Run the install once on staging before go-live.
- Email delivery depends on cPanel `mail()` working with `MAIL_FROM` on your domain. Failures are visible in admin → লগ → নোটিফিকেশন.
- Blog and condition body copy in the seed is the existing approved text; new medical content should be reviewed by the doctor.

## Ad tracking (Meta, Google Ads, Google Analytics)

Three admin pages, one table (`tracking_accounts`), any number of accounts:

- **Meta Ads ট্র্যাকিং**: প্রতিটি Pixel ID আলাদা অ্যাকাউন্ট। Access Token, ঐচ্ছিক Test event code, সার্ভার-সাইড ইভেন্ট চালু/বন্ধ।
- **Google Ads ট্র্যাকিং**: প্রতিটি AW- ID ও লিড কনভার্সন label আলাদা অ্যাকাউন্ট।
- **Google Analytics ট্র্যাকিং**: প্রতিটি G- Measurement ID আলাদা অ্যাকাউন্ট। API Secret, সার্ভার-সাইড ইভেন্ট চালু/বন্ধ।

How it works:
- সব সক্রিয় অ্যাকাউন্ট সাইটের `<head>`-এ নিজে থেকেই বসে (Pixel, gtag). `<body>`-র শুরুতে Meta no-script fallback.
- লিড জমা হলে প্রতিটি সার্ভার-চালু Pixel ও GA4 property-তে আলাদা ইভেন্ট যায়। Meta-তে ব্রাউজার ও সার্ভার একই `event_id` দিয়ে ডুপ্লিকেট বাদ দেয়।
- GA4 সার্ভার চালু থাকলে ব্রাউজার থেকে একই `generate_lead` যায় না, যাতে দুবার গণনা না হয়।
- Secret (Access Token, API Secret) শুধু সার্ভারে ব্যবহার হয়, কোনো পেজে ছাপানো হয় না। সম্পাদনার সময় খালি রাখলে আগের মান থাকে।
- পুরনো এক-অ্যাকাউন্টের সেটিং থাকলে প্রথমবার অ্যাডমিনে এলে সেগুলো `tracking_accounts`-এ একবার স্বয়ংক্রিয়ভাবে যোগ হয়।
- Google Ads-এর সার্ভার-সাইড কনভার্সন (Google Ads API, developer token, OAuth) এখানে নেই। লিড কনভার্সন ধন্যবাদ পেজ থেকে যায়।

