<?php
// ============================================================
// auth.php — общий модуль авторизации
// Подключается в начале index.php через require_once 'auth.php';
// ============================================================

/**
 * Получить текущий аккаунт из сессии (или null если не залогинен)
 * @return array|null
 */
function getCurrentAccount(PDO $pdo): ?array {
    $sessionId = $_COOKIE['bw_session'] ?? '';
    if (!$sessionId || strlen($sessionId) < 32) return null;

    try {
        $stmt = $pdo->prepare("
            SELECT a.*, s.expires_at
            FROM sessions s
            JOIN accounts a ON a.id = s.account_id
            WHERE s.id = ? AND s.expires_at > NOW()
        ");
        $stmt->execute([$sessionId]);
        $account = $stmt->fetch();
        if (!$account) return null;

        // Обновляем last_login раз в час чтобы не писать на каждый запрос
        $lastLogin = $account['last_login'] ?? null;
        if (!$lastLogin || (time() - strtotime($lastLogin)) > 3600) {
            $pdo->prepare("UPDATE accounts SET last_login=NOW() WHERE id=?")->execute([$account['id']]);
        }
        return $account;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Если не авторизован — редиректит на /login
 */
function requireAuth(PDO $pdo): array {
    $account = getCurrentAccount($pdo);
    if (!$account) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header("Location: /login?redirect={$redirect}");
        exit;
    }
    return $account;
}

/**
 * Универсальный ID пользователя для всех операций (библиотека, голоса, прогресс).
 * Возвращает ['type' => 'account'|'tg', 'account_id' => int|null, 'tg_id' => int|null]
 */
function getEffectiveAccountId(PDO $pdo): array {
    // 1. Приоритет — веб-сессия
    $account = getCurrentAccount($pdo);
    if ($account) {
        return [
            'type'       => 'account',
            'account_id' => (int)$account['id'],
            'tg_id'      => $account['tg_user_id'] ? (int)$account['tg_user_id'] : null,
        ];
    }

    // 2. Fallback — TG кука/параметр (обратная совместимость с ботом)
    $tgId = $_GET['tg_user_id'] ?? $_POST['tg_user_id'] ?? '';
    if ($tgId && is_numeric($tgId)) {
        if (!headers_sent()) setcookie('tg_user_id', $tgId, time()+86400*30, '/', '', false, false);
        $_SESSION['tg_user_id'] = $tgId;
        return ['type' => 'tg', 'account_id' => null, 'tg_id' => (int)$tgId];
    }
    if (!empty($_SESSION['tg_user_id'])) {
        return ['type' => 'tg', 'account_id' => null, 'tg_id' => (int)$_SESSION['tg_user_id']];
    }
    if (!empty($_COOKIE['tg_user_id']) && is_numeric($_COOKIE['tg_user_id'])) {
        $_SESSION['tg_user_id'] = $_COOKIE['tg_user_id'];
        return ['type' => 'tg', 'account_id' => null, 'tg_id' => (int)$_COOKIE['tg_user_id']];
    }

    // 3. Гость (нет ни сессии, ни TG)
    if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);
    return ['type' => 'guest', 'account_id' => null, 'tg_id' => (int)$_SESSION['guest_id']];
}

/**
 * Старая совместимая функция — возвращает просто int (tg_user_id или guest_id)
 * Используется там где менять не хотим
 */
function getEffectiveUserId(PDO $pdo): int {
    $info = getEffectiveAccountId($pdo);
    if ($info['type'] === 'account') {
        // Для account возвращаем tg_id если есть, иначе account_id * -1 (никогда не пересечётся с tg_id)
        return $info['tg_id'] ?? -$info['account_id'];
    }
    return $info['tg_id'];
}

/**
 * Создать сессию в БД и выставить куку
 */
function createSession(PDO $pdo, int $accountId, bool $remember = true): void {
    $sessionId = bin2hex(random_bytes(32)); // 64 символа
    $days = $remember ? 30 : 1;
    $pdo->prepare("
        INSERT INTO sessions (id, account_id, ip, user_agent, expires_at)
        VALUES (?, ?, ?, ?, NOW() + INTERVAL '{$days} days')
    ")->execute([
        $sessionId,
        $accountId,
        $_SERVER['REMOTE_ADDR'] ?? '',
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    $cookieOptions = [
        'expires'  => time() + 86400 * $days,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => true,
    ];
    setcookie('bw_session', $sessionId, $cookieOptions);
}

/**
 * Удалить сессию (логаут)
 */
function destroySession(PDO $pdo): void {
    $sessionId = $_COOKIE['bw_session'] ?? '';
    if ($sessionId) {
        try { $pdo->prepare("DELETE FROM sessions WHERE id=?")->execute([$sessionId]); } catch (Exception $e) {}
    }
    setcookie('bw_session', '', time()-3600, '/');
}

/**
 * Сгенерировать токен привязки TG для аккаунта
 */
function generateTgLinkToken(PDO $pdo, int $accountId): string {
    $token = bin2hex(random_bytes(16)); // 32 символа
    $pdo->prepare("UPDATE accounts SET tg_link_token=? WHERE id=?")->execute([$token, $accountId]);
    return $token;
}