<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}

if ($path === '/robots.txt') {
    require __DIR__ . '/robots.php';
    return true;
}

if ($path === '/newsletter/subscribe') {
    require __DIR__ . '/subscribe.php';
    return true;
}

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
