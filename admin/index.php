<?php
declare(strict_types=1);
require __DIR__ . '/core.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; img-src \'self\'; form-action \'self\'; frame-ancestors \'none\'; base-uri \'none\'');
header('X-Content-Type-Options: nosniff');

if ((empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Требуется HTTPS.');
}
try {
    [$directory, $config] = openStore();
} catch (Throwable) {
    http_response_code(503);
    exit('Хранилище редактора не настроено.');
}
session_start(['use_strict_mode' => 1, 'cookie_httponly' => 1, 'cookie_samesite' => 'Strict',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
if ((int) ($_SESSION['until'] ?? 0) <= time()) {
    unset($_SESSION['until']);
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$csrf = '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
$page = $_GET['page'] ?? '';
if (!is_string($page) || !array_key_exists($page, PAGES)) {
    http_response_code(404);
    exit('Страница не найдена.');
}
$authEpoch = state($directory)['auth_epoch'] ?? 0;
if (isset($_SESSION['until']) && ($_SESSION['auth_epoch'] ?? 0) !== $authEpoch) {
    unset($_SESSION['until']);
}
$action = $_POST['action'] ?? '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Обновите страницу перед отправкой формы.');
    }
    try {
        if ($action === 'login') {
            $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
            $password = $_POST['password'] ?? '';
            $verified = false;
            state($directory, function (array &$data) use ($ip, $password, $config, &$verified): void {
                $now = time();
                $attempts = array_filter($data['attempts'], static fn(array $attempt): bool => $attempt['until'] > $now);
                $attempt = $attempts[$ip] ?? ['count' => 0, 'until' => $now + 600];
                if ($attempt['count'] >= 8 || count($attempts) >= 1000 && !isset($attempts[$ip])) {
                    throw new InvalidArgumentException('Слишком много попыток. Попробуйте позже.');
                }
                $verified = is_string($password) && strlen($password) <= 1024 && password_verify($password, $data['password_hash'] ?? $config['password_hash']);
                if ($verified) {
                    unset($attempts[$ip]);
                } else {
                    $attempt['count']++;
                    $attempts[$ip] = $attempt;
                }
                $data['attempts'] = $attempts;
            });
            if (!$verified) {
                throw new InvalidArgumentException('Пароль не принят.');
            }
            session_regenerate_id(true);
            $_SESSION = ['until' => time() + 3600, 'csrf' => bin2hex(random_bytes(32)), 'auth_epoch' => state($directory)['auth_epoch'] ?? 0];
        } elseif (!isset($_SESSION['until'])) {
            http_response_code(403);
            exit('Войдите в редактор.');
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
        } else {
            $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT);
            state($directory, function (array &$data) use ($action, $page, $version, $directory, $config): void {
                if ($version !== $data['version']) {
                    throw new InvalidArgumentException('Данные изменились. Обновите страницу и повторите действие.');
                }
                if ($action === 'contacts') {
                    $contacts = [];
                    foreach (CONTACT_DEFAULTS as $key => $unused) {
                        $value = $_POST[$key] ?? '';
                        $pattern = match ($key) {
                            'telegram' => '~^https://t[.]me/[a-zA-Z0-9_]{5,32}$~D',
                            'max' => '~^https://max[.]ru/(?:u/)?[a-zA-Z0-9_-]{3,180}$~D',
                            default => '~^[+]7[0-9() -]{10,20}$~D',
                        };
                        if (!is_string($value) || !preg_match($pattern, trim($value))
                            || (in_array($key, ['mobile', 'landline'], true)
                                && strlen(phoneLink($value)) !== 16)) {
                            throw new InvalidArgumentException('Проверьте формат телефона или ссылки.');
                        }
                        $contacts[$key] = trim($value);
                    }
                    $data['draft_contacts'] = $contacts;
                } elseif ($action === 'service') {
                    $slug = $_POST['slug'] ?? '';
                    $title = $_POST['title'] ?? '';
                    $description = $_POST['description'] ?? '';
                    $order = filter_var($_POST['order'] ?? null, FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 0, 'max_range' => 999]]);
                    if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9-]{2,49}$/D', $slug)
                        || in_array($slug, ['admin', 'assets', 'ops', 'tests', 'uslugi', 'o-kompanii', 'kontakty', 'privacy', 'home'], true)
                        || !is_string($title) || trim($title) === '' || strlen($title) > 240 || !preg_match('//u', $title)
                        || !is_string($description) || strlen($description) > 3000 || !preg_match('//u', $description)
                        || (!isset(PAGES[$slug]) && trim($description) === '') || $order === false
                        || (!isset($data['draft_services'][$slug]) && count($data['draft_services'] ?? []) >= 30)) {
                        throw new InvalidArgumentException('Проверьте адрес, название, описание и порядок услуги.');
                    }
                    $data['draft_services'][$slug] = [
                        'title' => trim($title), 'description' => trim($description),
                        'order' => $order, 'hidden' => isset($_POST['hidden']),
                    ];
                } elseif ($action === 'password') {
                    $current = $_POST['current_password'] ?? '';
                    $new = $_POST['new_password'] ?? '';
                    if (!is_string($current) || strlen($current) > 1024
                        || !password_verify($current, $data['password_hash'] ?? $config['password_hash'])
                        || !is_string($new) || strlen($new) < 14 || strlen($new) > 72 || str_contains($new, chr(0))
                        || $new !== ($_POST['repeat_password'] ?? null)) {
                        throw new InvalidArgumentException('Проверьте текущий пароль и повтор нового пароля (14–72 байта).');
                    }
                    $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                    $data['auth_epoch'] = ($data['auth_epoch'] ?? 0) + 1;
                } elseif ($action === 'save') {
                    $submitted = $_POST['values'] ?? [];
                    if (!is_array($submitted)) {
                        throw new InvalidArgumentException('Некорректные поля.');
                    }
                    $values = [];
                    foreach (fields(document($page)) as $key => $node) {
                        if (!array_key_exists($key, $submitted)) {
                            continue;
                        }
                        $value = $submitted[$key];
                        if (!is_string($value) || preg_match('//u', $value) !== 1 || strlen($value) > 6000) {
                            throw new InvalidArgumentException('Проверьте формат и длину текста.');
                        }
                        if (str_starts_with($key, 'href-') && !preg_match('~^(tel:\+?[0-9() -]{7,24}|https://(?:t\.me|(?:www\.)?max\.ru)/[a-zA-Z0-9_./?&=%+-]+)$~D', $value)) {
                            throw new InvalidArgumentException('Разрешены ссылки на телефон, Telegram и MAX.');
                        }
                        if (str_starts_with($key, 'src-') && $value !== $node->nodeValue
                            && (!preg_match('~^/media\.php\?id=([a-f0-9]{64})$~D', $value, $match) || !isset($data['media'][$match[1]]))) {
                            throw new InvalidArgumentException('Выберите фотографию из медиатеки.');
                        }
                        if ($value !== $node->nodeValue) {
                            $values[$key] = $value;
                        }
                    }
                    $data['draft'][$page] = $values;
                } elseif ($action === 'publish' || $action === 'rollback') {
                    $previous = $data['live'];
                    $previousContacts = $data['live_contacts'] ?? [];
                    $previousServices = $data['live_services'] ?? [];
                    if ($action === 'publish') {
                        $data['live'] = $data['draft'];
                        $data['live_contacts'] = $data['draft_contacts'] ?? [];
                        $data['live_services'] = $data['draft_services'] ?? [];
                    } else {
                        $target = filter_var($_POST['target'] ?? null, FILTER_VALIDATE_INT);
                        if (!isset($data['history'][$target])) {
                            throw new InvalidArgumentException('Версия не найдена.');
                        }
                        $data['live'] = $data['history'][$target]['previous'];
                        $data['draft'] = $data['live'];
                        $data['draft_contacts'] = $data['live_contacts'] = $data['history'][$target]['contacts'] ?? [];
                        $data['draft_services'] = $data['live_services'] = $data['history'][$target]['services'] ?? [];
                    }
                    $data['history'][] = ['time' => gmdate('c'), 'action' => $action, 'previous' => $previous, 'contacts' => $previousContacts, 'services' => $previousServices];
                    $data['history'] = array_slice($data['history'], -30, null, true);
                } elseif ($action === 'upload') {
                    $file = $_FILES['photo'] ?? [];
                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] > 2_000_000
                        || !is_uploaded_file($file['tmp_name'])) {
                        throw new InvalidArgumentException('Выберите фотографию до 2 МБ.');
                    }
                    $image = @getimagesize($file['tmp_name']);
                    if (!$image || !in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                        || $image[0] * $image[1] > 4_000_000) {
                        throw new InvalidArgumentException('Допустимы JPEG, PNG и WebP до 4 мегапикселей.');
                    }
                    $original = @imagecreatefromstring(file_get_contents($file['tmp_name']));
                    if (!$original) {
                        throw new InvalidArgumentException('Фотография повреждена.');
                    }
                    ob_start();
                    $encoded = imagewebp($original, null, 85);
                    $bytes = ob_get_clean();
                    if (!$encoded || $bytes === '' || strlen($bytes) > 500_000) {
                        throw new InvalidArgumentException('Уменьшите размер фотографии.');
                    }
                    $id = hash('sha256', $bytes);
                    if (!isset($data['media'][$id]) && count($data['media'] ?? []) >= 40) {
                        throw new InvalidArgumentException('Достигнут лимит медиатеки.');
                    }
                    saveMedia($directory, $id, $bytes);
                    $data['media'][$id] = ['type' => 'image/webp'];
                } elseif ($action === 'delete_media') {
                    $id = $_POST['media_id'] ?? '';
                    if (!is_string($id) || !isset($data['media'][$id])) {
                        throw new InvalidArgumentException('Фотография не найдена.');
                    }
                    $references = $data;
                    unset($references['media']);
                    if (str_contains(json_encode($references, JSON_THROW_ON_ERROR), $id)) {
                        throw new InvalidArgumentException('Фотография используется на странице, в истории или резервной копии.');
                    }
                    // Remove from the library atomically. A maintenance pass may reclaim unreferenced blobs.
                    unset($data['media'][$id]);
                } elseif ($action === 'backup') {
                    $data['backup'] = array_intersect_key($data, array_flip(['draft', 'live', 'history', 'media', 'draft_contacts', 'live_contacts', 'draft_services', 'live_services']));
                } elseif ($action === 'restore' && isset($data['backup'])) {
                    $data = array_replace($data, ['media' => [], 'draft_contacts' => [], 'live_contacts' => [], 'draft_services' => [], 'live_services' => []], $data['backup']);
                } else {
                    throw new InvalidArgumentException('Действие недоступно.');
                }
                if (in_array($action, ['publish', 'rollback', 'restore'], true)) {
                    $data['release'] = releasePages($directory, $data['live'], $data['live_contacts'] ?? [], $data['live_services'] ?? []);
                }
                $data['version']++;
            });
        }
        header('Location: ?page=' . rawurlencode($page), true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (Throwable) {
        http_response_code(500);
        $error = 'Не удалось выполнить действие.';
    }
}

