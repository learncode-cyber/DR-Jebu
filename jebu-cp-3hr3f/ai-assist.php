<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once APP_ROOT . '/ai_clinical.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ai_json(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$u = admin_user();
if (!$u || !can('leads.view')) {
    ai_json(403, ['ok' => false, 'error' => 'অনুমতি নেই বা সেশন শেষ হয়েছে। আবার লগইন করুন।']);
}
if (!is_post()) {
    ai_json(405, ['ok' => false, 'error' => 'POST অনুরোধ প্রয়োজন।']);
}
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || !hash_equals(csrf_token(), $token)) {
    ai_json(403, ['ok' => false, 'error' => 'অনুরোধটি যাচাই করা যায়নি। পেজ রিফ্রেশ করুন।']);
}
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    ai_json(400, ['ok' => false, 'error' => 'অবৈধ অনুরোধ।']);
}

$mode = (string) ($body['mode'] ?? '');
$result = ai_clinical_run($mode, $body);
// Audit only the action, never the clinical text.
log_activity('ai.clinical', $mode, (int) $u['id']);
ai_json(200, $result);
