<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_fb_soon.php';
require_once __DIR__ . '/../app/meta.php';
require_perm('settings');
// Facts only: whether each value is saved. Nothing here is sent to Meta.
$callback = abs_url('/fb-webhook.php');
$hasVerify = trim(setting('meta_verify_token')) !== '';
$hasSecret = trim(setting('meta_app_secret')) !== '';
fb_soon_render('Facebook Webhooks', 'PARTIALLY IMPLEMENTED', [
    'Callback URL: ' . $callback . ' (receives events; GET handshake and signed POST are in code)',
    'Verify token saved: ' . ($hasVerify ? 'yes' : 'no (not set)'),
    'App secret saved for signature check: ' . ($hasSecret ? 'yes' : 'no (not set)'),
    'Not built yet: Webhook Settings page with Verify token generator, Subscribe/Check subscription buttons, last received event time',
    'Live status (Meta has called the endpoint, subscriptions are active) is NOT CONFIGURED until checked against Meta',
]);
