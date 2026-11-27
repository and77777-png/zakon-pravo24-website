<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const ORIGIN = 'https://zakon-pravo24.ru';
const PAGES = [
    '' => 'Главная', 'uslugi' => 'Юридические услуги', 'o-kompanii' => 'О юристе',
    'kontakty' => 'Контакты и мессенджеры', 'privacy' => 'Юридические документы',
    'avtoekspertiza' => 'Автотехническая экспертиза', 'bankrotstvo' => 'Банкротство',
    'ekonomicheskaya-ekspertiza' => 'Экономическая экспертиза', 'ekspertiza' => 'Независимая экспертиза',
    'ocenka-imushchestva' => 'Оценка имущества', 'pocherkovedcheskaya-ekspertiza' => 'Почерковедческая экспертиза',
    'recenzii' => 'Рецензии', 'semeynye-spory' => 'Семейные споры',
    'spory-s-zastroishchikami' => 'Споры с застройщиками', 'stroitelnaya-ekspertiza' => 'Строительная экспертиза',
    'vozmeshchenie-ushcherba' => 'Возмещение ущерба', 'zemleustroitelnaya-ekspertiza' => 'Землеустроительная экспертиза',
];

const CONTACT_DEFAULTS = [
    'mobile' => '+7 (933) 338-55-67',
    'landline' => '+7 (391) 214-49-00',
    'telegram' => 'https://t.me/Tati_Urist',
    'max' => 'https://max.ru/u/f9LHodD0cOLjT1F30CZmTXZ4HBb4FJZ4rc6DBHTtp88WCqEJI8GdYw2AX44',
];

function phoneLink(string $value): string {
    return 'tel:+' . preg_replace('/[^0-9]/', '', $value);
}

function contactReplacement(string $html, array $contacts): string {
    $contacts += CONTACT_DEFAULTS;
    $pairs = [];
    foreach (['mobile', 'landline'] as $key) {
        $pairs[phoneLink(CONTACT_DEFAULTS[$key])] = phoneLink($contacts[$key]);
        $pairs[CONTACT_DEFAULTS[$key]] = e($contacts[$key]);
    }
    foreach (['telegram', 'max'] as $key) {
        $pairs[CONTACT_DEFAULTS[$key]] = e($contacts[$key]);
    }
    return strtr($html, $pairs);
}

function mediaFile(string $directory, string $id): string {
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
        throw new InvalidArgumentException('Фотография не найдена.');
    }
    return $directory . '/media-' . $id . '.webp';
}

