<?php
declare(strict_types=1);
require __DIR__ . '/admin/core.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) {
    http_response_code(404);
    exit;
}
[$directory, $config] = openStore();
if (isset($_GET['preview']) || ($config['mode'] ?? '') !== 'production'
    || !in_array($_SERVER['HTTP_HOST'] ?? '', ['zakon-pravo24.ru', 'www.zakon-pravo24.ru'], true)) {
    header('X-Robots-Tag: noindex, nofollow');
}
$data = state($directory);
$url = '/media.php?id=' . $id;
$allowed = false;
if (isset($_GET['preview'])) {
    session_start(['use_strict_mode' => 1, 'read_and_close' => true]);
    $allowed = (int) ($_SESSION['until'] ?? 0) > time()
        && ($_SESSION['auth_epoch'] ?? 0) === ($data['auth_epoch'] ?? 0);
} else {
    foreach ($data['live'] as $content) {
        foreach ($content as $key => $value) {
            if (str_starts_with($key, 'src-') && $value === $url) {
                $allowed = true;
                break 2;
            }
        }
    }
}
if (!$allowed || !isset($data['media'][$id])) {
    http_response_code(404);
    exit;
}
$image = $data['media'][$id];
header('Content-Type: ' . $image['type']);
$file = mediaFile($directory, $id);
if (is_file($file)) {
    readfile($file);
} elseif (isset($image['bytes'])) {
    echo base64_decode($image['bytes'], true);
} else {
    http_response_code(404);
}
