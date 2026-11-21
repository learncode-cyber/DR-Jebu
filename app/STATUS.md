# Gemini provider — status

Build: v101 (see the zip name in the delivery message).

Verified offline (stubbed transport, real database, real PHP):
- Group 1 logic: 30/30 (classification, Pacific reset time, privacy guard, credentials, routing).
- Group 2 queue: 38/38 (success, privacy block with no API call, daily/minute quota, 500 to manual,
  503/network retry, 400 fail, invalid key failover in the same project, revoked key to manual,
  model fallback, medical no-downgrade, project ledgers, paid lock, daily cap, claim race, stale recovery, retry/cancel).
- Group 3 worker lock: 3/3 (second connection blocks the worker; released lock lets it run).
- Group 4 paid switch: 2/2 (paid key used only after owner switch).

NOT verified (sandbox cannot reach generativelanguage.googleapis.com; no owner key available):
- Real Gemini requests, real 429 payloads and Retry-After behaviour, real model list.
- Live credential verification with the owner's key.
- The owner's Hostinger cron running gem_worker_tick.

Known gaps:
- Facebook post generator and other older AI calls still use gemini_generate() directly (not the queue).
- API keys are stored in the gem_credentials table (admin-only DB access); not encrypted at rest.
- Health checks are cheap database counts; they make no AI calls.

## Facebook comments (approval first) — v97
- Comments are stored and queued as drafts through the Gemini queue. Nothing is sent until the owner approves in admin > Facebook কমেন্ট.
- Medical or health-risk comments are held for the owner; AI does not draft them.
- One private reply per commenter; private replies only within 7 days; approved text passes the privacy guard.
- Tests: gemini_tests/t5_comments.php (14/14, stubbed Gemini and Meta).
- Not verified live: Meta calls (no token in the sandbox), and whether the owner's webhook subscription delivers comment events.
- Still open (from the discussion): auto-send after two weeks, content sources, image policy, and the manual pin step.

## Facebook posts from source notes (approval first) — v98
- Owner enters a source name and a short summary (not a full copy). Gemini drafts through the queue.
- Finished text: no links (removed in code), credit line "সূত্র: …" and educational line always added in code.
- Nothing goes to the Page until the owner approves. Post goes to /feed; the appointment link is the FIRST comment.
- Tests: gemini_tests/t6_sources.php (12/12, stubbed Gemini and Meta). Needs meta_page_id and meta_page_token set to run.
- Not built: scraping or automatic source fetching; image search and upload; automatic posting after two weeks.

## Image SEO for blog covers — v99
- sitemap.xml: each published blog post with a real cover image gets an image:image entry (caption = image alt, else title).
- Blog post schema: cover is an ImageObject (url, contentUrl, caption).
- Checked: sitemap output is valid XML; temporary test post removed.
- Not done: width/height for OG images; alt text for images without a saved alt.

## Gemini phase 3: every direct call uses the ledger — v101
- gemini_generate() (chat, Facebook post generator, clinical helpers) now uses the credential manager, project ledger, quota blocks, daily cap and privacy guard.
- Patient-type text (phone, email, patient ID, prescription number) is not sent; the chat tells the visitor to contact the clinic directly.
- Trade-off: a chat that includes a phone number cannot pass the conversation to Gemini, so AI-assisted booking with a phone number stops working until the owner decides otherwise.
- Tests: gemini_tests/t7_direct_calls.php (11/11). All groups 1–7 pass offline (110 checks).
- Still open: live verification with a real key; the chat is still real-time (not queued, by design).
- v102: the chat refusal message now includes the appointment form link, so visitors can still book after a phone number is blocked.

## Comment auto-send (time-gated, OFF by default) — v103
- Settings: fb_comments_auto_send (0/1) and fb_auto_from (start date). Both must be set; otherwise nothing is sent.
- Only 'ready' drafts of non-medical comments are sent, through the same checks as the approve button (privacy guard, 7-day window, one reply per person, hourly limit).
- Tests: gemini_tests/t8_autosend.php (run with MODE=on and MODE=off, 3/3 and 1/1).

