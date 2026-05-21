<?php
// ============================================================
// new_api_endpoints.php — BLACKWATCH MANGA НОВЫЕ АПИШКИ
// Подключить в index.php ПОСЛЕ строки require_once __DIR__ . '/auth.php';
// require_once __DIR__ . '/functions.php';
// require_once __DIR__ . '/new_api_endpoints.php';
// ============================================================

// Создать новые таблицы (если не созданы)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_comments (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, account_id INT NOT NULL, parent_id INT DEFAULT NULL, text TEXT NOT NULL, likes INT DEFAULT 0, is_deleted BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comment_likes (account_id INT NOT NULL, comment_id INT NOT NULL, PRIMARY KEY (account_id, comment_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (account_id INT PRIMARY KEY, total_manga_read INT DEFAULT 0, total_chapters_read INT DEFAULT 0, total_pages_read INT DEFAULT 0, total_ratings INT DEFAULT 0, total_comments INT DEFAULT 0, comment_likes_received INT DEFAULT 0, reading_streak INT DEFAULT 0, last_read_date DATE DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_xp (account_id INT PRIMARY KEY, total_xp INT DEFAULT 0, level INT DEFAULT 1, weekly_xp INT DEFAULT 0, weekly_pages INT DEFAULT 0, weekly_chapters INT DEFAULT 0, weekly_comments INT DEFAULT 0, week_start DATE DEFAULT CURRENT_DATE, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS achievements (id SERIAL PRIMARY KEY, key VARCHAR(100) UNIQUE NOT NULL, name VARCHAR(200) NOT NULL, description TEXT NOT NULL, icon VARCHAR(10) DEFAULT '🏆', rarity VARCHAR(20) DEFAULT 'common', xp_reward INT DEFAULT 100, requirement_type VARCHAR(50) NOT NULL, requirement_value INT DEFAULT 1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_achievements (id SERIAL PRIMARY KEY, account_id INT NOT NULL, achievement_id INT NOT NULL, progress INT DEFAULT 0, unlocked_at TIMESTAMP DEFAULT NULL, UNIQUE(account_id, achievement_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS genres (id SERIAL PRIMARY KEY, name VARCHAR(100) UNIQUE NOT NULL, slug VARCHAR(100) UNIQUE NOT NULL, manga_count INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tags (id SERIAL PRIMARY KEY, name VARCHAR(100) UNIQUE NOT NULL, slug VARCHAR(100) UNIQUE NOT NULL, is_nsfw BOOLEAN DEFAULT FALSE, manga_count INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_genres (manga_id INT NOT NULL, genre_id INT NOT NULL, PRIMARY KEY (manga_id, genre_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_tags (manga_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (manga_id, tag_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_messages (id SERIAL PRIMARY KEY, from_account_id INT NOT NULL, to_account_id INT NOT NULL, text TEXT NOT NULL, image_url TEXT DEFAULT NULL, reply_to_id INT DEFAULT NULL, is_read BOOLEAN DEFAULT FALSE, is_deleted_by_sender BOOLEAN DEFAULT FALSE, is_deleted_by_receiver BOOLEAN DEFAULT FALSE, is_pinned BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_subscriptions (follower_id INT NOT NULL, following_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (follower_id, following_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_notifications (id SERIAL PRIMARY KEY, account_id INT NOT NULL, type VARCHAR(50) NOT NULL, from_account_id INT DEFAULT NULL, reference_id INT DEFAULT NULL, text TEXT NOT NULL, is_read BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_online (account_id INT PRIMARY KEY, last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP, is_hidden BOOLEAN DEFAULT FALSE)");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS is_nsfw BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS views INT DEFAULT 0");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS nsfw_confirmed BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS show_nsfw BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP DEFAULT NULL");
} catch (Exception $e) {}

// ========================= COMMENTS API =========================

