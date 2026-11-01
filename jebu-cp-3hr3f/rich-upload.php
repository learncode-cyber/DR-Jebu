<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
$u = require_perm('content');

// JSON endpoint for the rich-text editor: one image per request, POST only, CSRF checked, rate limited per user.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function rich_upload_reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_post()) {
    rich_upload_reply(405, ['error' => 'শুধু POST অনুরোধ গ্রহণযোগ্য।']);
}
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
if ($token === '' || !hash_equals(csrf_token(), $token)) {
    rich_upload_reply(403, ['error' => 'নিরাপত্তা যাচাই ব্যর্থ। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।']);
}
$recent = (int) q_val("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'media.uploaded' AND created_at >= (NOW() - INTERVAL 1 MINUTE)", [(int) $u['id']]);
if ($recent >= 30) {
    rich_upload_reply(429, ['error' => 'এক মিনিটে অনেকগুলো ছবি এসেছে। একটু পরে আবার চেষ্টা করুন।']);
}
$f = $_FILES['file'] ?? null;
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    rich_upload_reply(422, ['error' => 'ছবিটি আপলোড হয়নি। আবার চেষ্টা করুন।']);
}
$err = '';
$img = rich_store_image((string) $f['tmp_name'], $err);
if ($img === null) {
    rich_upload_reply(422, ['error' => $err !== '' ? $err : 'ছবি প্রসেস করা যায়নি।']);
}
log_activity('media.uploaded', $img['url'], (int) $u['id']);
rich_upload_reply(200, $img);