---

# v104 — Phase A (security and reliability), Phase B (gap analysis), Phase C (part), Phase D (checklist)

## Phase A — done (offline tests; no live calls made)
1. Gemini API key no longer plain text: AES-256-GCM (app/secrets.php). One-time migration of existing rows. Test: t9.
2. Encryption key: <account home>/secure/enc.key, outside public_html, mode 0600, never in the database.
   Fallback (folder not writable) derives the key from APP_KEY and is reported as weaker.
3. Old credentials migrated once (gem_migrate_keys, flag sec_gem_keys_v1). Plain settings of type secret are
   migrated on first read (sec_migrate_settings). Test: t9.
4. Every Facebook post publish goes through the DB queue (app/fb_queue.php): fbp_publish and fbs_publish now enqueue.
5. Duplicate prevention: job_key = kind:ref_id is UNIQUE; a job whose Facebook id exists is never sent again.
   Test: t10 (re-queued post sends nothing).
6. Failed jobs: transient errors (network, 5xx, Graph codes 1/2/4/17/32/613) retry with backoff (1, 2, 4, 8 min…,
   max 5 attempts). Permanent errors and exhausted retries go to 'dead' and raise an alert. Stale locks (10 min)
   are recovered. Owner can re-queue or cancel on admin page "Facebook পাঠানোর কিউ". Test: t10.
7. Cron heartbeat: dispatch.php already writes sys_cron_dispatch_at; the queue worker writes sys_fbq_tick_at.
   The queue page warns when the last run is more than 15 minutes old. Live check: Phase D.
8. First comment is a separate job (kind fbs_comment). A failed comment never re-sends the post. Test: t10.
9. og:image:width and og:image:height are read from the real file; og:image:alt falls back to the page title. Verified
   on the sandbox server (home page). Image alt inside pages: unchanged (empty alt stays empty for decorative images).
10. Static audit: every Gemini HTTP call is in gem_http (router worker, router verify) or gemini_generate (guard, cap,
    ledger). Two router callers and one guarded caller; no other file contains generativelanguage calls.

## Phase C (part) — done
- Approved Medical Source Library (app/medical_sources.php, admin "অনুমোদিত মেডিকেল উৎস"): 8 owner-approved sources;
  each has a terms check (owner marks it after reading the terms). Research items need https on the source's own host.
  Only doctor-approved items pass ms_can_use(). Nothing is fetched or scraped. Test: t11 (19/19).

## Offline test totals (all pass)
t1 30 · t2 38 · t3 3 · t4 2 · t5 14 · t6 12 · t7 11 · t8 on 3 / off 1 · t9 20 · t10 24 · t11 19  = 177 checks.
Run: /home/claude/jebu_medical/gemini_tests (see README.md). Some suites need switches set first; they are listed there.

## NOT verified (sandbox limits)
- No live Gemini call, no live Meta/Graph call, no live LinkedIn or TikTok call. The Facebook queue is tested with a stub.
- The admin pages were syntax-checked only; the sandbox cannot log in to /jebu-cp-3hr3f.
- The Hostinger cron running dispatch.php every 5 minutes is not verified.

## Known gaps (still open)
- The encryption key is on the same server as the database. Keep secure/ out of shared backups.
- Facebook comment reply/private-reply calls are not in the queue yet (only post and first-comment publishing is).
- The AI Facebook post generator is still synchronous (it is ledgered, but not queued).

## v105 — Phase C: SEO keyword plan
- app/seo_keywords.php + admin "কিওয়ার্ড পরিকল্পনা" (SEO group). Owner enters keywords (one per line); the system marks each
  as covered (a published post matches), draft only, or gap (no post matches). Matching uses title, meta title, focus keyword,
  tags and excerpt. No external data, no AI.
