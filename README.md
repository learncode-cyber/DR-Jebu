# Dr. Jebunnesa Jebu, Medical Website

Plain PHP 8.x + MariaDB. No framework, no build step.

## Setup
1. Import `app/database/jebu_medical_full.sql` into MariaDB.
2. Copy `app/config.sample.php` to `app/config.php` and fill in DB and `APP_KEY`.
3. Open `/jebu-cp-3hr3f/setup` once to create the first admin account.

## Admin
- Panel: `/jebu-cp-3hr3f/` (path set by `ADMIN_PATH` in config).
- Settings: general site settings, plus separate **Meta Ads ট্র্যাকিং** and **Google Ads ট্র্যাকিং** pages.
- Tracking secrets (Meta access token, GA4 API secret) are stored in the database, never printed on pages, and must not be committed.

## Tracking
- Browser: Meta Pixel, Google tag (GA4 + Google Ads conversion) in `app/views/layout.php`.
- Server: Meta Conversions API and GA4 Measurement Protocol, sent after a lead is saved (`app/tracking.php`).
- Lead deduplication: the thank-you page uses the same `event_id` as the server event.

## Layout
- `app/`, core helpers, views, leads, SEO, AI, tracking
- `jebu-cp-3hr3f/`, admin pages
- `assets/`, CSS, JS, images
- `uploads/`, media uploaded through the admin