function saveMedia(string $directory, string $id, string $bytes): void {
    if (hash('sha256', $bytes) !== $id) {
        throw new RuntimeException('Повреждён файл фотографии.');
    }
    $file = mediaFile($directory, $id);
    if (is_file($file) && hash_file('sha256', $file) === $id) {
        return;
    }
    $temporary = tempnam($directory, '.media-');
    if ($temporary === false) {
        throw new RuntimeException('Не удалось создать файл фотографии.');
    }
    try {
        if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $file)) {
            throw new RuntimeException('Не удалось сохранить фотографию.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function migrateMedia(string $directory, array &$data): void {
    foreach (['media', 'backup'] as $section) {
        if (!isset($data[$section])) {
            continue;
        }
        $entries = &$data[$section];
        if ($section === 'backup') {
            if (!isset($entries['media'])) {
                unset($entries);
                continue;
            }
            $entries = &$entries['media'];
        }
        foreach ($entries as $id => &$image) {
            if (isset($image['bytes'])) {
                $bytes = base64_decode($image['bytes'], true);
                if ($bytes === false) {
                    throw new RuntimeException('Повреждена резервная копия фотографии.');
                }
                saveMedia($directory, $id, $bytes);
                unset($image['bytes']);
            }
        }
        unset($image, $entries);
    }
}

function openStore(): array {
    $directory = realpath(getenv('ZAKON_DATA') ?: dirname(realpath(ROOT)) . '/zakon-private');
    $webRoot = realpath(ROOT);
    if (!$directory || !is_dir($directory) || !is_writable($directory)
        || str_starts_with(strtolower($directory . DIRECTORY_SEPARATOR), strtolower($webRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Закрытое хранилище не настроено.');
    }
    $config = require $directory . '/config.php';
    if (!is_array($config) || empty($config['password_hash'])) {
        throw new RuntimeException('Авторизация не настроена.');
    }
    return [$directory, $config];
}

function state(string $directory, ?callable $update = null): array {
    $lock = fopen($directory . '/state.lock', 'c');
    if (!$lock || !flock($lock, $update ? LOCK_EX : LOCK_SH)) {
        throw new RuntimeException('Хранилище занято.');
    }
    try {
        $file = $directory . '/state.json';
        $state = is_file($file)
            ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
            : ['version' => 0, 'draft' => [], 'live' => [], 'history' => [], 'attempts' => []];
        if ($update) {
            migrateMedia($directory, $state);
            $update($state);
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $temporary = tempnam($directory, '.state-');
            if ($temporary === false) {
                throw new RuntimeException('Не удалось создать временный файл.');
            }
            try {
                if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
                    throw new RuntimeException('Не удалось сохранить данные.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        return $state;
    } finally {
        fclose($lock);
    }
}

function publicPages(array $services): array {
    $pages = PAGES;
    foreach ($services as $slug => $service) {
        if ($service['hidden']) {
            unset($pages[$slug]);
        } else {
            $pages[$slug] = $service['title'];
        }
    }
    return $pages;
}

function applyServices(DOMDocument $doc, string $page, array $services): void {
    if (!$services) {
        return;
    }
    $xpath = new DOMXPath($doc);
    $links = iterator_to_array($xpath->query('//a[@href]'));
    foreach ($links as $link) {
        $slug = trim(str_replace('../', '', parse_url($link->getAttribute('href'), PHP_URL_PATH) ?? ''), '/.');
        if (($services[$slug]['hidden'] ?? false) && $slug !== '') {
            $link->parentNode->removeChild($link);
        }
    }
    if (isset($services[$page]) && $services[$page]['title'] !== '') {
        $heading = $xpath->query('//main//h1')->item(0);
        if ($heading) {
            while ($heading->firstChild !== null) {
                $heading->removeChild($heading->firstChild);
            }
            $heading->appendChild($doc->createTextNode($services[$page]['title']));
        }
    }
    if ($page === 'uslugi') {
        $directory = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," directory-item ")]')->item(0)?->parentNode;
        if (!$directory) {
            return;
        }
        $configured = $services;
        $cards = [];
        foreach ($xpath->query('./a[@href]', $directory) as $card) {
            $cards[trim(str_replace('../', '', $card->getAttribute('href')), '/')] = $card;
        }
        foreach (array_keys($cards) as $index => $slug) {
            if (!isset($configured[$slug])) {
                $configured[$slug] = ['title' => PAGES[$slug] ?? '', 'order' => 100 + $index, 'hidden' => false];
            }
        }
        uasort($configured, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        foreach ($configured as $slug => $service) {
            if ($service['hidden']) {
                continue;
            }
            $card = $cards[$slug] ?? $doc->createElement('a');
            if (!isset($cards[$slug])) {
                $card->setAttribute('class', 'directory-item');
                $card->setAttribute('href', '/' . $slug . '/');
                $card->appendChild($doc->createTextNode($service['title']));
            }
            if (isset($services[$slug]) && isset($cards[$slug])) {
                $heading = $xpath->query('.//*[self::h2 or self::h3 or self::strong]', $card)->item(0);
                if ($heading) {
                    while ($heading->firstChild !== null) {
                        $heading->removeChild($heading->firstChild);
                    }
                    $heading->appendChild($doc->createTextNode($service['title']));
                }
            }
            $directory->appendChild($card);
        }
    }
}

function document(string $page): DOMDocument {
    if (!array_key_exists($page, PAGES)) {
        throw new InvalidArgumentException('Страница не найдена.');
    }
    $document = new DOMDocument();
    $document->loadHTMLFile(ROOT . '/' . ($page === '' ? '' : $page . '/') . 'index.html',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return $document;
}

function fields(DOMDocument $document): array {
    $xpath = new DOMXPath($document);
    $fields = [];
    foreach ($xpath->query('//*[@data-editor-fields]') as $element) {
        $targets = json_decode($element->getAttribute('data-editor-fields'), true, 512, JSON_THROW_ON_ERROR);
        $text = [];
        foreach ($element->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) !== '') {
                $text[] = $child;
            }
        }
        foreach ($targets as $target => $key) {
            $node = match (true) {
                $target === 'element' => $element,
                str_starts_with($target, '@') => $element->getAttributeNode(substr($target, 1)),
                str_starts_with($target, 'text-') => $text[(int) substr($target, 5)] ?? null,
                default => null,
            };
            if (!$node || isset($fields[$key])) {
                throw new RuntimeException('У поля шаблона отсутствует уникальный идентификатор.');
            }
            $fields[$key] = $node;
        }
    }
    return $fields;
}

function renderPage(string $page, array $content, bool $preview = false, array $contacts = [], array $services = []): string {
    $custom = !isset(PAGES[$page]);
    $document = document($custom ? 'semeynye-spory' : $page);
    if ($custom) {
        $service = $services[$page];
        $main = $document->getElementsByTagName('main')->item(0);
        while ($main->firstChild !== null) {
            $main->removeChild($main->firstChild);
        }
        $section = $document->createElement('section');
        $section->setAttribute('class', 'section container');
        foreach (['h1' => $service['title'], 'p' => $service['description']] as $tag => $value) {
            $element = $document->createElement($tag);
            $element->appendChild($document->createTextNode($value));
            $section->appendChild($element);
        }
        $main->appendChild($section);
        $title = $document->getElementsByTagName('title')->item(0);
        while ($title->firstChild !== null) {
            $title->removeChild($title->firstChild);
        }
        $title->appendChild($document->createTextNode($service['title']));
        $description = (new DOMXPath($document))->query('//meta[@name="description"]')->item(0);
        $description?->setAttribute('content', $service['description']);
    }
    foreach (fields($document) as $key => $node) {
        if (!array_key_exists($key, $content)) {
            continue;
        }
        $value = $content[$key];
        if ($node instanceof DOMAttr) {
            if ($preview && str_starts_with($value, '/media.php?id=')) {
                $value .= '&preview=1';
            }
            $node->ownerElement->setAttribute($node->name, $value);
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            while ($node->firstChild !== null) {
                $node->removeChild($node->firstChild);
            }
            $node->appendChild($document->createTextNode($value));
        } else {
            $node->nodeValue = $value;
        }
    }
    applyServices($document, $page, $services);
    $head = $document->getElementsByTagName('head')->item(0);
    $canonical = $document->createElement('link');
    $canonical->setAttribute('rel', 'canonical');
    $canonical->setAttribute('href', ORIGIN . '/' . ($page === '' ? '' : $page . '/'));
    $head->appendChild($canonical);
    $xpath = new DOMXPath($document);
    $description = $xpath->query('//meta[@name="description"]')->item(0)?->getAttribute('content') ?? '';
    foreach ([
        'og:type' => 'website', 'og:locale' => 'ru_RU', 'og:url' => $canonical->getAttribute('href'),
        'og:title' => $document->getElementsByTagName('title')->item(0)->textContent,
        'og:description' => $description,
    ] as $name => $value) {
        $property = $document->createElement('meta');
        $property->setAttribute('property', $name);
        $property->setAttribute('content', $value);
        $head->appendChild($property);
    }
    $metadata = $document->createElement('script');
    $metadata->setAttribute('type', 'application/ld+json');
    $metadata->appendChild($document->createTextNode(json_encode([
        '@context' => 'https://schema.org', '@type' => $page === '' ? 'WebSite' : 'WebPage',
        'name' => $document->getElementsByTagName('title')->item(0)->textContent,
        'url' => $canonical->getAttribute('href'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)));
    $head->appendChild($metadata);
    return contactReplacement($document->saveHTML(), $contacts);
}

function releasePages(string $directory, array $content, array $contacts, array $services, int $minimumFreeBytes = 33554432): string {
    $free = disk_free_space($directory);
    if ($free === false || $free < $minimumFreeBytes) {
        throw new RuntimeException('Недостаточно свободного места для безопасной публикации.');
    }
    $id = bin2hex(random_bytes(16));
    $release = $directory . '/release-' . $id;
    if (!mkdir($release, 0700)) {
        throw new RuntimeException('Не удалось подготовить каталог публикации.');
    }
    try {
        foreach (array_keys(publicPages($services)) as $page) {
            $html = renderPage($page, $content[$page] ?? [], false, $contacts, $services);
            if (file_put_contents($release . '/' . ($page ?: 'home') . '.html', $html) !== strlen($html)) {
                throw new RuntimeException('Не удалось записать страницы публикации.');
            }
        }
    } catch (Throwable $failure) {
        foreach (glob($release . '/*.html') as $file) {
            unlink($file);
        }
        rmdir($release);
        throw $failure;
    }
    return $id;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
