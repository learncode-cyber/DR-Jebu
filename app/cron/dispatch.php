<?php
declare(strict_types=1);
// Retry queued/failed lead notifications and send Telegram/email messages.
// cPanel > Cron Jobs, every 5 minutes. Use the full path of this file as shown in File Manager, for example:
//   */5 * * * *  /usr/local/bin/php /home/CPUSER/public_html/app/cron/dispatch.php >/dev/null 2>&1
// The run time is recorded (cron heartbeat) so the admin can warn when the cron stops.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';
$n = notifications_dispatch(50);
[$remQueued, $remTelegram] = appt_reminders_run();
li_expiry_check();
tt_expiry_check();
try { fbp_cron(); } catch (Throwable $e) { error_log('[jebu] fbp_cron: ' . $e->getMessage()); }
try { fb_comment_autosend_run(); } catch (Throwable $e) { error_log('[jebu] fb_comment_autosend: ' . $e->getMessage()); }
try { fb_comment_sync_drafts(); } catch (Throwable $e) { error_log('[jebu] fb_comment_sync: ' . $e->getMessage()); }
try { fbs_sync(); } catch (Throwable $e) { error_log('[jebu] fbs_sync: ' . $e->getMessage()); }
try { blog_migrate_v109(); blog_collect_drafts(); } catch (Throwable $e) { error_log('[jebu] blog_collect_drafts: ' . $e->getMessage()); }
try { seo_migrate_v107(); } catch (Throwable $e) { error_log('[jebu] seo_migrate_v107: ' . $e->getMessage()); }
try { seo_migrate_v121(); } catch (Throwable $e) { error_log('[jebu] seo_migrate_v121: ' . $e->getMessage()); }
try { fbq_worker_tick(10); } catch (Throwable $e) { error_log('[jebu] fbq_worker_tick: ' . $e->getMessage()); }
try { gem_worker_tick(5); } catch (Throwable $e) { error_log('[jebu] gem_worker_tick: ' . $e->getMessage()); }
cron_heartbeat();
echo date('c') . " dispatched: $n, reminders queued: $remQueued, telegram digest: $remTelegram\n";
