<?php
declare(strict_types=1);

/*
 * Facebook automation overview. Counts come only from the local database.
 * Nothing here calls Meta, so webhook health and Meta API status stay NOT CONFIGURED until a live check exists.
 */

/** Returns the counts shown on the overview page. Missing tables count as zero. */
function fbo_counts(int $now): array {
    $out = ['posts' => [], 'comments' => [], 'jobs' => [], 'conversations' => 0, 'failed_jobs' => [], 'scheduled' => 0];
    try {
        foreach (q_all('SELECT status, COUNT(*) n FROM fb_posts GROUP BY status') as $r) {
            $out['posts'][(string) $r['status']] = (int) $r['n'];
        }
        $out['scheduled'] = (int) q_val("SELECT COUNT(*) FROM fb_posts WHERE status = 'queued' AND scheduled_at > ?", [date('Y-m-d H:i:s', $now)]);
        foreach (q_all('SELECT public_status s, COUNT(*) n FROM fb_comments GROUP BY public_status') as $r) {
            $out['comments'][(string) $r['s']] = (int) $r['n'];
        }
        foreach (q_all('SELECT state, COUNT(*) n FROM fb_jobs GROUP BY state') as $r) {
            $out['jobs'][(string) $r['state']] = (int) $r['n'];
        }
        $out['conversations'] = (int) q_val('SELECT COUNT(*) FROM fb_conversations');
        $out['failed_jobs'] = q_all("SELECT id, kind, ref_id, attempts, max_attempts, last_error, updated_at FROM fb_jobs WHERE state = 'dead' ORDER BY updated_at DESC LIMIT 10");
    } catch (Throwable $e) {
        error_log('[jebu] fbo_counts: ' . $e->getMessage());
    }
    return $out;
}
