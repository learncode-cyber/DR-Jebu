<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
// Served as /.well-known/security.txt (RFC 9116): how to report a security problem with this website.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo "Canonical: " . abs_url('/.well-known/security.txt') . "\n";
echo "Contact: " . abs_url('/contact') . "\n";
echo "Expires: 2027-12-31T00:00:00Z\n";
echo "Preferred-Languages: bn, en\n";
