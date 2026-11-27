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
            $update($state);
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $temporary = tempnam($directory, '.state-');
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
    $queries = [
        'text' => '//title | //main//*[self::h1 or self::h2 or self::h3 or self::p or self::li or self::summary]/text()[normalize-space()]',
        'content' => '//meta[@name="description"]/@content',
        'src' => '//main//img/@src',
        'alt' => '//main//img/@alt',
        'href' => '//a[starts-with(@href,"tel:") or starts-with(@href,"https://t.me/") or starts-with(@href,"https://max.ru/")]/@href',
    ];
    foreach ($queries as $type => $query) {
        foreach ($xpath->query($query) as $index => $node) {
            $key = $type . '-' . $index . '-' . substr(hash('sha256', $node->getNodePath() . $node->nodeValue), 0, 16);
            $fields[$key] = $node;
        }
    }
    return $fields;
}

function renderPage(string $page, array $content, bool $preview = false): string {
    $document = document($page);
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
            $node->replaceChildren($document->createTextNode($value));
        } else {
            $node->nodeValue = $value;
        }
    }
    $head = $document->getElementsByTagName('head')->item(0);
    $canonical = $document->createElement('link');
    $canonical->setAttribute('rel', 'canonical');
    $canonical->setAttribute('href', ORIGIN . '/' . ($page === '' ? '' : $page . '/'));
    $head->appendChild($canonical);
    $metadata = $document->createElement('script');
    $metadata->setAttribute('type', 'application/ld+json');
    $metadata->appendChild($document->createTextNode(json_encode([
        '@context' => 'https://schema.org', '@type' => $page === '' ? 'WebSite' : 'WebPage',
        'name' => $document->getElementsByTagName('title')->item(0)->textContent,
        'url' => $canonical->getAttribute('href'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)));
    $head->appendChild($metadata);
    return $document->saveHTML();
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
