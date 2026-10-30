<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_perm('content');

// JSON endpoint used by the rich-text editor: one image per request, POST only
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (!is_post()) {
    http_response_code(405);
    echo json_encode(['error' => 'শুধু POST অনুরোধ গ্রহণযোগ্য।'], JSON_UNESCAPED_UNICODE);
    exit;
}
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
if ($token === '' || !hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'নিরাপত্তা যাচাই ব্যর্থ। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।'], JSON_UNESCAPED_UNICODE);
    exit;
}
$err = '';
$url = save_upload('file', $err);
if ($url === null) {
    http_response_code(422);
    echo json_encode(['error' => $err !== '' ? $err : 'কোনো ছবি পাওয়া যায়নি।'], JSON_UNESCAPED_UNICODE);
    exit;
}
log_activity('media.uploaded', $url, (int) (admin_user()['id'] ?? 0));
echo json_encode(['url' => $url], JSON_UNESCAPED_UNICODE);