$data = state($directory);
if (isset($_SESSION['until']) && isset($_GET['preview'])) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'self'");
    echo str_replace('<head>', '<head><base href="/' . e($page === '' ? '' : $page . '/') . '">', renderPage($page, $data['draft'][$page] ?? [], true, $data['draft_contacts'] ?? [], $data['draft_services'] ?? []));
    exit;
}
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Редактор сайта</title><link rel="stylesheet" href="admin.css"></head>
<body><main><header><h1>Редактор сайта</h1><p>Закон и право</p></header>
<?php if ($error !== ''): ?><p role="alert" class="error"><?= e($error) ?></p><?php endif ?>
<?php if (!isset($_SESSION['until'])): ?>
<section><h2>Вход</h2><form method="post"><?= $csrf ?>
<label>Пароль<input type="password" name="password" autocomplete="current-password" required></label>
<button name="action" value="login">Войти</button></form></section>
<?php else: $versionField = '<input type="hidden" name="version" value="' . $data['version'] . '">'; ?>
<nav aria-label="Разделы редактора">
<a href="?">Главная</a><a href="#pages">Страницы</a><a href="#services">Юридические услуги</a><a href="?page=o-kompanii">О юристе</a>
<a href="#contacts">Контакты</a><a href="?page=privacy">Документы</a><a href="#editor">SEO</a>
<a href="#photos">Медиатека</a><a href="#history">История</a><a href="#backup">Резервирование</a><a href="#help">Безопасность и помощь</a>
</nav>
<section id="pages"><h2>Страницы сайта</h2><ul class="pages">
<?php foreach (PAGES as $slug => $caption): ?><li><a href="?page=<?= rawurlencode($slug) ?>#editor" <?= $slug === $page ? 'aria-current="page"' : '' ?>><?= e($caption) ?></a></li><?php endforeach ?>
</ul></section>
<section id="editor"><h2><?= e(PAGES[$page]) ?></h2>
<p><a href="?page=<?= rawurlencode($page) ?>&amp;preview=1">Открыть черновик</a></p>
<form method="post"><?= $csrf . $versionField ?>
<?php $document = document($page); foreach (fields($document) as $key => $node): $value = $data['draft'][$page][$key] ?? $node->nodeValue; ?>
<label><?= e(match (strtok($key, '-')) {
    'href' => 'Контактная ссылка', 'src' => 'Фотография', 'alt' => 'Описание фотографии',
    'content' => 'SEO-описание',
    default => match ($node->parentNode->nodeName) {
        'h1' => 'Главный заголовок', 'h2', 'h3' => 'Заголовок блока',
        'li' => 'Пункт списка', 'summary' => 'Название раскрывающегося блока',
        default => $node->nodeName === 'title' ? 'SEO-заголовок' : 'Абзац',
    },
}) ?>
<?php if (str_starts_with($key, 'src-')): ?>
<select name="values[<?= e($key) ?>]"><option value="<?= e($node->nodeValue) ?>">Исходное фото</option>
<?php foreach (array_keys($data['media'] ?? []) as $id): $url = '/media.php?id=' . $id; ?>
<option value="<?= e($url) ?>" <?= $url === $value ? 'selected' : '' ?>>Фото <?= e(substr($id, 0, 10)) ?></option>
<?php endforeach ?></select>
<?php else: ?><textarea name="values[<?= e($key) ?>]" rows="2" maxlength="2000"><?= e($value) ?></textarea><?php endif ?>
</label>
<?php endforeach ?>
<button name="action" value="save">Сохранить черновик</button></form>
<form method="post"><?= $csrf . $versionField ?><button name="action" value="publish">Опубликовать все черновики</button></form></section>
<section id="services"><h2>Юридические услуги</h2>
<p>Укажите порядок карточки в каталоге. Скрытая услуга исчезнет из каталога и карты сайта после публикации.</p>
<?php $serviceDefaults = array_diff_key(PAGES, array_flip(['', 'uslugi', 'o-kompanii', 'kontakty', 'privacy']));
$editableServices = ($data['draft_services'] ?? []) + array_map(
    static fn(string $title): array => ['title' => $title, 'description' => '', 'order' => 0, 'hidden' => false],
    $serviceDefaults
);
$editableServices[''] = ['title' => '', 'description' => '', 'order' => 100, 'hidden' => false];
foreach ($editableServices as $slug => $service): ?>
<details><summary><?= e($service['title'] ?: 'Добавить услугу') ?></summary>
<form method="post"><?= $csrf . $versionField ?>
<label>Адрес латиницей<input name="slug" value="<?= e($slug) ?>" <?= $slug !== '' ? 'readonly' : '' ?> required></label>
<label>Название<input name="title" value="<?= e($service['title']) ?>" required></label>
<label>Описание новой услуги<textarea name="description"><?= e($service['description']) ?></textarea></label>
<label>Порядок<input name="order" type="number" min="0" max="999" value="<?= $service['order'] ?>" required></label>
<label><input name="hidden" type="checkbox" <?= $service['hidden'] ? 'checked' : '' ?>> Скрыть</label>
<button name="action" value="service">Сохранить черновик услуги</button></form></details>
<?php endforeach ?></section>
<section id="contacts"><h2>Общие контакты</h2>
<p>После публикации контакты обновятся на всех страницах. Мобильный телефон остаётся основным.</p>
<form method="post"><?= $csrf . $versionField ?>
<?php foreach (['mobile' => 'Мобильный телефон', 'landline' => 'Городской телефон', 'telegram' => 'Ссылка Telegram', 'max' => 'Ссылка MAX'] as $key => $label): ?>
<label><?= e($label) ?><input required name="<?= e($key) ?>" value="<?= e($data['draft_contacts'][$key] ?? CONTACT_DEFAULTS[$key]) ?>"></label>
<?php endforeach ?>
<button name="action" value="contacts">Сохранить черновик контактов</button></form></section>
<section id="photos"><h2>Медиатека</h2>
<ul><?php foreach (array_keys($data['media'] ?? []) as $id): ?>
<li><img width="100" loading="lazy" src="/media.php?id=<?= e($id) ?>&amp;preview=1" alt=""> Фото <?= e(substr($id, 0, 10)) ?>
<form method="post"><?= $csrf . $versionField ?><input type="hidden" name="media_id" value="<?= e($id) ?>">
<button name="action" value="delete_media">Убрать из медиатеки</button></form></li>
<?php endforeach ?></ul>
<form method="post" enctype="multipart/form-data"><?= $csrf . $versionField ?>
<label>Фото до 2 МБ<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
<button name="action" value="upload">Загрузить фотографию</button></form></section>
<section id="history"><h2>История публикаций</h2>
<?php foreach (array_reverse($data['history'], true) as $index => $entry): ?>
<form method="post"><?= $csrf . $versionField ?><input type="hidden" name="target" value="<?= $index ?>">
<p><?= e($entry['time']) ?> · <?= $entry['action'] === 'publish' ? 'Публикация' : 'Откат' ?></p>
<button name="action" value="rollback">Восстановить версию до этого действия</button></form>
<?php endforeach ?></section>
<section id="backup"><h2>Резервное копирование</h2>
<p>Контрольная копия редактора хранится в закрытом хранилище. Для защиты от потери хостинга нужна отдельная копия каталога.</p>
<form method="post"><?= $csrf . $versionField ?><button name="action" value="backup">Сохранить контрольную копию</button>
<?php if (isset($data['backup'])): ?><button name="action" value="restore">Восстановить контрольную копию</button><?php endif ?></form></section>
<section id="help"><h2>Безопасность и помощь</h2>
<p>Сохраните черновик, проверьте его в браузере и опубликуйте. Редактор завершает авторизацию через час.</p>
<form method="post"><?= $csrf . $versionField ?>
<label>Текущий пароль<input name="current_password" type="password" autocomplete="current-password" required></label>
<label>Новый пароль<input name="new_password" type="password" autocomplete="new-password" required></label>
<label>Повтор нового пароля<input name="repeat_password" type="password" autocomplete="new-password" required></label>
<button name="action" value="password">Сменить пароль и завершить все сеансы</button></form>
<form method="post"><?= $csrf ?><button name="action" value="logout">Выйти</button></form></section>
<?php endif ?></main></body></html>
