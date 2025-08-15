<?php
/**
 * Модуль аутентификации админки
 *
 * Функции:
 * - is_admin_authenticated(): проверка сессии
 * - admin_login(login, password): вход по кредам из config.php (admin_credentials)
 * - admin_logout(): выход, зачистка сессии и cookies
 *
 * Предполагается, что сессия уже запущена (session_start()) в вызывающем скрипте
 * и что подключён config.php, предоставляющий admin_credentials().
 */

declare(strict_types=1);

/** Проверка, что админ аутентифицирован (по сессии) */
function is_admin_authenticated(): bool {
    return !empty($_SESSION['admin_ok']);
}

/** Вход админа: сравнение логина/пароля с конфигом */
function admin_login(string $login, string $password): bool {
    if (!function_exists('admin_credentials')) {
        return false;
    }
    $creds = admin_credentials();
    $envLogin = (string)($creds['login'] ?? '');
    $envPass  = (string)($creds['password'] ?? '');
    $ok = ($login === $envLogin && $password === $envPass);
    if ($ok) {
        // Перегенерируем ID сессии для безопасности и чтобы форсировать установку cookie
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['admin_ok'] = true;
        // Сразу закрываем сессию, чтобы данные записались до следующего запроса
        if (function_exists('session_write_close')) { @session_write_close(); }
    }
    // Диагностика: логируем только при неуспехе без раскрытия пароля
    if (!$ok) {
        $masked = str_repeat('*', strlen($envPass));
        @error_log('[DOMLearn] Admin login failed: provided_login=' . $login . ' env_login=' . $envLogin . ' env_pass_len=' . strlen($envPass) . ' env_pass_mask=' . $masked);
    }
    return $ok;
}

/** Выход админа: очистка сессии и cookies */
function admin_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