// GET /api/manga/:id/comments
if (preg_match('#^/api/manga/(\d+)/comments$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $mangaId = (int)$m[1];
    $sort = $_GET['sort'] ?? 'new';
    $offset = (int)($_GET['offset'] ?? 0);
    $currentViewerId = $currentAccount ? (int)$currentAccount['id'] : 0;

    $orderBy = $sort === 'popular' ? 'mc.likes DESC, mc.created_at DESC' : 'mc.created_at DESC';
    try {
        $stmt = $pdo->prepare("SELECT mc.id, mc.text, mc.likes, mc.created_at, mc.parent_id, mc.account_id,
            a.username, pc.avatar_url,
            COALESCE(ux.level,1) as user_level,
            a.is_admin, a.admin_tag,
            EXISTS(SELECT 1 FROM comment_likes cl WHERE cl.comment_id=mc.id AND cl.account_id=?) as is_liked_by_me
            FROM manga_comments mc
            JOIN accounts a ON a.id=mc.account_id
            LEFT JOIN profile_customizations pc ON pc.account_id=mc.account_id
            LEFT JOIN user_xp ux ON ux.account_id=mc.account_id
            WHERE mc.manga_id=? AND mc.is_deleted=FALSE AND mc.parent_id IS NULL
            ORDER BY {$orderBy}
            LIMIT 30 OFFSET ?");
        $stmt->execute([$currentViewerId, $mangaId, $offset]);
        $comments = $stmt->fetchAll();

        foreach ($comments as &$c) {
            $rStmt = $pdo->prepare("SELECT mc.id,mc.text,mc.likes,mc.created_at,mc.account_id,
                a.username, pc.avatar_url,
                EXISTS(SELECT 1 FROM comment_likes cl WHERE cl.comment_id=mc.id AND cl.account_id=?) as is_liked_by_me
                FROM manga_comments mc
                JOIN accounts a ON a.id=mc.account_id
                LEFT JOIN profile_customizations pc ON pc.account_id=mc.account_id
                WHERE mc.parent_id=? AND mc.is_deleted=FALSE
                ORDER BY mc.created_at ASC LIMIT 5");
            $rStmt->execute([$currentViewerId, (int)$c['id']]);
            $c['replies'] = $rStmt->fetchAll();
            $c['is_liked_by_me'] = (bool)$c['is_liked_by_me'];
            $c['is_mine'] = $currentViewerId && (int)$c['account_id'] === $currentViewerId;
        }

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_comments WHERE manga_id=? AND is_deleted=FALSE AND parent_id IS NULL");
        $totalStmt->execute([$mangaId]);
        $total = (int)$totalStmt->fetchColumn();

        echo json_encode(['success'=>true,'comments'=>$comments,'total'=>$total]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// POST /api/comment — добавить комментарий
if ($path==='/api/comment' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'error'=>'Нужна авторизация']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $mangaId = (int)($input['manga_id'] ?? 0);
    $text = trim($input['text'] ?? '');
    $parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : null;
    if (!$mangaId || !$text) { echo json_encode(['success'=>false,'error'=>'Пустой комментарий']); exit; }
    if (strlen($text) > 2000) { echo json_encode(['success'=>false,'error'=>'Слишком длинный комментарий']); exit; }
    $accountId = (int)$currentAccount['id'];

    // Антиспам: 5 комментариев в минуту
    $spamStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_comments WHERE account_id=? AND created_at > NOW()-INTERVAL '1 minute'");
    $spamStmt->execute([$accountId]);
    if ((int)$spamStmt->fetchColumn() >= 5) { echo json_encode(['success'=>false,'error'=>'Слишком много комментариев, подожди немного']); exit; }

    try {
        $textSafe = htmlspecialchars($text, ENT_QUOTES);
        $stmt = $pdo->prepare("INSERT INTO manga_comments (manga_id, account_id, parent_id, text) VALUES (?,?,?,?) RETURNING id, created_at");
        $stmt->execute([$mangaId, $accountId, $parentId, $textSafe]);
        $comment = $stmt->fetch();

        $pdo->prepare("INSERT INTO user_stats (account_id, total_comments) VALUES (?,1) ON CONFLICT (account_id) DO UPDATE SET total_comments=user_stats.total_comments+1")->execute([$accountId]);
        addXP($pdo, $accountId, XP_COMMENT, 'comment');
        updateOnlineStatus($pdo, $accountId);

        if ($parentId) {
            $pStmt = $pdo->prepare("SELECT account_id FROM manga_comments WHERE id=?");
            $pStmt->execute([$parentId]);
            $parent = $pStmt->fetch();
            if ($parent && (int)$parent['account_id'] !== $accountId) {
                sendInternalNotification($pdo, (int)$parent['account_id'], 'comment_reply', $accountId, (int)$comment['id'],
                    "@{$currentAccount['username']} ответил на ваш комментарий");
            }
        }
        checkAchievements($pdo, $accountId);

        echo json_encode(['success'=>true, 'comment'=>[
            'id'         => $comment['id'],
            'text'       => $textSafe,
            'likes'      => 0,
            'created_at' => $comment['created_at'],
            'username'   => $currentAccount['username'],
            'user_level' => 1,
            'is_mine'    => true,
            'is_liked_by_me' => false,
            'replies'    => [],
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// POST /api/comment/:id/like
if (preg_match('#^/api/comment/(\d+)/like$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'error'=>'Нужна авторизация']); exit; }
    $commentId = (int)$m[1];
    $accountId = (int)$currentAccount['id'];
    $result = likeComment($pdo, $commentId, $accountId);
    echo json_encode(['success'=>true,'liked'=>$result['liked'],'likes'=>$result['likes']]);
    exit;
}

// POST /api/comment/:id/delete
if (preg_match('#^/api/comment/(\d+)/delete$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'error'=>'Нужна авторизация']); exit; }
    $commentId = (int)$m[1];
    $accountId = (int)$currentAccount['id'];
    $isAdminAcc = isAccountAdmin($pdo, $accountId);
    $chk = $pdo->prepare("SELECT account_id FROM manga_comments WHERE id=?");
    $chk->execute([$commentId]);
    $row = $chk->fetch();
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Не найдено']); exit; }
    if ((int)$row['account_id'] !== $accountId && !$isAdminAcc) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }
    $pdo->prepare("UPDATE manga_comments SET is_deleted=TRUE WHERE id=?")->execute([$commentId]);
    echo json_encode(['success'=>true]);
    exit;
}

// ========================= NOTIFICATIONS API =========================

// GET /api/notifications
if ($path==='/api/notifications' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'notifications'=>[]]); exit; }
    $accountId = (int)$currentAccount['id'];
    $unread = (int)($_GET['unread'] ?? 0);
    $notifications = getNotifications($pdo, $accountId, (bool)$unread);
    $unreadCount = (int)$pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE account_id=? AND is_read=FALSE")->execute([$accountId])
        ? 0 : 0;
    $ucStmt = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE account_id=? AND is_read=FALSE");
    $ucStmt->execute([$accountId]);
    $unreadCount = (int)$ucStmt->fetchColumn();
    echo json_encode(['success'=>true,'notifications'=>$notifications,'unread_count'=>$unreadCount]);
    exit;
}

// POST /api/notifications/read
if ($path==='/api/notifications/read' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false]); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $ids = $input['ids'] ?? [];
    $accountId = (int)$currentAccount['id'];
    if (empty($ids)) {
        $pdo->prepare("UPDATE user_notifications SET is_read=TRUE WHERE account_id=?")->execute([$accountId]);
    } else {
        $in = implode(',', array_map('intval', $ids));
        $pdo->prepare("UPDATE user_notifications SET is_read=TRUE WHERE account_id=? AND id IN ({$in})")->execute([$accountId]);
    }
    echo json_encode(['success'=>true]);
    exit;
}

// ========================= MESSAGES API =========================

// GET /api/messages — список чатов
if ($path==='/api/messages' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'chats'=>[]]); exit; }
    $accountId = (int)$currentAccount['id'];
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT ON (other_id)
            sub.other_id,
            a.username,
            pc.avatar_url,
            sub.last_msg,
            sub.last_time,
            sub.is_read,
            uo.last_seen,
            (SELECT COUNT(*) FROM user_messages um2 WHERE um2.from_account_id=sub.other_id AND um2.to_account_id=? AND um2.is_read=FALSE) as unread_count
            FROM (
                SELECT
                    CASE WHEN from_account_id=? THEN to_account_id ELSE from_account_id END as other_id,
                    text as last_msg,
                    created_at as last_time,
                    is_read
                FROM user_messages
                WHERE (from_account_id=? OR to_account_id=?) AND is_deleted_by_sender=FALSE AND is_deleted_by_receiver=FALSE
                ORDER BY created_at DESC
            ) sub
            JOIN accounts a ON a.id = sub.other_id
            LEFT JOIN profile_customizations pc ON pc.account_id = sub.other_id
            LEFT JOIN user_online uo ON uo.account_id = sub.other_id
            ORDER BY other_id, sub.last_time DESC");
        $stmt->execute([$accountId, $accountId, $accountId, $accountId]);
        $chats = $stmt->fetchAll();
        foreach ($chats as &$c) {
            $c['online_status'] = getOnlineStatus($c['last_seen'] ?? '');
        }
        echo json_encode(['success'=>true,'chats'=>$chats]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// GET /api/messages/:userId — сообщения с конкретным пользователем
if (preg_match('#^/api/messages/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'messages'=>[]]); exit; }
    $accountId = (int)$currentAccount['id'];
    $otherId = (int)$m[1];
    $offset = (int)($_GET['offset'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT um.*, a.username as from_username
            FROM user_messages um
            JOIN accounts a ON a.id=um.from_account_id
            WHERE ((from_account_id=? AND to_account_id=?) OR (from_account_id=? AND to_account_id=?))
            AND (CASE WHEN from_account_id=? THEN is_deleted_by_sender ELSE is_deleted_by_receiver END) = FALSE
            ORDER BY created_at DESC LIMIT 50 OFFSET ?");
        $stmt->execute([$accountId, $otherId, $otherId, $accountId, $accountId, $offset]);
        $messages = array_reverse($stmt->fetchAll());

        // Отметить как прочитанные
        $pdo->prepare("UPDATE user_messages SET is_read=TRUE WHERE from_account_id=? AND to_account_id=? AND is_read=FALSE")
            ->execute([$otherId, $accountId]);

        // Инфо о собеседнике
        $uStmt = $pdo->prepare("SELECT a.id, a.username, pc.avatar_url, uo.last_seen
            FROM accounts a
            LEFT JOIN profile_customizations pc ON pc.account_id=a.id
            LEFT JOIN user_online uo ON uo.account_id=a.id
            WHERE a.id=?");
        $uStmt->execute([$otherId]);
        $other = $uStmt->fetch();
        if ($other) $other['online_status'] = getOnlineStatus($other['last_seen'] ?? '');

        echo json_encode(['success'=>true,'messages'=>$messages,'other'=>$other]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// POST /api/message — отправить сообщение
if ($path==='/api/message' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'error'=>'Нужна авторизация']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $toId = (int)($input['to_id'] ?? 0);
    $text = trim($input['text'] ?? '');
    $replyToId = isset($input['reply_to_id']) ? (int)$input['reply_to_id'] : null;
    if (!$toId || !$text) { echo json_encode(['success'=>false,'error'=>'Пустое сообщение']); exit; }
    $accountId = (int)$currentAccount['id'];
    if ($toId === $accountId) { echo json_encode(['success'=>false,'error'=>'Нельзя писать себе']); exit; }
    $msg = sendMessage($pdo, $accountId, $toId, $text, null, $replyToId);
    if (!$msg) { echo json_encode(['success'=>false,'error'=>'Превышен лимит сообщений']); exit; }
    echo json_encode(['success'=>true,'message'=>$msg]);
    exit;
}

// POST /api/message/:id/delete — удалить сообщение
if (preg_match('#^/api/message/(\d+)/delete$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false]); exit; }
    $msgId = (int)$m[1];
    $accountId = (int)$currentAccount['id'];
    $msgStmt = $pdo->prepare("SELECT from_account_id, to_account_id FROM user_messages WHERE id=?");
    $msgStmt->execute([$msgId]);
    $msg = $msgStmt->fetch();
    if (!$msg) { echo json_encode(['success'=>false]); exit; }
    if ((int)$msg['from_account_id'] === $accountId) {
        $pdo->prepare("UPDATE user_messages SET is_deleted_by_sender=TRUE WHERE id=?")->execute([$msgId]);
    } else if ((int)$msg['to_account_id'] === $accountId) {
        $pdo->prepare("UPDATE user_messages SET is_deleted_by_receiver=TRUE WHERE id=?")->execute([$msgId]);
    }
    echo json_encode(['success'=>true]);
    exit;
}

// ========================= GENRES & TAGS API =========================

// GET /api/genres
if ($path==='/api/genres' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $genres = $pdo->query("SELECT id,name,slug FROM genres ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tagsRaw = $pdo->query("SELECT id,name,slug,is_nsfw FROM tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tags = array_map(function($t){
            $t['is_nsfw'] = ($t['is_nsfw']==='t' || $t['is_nsfw']===true || $t['is_nsfw']==='1' || $t['is_nsfw']===1);
            return $t;
        }, $tagsRaw);
        echo json_encode(['genres'=>$genres,'tags'=>$tags]);
    } catch (Exception $e) {
        echo json_encode(['genres'=>[],'tags'=>[],'error'=>$e->getMessage()]);
    }
    exit;
}

// GET /api/tags
if ($path==='/api/tags' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $showNsfw = canViewNSFW($pdo, $currentAccount);
    try {
        $stmt = $pdo->query("SELECT t.*, COUNT(mt.manga_id) as manga_count
            FROM tags t LEFT JOIN manga_tags mt ON mt.tag_id=t.id
            WHERE t.is_nsfw=FALSE OR " . ($showNsfw?'TRUE':'FALSE') . "
            GROUP BY t.id ORDER BY manga_count DESC LIMIT 100");
        echo json_encode(['success'=>true,'tags'=>$stmt->fetchAll()]);
    } catch (Exception $e) { echo json_encode(['success'=>true,'tags'=>[]]); }
    exit;
}

// POST /api/admin/manga/:id/genres — назначить жанры
if (preg_match('#^/api/admin/manga/(\d+)/genres$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount || !isAccountAdmin($pdo, (int)$currentAccount['id'])) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }
    $mangaId = (int)$m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $genreIds = array_map('intval', $input['genre_ids'] ?? []);
    $pdo->prepare("DELETE FROM manga_genres WHERE manga_id=?")->execute([$mangaId]);
    foreach ($genreIds as $gid) {
        try { $pdo->prepare("INSERT INTO manga_genres (manga_id, genre_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$mangaId, $gid]); } catch (Exception $e) {}
    }
    echo json_encode(['success'=>true]);
    exit;
}

// POST /api/admin/manga/:id/tags — назначить теги
if (preg_match('#^/api/admin/manga/(\d+)/tags$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount || !isAccountAdmin($pdo, (int)$currentAccount['id'])) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }
    $mangaId = (int)$m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $tagIds = array_map('intval', $input['tag_ids'] ?? []);
    $pdo->prepare("DELETE FROM manga_tags WHERE manga_id=?")->execute([$mangaId]);
    foreach ($tagIds as $tid) {
        try { $pdo->prepare("INSERT INTO manga_tags (manga_id, tag_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$mangaId, $tid]); } catch (Exception $e) {}
    }
    echo json_encode(['success'=>true]);
    exit;
}

// POST /api/admin/genres/add
if ($path==='/api/admin/genres/add' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount || !isAccountAdmin($pdo, (int)$currentAccount['id'])) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    if (!$name) { echo json_encode(['success'=>false,'error'=>'Пустое название']); exit; }
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/','-', transliterator_transliterate('Any-Latin; Latin-ASCII', $name)));
    try { $pdo->prepare("INSERT INTO genres (name, slug) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$name, $slug]); echo json_encode(['success'=>true]); }
    catch (Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// POST /api/admin/tags/add
if ($path==='/api/admin/tags/add' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount || !isAccountAdmin($pdo, (int)$currentAccount['id'])) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $isNsfw = !empty($input['is_nsfw']);
    if (!$name) { echo json_encode(['success'=>false,'error'=>'Пустое название']); exit; }
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/','-', $name));
    $isNsfwVal = $isNsfw ? 'true' : 'false';
    try { $pdo->prepare("INSERT INTO tags (name, slug, is_nsfw) VALUES (?,?,$isNsfwVal) ON CONFLICT DO NOTHING")->execute([$name, $slug]); echo json_encode(['success'=>true]); }
    catch (Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ========================= NSFW API =========================

// POST /api/nsfw-confirm
if ($path==='/api/nsfw-confirm' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $confirm = !empty($input['confirm']);
    $exp = time() + 86400 * 365;
    if ($confirm) {
        setcookie('nsfw_ok', '1', $exp, '/', '', false, false);
        if ($currentAccount) {
            $pdo->prepare("UPDATE accounts SET nsfw_confirmed=TRUE, show_nsfw=TRUE WHERE id=?")->execute([(int)$currentAccount['id']]);
        }
    } else {
        setcookie('nsfw_ok', '0', $exp, '/', '', false, false);
    }
    echo json_encode(['success'=>true,'confirmed'=>$confirm]);
    exit;
}

// POST /api/profile/nsfw-toggle
if ($path==='/api/profile/nsfw-toggle' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false]); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $show = !empty($input['show']);
    $pdo->prepare("UPDATE accounts SET show_nsfw=? WHERE id=?")->execute([$show, (int)$currentAccount['id']]);
    echo json_encode(['success'=>true,'show_nsfw'=>$show]);
    exit;
}

// ========================= XP & ACHIEVEMENTS API =========================

// GET /api/achievements
if ($path==='/api/achievements' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'achievements'=>[]]); exit; }
    $accountId = (int)$currentAccount['id'];
    try {
        $stmt = $pdo->prepare("SELECT a.*, ua.progress, ua.unlocked_at
            FROM achievements a
            LEFT JOIN user_achievements ua ON ua.achievement_id=a.id AND ua.account_id=?
            ORDER BY a.rarity DESC, a.xp_reward DESC");
        $stmt->execute([$accountId]);
        $achs = $stmt->fetchAll();
        foreach ($achs as &$a) {
            $a['pct'] = $a['requirement_value'] > 0
                ? min(100, round((int)$a['progress'] / (int)$a['requirement_value'] * 100))
                : 0;
            $a['is_unlocked'] = !empty($a['unlocked_at']);
        }
        echo json_encode(['success'=>true,'achievements'=>$achs]);
    } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// GET /api/my-xp
if ($path==='/api/my-xp' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false]); exit; }
    $accountId = (int)$currentAccount['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_xp WHERE account_id=?");
        $stmt->execute([$accountId]);
        $xp = $stmt->fetch();
        if (!$xp) { $xp = ['total_xp'=>0,'level'=>1,'weekly_xp'=>0]; }
        $progress = xpProgressInLevel((int)$xp['total_xp']);
        $frame = getLevelFrame((int)$xp['level']);
        echo json_encode(['success'=>true,'xp'=>$xp,'progress'=>$progress,'frame'=>$frame]);
    } catch (Exception $e) { echo json_encode(['success'=>false]); }
    exit;
}

// ========================= RANKINGS API =========================

// GET /api/rankings/weekly
if ($path==='/api/rankings/weekly' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? 'pages';
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $top = getWeeklyTop($pdo, $type, $limit);
    foreach ($top as $i => &$u) {
        $u['rank'] = $i + 1;
        $u['frame'] = getLevelFrame((int)$u['level']);
        $u['online_status'] = getOnlineStatus($u['last_seen'] ?? '');
        if ($i === 0) $u['badge'] = '🥇 Топ-1';
        elseif ($i === 1) $u['badge'] = '🥈 Топ-2';
        elseif ($i === 2) $u['badge'] = '🥉 Топ-3';
        elseif ($i < 10) $u['badge'] = '⭐ Топ-10';
        else $u['badge'] = '';
    }
    echo json_encode(['success'=>true,'rankings'=>$top,'type'=>$type]);
    exit;
}

// ========================= RECOMMENDATIONS API =========================

// GET /api/recommendations
if ($path==='/api/recommendations' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $accountId = $currentAccount ? (int)$currentAccount['id'] : 0;
    $limit = (int)($_GET['limit'] ?? 10);
    $recs = getRecommendations($pdo, $accountId, $limit);
    echo json_encode(['success'=>true,'recommendations'=>$recs]);
    exit;
}

// ========================= ONLINE STATUS API =========================

// POST /api/ping — обновить онлайн статус
if ($path==='/api/ping' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if ($currentAccount) {
        updateOnlineStatus($pdo, (int)$currentAccount['id']);
    }
    echo json_encode(['success'=>true]);
    exit;
}

// ========================= PROGRESS SYNC API =========================
// Перехватываем /api/progress POST чтобы добавить XP
// Это хук — добавляется логика поверх существующего обработчика
// (существующий обработчик в index.php уже есть, поэтому здесь отдельный endpoint для синхронизации)

// POST /api/sync-stats — принудительная синхронизация статистики
if ($path==='/api/sync-stats' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false]); exit; }
    $accountId = (int)$currentAccount['id'];
    syncUserStats($pdo, $accountId);
    checkAchievements($pdo, $accountId);
    echo json_encode(['success'=>true]);
    exit;
}

// POST /api/record-read — записать прочитанные страницы с XP
if ($path==='/api/record-read' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $accountId = $currentAccount ? (int)$currentAccount['id'] : 0;
    if (!$accountId) { echo json_encode(['success'=>false]); exit; }
    $pages = max(0, (int)($input['pages'] ?? 1));
    $isChapter = !empty($input['is_chapter']);
    recordPageRead($pdo, $accountId, $pages, $isChapter);
    echo json_encode(['success'=>true]);
    exit;
}

// ========================= MANGA SEARCH WITH FILTERS =========================

// GET /api/manga/search — поиск по жанрам и тегам
if ($path==='/api/manga/search' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $genreIds = array_filter(array_map('intval', explode(',', $_GET['genres'] ?? '')));
    $tagIds = array_filter(array_map('intval', explode(',', $_GET['tags'] ?? '')));
    $sort = $_GET['sort'] ?? 'new';
    $page = max(0, (int)($_GET['page'] ?? 0));
    $limit = 24;
    $offset = $page * $limit;
    $showNsfw = canViewNSFW($pdo, $currentAccount);

    $where = ['1=1'];
    $params = [];

    if (!$showNsfw) { $where[] = 'm.is_nsfw = FALSE'; }
    if ($q) { $where[] = 'm.title ILIKE ?'; $params[] = "%{$q}%"; }
    if (!empty($genreIds)) {
        $in = implode(',', $genreIds);
        $where[] = "EXISTS (SELECT 1 FROM manga_genres mg WHERE mg.manga_id=m.id AND mg.genre_id IN ({$in}))";
    }
    if (!empty($tagIds)) {
        $in = implode(',', $tagIds);
        $where[] = "EXISTS (SELECT 1 FROM manga_tags mt WHERE mt.manga_id=m.id AND mt.tag_id IN ({$in}))";
    }

    $whereStr = implode(' AND ', $where);
    $orderBy = match($sort) {
        'popular' => 'm.likes DESC, m.id DESC',
        'rating'  => 'avg_rating DESC, m.id DESC',
        default   => 'm.id DESC',
    };

    try {
        $stmt = $pdo->prepare("SELECT m.id, m.title, m.cover_imgbb_url, m.is_series, m.is_nsfw, m.likes,
            COALESCE(AVG(mr.rating),0) as avg_rating,
            COALESCE(COUNT(DISTINCT mr.user_id),0) as rating_count
            FROM manga m
            LEFT JOIN manga_ratings mr ON mr.manga_id=m.id
            WHERE {$whereStr}
            GROUP BY m.id
            ORDER BY {$orderBy}
            LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM manga m WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        foreach ($items as &$item) {
            $item['avg_rating'] = round((float)$item['avg_rating'], 1);
        }

        echo json_encode(['success'=>true,'items'=>$items,'total'=>$total,'page'=>$page]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ========================= USER PUBLIC PROFILE API =========================

// GET /api/user/:username — публичный профиль
if (preg_match('#^/api/user/([a-zA-Z0-9_]+)$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $targetUsername = $m[1];
    $profile = getUserProfile($pdo, $targetUsername);
    if (!$profile) { echo json_encode(['success'=>false,'error'=>'Пользователь не найден']); exit; }

    $uid = (int)$profile['user']['id'];
    $privacy = $profile['user']['profile_privacy'] ?? 'public';
    $canView = $privacy === 'public';
    $isSelf = $currentAccount && (int)$currentAccount['id'] === $uid;

    if ($currentAccount && !$isSelf && $privacy === 'friends') {
        $fStmt = $pdo->prepare("SELECT id FROM friendships WHERE ((requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)) AND status='accepted'");
        $fStmt->execute([(int)$currentAccount['id'], $uid, $uid, (int)$currentAccount['id']]);
        $canView = (bool)$fStmt->fetch();
    }
    if ($isSelf || isAccountAdmin($pdo, $currentAccount ? (int)$currentAccount['id'] : 0)) $canView = true;

    $data = [
        'id'            => $uid,
        'username'      => $profile['user']['username'],
        'created_at'    => $profile['user']['reg_date'] ?? $profile['user']['created_at'],
        'is_verified'   => !empty($profile['user']['is_verified']),
        'is_admin'      => !empty($profile['user']['is_admin']),
        'admin_tag'     => $profile['user']['admin_tag'],
        'avatar_url'    => $profile['user']['avatar_url'],
        'banner_url'    => $profile['user']['banner_url'],
        'banner_color'  => $profile['user']['banner_color'] ?? '#1a1a2e',
        'bio'           => $profile['user']['bio'],
        'xp'            => $profile['xp'],
        'frame'         => getLevelFrame($profile['xp']['level']),
        'online'        => $profile['online'],
        'achievements'  => array_slice($profile['achievements'], 0, 12),
        'comments'      => $profile['comments'],
        'can_view'      => $canView,
        'is_self'       => $isSelf,
        'privacy'       => $privacy,
    ];

    if ($canView) {
        $data['stats']     = $profile['user'];
        $data['lib_stats'] = $profile['lib_stats'];
    }

    // Friendship status
    if ($currentAccount && !$isSelf) {
        $fsStmt = $pdo->prepare("SELECT id, status, requester_id FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)");
        $fsStmt->execute([(int)$currentAccount['id'], $uid, $uid, (int)$currentAccount['id']]);
        $fs = $fsStmt->fetch();
        $data['friendship'] = $fs ? ['id'=>$fs['id'],'status'=>$fs['status'],'is_mine'=>(int)$fs['requester_id']===(int)$currentAccount['id']] : null;
    }

    echo json_encode(['success'=>true,'data'=>$data]);
    exit;
}

// ========================= MANGA DETAIL WITH TAGS & GENRES =========================

// GET /api/manga/:id/meta — получить жанры и теги манги
if (preg_match('#^/api/manga/(\d+)/meta$#', $path, $m) && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $mangaId = (int)$m[1];
    try {
        $genreStmt = $pdo->prepare("SELECT g.id, g.name, g.slug FROM manga_genres mg JOIN genres g ON g.id=mg.genre_id WHERE mg.manga_id=?");
        $genreStmt->execute([$mangaId]);
        $genres = $genreStmt->fetchAll();

        $tagStmt = $pdo->prepare("SELECT t.id, t.name, t.slug, t.is_nsfw FROM manga_tags mt JOIN tags t ON t.id=mt.tag_id WHERE mt.manga_id=?");
        $tagStmt->execute([$mangaId]);
        $tags = $tagStmt->fetchAll();

        $viewsStmt = $pdo->prepare("UPDATE manga SET views=views+1 WHERE id=? RETURNING views");
        $viewsStmt->execute([$mangaId]);
        $views = $viewsStmt->fetchColumn();

        echo json_encode(['success'=>true,'genres'=>$genres,'tags'=>$tags,'views'=>(int)$views]);
    } catch (Exception $e) { echo json_encode(['success'=>true,'genres'=>[],'tags'=>[],'views'=>0]); }
    exit;
}

// ========================= ADMIN: СПИСОК ВСЕХ АДМИНОВ =========================
if ($path==='/api/admin/list-all' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['error'=>'Не авторизован']); exit; }
    $superAdmins = [1710365896, 1181510470];
    $tgId = (int)($currentAccount['tg_user_id']??0);
    if (!in_array($tgId, $superAdmins) && !isAccountAdmin($pdo, (int)$currentAccount['id'])) {
        echo json_encode(['error'=>'Нет прав']); exit;
    }
    try {
        $stmt = $pdo->query("SELECT a.id, a.username, a.email, a.tg_user_id, a.admin_tag, a.is_admin FROM accounts WHERE is_admin=TRUE");
        $admins = $stmt->fetchAll();
        $result = [];
        foreach ($admins as $adm) {
            $result[] = [
                'tg_id'    => $adm['tg_user_id'],
                'username' => $adm['username'],
                'email'    => $adm['email'],
                'tag'      => $adm['admin_tag'],
                'is_super' => in_array((int)$adm['tg_user_id'], $superAdmins),
            ];
        }
        // Add hardcoded admins not in accounts
        foreach ($superAdmins as $sid) {
            $found = false;
            foreach ($result as $r) { if ((int)$r['tg_id'] === $sid) { $found = true; break; } }
            if (!$found) $result[] = ['tg_id'=>$sid,'username'=>null,'email'=>null,'tag'=>'Суперадмин','is_super'=>true];
        }
        echo json_encode(['admins'=>$result]);
    } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ========================= ПОДПИСКИ =========================

// POST /api/subscribe
if ($path==='/api/subscribe' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { echo json_encode(['success'=>false,'error'=>'Нужна авторизация']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $targetId = (int)($input['target_id'] ?? 0);
    $accountId = (int)$currentAccount['id'];
    if ($targetId === $accountId) { echo json_encode(['success'=>false]); exit; }
    try {
        $check = $pdo->prepare("SELECT 1 FROM user_subscriptions WHERE follower_id=? AND following_id=?");
        $check->execute([$accountId, $targetId]);
        if ($check->fetch()) {
            $pdo->prepare("DELETE FROM user_subscriptions WHERE follower_id=? AND following_id=?")->execute([$accountId, $targetId]);
            echo json_encode(['success'=>true,'subscribed'=>false]);
        } else {
            $pdo->prepare("INSERT INTO user_subscriptions (follower_id, following_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$accountId, $targetId]);
            sendInternalNotification($pdo, $targetId, 'new_follower', $accountId, null,
                "@{$currentAccount['username']} подписался на вас");
            echo json_encode(['success'=>true,'subscribed'=>true]);
        }
    } catch (Exception $e) { echo json_encode(['success'=>false]); }
    exit;
}

// ========================= /rankings PAGE =========================
if ($path==='/rankings') {
    $type = $_GET['type'] ?? 'pages';
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Топ читателей | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#7c5cff;--green:#4ade80;--orange:#fb923c}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 50% at 50% 0%,rgba(124,92,255,0.06) 0%,transparent 55%);pointer-events:none}
.back{display:inline-flex;align-items:center;gap:7px;color:var(--muted);text-decoration:none;font-size:13px;margin-bottom:20px;transition:color .2s}.back:hover{color:var(--text)}
.wrap{max-width:700px;margin:0 auto;position:relative;z-index:1}
h1{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;margin-bottom:6px}
.subtitle{color:var(--muted);font-size:13px;margin-bottom:24px}
.tab-nav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px}
.tab-btn{padding:8px 16px;border:1px solid var(--border);border-radius:20px;background:transparent;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;text-decoration:none;display:inline-block}
.tab-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:14px}
.rank-item{display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid var(--border);transition:all .18s;text-decoration:none;color:var(--text)}
.rank-item:last-child{border-bottom:none}
.rank-item:hover{background:rgba(255,255,255,0.02)}
.rank-num{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--muted);width:32px;text-align:center;flex-shrink:0}
.rank-1{color:#f59e0b}.rank-2{color:#94a3b8}.rank-3{color:#cd7f32}
.avatar-wrap{position:relative;flex-shrink:0}
.avatar{width:42px;height:42px;border-radius:50%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;font-size:18px;overflow:hidden;border:2px solid var(--border)}
.avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.online-dot{position:absolute;bottom:1px;right:1px;width:10px;height:10px;border-radius:50%;background:#4ade80;border:2px solid var(--card)}
.rank-info{flex:1;min-width:0}
.rank-name{font-weight:700;font-size:14px;color:var(--text2);margin-bottom:2px}
.rank-meta{font-size:11px;color:var(--muted)}
.rank-value{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--accent);flex-shrink:0;text-align:right}
.rank-badge{font-size:11px;color:var(--orange);font-weight:700;margin-top:1px}
.level-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:rgba(124,92,255,0.15);color:#a78bfa;margin-left:6px}
.loading{padding:40px;text-align:center;color:var(--muted);font-size:14px}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;border:1px solid var(--border2);animation:ti .25s ease}
@keyframes ti{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
</style></head><body>
<div class="wrap">
<a href="/" class="back">← На главную</a>
<h1>🏆 Топ читателей</h1>
<p class="subtitle">Обновляется каждую неделю</p>
<div class="tab-nav">
<a href="/rankings?type=pages" class="tab-btn <?=$type==='pages'?'active':''?>">📄 По страницам</a>
<a href="/rankings?type=chapters" class="tab-btn <?=$type==='chapters'?'active':''?>">📚 По главам</a>
<a href="/rankings?type=level" class="tab-btn <?=$type==='level'?'active':''?>">⭐ По уровню</a>
<a href="/rankings?type=comments" class="tab-btn <?=$type==='comments'?'active':''?>">💬 По комментариям</a>
</div>
<div class="card" id="rankings-list"><div class="loading">⏳ Загружаем рейтинг...</div></div>
</div>
<script>
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
const type=<?=json_encode($type)?>;
const labels={pages:'страниц за неделю',chapters:'глав за неделю',level:'уровень',comments:'комментариев'};
const valKeys={pages:'weekly_pages',chapters:'weekly_chapters',level:'level',comments:'total_comments'};
async function load(){
    try{const res=await fetch('/api/rankings/weekly?type='+type+'&limit=50');const d=await res.json();
    const list=document.getElementById('rankings-list');
    if(!d.rankings?.length){list.innerHTML='<div class="loading">Нет данных</div>';return;}
    list.innerHTML=d.rankings.map((u,i)=>{
        const av=u.avatar_url?`<img src="${escapeHtml(u.avatar_url)}" alt="">`:'👤';
        const onlineDot=u.online_status==='online'?'<div class="online-dot"></div>':'';
        const valKey=valKeys[type]||'weekly_pages';
        const val=parseInt(u[valKey]||0);
        const badge=u.badge?`<div class="rank-badge">${escapeHtml(u.badge)}</div>`:'';
        const lf=u.frame||{color:'#6b7280'};
        return `<a class="rank-item" href="/u/${escapeHtml(u.username)}">
            <div class="rank-num rank-${i+1}">${i+1}</div>
            <div class="avatar-wrap"><div class="avatar" style="border-color:${lf.color}">${av}</div>${onlineDot}</div>
            <div class="rank-info">
                <div class="rank-name">${escapeHtml(u.username)}<span class="level-badge" style="background:${lf.color}22;color:${lf.color}">Lv${u.level}</span></div>
                <div class="rank-meta">${labels[type]||''}${badge}</div>
            </div>
            <div class="rank-value">${val.toLocaleString('ru-RU')}</div>
        </a>`;
    }).join('');}catch(e){document.getElementById('rankings-list').innerHTML='<div class="loading">❌ Ошибка</div>';}
}
load();
setInterval(load,60000);
</script></body></html><?php exit; }

// ========================= /messages PAGE =========================
if ($path==='/messages') {
    if (!$currentAccount) { header('Location: /login?redirect=/messages'); exit; }
    $accountId = (int)$currentAccount['id'];
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Сообщения | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#7c5cff;--green:#4ade80}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;height:100vh;display:flex;flex-direction:column}
.header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.back-btn{color:var(--muted);text-decoration:none;font-size:13px;flex-shrink:0}
.header-title{font-family:'Syne',sans-serif;font-weight:800;font-size:17px}
.msgs-layout{display:flex;flex:1;overflow:hidden}
.chats-panel{width:280px;border-right:1px solid var(--border);overflow-y:auto;flex-shrink:0}
.chat-item{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;border-bottom:1px solid var(--border);transition:all .15s}
.chat-item:hover,.chat-item.active{background:rgba(124,92,255,0.07)}
.chat-avatar{width:38px;height:38px;border-radius:50%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;overflow:hidden;position:relative}
.chat-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.chat-dot{position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;background:#4ade80;border:2px solid var(--card)}
.chat-info{flex:1;min-width:0}
.chat-name{font-size:13px;font-weight:600;color:var(--text2);margin-bottom:2px}
.chat-last{font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chat-unread{min-width:18px;height:18px;border-radius:9px;background:var(--accent);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0}
.msg-panel{flex:1;display:flex;flex-direction:column;overflow:hidden}
.msg-panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.msg-list{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px}
.msg-bubble{max-width:70%;padding:9px 13px;border-radius:14px;font-size:13px;line-height:1.5;word-break:break-word}
.msg-mine{background:var(--accent);color:#fff;align-self:flex-end;border-bottom-right-radius:4px}
.msg-other{background:var(--card);border:1px solid var(--border);align-self:flex-start;border-bottom-left-radius:4px}
.msg-time{font-size:10px;opacity:0.6;margin-top:3px}
.msg-input-row{padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;flex-shrink:0}
.msg-input{flex:1;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:20px;color:var(--text);font-size:14px;padding:9px 16px;font-family:inherit;outline:none;resize:none;max-height:120px}
.msg-input:focus{border-color:var(--border2)}
.send-btn{width:40px;height:40px;border-radius:50%;background:var(--accent);border:none;color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .18s}
.send-btn:hover{opacity:.85}
.empty-chat{flex:1;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;text-align:center}
.chats-empty{padding:30px 16px;text-align:center;color:var(--muted);font-size:12px}
@media(max-width:600px){.chats-panel{display:none}.msgs-layout{flex-direction:column}}
</style></head><body>
<div class="header">
<a href="/" class="back-btn">← Назад</a>
<div class="header-title">💬 Сообщения</div>
</div>
<div class="msgs-layout">
<div class="chats-panel" id="chats-panel">
<div class="chats-empty" id="chats-empty" style="display:none">Нет чатов</div>
<div id="chats-list"></div>
</div>
<div class="msg-panel">
<div class="msg-panel-header" id="msg-header" style="display:none">
<div id="chat-avatar" style="width:32px;height:32px;border-radius:50%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;font-size:14px;overflow:hidden"></div>
<div><div id="chat-with-name" style="font-weight:700;font-size:13px"></div><div id="chat-status" style="font-size:11px;color:var(--muted)"></div></div>
</div>
<div class="msg-list" id="msg-list" style="display:none"></div>
<div class="empty-chat" id="empty-chat"><div>Выбери чат слева<br>или начни новый разговор</div></div>
<div class="msg-input-row" id="msg-input-row" style="display:none">
<textarea class="msg-input" id="msg-input" placeholder="Написать сообщение..." rows="1"></textarea>
<button class="send-btn" onclick="sendMsg()">➤</button>
</div>
</div>
</div>
<script>
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
let currentChatId=null;
let pollInterval=null;
const myId=<?=$accountId?>;

async function loadChats(){
    const res=await fetch('/api/messages');const d=await res.json();
    const list=document.getElementById('chats-list');
    if(!d.chats?.length){document.getElementById('chats-empty').style.display='block';list.innerHTML='';return;}
    document.getElementById('chats-empty').style.display='none';
    list.innerHTML=d.chats.map(c=>{
        const av=c.avatar_url?`<img src="${escapeHtml(c.avatar_url)}" alt="">`:'👤';
        const dot=c.online_status==='online'?'<div class="chat-dot"></div>':'';
        const unread=c.unread_count>0?`<div class="chat-unread">${c.unread_count}</div>`:'';
        return `<div class="chat-item${currentChatId==c.other_id?' active':''}" onclick="openChat(${c.other_id},'${escapeHtml(c.username)}','${escapeHtml(c.avatar_url||'')}',${c.online_status==='online'?1:0})">
            <div class="chat-avatar">${av}${dot}</div>
            <div class="chat-info">
                <div class="chat-name">${escapeHtml(c.username)}</div>
                <div class="chat-last">${escapeHtml((c.last_msg||'').substring(0,40))}</div>
            </div>${unread}
        </div>`;
    }).join('');
}

async function openChat(uid,username,avatarUrl,isOnline){
    currentChatId=uid;
    document.getElementById('msg-header').style.display='flex';
    document.getElementById('msg-list').style.display='flex';
    document.getElementById('empty-chat').style.display='none';
    document.getElementById('msg-input-row').style.display='flex';
    document.getElementById('chat-with-name').textContent=username;
    document.getElementById('chat-status').textContent=isOnline?'🟢 Онлайн':'';
    document.getElementById('chat-avatar').innerHTML=avatarUrl?`<img src="${escapeHtml(avatarUrl)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`:'👤';
    if(pollInterval) clearInterval(pollInterval);
    await loadMessages();
    pollInterval=setInterval(loadMessages,3000);
    loadChats();
}

async function loadMessages(){
    if(!currentChatId) return;
    const res=await fetch('/api/messages/'+currentChatId);const d=await res.json();
    const list=document.getElementById('msg-list');
    if(!d.messages) return;
    const wasAtBottom=list.scrollHeight-list.scrollTop-list.clientHeight<50;
    list.innerHTML=d.messages.map(m=>{
        const isMine=m.from_account_id==myId;
        const time=new Date(m.created_at).toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'});
        return `<div style="display:flex;flex-direction:column;align-items:${isMine?'flex-end':'flex-start'}">
            <div class="msg-bubble ${isMine?'msg-mine':'msg-other'}">${escapeHtml(m.text)}<div class="msg-time">${time}${isMine&&m.is_read?' ✓✓':''}</div></div>
        </div>`;
    }).join('');
    if(wasAtBottom) list.scrollTop=list.scrollHeight;
}

async function sendMsg(){
    const input=document.getElementById('msg-input');const text=input.value.trim();
    if(!text||!currentChatId) return;
    input.value='';input.style.height='';
    await fetch('/api/message',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({to_id:currentChatId,text})});
    await loadMessages();loadChats();
}

document.getElementById('msg-input').addEventListener('keydown',e=>{
    if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg();}
});
document.getElementById('msg-input').addEventListener('input',function(){
    this.style.height='';this.style.height=Math.min(this.scrollHeight,120)+'px';
});

loadChats();
setInterval(loadChats,10000);
// Ping online
setInterval(()=>fetch('/api/ping',{method:'POST'}),60000);
</script></body></html><?php exit; }