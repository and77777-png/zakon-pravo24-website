<?php
declare(strict_types=1);
require __DIR__ . '/admin/core.php';

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) {
    http_response_code(404);
    exit;
}
[$directory] = openStore();
$data = state($directory);
$url = '/media.php?id=' . $id;
$allowed = false;
if (isset($_GET['preview'])) {
    session_start(['use_strict_mode' => 1, 'read_and_close' => true]);
    $allowed = (int) ($_SESSION['until'] ?? 0) > time();
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
echo base64_decode($image['bytes'], true);
