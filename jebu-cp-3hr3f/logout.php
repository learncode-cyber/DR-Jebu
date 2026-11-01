<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
if (is_post() && admin_user()) {
    csrf_require();
    log_activity('logout', '', (int) admin_user()['id']);
}
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
redirect(a_url('login'));
