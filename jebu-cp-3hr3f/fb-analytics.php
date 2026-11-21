<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_fb_soon.php';
require_perm('settings');
fb_soon_render('Facebook Analytics', 'NOT IMPLEMENTED', [
    'Facebook Insights sync (Meta read_insights permission, Page token, App Review)',
    'Post performance tracking and engagement history table',
    'Daily and weekly report generation (cron)',
    'Live check before any figure is shown',
]);
