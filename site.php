<?php
declare(strict_types=1);
require __DIR__ . '/admin/core.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$page = trim((string) $path, '/');
if ($page === 'index.html') {
    $page = '';
} elseif (str_ends_with($page, '/index.html')) {
    $page = substr($page, 0, -11);
}
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (array_keys(PAGES) as $slug) {
        echo '<url><loc>', e(ORIGIN . '/' . ($slug === '' ? '' : $slug . '/')), '</loc></url>';
    }
    echo '</urlset>';
    exit;
}
if (!array_key_exists($page, PAGES)) {
    http_response_code(404);
    exit('Страница не найдена.');
}
try {
    [$directory] = openStore();
    $data = state($directory);
    header('Content-Type: text/html; charset=UTF-8');
    echo renderPage($page, $data['live'][$page] ?? []);
} catch (Throwable) {
    http_response_code(503);
    exit('Сайт временно недоступен.');
}
