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
    // Убедимся что сессия стартовала
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

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
        if (!$account) {
            // Попробуем без JOIN чтобы понять в чём проблема
            try {
                $s2 = $pdo->prepare("SELECT account_id FROM sessions WHERE id=? AND expires_at > NOW()");
                $s2->execute([$sessionId]);
                $row = $s2->fetch();
                if ($row) {
                    $a2 = $pdo->prepare("SELECT * FROM accounts WHERE id=?");
                    $a2->execute([$row['account_id']]);
                    $account = $a2->fetch();
                }
            } catch(Exception $e2) {}
        }
        if (!$account) return null;

        // Обновляем last_login раз в час чтобы не писать на каждый запрос
        $lastLogin = $account['last_login'] ?? null;
        if (!$lastLogin || (time() - strtotime($lastLogin)) > 3600) {
            try { $pdo->prepare("UPDATE accounts SET last_login=NOW() WHERE id=?")->execute([$account['id']]); } catch(Exception $e) {}
        }
        return $account;
    } catch (Exception $e) {
        // JOIN упал — пробуем fallback без JOIN
        try {
            $s2 = $pdo->prepare("SELECT account_id FROM sessions WHERE id=? AND expires_at > NOW()");
            $s2->execute([$sessionId]);
            $row = $s2->fetch();
            if ($row) {
                $a2 = $pdo->prepare("SELECT * FROM accounts WHERE id=?");
                $a2->execute([$row['account_id']]);
                return $a2->fetch() ?: null;
            }
        } catch(Exception $e2) {}
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
    // 1. Приоритет — веб-сессия (ВСЕГДА выигрывает, даже если есть tg_user_id в GET)
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

    // Определяем secure динамически — работает и на HTTP (Render internal), и на HTTPS
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');

    $expires = time() + 86400 * $days;
    $secureFlag = $isSecure ? '; Secure' : '';
    // header() напрямую — единственный надёжный способ на Render с ob_start()
    header('Set-Cookie: bw_session=' . $sessionId . '; Path=/; HttpOnly; SameSite=Lax; Expires=' . gmdate('D, d M Y H:i:s T', $expires) . $secureFlag, false);
    setcookie('bw_session', $sessionId, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isSecure,
    ]);
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