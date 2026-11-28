<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../admin/core.php';
[$directory] = openStore();

$lock = fopen($directory . '/state.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    throw new RuntimeException('Редактор занят.');
}
try {
    $data = json_decode(file_get_contents($directory . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['live'], $data['draft'], $data['history'])) {
        throw new RuntimeException('Состояние редактора не прошло проверку.');
    }
    $references = json_encode($data, JSON_THROW_ON_ERROR);
    $releases = glob($directory . '/release-*', GLOB_ONLYDIR);
    usort($releases, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $kept = array_slice($releases, 0, 2);
    $move = in_array('--quarantine', $argv, true);
    $files = array_merge($releases, glob($directory . '/media-*.webp'));
    foreach ($files as $file) {
        $name = basename($file);
        if (is_link($file) || !preg_match('/^(?:release-([a-f0-9]{32})|media-([a-f0-9]{64})[.]webp)$/D', $name, $parts)) {
            continue;
        }
        $id = ($parts[1] ?? '') ?: $parts[2];
        if (in_array($file, $kept, true) || str_contains($references, $id)
            || filemtime($file) > time() - 7 * 86400) {
            continue;
        }
        if ($move) {
            $quarantine = $directory . '/quarantine';
            if (!is_dir($quarantine) && !mkdir($quarantine, 0700)) {
                throw new RuntimeException('Не удалось создать карантин.');
            }
            $target = $quarantine . '/' . $name;
            if (file_exists($target) || !rename($file, $target)) {
                throw new RuntimeException('Не удалось перенести объект в карантин.');
            }
        }
        echo $move ? "QUARANTINED $name\n" : "CANDIDATE $name\n";
    }
} finally {
    fclose($lock);
}
