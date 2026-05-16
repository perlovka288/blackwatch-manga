<?php
// ============================================================
// functions.php — BLACKWATCH MANGA
// Подключить в index.php после require_once __DIR__ . '/auth.php';
// require_once __DIR__ . '/functions.php';
// ============================================================

// ========================= XP КОНСТАНТЫ =========================

define('XP_PAGE',     2);   // за 1 страницу
define('XP_CHAPTER',  15);  // за завершённую главу
define('XP_COMMENT',  20);  // за комментарий
define('XP_RATING',   10);  // за оценку
define('XP_LIKE',     5);   // за лайк (получен)
define('XP_DAILY',    50);  // за ежедневную активность
define('XP_STREAK_BONUS', 10); // бонус за каждый день стрика

// ========================= УРОВНИ =========================

function getLevel(int $xp): int {
    // Формула: level = floor(1 + sqrt(xp / 100))
    // Уровни: 1=0xp, 2=100, 3=400, 5=1600, 10=8100, 20=38k, 50=245k
    return max(1, (int)floor(1 + sqrt($xp / 100)));
}

function xpForLevel(int $level): int {
    return (int)pow(max(1, $level - 1), 2) * 100;
}

function xpProgressInLevel(int $totalXp): array {
    $level = getLevel($totalXp);
    $currentLevelXp = xpForLevel($level);
    $nextLevelXp = xpForLevel($level + 1);
    $delta = $nextLevelXp - $currentLevelXp;
    $progress = $delta > 0 ? min(100, round(($totalXp - $currentLevelXp) / $delta * 100)) : 100;
    return [
        'current'  => $totalXp - $currentLevelXp,
        'needed'   => $delta,
        'pct'      => $progress,
        'next_lvl' => $level + 1,
    ];
}

function getLevelFrame(int $level): array {
    if ($level >= 100) return ['color' => '#ff006e', 'label' => '💎 Легенда',   'glow' => true];
    if ($level >= 50)  return ['color' => '#f59e0b', 'label' => '🔮 Мастер',    'glow' => true];
    if ($level >= 30)  return ['color' => '#7c5cff', 'label' => '⭐ Опытный',   'glow' => false];
    if ($level >= 20)  return ['color' => '#3b82f6', 'label' => '📘 Читатель',  'glow' => false];
    if ($level >= 10)  return ['color' => '#4ade80', 'label' => '🌱 Новичок+',  'glow' => false];
    return ['color' => '#6b7280', 'label' => '🌑 Новичок', 'glow' => false];
}

// ========================= XP: НАЧИСЛЕНИЕ =========================