- Bug found and fixed during tests: the normaliser removed Bangla vowel signs (\p{M}), which broke Bangla matching.
- Tests: t12_keywords.php 18/18. Offline total now 195 checks.
- Not built: automatic keyword discovery, search volume, competitor data (no approved data source yet).

## v106 — SEO audit (all pages) and title fixes
- Crawled all 74 sitemap URLs (local server): all 200; title, description, one H1, canonical, JSON-LD, image alt and OG image present on all.
- Changed (copy only, design unchanged): search titles for conditions, treatments, services, specialties, procedures, contact and appointment pages.
- Report (Bangla DOCX, not in the ZIP): NAP inconsistencies across directory sites, Search Console findings, owner action list.
- Not verified live: HTTPS/www redirect, 404 status on unknown URLs, Core Web Vitals, Search Console state.
- Open owner data: chamber address, hours and fee (chamber_address is empty; Physician schema has no address).

## v107 — Google Search Central checks (title links, structured data, sitemap, localized versions)
- Titles: a one-time data fix (seo_migrate_v107, run from cron) makes two records with the same search title distinct; owner-edited titles are never changed.
- Titles: a title that already carries the doctor's name is no longer given it twice; long titles are cut at a word boundary (trim_words) with the cap of 60 characters kept.
- Structured data: BlogPosting image uses the doctor's portrait when a post has no cover, never the placeholder graphic.
- Checked, no change needed: unique meta descriptions, one H1 per page, canonical on every page, no noindex on indexable pages, sitemap URLs canonical with lastmod only where it is real, robots.txt sitemap line, alt text on every img, width/height and lazy loading on content images.
- Localized versions: each page has one URL for both languages (language by cookie / ?lang), canonical points to that URL, so no hreflang is emitted (Google's hreflang rules need separate URLs per language).
- Owner action: the LocalBusiness/Physician schema has no address; add the chamber address in settings (Google requires address for LocalBusiness). Do not invent it.
- Offline: run_all.sh 195/195; crawl of the 74 sitemap URLs: all 200, no duplicate titles or descriptions, one H1 each.

## v108 — Facebook comment reply through the queue
- fb_comment_approve() now saves the draft and runs the job fbc_reply:<comment id> (app/fb_queue.php → fbq_do_fbc_reply in app/fb_comments.php).
- Each step is recorded (public_status, private_status). A retry never repeats a public reply that already succeeded.
- Meta codes 10, 100, 190, 200 are permanent (dead-letter with alert); other errors retry with backoff.
- Test: gemini_tests/t13_fbc_reply.php (16/16). Full offline suite 211/211.
- Still not verified live: the Page's comment and private reply on real Meta (see LIVE_CHECKLIST_BN.md).

## v109 — Phase C items 7, 8, 9
- LinkedIn posting goes through the queue (kind li_post): li_post() submits, fbq_do_li_post() classifies. HTTP 400/401/403/404/422 are permanent; other failures retry with backoff. A posted draft is never sent again.
- TikTok: offline tests for the publish flow (gemini_tests/t14_li_tt_errors.php). Public posting still BLOCKED until TikTok's app audit.
- Error center: error_center_snapshot() in app/alerts.php, shown as a panel on the admin system page (jebu-cp-3hr3f/system.php). Admin-only; the public site is unchanged.
- Test hooks added: $GLOBALS['li_http_stub'] (linkedin.php) and $GLOBALS['tt_http_stub'] (tiktok.php), used only by suites.
- Full offline suite: 226/226 (15 runs in run_all.sh).
- Not done in this round: Phase C items 3, 4, 5 (blog drafting from approved research, claim check and doctor approval, blog image picker); item 2 BLOCKED on owner data.

## v110 — Phase C items 3, 4, 5 (blog drafts, claim check, approval, cover source)
- app/blog_drafts.php (new): migration blog_migrate_v109 adds doctor_approved_at, doctor_approved_by, med_research_id, cover_source, cover_credit to blog_posts and creates blog_draft_requests. Existing live posts are marked approved by the migration so they stay published.
- Draft request only for doctor-approved research (ms_can_use). Gemini task blog_draft through gem_submit (privacy guard, usage). blog_collect_drafts() (cron, every 5 min, and the admin page) turns finished output into draft posts. Each draft gets a source block: source name, title, URL, research date.
- Claim check: blog_risk_check() lists absolute or guaranteed-outcome phrases; blog_doctor_approve() refuses while any remain and refuses if the research is no longer approved.
- Publish gate: jebu-cp-3hr3f/entity.php refuses publishing a blog post without doctor_approved_at (single and bulk).
- Cover: the blog form has cover_source and cover_credit; a cover without a source is refused. No automatic image search, copy or generation.
- Admin page: jebu-cp-3hr3f/blog-drafts.php ("ব্লগ খসড়া ও অনুমোদন"), nav item added.
- Public site unchanged.
- Offline: gemini_tests/t15_blog_gate.php 23/23. Full run_all.sh: 249/249 (16 runs).
- Not verified: Gemini live output quality; the admin form's publish gate in a browser.

## v111 — Cover from the owner library, Facebook draft from approved blog (gap items 9, 17)
- blog_set_cover(): cover picked from the owner library (the uploads folder, via upload_files()); the file must exist there; source and credit recorded.
- blog_repurpose_fb(): an approved blog post (doctor_approved_at set, no risk phrase in title, excerpt or body) becomes a Facebook post in fb_posts with status queued and no schedule. The cron only sends queued posts that have a schedule, so the owner publishes it by hand. Refused if already added.
- Admin page blog-drafts.php: library gallery, cover form per approved post, Facebook button per approved post.
- Offline: gemini_tests/t16_cover_fb.php 13/13. Full run_all.sh: 262/262 (17 runs).
- Not built: separate media manager; LinkedIn and TikTok repurposing; automatic image search (by policy).

## v112 — Messenger owner decision 1 fixed; Messenger and UTM tests
- Found: the Messenger rules (app/meta.php fb_safety_rules rule 6) and the booking line of the system prompt (app/fb_reply.php) still asked for name and mobile number. This broke owner decision 1. Both now say: no name, phone or health detail in chat; send the appointment link.
- Behaviour change (intended): the bot no longer asks for a mobile number in Messenger. Bookings go through https://drjebunnesa.online/appointment.
- Test: gemini_tests/t17_messenger_leads.php 11/11 (prompt rules, handoff and pause, silent paused chat, reply with no phone request, UTM stored and cut to length).
- Full run_all.sh: 273/273 (17 runs).
- Not verified live: Gemini replies on real Messenger.

## v113 — Analytics tests (gap item 19), UTM status (item 18)
- Test: gemini_tests/t18_analytics.php 9/9. Server-side Meta Lead and GA4 generate_lead go only to active, server-enabled accounts; phone and email are sent only as SHA-256 hashes; the browser event id is reused for deduplication.
- Test hook added: $GLOBALS['tracking_http_stub'] in app/tracking.php tracking_http_post() (test suites only).
- Item 18 (UTM) is covered by t17.
- Full run_all.sh: 282/282 (18 runs).
- Not verified live: real Meta and GA4 receipt of events.

## v114 — tracking master prompt (GTM, dedup lock, last name, phone click)
- GTM ID moved from admin Settings to the Google Analytics tracking page (own card, act gtm_save). Value still stored as gtm_container_id. Empty = GTM off (no change to the live site). A valid GTM-XXXX ID turns GTM on: GTM is the only browser tag manager, direct gtag/Pixel loaders are not printed, events go to window.dataLayer. Malformed IDs are rejected.
- Thank-you page: one-time lock per lead (table tracking_fired, keyed by event_id). Refresh and back-button do not fire browser events again. Server events are unchanged.
- Meta CAPI: last name sent as a hashed value (ln) for Advanced Matching; raw name never sent.
- Phone-call click (tel: links): dataLayer event phone_call_click in GTM mode; in direct mode gtag event (beacon transport) and Meta Contact. The direct-mode Meta Contact has no event_id (no server twin), so it is not deduplicated.
- Not applicable to this clinic: e-commerce events (view_item, add_to_cart, purchase, begin_checkout).
- Tests: gemini_tests/t19_tracking.php (14/14), registered in run_all.sh. Full offline run: 296 checks, all passing.
- Not verified live: GTM container tags, GA4 and Meta receipt, real tel: click, Advanced Matching quality in Events Manager.

## v116b — Gemini model routes moved into code
- Model order per task is now only in gem_default_routes() (app/gemini_router.php). The admin JSON editor and the gemini_routes setting are removed.
- Medical tasks never use lite/nano models: filtered on every read in gem_routes().
- Changing a model now needs a code update (new ZIP), not an admin edit.
- t1 checks: no admin save function, and every medical task has a non-lite route.

## v118 — tracking hub, browser test mode, duplicate report
- Admin page "সব ট্র্যাকিং ও টেস্ট" (tracking-hub): GTM ID (moved here from the GA4 page), all accounts with server test buttons, browser test mode, duplicate and safety report, event list.
- Browser test mode: 10 minutes, admin browser only (token in link or cookie). Thank-you and phone-click events print to the console as [jebu-test]; nothing goes to gtag, Pixel or dataLayer; real form submissions from that browser send no server events; the one-time lock is not used.
- Duplicate report: warns when GTM's GA4 tag may repeat the server generate_lead; warns when a Meta test code is left on.
- Platform pages (Meta, Google Ads, GA4) stay for add/edit.
- Tests: gemini_tests/t20_tracking_test.php (13/13). Full offline run: 21 suites, all passing.
- Not verified live: the console report in a real browser, GTM Preview, Meta Test Events tab, the hub page rendered in the admin.

## v119 — GTM on its own admin page
- Admin page tracking-gtm (sidebar: Google Tag Manager (GTM)) holds the GTM ID and setup steps. The hub links to it.

## v120 — Khulna SEO start
- Homepage English title (full_title): "<doctor name> | Gynecologist in Khulna" (was "Infertility Specialist in Khulna"). Bangla title and H1 unchanged. No design change.
- Not started, waiting for owner data: /gynecologist-khulna page (needs chamber address, degrees, doctor approval), Semrush error list (36 errors), Google Business Profile and Search Console.

## v121 — Audit fixes (SEO, technical)
- Duplicate title: seo_migrate_v121() gives /services/womens-lifespan-care its own title (only when it is empty or still equal to the specialty title); run from cron dispatch.
- HSTS header added in .htaccess (max-age 1 year, HTTPS only).
- Not changed, decision needed or intentional: credit footer links (arvanceagency.com, arqudrix.com) to verify live; /blog?cat= and ?page=2 and empty /videos stay noindex by design; 302 on ?lang= switch is the cookie language design.

## v122 — Khulna landing page
- New page /gynecologist-khulna (EN + BN): services links to existing service pages, chamber address/hours from settings, BMDC 72244, FAQ, Physician + FAQ schema. Added to sitemap.
- Published on owner instruction (doctor can edit later). Medical wording avoids guarantees and unverified figures.

## v123 — Schema fix
- Homepage Physician schema: availableService now typed Service objects (was plain text). Fixes the 3 markup errors reported for the homepage. Visible text unchanged.

## v124 — Meta privacy gate and Facebook preflight
- Pixel and CAPI are OFF: site setting meta_pixel_privacy_cleared = 0 by default. While off: no fbevents.js, no PageView, no noscript pixel, no Lead/Contact browser event, no server event (tracking_meta_send refuses without a network call). Owner sets it to 1 after the medical privacy review.
- Found and fixed: the public site was loading the Meta pixel and sending Lead events with content_name/lead_type before any review.
- New admin page Facebook যাচাই (fb-preflight.php): shows PASS / WARNING / BLOCKED / NOT CONFIGURED from saved settings only; makes no Meta call; no PASS is shown.
- New offline suite t21_meta_gate.php (6 checks). t18 and t19 now set the clearance for their own run.
- Planning: app/PLANNING.md added (Facebook page automation first, then Pixel/CAPI).
- Not verified live: Page publishing, comment webhook, Messenger, permissions. Owner must provide the Meta app, Page ID and token (in Settings, not chat).

## v125 — Facebook gap analysis, 90-day schedule, overview
- app/FB_GAP_ANALYSIS.md: 23 Facebook features with status (no LIVE-VERIFIED yet).
- Scheduling: a post can be planned up to 90 days ahead; later times refused (fbp_schedule_ok, t22).
- Admin Facebook ওভারভিউ (fb-overview.php): counts from local data only; no Meta call.
- New suite t22 (10 checks). All 23 suites pass.
- Not verified live: publishing, comments, Messenger, permissions, webhook delivery.

## v126 — Scheduling window is a setting, default one year
- fb_schedule_max_days (default 365, clamped 1..3650). Admin refuses later times.
- Meta API scheduling limit: 30 or 75 days (Meta docs differ). Our queue posts with cron, so the limit does not apply; kept a configurable cap for review of stale content.
- t22 updated (12 checks). All 23 suites pass.

## v127 — Comment policy and signature test
- Comment categories (10), rule-based. Emergency, medical advice and pregnancy questions go to medical hold. Spam and negative to owner review.
- Auto-send only for greeting and appreciation; a comment with no category is never sent.
- New column fb_comments.category (added on table setup).
- t23 (23 checks): signature cases and category policy. All 23 suites pass.

## v128 — Admin clinic theme (blue, default)
- Admin panel default theme is now Clinic Blue (white cards, blue actions, light blue background), matching the owner-chosen reference.
- Old black-and-white (mono) is no longer the default; a saved mono choice is ignored. The theme switch keeps all four options.
- Admin only. Public site design unchanged.

## v129 — Clinic theme palette
- Clinic theme now uses an indigo primary (instead of plain blue), lavender-grey background, with green and rose accent classes. Matches the reference more closely. Admin only.

## v130 — Social Media navigation
- Facebook menu now: Dashboard, Settings & Integration, Posts Overview, Publishing Queue, Comments Overview, Messenger, Analytics, AI Automation, Webhooks, Permissions & Diagnostics, Errors & Logs. LinkedIn, TikTok and existing items kept.
- Real pages: Dashboard, Posts, Queue, Comments, Messenger, Permissions (preflight), Errors & Logs (new, from fb_jobs last_error), Webhooks (facts only: callback URL, saved verify token and secret yes/no).
- Settings & Integration opens the site settings page where Meta credentials are saved. A full Meta settings center is NOT built yet.
- Analytics and AI Automation are NOT IMPLEMENTED (pages say so, show no numbers).

## v131 — Facebook configuration center (2026-11-21)
- Done (offline, tested): settings defaults; `app/fb_config.php` validators (App, AI reply, publishing, official URL, first-comment text); `jebu-cp-3hr3f/fb-settings.php` (App config, App Secret replace-only, verify token generator, callback copy); `fb-publishing.php` (mode, per day, max queue, first comment); `fb-ai.php` (AI reply tone/language/CTA/threshold/attempts; human approval always on); `fb-guide.php` (setup steps with official Meta doc links); nav updated; `fb_max_queue` enforced on admin add.
- Tests: t24 (21 checks) added; full suite t1–t24 passes in sandbox.
- NOT TESTED LIVE (BLOCKED until owner gives Meta app and Page credentials): OAuth connect, live permission check (/me/permissions), webhook subscribe check (subscribed_apps), live diagnostics, Messenger live test, comment reply live test, Insights sync. Labelled LIVE-PENDING; not PASS.
- Not implemented: Analytics and AI Automation as separate dashboards (AI reply config exists; analytics still NOT IMPLEMENTED).
