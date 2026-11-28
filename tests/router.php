<?php
// Use only with PHP's local development server bound to 127.0.0.1.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/(?:assets/[^/]+|admin/(?:index\.php|admin\.css)|media\.php|robots\.txt)$~D', $path)) {
    return false;
}
if ($path === '/admin/') {
    require __DIR__ . '/../admin/index.php';
} else {
    require __DIR__ . '/../site.php';
}
