<?php
declare(strict_types=1);

$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8081';
$https = ($_SERVER['HTTPS'] ?? '') === 'on';
$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
$scheme = $forwardedProto ?: ($https ? 'https' : 'http');

header('Content-Type: text/plain; charset=UTF-8');
?>
User-agent: *
Allow: /

Sitemap: <?= $scheme ?>://<?= $host ?>/sitemap.xml
