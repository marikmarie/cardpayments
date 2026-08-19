<?php
declare(strict_types=1);

$settings = is_file(__DIR__ . '/.env') ? parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW) ?: [] : [];
$appUrl = getenv('APP_URL') ?: (string) ($settings['APP_URL'] ?? '');
$basePath = parse_url($appUrl, PHP_URL_PATH);
$basePath = is_string($basePath) && $basePath !== '/' ? '/' . trim($basePath, '/') : '';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, strlen($basePath)) ?: '/';
}
$public = realpath(__DIR__ . '/public');
$asset = realpath($public . $path);

if ($path !== '/' && $asset && str_starts_with($asset, $public . DIRECTORY_SEPARATOR) && is_file($asset)) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
    header('Content-Type: ' . ($types[pathinfo($asset, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($asset);
    exit;
}

require __DIR__ . '/public/index.php';
