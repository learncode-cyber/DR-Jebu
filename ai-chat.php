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
// Flood control: each visitor can send at most 30 chat requests per 10 minutes (protects the Gemini quota)
$ip = client_ip();
if ($ip !== '') {
    $since = date('Y-m-d H:i:s', time() - 600);
    if ((int) q_val('SELECT COUNT(*) FROM ai_requests WHERE ip = ? AND created_at >= ?', [$ip, $since]) >= 30) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => tr('অনেক বার প্রশ্ন পাঠানো হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'Too many questions were sent. Please try again in a few minutes.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    q_exec('INSERT INTO ai_requests (ip) VALUES (?)', [$ip]);
    if (random_int(1, 100) === 1) {
        q_exec('DELETE FROM ai_requests WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
}
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 60000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => tr('বার্তাটি অনেক বড়।', 'The message is too long.')], JSON_UNESCAPED_UNICODE);
    exit;
}
$data = json_decode($raw, true);
$messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
echo json_encode(ai_chat_turn($messages), JSON_UNESCAPED_UNICODE);
