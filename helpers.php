<?php
/**
 * helpers.php — общие вспомогательные функции приложения
 *
 * Здесь собраны утилиты без побочных эффектов:
 * - base_path(): вычисление базового пути приложения (если развёрнуто в подпапке)
 * - asset(): построение URL для ассетов с учётом base_path
 * - json_response(): единый JSON-ответ API
 * - validate_slug(): обёртка над db_validate_slug() из db-api.php
 */

declare(strict_types=1);

// Важно: никаких session_start() и других сайд-эффектов здесь нет

/**
 * Базовый путь приложения относительно домена.
 * Пример: если скрипт лежит в /subdir/index.php — вернёт "/subdir"
 * Если на корне домена — вернёт пустую строку "".
 */
function base_path(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // Попытка №1: вычислить подкаталог относительно DOCUMENT_ROOT и __DIR__
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $dir = realpath(__DIR__);
    if ($doc !== false && $dir !== false) {
        $doc = rtrim(str_replace('\\','/', $doc), '/');
        $dir = rtrim(str_replace('\\','/', $dir), '/');
        if ($doc !== '' && strpos($dir, $doc) === 0) {
            $rel = substr($dir, strlen($doc));
            if ($rel === false) { $rel = ''; }
            $rel = rtrim($rel, '/');
            if ($rel === '' || $rel === '.') { return $cached = ''; }
            return $cached = ($rel[0] === '/' ? $rel : '/' . $rel);
        }
    }

    // Фолбэк: по SCRIPT_NAME (может быть пустым на некоторых конфигурациях)
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base === '/' || $base === '.') { $base = ''; }
    return $cached = ($base ?: '');
}

/** Построение URL для ассетов с учётом базового пути */
function asset(string $path): string {
    $b = base_path();
    return ($b === '' ? '' : $b) . $path;
}

/** Стандартизованный JSON-ответ для API */
function json_response($data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

/** Обёртка валидации slug через DAO-уровень */
function validate_slug(string $slug): bool {
    // Функция db_validate_slug() объявлена в db-api.php
    return db_validate_slug($slug);
}

// rrmdir() удалён как неиспользуемый дубликат (см. db-api.php::db_rrmdir)

/**
 * Очистка HTML-контента: разворачивает (удаляет тег) <span style="color:hsl(0,0%,0%)">...</span>
 * и возвращает внутреннее содержимое без этого инлайнового цвета.
 *
 * Поддерживает вариации пробелов в значении цвета: hsl(0, 0%, 0%).
 * Если парсинг DOM не удался, возвращает исходную строку.
 */
function sanitize_black_span_color(string $html): string {
    if ($html === '') return $html;
    // Быстрая проверка наличия ключевых подпоследовательностей
    // Достаточно искать <span и color:, не ограничиваемся только hsl
    if (stripos($html, '<span') === false || stripos($html, 'color:') === false) {
        return $html;
    }

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    // Оборачиваем во внешний контейнер для корректного парсинга фрагмента
    $wrapped = '<!DOCTYPE html><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div id="__root__">' . $html . '</div>';
    // Загружаем как HTML. DOMDocument ожидает HTML в ISO-8859-1, поэтому явно указываем мета
    $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if (!$loaded) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $html;
    }

    $root = $dom->getElementById('__root__');
    if (!$root) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $html;
    }

    $spans = $root->getElementsByTagName('span');
    // Коллекция «живая», поэтому сначала скопируем элементы в массив
    $toProcess = [];
    foreach ($spans as $sp) { $toProcess[] = $sp; }

    foreach ($toProcess as $sp) {
        /** @var DOMElement $sp */
        if (!$sp->hasAttribute('style')) continue;
        $style = $sp->getAttribute('style');
        // Нормализуем стиль: удаляем пробелы вокруг двоеточий/точек с запятой
        $norm = strtolower(trim($style));
        // Ищем свойство color со значением hsl(0,0%,0%) с допускаемыми пробелами
        // Сначала грубая проверка по подстроке
        if (strpos($norm, 'color') === false || strpos($norm, 'hsl') === false) continue;

        // Парсим стиль на пары свойств
        $pairs = array_filter(array_map('trim', explode(';', $norm)), 'strlen');
        $styles = [];
        foreach ($pairs as $p) {
            $kv = array_map('trim', explode(':', $p, 2));
            if (count($kv) === 2) {
                $styles[$kv[0]] = $kv[1];
            }
        }
        if (!isset($styles['color'])) continue;
        $valNorm = strtolower(trim($styles['color']));
        // Убираем !important и все пробелы
        $valNorm = preg_replace('/!important\b/i', '', $valNorm);
        $val = preg_replace('/\s+/', '', $valNorm);
        // Целевые значения «чёрного» цвета
        $blackValues = [
            'hsl(0,0%,0%)',
            'black',
            'rgb(0,0,0)',
            '#000',
            '#000000'
        ];
        if (in_array($val, $blackValues, true)) {
            // Разворачиваем span: перемещаем всех детей перед span и удаляем span
            while ($sp->firstChild) {
                $sp->parentNode->insertBefore($sp->firstChild, $sp);
            }
            if ($sp->parentNode) {
                $sp->parentNode->removeChild($sp);
            }
        }
    }

    // Извлекаем HTML из корневого контейнера
    $out = '';
    foreach (iterator_to_array($root->childNodes) as $node) {
        $out .= $dom->saveHTML($node);
    }

    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $out;
}
