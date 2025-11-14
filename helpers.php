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
    try {
        if ($html === '') return $html;
        // Быстрая проверка наличия ключевых подпоследовательностей
        // Достаточно искать <span и color:, не ограничиваемся только hsl
        if (stripos($html, '<span') === false || stripos($html, 'color') === false) {
            return $html;
        }

        // 1) Быстрый и устойчивый проход на регулярках: разворачиваем любые span с color=чёрный
        // Поддерживаются двойные/одинарные кавычки, любой порядок свойств, пробелы и !important
        $prevHtml = null;
        $iterGuard = 0;
        while ($prevHtml !== $html && $iterGuard < 5) { // несколько итераций на случай вложенных span
            $prevHtml = $html;
            $html = preg_replace_callback(
                "/<span\\b([^>]*)>([\\s\\S]*?)<\\/span>/i",
                function ($m) {
                    $attrs = $m[1];
                    $content = $m[2];
                    // Ищем style="..." или style='...'
                    if (!preg_match("/style\\s*=\\s*(\"|')(.*?)(\\1)/is", $attrs, $sm)) {
                        return $m[0];
                    }
                    $styleRaw = strtolower(trim($sm[2]));
                    // Нормализуем стиль: уберём переносы/много пробелов
                    $styleNorm = preg_replace('/\s+/', ' ', $styleRaw);
                    // Выделим значение color
                    $colorVal = null;
                    // Разбираем на пары свойств
                    foreach (preg_split('/;\s*/', $styleNorm) as $pair) {
                        if ($pair === '') continue;
                        $kv = array_map('trim', explode(':', $pair, 2));
                        if (count($kv) === 2 && $kv[0] === 'color') {
                            $colorVal = preg_replace('/!important\\b/i', '', strtolower(trim($kv[1])));
                            $colorVal = preg_replace('/\s+/', '', $colorVal);
                            break;
                        }
                    }
                    if ($colorVal === null) return $m[0];
                    // Специальный кейс: color:#1B1C1D — удалить только это свойство из style, сохранив span
                    if ($colorVal === '#1b1c1d') {
                        $quote = $sm[1];
                        $styleOriginal = $sm[2]; // оригинальный регистр и пробелы внутри кавычек
                        // Пересобираем style без color
                        $decls = preg_split('/;\s*/', $styleOriginal);
                        $kept = [];
                        foreach ($decls as $d) {
                            if ($d === '') continue;
                            $kv = array_map('trim', explode(':', $d, 2));
                            if (count($kv) !== 2) { $kept[] = $d; continue; }
                            $prop = strtolower($kv[0]);
                            if ($prop === 'color') {
                                $valNorm = strtolower(preg_replace('/\s+/', '', preg_replace('/!important\b/i','', $kv[1])));
                                if ($valNorm === '#1b1c1d') {
                                    // пропускаем это свойство
                                    continue;
                                }
                            }
                            $kept[] = $kv[0] . ':' . $kv[1];
                        }
                        $newStyle = implode('; ', $kept);
                        // Удаляем исходный фрагмент style="..." из $attrs и вставляем новый если остался
                        $attrsModified = $attrs;
                        $attrsModified = preg_replace("/\s*style\s*=\s*(\"|')(.*?)(\\1)/is", '', $attrsModified, 1);
                        $attrsModified = rtrim($attrsModified);
                        if ($newStyle !== '') {
                            // гарантируем ведущий пробел перед атрибутом
                            $attrsModified .= ' style=' . $quote . $newStyle . $quote;
                        }
                        // Возвращаем исходный span с модифицированными атрибутами
                        return '<span' . $attrsModified . '>' . $content . '</span>';
                    }
                    $blackValues = ['hsl(0,0%,0%)','black','rgb(0,0,0)','rgba(0,0,0,1)','#000','#000000'];
                    if (in_array($colorVal, $blackValues, true)) {
                        // Разворачиваем span: возвращаем только содержимое
                        return $content;
                    }
                    return $m[0];
                },
                $html
            );
            $iterGuard++;
        }

        // Отключаем DOM/libxml-этап для максимальной совместимости окружений
        return $html;
    } catch (Throwable $e) {
        // На проде не падать: вернуть исходный HTML
        return $html;
    }
}
