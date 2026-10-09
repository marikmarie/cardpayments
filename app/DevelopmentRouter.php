<?php
declare(strict_types=1);

namespace App;

/**
 * PHP built-in-server router.
 *
 * Production web servers point directly at public/. This class only resolves
 * local static assets before handing all other requests to Application.
 */
final class DevelopmentRouter
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /** Serve a public file when it exists, otherwise run the web application. */
    public function run(): never
    {
        $path = $this->requestPath();
        $public = realpath($this->projectRoot . '/public');
        $asset = $public === false ? false : realpath($public . $path);

        if ($path !== '/' && $public !== false && $asset !== false
            && str_starts_with($asset, $public . DIRECTORY_SEPARATOR) && is_file($asset)) {
            header('Content-Type: ' . $this->contentType($asset));
            readfile($asset);
            exit;
        }

        require $this->projectRoot . '/public/index.php';
        exit;
    }

    /** Remove the configured base path so local development behaves like production. */
    private function requestPath(): string
    {
        $appUrl = (string) Config::get('APP_URL', '');
        $basePath = parse_url($appUrl, PHP_URL_PATH);
        $basePath = is_string($basePath) && $basePath !== '/' ? '/' . trim($basePath, '/') : '';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            return substr($path, strlen($basePath)) ?: '/';
        }
        return $path;
    }

    /** Return the small set of content types used by the local public directory. */
    private function contentType(string $asset): string
    {
        return match (strtolower(pathinfo($asset, PATHINFO_EXTENSION))) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
