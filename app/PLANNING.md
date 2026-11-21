# Planning — Dr. Jebunnesa Jebu site and automation

Goal: a real, safe, 80–90% automated AI-assisted site, SEO, Facebook, LinkedIn and TikTok management.
Stack: PHP 8.3, MariaDB, cron. No Node.js backend. Existing public pages must keep working.

## Owner decisions (permanent — do not change without the owner)

1. Messenger and patient data: the bot does NOT collect a patient's phone number or personal health information in Messenger.
   Appointments use this link only: https://drjebunnesa.online/appointment
2. Image source policy is HYBRID:
   - the owner's own image library;
   - properly licensed image sources;
   - original AI-generated educational images where needed.
   No image is copied from another website without permission. No automatic image search or upload is built.
3. Medical content research is based on the APPROVED OFFICIAL SOURCE LIBRARY:
   WHO, NHS, CDC, NICE, ACOG, RCOG, FIGO, PubMed, and relevant official government health bodies.
   Use an official RSS/API feed where one exists. Where none exists, use public information under that
   source's terms and access rules. No indiscriminate scraping.
   Keep, for every source: name, URL, research date, and the link to the article it supports.
   Facebook main post: the source NAME is shown, the URL is NOT. The appointment link is in the FIRST COMMENT.

## Standing rules (from the brief)

- Gemini API key and every other secret: encrypted at rest; key file outside the public web root.
- Every Facebook post publish goes through the database queue (idempotent, retry, dead-letter).
- The first comment is its own queue job.
- Every direct AI API call goes through the Gemini router, the privacy guard, and usage tracking.
- No mock or placeholder is reported as real automation.
- Offline tests and live integration tests are reported separately.
- Nothing is "Production-VERIFIED" until it has been tested live.
- The owner's passwords, API tokens and app secrets are never requested in chat. They are entered in the admin Settings.

## Phases

- Phase A — Security and reliability. DONE in v104 (offline tests). See STATUS.md.
- Phase B — Gap analysis against the master prompt. DONE: app/GAP_ANALYSIS.md.
- Phase C — Continue implementation of safe items. PARTIAL: approved medical source library and doctor
  approval gate are DONE. The rest is listed in GAP_ANALYSIS.md ("Remaining Phase C").
- Phase D — Live verification checklist for each integration. DONE: app/LIVE_CHECKLIST_BN.md.

## Gemini provider (earlier plan, still in force)

- Rate limits are per Google Cloud project, not per key. Keys of one project share one ledger.
- RPD resets at midnight Pacific time.
- Never move a job to another project to get around a quota. One active project at a time.
- Paid use is off by default. It needs gemini_paid_allowed = 1 AND a key marked "paid".
- Medical tasks never use lite/nano models and never bypass the privacy guard.
- Stored keys are encrypted (app/secrets.php). Only the plain key is held in memory during a call.

---

## Facebook Page automation (v124, first priority)

Priority order (agreed): Facebook Page automation first; Pixel/CAPI is separate and waits for the medical privacy review.

Status legend: DONE (offline) = built and covered by offline tests, not a production check. LIVE-PENDING = needs the owner's Meta app, token and a real test. BLOCKED = needs an owner decision or access.

| Item | Status | Note |
|---|---|---|
| Page connection (ID + token saved encrypted) | DONE (offline) / LIVE-PENDING | Token stored via secrets; never printed |
| OAuth and Page Access Token | LIVE-PENDING | Token is pasted in Settings for now; OAuth flow not built |
| Page publishing (queue, retry, dead-letter) | DONE (offline) | t10 suite; live post not tested |
| Comment monitoring (feed webhook) | LIVE-PENDING | fb-webhook.php exists; signature check and dedup to be verified live |
| Public comment reply | DONE (offline) / LIVE-PENDING | t13 suite; approval queue; live reply not tested |
| Feed webhooks: feed subscription + /subscribed_apps | LIVE-PENDING | Preflight shows NOT CONFIGURED until checked |
| Messenger | LIVE-PENDING | t17 suite offline; needs pages_messaging and MESSAGING task access |
| First-comment appointment link | DONE (offline) | t10 queue; live not tested |
| API permission validation | NOT STARTED | Needs a live call to /me/permissions |
| Automation logs | PARTIAL | Existing logs and queue status; no per-feature log page yet |

Permissions to verify live: pages_show_list, pages_read_engagement, pages_read_user_content, pages_manage_posts, pages_manage_engagement, pages_manage_metadata, pages_messaging. Task access: CREATE_CONTENT, MODERATE, MESSAGING. Source: Meta Pages API documentation (developers.facebook.com/documentation/pages-api).

Webhook verification still needed: comment event receipt, signature verification, duplicate-event prevention, queue, retry, and one real test comment through the full workflow. Subscription success alone is not enough.

Pixel and CAPI (medical privacy): OFF. Gate: site setting meta_pixel_privacy_cleared (default 0). While 0: no browser pixel, no server event, no Contact event. Never send patient name, phone, condition, treatment, report, appointment details or any personal health data. No condition-specific URL, event name, custom parameter or audience. Review against Meta Business Tools Terms and health data restrictions before the owner sets the gate to 1.

Preflight page: jebu-cp-3hr3f/fb-preflight.php (sidebar: Facebook যাচাই). Shows PASS / WARNING / BLOCKED / NOT CONFIGURED. Makes no Meta call, so nothing is shown as PASS.

Owner actions before live tests:
1. Meta Developer app: Development or Live status, and which permissions are approved.
2. Facebook Page ID and Page access token entered in Settings (never in chat).
3. Business Manager access and domain verification status.
4. A test comment on the Page for the webhook test.
5. Medical privacy review decision for Pixel/CAPI.

Everything marked LIVE-PENDING is not verified. No mock test, UI-only feature or stubbed API response is counted as production verification.

## Facebook configuration center (v131)
- Settings, validators, publishing, AI reply, setup guide pages added. Live Meta checks remain LIVE-PENDING until owner credentials are provided.
