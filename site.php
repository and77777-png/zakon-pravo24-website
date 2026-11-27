<?php
declare(strict_types=1);
require __DIR__ . '/admin/core.php';

header('X-Content-Type-Options: nosniff');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$page = trim((string) $path, '/');
if ($page === 'index.html') {
    $page = '';
} elseif (str_ends_with($page, '/index.html')) {
    $page = substr($page, 0, -11);
}
try {
    [$directory, $config] = openStore();
    $data = state($directory);
} catch (Throwable) {
    http_response_code(503);
    exit('Сайт временно недоступен.');
}
$production = ($config['mode'] ?? '') === 'production'
    && in_array($_SERVER['HTTP_HOST'] ?? '', ['zakon-pravo24.ru', 'www.zakon-pravo24.ru'], true);
if (!$production) {
    header('X-Robots-Tag: noindex, nofollow');
}
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo $production
        ? "User-agent: *\nDisallow: /admin/\nSitemap: " . ORIGIN . "/sitemap.xml\n"
        : "User-agent: *\nDisallow: /\n";
    exit;
}
$services = $data['live_services'] ?? [];
$pages = publicPages($services);
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (array_keys($pages) as $slug) {
        echo '<url><loc>', e(ORIGIN . '/' . ($slug === '' ? '' : $slug . '/')), '</loc></url>';
    }
    echo '</urlset>';
    exit;
}
$redirects = [
    '/stroitelnaya_ekspertiza' => '/stroitelnaya-ekspertiza/',
    '/sporyi_s_zastroyschikami_krasnoyarsk' => '/spory-s-zastroishchikami/',
    '/vozmeschenie_uscherba_strahovyie_krasnoyarsk' => '/vozmeshchenie-ushcherba/',
    '/bankrotstvo_fizicheskih_lits_krasnoyarsk' => '/bankrotstvo/',
    '/kontaktyi' => '/kontakty/',
    '/nezavisimaya_ekspertiza_krasnoyarsk' => '/ekspertiza/',
];
$target = $redirects[rtrim($path, '/')] ?? null;
if ($path === '/sitemap' || $path === '/sitemap/') {
    header('Location: /sitemap.xml', true, 301);
    exit;
}
if ($target && isset($pages[trim($target, '/')])) {
    header('Location: ' . $target, true, 301);
    exit;
}
if (!array_key_exists($page, $pages)) {
    http_response_code(404);
    exit('Страница не найдена.');
}
$canonicalPath = $page === '' ? '/' : '/' . $page . '/';
if ($path !== $canonicalPath || ($production && ($_SERVER['HTTP_HOST'] ?? '') === 'www.zakon-pravo24.ru')) {
    header('Location: ' . ($production ? ORIGIN : '') . $canonicalPath, true, 301);
    exit;
}
try {
    header('Content-Type: text/html; charset=UTF-8');
    $release = $data['release'] ?? null;
    if (is_string($release) && preg_match('/^[a-f0-9]{32}$/D', $release)) {
        $file = $directory . '/release-' . $release . '/' . ($page ?: 'home') . '.html';
        if (!is_readable($file)) {
            throw new RuntimeException('Файл публикации недоступен.');
        }
        $html = file_get_contents($file);
    } else {
        $html = renderPage($page, $data['live'][$page] ?? [], false, $data['live_contacts'] ?? [], $services);
    }
    if ($html === false) {
        throw new RuntimeException('Не удалось прочитать страницу.');
    }
    echo $production ? str_replace('<meta name="robots" content="noindex,nofollow">', '', $html) : $html;
} catch (Throwable) {
    http_response_code(503);
    exit('Сайт временно недоступен.');
}
