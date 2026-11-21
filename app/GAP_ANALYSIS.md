# Gap analysis — master prompt vs. current code (v104)

Status scale: PLANNED · DESIGNED · IMPLEMENTED · TESTED · VERIFIED · BLOCKED
- PLANNED: written down only, no code.
- DESIGNED: design or schema exists, no working feature.
- IMPLEMENTED: real code path exists; not covered by an offline test.
- TESTED: real code path with an offline test (stubbed external services).
- VERIFIED: checked against the live service. NO feature is VERIFIED yet (see LIVE_CHECKLIST_BN.md).
- BLOCKED: cannot proceed without something the owner or an external party must provide. The reason is given.

A UI page alone is never counted as IMPLEMENTED.

| # | Feature | Status | Evidence in code | What is missing |
|---|---|---|---|---|
| 1 | Keyword Research Automation | TESTED | app/seo_keywords.php: owner-entered keywords, coverage against blog posts (covered / draft only / gap), admin "কিওয়ার্ড পরিকল্পনা"; t12 (18/18) | Automatic keyword discovery is NOT built (needs a data source, see 3). Coverage is text matching only. |
| 2 | Competitor Research | PLANNED | none | No data source or design. |
| 3 | Google Trends Integration | BLOCKED | none | Google Trends has no official public API. Owner decision needed: a licensed data provider, or manual entry of trend numbers. |
| 4 | Google Search Console Integration | BLOCKED | Only the verification meta tag (gsc_verification) | Needs a Google OAuth client or service account and the site verified in GSC by the owner. Nothing is built that calls the GSC API. |
| 5 | Medical Source Research | TESTED | app/medical_sources.php, admin "অনুমোদিত মেডিকেল উৎস", test t11 (19/19) | Terms check per source is done by the owner (checkbox). No feed pull, by design (no scraping). |
| 6 | AI Blog Writing | TESTED | app/blog_drafts.php: draft request only from doctor-approved research (ms_can_use), Gemini task blog_draft, cron collects finished drafts as draft posts; admin "ব্লগ খসড়া ও অনুমোদন"; t15 (23/23) | Live Gemini not verified. Drafts are text only; a doctor must still review every draft. |
| 7 | Medical Claim Verification | TESTED | blog_risk_check(): fixed list of absolute or guaranteed-outcome phrases blocks doctor approval until removed; doctor approval gate; t15 | Lexical check only, not a medical review. The doctor approval is the real gate. |
| 8 | Blog Approval & Publishing | TESTED | entity.php: single and bulk publish need doctor_approved_at (blog_can_publish); posts live before v110 marked approved by migration; t15 | Admin form path checked by code review, not in a browser. |
| 9 | Automatic Blog Image | TESTED | Owner picks a cover from the owner library (uploads folder) in admin "ব্লগ খসড়া ও অনুমোদন"; blog_set_cover() refuses files not in the library and needs a source (owner, licensed, AI educational) and a credit for the last two; t15, t16 | No automatic image search, copying or generation. Library is the existing uploads folder; no separate media manager. |
| 10 | Facebook Full Management | TESTED | Post queue (fb_queue.php, t10 24/24), source posts (fb_sources.php, t6 12/12), AI posts through the queue | Live Page calls not verified. Comment reply and private reply are queued (fbc_reply, t13). |
| 11 | All-Post Comment Monitoring | TESTED | fb-webhook.php → fb_parse_comments / fb_store_comments; t5 (14/14) | The webhook subscription to the Page's feed events is not verified live. |
| 12 | Comment Classification & Reply | TESTED | app/fb_comments.php: medical comments are held for the owner; other comments get a Gemini draft; owner approves; t5, t8 | Live Gemini not verified. |
| 13 | Messenger Automation | TESTED | app/fb_reply.php (send, split, typing), app/fb_webhook.php, app/meta.php (rules); t17 (11/11) | Owner decision 1 was NOT applied before v112: the rules asked for name and mobile. Fixed in v112 (rules and booking line no longer collect personal or health details). Live send not verified. |
| 14 | First Comment Appointment CTA | TESTED | fbs_comment job (t6, t10); link is abs_url('/appointment') | Live comment not verified. |
| 15 | LinkedIn Management | TESTED | app/linkedin.php: OAuth connect, drafts, POST to api.linkedin.com/rest/posts, token expiry check | Posting goes through the queue (li_post, retry and dead-letter), t14. Live post not verified. Needs a LinkedIn app with w_member_social (owner). |
| 16 | TikTok Content Studio & Approved Publishing | TESTED | app/tiktok.php: OAuth, drafts, upload init, status poll; approval via admin | Offline publish flow tested (t14: success, rejected init, failed upload). Public posting needs TikTok's app audit (until then posts are private only). Owner must complete the audit: BLOCKED for public posts. |
| 17 | Social Content Repurposing | TESTED | blog_repurpose_fb(): an approved blog post becomes a Facebook draft in the queue with no schedule (never sent by the cron; the owner publishes by hand). t16 | Only blog to Facebook. LinkedIn and TikTok repurposing not built. |
| 18 | Appointment UTM Attribution | TESTED | app/leads.php stores utm_source, utm_medium, utm_campaign from the visit URL; lead_form.php passes them | t17 (UTM stored and cut to length). Live visit not verified. |
| 19 | Analytics | TESTED | app/tracking.php: GTM container (optional, off by default; when set, GTM is the only browser tag manager and events go to window.dataLayer), GA4, Meta Pixel and Ads; server events (Meta CAPI, GA4 Measurement Protocol) with shared event_id; email, phone, first and last name hashed; one-time thank-you lock (table tracking_fired); phone-call click event; admin tracking-ga4.php, tracking-meta.php, tracking-google.php | t18 (9/9) server events; t19 (14/14) GTM routing, lock, last name, phone click. E-commerce events (view_item, add_to_cart, purchase, begin_checkout) NOT applicable: the site has no cart or checkout. Live events, GTM container tags and Meta/GA4 receipt not verified. Browser phone-click event in non-GTM mode sends Meta Contact without an event_id (browser-only, no server twin). |
| 20 | Central Error Center | TESTED | app/alerts.php (system_alerts table, cron_status); jebu-cp-3hr3f/system.php; queue dead-letter alerts now feed it (fb_queue.php) | Admin page panel (dead-letter jobs, retries, LinkedIn and TikTok token state) added in v109; data checked in t14. Admin page render not checked live. Gemini and LinkedIn/TikTok alerts use the same table but not every path is covered. |
| 21 | Automatic Error Recovery | TESTED | Gemini: backoff, fallback model, failover within project (t2). Facebook queue: retry, stale-lock recovery, dead-letter (t10). | Not yet for LinkedIn, TikTok and the Messenger send path. |

## Phase A status (summary)
Done, see STATUS.md. The security items 1–10 are implemented; offline tests cover items 1–6, 8, 9 (OG tags checked on the sandbox server).

## Remaining Phase C (each needs its own test before it is called done). Keyword plan (old item 1) is DONE in v105.
2. Competitor intelligence: blocked on data source (see 2).
3. Medical blog research and writing: DONE in v110 (draft only from doctor-approved research; t15).
4. Source verification and doctor approval workflow: DONE in v110 (risk check + doctor approval gate on publish; t15).
5. Blog image management: DONE in v110 (recorded cover source; no automatic search or copy; t15).
6. Facebook queue: comment reply and private reply through the queue — DONE in v108 (t13 16/16).
7. LinkedIn: queue and tests — DONE in v109 (t14).
8. TikTok content studio: offline tests DONE in v109 (t14); public posting stays BLOCKED until the app audit.
9. Central error center — DONE in v109 (system page panel).

## Blocked on the owner or an outside party
- Google Trends data source (decision).
- Google Search Console API (OAuth client, property verified by the owner).
- TikTok public posting (app audit by TikTok).
- Live credentials for Gemini, Meta, LinkedIn, TikTok, Telegram (entered in admin Settings only).
