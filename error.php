<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
// Apache sends visitors here for 400/403/404/405/500/503 (see .htaccess ErrorDocument lines)
$code = (int) ($_GET['code'] ?? 404);
if (!in_array($code, [400, 403, 404, 405, 429, 500, 503], true)) { $code = 404; }
render_error($code);