function addXP(PDO $pdo, int $accountId, int $amount, string $source = ''): array {
    if ($accountId <= 0 || $amount <= 0) return [];
    try {
        // Инициализируем запись если нет
        $pdo->prepare("INSERT INTO user_xp (account_id, total_xp, level, weekly_xp) VALUES (?,?,1,?)
            ON CONFLICT (account_id) DO UPDATE SET
                total_xp    = user_xp.total_xp + ?,
                weekly_xp   = user_xp.weekly_xp + ?,
                updated_at  = NOW()
            RETURNING total_xp, level")
            ->execute([$accountId, $amount, $amount, $amount, $amount]);

        $xpRow = $pdo->prepare("SELECT total_xp, level FROM user_xp WHERE account_id=?");
        $xpRow->execute([$accountId]);
        $row = $xpRow->fetch();
        if (!$row) return [];

        $newLevel = getLevel((int)$row['total_xp']);
        $leveledUp = $newLevel > (int)$row['level'];

        if ($newLevel !== (int)$row['level']) {
            $pdo->prepare("UPDATE user_xp SET level=? WHERE account_id=?")->execute([$newLevel, $accountId]);
        }

        return ['xp' => (int)$row['total_xp'], 'level' => $newLevel, 'leveled_up' => $leveledUp];
    } catch (Exception $e) { return []; }
}

// ========================= СТАТИСТИКА: ЗАПИСЬ ПРОЧИТАННЫХ СТРАНИЦ =========================

function recordPageRead(PDO $pdo, int $accountId, int $pages = 1, bool $isChapter = false): void {
    if ($accountId <= 0) return;
    try {
        // Обновить user_stats
        $chapterInc = $isChapter ? 1 : 0;
        $pdo->prepare("INSERT INTO user_stats (account_id, total_pages_read, total_chapters_read)
            VALUES (?, ?, ?)
            ON CONFLICT (account_id) DO UPDATE SET
                total_pages_read    = user_stats.total_pages_read + ?,
                total_chapters_read = user_stats.total_chapters_read + ?,
                updated_at = NOW()")
            ->execute([$accountId, $pages, $chapterInc, $pages, $chapterInc]);

        // Обновить weekly_xp stats
        $pdo->prepare("INSERT INTO user_xp (account_id, weekly_pages, weekly_chapters)
            VALUES (?,?,?)
            ON CONFLICT (account_id) DO UPDATE SET
                weekly_pages    = user_xp.weekly_pages + ?,
                weekly_chapters = user_xp.weekly_chapters + ?,
                updated_at = NOW()")
            ->execute([$accountId, $pages, $chapterInc, $pages, $chapterInc]);

        // XP за страницы
        if ($pages > 0) addXP($pdo, $accountId, $pages * XP_PAGE, 'page');
        if ($isChapter) addXP($pdo, $accountId, XP_CHAPTER, 'chapter');

        // Ежедневный стрик
        updateReadingStreak($pdo, $accountId);

        // Онлайн статус
        updateOnlineStatus($pdo, $accountId);

        // Проверить достижения
        checkAchievements($pdo, $accountId);
    } catch (Exception $e) {}
}

// ========================= СТРИК =========================

function updateReadingStreak(PDO $pdo, int $accountId): void {
    try {
        $row = $pdo->prepare("SELECT reading_streak, last_read_date FROM user_stats WHERE account_id=?");
        $row->execute([$accountId]);
        $stat = $row->fetch();

        $today = date('Y-m-d');
        if (!$stat) {
            $pdo->prepare("INSERT INTO user_stats (account_id, reading_streak, last_read_date)
                VALUES (?,1,?) ON CONFLICT DO NOTHING")->execute([$accountId, $today]);
            return;
        }

        $lastDate = $stat['last_read_date'];
        $streak = (int)$stat['reading_streak'];

        if ($lastDate === $today) return; // Уже сегодня читал

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($lastDate === $yesterday) {
            $streak++; // Продолжаем стрик
            addXP($pdo, $accountId, XP_DAILY + $streak * XP_STREAK_BONUS, 'streak');
        } else {
            $streak = 1; // Сброс стрика
            addXP($pdo, $accountId, XP_DAILY, 'daily');
        }

        $pdo->prepare("UPDATE user_stats SET reading_streak=?, last_read_date=? WHERE account_id=?")
            ->execute([$streak, $today, $accountId]);
    } catch (Exception $e) {}
}

// ========================= ОНЛАЙН СТАТУС =========================

function updateOnlineStatus(PDO $pdo, int $accountId): void {
    try {
        $pdo->prepare("INSERT INTO user_online (account_id, last_seen) VALUES (?,NOW())
            ON CONFLICT (account_id) DO UPDATE SET last_seen=NOW()")->execute([$accountId]);
        $pdo->prepare("UPDATE accounts SET last_seen=NOW() WHERE id=?")->execute([$accountId]);
    } catch (Exception $e) {}
}

function getOnlineStatus(?string $lastSeen): string {
    if (!$lastSeen) return 'offline';
    $diff = time() - strtotime($lastSeen);
    if ($diff < 300)  return 'online';     // 5 минут
    if ($diff < 1800) return 'recently';   // 30 минут
    return 'offline';
}

function getOnlineLabel(string $status, ?string $lastSeen): string {
    if ($status === 'online')   return '🟢 Онлайн';
    if ($status === 'recently') return '🟡 Был недавно';
    if ($lastSeen) {
        $diff = time() - strtotime($lastSeen);
        if ($diff < 3600)   return '⚫ ' . round($diff/60) . ' мин назад';
        if ($diff < 86400)  return '⚫ ' . round($diff/3600) . ' ч назад';
        if ($diff < 604800) return '⚫ ' . round($diff/86400) . ' дн назад';
    }
    return '⚫ Не в сети';
}

// ========================= ПРОВЕРКА ДОСТИЖЕНИЙ =========================

function checkAchievements(PDO $pdo, int $accountId): array {
    $unlocked = [];
    try {
        // Получаем все достижения и текущий прогресс пользователя
        $stmt = $pdo->prepare("SELECT a.*, COALESCE(ua.progress, 0) as current_progress, ua.unlocked_at
            FROM achievements a
            LEFT JOIN user_achievements ua ON ua.achievement_id=a.id AND ua.account_id=?");
        $stmt->execute([$accountId]);
        $achievements = $stmt->fetchAll();

        // Получаем текущую статистику
        $statStmt = $pdo->prepare("SELECT us.*, ux.level, ux.total_xp FROM user_stats us
            LEFT JOIN user_xp ux ON ux.account_id=us.account_id
            WHERE us.account_id=?");
        $statStmt->execute([$accountId]);
        $stats = $statStmt->fetch() ?: [];

        $accCreatedStmt = $pdo->prepare("SELECT created_at FROM accounts WHERE id=?");
        $accCreatedStmt->execute([$accountId]);
        $accInfo = $accCreatedStmt->fetch();

        // Ночной читатель: текущий час
        $isNight = (int)date('H') >= 0 && (int)date('H') < 5;

        foreach ($achievements as $ach) {
            if ($ach['unlocked_at']) continue; // Уже получено

            $current = 0;
            switch ($ach['requirement_type']) {
                case 'manga_read':    $current = (int)($stats['total_manga_read']    ?? 0); break;
                case 'pages_read':    $current = (int)($stats['total_pages_read']    ?? 0); break;
                case 'chapters_read': $current = (int)($stats['total_chapters_read'] ?? 0); break;
                case 'comments':      $current = (int)($stats['total_comments']       ?? 0); break;
                case 'comment_likes': $current = (int)($stats['comment_likes_received'] ?? 0); break;
                case 'ratings':       $current = (int)($stats['total_ratings']        ?? 0); break;
                case 'streak':        $current = (int)($stats['reading_streak']       ?? 0); break;
                case 'level':         $current = (int)($stats['level']                ?? 1); break;
                case 'night_read':    $current = $isNight ? 1 : 0; break;
                case 'account_age':
                    if ($accInfo) {
                        $days = (int)floor((time() - strtotime($accInfo['created_at'])) / 86400);
                        $current = $days;
                    }
                    break;
            }

            // Обновить прогресс
            $pdo->prepare("INSERT INTO user_achievements (account_id, achievement_id, progress)
                VALUES (?,?,?)
                ON CONFLICT (account_id, achievement_id) DO UPDATE SET progress=EXCLUDED.progress")
                ->execute([$accountId, $ach['id'], $current]);

            // Разблокировать если достигнуто
            if ($current >= (int)$ach['requirement_value']) {
                $pdo->prepare("UPDATE user_achievements SET unlocked_at=NOW() WHERE account_id=? AND achievement_id=?")
                    ->execute([$accountId, $ach['id']]);

                // XP за достижение
                addXP($pdo, $accountId, (int)$ach['xp_reward'], 'achievement');

                // Уведомление
                sendInternalNotification($pdo, $accountId, 'achievement', null, (int)$ach['id'],
                    "🏆 Достижение разблокировано: {$ach['name']}! (+{$ach['xp_reward']} XP)");

                $unlocked[] = $ach;
            }
        }
    } catch (Exception $e) {}
    return $unlocked;
}

// ========================= СИНХРОНИЗАЦИЯ СТАТИСТИКИ =========================

function syncUserStats(PDO $pdo, int $accountId): void {
    try {
        // Получить tg_user_id
        $accStmt = $pdo->prepare("SELECT tg_user_id FROM accounts WHERE id=?");
        $accStmt->execute([$accountId]);
        $acc = $accStmt->fetch();
        $tgId = $acc ? (int)$acc['tg_user_id'] : 0;

        // Считаем прочитанную мангу (из обеих систем)
        $mangaReadStmt = $pdo->prepare("SELECT COUNT(*) FROM user_manga_status
            WHERE status='read' AND (account_id=? " . ($tgId ? "OR user_id=?" : "") . ")");
        if ($tgId) $mangaReadStmt->execute([$accountId, $tgId]);
        else $mangaReadStmt->execute([$accountId]);
        $mangaRead = (int)$mangaReadStmt->fetchColumn();

        // Считаем оценки
        $ratingsCount = 0;
        if ($tgId) {
            $rStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_ratings WHERE user_id=?");
            $rStmt->execute([$tgId]);
            $ratingsCount = (int)$rStmt->fetchColumn();
        }

        // Считаем комментарии
        $commStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_comments WHERE account_id=? AND is_deleted=FALSE");
        $commStmt->execute([$accountId]);
        $commCount = (int)$commStmt->fetchColumn();

        // Обновляем user_stats
        $pdo->prepare("INSERT INTO user_stats (account_id, total_manga_read, total_ratings, total_comments)
            VALUES (?,?,?,?)
            ON CONFLICT (account_id) DO UPDATE SET
                total_manga_read = EXCLUDED.total_manga_read,
                total_ratings    = EXCLUDED.total_ratings,
                total_comments   = EXCLUDED.total_comments,
                updated_at = NOW()")
            ->execute([$accountId, $mangaRead, $ratingsCount, $commCount]);

        // Пересчитать уровень
        $xpStmt = $pdo->prepare("SELECT total_xp FROM user_xp WHERE account_id=?");
        $xpStmt->execute([$accountId]);
        $xpRow = $xpStmt->fetch();
        if ($xpRow) {
            $correctLevel = getLevel((int)$xpRow['total_xp']);
            $pdo->prepare("UPDATE user_xp SET level=? WHERE account_id=?")->execute([$correctLevel, $accountId]);
        }

        // Инициализировать XP если нет
        $pdo->prepare("INSERT INTO user_xp (account_id) VALUES (?) ON CONFLICT DO NOTHING")->execute([$accountId]);

    } catch (Exception $e) {}
}

// ========================= ПРОФИЛЬ ПОЛЬЗОВАТЕЛЯ =========================

function getUserProfile(PDO $pdo, string $username): ?array {
    try {
        $stmt = $pdo->prepare("SELECT a.id, a.username, a.email, a.created_at as reg_date, a.is_admin,
            a.admin_tag, a.is_verified, a.last_seen, a.profile_privacy,
            pc.avatar_url, pc.banner_url, pc.banner_color, pc.bio,
            COALESCE(ux.total_xp, 0) as total_xp,
            COALESCE(ux.level, 1) as level,
            COALESCE(ux.weekly_xp, 0) as weekly_xp,
            COALESCE(ux.weekly_pages, 0) as weekly_pages,
            COALESCE(ux.weekly_chapters, 0) as weekly_chapters,
            COALESCE(us.total_manga_read, 0) as total_manga_read,
            COALESCE(us.total_chapters_read, 0) as total_chapters_read,
            COALESCE(us.total_pages_read, 0) as total_pages_read,
            COALESCE(us.total_ratings, 0) as total_ratings,
            COALESCE(us.total_comments, 0) as total_comments,
            COALESCE(us.comment_likes_received, 0) as comment_likes_received,
            COALESCE(us.reading_streak, 0) as reading_streak
            FROM accounts a
            LEFT JOIN profile_customizations pc ON pc.account_id=a.id
            LEFT JOIN user_xp ux ON ux.account_id=a.id
            LEFT JOIN user_stats us ON us.account_id=a.id
            WHERE LOWER(a.username)=LOWER(?)");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user) return null;

        $uid = (int)$user['id'];

        // Библиотечная статистика (из user_manga_status)
        $tgStmt = $pdo->prepare("SELECT tg_user_id FROM accounts WHERE id=?");
        $tgStmt->execute([$uid]);
        $tgRow = $tgStmt->fetch();
        $tgId = $tgRow ? (int)$tgRow['tg_user_id'] : 0;

        $libStats = [];
        foreach (['read', 'reading', 'plan_to_read', 'dropped', 'on_hold'] as $status) {
            $lStmt = $pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE status=?
                AND (account_id=? " . ($tgId ? "OR user_id=?" : "") . ")");
            if ($tgId) $lStmt->execute([$status, $uid, $tgId]);
            else $lStmt->execute([$status, $uid]);
            $libStats[$status] = (int)$lStmt->fetchColumn();
        }

        // Последние комментарии
        $cStmt = $pdo->prepare("SELECT mc.id, mc.text, mc.likes, mc.created_at, m.title as manga_title, mc.manga_id
            FROM manga_comments mc
            JOIN manga m ON m.id=mc.manga_id
            WHERE mc.account_id=? AND mc.is_deleted=FALSE
            ORDER BY mc.created_at DESC LIMIT 5");
        $cStmt->execute([$uid]);
        $comments = $cStmt->fetchAll();

        // Достижения (только разблокированные)
        $aStmt = $pdo->prepare("SELECT a.key, a.name, a.icon, a.rarity, a.xp_reward, ua.unlocked_at
            FROM user_achievements ua
            JOIN achievements a ON a.id=ua.achievement_id
            WHERE ua.account_id=? AND ua.unlocked_at IS NOT NULL
            ORDER BY ua.unlocked_at DESC LIMIT 20");
        $aStmt->execute([$uid]);
        $achievements = $aStmt->fetchAll();

        // Онлайн статус
        $onlineStmt = $pdo->prepare("SELECT last_seen FROM user_online WHERE account_id=?");
        $onlineStmt->execute([$uid]);
        $onlineRow = $onlineStmt->fetch();
        $lastSeen = $onlineRow['last_seen'] ?? $user['last_seen'] ?? null;
        $onlineStatus = getOnlineStatus($lastSeen);

        // XP прогресс
        $xpProgress = xpProgressInLevel((int)$user['total_xp']);

        return [
            'user'        => $user,
            'lib_stats'   => $libStats,
            'comments'    => $comments,
            'achievements'=> $achievements,
            'online'      => [
                'status'   => $onlineStatus,
                'label'    => getOnlineLabel($onlineStatus, $lastSeen),
                'last_seen'=> $lastSeen,
            ],
            'xp'          => [
                'total'    => (int)$user['total_xp'],
                'level'    => (int)$user['level'],
                'weekly'   => (int)$user['weekly_xp'],
                'progress' => $xpProgress,
            ],
        ];
    } catch (Exception $e) { return null; }
}

// ========================= КОММЕНТАРИИ =========================

function likeComment(PDO $pdo, int $commentId, int $accountId): array {
    try {
        $check = $pdo->prepare("SELECT 1 FROM comment_likes WHERE comment_id=? AND account_id=?");
        $check->execute([$commentId, $accountId]);
        $liked = false;

        if ($check->fetch()) {
            // Убрать лайк
            $pdo->prepare("DELETE FROM comment_likes WHERE comment_id=? AND account_id=?")->execute([$commentId, $accountId]);
            $pdo->prepare("UPDATE manga_comments SET likes=GREATEST(0,likes-1) WHERE id=?")->execute([$commentId]);
        } else {
            // Поставить лайк
            $pdo->prepare("INSERT INTO comment_likes (comment_id, account_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$commentId, $accountId]);
            $pdo->prepare("UPDATE manga_comments SET likes=likes+1 WHERE id=?")->execute([$commentId]);
            $liked = true;

            // XP и статистика автора комментария
            $authorStmt = $pdo->prepare("SELECT account_id FROM manga_comments WHERE id=?");
            $authorStmt->execute([$commentId]);
            $author = $authorStmt->fetch();
            if ($author && (int)$author['account_id'] !== $accountId) {
                addXP($pdo, (int)$author['account_id'], XP_LIKE, 'like_received');
                $pdo->prepare("INSERT INTO user_stats (account_id, comment_likes_received) VALUES (?,1)
                    ON CONFLICT (account_id) DO UPDATE SET comment_likes_received=user_stats.comment_likes_received+1")
                    ->execute([(int)$author['account_id']]);
                checkAchievements($pdo, (int)$author['account_id']);
            }
        }

        $likesStmt = $pdo->prepare("SELECT likes FROM manga_comments WHERE id=?");
        $likesStmt->execute([$commentId]);
        $likes = (int)$likesStmt->fetchColumn();

        return ['liked' => $liked, 'likes' => $likes];
    } catch (Exception $e) { return ['liked' => false, 'likes' => 0]; }
}

// ========================= СООБЩЕНИЯ =========================

function sendMessage(PDO $pdo, int $fromId, int $toId, string $text, ?string $imageUrl = null, ?int $replyToId = null): ?array {
    // Антиспам: 20 сообщений в минуту
    $spamStmt = $pdo->prepare("SELECT COUNT(*) FROM user_messages WHERE from_account_id=? AND created_at > NOW()-INTERVAL '1 minute'");
    $spamStmt->execute([$fromId]);
    if ((int)$spamStmt->fetchColumn() >= 20) return null;

    $text = htmlspecialchars(substr(trim($text), 0, 4000), ENT_QUOTES);
    if (!$text) return null;

    $stmt = $pdo->prepare("INSERT INTO user_messages (from_account_id, to_account_id, text, image_url, reply_to_id)
        VALUES (?,?,?,?,?) RETURNING id, created_at");
    $stmt->execute([$fromId, $toId, $text, $imageUrl, $replyToId]);
    $msg = $stmt->fetch();

    // Уведомление
    $fromStmt = $pdo->prepare("SELECT username FROM accounts WHERE id=?");
    $fromStmt->execute([$fromId]);
    $from = $fromStmt->fetch();
    if ($from) {
        sendInternalNotification($pdo, $toId, 'message', $fromId, (int)$msg['id'],
            "@{$from['username']}: " . mb_substr($text, 0, 80));
    }

    return ['id' => $msg['id'], 'from_account_id' => $fromId, 'to_account_id' => $toId, 'text' => $text, 'created_at' => $msg['created_at']];
}

// ========================= УВЕДОМЛЕНИЯ =========================

function sendInternalNotification(PDO $pdo, int $accountId, string $type, ?int $fromId, ?int $refId, string $text): void {
    try {
        $pdo->prepare("INSERT INTO user_notifications (account_id, type, from_account_id, reference_id, text)
            VALUES (?,?,?,?,?)")->execute([$accountId, $type, $fromId, $refId, $text]);
    } catch (Exception $e) {}
}

function getNotifications(PDO $pdo, int $accountId, bool $unreadOnly = false): array {
    try {
        $where = $unreadOnly ? 'AND un.is_read=FALSE' : '';
        $stmt = $pdo->prepare("SELECT un.*, a.username as from_username, pc.avatar_url as from_avatar
            FROM user_notifications un
            LEFT JOIN accounts a ON a.id=un.from_account_id
            LEFT JOIN profile_customizations pc ON pc.account_id=un.from_account_id
            WHERE un.account_id=? {$where}
            ORDER BY un.created_at DESC LIMIT 50");
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    } catch (Exception $e) { return []; }
}

// ========================= NSFW =========================

function canViewNSFW(PDO $pdo, ?array $currentAccount): bool {
    if (!$currentAccount) {
        return !empty($_COOKIE['nsfw_ok']) && $_COOKIE['nsfw_ok'] === '1';
    }
    return !empty($currentAccount['nsfw_confirmed']) && !empty($currentAccount['show_nsfw']);
}

// ========================= РЕКОМЕНДАЦИИ =========================

function getRecommendations(PDO $pdo, int $accountId, int $limit = 10): array {
    $showNsfw = $accountId > 0 && canViewNSFW($pdo, null);
    $nsfwWhere = $showNsfw ? '' : 'AND m.is_nsfw=FALSE';

    try {
        if ($accountId > 0) {
            // Рекомендации по жанрам прочитанной манги
            $stmt = $pdo->prepare("SELECT DISTINCT m.id, m.title, m.cover_imgbb_url, m.is_nsfw,
                COALESCE(AVG(mr.rating),0) as avg_rating
                FROM manga m
                LEFT JOIN manga_ratings mr ON mr.manga_id=m.id
                WHERE m.id NOT IN (
                    SELECT manga_id FROM user_manga_status WHERE account_id=?
                ) {$nsfwWhere}
                AND m.id IN (
                    SELECT DISTINCT mg2.manga_id FROM manga_genres mg2
                    WHERE mg2.genre_id IN (
                        SELECT DISTINCT mg.genre_id FROM manga_genres mg
                        WHERE mg.manga_id IN (
                            SELECT manga_id FROM user_manga_status WHERE account_id=? AND status='read'
                        )
                    )
                )
                GROUP BY m.id
                ORDER BY avg_rating DESC, m.id DESC
                LIMIT ?");
            $stmt->execute([$accountId, $accountId, $limit]);
            $recs = $stmt->fetchAll();
            if (count($recs) >= 5) return $recs;
        }

        // Fallback — популярные
        $stmt = $pdo->prepare("SELECT m.id, m.title, m.cover_imgbb_url, m.is_nsfw,
            COALESCE(AVG(mr.rating),0) as avg_rating, COUNT(mr.user_id) as rating_count
            FROM manga m LEFT JOIN manga_ratings mr ON mr.manga_id=m.id
            WHERE 1=1 {$nsfwWhere}
            GROUP BY m.id ORDER BY rating_count DESC, m.id DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) { return []; }
}

// ========================= ТОП НЕДЕЛИ =========================

function getWeeklyTop(PDO $pdo, string $type = 'pages', int $limit = 50): array {
    $orderMap = [
        'pages'    => 'ux.weekly_pages DESC',
        'chapters' => 'ux.weekly_chapters DESC',
        'level'    => 'ux.level DESC, ux.total_xp DESC',
        'comments' => 'us.total_comments DESC',
        'xp'       => 'ux.weekly_xp DESC',
    ];
    $order = $orderMap[$type] ?? 'ux.weekly_pages DESC';

    try {
        $stmt = $pdo->prepare("SELECT a.id, a.username,
            pc.avatar_url,
            COALESCE(ux.level, 1) as level,
            COALESCE(ux.total_xp, 0) as total_xp,
            COALESCE(ux.weekly_xp, 0) as weekly_xp,
            COALESCE(ux.weekly_pages, 0) as weekly_pages,
            COALESCE(ux.weekly_chapters, 0) as weekly_chapters,
            COALESCE(ux.weekly_comments, 0) as weekly_comments,
            COALESCE(us.total_comments, 0) as total_comments,
            uo.last_seen
            FROM accounts a
            LEFT JOIN profile_customizations pc ON pc.account_id=a.id
            LEFT JOIN user_xp ux ON ux.account_id=a.id
            LEFT JOIN user_stats us ON us.account_id=a.id
            LEFT JOIN user_online uo ON uo.account_id=a.id
            WHERE (ux.total_xp > 0 OR us.total_comments > 0)
            ORDER BY {$order}
            LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) { return []; }
}

// ========================= ПОПУЛЯРНЫЕ ТЕГИ =========================

function getPopularTags(PDO $pdo, int $limit = 20, bool $showNsfw = false): array {
    $nsfwWhere = $showNsfw ? '' : 'WHERE t.is_nsfw=FALSE';
    try {
        $stmt = $pdo->query("SELECT t.id, t.name, t.slug, t.is_nsfw,
            COUNT(mt.manga_id) as manga_count
            FROM tags t LEFT JOIN manga_tags mt ON mt.tag_id=t.id
            {$nsfwWhere}
            GROUP BY t.id ORDER BY manga_count DESC LIMIT {$limit}");
        return $stmt->fetchAll();
    } catch (Exception $e) { return []; }
}

// ========================= СБРОС НЕДЕЛЬНОЙ СТАТИСТИКИ (запускать по крону) =========================
// Добавить в cron: 0 0 * * 1 /usr/bin/php /path/to/reset_weekly.php
// Или вызвать вручную через: resetWeeklyStats($pdo);

function resetWeeklyStats(PDO $pdo): void {
    try {
        $pdo->exec("UPDATE user_xp SET weekly_xp=0, weekly_pages=0, weekly_chapters=0, weekly_comments=0, week_start=CURRENT_DATE");
    } catch (Exception $e) {}
}