<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

// Meta Messenger webhook. GET = subscription handshake, POST = signed events.
// Nothing here is shown to visitors; errors go to the admin alerts only.

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $challenge = fb_verify_handshake($_GET);
    if ($challenge === null) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 500000) {
    http_response_code(413);
    exit;
}
$sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if (!fb_signature_ok($raw, $sig)) {
    system_alert('warning', 'meta', 'Webhook signature mismatch (request refused)');
    http_response_code(403);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

$fresh = fb_store_inbound(fb_parse_messages($payload));
$freshComments = fb_store_comments(fb_parse_comments($payload));

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'EVENT_RECEIVED';

// Messages are always stored (the admin inbox shows them); the bot answers only when it is switched on.
if ($fresh || ($freshComments && fb_comments_enabled())) {
    fb_finish_response_then(static function () use ($fresh, $freshComments): void {
        foreach ($fresh as $m) {
            try {
                fb_handle_inbound($m);
            } catch (Throwable $e) {
                system_alert('error', 'meta', 'Reply failed: ' . $e->getMessage());
            }
        }
        foreach ($freshComments as $c) {
            try {
                fb_handle_comment($c); // only queues a draft; nothing is sent without owner approval
            } catch (Throwable $e) {
                system_alert('error', 'meta', 'Comment reply failed: ' . $e->getMessage());
            }
        }
    });
}
