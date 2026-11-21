# Facebook automation: gap analysis (v125)

Checked against the code in this version (not against a Meta live account).
Status words used: IMPLEMENTED (code exists), TESTED (an offline suite covers it), LIVE-VERIFIED (checked against a real Page; none yet), PARTIALLY IMPLEMENTED, BLOCKED (needs owner access or Meta approval), NOT IMPLEMENTED.
No item is LIVE-VERIFIED. TESTED means the offline test passes, not that Meta accepted it.

| # | Feature | Status | Evidence / gap |
|---|---|---|---|
| 1 | Facebook Page connection | PARTIALLY IMPLEMENTED | Page ID and token are saved in Settings. No OAuth flow. |
| 2 | Page access token management | TESTED | Encrypted secrets (t9). Token never printed. |
| 3 | Meta app configuration | NOT IMPLEMENTED | Preflight shows NOT CONFIGURED; no app status check. |
| 4 | Page publishing API | TESTED | fb_queue publish path with stubbed HTTP (t10). Not live. |
| 5 | Scheduled publishing | TESTED | scheduled_at; 90-day window enforced (t22). Facebook only. |
| 6 | Queue-based publishing | TESTED | fb_jobs with states queued, retry, dead, done (t10). |
| 7 | First comment automation | TESTED | Separate job; appointment link to /appointment (t10). Not live. |
| 8 | Comment webhook | TESTED | fb-webhook.php: handshake, parse, store (t13, t17). Not live. |
| 9 | Webhook signature verification | TESTED | fb_signature_ok: valid, tampered, wrong secret, missing prefix, empty header (t23). Not live. |
| 10 | All-post comment monitoring | NOT IMPLEMENTED | Only webhook events are read. No polling of all posts. |
| 11 | Automatic comment classification | TESTED | 10 owner categories, rule-based, emergency first (t23). Rules are keyword-based, so some comments will be "other". |
| 12 | Automatic comment reply | TESTED | Auto-send only for greeting and appreciation. Emergency, medical, pregnancy stop for review. Spam and negative go to owner review (t23). Not live.
| 13 | Reply approval workflow | TESTED | Admin approval page; nothing sent without approval (t13). |
| 14 | Messenger automation | PARTIALLY IMPLEMENTED | Inbox and handler (t17). Welcome message and policy window not confirmed. |
| 15 | Appointment CTA | TESTED | First comment and replies use the appointment URL. |
| 16 | Duplicate comment prevention | TESTED | UNIQUE comment_id (t13). |
| 17 | Duplicate post prevention | TESTED | UNIQUE job_key on fb_jobs (t10). |
| 18 | Retry mechanism | TESTED | attempts, max_attempts, retry state (t10). |
| 19 | Failed job management | TESTED | dead state, "publish again" re-queues (t10). Overview lists dead jobs (t22). |
| 20 | API permission validation | NOT IMPLEMENTED | Needs a live call to /me/permissions. Preflight shows NOT CONFIGURED. |
| 21 | Rate limit handling | PARTIALLY IMPLEMENTED | Own cap of 30 comment actions per hour. Meta error codes 4, 17, 613 handled. X-App-Usage header not read. |
| 22 | Automation logs | PARTIALLY IMPLEMENTED | last_error on jobs, system alerts. No per-feature log page. |
| 23 | Error notifications | IMPLEMENTED | system_alert plus optional Telegram (alerts.php). Not tested. |

## Added in v125
- Scheduling window: a post can be planned up to 90 days ahead; later times are refused (fbp_schedule_ok, t22).
- Facebook ওভারভিউ page (admin): counts from local data only: published, scheduled, failed, comments, conversations, dead jobs.

## Done in v127
- Signature test (t23), 10-category classifier, auto-send limited to greeting and appreciation.

## Next (offline-possible)
1. Rate-limit: parse X-App-Usage and back off above 80 percent (needs the live call path wired).
2. Per-feature automation log page.
3. Show the category on the comment approval page.

## Owner access needed (BLOCKED for live)
Meta app status and approved permissions, Page ID and token in Settings, Business Manager access, one real test comment.
