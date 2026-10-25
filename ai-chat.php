<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 60000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'বার্তাটি অনেক বড়।'], JSON_UNESCAPED_UNICODE);
    exit;
}
$data = json_decode($raw, true);
$messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
echo json_encode(ai_chat_turn($messages), JSON_UNESCAPED_UNICODE);
