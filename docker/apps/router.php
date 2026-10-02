<?php

/*
 * Mzian.net — "apps" server: serves the customer applications deployed by
 * App\Deployment\Provider\LocalDeploymentProvider (development / demo hosting).
 *
 *   php -S 0.0.0.0:8081 docker/apps/router.php   (MZIAN_DEPLOYMENTS_DIR=var/deployments)
 *
 *   /<slug>/...        static website of the current release (<release>/public)
 *   /<slug>/admin/...  management application (<release>/public/admin/index.php)
 *
 * It runs in its own container without the platform's secrets or database.
 */

declare(strict_types=1);

$root = rtrim(getenv('MZIAN_DEPLOYMENTS_DIR') ?: __DIR__.'/../../var/deployments', '/');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH));

$notFound = static function (string $message = 'Not found'): bool {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>404</title><p style="font-family:sans-serif;padding:2rem">'.htmlspecialchars($message).'</p>';

    return true;
};

if ('/' === $path || '/healthz' === $path) {
    header('Content-Type: text/plain');
    echo "mzian apps server\n";

    return true;
}
if (1 !== preg_match('#^/([a-z0-9][a-z0-9-]{2,79})(/.*)?$#', $path, $match)) {
    return $notFound();
}
$slug = $match[1];
$rest = $match[2] ?? '';
if ('' === $rest) {
    header('Location: /'.$slug.'/', true, 301);

    return true;
}
$version = trim((string) @file_get_contents($root.'/'.$slug.'/current'));
$release = '' !== $version ? realpath($root.'/'.$slug.'/releases/'.basename($version)) : false;
if (false === $release || !is_dir($release.'/public')) {
    return $notFound('Application not deployed.');
}
$public = $release.'/public';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

// Management application (front controller).
if ('/admin' === $rest || str_starts_with($rest, '/admin/')) {
    $_SERVER['SCRIPT_NAME'] = '/'.$slug.'/admin/index.php';
    chdir($public.'/admin');
    require $public.'/admin/index.php';

    return true;
}

$file = $public.$rest;
if (str_ends_with($rest, '/')) {
    $file .= 'index.html';
}
$real = realpath($file);
if (false === $real || !str_starts_with($real, $public.'/') || !is_file($real) || str_contains($rest, '/.')) {
    return $notFound();
}
$types = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
    'ico' => 'image/x-icon', 'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
    'json' => 'application/json', 'webmanifest' => 'application/manifest+json', 'woff2' => 'font/woff2',
];
$extension = strtolower(pathinfo($real, \PATHINFO_EXTENSION));
if (!isset($types[$extension])) {
    return $notFound(); // never serve PHP sources or unknown files
}
header('Content-Type: '.$types[$extension]);
header('Cache-Control: '.('html' === $extension ? 'no-cache' : 'public, max-age=3600'));
readfile($real);

return true;
