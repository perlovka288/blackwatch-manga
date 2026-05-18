<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME'));
try {
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { die("DB Error: " . $e->getMessage()); }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga (id SERIAL PRIMARY KEY, title TEXT NOT NULL, file_id TEXT, description TEXT, likes INT DEFAULT 0, dislikes INT DEFAULT 0, added_by BIGINT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, cover_imgbb_url TEXT, telegraph_url TEXT, is_series BOOLEAN DEFAULT FALSE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_pages (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapters (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, chapter_num FLOAT NOT NULL DEFAULT 1, title TEXT, telegraph_url TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapter_pages (id SERIAL PRIMARY KEY, chapter_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (user_id BIGINT NOT NULL, manga_id INT NOT NULL, vote_type VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (user_id BIGINT NOT NULL, manga_id INT NOT NULL, status VARCHAR(50) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_progress (user_id BIGINT NOT NULL, manga_id INT NOT NULL, page_num INT DEFAULT 1, total_pages INT DEFAULT 0, chapter_id INT DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_admins (user_id BIGINT PRIMARY KEY)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archive (id SERIAL PRIMARY KEY, action_type VARCHAR(50) NOT NULL, action_text TEXT NOT NULL, action_by BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (user_id BIGINT PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (id SERIAL PRIMARY KEY, email TEXT NOT NULL UNIQUE, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, tg_user_id BIGINT DEFAULT NULL, tg_link_token TEXT DEFAULT NULL, is_verified BOOLEAN DEFAULT FALSE, last_login TIMESTAMP DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (id TEXT PRIMARY KEY, account_id INT NOT NULL, ip TEXT, user_agent TEXT, expires_at TIMESTAMP NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS suggestions (id SERIAL PRIMARY KEY, user_id BIGINT NOT NULL, text TEXT NOT NULL, status VARCHAR(20) DEFAULT 'new', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tags (user_id BIGINT PRIMARY KEY, tag_name VARCHAR(100) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_custom_statuses (id SERIAL PRIMARY KEY, user_id BIGINT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(20) NOT NULL DEFAULT '#7c5cff', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_ratings (user_id BIGINT NOT NULL, manga_id INT NOT NULL, rating INT NOT NULL CHECK(rating BETWEEN 1 AND 10), PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_messages (id SERIAL PRIMARY KEY, text TEXT NOT NULL, sent_by BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, is_deleted BOOLEAN DEFAULT FALSE)");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS is_series BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE manga_pages ADD COLUMN IF NOT EXISTS page_url TEXT");
    $pdo->exec("ALTER TABLE reading_progress ADD COLUMN IF NOT EXISTS chapter_id INT DEFAULT NULL");
    // New tables for friends, profile customization, email verification
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (id SERIAL PRIMARY KEY, email TEXT NOT NULL, code VARCHAR(6) NOT NULL, expires_at TIMESTAMP NOT NULL, used BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_customizations (account_id INT PRIMARY KEY, avatar_url TEXT DEFAULT NULL, banner_url TEXT DEFAULT NULL, banner_color VARCHAR(20) DEFAULT '#1a1a2e', bio TEXT DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS friendships (id SERIAL PRIMARY KEY, requester_id INT NOT NULL, addressee_id INT NOT NULL, status VARCHAR(20) DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(requester_id, addressee_id))");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS is_verified BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS verify_code VARCHAR(6) DEFAULT NULL");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS verify_expires TIMESTAMP DEFAULT NULL");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS profile_privacy VARCHAR(20) DEFAULT 'public'");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS is_admin BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS admin_tag VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE user_manga_status ADD COLUMN IF NOT EXISTS account_id INT DEFAULT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ums_account_id ON user_manga_status(account_id)");

    
    // ===== НОВЫЕ ТАБЛИЦЫ ДЛЯ КОММЕНТАРИЕВ, СООБЩЕНИЙ И СТАТИСТИКИ =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_comments (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, account_id INT NOT NULL, text TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_comments_manga ON manga_comments(manga_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_comments_account ON manga_comments(account_id)");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_messages (id SERIAL PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL, text TEXT NOT NULL, is_read BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_recipient ON user_messages(recipient_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_sender ON user_messages(sender_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_pair ON user_messages(sender_id, recipient_id)");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_weekly_stats (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, week_start TIMESTAMP NOT NULL, views INT DEFAULT 0, likes INT DEFAULT 0, comments INT DEFAULT 0, score INT DEFAULT 0, UNIQUE(manga_id, week_start))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_weekly_stats ON manga_weekly_stats(week_start, score)");
    
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS user_xp INT DEFAULT 0");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS user_level INT DEFAULT 1");
    // Tags & genres tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS tags (id SERIAL PRIMARY KEY, name TEXT NOT NULL UNIQUE, slug VARCHAR(100) NOT NULL UNIQUE, is_nsfw BOOLEAN DEFAULT FALSE, manga_count INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS genres (id SERIAL PRIMARY KEY, name TEXT NOT NULL UNIQUE, slug VARCHAR(100) NOT NULL UNIQUE, manga_count INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_tags (manga_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (manga_id, tag_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_genres (manga_id INT NOT NULL, genre_id INT NOT NULL, PRIMARY KEY (manga_id, genre_id))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_tags_manga ON manga_tags(manga_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_genres_manga ON manga_genres(manga_id)");
    // Seed tags from JSON if table is empty
    try {
        $tagCount = (int)$pdo->query("SELECT COUNT(*) FROM tags")->fetchColumn();
        if ($tagCount === 0) {
            $tagsJson = '[{"id":1,"name":"Реинкарнация","slug":"reincarnation","is_nsfw":false},{"id":2,"name":"Перерождение","slug":"rebirth","is_nsfw":false},{"id":3,"name":"Система","slug":"system","is_nsfw":false},{"id":4,"name":"Подземелья","slug":"dungeons","is_nsfw":false},{"id":5,"name":"Некромант","slug":"necromancer","is_nsfw":false},{"id":6,"name":"Культивация","slug":"cultivation","is_nsfw":false},{"id":7,"name":"Монстродевушки","slug":"monster-girls","is_nsfw":false},{"id":8,"name":"Цундере","slug":"tsundere","is_nsfw":false},{"id":9,"name":"Яндере","slug":"yandere","is_nsfw":false},{"id":10,"name":"Путешествие во времени","slug":"time-travel","is_nsfw":false},{"id":11,"name":"Боги","slug":"gods","is_nsfw":false},{"id":12,"name":"Зомби","slug":"zombies","is_nsfw":false},{"id":13,"name":"Школьная жизнь","slug":"school-life","is_nsfw":false},{"id":14,"name":"Ассасины","slug":"assassins","is_nsfw":false},{"id":15,"name":"Мафия","slug":"mafia","is_nsfw":false},{"id":16,"name":"Виртуальная реальность","slug":"vr","is_nsfw":false},{"id":17,"name":"Игровой мир","slug":"game-world","is_nsfw":false},{"id":18,"name":"Постапокалипсис","slug":"apocalypse","is_nsfw":false},{"id":19,"name":"Игра на выживание","slug":"survival-game","is_nsfw":false},{"id":20,"name":"Кулинария","slug":"cooking","is_nsfw":false},{"id":21,"name":"Драконы","slug":"dragons","is_nsfw":false},{"id":22,"name":"Зверолюди","slug":"beast-people","is_nsfw":false},{"id":23,"name":"Эльфы","slug":"elves","is_nsfw":false},{"id":24,"name":"Тёмное фэнтези","slug":"dark-fantasy","is_nsfw":false},{"id":25,"name":"Герой","slug":"hero","is_nsfw":false},{"id":26,"name":"Злодейка","slug":"villainess","is_nsfw":false},{"id":27,"name":"Строительство королевства","slug":"kingdom-building","is_nsfw":false},{"id":28,"name":"Регрессия","slug":"regression","is_nsfw":false},{"id":29,"name":"Охотники","slug":"hunters","is_nsfw":false},{"id":30,"name":"Гениальный ГГ","slug":"genius-mc","is_nsfw":false},{"id":31,"name":"Антигерой","slug":"antihero","is_nsfw":false},{"id":32,"name":"Ниндзя","slug":"ninja","is_nsfw":false},{"id":33,"name":"Пираты","slug":"pirates","is_nsfw":false},{"id":34,"name":"Космос","slug":"space","is_nsfw":false},{"id":35,"name":"Месть","slug":"revenge","is_nsfw":false},{"id":36,"name":"Турнир","slug":"tournament","is_nsfw":false},{"id":37,"name":"Сильный ГГ","slug":"op-mc","is_nsfw":false},{"id":38,"name":"Слабый в Сильный","slug":"weak-to-strong","is_nsfw":false},{"id":39,"name":"Магическая академия","slug":"magic-academy","is_nsfw":false},{"id":40,"name":"РПГ","slug":"rpg","is_nsfw":false},{"id":41,"name":"MMORPG","slug":"mmorpg","is_nsfw":false},{"id":42,"name":"Гильдии","slug":"guilds","is_nsfw":false},{"id":43,"name":"Любовный треугольник","slug":"love-triangle","is_nsfw":false},{"id":44,"name":"Холодный ГГ","slug":"cold-mc","is_nsfw":false},{"id":45,"name":"Легендарное оружие","slug":"legendary-weapon","is_nsfw":false},{"id":46,"name":"Проклятия","slug":"curses","is_nsfw":false},{"id":47,"name":"Короли","slug":"kings","is_nsfw":false},{"id":48,"name":"Академия","slug":"academy","is_nsfw":false},{"id":49,"name":"Гендер-бендер","slug":"gender-bender","is_nsfw":false},{"id":50,"name":"Суперсилы","slug":"superpowers","is_nsfw":false},{"id":51,"name":"Телепортация","slug":"teleportation","is_nsfw":false},{"id":52,"name":"Взрослый контент","slug":"adult","is_nsfw":true},{"id":53,"name":"18+","slug":"18plus","is_nsfw":true},{"id":54,"name":"NSFW","slug":"nsfw","is_nsfw":true}]';
            $tags = json_decode($tagsJson, true);
            $tagInsert = $pdo->prepare("INSERT INTO tags (name, slug, is_nsfw) VALUES (?, ?, ?) ON CONFLICT DO NOTHING");
            foreach ($tags as $t) { $tagInsert->execute([$t['name'], $t['slug'], $t['is_nsfw'] ? 1 : 0]); }
        }
    } catch(Exception $e) {}
    // Seed genres if empty
    try {
        $genreCount = (int)$pdo->query("SELECT COUNT(*) FROM genres")->fetchColumn();
        if ($genreCount === 0) {
            $defaultGenres = [
                ['Экшен','action'],['Романтика','romance'],['Фэнтези','fantasy'],['Комедия','comedy'],
                ['Драма','drama'],['Ужасы','horror'],['Мистика','mystery'],['Приключения','adventure'],
                ['Боевые искусства','martial-arts'],['Психология','psychology'],['Сёнен','shounen'],
                ['Сёдзё','shoujo'],['Сейнен','seinen'],['Иссекай','isekai'],['Спорт','sports'],
            ];
            $gInsert = $pdo->prepare("INSERT INTO genres (name, slug) VALUES (?, ?) ON CONFLICT DO NOTHING");
            foreach ($defaultGenres as $g) { $gInsert->execute($g); }
        }
    } catch(Exception $e) {}
} catch (Exception $e) {}

// Стартуем сессию СРАЗУ — до любых функций авторизации
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Отдаём style.css
if ($path === '/style.css') {
    header('Content-Type: text/css');
    header('Cache-Control: public, max-age=86400');
    readfile(__DIR__ . '/style.css');
    exit;
}

// ===== DEBUG: проверка таблиц тегов/жанров =====
if ($path === '/api/debug-tags') {
    header('Content-Type: application/json');
    try {
        $tagCount = (int)$pdo->query("SELECT COUNT(*) FROM tags")->fetchColumn();
        $genreCount = (int)$pdo->query("SELECT COUNT(*) FROM genres")->fetchColumn();
        $tags = $pdo->query("SELECT * FROM tags ORDER BY id LIMIT 5")->fetchAll();
        $genres = $pdo->query("SELECT * FROM genres ORDER BY id LIMIT 5")->fetchAll();
        // Попробуем вставить если пусто
        $seeded = false;
        if ($tagCount === 0) {
            $pdo->exec("INSERT INTO tags (name, slug, is_nsfw) VALUES ('Реинкарнация','reincarnation',false),('Система','system',false),('Экшен','action',false) ON CONFLICT DO NOTHING");
            $seeded = true;
        }
        if ($genreCount === 0) {
            $pdo->exec("INSERT INTO genres (name, slug) VALUES ('Экшен','action'),('Романтика','romance'),('Фэнтези','fantasy') ON CONFLICT DO NOTHING");
            $seeded = true;
        }
        echo json_encode([
            'tag_count' => $tagCount,
            'genre_count' => $genreCount,
            'tags_sample' => $tags,
            'genres_sample' => $genres,
            'auto_seeded' => $seeded,
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

require_once __DIR__ . '/auth.php';
$currentAccount = getCurrentAccount($pdo); // null если не залогинен

// ===== DEBUG SESSION ENDPOINT =====
if ($path === '/api/debug-session') {
    header('Content-Type: application/json');
    $sid = $_COOKIE['bw_session'] ?? '';
    $account = getCurrentAccount($pdo);
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $sc = 0; $sessionRow = null; $dbNow = null; $joinTest = null;
    try {
        $sc = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()")->fetchColumn();
        if ($sid) {
            $sStmt = $pdo->prepare("SELECT s.id, s.account_id, s.expires_at, s.created_at, (s.expires_at > NOW()) as is_valid FROM sessions s WHERE s.id = ?");
            $sStmt->execute([$sid]);
            $sessionRow = $sStmt->fetch();
        }
        $dbNow = $pdo->query("SELECT NOW() as now")->fetchColumn();
        // Test JOIN directly
        if ($sid) {
            try {
                $jStmt = $pdo->prepare("SELECT a.id, a.username FROM sessions s JOIN accounts a ON a.id = s.account_id WHERE s.id = ? AND s.expires_at > NOW()");
                $jStmt->execute([$sid]);
                $jRow = $jStmt->fetch();
                $joinTest = $jRow ? ['ok' => true, 'username' => $jRow['username']] : 'JOIN_EMPTY';
            } catch(Exception $je) { $joinTest = 'JOIN_ERROR: '.$je->getMessage(); }
        }
    } catch(Exception $e) {}
    echo json_encode([
        'cookie' => $sid ? substr($sid,0,8).'...' : 'NONE',
        'cookie_len' => strlen($sid),
        'account' => $account ? $account['username'] : null,
        'is_secure' => $isSecure,
        'https' => $_SERVER['HTTPS'] ?? 'not set',
        'forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'not set',
        'active_sessions_db' => $sc,
        'cookies_present' => array_keys($_COOKIE),
        'this_session_in_db' => $sessionRow ? ['found'=>true,'account_id'=>$sessionRow['account_id'],'expires_at'=>$sessionRow['expires_at'],'is_valid'=>$sessionRow['is_valid']] : ['found'=>false],
        'db_now' => $dbNow,
        'join_test' => $joinTest,
    ], JSON_PRETTY_PRINT);
    exit;
}


// ===== НОВЫЕ МОДУЛИ =====
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/profile_page.php';
require_once __DIR__ . '/new_api_endpoints.php';

$hardcodedAdmins = [1710365896, 1181510470];
try {
    $stmtAdmins = $pdo->query("SELECT user_id FROM bot_admins");
    foreach ($stmtAdmins as $row) { if (!in_array((int)$row['user_id'], $hardcodedAdmins)) $hardcodedAdmins[] = (int)$row['user_id']; }
} catch (Exception $e) {}
// Add TG IDs of account-based admins
try {
    $accAdmStmt = $pdo->query("SELECT tg_user_id FROM accounts WHERE is_admin=TRUE AND tg_user_id IS NOT NULL");
    foreach ($accAdmStmt as $row) { if (!in_array((int)$row['tg_user_id'], $hardcodedAdmins)) $hardcodedAdmins[] = (int)$row['tg_user_id']; }
} catch (Exception $e) {}

function isAdmin($userId, $admins) { return $userId > 0 && in_array((int)$userId, $admins); }

function isAdminFull($pdo, $userId, $admins) {
    if (isAdmin($userId, $admins)) return true;
    // Check account-based admin flag
    try {
        $s = $pdo->prepare("SELECT is_admin FROM accounts WHERE tg_user_id=? AND is_admin=TRUE");
        $s->execute([$userId]); if ($s->fetch()) return true;
    } catch(Exception $e) {}
    return false;
}


// ===== РАСЧЕТ УРОВНЯ ПОЛЬЗОВАТЕЛЯ =====
function calculateUserLevel(&$pdo, $account_id) {
    try {
        $stmt = $pdo->prepare("SELECT user_xp FROM accounts WHERE id = ?");
        $stmt->execute([$account_id]);
        $user = $stmt->fetch();
        if (!$user) return;
        $xp = $user['user_xp'];
        $level = 1;
        $xp_needed = 0;
        while ($xp_needed + (100 + ($level - 1) * 50) <= $xp) {
            $xp_needed += 100 + ($level - 1) * 50;
            $level++;
        }
        $pdo->prepare("UPDATE accounts SET user_level = ? WHERE id = ?")->execute([$level, $account_id]);
    } catch (Exception $e) {}
}

function isAccountAdmin($pdo, $accountId) {
    try {
        $s = $pdo->prepare("SELECT is_admin FROM accounts WHERE id=? AND is_admin=TRUE");
        $s->execute([$accountId]); return (bool)$s->fetch();
    } catch(Exception $e) { return false; }
}

/**
 * Универсальная проверка прав админа — проверяет ВСЕ способы авторизации:
 * 1) Веб-аккаунт с is_admin=TRUE
 * 2) TG ID веб-аккаунта в hardcodedAdmins
 * 3) TG ID из куки/GET-параметра в hardcodedAdmins
 */
function isAdminCombined(PDO $pdo, array $hardcodedAdmins): bool {
    // 1. Веб-сессия (аккаунт)
    $acc = getCurrentAccount($pdo);
    if ($acc) {
        if (!empty($acc['is_admin'])) return true;
        if (!empty($acc['tg_user_id']) && in_array((int)$acc['tg_user_id'], $hardcodedAdmins)) return true;
    }
    // 2. TG из куки / сессии
    $userId = getEffectiveUserId($pdo);
    if ($userId && in_array((int)$userId, $hardcodedAdmins)) return true;
    // 3. Прямой GET-параметр tg_user_id (совместимость с JS ?tg_user_id=...)
    if (!empty($_GET['tg_user_id']) && is_numeric($_GET['tg_user_id'])) {
        if (in_array((int)$_GET['tg_user_id'], $hardcodedAdmins)) return true;
    }
    return false;
}

$imgbbKeys = ['58ff4596fd55028a81cbf8c4e38388e1','6981ba08e7b2a8743aab2c8ea008f675','f9b8d27fa4029816d643c7814fd60c60','24dbed2ae9fea9369de6a7b68d0c3ee6','c3e6a55335c71a052c1a59b6a2d6d150'];

// getEffectiveUserId теперь из auth.php — совместимая обёртка

function sendTgNotify($userId, $text) {
    $botToken = getenv('BOT_TOKEN'); if (!$botToken||!$userId) return;
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$userId,'text'=>$text,'parse_mode'=>'HTML']),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false]);
    @curl_exec($ch); curl_close($ch);
}


// ===== НОВЫЕ API ENDPOINTS =====

// Функция для расчета уровня (повторно, на случай если не была добавлена)
if (!function_exists('calculateUserLevel')) {
    function calculateUserLevel(&$pdo, $account_id) {
        try {
            $stmt = $pdo->prepare("SELECT user_xp FROM accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $user = $stmt->fetch();
            if (!$user) return;
            $xp = $user['user_xp'];
            $level = 1;
            $xp_needed = 0;
            while ($xp_needed + (100 + ($level - 1) * 50) <= $xp) {
                $xp_needed += 100 + ($level - 1) * 50;
                $level++;
            }
            $pdo->prepare("UPDATE accounts SET user_level = ? WHERE id = ?")->execute([$level, $account_id]);
        } catch (Exception $e) {}
    }
}

// API: Комментарии под мангой
// Add reply_to column to manga_comments if not exists
try { $pdo->exec("ALTER TABLE manga_comments ADD COLUMN IF NOT EXISTS reply_to INT DEFAULT NULL"); } catch(Exception $e) {}

if (preg_match('~^/api/comments/(\d+)$~', $path, $m)) {
    $manga_id = (int)$m[1];
    // Ensure is_deleted column exists
    try { $pdo->exec("ALTER TABLE manga_comments ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN DEFAULT FALSE"); } catch(Exception $e) {}
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $stmt = $pdo->prepare("SELECT c.id, c.text, c.created_at, c.reply_to, a.username, a.id as account_id, COALESCE(pc.avatar_url, '') as avatar_url FROM manga_comments c JOIN accounts a ON c.account_id = a.id LEFT JOIN profile_customizations pc ON pc.account_id = a.id WHERE c.manga_id = ? AND c.is_deleted = FALSE ORDER BY c.created_at ASC LIMIT 200");
            $stmt->execute([$manga_id]);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'comments' => $stmt->fetchAll()]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$currentAccount) { http_response_code(401); echo json_encode(['success' => false]); exit; }
        $input = json_decode(file_get_contents('php://input'), true);
        $text = trim($input['text'] ?? '');
        $reply_to = isset($input['reply_to']) ? (int)$input['reply_to'] : null;
        if (!$text || strlen($text) > 500) { echo json_encode(['success' => false]); exit; }
        try {
            $pdo->prepare("INSERT INTO manga_comments (manga_id, account_id, text, reply_to) VALUES (?, ?, ?, ?)")->execute([$manga_id, $currentAccount['id'], $text, $reply_to]);
            $pdo->prepare("UPDATE accounts SET user_xp = user_xp + 5 WHERE id = ?")->execute([$currentAccount['id']]);
            calculateUserLevel($pdo, $currentAccount['id']);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false]); }
        exit;
    }
}

// API: Delete comment
if (preg_match('~^/api/comment/(\d+)/delete$~', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$currentAccount) { http_response_code(401); echo json_encode(['success' => false]); exit; }
    $comment_id = (int)$m[1];
    // Ensure is_deleted column exists
    try { $pdo->exec("ALTER TABLE manga_comments ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN DEFAULT FALSE"); } catch(Exception $e) {}
    try {
        // Allow delete by comment owner or admin (soft delete)
        $isAdmin = isAccountAdmin($pdo, (int)$currentAccount['id']);
        if ($isAdmin) {
            $stmt = $pdo->prepare("UPDATE manga_comments SET is_deleted=TRUE WHERE id=? AND is_deleted=FALSE");
            $stmt->execute([$comment_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE manga_comments SET is_deleted=TRUE WHERE id=? AND account_id=? AND is_deleted=FALSE");
            $stmt->execute([$comment_id, (int)$currentAccount['id']]);
        }
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Нет прав или уже удалён']);
        }
    } catch(Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

// API: Личные сообщения
if (preg_match('~^/api/messages(?:/(\d+))?$~', $path, $m)) {
    if (!$currentAccount) { http_response_code(401); echo json_encode(['success' => false]); exit; }
    $other_id = isset($m[1]) ? (int)$m[1] : null;
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            if ($other_id) {
                $stmt = $pdo->prepare("SELECT m.*, s.username as sender_username, s.id as sender_id, r.username as recipient_username, r.id as recipient_id FROM user_messages m JOIN accounts s ON m.sender_id = s.id JOIN accounts r ON m.recipient_id = r.id WHERE (m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?) ORDER BY m.created_at ASC LIMIT 100");
                $stmt->execute([$currentAccount['id'], $other_id, $other_id, $currentAccount['id']]);
                $pdo->prepare("UPDATE user_messages SET is_read = TRUE WHERE sender_id = ? AND recipient_id = ? AND is_read = FALSE")->execute([$other_id, $currentAccount['id']]);
            } else {
                $stmt = $pdo->prepare("SELECT CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END as other_id, (SELECT username FROM accounts WHERE id = CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END) as other_username, MAX(created_at) as last_message_time, (SELECT text FROM user_messages um2 WHERE (um2.sender_id=um.sender_id AND um2.recipient_id=um.recipient_id) OR (um2.sender_id=um.recipient_id AND um2.recipient_id=um.sender_id) ORDER BY um2.created_at DESC LIMIT 1) as last_text, SUM(CASE WHEN is_read = FALSE AND recipient_id = ? THEN 1 ELSE 0 END) as unread_count FROM user_messages um WHERE sender_id = ? OR recipient_id = ? GROUP BY other_id, other_username ORDER BY last_message_time DESC LIMIT 50");
                $stmt->execute([$currentAccount['id'], $currentAccount['id'], $currentAccount['id'], $currentAccount['id'], $currentAccount['id']]);
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'messages' => $stmt->fetchAll()]);
        } catch (Exception $e) { echo json_encode(['success' => false]); }
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $recipient_id = (int)($input['recipient_id'] ?? 0);
        $text = trim($input['text'] ?? '');
        if (!$recipient_id || !$text || strlen($text) > 1000) { echo json_encode(['success' => false]); exit; }
        try {
            $pdo->prepare("INSERT INTO user_messages (sender_id, recipient_id, text) VALUES (?, ?, ?)")->execute([$currentAccount['id'], $recipient_id, $text]);
            $pdo->prepare("UPDATE accounts SET user_xp = user_xp + 2 WHERE id = ?")->execute([$currentAccount['id']]);
            calculateUserLevel($pdo, $currentAccount['id']);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false]); }
        exit;
    }
}

// API: Топ недели
if ($path === '/api/top-week') {
    try {
        $week_start = date('Y-m-d H:i:s', strtotime('monday this week'));
        $stmt = $pdo->prepare("SELECT m.id, m.title, m.cover_imgbb_url as cover_display, COUNT(DISTINCT rp.user_id) as weekly_views, COALESCE(m.likes, 0) as likes, COUNT(DISTINCT mc.id) as comment_count FROM manga m LEFT JOIN reading_progress rp ON m.id = rp.manga_id AND rp.updated_at >= ? LEFT JOIN manga_comments mc ON m.id = mc.manga_id AND mc.created_at >= ? WHERE m.id IS NOT NULL GROUP BY m.id ORDER BY weekly_views DESC, m.likes DESC, comment_count DESC LIMIT 15");
        $stmt->execute([$week_start, $week_start]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
    } catch (Exception $e) { echo json_encode(['success' => false]); }
    exit;
}

// API: Уровень пользователя
if (preg_match('~^/api/user-level/(\d+)$~', $path, $m)) {
    try {
        $account_id = (int)$m[1];
        $stmt = $pdo->prepare("SELECT user_xp, user_level FROM accounts WHERE id = ?");
        $stmt->execute([$account_id]);
        $user = $stmt->fetch();
        if (!$user) { echo json_encode(['success' => false]); exit; }
        $xp_for_level = 100 + ($user['user_level'] - 1) * 50;
        $total_xp_to_level = 0;
        for ($i = 1; $i < $user['user_level']; $i++) { $total_xp_to_level += 100 + ($i - 1) * 50; }
        $current_xp = $user['user_xp'] - $total_xp_to_level;
        $progress = round(($current_xp / $xp_for_level) * 100);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'level' => (int)$user['user_level'], 'xp' => (int)$user['user_xp'], 'xp_for_level' => (int)$xp_for_level, 'current_xp' => (int)$current_xp, 'progress' => min(100, max(0, (int)$progress))]);
    } catch (Exception $e) { echo json_encode(['success' => false]); }
    exit;
}

// API: Поиск пользователей для чата
if ($path === '/api/search-users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$currentAccount) { http_response_code(401); echo json_encode(['success' => false]); exit; }
    try {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['success' => true, 'users' => []]); exit; }
        $stmt = $pdo->prepare("SELECT id, username, COALESCE(avatar_url, '') as avatar_url FROM accounts a LEFT JOIN profile_customizations pc ON pc.account_id = a.id WHERE a.id != ? AND a.username ILIKE ? LIMIT 10");
        $stmt->execute([$currentAccount['id'], '%' . $q . '%']);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
    } catch (Exception $e) { echo json_encode(['success' => false]); }
    exit;
}


// ===== BREVO EMAIL =====
function sendBrevoEmail(string $toEmail, string $toName, string $subject, string $htmlContent): bool {
    $apiKey = getenv('BREVO_API_KEY');
    if (!$apiKey) return false;
    $payload = json_encode([
        'sender'     => ['name' => 'BLACKWATCH Manga', 'email' => 'andreikostlim@gmail.com'],
        'to'         => [['email' => $toEmail, 'name' => $toName]],
        'subject'    => $subject,
        'htmlContent'=> $htmlContent,
    ]);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'api-key: '.$apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function sendVerificationEmail(string $email, string $username, string $code): bool {
    $html = "
    <div style='font-family:Outfit,sans-serif;background:#0c0c0c;padding:40px;border-radius:16px;max-width:480px;margin:auto'>
      <div style='font-family:Syne,sans-serif;font-size:22px;font-weight:800;color:#f2f2f2;letter-spacing:2px;margin-bottom:8px'>⚫ BLACKWATCH</div>
      <h2 style='color:#f2f2f2;font-size:18px;margin-bottom:16px'>Подтверждение email</h2>
      <p style='color:#aaa;font-size:14px;margin-bottom:20px'>Привет, <strong style='color:#f2f2f2'>{$username}</strong>! Введи этот код для подтверждения аккаунта:</p>
      <div style='background:#161616;border:1px solid #242424;border-radius:12px;padding:24px;text-align:center;margin-bottom:20px'>
        <div style='font-size:36px;font-weight:800;letter-spacing:8px;color:#fff;font-family:monospace'>{$code}</div>
        <div style='color:#666;font-size:12px;margin-top:8px'>Код действителен 15 минут</div>
      </div>
      <p style='color:#555;font-size:12px'>Если ты не регистрировался на BLACKWATCH — просто проигнорируй это письмо.</p>
    </div>";
    return sendBrevoEmail($email, $username, 'Подтверждение регистрации | BLACKWATCH', $html);
}

function uploadToImgbb($filePathOrUrl, $apiKeys, $retries=2) {
    $keys = is_array($apiKeys)?$apiKeys:[$apiKeys];
    foreach ($keys as $key) {
        for ($attempt=0;$attempt<=$retries;$attempt++) {
            try {
                $imageData=base64_encode(file_get_contents($filePathOrUrl));
                $ch=curl_init('https://api.imgbb.com/1/upload');
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['key'=>$key,'image'=>$imageData],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>false]);
                $response=curl_exec($ch); $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                if ($httpCode===200){$data=json_decode($response,true);if(!empty($data['data']['url']))return $data['data']['url'];}
                if ($httpCode===400||$httpCode===429) break;
            } catch (Exception $e) {}
        }
    }
    return null;
}

function extractAndSortZip($zipPath, $extractDir) {
    $allowed=['jpg','jpeg','png','webp','gif']; $raw=file_get_contents($zipPath); if($raw===false)return false;
    $len=strlen($raw); $eocdPos=false;
    for($i=$len-22;$i>=max(0,$len-65557);$i--){if(substr($raw,$i,4)==="\x50\x4b\x05\x06"){$eocdPos=$i;break;}}
    if($eocdPos===false)return false;
    $cdCount=unpack('v',substr($raw,$eocdPos+10,2))[1]; $cdOffset=unpack('V',substr($raw,$eocdPos+16,4))[1];
    $entries=[];$pos=$cdOffset;
    for($n=0;$n<$cdCount;$n++){
        if($pos+46>$len)break; if(substr($raw,$pos,4)!=="\x50\x4b\x01\x02")break;
        $modTime=unpack('v',substr($raw,$pos+12,2))[1]; $modDate=unpack('v',substr($raw,$pos+14,2))[1];
        $compSize=unpack('V',substr($raw,$pos+20,4))[1]; $origSize=unpack('V',substr($raw,$pos+24,4))[1];
        $fnameLen=unpack('v',substr($raw,$pos+28,2))[1]; $extraLen=unpack('v',substr($raw,$pos+30,2))[1];
        $cmtLen=unpack('v',substr($raw,$pos+32,2))[1]; $compress=unpack('v',substr($raw,$pos+10,2))[1];
        $localHdrOffset=unpack('V',substr($raw,$pos+42,4))[1]; $fname=substr($raw,$pos+46,$fnameLen);
        $pos+=46+$fnameLen+$extraLen+$cmtLen;
        if(substr($fname,-1)==='/')continue; $base=basename($fname);
        if($base===''||$base[0]==='.')continue; $ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));
        if(!in_array($ext,$allowed))continue;
        $lhPos=$localHdrOffset; if($lhPos+30>$len)continue; if(substr($raw,$lhPos,4)!=="\x50\x4b\x03\x04")continue;
        $lFnameLen=unpack('v',substr($raw,$lhPos+26,2))[1]; $lExtraLen=unpack('v',substr($raw,$lhPos+28,2))[1];
        $dataOffset=$lhPos+30+$lFnameLen+$lExtraLen;
        $second=($modTime&0x1F)*2; $minute=($modTime>>5)&0x3F; $hour=($modTime>>11)&0x1F;
        $day=$modDate&0x1F; $month=($modDate>>5)&0x0F; $year=(($modDate>>9)&0x7F)+1980;
        $mtime=mktime($hour,$minute,$second,$month,$day,$year);
        $entries[]=['name'=>$fname,'base'=>$base,'mtime'=>$mtime,'compress'=>$compress,'compSize'=>$compSize,'origSize'=>$origSize,'dataOffset'=>$dataOffset];
    }
    if(empty($entries))return false;
    usort($entries,function($a,$b){if($a['mtime']===$b['mtime'])return strnatcasecmp($a['base'],$b['base']);return $a['mtime']-$b['mtime'];});
    $extractedPaths=[];
    foreach($entries as $entry){
        $outPath=$extractDir.'/'.$entry['base'];
        if($entry['compress']===0){$fileData=substr($raw,$entry['dataOffset'],$entry['origSize']);}
        elseif($entry['compress']===8){$compressed=substr($raw,$entry['dataOffset'],$entry['compSize']);$fileData=@gzinflate($compressed);if($fileData===false)continue;}
        else{continue;}
        if(file_put_contents($outPath,$fileData)===false)continue;
        $extractedPaths[]=$outPath;
    }
    return empty($extractedPaths)?false:$extractedPaths;
}

function createTelegraphPage($title, $imageUrls) {
    $nodes=[]; $imageUrls=array_values(array_unique(array_filter($imageUrls)));
    foreach($imageUrls as $url){if(!empty($url))$nodes[]=['tag'=>'img','attrs'=>['src'=>$url]];}
    if(empty($nodes))return false;
    $accessToken='192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831';
    $postData=http_build_query(['access_token'=>$accessToken,'title'=>mb_substr($title,0,256),'author_name'=>'MangaBot','content'=>json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'return_content'=>'false']);
    $ch=curl_init("https://api.telegra.ph/createPage");
    curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$postData,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>false]);
    $rawResponse=curl_exec($ch); curl_close($ch);
    $res=json_decode($rawResponse,true);
    if(!isset($res['ok'])||!$res['ok'])return false;
    return $res['result']['url']??false;
}

function saveMangaPages($pdo,$mangaId,$pageUrls){
    $pdo->prepare("DELETE FROM manga_pages WHERE manga_id=?")->execute([$mangaId]);
    $stmt=$pdo->prepare("INSERT INTO manga_pages (manga_id,page_url,page_order) VALUES (?,?,?)");
    foreach($pageUrls as $i=>$url){if(!empty($url))$stmt->execute([$mangaId,$url,$i]);}
}

function saveChapterPages($pdo,$chapterId,$pageUrls){
    $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id=?")->execute([$chapterId]);
    $stmt=$pdo->prepare("INSERT INTO manga_chapter_pages (chapter_id,page_url,page_order) VALUES (?,?,?)");
    foreach($pageUrls as $i=>$url){if(!empty($url))$stmt->execute([$chapterId,$url,$i]);}
}

function logArchiveEntry($pdo,$action_type,$action_text,$userId){
    try{$pdo->prepare("INSERT INTO bot_archive (action_type,action_text,action_by) VALUES (?,?,?)")->execute([$action_type,$action_text,$userId]);}catch(Exception $e){}
}

function extractImgFromContent($nodes){
    $urls=[];if(!is_array($nodes))return $urls;
    foreach($nodes as $node){
        if(!is_array($node))continue;
        if(isset($node['tag'])&&$node['tag']==='img'&&!empty($node['attrs']['src'])){$src=$node['attrs']['src'];$urls[]=strpos($src,'http')===0?$src:'https://telegra.ph'.$src;}
        if(!empty($node['children']))$urls=array_merge($urls,extractImgFromContent($node['children']));
    }
    return $urls;
}

# ========================= API =========================

if ($path==='/api/check-admin'){
    header('Content-Type: application/json');
    $isAdm = isAdminCombined($pdo, $hardcodedAdmins);
    $userId = getEffectiveUserId($pdo);
    echo json_encode(['is_admin'=>$isAdm,'user_id'=>$userId]);exit;
}

if ($path==='/api/imgbb-keys'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['success'=>false,'keys'=>[]]);exit;}echo json_encode(['success'=>true,'keys'=>$imgbbKeys]);exit;}

if ($path==='/api/save-manga'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    try{
        if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}$userId=getEffectiveUserId($pdo);
        $input=json_decode(file_get_contents('php://input'),true);
        $title=trim($input['title']??'');$description=trim($input['description']??'');$coverUrl=trim($input['cover_url']??'');$pageUrls=array_values(array_filter($input['page_urls']??[]));$isSeries=!empty($input['is_series']);
        if(!$title){echo json_encode(['success'=>false,'error'=>'Название обязательно']);exit;}
        $telegraphLink=null;if(!empty($pageUrls)&&!$isSeries)$telegraphLink=createTelegraphPage('♥ '.$title,$pageUrls);
        $insertStmt=$pdo->prepare("INSERT INTO manga (title,telegraph_url,description,cover_imgbb_url,added_by,is_series) VALUES (?,?,?,?,?,?) RETURNING id");
        $insertStmt->execute(['♥ '.$title,$telegraphLink,$description,$coverUrl?:null,$userId,$isSeries]);
        $row=$insertStmt->fetch(PDO::FETCH_ASSOC);$newMangaId=(int)($row['id']??0);
        if(!$newMangaId)$newMangaId=(int)$pdo->lastInsertId();
        if(!$newMangaId){echo json_encode(['success'=>false,'error'=>'Не удалось получить ID']);exit;}
        if(!empty($pageUrls)&&!$isSeries)saveMangaPages($pdo,$newMangaId,$pageUrls);
        // Save genres and tags if provided
        $genreIds=array_filter(array_map('intval',$input['genre_ids']??[]));
        $tagIds=array_filter(array_map('intval',$input['tag_ids']??[]));
        if(!empty($genreIds)){$gIns=$pdo->prepare("INSERT INTO manga_genres(manga_id,genre_id)VALUES(?,?) ON CONFLICT DO NOTHING");foreach($genreIds as $gid)$gIns->execute([$newMangaId,$gid]);}
        if(!empty($tagIds)){$tIns=$pdo->prepare("INSERT INTO manga_tags(manga_id,tag_id)VALUES(?,?) ON CONFLICT DO NOTHING");foreach($tagIds as $tid)$tIns->execute([$newMangaId,$tid]);}
        logArchiveEntry($pdo,'add_manga',"Добавлена манга: ♥ $title",$userId);
        $siteUrl=rtrim(getenv('SITE_URL')?:'','/');
        echo json_encode(['success'=>true,'manga_id'=>$newMangaId,'telegraph'=>$telegraphLink,'pages'=>count($pageUrls),'site_url'=>"{$siteUrl}/read/{$newMangaId}"]);
    }catch(Throwable $e){echo json_encode(['success'=>false,'error'=>'Исключение: '.$e->getMessage()]);}
    exit;
}

if ($path==='/api/save-chapter'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    try{
        if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}$userId=getEffectiveUserId($pdo);
        $input=json_decode(file_get_contents('php://input'),true);$mangaId=(int)($input['manga_id']??0);$chapterNum=(float)($input['chapter_num']??1);$chTitle=trim($input['chapter_title']??'');$pageUrls=array_values(array_filter($input['page_urls']??[]));
        if(!$mangaId){echo json_encode(['success'=>false,'error'=>'Не указан manga_id']);exit;}
        $mangaStmt=$pdo->prepare("SELECT title FROM manga WHERE id=?");$mangaStmt->execute([$mangaId]);$manga=$mangaStmt->fetch();
        if(!$manga){echo json_encode(['success'=>false,'error'=>'Манга не найдена']);exit;}
        $telegraphLink=null;
        if(!empty($pageUrls)){$chapterLabel="Глава $chapterNum".($chTitle?": $chTitle":'');$telegraphLink=createTelegraphPage($manga['title'].' — '.$chapterLabel,$pageUrls);}
        $chInsert=$pdo->prepare("INSERT INTO manga_chapters (manga_id,chapter_num,title,telegraph_url) VALUES (?,?,?,?) RETURNING id");
        $chInsert->execute([$mangaId,$chapterNum,$chTitle?:null,$telegraphLink]);$chRow=$chInsert->fetch();$chapterId=(int)($chRow['id']??0);
        if($chapterId&&!empty($pageUrls))saveChapterPages($pdo,$chapterId,$pageUrls);
        $pdo->prepare("UPDATE manga SET is_series=TRUE WHERE id=?")->execute([$mangaId]);
        logArchiveEntry($pdo,'add_chapter',"Добавлена глава $chapterNum для манги: {$manga['title']}",$userId);
        echo json_encode(['success'=>true,'chapter_id'=>$chapterId,'telegraph'=>$telegraphLink]);
    }catch(Throwable $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}
    exit;
}

if ($path==='/api/manga'){
    header('Content-Type: application/json');
    $page=max(0,(int)($_GET['page']??0));$q=trim($_GET['q']??'');$sort=$_GET['sort']??'new';$limit=24;$offset=$page*$limit;
    $genreSlug=trim($_GET['genre']??'');$tagSlug=trim($_GET['tag']??'');
    $orderBy='m.id DESC';if($sort==='popular')$orderBy='m.likes DESC, m.id DESC';if($sort==='alpha')$orderBy='m.title ASC';
    $conditions=[];$params=[];
    if($q){$conditions[]="LOWER(m.title) LIKE LOWER(?)";$params[]="%{$q}%";}
    if($genreSlug){$conditions[]="m.id IN (SELECT mg.manga_id FROM manga_genres mg JOIN genres g ON g.id=mg.genre_id WHERE g.slug=?)";$params[]=$genreSlug;}
    if($tagSlug){$conditions[]="m.id IN (SELECT mt.manga_id FROM manga_tags mt JOIN tags t ON t.id=mt.tag_id WHERE t.slug=?)";$params[]=$tagSlug;}
    $where=$conditions?'WHERE '.implode(' AND ',$conditions):'';
    $stmt=$pdo->prepare("SELECT m.id,m.title,m.likes,m.dislikes,m.cover_imgbb_url,m.file_id,m.created_at,m.is_series FROM manga m $where ORDER BY $orderBy LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params,[$limit,$offset]));
    $countStmt=$pdo->prepare("SELECT COUNT(*) FROM manga m $where");$countStmt->execute($params);
    $now=date('Y-m-d H:i:s',time()-86400);$items=[];
    foreach($stmt as $m){
        $chapCount=0;if($m['is_series']){$cStmt=$pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE manga_id=?");$cStmt->execute([$m['id']]);$chapCount=(int)$cStmt->fetchColumn();}
        $rStmt=$pdo->prepare("SELECT AVG(rating) as avg FROM manga_ratings WHERE manga_id=?");$rStmt->execute([$m['id']]);$rRow=$rStmt->fetch();$avgRating=$rRow['avg']?round((float)$rRow['avg'],1):0;
        $items[]=['id'=>(int)$m['id'],'title'=>$m['title'],'likes'=>(int)$m['likes'],'dislikes'=>(int)$m['dislikes'],'cover_display'=>!empty($m['cover_imgbb_url'])?$m['cover_imgbb_url']:(!empty($m['file_id'])?'tg://'.$m['file_id']:null),'is_new'=>($m['created_at']>=$now),'is_series'=>(bool)$m['is_series'],'chapter_count'=>$chapCount,'avg_rating'=>$avgRating];
    }
    echo json_encode(['items'=>$items,'total'=>(int)$countStmt->fetchColumn(),'limit'=>$limit]);exit;
}

if ($path==='/api/new-manga'){
    header('Content-Type: application/json');$since=date('Y-m-d H:i:s',time()-86400);
    $stmt=$pdo->prepare("SELECT id,title,likes,dislikes,cover_imgbb_url,file_id,created_at,is_series FROM manga WHERE created_at>=? ORDER BY created_at DESC LIMIT 20");$stmt->execute([$since]);
    $items=[];foreach($stmt as $m){$items[]=['id'=>(int)$m['id'],'title'=>$m['title'],'likes'=>(int)$m['likes'],'cover_display'=>!empty($m['cover_imgbb_url'])?$m['cover_imgbb_url']:null,'is_series'=>(bool)$m['is_series']];}
    echo json_encode(['items'=>$items]);exit;
}

if ($path==='/api/random'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);$stmt=$pdo->prepare("SELECT id FROM manga WHERE id NOT IN (SELECT manga_id FROM user_manga_status WHERE user_id=? AND status='read') ORDER BY RANDOM() LIMIT 1");$stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)$row=$pdo->query("SELECT id FROM manga ORDER BY RANDOM() LIMIT 1")->fetch();echo json_encode(['id'=>$row?(int)$row['id']:null]);exit;}

if (preg_match('#^/api/chapters/(\d+)$#',$path,$m)){header('Content-Type: application/json');$mangaId=(int)$m[1];$stmt=$pdo->prepare("SELECT id,chapter_num,title,telegraph_url,created_at FROM manga_chapters WHERE manga_id=? ORDER BY chapter_num ASC");$stmt->execute([$mangaId]);$chapters=$stmt->fetchAll();foreach($chapters as &$ch){$pStmt=$pdo->prepare("SELECT COUNT(*) FROM manga_chapter_pages WHERE chapter_id=?");$pStmt->execute([$ch['id']]);$ch['page_count']=(int)$pStmt->fetchColumn();}echo json_encode(['chapters'=>$chapters]);exit;}

if (preg_match('#^/api/chapter-pages/(\d+)$#',$path,$m)){
    header('Content-Type: application/json');$chapterId=(int)$m[1];
    $stmt=$pdo->prepare("SELECT page_url FROM manga_chapter_pages WHERE chapter_id=? ORDER BY page_order ASC");$stmt->execute([$chapterId]);$pages=$stmt->fetchAll(PDO::FETCH_COLUMN);
    if(empty($pages)){$chStmt=$pdo->prepare("SELECT telegraph_url FROM manga_chapters WHERE id=?");$chStmt->execute([$chapterId]);$ch=$chStmt->fetch();if(!empty($ch['telegraph_url'])){$tPath=ltrim(parse_url($ch['telegraph_url'],PHP_URL_PATH),'/');$ctx=stream_context_create(['http'=>['timeout'=>10,'user_agent'=>'Mozilla/5.0']]);$apiResp=@file_get_contents("https://api.telegra.ph/getPage/".$tPath."?return_content=true",false,$ctx);if($apiResp){$apiData=json_decode($apiResp,true);if(!empty($apiData['ok'])&&!empty($apiData['result']['content']))$pages=extractImgFromContent($apiData['result']['content']);}}}
    echo json_encode(['pages'=>array_values($pages)]);exit;
}

if (preg_match('#^/api/pages/(\d+)$#',$path,$m)){
    header('Content-Type: application/json');$id=(int)$m[1];
    $stmt=$pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id=? AND page_url IS NOT NULL AND page_url!='' ORDER BY page_order ASC");$stmt->execute([$id]);$pages=$stmt->fetchAll(PDO::FETCH_COLUMN);
    if(empty($pages)){$mangaStmt=$pdo->prepare("SELECT telegraph_url FROM manga WHERE id=?");$mangaStmt->execute([$id]);$manga=$mangaStmt->fetch();$tUrl=$manga['telegraph_url']??null;if($tUrl){$tPath=ltrim(parse_url($tUrl,PHP_URL_PATH),'/');$ctx=stream_context_create(['http'=>['timeout'=>10,'user_agent'=>'Mozilla/5.0']]);$apiResp=@file_get_contents("https://api.telegra.ph/getPage/".$tPath."?return_content=true",false,$ctx);if($apiResp){$apiData=json_decode($apiResp,true);if(!empty($apiData['ok'])&&!empty($apiData['result']['content']))$pages=extractImgFromContent($apiData['result']['content']);}if(!empty($pages)&&strpos($pages[0],'ibb.co')!==false)array_shift($pages);if(empty($pages)){echo json_encode(['pages'=>[],'telegraph_url'=>$tUrl]);exit;}}}
    echo json_encode(['pages'=>array_values($pages)]);exit;
}

if ($path==='/api/progress'&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$input=json_decode(file_get_contents('php://input'),true);$userId=getEffectiveUserId($pdo);if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id']))$userId=(int)$input['tg_user_id'];$mangaId=(int)($input['manga_id']??0);$pageNum=(int)($input['page_num']??1);$totalPages=(int)($input['total_pages']??0);$chapterId=isset($input['chapter_id'])?(int)$input['chapter_id']:null;if($mangaId&&$userId){$pdo->prepare("INSERT INTO reading_progress (user_id,manga_id,page_num,total_pages,chapter_id,updated_at) VALUES (?,?,?,?,?,NOW()) ON CONFLICT (user_id,manga_id) DO UPDATE SET page_num=EXCLUDED.page_num,total_pages=EXCLUDED.total_pages,chapter_id=EXCLUDED.chapter_id,updated_at=NOW()")->execute([$userId,$mangaId,$pageNum,$totalPages,$chapterId]);echo json_encode(['success'=>true]);}else{echo json_encode(['success'=>false]);}exit;}

if ($path==='/api/progress'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);
$stmt=$pdo->prepare("SELECT m.id,m.title,m.cover_imgbb_url,m.file_id,m.is_series,rp.page_num,rp.total_pages,rp.updated_at,rp.chapter_id,s.status,mc.chapter_num FROM user_manga_status s JOIN manga m ON s.manga_id=m.id LEFT JOIN reading_progress rp ON rp.manga_id=m.id AND rp.user_id=s.user_id LEFT JOIN manga_chapters mc ON mc.id=rp.chapter_id WHERE s.user_id=? AND s.status IN ('now','will') ORDER BY rp.updated_at DESC NULLS LAST LIMIT 20");
$stmt->execute([$userId]);$rows=$stmt->fetchAll();
foreach($rows as &$row){
    if($row['is_series'] && empty($row['chapter_id'])){
        $fStmt=$pdo->prepare("SELECT id,chapter_num,title,telegraph_url FROM manga_chapters WHERE manga_id=? ORDER BY chapter_num ASC LIMIT 1");
        $fStmt->execute([$row['id']]);$first=$fStmt->fetch();
        if($first){$row['chapter_id']=$first['id'];$row['chapter_num']=$first['chapter_num'];$row['chapter_title']=$first['title'];$row['chapter_telegraph']=$first['telegraph_url'];$row['is_first_chapter']=true;}
    }
}unset($row);
echo json_encode(['items'=>$rows]);exit;}

if (preg_match('#^/api/cover/(.+)$#',$path,$m)){$fileId=$m[1];$token=getenv('BOT_TOKEN');$ctx=stream_context_create(['http'=>['timeout'=>10]]);$res=@file_get_contents("https://api.telegram.org/bot{$token}/getFile?file_id=".urlencode($fileId),false,$ctx);if($res){$data=json_decode($res,true);if(!empty($data['result']['file_path'])){header("Location: https://api.telegram.org/file/bot{$token}/".$data['result']['file_path'],true,302);exit;}}http_response_code(404);exit;}

if ($path==='/api/vote'&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$input=json_decode(file_get_contents('php://input'),true);$userId=getEffectiveUserId($pdo);if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id']))$userId=(int)$input['tg_user_id'];$mangaId=(int)($input['manga_id']??0);$voteType=$input['vote_type']??'';if($mangaId&&in_array($voteType,['like','dislike'])){$check=$pdo->prepare("SELECT vote_type FROM votes WHERE user_id=? AND manga_id=?");$check->execute([$userId,$mangaId]);$existing=$check->fetch();if($existing){if($existing['vote_type']!==$voteType){$pdo->prepare("UPDATE votes SET vote_type=? WHERE user_id=? AND manga_id=?")->execute([$voteType,$userId,$mangaId]);if($voteType=='like')$pdo->prepare("UPDATE manga SET likes=likes+1,dislikes=GREATEST(0,dislikes-1) WHERE id=?")->execute([$mangaId]);else $pdo->prepare("UPDATE manga SET dislikes=dislikes+1,likes=GREATEST(0,likes-1) WHERE id=?")->execute([$mangaId]);}}else{$pdo->prepare("INSERT INTO votes (user_id,manga_id,vote_type) VALUES (?,?,?)")->execute([$userId,$mangaId,$voteType]);if($voteType=='like')$pdo->prepare("UPDATE manga SET likes=likes+1 WHERE id=?")->execute([$mangaId]);else $pdo->prepare("UPDATE manga SET dislikes=dislikes+1 WHERE id=?")->execute([$mangaId]);}$stmt=$pdo->prepare("SELECT likes,dislikes FROM manga WHERE id=?");$stmt->execute([$mangaId]);$stats=$stmt->fetch();echo json_encode(['success'=>true,'likes'=>(int)$stats['likes'],'dislikes'=>(int)$stats['dislikes']]);exit;}echo json_encode(['success'=>false]);exit;}

if ($path==='/api/status'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $input=json_decode(file_get_contents('php://input'),true);
    $userId=getEffectiveUserId($pdo);
    if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id'])){$userId=(int)$input['tg_user_id'];$_SESSION['tg_user_id']=$userId;if(!headers_sent())setcookie('tg_user_id',$userId,time()+86400*30,'/',''  ,false,false);}
    $mangaId=(int)($input['manga_id']??0);$status=$input['status']??'';
    if($mangaId&&$status){
        // FIX: Check wasRead BEFORE updating status
        $wasReadBefore = false;
        if ($currentAccount && in_array($status, ['read','Прочитано'])) {
            try {
                $chkBefore = $pdo->prepare("SELECT 1 FROM user_manga_status WHERE (user_id=? OR account_id=?) AND manga_id=? AND status IN ('read','Прочитано')");
                $chkBefore->execute([$userId, (int)$currentAccount['id'], $mangaId]);
                $wasReadBefore = (bool)$chkBefore->fetch();
            } catch(Exception $e) {}
        }
        $pdo->prepare("INSERT INTO user_manga_status (user_id,manga_id,status) VALUES (?,?,?) ON CONFLICT (user_id,manga_id) DO UPDATE SET status=EXCLUDED.status")->execute([$userId,$mangaId,$status]);
        // Also update account_id column if user is logged in via web session
        if ($currentAccount) {
            $aid = (int)$currentAccount['id'];
            $pdo->prepare("INSERT INTO user_manga_status (user_id,manga_id,status,account_id) VALUES (?,?,?,?) ON CONFLICT (user_id,manga_id) DO UPDATE SET status=EXCLUDED.status,account_id=EXCLUDED.account_id")->execute([$userId,$mangaId,$status,$aid]);
        }
        // Award XP for reading (50 XP per manga, only once)
        if (in_array($status, ['read','Прочитано'])) {
            if ($currentAccount && !$wasReadBefore) {
                $pdo->prepare("UPDATE accounts SET user_xp = user_xp + 50 WHERE id = ?")->execute([$currentAccount['id']]);
                calculateUserLevel($pdo, $currentAccount['id']);
            }
        }
        echo json_encode(['success'=>true,'user_id'=>$userId]);exit;
    }
    echo json_encode(['success'=>false]);exit;
}

if ($path==='/api/library'){
    header('Content-Type: application/json');
    $userId=getEffectiveUserId($pdo);
    // If logged in via account, also fetch by account_id to merge old+new entries
    if ($currentAccount) {
        $aid = (int)$currentAccount['id'];
        $stmt=$pdo->prepare("SELECT DISTINCT ON (m.id) m.id,m.title,m.cover_imgbb_url,m.file_id,s.status, COALESCE((SELECT AVG(r.rating) FROM manga_ratings r WHERE r.manga_id=m.id),0) as avg_rating FROM user_manga_status s JOIN manga m ON s.manga_id=m.id WHERE s.user_id=? OR s.account_id=? ORDER BY m.id");
        $stmt->execute([$userId,$aid]);
    } else {
        $stmt=$pdo->prepare("SELECT m.id,m.title,m.cover_imgbb_url,m.file_id,s.status, COALESCE((SELECT AVG(r.rating) FROM manga_ratings r WHERE r.manga_id=m.id),0) as avg_rating FROM user_manga_status s JOIN manga m ON s.manga_id=m.id WHERE s.user_id=?");
        $stmt->execute([$userId]);
    }
    $items=$stmt->fetchAll();foreach($items as &$row){$row['avg_rating']=round((float)$row['avg_rating'],1);}unset($row);
    echo json_encode(['items'=>$items,'user_id'=>$userId]);exit;
}

if ($path==='/api/suggest'&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$input=json_decode(file_get_contents('php://input'),true);$userId=getEffectiveUserId($pdo);$text=trim($input['text']??'');if(!$text){echo json_encode(['success'=>false,'error'=>'Пустое сообщение']);exit;}$pdo->prepare("INSERT INTO suggestions (user_id,text) VALUES (?,?)")->execute([$userId,$text]);foreach($hardcodedAdmins as $adminId)sendTgNotify($adminId,"💡 Новое предложение от #$userId:\n".mb_substr($text,0,400));echo json_encode(['success'=>true]);exit;}

// API: Custom statuses
if ($path==='/api/custom-statuses'){
    header('Content-Type: application/json');
    $userId=getEffectiveUserId($pdo);
    $stmt=$pdo->prepare("SELECT id,name,color FROM user_custom_statuses WHERE user_id=? ORDER BY created_at ASC");
    $stmt->execute([$userId]);
    echo json_encode(['items'=>$stmt->fetchAll()]);exit;
}

if ($path==='/api/custom-statuses/add'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $input=json_decode(file_get_contents('php://input'),true);
    $userId=getEffectiveUserId($pdo);
    $name=trim($input['name']??'');$color=trim($input['color']??'#7c5cff');
    if(!$name){echo json_encode(['success'=>false,'error'=>'Нет названия']);exit;}
    $pdo->prepare("INSERT INTO user_custom_statuses (user_id,name,color) VALUES (?,?,?)")->execute([$userId,$name,$color]);
    echo json_encode(['success'=>true]);exit;
}

if (preg_match('#^/api/custom-statuses/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $userId=getEffectiveUserId($pdo);
    $pdo->prepare("DELETE FROM user_custom_statuses WHERE id=? AND user_id=?")->execute([(int)$m[1],$userId]);
    echo json_encode(['success'=>true]);exit;
}

// API: Ratings
if ($path==='/api/rate'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $input=json_decode(file_get_contents('php://input'),true);
    $userId=getEffectiveUserId($pdo);
    if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id']))$userId=(int)$input['tg_user_id'];
    $mangaId=(int)($input['manga_id']??0);$rating=(int)($input['rating']??0);
    if($mangaId&&$rating>=1&&$rating<=10){
        $pdo->prepare("INSERT INTO manga_ratings (user_id,manga_id,rating) VALUES (?,?,?) ON CONFLICT (user_id,manga_id) DO UPDATE SET rating=EXCLUDED.rating")->execute([$userId,$mangaId,$rating]);
        $avg=$pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM manga_ratings WHERE manga_id=?");$avg->execute([$mangaId]);$r=$avg->fetch();
        echo json_encode(['success'=>true,'avg'=>round((float)$r['avg'],1),'count'=>(int)$r['cnt']]);exit;
    }
    echo json_encode(['success'=>false]);exit;
}

if (preg_match('#^/api/rating/(\d+)$#',$path,$m)){
    header('Content-Type: application/json');$mangaId=(int)$m[1];$userId=getEffectiveUserId($pdo);
    $avg=$pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM manga_ratings WHERE manga_id=?");$avg->execute([$mangaId]);$r=$avg->fetch();
    $myR=$pdo->prepare("SELECT rating FROM manga_ratings WHERE manga_id=? AND user_id=?");$myR->execute([$mangaId,$userId]);$my=$myR->fetchColumn();
    echo json_encode(['avg'=>round((float)$r['avg'],1),'count'=>(int)$r['cnt'],'my_rating'=>$my?:(int)$my]);exit;
}

// API: Admin messages
if ($path==='/api/admin/messages'&&$_SERVER['REQUEST_METHOD']==='GET'){
    header('Content-Type: application/json');
    $stmt=$pdo->query("SELECT id,text,sent_by,created_at FROM admin_messages WHERE is_deleted=FALSE ORDER BY created_at DESC");
    echo json_encode(['items'=>$stmt->fetchAll()]);exit;
}

if ($path==='/api/admin/messages/send'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}$userId=getEffectiveUserId($pdo);
    $input=json_decode(file_get_contents('php://input'),true);$text=trim($input['text']??'');
    if(!$text){echo json_encode(['success'=>false,'error'=>'Пустое сообщение']);exit;}
    $pdo->prepare("INSERT INTO admin_messages (text,sent_by) VALUES (?,?)")->execute([$text,$userId]);
    echo json_encode(['success'=>true]);exit;
}

if (preg_match('#^/api/admin/messages/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}
    $pdo->prepare("UPDATE admin_messages SET is_deleted=TRUE WHERE id=?")->execute([(int)$m[1]]);
    echo json_encode(['success'=>true]);exit;
}

if ($path==='/api/admin/stats'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$mangaCount=(int)$pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();$usersCount=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();$votesCount=(int)$pdo->query("SELECT COUNT(*) FROM votes")->fetchColumn();$pagesCount=(int)$pdo->query("SELECT COUNT(*) FROM manga_pages")->fetchColumn();$chaptersCount=(int)$pdo->query("SELECT COUNT(*) FROM manga_chapters")->fetchColumn();$newToday=(int)$pdo->query("SELECT COUNT(*) FROM manga WHERE created_at>=NOW()-INTERVAL '24 hours'")->fetchColumn();$topManga=$pdo->query("SELECT title,likes FROM manga ORDER BY likes DESC LIMIT 5")->fetchAll();$suggestCount=(int)$pdo->query("SELECT COUNT(*) FROM suggestions WHERE status='new'")->fetchColumn();$msgCount=(int)$pdo->query("SELECT COUNT(*) FROM admin_messages WHERE is_deleted=FALSE")->fetchColumn();echo json_encode(['manga_count'=>$mangaCount,'users_count'=>$usersCount,'votes_count'=>$votesCount,'pages_count'=>$pagesCount,'chapters_count'=>$chaptersCount,'new_today'=>$newToday,'top_manga'=>$topManga,'suggest_count'=>$suggestCount,'msg_count'=>$msgCount]);exit;}

if ($path==='/api/admin/archive'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$page=max(0,(int)($_GET['page']??0));$limit=20;$offset=$page*$limit;$total=(int)$pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();$stmt=$pdo->prepare("SELECT * FROM bot_archive ORDER BY created_at DESC LIMIT $limit OFFSET $offset");$stmt->execute();echo json_encode(['items'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page]);exit;}

if ($path==='/api/admin/manga-list'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$page=max(0,(int)($_GET['page']??0));$q=trim($_GET['q']??'');$limit=10;$offset=$page*$limit;if($q){$stmt=$pdo->prepare("SELECT id,title,cover_imgbb_url,created_at,likes,is_series FROM manga WHERE title ILIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");$stmt->execute(["%$q%"]);$cStmt=$pdo->prepare("SELECT COUNT(*) FROM manga WHERE title ILIKE ?");$cStmt->execute(["%$q%"]);}else{$stmt=$pdo->prepare("SELECT id,title,cover_imgbb_url,created_at,likes,is_series FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");$stmt->execute();$cStmt=$pdo->query("SELECT COUNT(*) FROM manga");}echo json_encode(['items'=>$stmt->fetchAll(),'total'=>(int)$cStmt->fetchColumn()]);exit;}


// ===== API: ADMIN TAGS/GENRES CRUD =====

// Добавить тег
if ($path==='/api/admin/tags/add' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}
    $input=json_decode(file_get_contents('php://input'),true);
    $name=trim($input['name']??'');
    $slug=trim($input['slug']??'');
    $isNsfw=!empty($input['is_nsfw']);
    if(!$name||!$slug){echo json_encode(['success'=>false,'error'=>'Укажи название и slug']);exit;}
    $slug=preg_replace('/[^a-z0-9\-]/','',$slug);
    try{$pdo->prepare("INSERT INTO tags(name,slug,is_nsfw)VALUES(?,?,?) ON CONFLICT(slug) DO NOTHING")->execute([$name,$slug,$isNsfw?1:0]);
    echo json_encode(['success'=>true]);}catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}

// Удалить тег
if (preg_match('#^/api/admin/tags/(\d+)/delete$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}
    try{$pdo->prepare("DELETE FROM manga_tags WHERE tag_id=?")->execute([(int)$m[1]]);
    $pdo->prepare("DELETE FROM tags WHERE id=?")->execute([(int)$m[1]]);
    echo json_encode(['success'=>true]);}catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}

// Добавить жанр
if ($path==='/api/admin/genres/add' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}
    $input=json_decode(file_get_contents('php://input'),true);
    $name=trim($input['name']??'');
    $slug=trim($input['slug']??'');
    if(!$name||!$slug){echo json_encode(['success'=>false,'error'=>'Укажи название и slug']);exit;}
    $slug=preg_replace('/[^a-z0-9\-]/','',$slug);
    try{$pdo->prepare("INSERT INTO genres(name,slug)VALUES(?,?) ON CONFLICT DO NOTHING")->execute([$name,$slug]);
    echo json_encode(['success'=>true]);}catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}

// Удалить жанр
if (preg_match('#^/api/admin/genres/(\d+)/delete$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}
    try{$pdo->prepare("DELETE FROM manga_genres WHERE genre_id=?")->execute([(int)$m[1]]);
    $pdo->prepare("DELETE FROM genres WHERE id=?")->execute([(int)$m[1]]);
    echo json_encode(['success'=>true]);}catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}

// Сид всех тегов и жанров (принудительный)
if ($path==='/api/admin/reseed-tags' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}
    try{
        $tagsData=[['Реинкарнация','reincarnation',false],['Перерождение','rebirth',false],['Система','system',false],['Подземелья','dungeons',false],['Некромант','necromancer',false],['Культивация','cultivation',false],['Монстродевушки','monster-girls',false],['Цундере','tsundere',false],['Яндере','yandere',false],['Путешествие во времени','time-travel',false],['Боги','gods',false],['Зомби','zombies',false],['Школьная жизнь','school-life',false],['Ассасины','assassins',false],['Мафия','mafia',false],['Виртуальная реальность','vr',false],['Игровой мир','game-world',false],['Постапокалипсис','apocalypse',false],['Игра на выживание','survival-game',false],['Кулинария','cooking',false],['Драконы','dragons',false],['Зверолюди','beast-people',false],['Эльфы','elves',false],['Тёмное фэнтези','dark-fantasy',false],['Герой','hero',false],['Злодейка','villainess',false],['Строительство королевства','kingdom-building',false],['Регрессия','regression',false],['Охотники','hunters',false],['Гениальный ГГ','genius-mc',false],['Антигерой','antihero',false],['Ниндзя','ninja',false],['Пираты','pirates',false],['Космос','space',false],['Месть','revenge',false],['Турнир','tournament',false],['Сильный ГГ','op-mc',false],['Слабый в Сильный','weak-to-strong',false],['Магическая академия','magic-academy',false],['РПГ','rpg',false],['MMORPG','mmorpg',false],['Гильдии','guilds',false],['Любовный треугольник','love-triangle',false],['Холодный ГГ','cold-mc',false],['Легендарное оружие','legendary-weapon',false],['Проклятия','curses',false],['Короли','kings',false],['Академия','academy',false],['Гендер-бендер','gender-bender',false],['Суперсилы','superpowers',false],['Телепортация','teleportation',false],['Взрослый контент','adult',true],['18+','18plus',true],['NSFW','nsfw',true]];
        $genresData=[['Экшен','action'],['Романтика','romance'],['Фэнтези','fantasy'],['Комедия','comedy'],['Драма','drama'],['Ужасы','horror'],['Мистика','mystery'],['Приключения','adventure'],['Боевые искусства','martial-arts'],['Психология','psychology'],['Сёнен','shounen'],['Сёдзё','shoujo'],['Сейнен','seinen'],['Иссекай','isekai'],['Спорт','sports']];
        $tIns=$pdo->prepare("INSERT INTO tags(name,slug,is_nsfw)VALUES(?,?,?) ON CONFLICT(slug) DO UPDATE SET name=EXCLUDED.name, is_nsfw=EXCLUDED.is_nsfw");
        foreach($tagsData as $t)$tIns->execute([$t[0],$t[1],$t[2]?1:0]);
        $gIns=$pdo->prepare("INSERT INTO genres(name,slug)VALUES(?,?) ON CONFLICT DO NOTHING");
        foreach($genresData as $g)$gIns->execute($g);
        $tc=(int)$pdo->query("SELECT COUNT(*) FROM tags")->fetchColumn();
        $gc=(int)$pdo->query("SELECT COUNT(*) FROM genres")->fetchColumn();
        echo json_encode(['success'=>true,'tags'=>$tc,'genres'=>$gc]);
    }catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}

// API: Удаление дублей жанров (оставляет первый по id)
if ($path==='/api/admin/dedup-genres' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}
    try{
        // Удалить дубли жанров — оставить минимальный id по каждому slug
        $pdo->exec("DELETE FROM manga_genres WHERE genre_id NOT IN (SELECT MIN(id) FROM genres GROUP BY slug)");
        $pdo->exec("DELETE FROM genres WHERE id NOT IN (SELECT MIN(id) FROM genres GROUP BY slug)");
        // То же для тегов
        $pdo->exec("DELETE FROM manga_tags WHERE tag_id NOT IN (SELECT MIN(id) FROM tags GROUP BY slug)");
        $pdo->exec("DELETE FROM tags WHERE id NOT IN (SELECT MIN(id) FROM tags GROUP BY slug)");
        $tc=(int)$pdo->query("SELECT COUNT(*) FROM tags")->fetchColumn();
        $gc=(int)$pdo->query("SELECT COUNT(*) FROM genres")->fetchColumn();
        echo json_encode(['success'=>true,'tags'=>$tc,'genres'=>$gc]);
    }catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}


// API: Get genres and tags lists
if ($path==='/api/genres'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        $genres=$pdo->query("SELECT id,name,slug FROM genres ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tagsRaw=$pdo->query("SELECT id,name,slug,is_nsfw FROM tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        // Правильный каст is_nsfw в bool (PostgreSQL возвращает 't'/'f' или '1'/'0')
        $tags=array_map(function($t){
            $t['is_nsfw']=($t['is_nsfw']==='t'||$t['is_nsfw']===true||$t['is_nsfw']==='1'||$t['is_nsfw']===1);
            return $t;
        },$tagsRaw);
        // Авто-сид если пусто
        if(empty($tags)){
            $tagsData=[['Реинкарнация','reincarnation',false],['Перерождение','rebirth',false],['Система','system',false],['Подземелья','dungeons',false],['Некромант','necromancer',false],['Культивация','cultivation',false],['Монстродевушки','monster-girls',false],['Цундере','tsundere',false],['Яндере','yandere',false],['Путешествие во времени','time-travel',false],['Боги','gods',false],['Зомби','zombies',false],['Школьная жизнь','school-life',false],['Ассасины','assassins',false],['Мафия','mafia',false],['Виртуальная реальность','vr',false],['Игровой мир','game-world',false],['Постапокалипсис','apocalypse',false],['Игра на выживание','survival-game',false],['Кулинария','cooking',false],['Драконы','dragons',false],['Зверолюди','beast-people',false],['Эльфы','elves',false],['Тёмное фэнтези','dark-fantasy',false],['Герой','hero',false],['Злодейка','villainess',false],['Строительство королевства','kingdom-building',false],['Регрессия','regression',false],['Охотники','hunters',false],['Гениальный ГГ','genius-mc',false],['Антигерой','antihero',false],['Ниндзя','ninja',false],['Пираты','pirates',false],['Космос','space',false],['Месть','revenge',false],['Турнир','tournament',false],['Сильный ГГ','op-mc',false],['Слабый в Сильный','weak-to-strong',false],['Магическая академия','magic-academy',false],['РПГ','rpg',false],['MMORPG','mmorpg',false],['Гильдии','guilds',false],['Любовный треугольник','love-triangle',false],['Холодный ГГ','cold-mc',false],['Легендарное оружие','legendary-weapon',false],['Проклятия','curses',false],['Короли','kings',false],['Академия','academy',false],['Гендер-бендер','gender-bender',false],['Суперсилы','superpowers',false],['Телепортация','teleportation',false],['Взрослый контент','adult',true],['18+','18plus',true],['NSFW','nsfw',true]];
            $tIns=$pdo->prepare("INSERT INTO tags(name,slug,is_nsfw)VALUES(?,?,?) ON CONFLICT(slug) DO NOTHING");
            foreach($tagsData as $t)$tIns->execute([$t[0],$t[1],$t[2]?1:0]);
            $tagsRaw=$pdo->query("SELECT id,name,slug,is_nsfw FROM tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $tags=array_map(function($t){$t['is_nsfw']=($t['is_nsfw']==='t'||$t['is_nsfw']===true||$t['is_nsfw']==='1'||$t['is_nsfw']===1);return $t;},$tagsRaw);
        }
        if(empty($genres)){
            $genresData=[['Экшен','action'],['Романтика','romance'],['Фэнтези','fantasy'],['Комедия','comedy'],['Драма','drama'],['Ужасы','horror'],['Мистика','mystery'],['Приключения','adventure'],['Боевые искусства','martial-arts'],['Психология','psychology'],['Сёнен','shounen'],['Сёдзё','shoujo'],['Сейнен','seinen'],['Иссекай','isekai'],['Спорт','sports']];
            $gIns=$pdo->prepare("INSERT INTO genres(name,slug)VALUES(?,?) ON CONFLICT(slug) DO NOTHING");
            foreach($genresData as $g)$gIns->execute($g);
            $genres=$pdo->query("SELECT id,name,slug FROM genres ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['genres'=>$genres,'tags'=>$tags]);
    }catch(Exception $e){
        echo json_encode(['genres'=>[],'tags'=>[],'error'=>$e->getMessage()]);
    }
    exit;
}


// API: Update manga genres/tags (admin)
if (preg_match('#^/api/admin/manga/(\\d+)/genres$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$mId=(int)$m[1];$input=json_decode(file_get_contents('php://input'),true);$genreIds=$input['genre_ids']??[];$tagIds=$input['tag_ids']??[];
    try{$pdo->prepare("DELETE FROM manga_genres WHERE manga_id=?")->execute([$mId]);
    $pdo->prepare("DELETE FROM manga_tags WHERE manga_id=?")->execute([$mId]);
    foreach($genreIds as $gid){try{$pdo->prepare("INSERT INTO manga_genres(manga_id,genre_id)VALUES(?,?) ON CONFLICT DO NOTHING")->execute([$mId,(int)$gid]);}catch(Exception $e){}}
    foreach($tagIds as $tid){try{$pdo->prepare("INSERT INTO manga_tags(manga_id,tag_id)VALUES(?,?) ON CONFLICT DO NOTHING")->execute([$mId,(int)$tid]);}catch(Exception $e){}}
    echo json_encode(['success'=>true]);}catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;}

// API: Get manga genres/tags
if (preg_match('#^/api/manga/(\\d+)/genres$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='GET'){header('Content-Type: application/json');$mId=(int)$m[1];
    try{$genres=$pdo->prepare("SELECT g.id,g.name,g.slug FROM genres g JOIN manga_genres mg ON g.id=mg.genre_id WHERE mg.manga_id=? ORDER BY g.name");$genres->execute([$mId]);
    $tags=$pdo->prepare("SELECT t.id,t.name,t.slug,t.is_nsfw FROM tags t JOIN manga_tags mt ON t.id=mt.tag_id WHERE mt.manga_id=? ORDER BY t.name");$tags->execute([$mId]);
    echo json_encode(['genres'=>$genres->fetchAll(),'tags'=>$tags->fetchAll()]);}catch(Exception $e){echo json_encode(['genres'=>[],'tags'=>[]]);}exit;}
if (preg_match('#^/api/admin/manga/(\d+)$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='GET'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$mId=(int)$m[1];$stmt=$pdo->prepare("SELECT * FROM manga WHERE id=?");$stmt->execute([$mId]);$manga=$stmt->fetch();if(!$manga){echo json_encode(['error'=>'Не найдено']);exit;}$chapters=[];if($manga['is_series']){$chStmt=$pdo->prepare("SELECT id,chapter_num,title,created_at FROM manga_chapters WHERE manga_id=? ORDER BY chapter_num ASC");$chStmt->execute([$mId]);$chapters=$chStmt->fetchAll();}$manga['chapters']=$chapters;echo json_encode($manga);exit;}

if (preg_match('#^/api/admin/manga/(\d+)$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$userId=getEffectiveUserId($pdo);$mId=(int)$m[1];$input=json_decode(file_get_contents('php://input'),true);$allowed=['title','description','telegraph_url','cover_imgbb_url'];$updates=[];$values=[];foreach($allowed as $field){if(isset($input[$field])){$updates[]="$field=?";$values[]=$input[$field];}}if(empty($updates)){echo json_encode(['success'=>false,'error'=>'Нечего обновлять']);exit;}$values[]=$mId;$pdo->prepare("UPDATE manga SET ".implode(', ',$updates)." WHERE id=?")->execute($values);logArchiveEntry($pdo,'edit_manga',"Отредактирована манга ID: $mId (через сайт)",$userId);echo json_encode(['success'=>true]);exit;}

if (preg_match('#^/api/admin/manga/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$userId=getEffectiveUserId($pdo);$mId=(int)$m[1];$titleStmt=$pdo->prepare("SELECT title FROM manga WHERE id=?");$titleStmt->execute([$mId]);$row=$titleStmt->fetch();$pdo->prepare("DELETE FROM manga WHERE id=?")->execute([$mId]);$pdo->prepare("DELETE FROM manga_pages WHERE manga_id=?")->execute([$mId]);$pdo->prepare("DELETE FROM manga_chapters WHERE manga_id=?")->execute([$mId]);logArchiveEntry($pdo,'delete_manga',"Удалена манга: ".($row['title']??"ID $mId"),$userId);echo json_encode(['success'=>true]);exit;}

if (preg_match('#^/api/admin/chapter/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$userId=getEffectiveUserId($pdo);$chId=(int)$m[1];$pdo->prepare("DELETE FROM manga_chapters WHERE id=?")->execute([$chId]);$pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id=?")->execute([$chId]);logArchiveEntry($pdo,'delete_chapter',"Удалена глава ID: $chId",$userId);echo json_encode(['success'=>true]);exit;}

if ($path==='/api/admin/suggestions'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$status=$_GET['status']??'new';$page=max(0,(int)($_GET['page']??0));$limit=15;$offset=$page*$limit;$total=(int)$pdo->query("SELECT COUNT(*) FROM suggestions WHERE status='".addslashes($status)."'")->fetchColumn();$stmt=$pdo->prepare("SELECT * FROM suggestions WHERE status=? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");$stmt->execute([$status]);echo json_encode(['items'=>$stmt->fetchAll(),'total'=>$total]);exit;}

if (preg_match('#^/api/admin/suggestions/(\d+)/status$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$input=json_decode(file_get_contents('php://input'),true);$newStatus=$input['status']??'read';$pdo->prepare("UPDATE suggestions SET status=? WHERE id=?")->execute([$newStatus,(int)$m[1]]);echo json_encode(['success'=>true]);exit;}

if ($path==='/api/admin/admins'){header('Content-Type: application/json');if(!isAdminCombined($pdo,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$admins=[];foreach($hardcodedAdmins as $id){$tag=null;try{$s=$pdo->prepare("SELECT tag_name FROM admin_tags WHERE user_id=?");$s->execute([$id]);$tag=$s->fetchColumn();}catch(Exception $e){}$admins[]=['user_id'=>$id,'tag'=>$tag?:"ID: $id"];}echo json_encode(['admins'=>$admins]);exit;}

# ========================= AUTH API =========================

// API: Регистрация
if ($path==='/api/auth/register' && $_SERVER['REQUEST_METHOD']==='POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $email    = strtolower(trim($input['email'] ?? ''));
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $confirm  = $input['confirm'] ?? '';

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'error'=>'Неверный email']); exit; }
    if (!$username || strlen($username)<3 || strlen($username)>30) { echo json_encode(['success'=>false,'error'=>'Username: 3-30 символов']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { echo json_encode(['success'=>false,'error'=>'Username: только буквы, цифры, _']); exit; }
    if (strlen($password)<6) { echo json_encode(['success'=>false,'error'=>'Пароль минимум 6 символов']); exit; }
    if ($password !== $confirm) { echo json_encode(['success'=>false,'error'=>'Пароли не совпадают']); exit; }

    try {
        $check = $pdo->prepare("SELECT id FROM accounts WHERE email=? OR username=?");
        $check->execute([$email, $username]);
        if ($check->fetch()) { echo json_encode(['success'=>false,'error'=>'Email или username уже занят']); exit; }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $verifyCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $verifyExpires = date('Y-m-d H:i:s', time() + 900); // 15 min
        $ins = $pdo->prepare("INSERT INTO accounts (email,username,password_hash,is_verified,verify_code,verify_expires) VALUES (?,?,?,FALSE,?,?) RETURNING id");
        $ins->execute([$email, $username, $hash, $verifyCode, $verifyExpires]);
        $accountId = (int)$ins->fetchColumn();

        // Send verification email
        sendVerificationEmail($email, $username, $verifyCode);

        // Create session anyway (they can verify later)
        createSession($pdo, $accountId, true);
        echo json_encode(['success'=>true,'username'=>$username,'needs_verify'=>true]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>'Ошибка сервера: '.$e->getMessage()]);
    }
    exit;
}

// API: Вход
if ($path==='/api/auth/login' && $_SERVER['REQUEST_METHOD']==='POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $input    = json_decode(file_get_contents('php://input'), true);
    $login    = strtolower(trim($input['login'] ?? '')); // email или username
    $password = $input['password'] ?? '';
    $remember = !empty($input['remember']);

    if (!$login || !$password) { echo json_encode(['success'=>false,'error'=>'Заполни все поля']); exit; }

    try {
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE email=? OR username=?");
        $stmt->execute([$login, $login]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            echo json_encode(['success'=>false,'error'=>'Неверный email/username или пароль']);
            exit;
        }
        createSession($pdo, (int)$account['id'], $remember);
        echo json_encode(['success'=>true,'username'=>$account['username']]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>'Ошибка сервера']);
    }
    exit;
}

// API: Получить токен привязки TG
if ($path==='/api/auth/tg-link-token' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $token = generateTgLinkToken($pdo, (int)$account['id']);
    $botUsername = getenv('BOT_USERNAME') ?: 'blackwatch_manga_bot';
    echo json_encode(['success'=>true,'token'=>$token,'command'=>"/start link_{$token}",'bot'=>$botUsername]);
    exit;
}

// API: Отвязать TG
if ($path==='/api/auth/tg-unlink' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $pdo->prepare("UPDATE accounts SET tg_user_id=NULL, tg_link_token=NULL WHERE id=?")->execute([(int)$account['id']]);
    echo json_encode(['success'=>true]);
    exit;
}

// API: Статистика профиля
if ($path==='/api/auth/profile-stats') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false]); exit; }
    $aid = (int)$account['id'];
    $tgId = $account['tg_user_id'] ? (int)$account['tg_user_id'] : 0;
    // Build WHERE clause combining account_id and optional tg_user_id
    $acWhere = $tgId ? "(account_id={$aid} OR user_id={$tgId})" : "account_id={$aid}";
    try {
        $total = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE {$acWhere}")->fetchColumn();
        $read  = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('read','Прочитано') AND {$acWhere}")->fetchColumn();
        $now   = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('now','reading','Читаю') AND {$acWhere}")->fetchColumn();
        $will  = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('will','Запланировано') AND {$acWhere}")->fetchColumn();
        $drop  = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('drop','Брошено') AND {$acWhere}")->fetchColumn();
        $pause = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('pause','На паузе') AND {$acWhere}")->fetchColumn();
        echo json_encode(['success'=>true,'total'=>$total,'read'=>$read,'now'=>$now,'will'=>$will,'drop'=>$drop,'pause'=>$pause]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// API: Подтверждение email
if ($path==='/api/auth/verify-email' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim($input['code'] ?? '');
    if (!$code) { echo json_encode(['success'=>false,'error'=>'Введи код']); exit; }
    $stmt = $pdo->prepare("SELECT verify_code, verify_expires FROM accounts WHERE id=?");
    $stmt->execute([(int)$account['id']]);
    $row = $stmt->fetch();
    if (!$row || $row['verify_code'] !== $code) { echo json_encode(['success'=>false,'error'=>'Неверный код']); exit; }
    if (strtotime($row['verify_expires']) < time()) { echo json_encode(['success'=>false,'error'=>'Код истёк']); exit; }
    $pdo->prepare("UPDATE accounts SET is_verified=TRUE, verify_code=NULL, verify_expires=NULL WHERE id=?")->execute([(int)$account['id']]);
    echo json_encode(['success'=>true]);
    exit;
}

// API: Повторная отправка кода
if ($path==='/api/auth/resend-verify' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    if ($account['is_verified']) { echo json_encode(['success'=>false,'error'=>'Уже подтверждён']); exit; }
    $verifyCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $verifyExpires = date('Y-m-d H:i:s', time() + 900);
    $pdo->prepare("UPDATE accounts SET verify_code=?, verify_expires=? WHERE id=?")->execute([$verifyCode, $verifyExpires, (int)$account['id']]);
    sendVerificationEmail($account['email'], $account['username'], $verifyCode);
    echo json_encode(['success'=>true]);
    exit;
}

// API: Кастомизация профиля — получить
if ($path==='/api/profile/customization' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $username = trim($_GET['username'] ?? '');
    if ($username) {
        $acc = $pdo->prepare("SELECT id FROM accounts WHERE username=?"); $acc->execute([$username]); $row = $acc->fetch();
        $aid = $row ? (int)$row['id'] : 0;
    } else {
        $account = getCurrentAccount($pdo);
        $aid = $account ? (int)$account['id'] : 0;
    }
    if (!$aid) { echo json_encode(['success'=>false]); exit; }
    $stmt = $pdo->prepare("SELECT * FROM profile_customizations WHERE account_id=?"); $stmt->execute([$aid]);
    $custom = $stmt->fetch() ?: ['avatar_url'=>null,'banner_url'=>null,'banner_color'=>'#1a1a2e','bio'=>null];
    echo json_encode(['success'=>true,'data'=>$custom]);
    exit;
}

// API: Кастомизация профиля — сохранить
if ($path==='/api/profile/customization' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $avatarUrl    = trim($input['avatar_url'] ?? '');
    $bannerUrl    = trim($input['banner_url'] ?? '');
    $bannerColor  = trim($input['banner_color'] ?? '#1a1a2e');
    $bio          = mb_substr(trim($input['bio'] ?? ''), 0, 300);
    $pdo->prepare("INSERT INTO profile_customizations (account_id,avatar_url,banner_url,banner_color,bio,updated_at)
        VALUES (?,?,?,?,?,NOW())
        ON CONFLICT (account_id) DO UPDATE SET avatar_url=EXCLUDED.avatar_url, banner_url=EXCLUDED.banner_url, banner_color=EXCLUDED.banner_color, bio=EXCLUDED.bio, updated_at=NOW()")
        ->execute([(int)$account['id'], $avatarUrl?:null, $bannerUrl?:null, $bannerColor, $bio?:null]);
    echo json_encode(['success'=>true]);
    exit;
}

// API: Загрузка фото профиля (аватарка или баннер)
if ($path==='/api/profile/upload-image' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $type = trim($_GET['type'] ?? 'avatar'); // avatar | banner
    if (!in_array($type, ['avatar','banner'])) { echo json_encode(['success'=>false,'error'=>'Неверный тип']); exit; }
    if (empty($_FILES['image']['tmp_name'])) { echo json_encode(['success'=>false,'error'=>'Файл не загружен']); exit; }
    $file = $_FILES['image'];
    $maxSize = 8 * 1024 * 1024; // 8 MB
    if ($file['size'] > $maxSize) { echo json_encode(['success'=>false,'error'=>'Файл слишком большой (макс. 8 МБ)']); exit; }
    $allowedTypes = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedTypes)) { echo json_encode(['success'=>false,'error'=>'Неверный формат файла']); exit; }
    // Upload to ImgBB
    $imgbbKeys = ['58ff4596fd55028a81cbf8c4e38388e1','6981ba08e7b2a8743aab2c8ea008f675','f9b8d27fa4029816d643c7814fd60c60','24dbed2ae9fea9369de6a7b68d0c3ee6','c3e6a55335c71a052c1a59b6a2d6d150'];
    $imageData = base64_encode(file_get_contents($file['tmp_name']));
    $uploadedUrl = null;
    foreach ($imgbbKeys as $key) {
        $ch = curl_init('https://api.imgbb.com/1/upload');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['key'=>$key,'image'=>$imageData],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>false]);
        $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($httpCode === 200) { $data = json_decode($response, true); if (!empty($data['data']['url'])) { $uploadedUrl = $data['data']['url']; break; } }
    }
    if (!$uploadedUrl) { echo json_encode(['success'=>false,'error'=>'Ошибка загрузки на сервер']); exit; }
    // Save to DB
    $aid = (int)$account['id'];
    if ($type === 'avatar') {
        $pdo->prepare("INSERT INTO profile_customizations (account_id,avatar_url,updated_at) VALUES (?,?,NOW()) ON CONFLICT (account_id) DO UPDATE SET avatar_url=EXCLUDED.avatar_url, updated_at=NOW()")->execute([$aid,$uploadedUrl]);
    } else {
        $pdo->prepare("INSERT INTO profile_customizations (account_id,banner_url,updated_at) VALUES (?,?,NOW()) ON CONFLICT (account_id) DO UPDATE SET banner_url=EXCLUDED.banner_url, updated_at=NOW()")->execute([$aid,$uploadedUrl]);
    }
    echo json_encode(['success'=>true,'url'=>$uploadedUrl]);
    exit;
}

// API: Изменить никнейм
if ($path==='/api/profile/change-username' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $newUsername = trim($input['username'] ?? '');
    if (!$newUsername || strlen($newUsername)<3 || strlen($newUsername)>30) { echo json_encode(['success'=>false,'error'=>'Username: 3-30 символов']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $newUsername)) { echo json_encode(['success'=>false,'error'=>'Только буквы, цифры, _']); exit; }
    try {
        $check = $pdo->prepare("SELECT id FROM accounts WHERE username=? AND id!=?");
        $check->execute([$newUsername, (int)$account['id']]);
        if ($check->fetch()) { echo json_encode(['success'=>false,'error'=>'Этот никнейм уже занят']); exit; }
        $pdo->prepare("UPDATE accounts SET username=? WHERE id=?")->execute([$newUsername, (int)$account['id']]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'Ошибка сервера']); }
    exit;
}

// API: Изменить email (требует пароль + код на почту)
if ($path==='/api/profile/change-email' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $step = $input['step'] ?? 'request'; // request | confirm
    if ($step === 'request') {
        $password = $input['password'] ?? '';
        $newEmail = strtolower(trim($input['new_email'] ?? ''));
        if (!password_verify($password, $account['password_hash'])) { echo json_encode(['success'=>false,'error'=>'Неверный пароль']); exit; }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'error'=>'Неверный email']); exit; }
        $chk = $pdo->prepare("SELECT id FROM accounts WHERE email=? AND id!=?"); $chk->execute([$newEmail,(int)$account['id']]);
        if ($chk->fetch()) { echo json_encode(['success'=>false,'error'=>'Email уже занят']); exit; }
        $code = str_pad(random_int(0,999999),6,'0',STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time()+900);
        $pdo->prepare("INSERT INTO email_verifications (email,code,expires_at) VALUES (?,?,?)")->execute([$newEmail,$code,$expires]);
        // Store pending email in session data (we use a temp column approach)
        $pdo->prepare("UPDATE accounts SET verify_code=?, verify_expires=? WHERE id=?")->execute([$code,$expires,(int)$account['id']]);
        // send code to NEW email
        $html = "<div style='font-family:Outfit,sans-serif;background:#0c0c0c;padding:40px;border-radius:16px;max-width:480px;margin:auto'><div style='font-size:22px;font-weight:800;color:#f2f2f2;letter-spacing:2px;margin-bottom:8px'>⚫ BLACKWATCH</div><h2 style='color:#f2f2f2;font-size:18px;margin-bottom:16px'>Смена email</h2><p style='color:#aaa;font-size:14px;margin-bottom:20px'>Код подтверждения для смены email:</p><div style='background:#161616;border:1px solid #242424;border-radius:12px;padding:24px;text-align:center;margin-bottom:20px'><div style='font-size:36px;font-weight:800;letter-spacing:8px;color:#fff;font-family:monospace'>{$code}</div><div style='color:#666;font-size:12px;margin-top:8px'>Действителен 15 минут</div></div></div>";
        sendBrevoEmail($newEmail, $account['username'], 'Смена email | BLACKWATCH', $html);
        // Store new_email pending in session
        $_SESSION['pending_email_change'] = $newEmail;
        echo json_encode(['success'=>true,'message'=>'Код отправлен на новый email']);
    } else {
        $code = trim($input['code'] ?? '');
        $newEmail = $_SESSION['pending_email_change'] ?? '';
        if (!$newEmail) { echo json_encode(['success'=>false,'error'=>'Сессия истекла, начни заново']); exit; }
        $row = $pdo->prepare("SELECT verify_code,verify_expires FROM accounts WHERE id=?"); $row->execute([(int)$account['id']]); $r=$row->fetch();
        if (!$r || $r['verify_code'] !== $code) { echo json_encode(['success'=>false,'error'=>'Неверный код']); exit; }
        if (strtotime($r['verify_expires']) < time()) { echo json_encode(['success'=>false,'error'=>'Код истёк']); exit; }
        $pdo->prepare("UPDATE accounts SET email=?, verify_code=NULL, verify_expires=NULL WHERE id=?")->execute([$newEmail,(int)$account['id']]);
        unset($_SESSION['pending_email_change']);
        echo json_encode(['success'=>true]);
    }
    exit;
}

// API: Приватность профиля
if ($path==='/api/profile/privacy' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $mode = $input['mode'] ?? 'public'; // public | friends | private
    if (!in_array($mode,['public','friends','private'])) { echo json_encode(['success'=>false,'error'=>'Неверный режим']); exit; }
    try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS profile_privacy VARCHAR(20) DEFAULT 'public'"); } catch(Exception $e){}
    $pdo->prepare("UPDATE accounts SET profile_privacy=? WHERE id=?")->execute([$mode,(int)$account['id']]);
    echo json_encode(['success'=>true]);
    exit;
}

// API: Публичный профиль пользователя
if (preg_match('#^/api/profile/view/([a-zA-Z0-9_]+)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $targetUsername = $m[1];
    $stmt = $pdo->prepare("SELECT id,username,email,created_at,is_verified,tg_user_id,profile_privacy FROM accounts WHERE username=?");
    $stmt->execute([$targetUsername]); $target = $stmt->fetch();
    if (!$target) { echo json_encode(['success'=>false,'error'=>'Пользователь не найден']); exit; }
    $tid = (int)$target['id'];
    $privacy = $target['profile_privacy'] ?? 'public';
    // Check viewer
    $viewer = getCurrentAccount($pdo);
    $viewerIsAdmin = false;
    if ($viewer) {
        $vtg = (int)($viewer['tg_user_id']??0);
        $viewerIsAdmin = in_array($vtg,$hardcodedAdmins) || (!empty($viewer['is_admin'])&&$viewer['is_admin']);
    }
    $isFriend = false;
    if ($viewer && (int)$viewer['id'] !== $tid) {
        $fStmt = $pdo->prepare("SELECT id FROM friendships WHERE ((requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)) AND status='accepted'");
        $fStmt->execute([(int)$viewer['id'],$tid,$tid,(int)$viewer['id']]); $isFriend = (bool)$fStmt->fetch();
    }
    $isSelf = $viewer && (int)$viewer['id'] === $tid;
    $canView = $viewerIsAdmin || $isSelf || $privacy==='public' || ($privacy==='friends'&&$isFriend);
    // Get customization
    $custStmt = $pdo->prepare("SELECT * FROM profile_customizations WHERE account_id=?"); $custStmt->execute([$tid]);
    $custom = $custStmt->fetch()?:['avatar_url'=>null,'banner_url'=>null,'banner_color'=>'#1a1a2e','bio'=>null];
    // Admin check for target
    $tgid = (int)($target['tg_user_id']??0);
    $targetIsAdmin = in_array($tgid,$hardcodedAdmins) || (function() use($pdo,$tid){ try{$s=$pdo->prepare("SELECT is_admin FROM accounts WHERE id=? AND is_admin=TRUE");$s->execute([$tid]);return (bool)$s->fetch();}catch(Exception $e){return false;} })();
    $adminTag = null;
    try{$s=$pdo->prepare("SELECT admin_tag FROM accounts WHERE id=?");$s->execute([$tid]);$r=$s->fetch();$adminTag=$r['admin_tag']??null;}catch(Exception $e){}
    $data = [
        'id'=>$tid,'username'=>$target['username'],'created_at'=>$target['created_at'],
        'is_verified'=>(bool)$target['is_verified'],'is_admin'=>$targetIsAdmin,'admin_tag'=>$adminTag,
        'privacy'=>$privacy,'can_view_library'=>$canView,'is_friend'=>$isFriend,'is_self'=>$isSelf,
        'custom'=>$custom
    ];
    if ($canView) {
        // Stats — всегда публичные
        $stTotal=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=?");$stTotal->execute([$tid]);$data['total']=(int)$stTotal->fetchColumn();
        $stRead=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=? AND status='read'");$stRead->execute([$tid]);$data['read']=(int)$stRead->fetchColumn();
        $stNow=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=? AND status='now'");$stNow->execute([$tid]);$data['now']=(int)$stNow->fetchColumn();
        // Library items
        $libStmt=$pdo->prepare("SELECT m.id,m.title,m.cover_imgbb_url,s.status FROM user_manga_status s JOIN manga m ON s.manga_id=m.id WHERE s.account_id=? ORDER BY s.manga_id DESC LIMIT 30");
        $libStmt->execute([$tid]);$data['library']=$libStmt->fetchAll();
    } else {
        // Даже для приватных профилей — показываем базовую статистику
        $stTotal=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=?");$stTotal->execute([$tid]);$data['total']=(int)$stTotal->fetchColumn();
        $stRead=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=? AND status='read'");$stRead->execute([$tid]);$data['read']=(int)$stRead->fetchColumn();
        $stNow=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=? AND status='now'");$stNow->execute([$tid]);$data['now']=(int)$stNow->fetchColumn();
        $data['library'] = []; // библиотека скрыта
    }
    // Friends list (only if can_view or public)
    $fListStmt=$pdo->prepare("SELECT a.username,pc.avatar_url,f.status FROM friendships f JOIN accounts a ON (CASE WHEN f.requester_id=? THEN f.addressee_id ELSE f.requester_id END)=a.id LEFT JOIN profile_customizations pc ON pc.account_id=a.id WHERE (f.requester_id=? OR f.addressee_id=?) AND f.status='accepted' LIMIT 20");
    $fListStmt->execute([$tid,$tid,$tid]);$data['friends']=$fListStmt->fetchAll();
    // Friendship status with viewer
    if ($viewer && !$isSelf) {
        $fsStmt=$pdo->prepare("SELECT id,status,requester_id FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)");
        $fsStmt->execute([(int)$viewer['id'],$tid,$tid,(int)$viewer['id']]);$fs=$fsStmt->fetch();
        $data['friendship'] = $fs?['id'=>$fs['id'],'status'=>$fs['status'],'is_mine'=>(int)$fs['requester_id']===(int)$viewer['id']]:null;
    }
    echo json_encode(['success'=>true,'data'=>$data]);
    exit;
}

// API: Ежемесячная переаутентификация — проверить нужно ли
if ($path==='/api/auth/reauth-check' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['needs_reauth'=>false]); exit; }
    $lastConfirm = $_SESSION['last_reauth_confirm'] ?? 0;
    $needs = (time() - $lastConfirm) > (30*24*3600);
    echo json_encode(['needs_reauth'=>$needs]);
    exit;
}

// API: Ежемесячная переаутентификация — подтвердить
if ($path==='/api/auth/reauth-confirm' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $step = $input['step'] ?? 'password';
    if ($step === 'password') {
        $password = $input['password'] ?? '';
        if (!password_verify($password, $account['password_hash'])) { echo json_encode(['success'=>false,'error'=>'Неверный пароль']); exit; }
        // Send code to email
        $code = str_pad(random_int(0,999999),6,'0',STR_PAD_LEFT);
        $_SESSION['reauth_code'] = $code;
        $_SESSION['reauth_code_expires'] = time()+600;
        $html = "<div style='font-family:Outfit,sans-serif;background:#0c0c0c;padding:40px;border-radius:16px;max-width:480px;margin:auto'><div style='font-size:22px;font-weight:800;color:#f2f2f2;letter-spacing:2px;margin-bottom:8px'>⚫ BLACKWATCH</div><h2 style='color:#f2f2f2;font-size:18px;margin-bottom:16px'>Подтверждение входа</h2><p style='color:#aaa;font-size:14px;margin-bottom:20px'>Ежемесячное подтверждение аккаунта. Твой код:</p><div style='background:#161616;border:1px solid #242424;border-radius:12px;padding:24px;text-align:center'><div style='font-size:36px;font-weight:800;letter-spacing:8px;color:#fff;font-family:monospace'>{$code}</div><div style='color:#666;font-size:12px;margin-top:8px'>Действителен 10 минут</div></div></div>";
        sendBrevoEmail($account['email'],$account['username'],'Подтверждение сессии | BLACKWATCH',$html);
        echo json_encode(['success'=>true,'message'=>'Код отправлен на email']);
    } else {
        $code = trim($input['code']??'');
        if (!isset($_SESSION['reauth_code']) || $_SESSION['reauth_code']!==$code || time()>($_SESSION['reauth_code_expires']??0)) {
            echo json_encode(['success'=>false,'error'=>'Неверный или истёкший код']); exit;
        }
        $_SESSION['last_reauth_confirm'] = time();
        unset($_SESSION['reauth_code'],$_SESSION['reauth_code_expires']);
        echo json_encode(['success'=>true]);
    }
    exit;
}

// API: Назначить админа по TG ID (для суперадминов)
if ($path==='/api/admin/assign-by-tgid' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $superAdmins = [1710365896, 1181510470];
    $tgId = (int)($account['tg_user_id']??0);
    if (!in_array($tgId,$superAdmins)) { echo json_encode(['success'=>false,'error'=>'Нет прав суперадмина']); exit; }
    $input = json_decode(file_get_contents('php://input'),true);
    $targetTgId = (int)($input['tg_id']??0);
    $tagName = trim($input['tag']??'Администратор');
    $action = $input['action']??'add';
    if (!$targetTgId) { echo json_encode(['success'=>false,'error'=>'Укажи TG ID']); exit; }
    // Check if account with this TG ID exists
    $accStmt=$pdo->prepare("SELECT id,username,email FROM accounts WHERE tg_user_id=?");$accStmt->execute([$targetTgId]);$targetAcc=$accStmt->fetch();
    if (!$targetAcc) { echo json_encode(['success'=>false,'error'=>'Пользователь с таким TG ID не привязан к аккаунту на сайте']); exit; }
    try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS is_admin BOOLEAN DEFAULT FALSE"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS admin_tag VARCHAR(100) DEFAULT NULL"); } catch(Exception $e){}
    if ($action==='add') {
        $pdo->prepare("INSERT INTO bot_admins (user_id) VALUES (?) ON CONFLICT DO NOTHING")->execute([$targetTgId]);
        $pdo->prepare("INSERT INTO admin_tags (user_id,tag_name) VALUES (?,?) ON CONFLICT (user_id) DO UPDATE SET tag_name=EXCLUDED.tag_name")->execute([$targetTgId,$tagName]);
        $pdo->prepare("UPDATE accounts SET is_admin=TRUE, admin_tag=? WHERE id=?")->execute([$tagName,(int)$targetAcc['id']]);
        sendTgNotify($targetTgId,"⚡ <b>Ты назначен администратором!</b>\n\nТег: <b>{$tagName}</b>\n\n🌐 Зайди на сайт чтобы открыть панель управления.");
        echo json_encode(['success'=>true,'message'=>"Админ {$targetAcc['username']} добавлен"]);
    } else {
        $pdo->prepare("DELETE FROM bot_admins WHERE user_id=?")->execute([$targetTgId]);
        $pdo->prepare("DELETE FROM admin_tags WHERE user_id=?")->execute([$targetTgId]);
        $pdo->prepare("UPDATE accounts SET is_admin=FALSE, admin_tag=NULL WHERE id=?")->execute([(int)$targetAcc['id']]);
        sendTgNotify($targetTgId,"❌ Твои права администратора были сняты.");
        echo json_encode(['success'=>true,'message'=>"Права сняты с {$targetAcc['username']}"]);
    }
    exit;
}

// API: Поиск пользователей для добавления в друзья
if ($path==='/api/users/search' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'users'=>[]]); exit; }
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['success'=>true,'users'=>[]]); exit; }
    $aid = (int)$account['id'];
    $stmt = $pdo->prepare("SELECT a.id, a.username, pc.avatar_url FROM accounts a LEFT JOIN profile_customizations pc ON pc.account_id=a.id WHERE a.username ILIKE ? AND a.id != ? LIMIT 8");
    $stmt->execute(["$q%", $aid]);
    $users = $stmt->fetchAll();
    echo json_encode(['success'=>true,'users'=>$users]);
    exit;
}

// API: Назначить/снять админа (только суперадмины)
if ($path==='/api/admin/assign' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $superAdmins = [1710365896, 1181510470];
    $tgId = (int)($account['tg_user_id'] ?? 0);
    if (!in_array($tgId, $superAdmins)) { echo json_encode(['success'=>false,'error'=>'Нет прав суперадмина']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $targetEmail = strtolower(trim($input['email'] ?? ''));
    $tagName = trim($input['tag'] ?? 'Администратор');
    $action = $input['action'] ?? 'add'; // add | remove
    if (!$targetEmail) { echo json_encode(['success'=>false,'error'=>'Укажи email']); exit; }
    $targetStmt = $pdo->prepare("SELECT id, username, tg_user_id, email FROM accounts WHERE email=?");
    $targetStmt->execute([$targetEmail]); $target = $targetStmt->fetch();
    if (!$target) { echo json_encode(['success'=>false,'error'=>'Пользователь не найден']); exit; }
    $targetAccountId = (int)$target['id'];
    $targetTgId = $target['tg_user_id'] ? (int)$target['tg_user_id'] : null;
    if ($action === 'add') {
        // Add to bot_admins if TG linked
        if ($targetTgId) {
            $pdo->prepare("INSERT INTO bot_admins (user_id) VALUES (?) ON CONFLICT DO NOTHING")->execute([$targetTgId]);
            $pdo->prepare("INSERT INTO admin_tags (user_id,tag_name) VALUES (?,?) ON CONFLICT (user_id) DO UPDATE SET tag_name=EXCLUDED.tag_name")->execute([$targetTgId,$tagName]);
        }
        // Store admin assignment with account_id reference
        // We use a flag in accounts table - add column if not exists
        try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS is_admin BOOLEAN DEFAULT FALSE"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS admin_tag VARCHAR(100) DEFAULT NULL"); } catch(Exception $e) {}
        $pdo->prepare("UPDATE accounts SET is_admin=TRUE, admin_tag=? WHERE id=?")->execute([$tagName, $targetAccountId]);
        // Send email notification
        $html = "
        <div style='font-family:Outfit,sans-serif;background:#0c0c0c;padding:40px;border-radius:16px;max-width:480px;margin:auto'>
          <div style='font-family:Syne,sans-serif;font-size:22px;font-weight:800;color:#f2f2f2;letter-spacing:2px;margin-bottom:8px'>⚫ BLACKWATCH</div>
          <h2 style='color:#ef4444;font-size:18px;margin-bottom:16px'>⚡ Ты назначен администратором</h2>
          <p style='color:#aaa;font-size:14px;margin-bottom:20px'>Привет, <strong style='color:#f2f2f2'>{$target['username']}</strong>! Тебе выдан тег администратора:</p>
          <div style='background:#161616;border:1px solid rgba(239,68,68,0.4);border-radius:12px;padding:20px;text-align:center;margin-bottom:20px'>
            <div style='font-size:20px;font-weight:800;color:#ef4444;letter-spacing:2px'>{$tagName}</div>
          </div>
          <p style='color:#aaa;font-size:13px;margin-bottom:10px'>Теперь тебе доступна <strong style='color:#f2f2f2'>Админ-панель</strong> на сайте".($targetTgId?' и в Telegram боте':'').".</p>
          ".(!$targetTgId?'<p style="color:#fb923c;font-size:12px">⚠️ Для доступа к Telegram боту привяжи свой аккаунт Telegram на странице профиля.</p>':'')."
        </div>";
        sendBrevoEmail($target['email'], $target['username'], '⚡ Ты назначен администратором | BLACKWATCH', $html);
        // Send TG notification
        if ($targetTgId) {
            sendTgNotify($targetTgId, "⚡ <b>Ты назначен администратором!</b>\n\nТег: <b>{$tagName}</b>\n\n🌐 Зайди на сайт чтобы открыть панель управления.");
        }
        echo json_encode(['success'=>true,'message'=>"Администратор назначен. Уведомление отправлено на {$target['email']}".($targetTgId?" и в Telegram":'')]);
    } else {
        // Remove admin
        if ($targetTgId) {
            $pdo->prepare("DELETE FROM bot_admins WHERE user_id=?")->execute([$targetTgId]);
            $pdo->prepare("DELETE FROM admin_tags WHERE user_id=?")->execute([$targetTgId]);
        }
        try { $pdo->prepare("UPDATE accounts SET is_admin=FALSE, admin_tag=NULL WHERE id=?")->execute([$targetAccountId]); } catch(Exception $e) {}
        if ($targetTgId) sendTgNotify($targetTgId, "❌ Твои права администратора были сняты.");
        echo json_encode(['success'=>true,'message'=>'Права администратора сняты']);
    }
    exit;
}

// API: Список администраторов (расширенный)
if ($path==='/api/admin/list-all' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['error'=>'Нет прав']); exit; }
    $tgId = (int)($account['tg_user_id'] ?? 0);
    if (!isAdmin($tgId, $hardcodedAdmins)) { echo json_encode(['error'=>'Нет прав']); exit; }
    // Get all admins from bot_admins + accounts.is_admin
    try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS is_admin BOOLEAN DEFAULT FALSE"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS admin_tag VARCHAR(100) DEFAULT NULL"); } catch(Exception $e) {}
    $superAdmins = [1710365896, 1181510470];
    $admins = [];
    // Bot admins
    $stmt = $pdo->query("SELECT ba.user_id, at.tag_name, a.username, a.email FROM bot_admins ba LEFT JOIN admin_tags at ON at.user_id=ba.user_id LEFT JOIN accounts a ON a.tg_user_id=ba.user_id");
    foreach ($stmt->fetchAll() as $row) {
        $isSuper = in_array((int)$row['user_id'], $superAdmins);
        $admins[(int)$row['user_id']] = ['tg_id'=>(int)$row['user_id'],'tag'=>$row['tag_name']??'Администратор','username'=>$row['username']??null,'email'=>$row['email']??null,'is_super'=>$isSuper,'source'=>'bot'];
    }
    // Super admins (hardcoded)
    foreach ($superAdmins as $sid) {
        if (!isset($admins[$sid])) {
            $accR = $pdo->prepare("SELECT username,email FROM accounts WHERE tg_user_id=?"); $accR->execute([$sid]); $ar = $accR->fetch();
            $admins[$sid] = ['tg_id'=>$sid,'tag'=>'Суперадмин','username'=>$ar['username']??null,'email'=>$ar['email']??null,'is_super'=>true,'source'=>'hardcoded'];
        } else {
            $admins[$sid]['is_super'] = true; $admins[$sid]['tag'] = 'Суперадмин';
        }
    }
    echo json_encode(['admins'=>array_values($admins)]);
    exit;
}

// API: Друзья — список
if ($path==='/api/friends' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'friends'=>[]]); exit; }
    $aid = (int)$account['id'];
    $stmt = $pdo->prepare("
        SELECT a.id, a.username, f.status, f.requester_id,
               (SELECT avatar_url FROM profile_customizations WHERE account_id=a.id) as avatar_url
        FROM friendships f
        JOIN accounts a ON (CASE WHEN f.requester_id=? THEN f.addressee_id ELSE f.requester_id END)=a.id
        WHERE (f.requester_id=? OR f.addressee_id=?) AND f.status IN ('accepted','pending')
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$aid,$aid,$aid]);
    $rows = $stmt->fetchAll();
    foreach($rows as &$r) { $r['is_mine'] = ((int)$r['requester_id'] === $aid); } unset($r);
    echo json_encode(['success'=>true,'friends'=>$rows]);
    exit;
}

// API: Добавить в друзья
if ($path==='/api/friends/add' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $targetId = (int)($input['target_id'] ?? 0);
    if ($targetId) {
        $target = $pdo->prepare("SELECT id FROM accounts WHERE id=?"); $target->execute([$targetId]); $row = $target->fetch();
    } else {
        $target = $pdo->prepare("SELECT id FROM accounts WHERE username=?"); $target->execute([$username]); $row = $target->fetch();
    }
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Пользователь не найден']); exit; }
    $tid = (int)$row['id']; $aid = (int)$account['id'];
    if ($tid === $aid) { echo json_encode(['success'=>false,'error'=>'Нельзя добавить себя']); exit; }
    try {
        $pdo->prepare("INSERT INTO friendships (requester_id,addressee_id,status) VALUES (?,?,'pending') ON CONFLICT DO NOTHING")->execute([$aid,$tid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'Уже отправлено']); }
    exit;
}

// API: Принять/отклонить запрос в друзья
if (preg_match('#^/api/friends/(\d+)/(accept|reject|remove)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $account = getCurrentAccount($pdo);
    if (!$account) { echo json_encode(['success'=>false]); exit; }
    $fid = (int)$m[1]; $action = $m[2]; $aid = (int)$account['id'];
    if ($action === 'accept') {
        $pdo->prepare("UPDATE friendships SET status='accepted' WHERE id=? AND addressee_id=?")->execute([$fid,$aid]);
    } elseif ($action === 'reject') {
        $pdo->prepare("DELETE FROM friendships WHERE id=? AND (addressee_id=? OR requester_id=?)")->execute([$fid,$aid,$aid]);
    } elseif ($action === 'remove') {
        $pdo->prepare("DELETE FROM friendships WHERE id=?")->execute([$fid]);
    }
    echo json_encode(['success'=>true]);
    exit;
}

// Логаут
if ($path==='/logout') {
    destroySession($pdo);
    header('Location: /');
    exit;
}

# ========================= AUTH PAGES =========================

// /login
if ($path==='/login') {
    if (getCurrentAccount($pdo)) { header('Location: /'); exit; }
    $redirect = htmlspecialchars($_GET['redirect'] ?? '/');
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0a0a;--card:#141414;--border:#1e1e1e;--border2:#2a2a2a;--text:#f0f0f0;--text2:#b8b8b8;--muted:#555;--accent:#d0d0d0}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;position:relative}
.box{background:var(--card);border:1px solid var(--border);border-radius:22px;padding:38px 34px;width:100%;max-width:400px;position:relative;z-index:1}
.logo{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;letter-spacing:3px;color:var(--text2);text-decoration:none;display:block;text-align:center;margin-bottom:30px;text-transform:uppercase}
h1{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:6px;text-align:center}
.sub{color:var(--muted);font-size:13px;text-align:center;margin-bottom:28px}
.fg{margin-bottom:14px}
label{display:block;font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.7px;margin-bottom:5px}
input[type=text],input[type=email],input[type=password]{width:100%;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:11px;color:var(--text);font-family:'Inter',sans-serif;font-size:14px;padding:12px 14px;outline:none;transition:border-color .15s}
input:focus{border-color:var(--border2)}
.remember{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;cursor:pointer;margin-bottom:20px}
.remember input{width:auto;accent-color:var(--accent)}
.btn{width:100%;padding:13px;background:var(--text);color:var(--bg);border:none;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:opacity .15s;margin-bottom:14px}
.btn:hover{opacity:.88}
.btn:disabled{opacity:.4;cursor:not-allowed}
.err{background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.22);border-radius:10px;padding:10px 14px;color:#fca5a5;font-size:12px;margin-bottom:14px;display:none}
.err.show{display:block}
.link{text-align:center;font-size:13px;color:var(--muted)}
.link a{color:var(--text2);text-decoration:none;font-weight:500}
.link a:hover{color:var(--text)}
.divider{display:flex;align-items:center;gap:10px;margin:16px 0;color:var(--muted);font-size:11px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}
</style>
</head>
<body>
<div class="box">
    <a href="/" class="logo">⚫ BLACKWATCH</a>
    <h1>Добро пожаловать</h1>
    <p class="sub">Войди чтобы читать мангу</p>
    <div class="err" id="err"></div>
    <div class="fg"><label>Email или Username</label><input type="text" id="login" placeholder="user@mail.com или username" autocomplete="username"></div>
    <div class="fg"><label>Пароль</label><input type="password" id="password" placeholder="••••••••" autocomplete="current-password"></div>
    <label class="remember"><input type="checkbox" id="remember" checked> Запомнить меня</label>
    <button class="btn" id="btn" onclick="doLogin()">Войти</button>
    <div class="divider">или</div>
    <div class="link">Нет аккаунта? <a href="/register">Зарегистрироваться</a></div>
</div>
<script>
const redirect = <?=json_encode($redirect)?>;
async function doLogin() {
    const btn = document.getElementById('btn');
    const err = document.getElementById('err');
    err.classList.remove('show');
    btn.disabled = true; btn.textContent = 'Входим...';
    try {
        const res = await fetch('/api/auth/login', {method:'POST', credentials:'include', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                login: document.getElementById('login').value.trim(),
                password: document.getElementById('password').value,
                remember: document.getElementById('remember').checked
            })
        });
        const d = await res.json();
        if (d.success) { window.location.href = redirect || '/'; }
        else { err.textContent = d.error || 'Ошибка'; err.classList.add('show'); }
    } catch(e) { err.textContent = 'Ошибка сети'; err.classList.add('show'); }
    btn.disabled = false; btn.textContent = 'Войти';
}
document.addEventListener('keydown', e => { if(e.key==='Enter') doLogin(); });
</script>
</body></html><?php exit; }

// /register
if ($path==='/register') {
    if (getCurrentAccount($pdo)) { header('Location: /'); exit; }
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Регистрация | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/style.css">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#e0e0e0;--green:#4ade80}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;position:relative}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(255,255,255,0.03) 0%,transparent 60%);pointer-events:none}
.box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px 32px;width:100%;max-width:420px;position:relative;z-index:1}
.logo{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;letter-spacing:2px;color:var(--text2);text-decoration:none;display:block;text-align:center;margin-bottom:28px;opacity:0.85}
h1{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:6px;text-align:center}
.sub{color:var(--muted);font-size:13px;text-align:center;margin-bottom:26px;line-height:1.6}
.fg{margin-bottom:13px;position:relative}
label{display:block;font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:5px}
input{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Inter',sans-serif;font-size:14px;padding:11px 14px;outline:none;transition:border-color .2s}
input:focus{border-color:var(--border2)}
input.valid{border-color:rgba(74,222,128,.4)}
input.invalid{border-color:rgba(248,113,113,.4)}
.hint{font-size:10px;color:var(--muted);margin-top:4px}
.hint.ok{color:var(--green)}
.hint.bad{color:#f87171}
.btn{width:100%;padding:13px;background:var(--text);color:var(--bg);border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:opacity .2s;margin-bottom:12px;margin-top:6px}
.btn:hover{opacity:.88}
.btn:disabled{opacity:.4;cursor:not-allowed}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);border-radius:9px;padding:10px 14px;color:#fca5a5;font-size:12px;margin-bottom:14px;display:none}
.err.show{display:block}
.link{text-align:center;font-size:13px;color:var(--muted)}
.link a{color:var(--text2);text-decoration:none;font-weight:500}
.divider{display:flex;align-items:center;gap:10px;margin:16px 0;color:var(--muted);font-size:11px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}
</style>
</head>
<body>
<div class="box">
    <a href="/" class="logo">⚫ BLACKWATCH</a>
    <h1>Создать аккаунт</h1>
    <p class="sub">Регистрация даёт доступ ко всей манге<br>Telegram можно привязать позже</p>
    <div class="err" id="err"></div>
    <div class="fg"><label>Email</label><input type="email" id="email" placeholder="user@mail.com" oninput="validateEmail()" autocomplete="email"><div class="hint" id="hint-email"></div></div>
    <div class="fg"><label>Username</label><input type="text" id="username" placeholder="coolreader123" maxlength="30" oninput="validateUsername()" autocomplete="username"><div class="hint" id="hint-user">3-30 символов, только a-z, 0-9, _</div></div>
    <div class="fg"><label>Пароль</label><input type="password" id="password" placeholder="Минимум 6 символов" oninput="validatePass()" autocomplete="new-password"><div class="hint" id="hint-pass"></div></div>
    <div class="fg"><label>Подтверждение пароля</label><input type="password" id="confirm" placeholder="Повтори пароль" oninput="validateConfirm()" autocomplete="new-password"><div class="hint" id="hint-confirm"></div></div>
    <button class="btn" id="btn" onclick="doRegister()">Зарегистрироваться</button>
    <div class="divider">или</div>
    <div class="link">Уже есть аккаунт? <a href="/login">Войти</a></div>
</div>
<script>
function validateEmail(){const v=document.getElementById('email').value;const ok=v&&/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);setHint('hint-email',document.getElementById('email'),ok?'✓ Отлично':'','',ok);}
function validateUsername(){const v=document.getElementById('username').value;const ok=/^[a-zA-Z0-9_]{3,30}$/.test(v);setHint('hint-user',document.getElementById('username'),ok?'✓ Отлично':'3-30 символов, только a-z, 0-9, _','3-30 символов, только a-z, 0-9, _',ok);}
function validatePass(){const v=document.getElementById('password').value;const ok=v.length>=6;setHint('hint-pass',document.getElementById('password'),ok?'✓ Надёжный':'Минимум 6 символов','Минимум 6 символов',ok);validateConfirm();}
function validateConfirm(){const v=document.getElementById('confirm').value;const p=document.getElementById('password').value;const ok=v&&v===p;setHint('hint-confirm',document.getElementById('confirm'),ok?'✓ Совпадает':'Пароли не совпадают','',ok&&v.length>0);}
function setHint(hintId,input,okText,badText,ok){const h=document.getElementById(hintId);if(input.value){input.classList.toggle('valid',ok);input.classList.toggle('invalid',!ok);h.textContent=ok?okText:badText;h.className='hint '+(ok?'ok':'bad');}else{input.classList.remove('valid','invalid');h.textContent=badText||'';h.className='hint';}}
async function doRegister(){
    const btn=document.getElementById('btn');const err=document.getElementById('err');
    err.classList.remove('show');btn.disabled=true;btn.textContent='Создаём аккаунт...';
    try{
        const res=await fetch('/api/auth/register',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({
            email:document.getElementById('email').value.trim(),
            username:document.getElementById('username').value.trim(),
            password:document.getElementById('password').value,
            confirm:document.getElementById('confirm').value
        })});
        const d=await res.json();
        if(d.success){window.location.href='/verify-email';}
        else{err.textContent=d.error||'Ошибка';err.classList.add('show');}
    }catch(e){err.textContent='Ошибка сети';err.classList.add('show');}
    btn.disabled=false;btn.textContent='Зарегистрироваться';
}
document.addEventListener('keydown',e=>{if(e.key==='Enter')doRegister();});
</script>
</body></html><?php exit; }

// /messages — страница с историей чатов
if ($path === '/messages' || preg_match('~^/messages\?with=(\d+)~', $path, $m)) {
    if (!$currentAccount) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header("Location: /login?redirect={$redirect}");
        exit;
    }
    
    $other_id = isset($m[1]) ? (int)$m[1] : (isset($_GET['with']) ? (int)$_GET['with'] : null);
    
    // Проверка существования пользователя
    if ($other_id) {
        $checkStmt = $pdo->prepare("SELECT id, username FROM accounts WHERE id=?");
        $checkStmt->execute([$other_id]);
        $otherUser = $checkStmt->fetch();
        if (!$otherUser) $other_id = null;
    }
    
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Сообщения | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#7c5cff;--green:#4ade80}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh}
.page-header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:100;background:rgba(12,12,12,.92);backdrop-filter:blur(12px)}
.back-btn{color:var(--muted);text-decoration:none;font-size:13px;transition:color .2s}.back-btn:hover{color:var(--text)}
.page-title{font-family:'Syne',sans-serif;font-weight:800;font-size:17px}
.messages-container{display:flex;height:calc(100vh - 53px)}
.msg-list{width:280px;border-right:1px solid var(--border);overflow-y:auto;background:rgba(0,0,0,.5)}
.msg-list-empty{padding:24px 16px;text-align:center;color:var(--muted);font-size:13px}
.msg-item{padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s}
.msg-item:hover{background:rgba(124,92,255,.08)}
.msg-item.active{background:rgba(124,92,255,.15);border-left:3px solid var(--accent)}
.msg-item-name{font-weight:600;color:var(--text);font-size:13px;margin-bottom:4px}
.msg-item-text{font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-item-time{font-size:10px;color:var(--muted);margin-top:3px}
.msg-chat{flex:1;display:flex;flex-direction:column}
.msg-empty{flex:1;display:flex;align-items:center;justify-content:center;color:var(--muted);text-align:center;padding:24px}
.msg-scroll{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px}
.msg-row{display:flex;gap:8px;margin-bottom:12px;animation:msg-in .2s ease}
@keyframes msg-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.msg-bubble{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px 12px;max-width:70%;word-wrap:break-word;font-size:13px;line-height:1.4}
.msg-bubble.own{background:var(--accent);color:#000;border-color:var(--accent)}
.msg-row.own{justify-content:flex-end}
.msg-footer{padding:16px;border-top:1px solid var(--border);display:flex;gap:8px}
.msg-input{flex:1;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 12px;font-family:inherit;font-size:13px;outline:none}
.msg-input:focus{border-color:var(--border2)}
.msg-send{padding:9px 16px;background:var(--accent);color:#000;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
.msg-send:hover{opacity:.9}
.msg-send:disabled{opacity:.4;cursor:not-allowed}
@media(max-width:768px){.messages-container{flex-direction:column}.msg-list{width:100%;height:auto;max-height:120px;border-right:none;border-bottom:1px solid var(--border)}.msg-bubble{max-width:90%}}
</style>
</head>
<body>
<div class="page-header">
    <a href="/" class="back-btn">← Назад</a>
    <h1 class="page-title">💬 Сообщения</h1>
</div>
<div class="messages-container">
    <div class="msg-list" id="msg-list"></div>
    <div class="msg-chat">
        <div class="msg-empty" id="msg-empty">Выберите чат или начните новую переписку</div>
        <div class="msg-scroll" id="msg-scroll" style="display:none"></div>
        <div class="msg-footer" id="msg-footer" style="display:none">
            <input type="text" class="msg-input" id="msg-input" placeholder="Напиши сообщение...">
            <button class="msg-send" id="msg-send">Отправить</button>
        </div>
    </div>
</div>
<script>
const currentUserId = <?=(int)$currentAccount['id']?>;
const otherId = <?=($other_id ?? 'null')?>;

async function loadConversations(){
    try{const res=await fetch('/api/messages');const d=await res.json();
    if(!d.success||!d.messages)return;
    const list=document.getElementById('msg-list');
    if(d.messages.length===0){list.innerHTML='<div class="msg-list-empty">Нет сообщений</div>';return;}
    list.innerHTML=d.messages.map(m=>`
        <div class="msg-item ${otherId===m.other_id?'active':''}" onclick="selectChat(${m.other_id})">
            <div class="msg-item-name">${escapeHtml(m.other_username)}</div>
            <div class="msg-item-text">${escapeHtml(m.last_text||'...')}</div>
            <div class="msg-item-time">${new Date(m.last_message_time).toLocaleDateString()}</div>
        </div>`).join('');
    }catch(e){}
}

function selectChat(uid){window.location.href='/messages?with='+uid;}

async function loadMessages(){
    if(!otherId)return;
    try{const res=await fetch('/api/messages/'+otherId);const d=await res.json();
    if(!d.success||!d.messages)return;
    const scroll=document.getElementById('msg-scroll');
    scroll.innerHTML=d.messages.map(m=>`
        <div class="msg-row ${m.sender_id===currentUserId?'own':''}">
            <div class="msg-bubble ${m.sender_id===currentUserId?'own':''}">${escapeHtml(m.text)}</div>
        </div>`).join('');
    scroll.scrollTop=scroll.scrollHeight;
    }catch(e){}
}

async function sendMessage(){
    const input=document.getElementById('msg-input');const text=input.value.trim();
    if(!text||!otherId)return;
    input.value='';document.getElementById('msg-send').disabled=true;
    try{const res=await fetch('/api/messages',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({recipient_id:otherId,text})});
    const d=await res.json();
    if(d.success){loadMessages();loadConversations();}
    }catch(e){}
    document.getElementById('msg-send').disabled=false;
}

function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

if(otherId){
    document.getElementById('msg-empty').style.display='none';
    document.getElementById('msg-scroll').style.display='flex';
    document.getElementById('msg-footer').style.display='flex';
    loadMessages();
}
document.getElementById('msg-send').addEventListener('click',sendMessage);
document.getElementById('msg-input').addEventListener('keypress',e=>{if(e.key==='Enter')sendMessage();});
loadConversations();
setInterval(()=>{if(otherId)loadMessages();},2000);
</script>
</body>
</html><?php exit; }

// /profile

// /profile
if ($path==='/profile') {
    $account = requireAuth($pdo);
    $botUsername = getenv('BOT_USERNAME') ?: 'blackwatch_manga_bot';
    $isAccountAdmin = in_array((int)($account['tg_user_id'] ?? 0), $hardcodedAdmins)
        || (!empty($account['is_admin']) && $account['is_admin']);
    $isSuperAdmin = in_array((int)($account['tg_user_id'] ?? 0), [1710365896, 1181510470]);
    $accountAdminTag = $account['admin_tag'] ?? null;
    // Load profile customization
    $custStmt = $pdo->prepare("SELECT * FROM profile_customizations WHERE account_id=?");
    $custStmt->execute([(int)$account['id']]);
    $custom = $custStmt->fetch() ?: ['avatar_url'=>null,'banner_url'=>null,'banner_color'=>'#1a1a2e','bio'=>null];
    // Load friends count
    $friendsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE (requester_id=? OR addressee_id=?) AND status='accepted'");
    $friendsCountStmt->execute([(int)$account['id'],(int)$account['id']]);
    $friendsCount = (int)$friendsCountStmt->fetchColumn();
    // Reauth check
    $needsReauth = false;
    if ($account) {
        $lastConfirm = $_SESSION['last_reauth_confirm'] ?? 0;
        $needsReauth = (time() - $lastConfirm) > (30*24*3600);
    }
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Профиль | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0a0a;--card:#141414;--border:#1e1e1e;--border2:#2a2a2a;--text:#f0f0f0;--text2:#b8b8b8;--muted:#555;--accent:#d0d0d0;--green:#4ade80;--orange:#fb923c;--red:#f87171}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;padding:24px 16px}
.back{display:inline-flex;align-items:center;gap:7px;color:var(--muted);text-decoration:none;font-size:13px;margin-bottom:22px;transition:color .18s}
.back:hover{color:var(--text)}
.wrap{max-width:560px;margin:0 auto;position:relative}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;margin-bottom:12px;overflow:hidden}
.profile-banner{width:100%;height:115px;background:<?=htmlspecialchars($custom['banner_color']??'#111')?>;background-size:cover;background-position:center;position:relative;overflow:hidden}
.profile-banner-img{width:100%;height:100%;object-fit:cover;display:block}
.avatar-wrap{position:relative;margin-top:-44px;margin-left:20px;display:inline-block;z-index:2}
.avatar{width:84px;height:84px;border-radius:50%;background:#1a1a1a;border:3px solid var(--card);display:flex;align-items:center;justify-content:center;font-size:34px;overflow:hidden;flex-shrink:0}
.avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.profile-header-row{display:flex;align-items:flex-start;justify-content:space-between;padding:0 20px 16px}
.profile-name-col{flex:1;min-width:0}
.edit-profile-btn{padding:8px 16px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--text2);font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:6px;flex-shrink:0;margin-top:10px;margin-left:12px}
.edit-profile-btn:hover{border-color:var(--border2);color:var(--text);background:rgba(255,255,255,0.03)}
.admin-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.35);color:#ef4444;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;letter-spacing:0.5px;text-transform:uppercase;margin-left:8px;vertical-align:middle}
.verify-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(74,222,128,0.09);border:1px solid rgba(74,222,128,0.28);color:var(--green);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;margin-left:6px;vertical-align:middle}
.username{font-family:'Syne',sans-serif;font-size:21px;font-weight:800;color:var(--text);margin-bottom:3px;margin-top:12px}
.email{font-size:12px;color:var(--muted);margin-bottom:8px}
.bio-text{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:10px}
.joined{font-size:11px;color:var(--muted);background:rgba(255,255,255,.03);border:1px solid var(--border);padding:3px 10px;border-radius:7px;display:inline-block}
.sec-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:13px;padding:20px 22px 0}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 22px 20px}
.stat{display:flex;flex-direction:column;align-items:center;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:12px;padding:14px 8px;text-decoration:none;color:var(--text);transition:all .15s}
.stat:hover{border-color:var(--border2);background:rgba(255,255,255,.04)}
.stat-n{font-size:22px;font-weight:800;font-family:'Syne',sans-serif;color:var(--text);line-height:1}
.stat-l{font-size:10px;color:var(--muted);margin-top:4px;font-weight:500;text-align:center}
.profile-link{font-size:12px;color:var(--muted);text-decoration:none;display:inline-flex;align-items:center;gap:5px;margin-top:6px;transition:color .15s}
.profile-link:hover{color:var(--text2)}
.verify-banner{background:rgba(251,146,60,0.06);border:1px solid rgba(251,146,60,0.18);border-radius:14px;padding:14px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px}
.verify-icon{font-size:22px;flex-shrink:0}
.verify-info{flex:1}
.verify-info p{font-size:12px;color:var(--text2);margin-bottom:8px;line-height:1.5}
.verify-input-row{display:flex;gap:7px}
.verify-input-row input{flex:1;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:14px;padding:8px 12px;font-family:monospace;letter-spacing:4px;text-align:center;outline:none}
.verify-input-row button{padding:8px 14px;background:var(--orange);border:none;border-radius:9px;color:#000;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap}
.resend-link{font-size:11px;color:var(--muted);cursor:pointer;text-decoration:underline;margin-top:5px;display:inline-block}
.reauth-banner{background:rgba(180,180,180,0.06);border:1px solid rgba(180,180,180,0.15);border-radius:14px;padding:14px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px}
.privacy-row{display:flex;gap:6px;padding:0 22px 20px}
.privacy-btn{flex:1;padding:9px;background:transparent;border:1px solid var(--border);border-radius:9px;color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;text-align:center}
.privacy-btn.active-public{background:rgba(74,222,128,0.07);border-color:rgba(74,222,128,0.28);color:var(--green)}
.privacy-btn.active-friends{background:rgba(200,200,200,0.07);border-color:rgba(200,200,200,0.2);color:var(--text2)}
.privacy-btn.active-private{background:rgba(248,113,113,0.05);border-color:rgba(248,113,113,0.22);color:var(--red)}
.tg-inner{padding:20px 22px}
.tg-connected{display:flex;align-items:center;justify-content:space-between;background:rgba(74,222,128,.05);border:1px solid rgba(74,222,128,.15);border-radius:12px;padding:12px 14px;margin-bottom:10px}
.tg-info{font-size:13px;color:var(--green);font-weight:600}
.tg-meta{font-size:10px;color:var(--muted);margin-top:2px}
.unlink-btn{padding:5px 13px;background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.2);border-radius:8px;color:#f87171;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s}
.unlink-btn:hover{background:rgba(248,113,113,.13)}
.tg-steps{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:12px;padding:14px}
.step{display:flex;gap:10px;margin-bottom:10px;align-items:flex-start}
.step:last-child{margin-bottom:0}
.step-n{width:22px;height:22px;border-radius:6px;background:rgba(255,255,255,.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;color:var(--text2)}
.step-text{font-size:13px;color:var(--text2);line-height:1.5;padding-top:1px}
.command-box{background:rgba(0,0,0,.4);border:1px solid var(--border2);border-radius:8px;padding:10px 13px;font-family:monospace;font-size:13px;color:var(--orange);margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;word-break:break-all}
.copy-cmd{padding:4px 10px;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:10px;cursor:pointer;font-family:inherit;flex-shrink:0;transition:all .2s}
.copy-cmd:hover{color:var(--text)}
.gen-btn{width:100%;padding:11px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--text2);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:11px;transition:all .2s}
.gen-btn:hover{border-color:var(--border2);color:var(--text)}
.friends-inner{padding:22px 24px}
.friend-add-row{display:flex;gap:7px;margin-bottom:14px}
.friend-add-row input{flex:1;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:13px;padding:9px 12px;font-family:inherit;outline:none}
.friend-add-row input:focus{border-color:var(--border2)}
.friend-add-btn{padding:9px 16px;background:var(--accent);border:none;border-radius:9px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap}
.friend-item{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin-bottom:7px}
.friend-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#1a1a2e,#3b3b5e);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;overflow:hidden}
.friend-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.friend-name{font-size:13px;font-weight:600;color:var(--text2);text-decoration:none}
.friend-name:hover{color:var(--text)}
.friend-status{font-size:10px;color:var(--muted);margin-top:1px}
.friend-actions{margin-left:auto;display:flex;gap:5px}
.f-btn{padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s;border:1px solid}
.f-btn.accept{background:rgba(74,222,128,.08);border-color:rgba(74,222,128,.3);color:var(--green)}
.f-btn.reject,.f-btn.remove{background:rgba(248,113,113,.06);border-color:rgba(248,113,113,.25);color:var(--red)}
.profile-link{font-size:11px;color:var(--accent);text-decoration:none}
.profile-link:hover{text-decoration:underline}
.admin-inner{padding:22px 24px}
.admin-add-row{display:flex;flex-direction:column;gap:9px;margin-bottom:13px}
.admin-add-row input,.admin-add-row select{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:13px;padding:9px 12px;font-family:inherit;outline:none;width:100%}
.admin-add-row input:focus{border-color:var(--border2)}
.admin-add-btn{padding:11px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:9px;color:#ef4444;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s}
.admin-add-btn:hover{background:rgba(239,68,68,0.18)}
.logout-btn{width:100%;padding:11px;background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.2);border-radius:10px;color:#f87171;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s;text-decoration:none;display:block;text-align:center;margin-bottom:14px}
.logout-btn:hover{background:rgba(248,113,113,.12)}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;pointer-events:none;border:1px solid var(--border2);animation:ti .25s ease;white-space:nowrap}
@keyframes ti{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.88);backdrop-filter:blur(14px);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:28px;max-width:360px;width:100%}
.modal-box h2{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:6px}
.modal-box p{color:var(--muted);font-size:13px;margin-bottom:18px;line-height:1.6}
.fi{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:13px;padding:9px 12px;font-family:inherit;outline:none;margin-bottom:10px}
.fi:focus{border-color:var(--border2)}
.sbtn{width:100%;padding:11px;background:var(--text);color:var(--bg);border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .2s}
.sbtn:hover{opacity:.85}
.sbtn:disabled{opacity:.4;cursor:not-allowed}
.err-msg{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);border-radius:9px;padding:9px 13px;color:#fca5a5;font-size:12px;margin-bottom:10px;display:none}
.err-msg.show{display:block}
</style>
</head>
<body>
<div class="wrap">
    <a href="/" class="back">← Каталог</a>

    <?php if (!$account['is_verified']): ?>
    <div class="verify-banner">
        <div class="verify-icon">📧</div>
        <div class="verify-info">
            <p>Подтверди email <strong style="color:var(--text)"><?=htmlspecialchars($account['email'])?></strong> — мы отправили 6-значный код.</p>
            <div class="verify-input-row">
                <input type="text" id="vcode" placeholder="000000" maxlength="6" inputmode="numeric">
                <button onclick="verifyEmail()">Подтвердить</button>
            </div>
            <span class="resend-link" onclick="resendCode()">Отправить повторно</span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($needsReauth): ?>
    <div class="reauth-banner">
        <div style="font-size:22px;flex-shrink:0">🔑</div>
        <div style="flex:1">
            <p style="font-size:12px;color:var(--text2);margin-bottom:8px;line-height:1.5">Ежемесячное подтверждение. Нажми чтобы подтвердить аккаунт.</p>
            <button onclick="openReauthModal()" style="padding:7px 14px;background:rgba(124,92,255,0.15);border:1px solid rgba(124,92,255,0.3);border-radius:8px;color:#a78bfa;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit">Подтвердить сейчас</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- PROFILE CARD -->
    <div class="card">
        <div class="profile-banner">
            <?php if (!empty($custom['banner_url'])): ?>
            <img class="profile-banner-img" src="<?=htmlspecialchars($custom['banner_url'])?>" alt="">
            <?php endif; ?>
        </div>
        <div class="avatar-wrap">
            <div class="avatar">
                <?php if (!empty($custom['avatar_url'])): ?>
                <img src="<?=htmlspecialchars($custom['avatar_url'])?>" alt="">
                <?php else: ?>👤<?php endif; ?>
            </div>
        </div>
        <div class="profile-header-row">
            <div class="profile-name-col">
                <div class="username">
                    <?=htmlspecialchars($account['username'])?>
                    <?php if ($isAccountAdmin): ?><span class="admin-badge">⚡ <?=htmlspecialchars($accountAdminTag ?? 'ADMIN')?></span><?php endif; ?>
                    <?php if ($account['is_verified']): ?><span class="verify-badge">✓ Верифицирован</span><?php endif; ?>
                </div>
                <div class="email"><?=htmlspecialchars($account['email'])?></div>
                <?php if (!empty($custom['bio'])): ?>
                <div class="bio-text"><?=nl2br(htmlspecialchars($custom['bio']))?></div>
                <?php endif; ?>
                <div class="joined">На сайте с: <?=date('d.m.Y', strtotime($account['created_at']))?></div>
                <div style="margin-top:8px"><a href="/u/<?=htmlspecialchars($account['username'])?>" class="profile-link">👁 Открыть публичный профиль</a></div>
            </div>
            <a href="/profile/edit" class="edit-profile-btn">✏️ Редактировать</a>
        </div>
    </div>

    <!-- XP / УРОВЕНЬ -->
    <?php
    try {
        $xpRow = $pdo->prepare("SELECT user_xp, user_level FROM accounts WHERE id=?");
        $xpRow->execute([(int)$account['id']]);
        $xpData = $xpRow->fetch();
        $totalXp = $xpData ? (int)$xpData['user_xp'] : 0;
        $curLevel = $xpData ? (int)$xpData['user_level'] : 1;
        // Recalculate from scratch
        $xpForLvl = 100 + ($curLevel - 1) * 50;
        $totalToLevel = 0;
        for ($i = 1; $i < $curLevel; $i++) $totalToLevel += 100 + ($i-1)*50;
        $curXpInLevel = max(0, $totalXp - $totalToLevel);
        $pct = $xpForLvl > 0 ? min(100, round($curXpInLevel / $xpForLvl * 100)) : 0;
        $xpProgress = ['pct'=>$pct,'current'=>$curXpInLevel,'needed'=>$xpForLvl,'next_lvl'=>$curLevel+1];
        // Level label
        if ($curLevel >= 50) $lvlLabel = ['color'=>'#f59e0b','label'=>'👑 Легенда'];
        elseif ($curLevel >= 30) $lvlLabel = ['color'=>'#8b5cf6','label'=>'💎 Мастер'];
        elseif ($curLevel >= 15) $lvlLabel = ['color'=>'#3b82f6','label'=>'⚡ Опытный'];
        elseif ($curLevel >= 5)  $lvlLabel = ['color'=>'#10b981','label'=>'📚 Читатель'];
        else                     $lvlLabel = ['color'=>'#6b7280','label'=>'🌑 Новичок'];
        $levelFrame = $lvlLabel;
    } catch(Exception $e) { $totalXp=0;$curLevel=1;$xpProgress=['pct'=>0,'current'=>0,'needed'=>100,'next_lvl'=>2];$levelFrame=['color'=>'#6b7280','label'=>'🌑 Новичок']; }
    ?>
    <div class="card" style="padding:0;overflow:hidden">
        <div style="padding:18px 20px 16px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px">Уровень</span>
                    <span style="background:<?=htmlspecialchars($levelFrame['color'])?>;color:#fff;font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px"><?=htmlspecialchars($levelFrame['label'])?> <?=$curLevel?></span>
                </div>
                <span style="font-size:11px;color:var(--muted)">⭐ <?=number_format($totalXp)?> XP</span>
            </div>
            <div style="background:var(--border);border-radius:4px;height:6px;overflow:hidden">
                <div style="background:<?=htmlspecialchars($levelFrame['color'])?>;height:100%;width:<?=$xpProgress['pct']?>%;border-radius:4px;transition:width .6s"></div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:5px">
                <span style="font-size:10px;color:var(--muted)"><?=number_format($xpProgress['current'])?> / <?=number_format($xpProgress['needed'])?> XP</span>
                <span style="font-size:10px;color:var(--muted)">Lv<?=$curLevel?> → Lv<?=$xpProgress['next_lvl']?></span>
            </div>
        </div>
    </div>

    <!-- STATS -->
    <?php
    try {
        $aid = (int)$account['id'];
        $tgIdStmt = $pdo->prepare("SELECT tg_user_id FROM accounts WHERE id=?");
        $tgIdStmt->execute([$aid]); $tgRow = $tgIdStmt->fetch(); $tgId2 = $tgRow ? (int)$tgRow['tg_user_id'] : 0;
        $totalLib = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE account_id={$aid}".($tgId2?" OR user_id={$tgId2}":""))->fetchColumn();
        $readingLib = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('now','reading','Читаю') AND (account_id={$aid}".($tgId2?" OR user_id={$tgId2}":"").")")->fetchColumn();
        $readLib = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('read','Прочитано') AND (account_id={$aid}".($tgId2?" OR user_id={$tgId2}":"").")")->fetchColumn();
        $planLib = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('will','Запланировано') AND (account_id={$aid}".($tgId2?" OR user_id={$tgId2}":"").")")->fetchColumn();
        $dropLib = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('drop','Брошено') AND (account_id={$aid}".($tgId2?" OR user_id={$tgId2}":"").")")->fetchColumn();
        $pauseLib = (int)$pdo->query("SELECT COUNT(DISTINCT manga_id) FROM user_manga_status WHERE status IN ('pause','На паузе') AND (account_id={$aid}".($tgId2?" OR user_id={$tgId2}":"").")")->fetchColumn();
    } catch(Exception $e) { $totalLib=0; $readingLib=0; $readLib=0; }
    ?>
    <div class="card">
        <div class="sec-title">📚 Библиотека</div>
        <div class="stats-row" id="stats-row">
            <a class="stat" href="/library"><div class="stat-n"><?=$totalLib?></div><div class="stat-l">📖 Всего</div></a>
            <a class="stat" href="/library"><div class="stat-n"><?=$readLib?></div><div class="stat-l">✅ Прочитано</div></a>
            <a class="stat" href="/library"><div class="stat-n"><?=$readingLib?></div><div class="stat-l">▶ Читаю</div></a>
            <a class="stat" href="/library"><div class="stat-n"><?=$planLib?></div><div class="stat-l">📋 В планах</div></a>
            <a class="stat" href="/library"><div class="stat-n"><?=$dropLib?></div><div class="stat-l">❌ Брошено</div></a>
            <a class="stat" href="/library"><div class="stat-n"><?=$pauseLib?></div><div class="stat-l">⏸ На паузе</div></a>
        </div>
    </div>

    <!-- PRIVACY -->
    <div class="card">
        <div class="sec-title">🔒 Видимость профиля</div>
        <?php $privacy = $account['profile_privacy'] ?? 'public'; ?>
        <div class="privacy-row">
            <button class="privacy-btn <?=$privacy==='public'?'active-public':''?>" onclick="setPrivacy('public')">🌐 Открытый</button>
            <button class="privacy-btn <?=$privacy==='friends'?'active-friends':''?>" onclick="setPrivacy('friends')">👥 Для друзей</button>
            <button class="privacy-btn <?=$privacy==='private'?'active-private':''?>" onclick="setPrivacy('private')">🔒 Закрытый</button>
        </div>
        <div style="padding:0 24px 16px;font-size:11px;color:var(--muted)" id="privacy-desc">
            <?php if($privacy==='public'): ?>Профиль виден всем пользователям
            <?php elseif($privacy==='friends'): ?>Профиль виден только друзьям
            <?php else: ?>Профиль закрыт (только для администраторов)<?php endif; ?>
        </div>
    </div>

    <!-- FRIENDS -->
    <div class="card">
        <div class="friends-inner">
            <div class="sec-title" style="padding:0;margin-bottom:14px">👥 Друзья <span style="font-size:11px;color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0">(<?=$friendsCount?>)</span></div>
            <div class="friend-add-row">
                <input type="text" id="friend-username" placeholder="Username друга..." oninput="searchUsers(this.value)">
                <button class="friend-add-btn" onclick="addFriend()">+ Добавить</button>
            </div>
            <div id="user-suggestions" style="margin-bottom:10px"></div>
            <div id="friends-list"><div style="color:var(--muted);font-size:12px">Загрузка...</div></div>
        </div>
    </div>

    <!-- TELEGRAM -->
    <div class="card">
        <div class="tg-inner">
            <div class="sec-title" style="padding:0;margin-bottom:13px">🤖 Telegram</div>
            <?php if ($account['tg_user_id']): ?>
            <div class="tg-connected">
                <div>
                    <div class="tg-info">✅ Telegram привязан</div>
                    <div class="tg-meta">TG ID: <?=(int)$account['tg_user_id']?></div>
                </div>
                <button class="unlink-btn" onclick="unlinkTg()">Отвязать</button>
            </div>
            <p style="font-size:12px;color:var(--muted);line-height:1.6">Твоя библиотека и прогресс синхронизированы с ботом.</p>
            <?php else: ?>
            <p style="font-size:13px;color:var(--muted);margin-bottom:14px;line-height:1.6">Привяжи Telegram для синхронизации с ботом.</p>
            <div class="tg-steps">
                <div class="step"><div class="step-n">1</div><div class="step-text">Нажми кнопку ниже — сгенерируется одноразовая команда</div></div>
                <div class="step"><div class="step-n">2</div><div class="step-text">Открой <strong>@<?=htmlspecialchars($botUsername)?></strong> и отправь команду</div></div>
                <div class="step"><div class="step-n">3</div><div class="step-text">Готово — аккаунты связаны!</div></div>
            </div>
            <div id="link-command" style="display:none">
                <div class="command-box"><span id="cmd-text"></span><button class="copy-cmd" onclick="copyCmd()">Копировать</button></div>
                <p style="font-size:11px;color:var(--muted);margin-top:7px">Токен действителен 10 минут.</p>
            </div>
            <button class="gen-btn" id="gen-btn" onclick="genLink()">🔗 Получить команду привязки</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isSuperAdmin): ?>
    <!-- ADMIN MANAGEMENT (only for superadmins) -->
    <div class="card">
        <div class="admin-inner">
            <div class="sec-title" style="padding:0;margin-bottom:13px">⚡ Управление администраторами</div>
            <div class="admin-add-row">
                <input type="text" id="new-admin-input" placeholder="Email или TG ID пользователя">
                <input type="text" id="new-admin-tag" placeholder="Тег (например: Редактор)">
                <button class="admin-add-btn" onclick="addAdmin()">➕ Добавить администратора</button>
            </div>
            <div id="admin-result" style="font-size:12px;color:var(--muted);margin-bottom:10px"></div>
            <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:7px">Список администраторов</div>
            <div id="admins-full-list"><div style="color:var(--muted);font-size:12px">Загрузка...</div></div>
        </div>
    </div>
    <?php endif; ?>

    <a href="/logout" class="logout-btn">Выйти из аккаунта</a>
</div>

<!-- REAUTH MODAL -->
<div class="modal-overlay" id="reauth-modal">
<div class="modal-box">
    <h2>🔑 Подтверждение</h2>
    <p>Ежемесячное подтверждение. Введи пароль, затем код из письма.</p>
    <div class="err-msg" id="reauth-err"></div>
    <div id="reauth-step1">
        <input class="fi" type="password" id="reauth-pass" placeholder="Твой пароль">
        <button class="sbtn" id="reauth-pass-btn" onclick="reauthStep1()">Продолжить →</button>
    </div>
    <div id="reauth-step2" style="display:none">
        <p style="color:var(--text2);font-size:12px;margin-bottom:12px">Код отправлен на email. Введи его:</p>
        <input class="fi" type="text" id="reauth-code" placeholder="000000" maxlength="6" style="font-family:monospace;letter-spacing:6px;text-align:center;font-size:20px">
        <button class="sbtn" onclick="reauthStep2()">Подтвердить</button>
    </div>
</div>
</div>

<script>
function showToast(msg){const t=document.createElement('div');t.className='toast';t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2500);}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

async function verifyEmail(){
    const code=document.getElementById('vcode').value.trim();
    if(code.length!==6){showToast('Введи 6 цифр');return;}
    const res=await fetch('/api/auth/verify-email',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})});
    const d=await res.json();
    if(d.success){showToast('✅ Email подтверждён!');setTimeout(()=>location.reload(),800);}
    else showToast('❌ '+d.error);
}
async function resendCode(){
    const res=await fetch('/api/auth/resend-verify',{method:'POST'});
    const d=await res.json();
    showToast(d.success?'📧 Код отправлен заново':'❌ '+d.error);
}

async function genLink(){const btn=document.getElementById('gen-btn');btn.disabled=true;btn.textContent='Генерируем...';try{const res=await fetch('/api/auth/tg-link-token',{method:'POST'});const d=await res.json();if(d.success){document.getElementById('cmd-text').textContent=d.command;document.getElementById('link-command').style.display='block';btn.textContent='🔄 Обновить команду';}else showToast('Ошибка: '+d.error);}catch(e){showToast('Ошибка сети');}btn.disabled=false;}
function copyCmd(){navigator.clipboard?.writeText(document.getElementById('cmd-text').textContent);showToast('✅ Скопировано!');}
async function unlinkTg(){if(!confirm('Отвязать Telegram?'))return;const res=await fetch('/api/auth/tg-unlink',{method:'POST'});const d=await res.json();if(d.success){showToast('Telegram отвязан');setTimeout(()=>location.reload(),800);}}

async function loadStats(){try{const res=await fetch('/api/auth/profile-stats');const d=await res.json();if(d.success){const rows=document.getElementById('stats-row');if(rows)rows.innerHTML=`<a class="stat" href="/library"><div class="stat-n">${d.total||0}</div><div class="stat-l">📖 Всего</div></a><a class="stat" href="/library"><div class="stat-n">${d.read||0}</div><div class="stat-l">✅ Прочитано</div></a><a class="stat" href="/library"><div class="stat-n">${d.now||0}</div><div class="stat-l">▶ Читаю</div></a><a class="stat" href="/library"><div class="stat-n">${d.will||0}</div><div class="stat-l">📋 В планах</div></a><a class="stat" href="/library"><div class="stat-n">${d.drop||0}</div><div class="stat-l">❌ Брошено</div></a><a class="stat" href="/library"><div class="stat-n">${d.pause||0}</div><div class="stat-l">⏸ На паузе</div></a>`;}}catch(e){}}

async function setPrivacy(mode){
    const btns=document.querySelectorAll('.privacy-btn');
    btns.forEach(b=>b.classList.remove('active-public','active-friends','active-private'));
    const map={public:'active-public',friends:'active-friends',private:'active-private'};
    event.target.classList.add(map[mode]);
    const labels={public:'Профиль виден всем пользователям',friends:'Профиль виден только друзьям',private:'Профиль закрыт (только для администраторов)'};
    document.getElementById('privacy-desc').textContent=labels[mode];
    const res=await fetch('/api/profile/privacy',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({mode})});
    const d=await res.json();
    if(d.success){showToast('✅ '+labels[mode]);}
    else showToast('❌ Ошибка');
}

let searchTimer;
async function searchUsers(q){
    clearTimeout(searchTimer);
    const sug=document.getElementById('user-suggestions');
    if(q.length<2){sug.innerHTML='';return;}
    searchTimer=setTimeout(async()=>{
        try{const res=await fetch('/api/users/search?q='+encodeURIComponent(q));const d=await res.json();
        if(!d.users||!d.users.length){sug.innerHTML='';return;}
        sug.innerHTML=d.users.map(u=>`<div onclick="selectUser('${escapeHtml(u.username)}')" style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;cursor:pointer;margin-bottom:5px;transition:all .15s" onmouseover="this.style.borderColor='var(--border2)'" onmouseout="this.style.borderColor='var(--border)'">${u.avatar_url?`<img src="${escapeHtml(u.avatar_url)}" style="width:28px;height:28px;border-radius:50%;object-fit:cover">`:'<div style="width:28px;height:28px;border-radius:50%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;font-size:12px">👤</div>'}<span style="font-size:13px;font-weight:600;color:var(--text2)">${escapeHtml(u.username)}</span></div>`).join('');}catch(e){}
    },300);
}
function selectUser(username){document.getElementById('friend-username').value=username;document.getElementById('user-suggestions').innerHTML='';}

async function loadFriends(){
    try{const res=await fetch('/api/friends');const d=await res.json();const list=document.getElementById('friends-list');
    if(!d.friends||!d.friends.length){list.innerHTML='<div style="color:var(--muted);font-size:12px">Друзей пока нет</div>';return;}
    list.innerHTML=d.friends.map(f=>{
        const isPending=f.status==='pending';const isMine=f.is_mine;
        const avatarHtml=f.avatar_url?`<img src="${escapeHtml(f.avatar_url)}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0">`:`<div class="friend-avatar">👤</div>`;
        let actions='';
        if(isPending&&!isMine)actions=`<button class="f-btn accept" onclick="friendAction(${f.id},'accept')">✓ Принять</button><button class="f-btn reject" onclick="friendAction(${f.id},'reject')">✕</button>`;
        else if(isPending&&isMine)actions=`<span style="font-size:10px;color:var(--muted)">⏳ Ожидание...</span>`;
        else actions=`<button class="f-btn remove" onclick="friendAction(${f.id},'remove')">Удалить</button>`;
        return `<div class="friend-item"><div class="friend-avatar" style="overflow:hidden">${avatarHtml}</div><div style="flex:1;min-width:0"><a href="/u/${escapeHtml(f.username)}" class="friend-name">${escapeHtml(f.username)}</a><div class="friend-status">${isPending?(isMine?'Запрос отправлен':'Входящий запрос'):'👥 Друг'}</div></div><div class="friend-actions">${actions}</div></div>`;
    }).join('');}catch(e){}
}
async function addFriend(){
    const username=document.getElementById('friend-username').value.trim();
    if(!username){showToast('Введи имя пользователя');return;}
    const res=await fetch('/api/friends/add',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username})});
    const d=await res.json();
    if(d.success){showToast('✅ Запрос отправлен!');document.getElementById('friend-username').value='';document.getElementById('user-suggestions').innerHTML='';loadFriends();}
    else showToast('❌ '+(d.error||'Ошибка'));
}
async function friendAction(id,action){
    await fetch(`/api/friends/${id}/${action}`,{method:'POST'});loadFriends();
    showToast(action==='accept'?'✅ Принято!':action==='reject'?'Отклонено':'Удалено');
}

function openReauthModal(){document.getElementById('reauth-modal').classList.add('open');}
async function reauthStep1(){
    const pass=document.getElementById('reauth-pass').value;
    const err=document.getElementById('reauth-err');err.classList.remove('show');
    if(!pass){err.textContent='Введи пароль';err.classList.add('show');return;}
    document.getElementById('reauth-pass-btn').disabled=true;
    const res=await fetch('/api/auth/reauth-confirm',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({step:'password',password:pass})});
    const d=await res.json();
    if(d.success){document.getElementById('reauth-step1').style.display='none';document.getElementById('reauth-step2').style.display='block';}
    else{err.textContent=d.error||'Ошибка';err.classList.add('show');document.getElementById('reauth-pass-btn').disabled=false;}
}
async function reauthStep2(){
    const code=document.getElementById('reauth-code').value.trim();
    const err=document.getElementById('reauth-err');err.classList.remove('show');
    if(code.length!==6){err.textContent='Введи 6 цифр';err.classList.add('show');return;}
    const res=await fetch('/api/auth/reauth-confirm',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({step:'code',code})});
    const d=await res.json();
    if(d.success){showToast('✅ Подтверждено!');document.getElementById('reauth-modal').classList.remove('open');setTimeout(()=>location.reload(),500);}
    else{err.textContent=d.error||'Неверный код';err.classList.add('show');}
}

<?php if ($isSuperAdmin): ?>
async function addAdmin(){
    const input=document.getElementById('new-admin-input').value.trim();
    const tag=document.getElementById('new-admin-tag').value.trim()||'Администратор';
    const result=document.getElementById('admin-result');
    if(!input){showToast('Введи email или TG ID');return;}
    let endpoint='/api/admin/assign';
    let body={tag,action:'add'};
    if(/^\d+$/.test(input)){endpoint='/api/admin/assign-by-tgid';body.tg_id=parseInt(input);}
    else{body.email=input;}
    const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const d=await res.json();
    if(d.success){result.style.color='var(--green)';result.textContent='✅ '+d.message;loadAdminsList();}
    else{result.style.color='var(--red)';result.textContent='❌ '+d.error;}
}
async function removeAdmin(tgId){
    if(!confirm('Снять права администратора?'))return;
    const res=await fetch('/api/admin/assign-by-tgid',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tg_id:tgId,action:'remove'})});
    const d=await res.json();
    if(d.success){showToast('✅ Права сняты');loadAdminsList();}
    else showToast('❌ '+d.error);
}
async function loadAdminsList(){
    try{const res=await fetch('/api/admin/list-all');const d=await res.json();
    if(d.error){document.getElementById('admins-full-list').innerHTML='<div style="color:var(--muted);font-size:12px">Нет прав</div>';return;}
    document.getElementById('admins-full-list').innerHTML=d.admins.map(a=>`
        <div style="display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:5px">
            <div>
                <div style="font-size:12px;font-weight:600;color:${a.is_super?'#ef4444':'var(--text2)'}">${escapeHtml(a.tag||'Администратор')}${a.is_super?' ⭐':''}</div>
                <div style="font-size:10px;color:var(--muted)">${a.username?'@'+escapeHtml(a.username)+' · ':''}TG: ${a.tg_id}</div>
                ${a.email?`<div style="font-size:10px;color:var(--muted)">${escapeHtml(a.email)}</div>`:''}
            </div>
            ${!a.is_super&&a.tg_id?`<button onclick="removeAdmin(${a.tg_id})" style="padding:3px 10px;background:rgba(248,113,113,0.07);border:1px solid rgba(248,113,113,0.2);border-radius:6px;color:var(--red);font-size:10px;cursor:pointer;font-family:inherit;font-weight:600">Снять</button>`:'<span style="font-size:10px;color:var(--muted)">Суперадмин</span>'}
        </div>`).join('');}catch(e){}
}
loadAdminsList();
<?php endif; ?>

loadStats();loadFriends();
</script>
</body></html><?php exit; }

// /profile/edit
if ($path==='/profile/edit') {
    $account = requireAuth($pdo);
    $custStmt = $pdo->prepare("SELECT * FROM profile_customizations WHERE account_id=?");
    $custStmt->execute([(int)$account['id']]);
    $custom = $custStmt->fetch() ?: ['avatar_url'=>null,'banner_url'=>null,'banner_color'=>'#1a1a2e','bio'=>null];
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Редактировать профиль | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#7c5cff;--green:#4ade80;--orange:#fb923c;--red:#f87171}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 50% at 50% 0%,rgba(124,92,255,0.04) 0%,transparent 55%);pointer-events:none}
.back{display:inline-flex;align-items:center;gap:7px;color:var(--muted);text-decoration:none;font-size:13px;margin-bottom:20px;transition:color .2s}.back:hover{color:var(--text)}
.wrap{max-width:540px;margin:0 auto;position:relative;z-index:1}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:14px}
.card-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;margin-bottom:18px;color:var(--text)}
.fg{margin-bottom:14px}
.fl{display:block;font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px}
.fi{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:13px;padding:9px 12px;font-family:inherit;outline:none;transition:border-color .2s}
.fi:focus{border-color:var(--border2)}
.fta{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:13px;padding:9px 12px;font-family:inherit;outline:none;resize:none;min-height:70px}
.fta:focus{border-color:var(--border2)}
.img-upload-zone{border:1.5px dashed var(--border);border-radius:12px;cursor:pointer;position:relative;overflow:hidden;transition:all .18s;background:rgba(255,255,255,0.02)}
.img-upload-zone:hover{border-color:var(--border2);background:rgba(255,255,255,0.04)}
.img-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.avatar-zone{width:100px;height:100px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px}
.banner-zone{width:100%;height:110px;display:flex;align-items:center;justify-content:center}
.img-preview{width:100%;height:100%;object-fit:cover;display:block}
.avatar-preview{border-radius:50%;width:100%;height:100%;object-fit:cover}
.upload-ph{font-size:13px;color:var(--muted);text-align:center;padding:10px;line-height:1.5}
.upload-ph span{font-size:20px;display:block;margin-bottom:4px}
.upload-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;color:#fff;opacity:0;transition:opacity .18s;pointer-events:none}
.img-upload-zone:hover .upload-overlay{opacity:1}
.upload-status{font-size:11px;color:var(--muted);margin-top:5px;text-align:center;min-height:16px}
.color-presets{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.color-preset{width:26px;height:26px;border-radius:7px;cursor:pointer;border:2px solid transparent;transition:all .15s;flex-shrink:0}
.color-preset.active{border-color:#fff;transform:scale(1.2)}
.save-btn{width:100%;padding:12px;background:var(--text);color:var(--bg);border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .2s}
.save-btn:hover{opacity:.88}
.save-btn:disabled{opacity:.4;cursor:not-allowed}
.err-box{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);border-radius:9px;padding:9px 13px;color:#fca5a5;font-size:12px;margin-bottom:10px;display:none}
.err-box.show{display:block}
.ok-box{background:rgba(74,222,128,.07);border:1px solid rgba(74,222,128,.2);border-radius:9px;padding:9px 13px;color:#86efac;font-size:12px;margin-bottom:10px;display:none}
.ok-box.show{display:block}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;pointer-events:none;border:1px solid var(--border2);animation:ti .25s ease;white-space:nowrap}
@keyframes ti{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.tab-nav{display:flex;gap:0;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:3px;margin-bottom:18px}
.tab-btn{flex:1;padding:8px;border:none;background:transparent;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;border-radius:8px;font-family:inherit;transition:all .18s}
.tab-btn.active{background:var(--card);border:1px solid var(--border2);color:var(--text)}
.tab-panel{display:none}.tab-panel.active{display:block}
</style>
</head>
<body>
<div class="wrap">
    <a href="/profile" class="back">← Профиль</a>
    <h1 style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:18px">✏️ Редактировать профиль</h1>

    <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('visuals')">🖼 Внешний вид</button>
        <button class="tab-btn" onclick="switchTab('account')">👤 Аккаунт</button>
    </div>

    <!-- TAB: VISUALS -->
    <div class="tab-panel active" id="tab-visuals">
        <!-- AVATAR -->
        <div class="card">
            <div class="card-title">🖼 Аватарка</div>
            <div style="display:flex;align-items:center;gap:16px">
                <div class="img-upload-zone avatar-zone" id="avatar-zone">
                    <input type="file" accept="image/*" id="avatar-input" onchange="uploadImage(this,'avatar')">
                    <?php if(!empty($custom['avatar_url'])): ?>
                    <img id="avatar-preview" class="avatar-preview" src="<?=htmlspecialchars($custom['avatar_url'])?>" alt="">
                    <div class="upload-overlay">📷 Изменить</div>
                    <?php else: ?>
                    <div class="upload-ph"><span>👤</span>Загрузить</div>
                    <div class="upload-overlay">📷</div>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size:13px;color:var(--text2);margin-bottom:5px">Нажми чтобы загрузить фото</div>
                    <div style="font-size:11px;color:var(--muted)">JPG, PNG, WebP • Макс. 8 МБ<br>Квадратное фото — лучший вариант</div>
                    <div class="upload-status" id="avatar-status"></div>
                </div>
            </div>
        </div>

        <!-- BANNER -->
        <div class="card">
            <div class="card-title">🖼 Шапка профиля (баннер)</div>
            <div class="img-upload-zone banner-zone" id="banner-zone" style="margin-bottom:10px;<?=!empty($custom['banner_url'])?'padding:0':''?>">
                <input type="file" accept="image/*" id="banner-input" onchange="uploadImage(this,'banner')">
                <?php if(!empty($custom['banner_url'])): ?>
                <img id="banner-preview" class="img-preview" src="<?=htmlspecialchars($custom['banner_url'])?>" alt="">
                <div class="upload-overlay">📷 Изменить баннер</div>
                <?php else: ?>
                <div class="upload-ph"><span>🖼</span>Загрузить баннер<br><small>или выбери цвет ниже</small></div>
                <div class="upload-overlay">📷 Загрузить</div>
                <?php endif; ?>
            </div>
            <div class="upload-status" id="banner-status"></div>
            <div style="font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;margin-top:8px">Или выбери цвет шапки</div>
            <div class="color-presets">
                <?php foreach(['#1a1a2e','#0f2027','#1a0a2e','#0a1a2e','#0a2e1a','#2e1a0a','#2e0a0a','#0a0a0a','#1e1b4b','#064e3b'] as $c): ?>
                <div class="color-preset <?=$custom['banner_color']===$c?'active':''?>" style="background:<?=htmlspecialchars($c)?>" onclick="pickBannerColor('<?=htmlspecialchars($c)?>')"></div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="cust-color" value="<?=htmlspecialchars($custom['banner_color']??'#1a1a2e')?>">
        </div>

        <!-- BIO -->
        <div class="card">
            <div class="card-title">📝 О себе</div>
            <div class="fg">
                <label class="fl">Биография</label>
                <textarea class="fta" id="cust-bio" placeholder="Расскажи о себе..." maxlength="300"><?=htmlspecialchars($custom['bio']??'')?></textarea>
            </div>
            <div class="ok-box" id="bio-ok"></div>
            <div class="err-box" id="bio-err"></div>
            <!-- Скрытые поля для хранения URL фото перед сохранением -->
            <input type="hidden" id="avatar-pending-url" value="">
            <input type="hidden" id="banner-pending-url" value="">
            <button class="save-btn" onclick="saveBio()">💾 Сохранить изменения</button>
        </div>
    </div>

    <!-- TAB: ACCOUNT -->
    <div class="tab-panel" id="tab-account">
        <!-- CHANGE USERNAME -->
        <div class="card">
            <div class="card-title">🔤 Изменить никнейм</div>
            <p style="font-size:12px;color:var(--muted);margin-bottom:14px">Текущий: <strong style="color:var(--text2)"><?=htmlspecialchars($account['username'])?></strong></p>
            <div class="fg">
                <label class="fl">Новый никнейм</label>
                <input class="fi" type="text" id="new-username" placeholder="новый_ник" maxlength="30">
            </div>
            <div class="ok-box" id="nick-ok"></div>
            <div class="err-box" id="nick-err"></div>
            <button class="save-btn" onclick="changeUsername()">Сохранить никнейм</button>
        </div>

        <!-- CHANGE EMAIL -->
        <div class="card">
            <div class="card-title">📧 Изменить email</div>
            <p style="font-size:12px;color:var(--muted);margin-bottom:14px">Текущий: <strong style="color:var(--text2)"><?=htmlspecialchars($account['email'])?></strong></p>
            <div id="email-step1">
                <div class="fg">
                    <label class="fl">Пароль (подтверждение)</label>
                    <input class="fi" type="password" id="email-pass" placeholder="Введи пароль">
                </div>
                <div class="fg">
                    <label class="fl">Новый Email</label>
                    <input class="fi" type="email" id="new-email" placeholder="new@email.com">
                </div>
                <div class="ok-box" id="email-ok1"></div>
                <div class="err-box" id="email-err1"></div>
                <button class="save-btn" onclick="changeEmailStep1()">Получить код →</button>
            </div>
            <div id="email-step2" style="display:none">
                <p style="font-size:12px;color:var(--text2);margin-bottom:12px">Код отправлен на новый email. Введи его:</p>
                <div class="fg">
                    <input class="fi" type="text" id="email-code" placeholder="000000" maxlength="6" style="font-family:monospace;letter-spacing:8px;text-align:center;font-size:20px">
                </div>
                <div class="ok-box" id="email-ok2"></div>
                <div class="err-box" id="email-err2"></div>
                <button class="save-btn" onclick="changeEmailStep2()">Подтвердить смену email</button>
            </div>
        </div>
    </div>
</div>

<script>
function showToast(msg){const t=document.createElement('div');t.className='toast';t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2500);}

function switchTab(tab){
    document.querySelectorAll('.tab-btn').forEach((b,i)=>b.classList.toggle('active',['visuals','account'][i]===tab));
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('tab-'+tab).classList.add('active');
}

async function uploadImage(input, type){
    const file = input.files[0];
    if(!file) return;
    let statusEl = document.getElementById(type+'-status');
    if(!statusEl){
        statusEl = document.createElement('div');
        statusEl.id = type+'-status';
        statusEl.className = 'upload-status';
        const zone = document.getElementById(type+'-zone');
        if(zone && zone.parentNode) zone.parentNode.insertBefore(statusEl, zone.nextSibling);
    }
    statusEl.textContent = '⏳ Загружаю...';
    const formData = new FormData();
    formData.append('image', file);
    try {
        const res = await fetch('/api/profile/upload-image?type='+type, {method:'POST', body:formData});
        const d = await res.json();
        if(d.success){
            statusEl.textContent = '✅ Загружено! Нажми "Сохранить" чтобы применить';
            // Сохраняем URL в скрытое поле для последующего сохранения
            document.getElementById(type+'-pending-url').value = d.url;
            if(type==='avatar'){
                const zone = document.getElementById('avatar-zone');
                zone.innerHTML = `<input type="file" accept="image/*" id="avatar-input" onchange="uploadImage(this,'avatar')"><img class="avatar-preview" src="${d.url}" alt=""><div class="upload-overlay">📷 Изменить</div>`;
            } else {
                const zone = document.getElementById('banner-zone');
                zone.style.padding='0';
                zone.innerHTML = `<input type="file" accept="image/*" id="banner-input" onchange="uploadImage(this,'banner')"><img class="img-preview" src="${d.url}" alt=""><div class="upload-overlay">📷 Изменить баннер</div>`;
            }
            setTimeout(()=>{statusEl.textContent='';},5000);
        } else {
            statusEl.textContent = '❌ '+(d.error||'Ошибка загрузки');
        }
    } catch(e){statusEl.textContent = '❌ Ошибка загрузки';}
}

function pickBannerColor(c){
    document.getElementById('cust-color').value=c;
    document.querySelectorAll('.color-preset').forEach(el=>{
        const onclick=el.getAttribute('onclick')||'';
        el.classList.toggle('active', onclick.includes(`'${c}'`));
    });
    // Also save immediately
    fetch('/api/profile/customization',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({banner_color:c,bio:document.getElementById('cust-bio').value.trim()})});
}

async function saveBio(){
    const bio=document.getElementById('cust-bio').value.trim();
    const color=document.getElementById('cust-color').value||'#1a1a2e';
    const avatarUrl=document.getElementById('avatar-pending-url').value;
    const bannerUrl=document.getElementById('banner-pending-url').value;
    const ok=document.getElementById('bio-ok');const err=document.getElementById('bio-err');
    ok.classList.remove('show');err.classList.remove('show');
    
    const body={bio,banner_color:color};
    if(avatarUrl)body.avatar_url=avatarUrl;
    if(bannerUrl)body.banner_url=bannerUrl;
    
    const res=await fetch('/api/profile/customization',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const d=await res.json();
    if(d.success){
        ok.textContent='✅ Сохранено!';ok.classList.add('show');
        document.getElementById('avatar-pending-url').value='';
        document.getElementById('banner-pending-url').value='';
        showToast('✅ Профиль обновлён!');
    }
    else{err.textContent='❌ '+(d.error||'Ошибка');err.classList.add('show');}
}

async function changeUsername(){
    const username=document.getElementById('new-username').value.trim();
    const ok=document.getElementById('nick-ok');const err=document.getElementById('nick-err');
    ok.classList.remove('show');err.classList.remove('show');
    if(!username){err.textContent='Введи новый никнейм';err.classList.add('show');return;}
    const res=await fetch('/api/profile/change-username',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username})});
    const d=await res.json();
    if(d.success){ok.textContent='✅ Никнейм изменён! Перезагружаю...';ok.classList.add('show');setTimeout(()=>location.reload(),1000);}
    else{err.textContent='❌ '+(d.error||'Ошибка');err.classList.add('show');}
}

async function changeEmailStep1(){
    const password=document.getElementById('email-pass').value;
    const new_email=document.getElementById('new-email').value.trim();
    const ok=document.getElementById('email-ok1');const err=document.getElementById('email-err1');
    ok.classList.remove('show');err.classList.remove('show');
    if(!password||!new_email){err.textContent='Заполни все поля';err.classList.add('show');return;}
    const res=await fetch('/api/profile/change-email',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({step:'request',password,new_email})});
    const d=await res.json();
    if(d.success){ok.textContent='📧 '+d.message;ok.classList.add('show');document.getElementById('email-step1').style.display='none';document.getElementById('email-step2').style.display='block';}
    else{err.textContent='❌ '+(d.error||'Ошибка');err.classList.add('show');}
}
async function changeEmailStep2(){
    const code=document.getElementById('email-code').value.trim();
    const ok=document.getElementById('email-ok2');const err=document.getElementById('email-err2');
    ok.classList.remove('show');err.classList.remove('show');
    if(code.length!==6){err.textContent='Введи 6 цифр';err.classList.add('show');return;}
    const res=await fetch('/api/profile/change-email',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({step:'confirm',code})});
    const d=await res.json();
    if(d.success){ok.textContent='✅ Email изменён!';ok.classList.add('show');showToast('✅ Email обновлён!');setTimeout(()=>location.reload(),1000);}
    else{err.textContent='❌ '+(d.error||'Ошибка');err.classList.add('show');}
}
</script>
</body></html><?php exit; }

// /u/username — PUBLIC USER PROFILE PAGE
if (preg_match('#^/u/([a-zA-Z0-9_]{2,30})$#', $path, $um)) {
    $targetUsername = $um[1];
    $stmt = $pdo->prepare("SELECT id,username,created_at,is_verified,tg_user_id,profile_privacy,is_admin,admin_tag FROM accounts WHERE username=?");
    $stmt->execute([$targetUsername]);
    $target = $stmt->fetch();
    if (!$target) { http_response_code(404); echo '<!DOCTYPE html><html><body style="background:#0c0c0c;color:#f2f2f2;font-family:Outfit,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh"><div style="text-align:center"><div style="font-size:48px;margin-bottom:16px">😔</div><h1 style="font-size:22px">Пользователь не найден</h1><a href="/" style="color:#7c5cff;text-decoration:none;margin-top:16px;display:block">← В каталог</a></div></body></html>'; exit; }
    $tid = (int)$target['id'];
    $privacy = $target['profile_privacy'] ?? 'public';
    $viewer = $currentAccount;
    $viewerIsAdmin = false;
    if ($viewer) {
        $vtg = (int)($viewer['tg_user_id']??0);
        $viewerIsAdmin = in_array($vtg,$hardcodedAdmins) || (!empty($viewer['is_admin'])&&$viewer['is_admin']);
    }
    $isSelf = $viewer && (int)$viewer['id'] === $tid;
    if ($isSelf) { header('Location: /profile'); exit; }
    $isFriend = false;
    $friendshipId = null;
    $friendshipStatus = null;
    $friendshipIsMine = false;
    if ($viewer) {
        $fStmt = $pdo->prepare("SELECT id,status,requester_id FROM friendships WHERE ((requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?))");
        $fStmt->execute([(int)$viewer['id'],$tid,$tid,(int)$viewer['id']]);
        $fs = $fStmt->fetch();
        if ($fs) {
            $friendshipId = (int)$fs['id'];
            $friendshipStatus = $fs['status'];
            $friendshipIsMine = (int)$fs['requester_id'] === (int)$viewer['id'];
            $isFriend = $fs['status'] === 'accepted';
        }
    }
    $canView = $viewerIsAdmin || $privacy==='public' || ($privacy==='friends'&&$isFriend);
    $custStmt = $pdo->prepare("SELECT * FROM profile_customizations WHERE account_id=?"); $custStmt->execute([$tid]);
    $custom = $custStmt->fetch() ?: ['avatar_url'=>null,'banner_url'=>null,'banner_color'=>'#1a1a2e','bio'=>null];
    $tgid = (int)($target['tg_user_id']??0);
    $targetIsAdmin = in_array($tgid,$hardcodedAdmins) || (!empty($target['is_admin'])&&$target['is_admin']);
    $adminTag = $target['admin_tag'] ?? null;
    $libItems = [];
    $libStats = ['total'=>0,'read'=>0,'now'=>0];
    if ($canView) {
        $stTotal=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=?");$stTotal->execute([$tid]);$libStats['total']=(int)$stTotal->fetchColumn();
        $stRead=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=? AND status='read'");$stRead->execute([$tid]);$libStats['read']=(int)$stRead->fetchColumn();
        $stNow=$pdo->prepare("SELECT COUNT(*) FROM user_manga_status WHERE account_id=? AND status='now'");$stNow->execute([$tid]);$libStats['now']=(int)$stNow->fetchColumn();
        $libStmt=$pdo->prepare("SELECT m.id,m.title,m.cover_imgbb_url,s.status FROM user_manga_status s JOIN manga m ON s.manga_id=m.id WHERE s.account_id=? ORDER BY s.manga_id DESC LIMIT 30");
        $libStmt->execute([$tid]);$libItems=$libStmt->fetchAll();
    }
    $fListStmt=$pdo->prepare("SELECT a.username,pc.avatar_url FROM friendships f JOIN accounts a ON (CASE WHEN f.requester_id=? THEN f.addressee_id ELSE f.requester_id END)=a.id LEFT JOIN profile_customizations pc ON pc.account_id=a.id WHERE (f.requester_id=? OR f.addressee_id=?) AND f.status='accepted' LIMIT 12");
    $fListStmt->execute([$tid,$tid,$tid]);$friendsList=$fListStmt->fetchAll();
    // Fetch XP/Level for public display
    $targetXp = (int)($target['user_xp'] ?? 0);
    $targetLevel = (int)($target['user_level'] ?? 1);
    $xpForLevel = 100 + ($targetLevel - 1) * 50;
    $totalXpToLevel = 0;
    for ($i = 1; $i < $targetLevel; $i++) { $totalXpToLevel += 100 + ($i - 1) * 50; }
    $currentXp = $targetXp - $totalXpToLevel;
    $xpProgress = $xpForLevel > 0 ? min(100, max(0, round(($currentXp / $xpForLevel) * 100))) : 0;
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($target['username'])?> | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#7c5cff;--green:#4ade80;--orange:#fb923c;--red:#f87171}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 50% at 50% 0%,rgba(124,92,255,0.04) 0%,transparent 55%);pointer-events:none}
.back{display:inline-flex;align-items:center;gap:7px;color:var(--muted);text-decoration:none;font-size:13px;margin-bottom:20px;transition:color .2s}.back:hover{color:var(--text)}
.wrap{max-width:580px;margin:0 auto;position:relative;z-index:1}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;margin-bottom:14px;overflow:hidden}
.profile-banner{width:100%;height:120px;background:<?=htmlspecialchars($custom['banner_color']??'#1a1a2e')?>;background-size:cover;background-position:center;overflow:hidden}
.profile-banner-img{width:100%;height:100%;object-fit:cover;display:block}
.avatar-wrap{position:relative;margin-top:-46px;margin-left:20px;display:inline-block;z-index:2}
.avatar{width:88px;height:88px;border-radius:50%;background:linear-gradient(135deg,#1a1a2e,#2e2e4e);border:4px solid var(--card);display:flex;align-items:center;justify-content:center;font-size:36px;overflow:hidden}
.avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.profile-header-row{padding:0 20px 20px;display:flex;align-items:flex-start;justify-content:space-between}
.admin-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.4);color:#ef4444;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;letter-spacing:0.5px;text-transform:uppercase;margin-left:8px;vertical-align:middle}
.verify-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(74,222,128,0.1);border:1px solid rgba(74,222,128,0.3);color:var(--green);font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;margin-left:6px;vertical-align:middle}
.username{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:3px;margin-top:12px}
.bio-text{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:10px}
.joined{font-size:11px;color:var(--muted);background:rgba(255,255,255,.04);border:1px solid var(--border);padding:3px 10px;border-radius:6px;display:inline-block}
.friend-action-btn{padding:8px 16px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;border:1px solid;transition:all .2s;flex-shrink:0;margin-top:10px;margin-left:12px;text-align:center}
.btn-add-friend{background:rgba(124,92,255,0.15);border-color:rgba(124,92,255,0.3);color:#a78bfa}
.btn-add-friend:hover{background:rgba(124,92,255,0.25)}
.btn-pending{background:rgba(255,255,255,0.04);border-color:var(--border);color:var(--muted)}
.btn-accept{background:rgba(74,222,128,0.1);border-color:rgba(74,222,128,0.3);color:var(--green)}
.btn-friends{background:rgba(74,222,128,0.06);border-color:rgba(74,222,128,0.2);color:var(--green)}
.sec-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;padding:22px 24px 0;margin-bottom:13px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 24px 22px}
.lib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:9px;padding:0 20px 20px}
.lib-card{text-decoration:none;color:var(--text);transition:all .2s}
.lib-card:hover{transform:translateY(-2px)}
.lib-cover{width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:9px;background:var(--border);display:block}
.lib-cover-ph{width:100%;aspect-ratio:2/3;border-radius:9px;background:rgba(255,255,255,.04);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:22px}
.lib-title{font-size:10px;font-weight:600;margin-top:4px;line-height:1.3;color:var(--text2);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.friends-row{display:flex;gap:10px;flex-wrap:wrap;padding:0 20px 20px}
.friend-chip{display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:9px;padding:7px 10px;text-decoration:none;color:var(--text2);transition:all .2s}
.friend-chip:hover{border-color:var(--border2)}
.friend-chip-av{width:28px;height:28px;border-radius:50%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;font-size:12px;overflow:hidden;flex-shrink:0}
.friend-chip-av img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.friend-chip-name{font-size:12px;font-weight:600}
.private-notice{padding:40px 24px;text-align:center;color:var(--muted)}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;pointer-events:none;border:1px solid var(--border2);animation:ti .25s ease;white-space:nowrap}
@keyframes ti{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
</style>
</head>
<body>
<div class="wrap">
    <a href="/" class="back">← Каталог</a>

    <div class="card">
        <div class="profile-banner">
            <?php if (!empty($custom['banner_url'])): ?>
            <img class="profile-banner-img" src="<?=htmlspecialchars($custom['banner_url'])?>" alt="">
            <?php endif; ?>
        </div>
        <div class="avatar-wrap">
            <div class="avatar">
                <?php if (!empty($custom['avatar_url'])): ?>
                <img src="<?=htmlspecialchars($custom['avatar_url'])?>" alt="">
                <?php else: ?>👤<?php endif; ?>
            </div>
        </div>
        <div class="profile-header-row">
            <div>
                <div class="username">
                    <?=htmlspecialchars($target['username'])?>
                    <?php if ($targetIsAdmin): ?><span class="admin-badge">⚡ <?=htmlspecialchars($adminTag ?? 'ADMIN')?></span><?php endif; ?>
                    <?php if ($target['is_verified']): ?><span class="verify-badge">✓</span><?php endif; ?>
                </div>
                <?php if (!empty($custom['bio'])): ?>
                <div class="bio-text"><?=nl2br(htmlspecialchars($custom['bio']))?></div>
                <?php endif; ?>
                <div class="joined">На сайте с: <?=date('d.m.Y', strtotime($target['created_at']))?></div>
            </div>
            <?php if ($viewer): ?>
            <div style="display:flex;flex-direction:column;gap:7px;align-items:flex-end">
                <?php if ($friendshipStatus === 'accepted'): ?>
                <button class="friend-action-btn btn-friends" onclick="removeFriend(<?=$friendshipId?>)">👥 Друзья</button>
                <?php elseif ($friendshipStatus === 'pending' && $friendshipIsMine): ?>
                <button class="friend-action-btn btn-pending">⏳ Ожидание</button>
                <?php elseif ($friendshipStatus === 'pending' && !$friendshipIsMine): ?>
                <button class="friend-action-btn btn-accept" onclick="acceptFriend(<?=$friendshipId?>)">✓ Принять</button>
                <?php else: ?>
                <button class="friend-action-btn btn-add-friend" onclick="addFriend('<?=htmlspecialchars($target['username'])?>')">+ В друзья</button>
                <?php endif; ?>
                <button class="friend-action-btn" style="background:rgba(255,255,255,0.05);border-color:var(--border2);color:var(--text2);font-size:11px;padding:6px 12px" onclick="openMsgToUser(<?=$tid?>,'<?=htmlspecialchars($target['username'])?>')">✉️ Написать</button>
            </div>
            <?php else: ?>
            <a href="/login" class="friend-action-btn btn-add-friend" style="text-decoration:none">+ В друзья</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- XP/Level block -->
    <div class="card" style="padding:18px 20px">
        <div style="display:flex;align-items:center;gap:14px">
            <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#7c5cff,#5a4ca0);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;flex-shrink:0"><?=$targetLevel?></div>
            <div style="flex:1"><div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:5px">⭐ Уровень <?=$targetLevel?></div><div style="height:5px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden;margin-bottom:4px"><div style="height:100%;background:linear-gradient(90deg,#7c5cff,#a78bfa);width:<?=$xpProgress?>%;border-radius:3px"></div></div><div style="font-size:10px;color:var(--muted)"><?=$currentXp?>/<?=$xpForLevel?> XP до следующего уровня</div></div>
            <div style="text-align:right;flex-shrink:0"><div style="font-size:18px;font-weight:800;color:var(--text2)"><?=$targetXp?></div><div style="font-size:9px;color:var(--muted)">Всего XP</div></div>
        </div>
    </div>
    <?php if ($canView): ?>
    <div class="card">
        <div class="sec-title">📚 Библиотека</div>
        <div class="stats-row">
            <div class="stat"><div class="stat-n"><?=$libStats['total']?></div><div class="stat-l">Всего</div></div>
            <div class="stat"><div class="stat-n"><?=$libStats['now']?></div><div class="stat-l">Читает</div></div>
            <div class="stat"><div class="stat-n"><?=$libStats['read']?></div><div class="stat-l">Прочитано</div></div>
        </div>
        <?php if (!empty($libItems)): ?>
        <div class="lib-grid">
            <?php foreach(array_slice($libItems,0,12) as $li): ?>
            <a class="lib-card" href="/read/<?=(int)$li['id']?>">
                <?php if(!empty($li['cover_imgbb_url'])): ?>
                <img class="lib-cover" src="<?=htmlspecialchars($li['cover_imgbb_url'])?>" alt="" onerror="this.style.display='none';this.nextSibling.style.display='flex'">
                <div class="lib-cover-ph" style="display:none">📖</div>
                <?php else: ?>
                <div class="lib-cover-ph">📖</div>
                <?php endif; ?>
                <div class="lib-title"><?=htmlspecialchars($li['title'])?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif ($privacy === 'private'): ?>
    <div class="card"><div class="private-notice">🔒 Профиль закрыт</div></div>
    <?php else: ?>
    <div class="card"><div class="private-notice">👥 Только для друзей<br><small style="font-size:11px;margin-top:6px;display:block">Добавь пользователя в друзья чтобы видеть библиотеку</small></div></div>
    <?php endif; ?>

    <?php if (!empty($friendsList)): ?>
    <div class="card">
        <div class="sec-title">👥 Друзья</div>
        <div class="friends-row">
            <?php foreach($friendsList as $fl): ?>
            <a href="/u/<?=htmlspecialchars($fl['username'])?>" class="friend-chip">
                <div class="friend-chip-av">
                    <?php if(!empty($fl['avatar_url'])): ?><img src="<?=htmlspecialchars($fl['avatar_url'])?>" alt=""><?php else: ?>👤<?php endif; ?>
                </div>
                <div class="friend-chip-name"><?=htmlspecialchars($fl['username'])?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function showToast(msg){const t=document.createElement('div');t.className='toast';t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2500);}
async function addFriend(username){
    const res=await fetch('/api/friends/add',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username})});
    const d=await res.json();
    if(d.success){showToast('✅ Запрос отправлен!');setTimeout(()=>location.reload(),800);}
    else showToast('❌ '+(d.error||'Ошибка'));
}
async function acceptFriend(id){
    const res=await fetch(`/api/friends/${id}/accept`,{method:'POST'});
    const d=await res.json();
    if(d.success){showToast('✅ Теперь вы друзья!');setTimeout(()=>location.reload(),800);}
}
async function removeFriend(id){
    if(!confirm('Удалить из друзей?'))return;
    const res=await fetch(`/api/friends/${id}/remove`,{method:'POST'});
    const d=await res.json();
    if(d.success){showToast('Удалено из друзей');setTimeout(()=>location.reload(),800);}
}

// Открыть новый чат с пользователем
function openMsgToUser(userId, username) {
    openMessagesModal();
    setTimeout(() => openDialog(userId, username), 150);
}

</script>
</body></html><?php exit; }


// /verify-email (separate page after registration)
if ($path==='/verify-email') {
    $account = getCurrentAccount($pdo);
    if (!$account) { header('Location: /register'); exit; }
    if ($account['is_verified']) { header('Location: /'); exit; }
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Подтверждение email | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#e0e0e0;--orange:#fb923c}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px 32px;width:100%;max-width:400px;text-align:center}
.logo{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;letter-spacing:2px;color:var(--text2);text-decoration:none;display:block;margin-bottom:24px}
.icon{font-size:48px;margin-bottom:16px}
h1{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;margin-bottom:8px}
p{color:var(--muted);font-size:13px;margin-bottom:24px;line-height:1.6}
.code-input{width:100%;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:12px;color:var(--text);font-size:28px;font-family:monospace;letter-spacing:10px;text-align:center;padding:16px;outline:none;transition:border-color .2s;margin-bottom:12px}
.code-input:focus{border-color:var(--border2)}
.btn{width:100%;padding:13px;background:var(--text);color:var(--bg);border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .2s;margin-bottom:12px}
.btn:hover{opacity:.88}.btn:disabled{opacity:.4;cursor:not-allowed}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);border-radius:9px;padding:10px 14px;color:#fca5a5;font-size:12px;margin-bottom:12px;display:none}
.err.show{display:block}
.resend{color:var(--muted);font-size:12px;cursor:pointer;text-decoration:underline}
.skip{display:block;margin-top:16px;color:var(--muted);font-size:12px;text-decoration:none}
.skip:hover{color:var(--text)}
</style></head>
<body>
<div class="box">
    <a href="/" class="logo">⚫ BLACKWATCH</a>
    <div class="icon">📧</div>
    <h1>Подтверди email</h1>
    <p>Мы отправили 6-значный код на <strong style="color:var(--text)"><?=htmlspecialchars($account['email'])?></strong></p>
    <div class="err" id="err"></div>
    <input class="code-input" type="text" id="code" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
    <button class="btn" id="btn" onclick="verify()">Подтвердить →</button>
    <span class="resend" onclick="resend()">Отправить повторно</span>
    <a href="/" class="skip">Пропустить, сделаю позже →</a>
</div>
<script>
async function verify(){
    const btn=document.getElementById('btn');const err=document.getElementById('err');
    const code=document.getElementById('code').value.trim();
    if(code.length!==6){err.textContent='Введи 6 цифр';err.classList.add('show');return;}
    btn.disabled=true;btn.textContent='Проверяем...';err.classList.remove('show');
    const res=await fetch('/api/auth/verify-email',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})});
    const d=await res.json();
    if(d.success){window.location.href='/';}
    else{err.textContent=d.error||'Неверный код';err.classList.add('show');}
    btn.disabled=false;btn.textContent='Подтвердить →';
}
async function resend(){
    const res=await fetch('/api/auth/resend-verify',{method:'POST'});
    const d=await res.json();
    alert(d.success?'📧 Код отправлен повторно!':'Ошибка: '+d.error);
}
document.getElementById('code').addEventListener('keydown',e=>{if(e.key==='Enter')verify();});
</script>
</body></html><?php exit; }

# ========================= VIEWERS =========================

if (preg_match('#^/view/(\d+)$#',$path,$m)){
    $id=(int)$m[1];$stmt=$pdo->prepare("SELECT id,title,telegraph_url FROM manga WHERE id=?");$stmt->execute([$id]);$manga=$stmt->fetch();
    if(!$manga){http_response_code(404);die('404');}
    $title=htmlspecialchars($manga['title']);$telegraphUrl=htmlspecialchars($manga['telegraph_url']??'');
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"><title><?=$title?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#000;color:#fff;font-family:sans-serif;overflow:hidden;touch-action:none}
.reader{height:100dvh;display:flex;align-items:center;justify-content:center;background:#0a0a0a;position:relative}
#page{max-width:100%;max-height:100dvh;object-fit:contain;display:none;user-select:none;-webkit-user-drag:none}
.nav-area{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer}
.nav-area.prev{left:0}.nav-area.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);padding:8px 20px;border-radius:999px;z-index:100;font-size:14px;pointer-events:none;white-space:nowrap}
.back-btn{position:fixed;top:16px;left:16px;z-index:200;color:#fff;text-decoration:none;background:rgba(0,0,0,0.7);padding:10px 18px;border-radius:30px;font-size:14px;border:1px solid rgba(255,255,255,0.15);backdrop-filter:blur(10px)}
#loading{position:absolute;color:#aaa;font-size:16px;text-align:center;padding:20px}
.fallback{position:absolute;text-align:center;display:none;padding:20px}
.fallback p{margin-bottom:16px;color:#aaa}
.telegraph-link{background:#7c5cff;color:#fff;padding:12px 24px;border-radius:40px;text-decoration:none;font-weight:600;display:inline-block}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<a href="/read/<?=$id?>" class="back-btn">← Назад</a>
<div class="counter"><span id="counter">—</span></div>
<div class="nav-area prev" id="nav-prev"></div>
<div class="nav-area next" id="nav-next"></div>
<div class="reader"><div id="loading">📖 Загрузка...</div><img id="page" alt=""><div class="fallback" id="fallback"><p>❌ Страницы не найдены</p><?php if($telegraphUrl):?><a href="<?=$telegraphUrl?>" target="_blank" class="telegraph-link">📄 Telegraph</a><?php endif;?></div></div>
<script>
let pages=[],current=0;
const loadEl=document.getElementById('loading'),pageEl=document.getElementById('page'),fallEl=document.getElementById('fallback'),cntEl=document.getElementById('counter');
const mangaId=<?=$id?>;
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u)return u;const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function saveProgress(p){try{localStorage.setItem('progress_'+mangaId,p);}catch(e){}fetch('/api/progress',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,page_num:p+1,total_pages:pages.length,tg_user_id:getTgUser()})}).catch(()=>{});}
async function init(){try{const res=await fetch('/api/pages/<?=$id?>');const data=await res.json();pages=data.pages||[];if(!pages.length){loadEl.style.display='none';fallEl.style.display='block';return;}loadEl.style.display='none';let saved=0;try{saved=parseInt(localStorage.getItem('progress_'+mangaId)||'0');}catch(e){}current=saved>=pages.length?0:saved;render();}catch(e){loadEl.style.display='none';fallEl.style.display='block';}}
function render(){if(!pages[current])return;pageEl.style.display='none';const img=new Image();img.onload=()=>{pageEl.src=pages[current];pageEl.style.display='block';cntEl.innerText=(current+1)+' / '+pages.length;};img.onerror=()=>{if(current<pages.length-1){current++;render();}else{fallEl.style.display='block';}};img.src=pages[current];cntEl.innerText=(current+1)+' / '+pages.length;}
function nextPage(){if(current<pages.length-1){current++;render();saveProgress(current);}}
function prevPage(){if(current>0){current--;render();saveProgress(current);}}
document.getElementById('nav-next').onclick=nextPage;
document.getElementById('nav-prev').onclick=prevPage;
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowDown')nextPage();if(e.key==='ArrowLeft'||e.key==='ArrowUp')prevPage();});
let tx=0,ty=0;
document.addEventListener('touchstart',e=>{tx=e.changedTouches[0].screenX;ty=e.changedTouches[0].screenY;},{passive:true});
document.addEventListener('touchend',e=>{const dx=e.changedTouches[0].screenX-tx;const dy=e.changedTouches[0].screenY-ty;if(Math.abs(dx)>Math.abs(dy)&&Math.abs(dx)>40){if(dx<0)nextPage();else prevPage();}},{passive:true});
init();
</script></body></html><?php exit;}

if (preg_match('#^/view-chapter/(\d+)$#',$path,$m)){
    $chapterId=(int)$m[1];$stmt=$pdo->prepare("SELECT mc.*,m.title as manga_title,m.id as manga_id FROM manga_chapters mc JOIN manga m ON mc.manga_id=m.id WHERE mc.id=?");$stmt->execute([$chapterId]);$chapter=$stmt->fetch();
    if(!$chapter){http_response_code(404);die('404');}
    $prevCh=$pdo->prepare("SELECT id,chapter_num FROM manga_chapters WHERE manga_id=? AND chapter_num<? ORDER BY chapter_num DESC LIMIT 1");$prevCh->execute([$chapter['manga_id'],$chapter['chapter_num']]);$prevChapter=$prevCh->fetch();
    $nextCh=$pdo->prepare("SELECT id,chapter_num FROM manga_chapters WHERE manga_id=? AND chapter_num>? ORDER BY chapter_num ASC LIMIT 1");$nextCh->execute([$chapter['manga_id'],$chapter['chapter_num']]);$nextChapter=$nextCh->fetch();
    $chTitle=htmlspecialchars($chapter['manga_title'].' — Глава '.$chapter['chapter_num'].($chapter['title']?': '.$chapter['title']:''));
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"><title><?=$chTitle?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#000;color:#fff;font-family:sans-serif;overflow:hidden;touch-action:none}
.reader{height:100dvh;display:flex;align-items:center;justify-content:center;background:#0a0a0a}
#page{max-width:100%;max-height:100dvh;object-fit:contain;display:none;user-select:none}
.nav-area{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer}
.nav-area.prev{left:0}.nav-area.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);padding:8px 20px;border-radius:999px;z-index:100;font-size:14px;pointer-events:none}
.top-bar{position:fixed;top:0;left:0;right:0;z-index:200;display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:linear-gradient(180deg,rgba(0,0,0,0.85) 0%,transparent 100%);pointer-events:none}
.top-bar>*{pointer-events:all}
.back-btn{color:#fff;text-decoration:none;background:rgba(0,0,0,0.7);padding:8px 16px;border-radius:30px;font-size:13px;border:1px solid rgba(255,255,255,0.15);backdrop-filter:blur(8px)}
.ch-nav{display:flex;gap:8px}
.ch-nav a{color:#fff;text-decoration:none;background:rgba(124,92,255,0.6);padding:8px 14px;border-radius:20px;font-size:12px;font-weight:700;backdrop-filter:blur(8px)}
.ch-nav a.disabled{opacity:0.3;pointer-events:none;background:rgba(255,255,255,0.1)}
#loading{position:absolute;color:#aaa;font-size:16px}
.fallback{position:absolute;text-align:center;display:none;padding:20px}
.chapter-end{position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:300;display:none;align-items:center;justify-content:center;flex-direction:column;gap:20px;text-align:center;padding:24px;backdrop-filter:blur(4px)}
.chapter-end h2{font-size:24px;font-weight:800}.chapter-end p{color:#aaa;font-size:14px}
.end-btn{padding:14px 28px;border-radius:50px;border:none;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-family:sans-serif}
.end-next{background:#7c5cff;color:#fff}.end-back{background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2)}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<div class="top-bar">
    <a href="/read/<?=$chapter['manga_id']?>" class="back-btn">← К манге</a>
    <div class="ch-nav">
        <?php if($prevChapter):?><a href="/view-chapter/<?=$prevChapter['id']?>">← Гл. <?=$prevChapter['chapter_num']?></a><?php else:?><a class="disabled">← Нет</a><?php endif;?>
        <?php if($nextChapter):?><a href="/view-chapter/<?=$nextChapter['id']?>">Гл. <?=$nextChapter['chapter_num']?> →</a><?php else:?><a class="disabled">Нет →</a><?php endif;?>
    </div>
</div>
<div class="counter"><span id="counter">—</span></div>
<div class="nav-area prev" id="nav-prev"></div>
<div class="nav-area next" id="nav-next"></div>
<div class="reader"><div id="loading">📖 Загрузка...</div><img id="page" alt=""><div class="fallback" id="fallback"><p>❌ Страницы не найдены</p></div></div>
<div class="chapter-end" id="chapter-end">
    <h2>🎉 Глава завершена!</h2>
    <p>Глава <?=$chapter['chapter_num']?><?=$chapter['title']?': '.htmlspecialchars($chapter['title']):''?></p>
    <?php if($nextChapter):?><a class="end-btn end-next" href="/view-chapter/<?=$nextChapter['id']?>">▶ Читать главу <?=$nextChapter['chapter_num']?></a><?php else:?><p style="color:#7c5cff;font-weight:600">✅ Это последняя глава</p><?php endif;?>
    <a class="end-btn end-back" href="/read/<?=$chapter['manga_id']?>">← К информации о манге</a>
</div>
<script>
let pages=[],current=0;
const loadEl=document.getElementById('loading'),pageEl=document.getElementById('page'),fallEl=document.getElementById('fallback'),cntEl=document.getElementById('counter'),endEl=document.getElementById('chapter-end');
const mangaId=<?=$chapter['manga_id']?>,chapterId=<?=$chapterId?>;
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function saveProgress(p){fetch('/api/progress',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,chapter_id:chapterId,page_num:p+1,total_pages:pages.length,tg_user_id:getTgUser()})}).catch(()=>{});}
async function init(){try{const res=await fetch('/api/chapter-pages/<?=$chapterId?>');const data=await res.json();pages=data.pages||[];if(!pages.length){loadEl.style.display='none';fallEl.style.display='block';return;}loadEl.style.display='none';render();}catch(e){loadEl.style.display='none';fallEl.style.display='block';}}
function render(){if(!pages[current])return;pageEl.style.display='none';const img=new Image();img.onload=()=>{pageEl.src=pages[current];pageEl.style.display='block';cntEl.innerText=(current+1)+' / '+pages.length;};img.onerror=()=>{if(current<pages.length-1){current++;render();}else{showEnd();}};img.src=pages[current];cntEl.innerText=(current+1)+' / '+pages.length;}
function nextPage(){if(current<pages.length-1){current++;render();saveProgress(current);}else{showEnd();}}
function prevPage(){if(current>0){current--;render();saveProgress(current);}}
function showEnd(){endEl.style.display='flex';saveProgress(pages.length-1);}
document.getElementById('nav-next').onclick=nextPage;
document.getElementById('nav-prev').onclick=prevPage;
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowDown')nextPage();if(e.key==='ArrowLeft'||e.key==='ArrowUp')prevPage();});
let tx=0,ty=0;
document.addEventListener('touchstart',e=>{tx=e.changedTouches[0].screenX;ty=e.changedTouches[0].screenY;},{passive:true});
document.addEventListener('touchend',e=>{const dx=e.changedTouches[0].screenX-tx;const dy=e.changedTouches[0].screenY-ty;if(Math.abs(dx)>Math.abs(dy)&&Math.abs(dx)>40){if(dx<0)nextPage();else prevPage();}},{passive:true});
init();
</script></body></html><?php exit;}

if (preg_match('#^/read/(\d+)$#',$path,$m)){
    $id=(int)$m[1];$stmt=$pdo->prepare("SELECT id,title,description,cover_imgbb_url,file_id,telegraph_url,likes,dislikes,is_series FROM manga WHERE id=?");$stmt->execute([$id]);$manga=$stmt->fetch();
    if(!$manga){http_response_code(404);die('404');}
    // AUTH GATE: require login for reading
    if (!$currentAccount) {
        $title = htmlspecialchars($manga['title']);
        $cover = !empty($manga['cover_imgbb_url']) ? htmlspecialchars($manga['cover_imgbb_url']) : '';
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=$title?> | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--text:#f2f2f2;--muted:#666;--accent:#7c5cff}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center}
<?php if($cover):?>body::before{content:'';position:fixed;inset:0;background:url('<?=$cover?>') center/cover no-repeat;filter:blur(40px) brightness(0.15);pointer-events:none;z-index:0}<?php endif;?>
.box{position:relative;z-index:1;max-width:380px;width:100%}
.lock{font-size:56px;margin-bottom:16px}
h1{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:8px}
p{color:var(--muted);font-size:14px;line-height:1.6;margin-bottom:28px}
.btns{display:flex;gap:10px;flex-direction:column}
.btn-reg{padding:14px;background:var(--accent);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;display:block;font-family:inherit}
.btn-login{padding:14px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.15);border-radius:12px;color:var(--text);font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:block;font-family:inherit}
.back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);text-decoration:none;font-size:13px;margin-bottom:28px}
.manga-title{font-size:15px;color:rgba(255,255,255,0.6);margin-bottom:18px;font-style:italic}
</style>
</head>
<body>
<div class="box">
    <a href="/" class="back">← Каталог</a>
    <div class="lock">🔒</div>
    <div class="manga-title">«<?=$title?>»</div>
    <h1>Нужна регистрация</h1>
    <p>Чтобы читать мангу, следить за прогрессом и сохранять в библиотеку — создай аккаунт. Это бесплатно!</p>
    <div class="btns">
        <a href="/register" class="btn-reg">🚀 Зарегистрироваться</a>
        <a href="/login?redirect=/read/<?=$id?>" class="btn-login">Войти</a>
    </div>
</div>
</body></html><?php exit; }
    $userId=getEffectiveUserId($pdo);$stmtStatus=$pdo->prepare("SELECT status FROM user_manga_status WHERE user_id=? AND manga_id=?");$stmtStatus->execute([$userId,$id]);$currentStatus=$stmtStatus->fetchColumn()?:'';
    $pagesCount=0;if(!$manga['is_series']){$pagesStmt=$pdo->prepare("SELECT COUNT(*) FROM manga_pages WHERE manga_id=? AND page_url IS NOT NULL AND page_url!=''");$pagesStmt->execute([$id]);$pagesCount=(int)$pagesStmt->fetchColumn();}
    $coverSrc=!empty($manga['cover_imgbb_url'])?htmlspecialchars($manga['cover_imgbb_url']):(!empty($manga['file_id'])?'/api/cover/'.htmlspecialchars($manga['file_id']):'');
    // Get all custom statuses for user
    $customStatusesStmt=$pdo->prepare("SELECT id,name,color FROM user_custom_statuses WHERE user_id=? ORDER BY created_at ASC");$customStatusesStmt->execute([$userId]);$customStatuses=$customStatusesStmt->fetchAll();
    // Get rating
    $ratingStmt=$pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM manga_ratings WHERE manga_id=?");$ratingStmt->execute([$id]);$ratingData=$ratingStmt->fetch();
    $myRatingStmt=$pdo->prepare("SELECT rating FROM manga_ratings WHERE manga_id=? AND user_id=?");$myRatingStmt->execute([$id,$userId]);$myRating=$myRatingStmt->fetchColumn();
    // Similar manga
    $similarStmt=$pdo->prepare("SELECT id,title,cover_imgbb_url,likes FROM manga WHERE id!=? ORDER BY likes DESC, RANDOM() LIMIT 10");$similarStmt->execute([$id]);$similarManga=$similarStmt->fetchAll();
    // Genres and tags for manga
    $genresStmt=$pdo->prepare("SELECT g.name,g.slug FROM genres g JOIN manga_genres mg ON g.id=mg.genre_id WHERE mg.manga_id=? ORDER BY g.name");$genresStmt->execute([$id]);$mangaGenres=$genresStmt->fetchAll();
    $tagsStmt=$pdo->prepare("SELECT t.name,t.slug,t.is_nsfw FROM tags t JOIN manga_tags mt ON t.id=mt.tag_id WHERE mt.manga_id=? ORDER BY t.name");$tagsStmt->execute([$id]);$mangaTags=$tagsStmt->fetchAll();
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($manga['title'])?> | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--accent:#e0e0e0;--muted:#666666;--green:#4ade80;--orange:#fb923c}
.dark{--bg:#0c0c0c;--card:#161616;--border:#242424;--text:#f2f2f2;--muted:#666666}
.light{--bg:#f7f7f7;--card:#ffffff;--border:#e2e2e2;--border2:#d0d0d0;--text:#141414;--text2:#3a3a3a;--accent:#333333;--muted:#a0a0a0;--green:#16a34a;--orange:#ea580c}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;transition:background 0.3s,color 0.3s}
.back{display:inline-flex;align-items:center;gap:8px;margin:18px 20px 0;color:var(--muted);text-decoration:none;font-size:13px;font-weight:500;transition:color 0.18s;letter-spacing:0.1px}
.back:hover{color:var(--text)}
.manga-page{max-width:820px;margin:0 auto;padding:16px 16px 80px}
.hero{display:flex;gap:22px;margin-bottom:24px}
.cover-wrap{flex-shrink:0;width:155px}
@media(max-width:500px){.cover-wrap{width:105px}.hero{gap:14px}}
.cover-img{width:100%;border-radius:12px;aspect-ratio:2/3;object-fit:cover;box-shadow:0 12px 36px rgba(0,0,0,0.5)}
.cover-ph{width:100%;aspect-ratio:2/3;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--card);border:1px solid var(--border);color:var(--muted);font-size:44px}
.meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:11px}
.manga-title{font-size:20px;font-weight:800;font-family:'Syne',sans-serif;line-height:1.2;color:var(--text);letter-spacing:0.2px}
@media(max-width:500px){.manga-title{font-size:16px}}
.badge-series{background:rgba(255,255,255,0.07);color:var(--text2);border:1px solid var(--border);padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;display:inline-block;letter-spacing:0.3px}
.manga-desc{color:var(--muted);font-size:13px;line-height:1.7}
.vote-row{display:flex;gap:7px;flex-wrap:wrap}
.vote-btn{padding:6px 14px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;transition:all 0.18s;font-weight:500}
.vote-btn.like:hover,.vote-btn.like.active{background:rgba(74,222,128,0.08);border-color:rgba(74,222,128,0.4);color:var(--green)}
.vote-btn.dislike:hover,.vote-btn.dislike.active{background:rgba(248,113,113,0.08);border-color:rgba(248,113,113,0.4);color:#f87171}
/* Star rating */
.rating-block{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.rate-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;transition:all 0.18s}
.rate-btn:hover{border-color:var(--border2);color:var(--text)}
.rate-avg{font-size:12px;color:var(--muted);font-weight:400}
.star-icon{font-size:12px}
/* Status */
.status-row{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.status-btn{padding:5px 12px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;font-weight:500;transition:all 0.18s}
.status-btn:hover{border-color:var(--border2);color:var(--text2)}
.status-btn.active-now{background:rgba(251,146,60,0.1);border-color:rgba(251,146,60,0.4);color:var(--orange)}
.status-btn.active-will{background:rgba(255,255,255,0.06);border-color:var(--border2);color:var(--text)}
.status-btn.active-read{background:rgba(74,222,128,0.08);border-color:rgba(74,222,128,0.35);color:var(--green)}
.status-btn.active-custom{border-width:1px}
.status-add-btn{width:26px;height:26px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.18s;font-family:inherit}
.status-add-btn:hover{border-color:var(--border2);color:var(--text)}
.read-section{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:16px;margin-bottom:14px}
.read-section h3{font-size:11px;font-weight:700;margin-bottom:11px;font-family:'Inter',sans-serif;letter-spacing:0.7px;text-transform:uppercase;color:var(--muted)}
.read-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;transition:all 0.18s;margin-right:7px;margin-bottom:7px}
.read-primary{background:var(--text);color:var(--bg)}
.read-primary:hover{opacity:0.88;transform:translateY(-1px)}
.read-secondary{background:transparent;color:var(--text2);border:1px solid var(--border)}
.read-secondary:hover{border-color:var(--border2)}
.chapters-list{display:flex;flex-direction:column;gap:4px;max-height:400px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.chapter-item{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:9px;text-decoration:none;color:var(--text2);transition:all 0.18s}
.chapter-item:hover{border-color:var(--border2);background:rgba(255,255,255,0.04);transform:translateX(3px)}
.ch-num{font-weight:700;font-size:13px;color:var(--text)}.ch-title{font-size:11px;color:var(--muted);margin-left:5px}.ch-date{font-size:10px;color:var(--muted)}.ch-pages{font-size:10px;color:var(--muted);background:var(--card);border:1px solid var(--border);padding:2px 7px;border-radius:7px}
/* Similar manga */
.similar-section{margin-top:22px}
.similar-title{font-size:10px;font-weight:700;font-family:'Inter',sans-serif;margin-bottom:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px}
.similar-list{display:flex;gap:9px;overflow-x:auto;padding-bottom:6px;scrollbar-width:none}
.similar-list::-webkit-scrollbar{display:none}
.sim-card{flex:0 0 86px;text-decoration:none;color:var(--text);transition:all 0.18s}
.sim-card:hover{transform:translateY(-3px)}
.sim-cover{width:86px;height:115px;object-fit:cover;border-radius:9px;background:var(--card);border:1px solid var(--border);display:block}
.sim-title{font-size:10px;font-weight:600;margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3;color:var(--text2)}
/* Modal for rating */
.rating-modal{position:fixed;inset:0;background:rgba(0,0,0,0.88);backdrop-filter:blur(14px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.rating-modal.open{display:flex}
.rating-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:26px;text-align:center;max-width:320px;width:100%}
.rating-box h3{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;margin-bottom:5px;color:var(--text)}
.rating-box p{color:var(--muted);font-size:13px;margin-bottom:18px}
.stars-row{display:flex;gap:5px;justify-content:center;margin-bottom:18px}
.star-r{font-size:26px;cursor:pointer;transition:transform 0.12s;filter:grayscale(1);opacity:0.35;color:var(--text)}
.star-r:hover,.star-r.active{filter:none;opacity:1;transform:scale(1.15)}
.rate-submit{width:100%;padding:11px;background:var(--text);color:var(--bg);border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity 0.18s}
.rate-submit:hover{opacity:0.85}
.rate-cancel{width:100%;padding:9px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;margin-top:7px}
/* Custom status modal */
.cstatus-modal{position:fixed;inset:0;background:rgba(0,0,0,0.88);backdrop-filter:blur(14px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.cstatus-modal.open{display:flex}
.cstatus-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px;max-width:320px;width:100%}
.cstatus-box h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:800;margin-bottom:14px;color:var(--text)}
.cstatus-box input{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:14px;padding:9px 11px;margin-bottom:10px;font-family:inherit}
.cstatus-box input:focus{outline:none;border-color:var(--border2)}
.color-row{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:13px}
.color-opt{width:24px;height:24px;border-radius:6px;cursor:pointer;border:2px solid transparent;transition:all 0.15s}
.color-opt.sel{border-color:#fff;transform:scale(1.15)}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,0.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;box-shadow:0 8px 28px rgba(0,0,0,0.6);animation:toastIn 0.25s ease;white-space:nowrap;border:1px solid var(--border2);backdrop-filter:blur(12px)}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.manga-page{max-width:820px;margin:0 auto;padding:16px 16px 80px}
.hero{display:flex;gap:24px;margin-bottom:28px}
.cover-wrap{flex-shrink:0;width:160px}
@media(max-width:500px){.cover-wrap{width:110px}.hero{gap:14px}}
.cover-img{width:100%;border-radius:14px;aspect-ratio:2/3;object-fit:cover;box-shadow:0 12px 40px rgba(0,0,0,0.5)}
.cover-ph{width:100%;aspect-ratio:2/3;border-radius:14px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a);font-size:50px}
.meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:12px}
.manga-title{font-size:22px;font-weight:800;font-family:'Syne',sans-serif;line-height:1.2}
@media(max-width:500px){.manga-title{font-size:17px}}
.badge-series{background:rgba(124,92,255,0.15);color:var(--accent);border:1px solid rgba(124,92,255,0.3);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
.manga-desc{color:var(--muted);font-size:13px;line-height:1.7}
.vote-row{display:flex;gap:8px;flex-wrap:wrap}
.vote-btn{padding:7px 16px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--text);font-size:13px;cursor:pointer;font-family:inherit;transition:all 0.2s}
.vote-btn.like:hover,.vote-btn.like.active{background:rgba(76,175,80,0.15);border-color:#4caf50;color:#4caf50}
.vote-btn.dislike:hover,.vote-btn.dislike.active{background:rgba(244,67,54,0.15);border-color:#f44336;color:#f44336}
/* Star rating */
.rating-block{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rate-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all 0.2s}
.rate-btn:hover{border-color:var(--accent);color:var(--accent)}
.rate-avg{font-size:12px;color:var(--muted)}
.star-icon{font-size:13px}
/* Status */
.status-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.status-btn{padding:6px 13px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;font-weight:600;transition:all 0.2s}
.status-btn:hover{border-color:var(--accent);color:var(--text)}
.status-btn.active-now{background:rgba(255,165,0,0.15);border-color:#ffa500;color:#ffa500}
.status-btn.active-will{background:rgba(124,92,255,0.15);border-color:var(--accent);color:var(--accent)}
.status-btn.active-read{background:rgba(76,175,80,0.15);border-color:#4caf50;color:#4caf50}
.status-btn.active-custom{border-width:2px}
.status-add-btn{width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;font-family:inherit}
.status-add-btn:hover{border-color:var(--accent);color:var(--accent)}
.read-section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:16px}
.read-section h3{font-size:14px;font-weight:700;margin-bottom:12px;font-family:'Syne',sans-serif}
.read-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:50px;text-decoration:none;font-weight:700;font-size:13px;transition:all 0.2s;margin-right:8px;margin-bottom:8px}
.read-primary{background:var(--accent);color:#fff}
.read-primary:hover{opacity:0.88;transform:translateY(-1px)}
.read-secondary{background:rgba(255,255,255,0.06);color:var(--text);border:1px solid var(--border)}
.read-secondary:hover{border-color:var(--accent)}
.chapters-list{display:flex;flex-direction:column;gap:5px;max-height:400px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.chapter-item{display:flex;align-items:center;justify-content:space-between;padding:10px 13px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px;text-decoration:none;color:var(--text);transition:all 0.2s}
.chapter-item:hover{border-color:var(--accent);background:rgba(124,92,255,0.06);transform:translateX(3px)}
.ch-num{font-weight:700;font-size:13px}.ch-title{font-size:11px;color:var(--muted);margin-left:6px}.ch-date{font-size:10px;color:var(--muted)}.ch-pages{font-size:10px;color:var(--muted);background:var(--card);border:1px solid var(--border);padding:2px 7px;border-radius:8px}
/* Similar manga */
.similar-section{margin-top:24px}
.similar-title{font-size:14px;font-weight:700;font-family:'Syne',sans-serif;margin-bottom:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;font-size:11px}
.similar-list{display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;scrollbar-width:none}
.similar-list::-webkit-scrollbar{display:none}
.sim-card{flex:0 0 90px;text-decoration:none;color:var(--text);transition:all 0.2s}
.sim-card:hover{transform:translateY(-3px)}
.sim-cover{width:90px;height:120px;object-fit:cover;border-radius:10px;background:var(--card);border:1px solid var(--border);display:block}
.sim-title{font-size:10px;font-weight:600;margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3}
/* Modal for rating */
.rating-modal{position:fixed;inset:0;background:rgba(0,0,0,0.8);backdrop-filter:blur(10px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.rating-modal.open{display:flex}
.rating-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px;text-align:center;max-width:320px;width:100%}
.rating-box h3{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:6px}
.rating-box p{color:var(--muted);font-size:13px;margin-bottom:20px}
.stars-row{display:flex;gap:6px;justify-content:center;margin-bottom:20px}
.star-r{font-size:28px;cursor:pointer;transition:transform 0.15s;filter:grayscale(1);opacity:0.4}
.star-r:hover,.star-r.active{filter:none;opacity:1;transform:scale(1.15)}
.rate-submit{width:100%;padding:12px;background:var(--accent);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}
.rate-cancel{width:100%;padding:10px;background:transparent;border:1px solid var(--border);border-radius:12px;color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit;margin-top:8px}
/* Custom status modal */
.cstatus-modal{position:fixed;inset:0;background:rgba(0,0,0,0.8);backdrop-filter:blur(10px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.cstatus-modal.open{display:flex}
.cstatus-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:24px;max-width:320px;width:100%}
.cstatus-box h3{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;margin-bottom:16px}
.cstatus-box input{width:100%;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px;padding:10px 12px;margin-bottom:10px;font-family:inherit}
.cstatus-box input:focus{outline:none;border-color:var(--accent)}
.color-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.color-opt{width:26px;height:26px;border-radius:6px;cursor:pointer;border:2px solid transparent;transition:all 0.15s}
.color-opt.sel{border-color:#fff;transform:scale(1.15)}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(124,92,255,0.95);color:#fff;padding:10px 22px;border-radius:50px;font-size:14px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(124,92,255,0.4);animation:toastIn 0.3s ease;white-space:nowrap}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<a href="/" class="back">← Каталог</a>
<div class="manga-page">
    <div class="hero">
        <div class="cover-wrap">
            <?php if($coverSrc):?><img class="cover-img" src="<?=$coverSrc?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><div class="cover-ph" style="display:none">📖</div><?php else:?><div class="cover-ph">📖</div><?php endif;?>
        </div>
        <div class="meta">
            <div class="manga-title"><?=htmlspecialchars($manga['title'])?></div>
            <?php if($manga['is_series']):?><div><span class="badge-series">📚 Серия глав</span></div><?php endif;?>
            <?php if($manga['description']):?><div class="manga-desc"><?=nl2br(htmlspecialchars($manga['description']))?></div><?php endif;?>
            <?php if(!empty($mangaGenres)||!empty($mangaTags)):?>
            <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:8px">
                <?php foreach($mangaGenres as $g):?><a href="/?genre=<?=htmlspecialchars($g['slug'])?>" style="font-size:10px;padding:3px 9px;border:1px solid var(--border);border-radius:20px;color:var(--text2);text-decoration:none;transition:all .15s;white-space:nowrap" onmouseover="this.style.borderColor='var(--border2)'" onmouseout="this.style.borderColor='var(--border)'"><?=htmlspecialchars($g['name'])?></a><?php endforeach;?>
                <?php foreach($mangaTags as $t):?><a href="/?tag=<?=htmlspecialchars($t['slug'])?>" style="font-size:10px;padding:3px 9px;border:1px solid var(--border);border-radius:20px;color:var(--muted);text-decoration:none;transition:all .15s;white-space:nowrap<?=$t['is_nsfw']?' border-color:rgba(239,68,68,0.3)':''?>" onmouseover="this.style.borderColor='var(--border2)'" onmouseout="this.style.borderColor='var(--border)'"><?=htmlspecialchars($t['name'])?><?=$t['is_nsfw']?' 🔞':''?></a><?php endforeach;?>
            </div>
            <?php endif;?>
            <div class="vote-row">
                <button class="vote-btn like" onclick="vote('like')">👍 <span id="likes"><?=(int)$manga['likes']?></span></button>
                <button class="vote-btn dislike" onclick="vote('dislike')">👎 <span id="dislikes"><?=(int)$manga['dislikes']?></span></button>
            </div>
            <!-- Rating block -->
            <div class="rating-block">
                <button class="rate-btn" onclick="openRatingModal()">
                    <span class="star-icon">★</span> Оценить
                </button>
                <span class="rate-avg" id="rate-avg-text"><?=($ratingData['cnt']>0)?round((float)$ratingData['avg'],1).' / 10 ('.(int)$ratingData['cnt'].' оценок)':'Нет оценок'?></span>
            </div>
            <div class="status-row" id="status-row">
                <button class="status-btn <?=$currentStatus==='now'?'active-now':''?>" onclick="setStatus('now')">📖 Читаю</button>
                <button class="status-btn <?=$currentStatus==='will'?'active-will':''?>" onclick="setStatus('will')">🔖 Буду читать</button>
                <button class="status-btn <?=$currentStatus==='read'?'active-read':''?>" onclick="setStatus('read')">✅ Прочитано</button>
                <?php foreach($customStatuses as $cs):?>
                <button class="status-btn <?=$currentStatus===$cs['name']?'active-custom':''?>" style="<?=$currentStatus===$cs['name']?'background:'.htmlspecialchars($cs['color']).'22;border-color:'.htmlspecialchars($cs['color']).';color:'.htmlspecialchars($cs['color']):''?>" onclick="setStatus('<?=htmlspecialchars(addslashes($cs['name']))?>')"><?=htmlspecialchars($cs['name'])?></button>
                <?php endforeach;?>
                <button class="status-add-btn" onclick="openCStatusModal()" title="Добавить свой статус">+</button>
            </div>
        </div>
    </div>
    <div class="read-section">
        <?php if($manga['is_series']):?>
        <h3>📚 Список глав</h3>
        <div class="chapters-list" id="chapters-list"><div style="color:var(--muted);padding:8px 0">Загрузка глав...</div></div>
        <?php else:?>
        <h3>📖 Читать</h3>
        <?php if($pagesCount>0):?><a href="/view/<?=$id?>" class="read-btn read-primary">📖 Читать (<?=$pagesCount?> стр.)</a><?php endif;?>
        <?php if($manga['telegraph_url']):?><a href="<?=htmlspecialchars($manga['telegraph_url'])?>" target="_blank" class="read-btn read-secondary">📄 Telegraph</a><?php endif;?>
        <?php if(!$pagesCount&&!$manga['telegraph_url']):?><p style="color:var(--muted);font-size:14px">😔 Страницы ещё не добавлены</p><?php endif;?>
        <?php endif;?>
    </div>
    <!-- Similar manga -->
    <?php if(!empty($similarManga)):?>
    <div class="similar-section">
        <div class="similar-title">Похожая манга</div>
        <div class="similar-list">
            <?php foreach($similarManga as $sm):$smCover=!empty($sm['cover_imgbb_url'])?htmlspecialchars($sm['cover_imgbb_url']):'';?>
            <a class="sim-card" href="/read/<?=(int)$sm['id']?>">
                <?php if($smCover):?><img class="sim-cover" src="<?=$smCover?>" alt="" onerror="this.style.background='#1a1a2e'">
                <?php else:?><div class="sim-cover" style="display:flex;align-items:center;justify-content:center;font-size:28px">📖</div><?php endif;?>
                <div class="sim-title"><?=htmlspecialchars($sm['title'])?></div>
            </a>
            <?php endforeach;?>
        </div>
    </div>
    <?php endif;?>

    <!-- ===== КОММЕНТАРИИ НА СТРАНИЦЕ МАНГИ ===== -->
    <div id="manga-comments-section" style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-top:16px">
        <div style="font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px;font-family:'Syne',sans-serif">
            💬 Комментарии <span style="background:rgba(255,255,255,0.07);color:var(--muted);border-radius:20px;padding:2px 9px;font-size:10px;font-weight:600" id="manga-comments-count">(0)</span>
        </div>
        <?php if($currentAccount): ?>
        <div style="margin-bottom:16px;display:flex;flex-direction:column;gap:8px">
            <textarea id="manga-comment-input" placeholder="Поделись мнением о манге..."
                style="width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;resize:none;outline:none;min-height:72px;max-height:150px;line-height:1.5;transition:border-color .2s"
                onfocus="this.style.borderColor='var(--border2)'" onblur="this.style.borderColor='var(--border)'"></textarea>
            <div style="display:flex;justify-content:flex-end">
                <button onclick="submitMangaComment(<?=(int)$id?>)" style="padding:8px 18px;background:var(--text);color:var(--bg);border:none;border-radius:9px;cursor:pointer;font-weight:700;font-size:13px;font-family:inherit;transition:opacity .2s" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    📤 Отправить
                </button>
            </div>
        </div>
        <?php else: ?>
        <div style="margin-bottom:14px;padding:12px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:9px;font-size:12px;color:var(--muted);text-align:center">
            <a href="/login" style="color:var(--text2);text-decoration:none;font-weight:600">Войди</a> чтобы оставить комментарий
        </div>
        <?php endif; ?>
        <div id="manga-comments-list" style="display:flex;flex-direction:column;gap:10px">
            <div style="text-align:center;color:var(--muted);font-size:12px;padding:20px 0">Загрузка...</div>
        </div>
    </div>
</div>

<!-- Rating modal -->
<div class="rating-modal" id="rating-modal">
<div class="rating-box">
    <h3>★ Оценить мангу</h3>
    <p>Выбери оценку от 1 до 10</p>
    <div class="stars-row" id="stars-row">
        <?php for($i=1;$i<=10;$i++):?><span class="star-r <?=($myRating>=$i)?'active':''?>" data-val="<?=$i?>">★</span><?php endfor;?>
    </div>
    <button class="rate-submit" onclick="submitRating()">Сохранить оценку</button>
    <button class="rate-cancel" onclick="closeRatingModal()">Отмена</button>
</div>
</div>

<!-- Custom status modal -->
<div class="cstatus-modal" id="cstatus-modal">
<div class="cstatus-box">
    <h3>+ Новый статус</h3>
    <?php if(!empty($customStatuses)):?>
    <div style="margin-bottom:12px">
        <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:7px">Мои статусы</div>
        <?php foreach($customStatuses as $cs):?>
        <div style="display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:9px;padding:7px 10px;margin-bottom:5px">
            <span style="font-size:12px;font-weight:600;color:<?=htmlspecialchars($cs['color'])?>"><?=htmlspecialchars($cs['name'])?></span>
            <button onclick="confirmDeleteStatus(<?=(int)$cs['id']?>, '<?=htmlspecialchars(addslashes($cs['name']))?>')" style="width:22px;height:22px;border-radius:6px;background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.25);color:var(--red);font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;transition:all 0.18s" title="Удалить статус">✕</button>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>
    <input type="text" id="cs-name" placeholder="Название статуса..." maxlength="50">
    <div class="color-row" id="cs-colors"></div>
    <button style="width:100%;padding:11px;background:var(--accent);border:none;border-radius:10px;color:#fff;font-weight:700;cursor:pointer;font-family:inherit;margin-bottom:8px;transition:all 0.2s" onclick="saveCustomStatus()">Добавить</button>
    <button style="width:100%;padding:9px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);cursor:pointer;font-family:inherit;transition:all 0.2s" onclick="closeCStatusModal()">Отмена</button>
</div>
</div>

<!-- Delete status confirm modal -->
<div id="delete-status-modal" style="position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(12px);z-index:600;display:none;align-items:center;justify-content:center;padding:16px">
<div style="background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;max-width:300px;width:100%;text-align:center">
    <div style="font-size:32px;margin-bottom:12px">⚠️</div>
    <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;margin-bottom:8px">Удалить статус?</div>
    <div style="color:var(--muted);font-size:13px;margin-bottom:20px">Вы действительно хотите удалить статус <strong id="del-status-name" style="color:var(--text)"></strong>?</div>
    <div style="display:flex;gap:8px">
        <button onclick="cancelDeleteStatus()" style="flex:1;padding:10px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);cursor:pointer;font-family:inherit;font-size:13px;transition:all 0.2s">Отмена</button>
        <button onclick="executeDeleteStatus()" style="flex:1;padding:10px;background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);border-radius:10px;color:var(--red);font-weight:700;cursor:pointer;font-family:inherit;font-size:13px;transition:all 0.2s">Удалить</button>
    </div>
</div>
</div>

<script>
const mangaId=<?=$id?>;
let selectedRating=<?=(int)($myRating??0)?>;
let selectedColor='#7c5cff';
const colors=['#7c5cff','#22c55e','#f59e0b','#ef4444','#3b82f6','#ec4899','#06b6d4','#a3e635'];

function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u){document.cookie='tg_user_id='+u+';max-age='+(86400*30)+';path=/';return u;}const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function showToast(msg){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className='toast';t.innerText=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2500);}
async function vote(type){try{const res=await fetch('/api/vote',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,vote_type:type,tg_user_id:getTgUser()})});const data=await res.json();if(data.success){document.getElementById('likes').textContent=data.likes;document.getElementById('dislikes').textContent=data.dislikes;showToast(type==='like'?'👍 Лайк!':'👎 Дизлайк');}}catch(e){}}
async function setStatus(s){try{await fetch('/api/status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,status:s,tg_user_id:getTgUser()})});document.querySelectorAll('.status-btn').forEach(b=>{b.className='status-btn';b.removeAttribute('style');});const map={'now':'active-now','will':'active-will','read':'active-read'};if(map[s])event.target.classList.add(map[s]);else{event.target.classList.add('active-custom');const clr=event.target.style.borderColor||'var(--accent)';event.target.style.cssText=`background:${clr}22;border-color:${clr};color:${clr};border-width:2px`;}showToast('✅ Статус обновлён');}catch(e){}}
// Rating
function openRatingModal(){document.getElementById('rating-modal').classList.add('open');initStars();}
function closeRatingModal(){document.getElementById('rating-modal').classList.remove('open');}
function initStars(){const stars=document.querySelectorAll('.star-r');stars.forEach(s=>{s.addEventListener('mouseenter',()=>{const v=parseInt(s.dataset.val);stars.forEach((st,i)=>{st.classList.toggle('active',i<v);});});s.addEventListener('click',()=>{selectedRating=parseInt(s.dataset.val);stars.forEach((st,i)=>{st.classList.toggle('active',i<selectedRating);});});});document.getElementById('stars-row').addEventListener('mouseleave',()=>{stars.forEach((st,i)=>{st.classList.toggle('active',i<selectedRating);});});}
async function submitRating(){if(!selectedRating){showToast('Выбери оценку');return;}try{const res=await fetch('/api/rate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,rating:selectedRating,tg_user_id:getTgUser()})});const d=await res.json();if(d.success){document.getElementById('rate-avg-text').textContent=d.avg+' / 10 ('+d.count+' оценок)';showToast('★ Оценка '+selectedRating+' сохранена!');closeRatingModal();}}catch(e){}}
// Custom statuses
function openCStatusModal(){const cr=document.getElementById('cs-colors');cr.innerHTML=colors.map(c=>`<div class="color-opt${c===selectedColor?' sel':''}" style="background:${c}" data-c="${c}" onclick="pickColor(this,'${c}')"></div>`).join('');document.getElementById('cstatus-modal').classList.add('open');}
function closeCStatusModal(){document.getElementById('cstatus-modal').classList.remove('open');}
function pickColor(el,c){selectedColor=c;document.querySelectorAll('.color-opt').forEach(o=>o.classList.remove('sel'));el.classList.add('sel');}
async function saveCustomStatus(){const name=document.getElementById('cs-name').value.trim();if(!name){showToast('Введи название');return;}try{await fetch('/api/custom-statuses/add',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,color:selectedColor,tg_user_id:getTgUser()})});showToast('✅ Статус добавлен');closeCStatusModal();setTimeout(()=>location.reload(),800);}catch(e){}}
let _delStatusId=null;
function confirmDeleteStatus(id,name){_delStatusId=id;document.getElementById('del-status-name').textContent=name;const m=document.getElementById('delete-status-modal');m.style.display='flex';}
function cancelDeleteStatus(){_delStatusId=null;document.getElementById('delete-status-modal').style.display='none';}
async function executeDeleteStatus(){if(!_delStatusId)return;try{await fetch(`/api/custom-statuses/${_delStatusId}/delete`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tg_user_id:getTgUser()})});showToast('🗑 Статус удалён');cancelDeleteStatus();setTimeout(()=>location.reload(),600);}catch(e){showToast('❌ Ошибка');}}
<?php if($manga['is_series']):?>
async function loadChapters(){try{const res=await fetch('/api/chapters/<?=$id?>');const data=await res.json();const list=document.getElementById('chapters-list');if(!data.chapters?.length){list.innerHTML='<div style="color:var(--muted);padding:8px 0">Глав пока нет</div>';return;}list.innerHTML=data.chapters.map(ch=>`<a class="chapter-item" href="/view-chapter/${ch.id}"><div><span class="ch-num">Глава ${ch.chapter_num}</span>${ch.title?`<span class="ch-title">${escapeHtml(ch.title)}</span>`:''}</div><div style="display:flex;gap:8px;align-items:center">${ch.page_count>0?`<span class="ch-pages">${ch.page_count} стр.</span>`:''}<span class="ch-date">${new Date(ch.created_at).toLocaleDateString('ru-RU')}</span></div></a>`).join('');}catch(e){document.getElementById('chapters-list').innerHTML='<div style="color:var(--muted)">Ошибка загрузки</div>';}}
loadChapters();
<?php endif;?>

// ===== MANGA PAGE COMMENTS =====
let _mangaReplyTo = null;
const _mangaCurrentUser = <?=json_encode($currentAccount ? $currentAccount['username'] : null)?>;
const _mangaCurrentAccId = <?=json_encode($currentAccount ? (int)$currentAccount['id'] : null)?>;
const _mangaIsAdmin = <?=json_encode($currentAccount && isAccountAdmin($pdo, (int)$currentAccount['id']))?>;

async function loadMangaComments(mangaId) {
    try {
        const res = await fetch(`/api/comments/${mangaId}`);
        const data = await res.json();
        const list = document.getElementById('manga-comments-list');
        const countEl = document.getElementById('manga-comments-count');
        if (!list) return;
        if (!data.success || !data.comments || data.comments.length === 0) {
            list.innerHTML = '<div style="text-align:center;color:var(--muted);font-size:12px;padding:20px 0">💬 Комментариев пока нет. Будь первым!</div>';
            if (countEl) countEl.textContent = '(0)';
            return;
        }
        if (countEl) countEl.textContent = `(${data.comments.length})`;
        const commentMap = {};
        data.comments.forEach(c => { commentMap[c.id] = c; });
        list.innerHTML = data.comments.map(c => {
            const canDel = _mangaCurrentAccId && (c.account_id == _mangaCurrentAccId || _mangaIsAdmin);
            const replyRef = c.reply_to && commentMap[c.reply_to]
                ? `<div style="background:rgba(255,255,255,0.03);border-left:2px solid var(--border2);border-radius:0 6px 6px 0;padding:4px 9px;margin-bottom:7px;font-size:11px;color:var(--muted)"><span style="font-weight:600;color:var(--text2)">@${escapeHtml(commentMap[c.reply_to].username)}</span>: ${escapeHtml(commentMap[c.reply_to].text.substring(0,60))}${commentMap[c.reply_to].text.length>60?'...':''}</div>`
                : '';
            return `<div id="cmt-${c.id}" style="padding:12px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px">
                <div style="display:flex;align-items:center;gap:9px;margin-bottom:8px">
                    <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--border2),var(--border));display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;overflow:hidden">
                        ${c.avatar_url ? `<img src="${escapeHtml(c.avatar_url)}" style="width:100%;height:100%;object-fit:cover">` : escapeHtml(c.username[0].toUpperCase())}
                    </div>
                    <div style="flex:1">
                        <a href="/u/${escapeHtml(c.username)}" style="font-weight:600;font-size:12px;color:var(--text2);text-decoration:none">${escapeHtml(c.username)}</a>
                        <div style="font-size:10px;color:var(--muted)">${new Date(c.created_at).toLocaleDateString('ru-RU',{day:'2-digit',month:'short',year:'numeric'})}</div>
                    </div>
                    <div style="display:flex;gap:5px;flex-shrink:0">
                        ${_mangaCurrentAccId ? `<button onclick="setMangaReply(${c.id},'${escapeHtml(c.username).replace(/'/g,"\\'")}')" style="padding:3px 9px;background:transparent;border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:10px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.color='var(--text2)';this.style.borderColor='var(--border2)'" onmouseout="this.style.color='var(--muted)';this.style.borderColor='var(--border)'">↩ Ответить</button>` : ''}
                        ${canDel ? `<button onclick="deleteMangaComment(${c.id},${mangaId})" style="padding:3px 9px;background:rgba(248,113,113,0.06);border:1px solid rgba(248,113,113,0.2);border-radius:6px;color:#f87171;font-size:10px;cursor:pointer;font-family:inherit" onmouseover="this.style.background='rgba(248,113,113,0.15)'" onmouseout="this.style.background='rgba(248,113,113,0.06)'">🗑 Удалить</button>` : ''}
                    </div>
                </div>
                <div style="font-size:13px;color:var(--text2);line-height:1.55;padding-left:39px;margin-top:-30px;padding-top:30px">${replyRef}${escapeHtml(c.text)}</div>
            </div>`;
        }).join('');
    } catch(e) { console.error(e); }
}

function setMangaReply(commentId, username) {
    _mangaReplyTo = commentId;
    const input = document.getElementById('manga-comment-input');
    if (!input) return;
    input.placeholder = `Ответ для @${username}... (Esc — отмена)`;
    input.focus();
    let hint = document.getElementById('reply-hint');
    if (!hint) { hint = document.createElement('div'); hint.id = 'reply-hint'; hint.style.cssText = 'font-size:10px;color:var(--muted);margin-bottom:6px;display:flex;align-items:center;gap:6px'; input.parentNode.insertBefore(hint, input); }
    hint.innerHTML = `<span>↩ Ответ для <strong style="color:var(--text2)">@${username}</strong></span><button onclick="cancelMangaReply()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:10px;text-decoration:underline;font-family:inherit;padding:0">Отмена</button>`;
}

function cancelMangaReply() {
    _mangaReplyTo = null;
    const input = document.getElementById('manga-comment-input');
    if (input) input.placeholder = 'Напиши комментарий...';
    const hint = document.getElementById('reply-hint');
    if (hint) hint.remove();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape' && _mangaReplyTo) cancelMangaReply(); });

async function deleteMangaComment(commentId, mangaId) {
    if (!confirm('Удалить комментарий?')) return;
    try {
        const res = await fetch(`/api/comment/${commentId}/delete`, {method:'POST'});
        const d = await res.json();
        if (d.success) { const el = document.getElementById('cmt-'+commentId); if(el) el.remove(); showToast('🗑 Удалён'); const cnt = document.getElementById('manga-comments-count'); if(cnt) cnt.textContent='('+document.querySelectorAll('[id^=cmt-]').length+')'; }
        else showToast('❌ Нет прав');
    } catch(e) { showToast('❌ Ошибка'); }
}

async function submitMangaComment(mangaId) {
    const input = document.getElementById('manga-comment-input');
    if (!input) return;
    const text = input.value.trim();
    if (!text || text.length > 500) { showToast('Комментарий: 1-500 символов'); return; }
    try {
        const body = {text};
        if (_mangaReplyTo) body.reply_to = _mangaReplyTo;
        const res = await fetch(`/api/comments/${mangaId}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        const data = await res.json();
        if (data.success) {
            input.value = '';
            cancelMangaReply();
            showToast('✅ Комментарий добавлен!');
            loadMangaComments(mangaId);
        } else {
            showToast('❌ Сначала войди в аккаунт');
        }
    } catch(e) { showToast('❌ Ошибка сети'); }
}

// Auto-load comments on read page
loadMangaComments(<?=(int)$id?>);
</script></body></html><?php exit;}

if ($path==='/library'){
    if (!$currentAccount) {
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Библиотека | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--text:#f2f2f2;--muted:#666;--accent:#7c5cff}*{margin:0;padding:0;box-sizing:border-box}body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center}.box{max-width:360px;width:100%}.lock{font-size:56px;margin-bottom:16px}h1{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:8px}p{color:var(--muted);font-size:14px;line-height:1.6;margin-bottom:28px}.btns{display:flex;gap:10px;flex-direction:column}.btn-reg{padding:14px;background:var(--accent);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:700;text-decoration:none;display:block}.btn-login{padding:14px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.15);border-radius:12px;color:var(--text);font-size:14px;font-weight:600;text-decoration:none;display:block}.back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);text-decoration:none;font-size:13px;margin-bottom:28px}</style>
</head><body><div class="box"><a href="/" class="back">← Каталог</a><div class="lock">📚</div><h1>Библиотека закрыта</h1><p>Войди или зарегистрируйся чтобы сохранять мангу в библиотеку и следить за прогрессом.</p><div class="btns"><a href="/register" class="btn-reg">🚀 Зарегистрироваться</a><a href="/login?redirect=/library" class="btn-login">Войти</a></div></div></body></html><?php exit; }
    $userId=getEffectiveUserId($pdo);
    // Get custom statuses
    $csStmt=$pdo->prepare("SELECT id,name,color FROM user_custom_statuses WHERE user_id=? ORDER BY created_at ASC");$csStmt->execute([$userId]);$customStatuses=$csStmt->fetchAll();
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Библиотека | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/style.css">
<style>
/* ===== THEME VARS — идентично главной ===== */
:root{
    --bg:#0c0c0c;--bg2:#111111;--card:#161616;--card2:#121212;
    --border:#242424;--border2:#2e2e2e;
    --text:#f2f2f2;--text2:#c8c8c8;--muted:#666666;
    --accent:#e0e0e0;--accent2:#ffffff;
    --green:#4ade80;--orange:#fb923c;--red:#f87171;--blue:#60a5fa;
    --shadow:0 8px 40px rgba(0,0,0,0.7);--shadow2:0 2px 12px rgba(0,0,0,0.4);
    --grad:linear-gradient(180deg,#0c0c0c 0%,#141414 100%);
}
.light{
    --bg:#f7f7f7;--bg2:#efefef;--card:#ffffff;--card2:#fafafa;
    --border:#e2e2e2;--border2:#d4d4d4;
    --text:#141414;--text2:#3a3a3a;--muted:#a0a0a0;
    --accent:#333333;--accent2:#111111;
    --shadow:0 4px 24px rgba(0,0,0,0.1);--shadow2:0 2px 8px rgba(0,0,0,0.06);
    --grad:linear-gradient(180deg,#f7f7f7 0%,#efefef 100%);
}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#0a0a0a;color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;transition:background 0.3s,color 0.3s;position:relative}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 60% 40% at 20% 80%,rgba(255,255,255,0.02) 0%,transparent 60%),radial-gradient(ellipse 50% 35% at 80% 10%,rgba(255,255,255,0.015) 0%,transparent 55%),linear-gradient(180deg,#080808 0%,#0c0c0c 40%,#111111 100%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 55% 25% at 50% -5%,rgba(255,255,255,0.016) 0%,transparent 55%)}
.light body{background:#f5f5f5}
.light body::before{background:radial-gradient(ellipse 110% 50% at 50% 115%,rgba(215,215,220,0.5) 0%,transparent 65%),linear-gradient(180deg,#f7f7f7 0%,#f2f2f2 50%,#ebebeb 100%)}
.light body::after{background:none}

/* ===== HEADER ===== */
header{position:sticky;top:0;z-index:200;backdrop-filter:blur(28px);-webkit-backdrop-filter:blur(28px);background:rgba(12,12,12,0.92);border-bottom:1px solid var(--border)}
.light header{background:rgba(247,247,247,0.92)}
.header-inner{max-width:1280px;margin:auto;height:58px;display:flex;align-items:center;gap:12px;padding:0 22px}
.logo{font-size:17px;font-weight:800;font-family:'Syne',sans-serif;color:var(--text2);text-decoration:none;flex-shrink:0;letter-spacing:2px;text-transform:uppercase;opacity:0.9}

/* Back button */
.back-btn{color:var(--muted);text-decoration:none;font-size:12px;margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1px solid var(--border);border-radius:8px;font-weight:500;transition:all 0.2s cubic-bezier(0.34,1.56,0.64,1);white-space:nowrap;letter-spacing:0.2px;font-family:'Inter',sans-serif}
.back-btn:hover{border-color:var(--border2);color:var(--text);background:rgba(255,255,255,0.04);transform:translateX(-2px)}
.light .back-btn:hover{background:rgba(0,0,0,0.04)}

/* ===== WRAP ===== */
.wrap{max-width:1280px;margin:auto;padding:22px 22px 80px;position:relative;z-index:1}

/* ===== PAGE TITLE ===== */
.lib-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;letter-spacing:0.5px;color:var(--text);margin-bottom:18px}
.lib-title span{color:var(--muted);font-size:13px;font-weight:400;margin-left:8px;letter-spacing:0}

/* ===== CONTROLS ===== */
.lib-controls{display:flex;gap:8px;align-items:center;margin-bottom:20px;flex-wrap:wrap}
.lib-search{flex:1;min-width:180px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:13px;padding:9px 16px;font-family:'Inter',sans-serif;transition:all 0.2s;outline:none;letter-spacing:0.1px}
.lib-search:focus{border-color:var(--border2);background:rgba(255,255,255,0.06)}
.light .lib-search{background:rgba(0,0,0,0.03)}
.sort-select{padding:8px 14px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:12px;font-family:'Inter',sans-serif;cursor:pointer;outline:none;transition:all 0.2s}
.sort-select:focus{border-color:var(--border2)}
.light .sort-select{background:rgba(0,0,0,0.03)}
.view-btns{display:flex;gap:3px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:9px;padding:3px}
.light .view-btns{background:rgba(0,0,0,0.03)}
.view-btn{padding:6px 12px;border-radius:7px;border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:11px;font-weight:500;font-family:'Inter',sans-serif;transition:all 0.2s;letter-spacing:0.2px}
.view-btn.active{background:var(--card2);border:1px solid var(--border2);color:var(--text);font-weight:600}

/* ===== SECTION HEADER ===== */
.section{margin-bottom:32px}
.section-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;font-family:'Syne',sans-serif;letter-spacing:0.8px;text-transform:uppercase;color:var(--text2);margin-bottom:14px}
.sec-count{background:rgba(255,255,255,0.08);color:var(--muted);border-radius:20px;padding:1px 8px;font-size:10px;font-weight:600;letter-spacing:0.3px}
.light .sec-count{background:rgba(0,0,0,0.07)}

/* ===== GRID view ===== */
.grid-view{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:14px}
@media(max-width:600px){.grid-view{grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px}}
@media(max-width:380px){.grid-view{grid-template-columns:repeat(2,1fr);gap:8px}}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;text-decoration:none;color:var(--text);position:relative;animation:cardIn 0.28s ease both}
.card{transition:all 0.25s cubic-bezier(0.34,1.56,0.64,1)}
.card:hover{transform:translateY(-4px);border-color:var(--border2);box-shadow:0 8px 28px rgba(0,0,0,0.3)}
.card:hover .cover{transform:scale(1.03)}
.card:active{transform:scale(0.97)!important}
@keyframes cardIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block;transition:transform 0.35s ease}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:var(--card2);color:var(--muted);font-size:32px}
.card-info{padding:9px 10px}
.card-title{font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.45;font-family:'Inter',sans-serif;color:var(--text2)}

/* ===== LIST view ===== */
.list-view{display:flex;flex-direction:column;gap:6px}
.list-item{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px 14px;text-decoration:none;color:var(--text);transition:all 0.2s}
.list-item:hover{border-color:var(--border2);transform:translateX(3px);box-shadow:var(--shadow2)}
.list-cover{width:44px;height:60px;object-fit:cover;border-radius:7px;flex-shrink:0;background:var(--card2);display:block}
.list-cover-ph{width:44px;height:60px;border-radius:7px;flex-shrink:0;background:var(--card2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--muted)}
.list-body{flex:1;min-width:0}
.list-title{font-size:13px;font-weight:600;font-family:'Syne',sans-serif;margin-bottom:3px;color:var(--text)}
.list-date{font-size:11px;color:var(--muted);font-weight:400}
.list-status{margin-left:auto;flex-shrink:0}

/* ===== BADGES ===== */
.badge{display:inline-block;padding:3px 9px;border-radius:8px;font-size:10px;font-weight:700;letter-spacing:0.3px}
.badge-now{background:rgba(251,146,60,0.1);color:var(--orange);border:1px solid rgba(251,146,60,0.25)}
.badge-will{background:rgba(255,255,255,0.06);color:var(--text2);border:1px solid var(--border)}
.badge-read{background:rgba(74,222,128,0.08);color:var(--green);border:1px solid rgba(74,222,128,0.2)}

/* ===== EMPTY / TOAST ===== */
.empty-page{text-align:center;padding:80px 20px;color:var(--muted);font-size:13px;line-height:1.7}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,0.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;box-shadow:var(--shadow);animation:toastIn 0.25s ease;white-space:nowrap;pointer-events:none;border:1px solid var(--border2);backdrop-filter:blur(12px)}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}

/* ===== MOBILE ===== */
@media(max-width:768px){
    .header-inner{height:50px;padding:0 14px}
    .wrap{padding:14px 12px 70px}
    .lib-title{font-size:18px}
}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<div id="lib-loader" style="position:fixed;inset:0;z-index:9998;background:var(--bg);display:flex;align-items:center;justify-content:center;transition:opacity 0.4s,visibility 0.4s">
  <div class="loader-ring">
    <div class="loader-dot"></div><div class="loader-dot"></div>
    <div class="loader-dot"></div><div class="loader-dot"></div>
    <div class="loader-dot"></div><div class="loader-dot"></div>
    <div class="loader-dot"></div><div class="loader-dot"></div>
  </div>
</div>
<style>
.loader-ring{position:relative;width:56px;height:56px}
.loader-dot{position:absolute;width:10px;height:10px;border-radius:50%;background:var(--border2);animation:loaderSpin 1.2s linear infinite}
.loader-dot:nth-child(1){top:0;left:50%;transform:translate(-50%,0);animation-delay:0s}
.loader-dot:nth-child(2){top:15%;right:7%;animation-delay:.15s}
.loader-dot:nth-child(3){top:50%;right:0;transform:translate(0,-50%);animation-delay:.3s}
.loader-dot:nth-child(4){bottom:15%;right:7%;animation-delay:.45s}
.loader-dot:nth-child(5){bottom:0;left:50%;transform:translate(-50%,0);animation-delay:.6s}
.loader-dot:nth-child(6){bottom:15%;left:7%;animation-delay:.75s}
.loader-dot:nth-child(7){top:50%;left:0;transform:translate(0,-50%);animation-delay:.9s}
.loader-dot:nth-child(8){top:15%;left:7%;animation-delay:1.05s}
@keyframes loaderSpin{0%,100%{opacity:.15;transform:scale(.7)}50%{opacity:1;transform:scale(1.1);background:var(--text2)}}
</style>
<header><div class="header-inner">
    <a href="/" class="logo">⬛ <span>BLACKWATCH</span></a>
    <a href="/" class="back-btn" style="margin-left:auto">← Каталог</a>
</div></header>
<div class="wrap">
    <div class="lib-title">📚 Моя библиотека <span id="lib-total-count"></span></div>
    <div class="lib-controls">
        <input class="lib-search" type="text" placeholder="🔍 Поиск в библиотеке..." id="lib-search" oninput="filterLib()">
        <select class="sort-select" id="sort-select" onchange="filterLib()">
            <option value="all">Все статусы</option>
            <option value="popular">По популярности</option>
            <option value="now">📖 Читаю</option>
            <option value="read">✅ Прочитано</option>
            <option value="will">🔖 Буду читать</option>
            <?php foreach($customStatuses as $cs):?><option value="custom_<?=htmlspecialchars($cs['name'])?>"><?=htmlspecialchars($cs['name'])?></option><?php endforeach;?>
        </select>
        <div class="view-btns">
            <button class="view-btn active" id="vb-grid" onclick="setView('grid')">⊞ Плитка</button>
            <button class="view-btn" id="vb-list" onclick="setView('list')">☰ Список</button>
        </div>
    </div>
    <div id="content"><div class="empty-page">📖 Загрузка...</div></div>
</div>
<script>
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u)return u;const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
// Hide loader after page ready
window.addEventListener('load',()=>{const l=document.getElementById('lib-loader');if(l){l.style.opacity='0';l.style.visibility='hidden';setTimeout(()=>l.remove(),450);}});
let allItems=[],currentView='grid';
const badgeMap={now:'badge-now',will:'badge-will',read:'badge-read'};
const labelMap={now:'📖 Читаю',will:'🔖 Буду читать',read:'✅ Прочитано'};
function getBadgeClass(status){return badgeMap[status]||'badge-will';}
function getBadgeLabel(status){return labelMap[status]||status;}
function setView(v){currentView=v;document.getElementById('vb-grid').classList.toggle('active',v==='grid');document.getElementById('vb-list').classList.toggle('active',v==='list');renderLib(allItems);}
function filterLib(){const q=document.getElementById('lib-search').value.toLowerCase();const sort=document.getElementById('sort-select').value;let items=allItems.filter(m=>m.title.toLowerCase().includes(q));if(sort==='now')items=items.filter(m=>m.status==='now');else if(sort==='will')items=items.filter(m=>m.status==='will');else if(sort==='read')items=items.filter(m=>m.status==='read');else if(sort==='popular')items=items.sort((a,b)=>(b.likes||0)-(a.likes||0));else if(sort.startsWith('custom_')){const sn=sort.slice(7);items=items.filter(m=>m.status===sn);}renderLib(items);}
function renderLib(items){
    const c=document.getElementById('content');
    const tc=document.getElementById('lib-total-count');if(tc)tc.textContent='('+items.length+')';
    if(!items.length){c.innerHTML='<div class="empty-page">📭 Ничего не найдено<br><br><a href="/" style="color:var(--accent)">Каталог →</a></div>';return;}
    if(currentView==='grid'){
        // Group by status
        const groups={now:[],will:[],read:[],custom:{}};
        items.forEach(m=>{if(m.status==='now')groups.now.push(m);else if(m.status==='will')groups.will.push(m);else if(m.status==='read')groups.read.push(m);else{if(!groups.custom[m.status])groups.custom[m.status]=[];groups.custom[m.status].push(m);}});
        let html='';
        const renderSection=(icon,title,arr)=>{if(!arr.length)return '';let src='';let cards=arr.map((m,i)=>{src=m.cover_imgbb_url||'';if(!src&&m.file_id)src='/api/cover/'+m.file_id;const covId='cv'+m.id,phId='ph'+m.id;const imgHtml=src?`<img class="cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="document.getElementById('${covId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`:'' ;const rHtml=m.avg_rating>0?`<div style="font-size:9px;color:#f59e0b;margin-top:2px">${'★'.repeat(Math.round(m.avg_rating/2))}${'☆'.repeat(5-Math.round(m.avg_rating/2))}<span style="color:var(--muted);margin-left:3px">${m.avg_rating}</span></div>`:'';return `<a class="card" href="/read/${m.id}" style="animation-delay:${i*25}ms">${imgHtml}<div class="cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:36px">📖</span></div><div class="card-info"><div class="card-title">${escapeHtml(m.title)}</div>${rHtml}</div></a>`;}).join('');return `<div class="section"><div class="section-title"><span>${icon}</span>${title}<span class="sec-count">${arr.length}</span></div><div class="grid-view">${cards}</div></div>`;};
        if(groups.now.length)html+=renderSection('📖','Читаю сейчас',groups.now);
        if(groups.will.length)html+=renderSection('🔖','Буду читать',groups.will);
        if(groups.read.length)html+=renderSection('✅','Прочитано',groups.read);
        Object.entries(groups.custom).forEach(([name,arr])=>{if(arr.length)html+=renderSection('🏷',name,arr);});
        c.innerHTML=html||'<div class="empty-page">📭 Список пуст</div>';
    } else {
        // List view
        let html='<div class="list-view">';
        items.forEach(m=>{let src=m.cover_imgbb_url||'';if(!src&&m.file_id)src='/api/cover/'+m.file_id;const covId='lc'+m.id,phId='lp'+m.id;html+=`<a class="list-item" href="/read/${m.id}">${src?`<img class="list-cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';document.getElementById('${phId}').style.display='flex'">`:''}<div class="list-cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}">📖</div><div class="list-body"><div class="list-title">${escapeHtml(m.title)}</div><div class="list-date">${getBadgeLabel(m.status)}</div></div><div class="list-status"><span class="badge ${getBadgeClass(m.status)}">${getBadgeLabel(m.status)}</span></div></a>`;});
        html+='</div>';c.innerHTML=html;
    }
}
async function load(){try{const res=await fetch('/api/library?tg_user_id='+getTgUser());const data=await res.json();const content=document.getElementById('content');if(!data.items?.length){content.innerHTML='<div class="empty-page">📭 Пока нет добавленной манги<br><br><a href="/" style="color:var(--accent)">В каталог →</a></div>';return;}allItems=data.items;renderLib(allItems);}catch(e){document.getElementById('content').innerHTML='<div class="empty-page">❌ Ошибка загрузки</div>';}}
load();
</script></body></html><?php exit;}

# ========================= HOME =========================

# ========================= HOME =========================
$total=$pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
$botUsername=getenv('BOT_USERNAME')?:'blackwatch_manga_bot';
// Count unread admin messages
$msgCount=(int)$pdo->query("SELECT COUNT(*) FROM admin_messages WHERE is_deleted=FALSE")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BLACKWATCH | Manga Reader</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700;800;900&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/style.css">
<style>
/* ============================================================
   BLACKWATCH v5 — RED/WHITE PREMIUM REDESIGN
   ============================================================ */

:root{
    --bg:#0a0a0b;
    --bg2:#111113;
    --card:#141416;
    --card2:#1a1a1d;
    --border:#222226;
    --border2:#333338;
    --text:#f5f5f7;
    --text2:#8e8e9a;
    --muted:#4a4a58;
    --accent:#e8192c;
    --accent2:#ff2d42;
    --accent-glow:rgba(232,25,44,.25);
    --red:#e8192c;
    --green:#22c55e;
    --orange:#f97316;
    --yellow:#eab308;
    --blue:#3b82f6;
    --purple:#8b5cf6;
    --header-h:64px;
    --r:12px;
    --r-sm:8px;
    --r-lg:22px;
    --t:.22s cubic-bezier(.4,0,.2,1);
    --shadow:0 24px 60px rgba(0,0,0,.9);
    --glow:0 0 40px rgba(232,25,44,.15);
}
.light{
    --bg:#f5f5f7;--bg2:#eaeaef;--card:#ffffff;--card2:#f0f0f5;
    --border:#dcdce8;--border2:#c8c8d8;--text:#0a0a0e;--text2:#505060;
    --muted:#9090a8;--accent:#e8192c;--accent-glow:rgba(232,25,44,.15);
    --shadow:0 12px 40px rgba(0,0,0,.08);
}

/* ── RESET ── */
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;font-size:14px;line-height:1.55;min-height:100vh;transition:background var(--t),color var(--t);overflow-x:hidden;-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit}
button{font-family:inherit;cursor:pointer;border:none;background:none}
img{display:block;max-width:100%}

/* ── PAGE LOADER ── */
#page-loader{position:fixed;inset:0;background:var(--bg);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;transition:opacity .45s ease}
#page-loader.hidden{opacity:0;pointer-events:none}
.bw-logo-load{font-family:'Bebas Neue',sans-serif;font-size:32px;letter-spacing:8px;color:var(--text);opacity:.8}
.loader-bar{width:140px;height:3px;background:var(--border2);border-radius:3px;overflow:hidden}
.loader-bar-fill{height:100%;width:30%;background:var(--accent);border-radius:3px;animation:barSlide 1.1s ease infinite}
@keyframes barSlide{0%{transform:translateX(-100%)}100%{transform:translateX(450%)}}

/* ── HEADER ── */
header{
    position:sticky;top:0;z-index:500;
    height:var(--header-h);
    background:rgba(10,10,11,.95);
    backdrop-filter:blur(24px) saturate(180%);-webkit-backdrop-filter:blur(24px) saturate(180%);
    border-bottom:1px solid var(--border);
    transition:background var(--t);
}
.light header{background:rgba(245,245,247,.97);border-bottom-color:var(--border)}
.header-inner{
    max-width:1500px;margin:auto;height:100%;
    display:flex;align-items:center;gap:14px;padding:0 28px;
}

/* Logo */
.logo{
    font-family:'Bebas Neue',sans-serif;font-size:20px;
    letter-spacing:5px;color:var(--text);
    text-decoration:none;flex-shrink:0;transition:opacity var(--t);
    display:flex;align-items:center;gap:8px;
}
.logo:hover{opacity:.8}
.logo-dot{
    width:8px;height:8px;border-radius:2px;
    background:var(--accent);flex-shrink:0;
    box-shadow:0 0 10px var(--accent);
}

/* Header search — center */
.header-search-wrap{
    flex:1;max-width:500px;margin:0 auto;
    position:relative;
}
.header-search{
    width:100%;background:rgba(255,255,255,.04);
    border:1px solid var(--border);border-radius:8px;
    color:var(--text);font-family:'Outfit',sans-serif;font-size:13px;
    padding:9px 18px 9px 40px;
    transition:all var(--t);outline:none;letter-spacing:.1px;
}
.light .header-search{background:rgba(0,0,0,.04)}
.header-search:focus{
    border-color:var(--accent);
    background:rgba(255,255,255,.06);
    box-shadow:0 0 0 3px var(--accent-glow);
}
.header-search-icon{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);
    color:var(--muted);font-size:14px;pointer-events:none;
}
.light .search{background:var(--card)}
.search:focus{border-color:var(--accent);background:var(--card2);box-shadow:0 0 0 3px var(--accent-glow)}
.search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:15px;pointer-events:none}
.search-dropdown{position:absolute;top:calc(100% + 8px);left:0;right:0;background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);z-index:300;overflow:hidden;max-height:400px;overflow-y:auto;opacity:0;transform:translateY(-8px) scale(.98);transition:opacity .2s ease,transform .2s ease;pointer-events:none}
.search-dropdown.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
.sd-item{display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;transition:background .12s;text-decoration:none;color:var(--text);border-bottom:1px solid var(--border)}
.sd-item:last-child{border-bottom:none}
.sd-item:hover{background:rgba(232,25,44,.06)}
.light .sd-item:hover{background:rgba(232,25,44,.04)}
.sd-cover{width:38px;height:52px;object-fit:cover;border-radius:6px;flex-shrink:0;background:var(--border)}
.sd-cover-ph{width:38px;height:52px;border-radius:6px;flex-shrink:0;background:var(--card2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:16px}
.sd-info{flex:1;min-width:0}
.sd-title{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.sd-meta{font-size:11px;color:var(--muted);margin-top:2px;font-weight:400}

/* Also sync header search dropdown */
.header-search-drop{position:absolute;top:calc(100% + 10px);left:0;right:0;background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);z-index:400;overflow:hidden;max-height:380px;overflow-y:auto;opacity:0;transform:translateY(-8px) scale(.98);transition:all .2s;pointer-events:none}
.header-search-drop.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}

/* Header actions */
.header-actions{margin-left:auto;display:flex;align-items:center;gap:6px;flex-shrink:0}
.hbtn{
    padding:7px 14px;border-radius:7px;
    border:1px solid var(--border);background:transparent;
    color:var(--text2);font-size:12px;font-weight:500;
    cursor:pointer;text-decoration:none;transition:all var(--t);
    display:inline-flex;align-items:center;gap:5px;
    font-family:'Outfit',sans-serif;white-space:nowrap;letter-spacing:.1px;
}
.hbtn:hover{border-color:var(--border2);color:var(--text);background:rgba(255,255,255,.04)}
.light .hbtn:hover{background:rgba(0,0,0,.04)}
.hbtn-admin{display:none}
.hbtn-admin.visible{display:inline-flex}
/* Primary accent button in nav */
.hbtn-accent{
    background:var(--accent);border-color:var(--accent);
    color:#fff;font-weight:700;
}
.hbtn-accent:hover{background:var(--accent2);border-color:var(--accent2);color:#fff;transform:translateY(-1px);box-shadow:0 4px 16px var(--accent-glow)}
.theme-btn{
    width:36px;height:36px;border-radius:7px;
    border:1px solid var(--border);background:transparent;
    color:var(--muted);font-size:15px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:all var(--t);flex-shrink:0;
}
.theme-btn:hover{border-color:var(--border2);color:var(--text)}

/* ── SIDEBAR ── */
.sidebar-icons{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:400}
.sidebar-rail{
    background:rgba(10,10,11,.97);backdrop-filter:blur(28px);
    border:1px solid var(--border);border-right:none;
    border-radius:14px 0 0 14px;
    padding:8px 7px;display:flex;flex-direction:column;gap:4px;
    box-shadow:-6px 0 30px rgba(0,0,0,.4);
}
.light .sidebar-rail{background:rgba(255,255,255,.98)}
.sidebar-icon-btn{
    width:38px;height:38px;border-radius:8px;
    border:1px solid var(--border);background:transparent;
    color:var(--muted);font-size:17px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:all var(--t);position:relative;text-decoration:none;
}
.sidebar-icon-btn:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-glow)}
.light .sidebar-icon-btn:hover{background:rgba(232,25,44,.06)}
.sidebar-badge{position:absolute;top:-4px;right:-4px;width:10px;height:10px;border-radius:50%;background:#f87171;border:2px solid var(--bg);animation:pulse-red 2s infinite}
@keyframes pulse-red{0%,100%{box-shadow:0 0 0 0 rgba(248,113,113,.5)}50%{box-shadow:0 0 0 5px rgba(248,113,113,0)}}
@media(max-width:600px){
    .sidebar-icons{display:flex;bottom:0;top:auto;right:0;left:0;transform:none;z-index:400}
    .sidebar-rail{flex-direction:row;border-radius:0;border:none;border-top:1px solid var(--border);width:100%;padding:7px 16px;justify-content:space-around;backdrop-filter:blur(28px);background:rgba(6,6,8,.97)}
    .sidebar-icon-btn{width:44px;height:44px;font-size:20px}
}
.light .sidebar-rail{background:rgba(250,250,250,.98)}

/* ── WRAP ── */
.wrap{max-width:1500px;margin:auto;padding:0 24px 110px;position:relative;z-index:1}

/* ── HERO BANNER ── */
.hero-banner{
    position:relative;height:500px;overflow:hidden;
    background:var(--card2);margin-bottom:32px;
    border-radius:0 0 20px 20px;
}
.hero-track{display:flex;height:100%;transition:transform .6s cubic-bezier(.77,0,.18,1)}
.hero-slide{
    flex:0 0 100%;height:100%;position:relative;overflow:hidden;
    display:flex;align-items:flex-end;
}
.hero-slide-bg{
    position:absolute;inset:0;
    background-size:cover;background-position:center top;
    filter:brightness(.45) saturate(1.2);
    transition:transform 8s ease;
}
.hero-slide:hover .hero-slide-bg{transform:scale(1.04)}
.hero-slide-grad{
    position:absolute;inset:0;
    background:linear-gradient(to right,rgba(10,10,11,.98) 0%,rgba(10,10,11,.65) 50%,rgba(10,10,11,.1) 100%);
}
.hero-slide-grad2{
    position:absolute;bottom:0;left:0;right:0;height:200px;
    background:linear-gradient(to top,var(--bg) 0%,transparent 100%);
}
/* Red accent bar on hero */
.hero-slide-accentbar{
    position:absolute;top:0;left:0;width:4px;height:100%;
    background:var(--accent);z-index:4;
}
.hero-content{
    position:relative;z-index:3;
    padding:0 56px 48px;max-width:680px;
}
.hero-label{
    display:inline-flex;align-items:center;gap:6px;
    font-family:'Outfit',sans-serif;font-size:10px;font-weight:700;letter-spacing:3px;text-transform:uppercase;
    color:var(--accent);margin-bottom:12px;
    border-left:3px solid var(--accent);padding-left:10px;
}
.hero-rank{
    display:inline-block;padding:4px 12px;border-radius:4px;
    font-family:'Outfit',sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
    background:var(--accent);color:#fff;margin-bottom:14px;
}
.hero-title{
    font-family:'Syne',sans-serif;
    font-size:clamp(40px,5.5vw,76px);line-height:.92;
    font-weight:800;
    letter-spacing:-1px;color:#fff;
    text-shadow:0 4px 30px rgba(0,0,0,.9);
    margin-bottom:14px;
}
.hero-desc{
    font-size:13px;color:rgba(255,255,255,.55);line-height:1.7;
    margin-bottom:24px;max-width:420px;
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;
}
.hero-stats{display:flex;gap:20px;margin-bottom:22px;flex-wrap:wrap}
.hero-stat{font-size:11px;color:rgba(255,255,255,.45);display:flex;align-items:center;gap:5px}
.hero-stat strong{color:#fff;font-weight:700}
.hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.hero-btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:11px 24px;border-radius:7px;
    font-family:'Outfit',sans-serif;font-size:13px;font-weight:700;letter-spacing:.2px;
    transition:all .25s;cursor:pointer;border:none;
}
.hero-btn-primary{background:var(--accent);color:#fff;box-shadow:0 4px 20px var(--accent-glow)}
.hero-btn-primary:hover{background:var(--accent2);transform:translateY(-2px);box-shadow:0 10px 32px var(--accent-glow)}
.hero-btn-secondary{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(8px)}
.hero-btn-secondary:hover{background:rgba(255,255,255,.14);transform:translateY(-2px)}
/* Hero controls */
.hero-arr{
    position:absolute;top:50%;transform:translateY(-50%);z-index:10;
    width:44px;height:44px;border-radius:7px;
    background:rgba(0,0,0,.5);backdrop-filter:blur(12px);
    border:1px solid rgba(255,255,255,.1);color:#fff;
    font-size:22px;cursor:pointer;display:flex;align-items:center;justify-content:center;
    transition:all .22s;
}
.hero-arr:hover{background:var(--accent);border-color:var(--accent);transform:translateY(-50%) scale(1.05)}
.hero-arr-left{left:16px}
.hero-arr-right{right:16px}
.hero-dots{
    position:absolute;bottom:22px;left:60px;z-index:10;
    display:flex;gap:5px;
}
.hero-dot{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,.3);cursor:pointer;transition:all .22s}
.hero-dot.active{width:24px;border-radius:4px;background:var(--accent)}
/* Hero right thumbnails */
.hero-thumbs{
    position:absolute;right:32px;top:50%;transform:translateY(-50%);
    display:flex;flex-direction:column;gap:8px;z-index:10;
}
.hero-thumb{
    width:58px;height:80px;border-radius:7px;overflow:hidden;
    border:2px solid transparent;cursor:pointer;transition:all .22s;opacity:.4;
}
.hero-thumb.active,.hero-thumb:hover{opacity:1;border-color:var(--accent)}
.hero-thumb img{width:100%;height:100%;object-fit:cover}

/* ── SECTION HEADERS ── */
.sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.sec-title{
    display:flex;align-items:center;gap:10px;
    font-family:'Syne',sans-serif;
    font-size:18px;font-weight:800;letter-spacing:.5px;color:var(--text);
}
.sec-bar{width:4px;height:20px;border-radius:2px;background:var(--accent);flex-shrink:0}
.sec-count{background:var(--accent-glow);color:var(--accent);border:1px solid rgba(232,25,44,.3);border-radius:5px;padding:1px 8px;font-size:10px;font-weight:700;letter-spacing:.3px;font-family:'Outfit',sans-serif}
.light .sec-count{background:rgba(232,25,44,.08)}
.sec-more{font-size:11px;font-weight:600;color:var(--muted);display:inline-flex;align-items:center;gap:3px;padding:6px 12px;border-radius:6px;border:1px solid var(--border);transition:all var(--t);cursor:pointer;text-decoration:none}
.sec-more:hover{border-color:var(--accent);color:var(--accent)}

/* ── SECTION WRAPPERS ── */
.sec-box{
    background:var(--card);border:1px solid var(--border);
    border-radius:14px;padding:20px 22px;
    margin-bottom:16px;position:relative;overflow:hidden;
}
.sec-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent) 0%,transparent 40%)}

/* ── NEW MANGA SLIDER ── */
.new-section{margin-bottom:16px}
.slider-wrap{position:relative;display:flex;align-items:center;gap:8px}
.slider-outer{flex:1;overflow:hidden}
.slider-track{display:flex;gap:12px;transition:transform .36s cubic-bezier(.4,0,.2,1)}
.slide-card{
    flex:0 0 112px;text-decoration:none;color:var(--text);
    transition:transform var(--t);
}
.slide-card:hover{transform:translateY(-6px)}
.slide-cover{
    width:112px;height:155px;object-fit:cover;
    border-radius:8px;display:block;
    background:var(--border);border:1px solid var(--border);
    transition:box-shadow var(--t);
}
.slide-card:hover .slide-cover{box-shadow:0 12px 32px rgba(0,0,0,.8),0 0 0 2px var(--accent)}
.slide-cover-ph{width:112px;height:155px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:var(--card2);border:1px solid var(--border);font-size:26px;color:var(--muted)}
.slide-title{font-size:10px;font-weight:600;margin-top:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;color:var(--text2)}
.sarrow{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:var(--card2);color:var(--muted);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all var(--t);flex-shrink:0}
.sarrow:hover{border-color:var(--accent);color:var(--accent)}
.sarrow.hidden{opacity:0;pointer-events:none}

/* ── TOP WEEK ── */
.top-week-section{margin-bottom:16px}
.top-week-accent-line{position:absolute;top:0;left:0;width:100%;height:2px;background:linear-gradient(90deg,var(--accent) 0%,transparent 50%)}

/* ── CONTINUE READING ── */
.cont-section{margin-bottom:16px}
.cont-list{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.cont-list::-webkit-scrollbar{display:none}
.cont-card{
    flex:0 0 210px;background:var(--card2);
    border:1px solid var(--border);border-radius:10px;
    overflow:hidden;text-decoration:none;color:var(--text);
    display:flex;transition:all var(--t);
}
.cont-card:hover{border-color:var(--accent);transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,.5)}
.cont-cover-wrap{width:54px;flex-shrink:0}
.cont-cover{width:100%;height:100%;object-fit:cover;display:block;min-height:80px}
.cont-body{flex:1;padding:10px 12px;display:flex;flex-direction:column;justify-content:space-between;min-width:0}
.cont-title{font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;margin-bottom:3px;color:var(--text)}
.cont-chapter{font-size:10px;color:var(--muted);margin-bottom:4px;font-weight:500}
.cont-bar-bg{height:2px;background:var(--border);border-radius:2px;overflow:hidden;margin-bottom:5px}
.cont-bar-fill{height:100%;background:var(--accent);border-radius:2px;transition:width .4s}
.cont-btn{display:inline-block;padding:3px 9px;background:rgba(232,25,44,.1);border:1px solid rgba(232,25,44,.3);border-radius:4px;color:var(--accent);font-size:9px;font-weight:700;letter-spacing:.2px}

/* ── FILTERS ── */
.filters{display:flex;gap:6px;align-items:center;padding:20px 0 16px;flex-wrap:wrap}
.filter-btn{
    padding:8px 18px;border-radius:6px;
    border:1px solid var(--border);background:transparent;
    color:var(--muted);font-size:12px;font-weight:500;
    cursor:pointer;transition:all var(--t);
    font-family:'Outfit',sans-serif;letter-spacing:.1px;
}
.filter-btn:hover{border-color:var(--accent);color:var(--accent)}
.filter-btn.active{
    background:var(--accent);border-color:var(--accent);
    color:#fff;font-weight:700;
}
.stats-label{color:var(--muted);font-size:11px;margin-left:auto;font-weight:400;letter-spacing:.3px}
.filter-tag-btn{padding:5px 12px;border-radius:5px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:11px;cursor:pointer;transition:all var(--t);font-family:'Outfit',sans-serif;white-space:nowrap}
.filter-tag-btn:hover{border-color:var(--accent);color:var(--accent)}
.filter-tag-btn.active-tag{background:rgba(232,25,44,.1);border-color:rgba(232,25,44,.4);color:var(--accent);font-weight:600}
.filter-tag-btn.nsfw-tag{border-color:rgba(239,68,68,.3);color:var(--red)}
.filter-tag-btn.nsfw-tag.active-tag{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.5)}

/* ── GRID ── */
.grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(175px,1fr));
    gap:18px;
}
@media(max-width:1100px){.grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}}
@media(max-width:600px){.grid{grid-template-columns:repeat(auto-fill,minmax(138px,1fr));gap:11px}}
@media(max-width:380px){.grid{grid-template-columns:repeat(2,1fr);gap:10px}}

/* ── CARD — sharp premium ── */
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:10px;
    overflow:hidden;
    text-decoration:none;color:var(--text);
    transition:all .28s cubic-bezier(.4,0,.2,1);
    position:relative;
    animation:cardIn .3s ease both;
}
@keyframes cardIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.card:hover{
    transform:translateY(-8px);
    border-color:var(--accent);
    box-shadow:0 20px 50px rgba(0,0,0,.85),0 0 0 1px var(--accent);
}
.light .card:hover{box-shadow:0 8px 24px rgba(232,25,44,.15),0 0 0 1px var(--accent)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block;transition:transform .5s ease}
.card:hover .cover{transform:scale(1.06)}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:var(--card2);color:var(--muted);font-size:36px}
.info{padding:10px 12px 12px}
.title{
    font-size:12px;font-weight:600;
    display:-webkit-box;-webkit-line-clamp:2;
    -webkit-box-orient:vertical;overflow:hidden;
    line-height:1.45;font-family:'Outfit',sans-serif;color:var(--text2);
}
.likes{margin-top:5px;color:var(--muted);font-size:10px;font-weight:400;display:flex;align-items:center;gap:4px}
.card-new-badge{
    position:absolute;top:8px;left:8px;
    background:var(--accent);color:#fff;
    font-size:8px;font-weight:800;
    padding:3px 8px;border-radius:4px;
    text-transform:uppercase;letter-spacing:.8px;
}
.card-series-badge{display:inline-block;background:rgba(255,255,255,.05);color:var(--muted);border:1px solid var(--border);padding:2px 7px;border-radius:4px;font-size:8px;font-weight:600;margin-top:4px;letter-spacing:.3px}
.card-rating{display:flex;align-items:center;gap:3px;margin-top:4px}
.card-stars{color:#f59e0b;font-size:9px;letter-spacing:.5px}
.card-rating-val{font-size:9px;color:var(--muted);font-weight:600}

/* Gradient overlay on hover */
.card::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(180deg,transparent 55%,rgba(232,25,44,.12) 100%);
    opacity:0;transition:opacity .3s;pointer-events:none;z-index:1;
}
.card:hover::before{opacity:1}
.card:active{transform:scale(.97)!important}

/* ── HERO SLIDER (legacy compat) ── */
.hero-slider{
    position:relative;border-radius:14px;
    overflow:hidden;height:270px;margin-bottom:16px;
    background:var(--card);border:1px solid var(--border);
}
.hero-slide-title{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;line-height:1.05}
.hero-slide-content{position:absolute;left:24px;bottom:20px;max-width:290px}

/* ── LOAD MORE ── */
.load-more{
    margin:40px auto;display:block;padding:12px 36px;
    background:transparent;border:1px solid var(--border);
    color:var(--muted);border-radius:7px;
    cursor:pointer;font-size:12px;font-weight:600;
    font-family:'Outfit',sans-serif;
    transition:all var(--t);letter-spacing:.3px;
}
.load-more:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-2px)}

/* ── TOAST ── */
#toast-container{position:fixed;bottom:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:6px;pointer-events:none}
.toast{
    background:var(--card2);border:1px solid var(--border2);
    border-radius:8px;padding:10px 16px;
    font-size:12px;font-weight:500;color:var(--text);
    box-shadow:var(--shadow);animation:toastIn .2s ease;pointer-events:auto;
    border-left:3px solid var(--accent);
}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}

/* ── MODALS ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(18px);z-index:800;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:26px;max-width:480px;width:100%;position:relative;animation:modalIn .22s cubic-bezier(.34,1.56,.64,1);max-height:90vh;overflow-y:auto}
@keyframes modalIn{from{opacity:0;transform:scale(.94)translateY(10px)}to{opacity:1;transform:scale(1)translateY(0)}}
.modal-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;letter-spacing:.3px;margin-bottom:16px}
.modal-head{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;letter-spacing:.3px;margin-bottom:4px}
.modal-sub{font-size:12px;color:var(--muted);margin-bottom:18px}
.modal-close,.modal-x{position:absolute;top:16px;right:16px;width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all var(--t)}
.modal-close:hover,.modal-x:hover{border-color:var(--red);color:var(--red)}
.modal input,.modal textarea,.modal select,.fi,.fta,.fsel{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'Outfit',sans-serif;font-size:13px;padding:10px 12px;outline:none;transition:border-color var(--t);margin-bottom:8px}
.modal input:focus,.modal textarea:focus,.fi:focus,.fta:focus,.fsel:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow)}
.light .modal input,.light .modal textarea,.light .fi,.light .fta{background:rgba(0,0,0,.04)}
.modal label,.fl{display:block;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.fg{margin-bottom:14px}
.btn-primary{padding:10px 20px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;font-family:'Outfit',sans-serif;transition:all var(--t);letter-spacing:.3px}
.btn-primary:hover{background:var(--accent2);transform:translateY(-1px);box-shadow:0 4px 16px var(--accent-glow)}
.btn-secondary{padding:10px 20px;background:transparent;color:var(--text2);border:1px solid var(--border);border-radius:8px;font-weight:500;font-size:12px;cursor:pointer;font-family:'Outfit',sans-serif;transition:all var(--t)}
.btn-secondary:hover{border-color:var(--border2);color:var(--text)}

/* ── STATS / ADMIN ── */
.stats-layout{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:18px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:18px 20px}
.stat-card-val{font-family:'Bebas Neue',sans-serif;font-size:34px;color:var(--text)}
.stat-card-label{font-size:11px;color:var(--muted);margin-top:4px}
.admin-table{width:100%;border-collapse:collapse;font-size:12px}
.admin-table th{padding:9px 13px;text-align:left;color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.admin-table td{padding:9px 13px;border-bottom:1px solid var(--border);color:var(--text2);vertical-align:middle}
.admin-table tr:hover td{background:rgba(255,255,255,.02)}
.light .admin-table tr:hover td{background:rgba(0,0,0,.02)}
.func-orange-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:16px}

/* ── COPY / CHAPTER ADMIN ── */
.copy-btn{padding:3px 9px;background:transparent;border:1px solid var(--border);border-radius:5px;color:var(--muted);font-size:10px;cursor:pointer;font-family:inherit;font-weight:600;transition:all var(--t);flex-shrink:0}
.copy-btn:hover{border-color:var(--border2);color:var(--text)}
.add-ch-form{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:var(--r-sm);padding:12px;margin-top:9px}
.add-ch-form h4{font-size:11px;font-weight:700;margin-bottom:8px;color:var(--text2)}
.ch-inputs{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap}
.ch-inputs input{flex:1;min-width:90px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r-sm);color:var(--text);font-family:'Outfit',sans-serif;font-size:12px;padding:7px 10px;outline:none}
.ch-inputs input:focus{outline:none;border-color:var(--border2)}

/* ── MESSAGES MODAL ── */
.messages-modal{max-width:500px}

/* ── SUPPORT MODAL ── */
.support-modal{max-width:460px}

/* ── MOBILE ── */
@media(max-width:768px){
    .header-inner{height:54px;padding:0 14px}
    .header-search-wrap{display:none}
    .hbtn-tg{display:none}
    .wrap{padding:0 12px 90px}
    .hero-banner{height:320px;border-radius:0 0 14px 14px}
    .hero-content{padding:0 18px 32px;max-width:100%}
    .hero-title{font-size:36px}
    .hero-thumbs{display:none}
    .hero-arr{width:36px;height:36px;font-size:17px}
    .hero-arr-left{left:10px}
    .hero-arr-right{right:10px}
    .hero-dots{left:18px;bottom:14px}
    .sec-box{padding:14px 12px;border-radius:12px}
    .grid{grid-template-columns:repeat(auto-fill,minmax(134px,1fr));gap:10px}
    .admin-modal{max-width:100%}
    .stats-layout{grid-template-columns:1fr}
    .func-orange-row{grid-template-columns:1fr 1fr}
    .modal{padding:18px 14px;border-radius:14px}
    .top-search-wrap{max-width:none}
    .hero-slider{height:200px}
}
@media(max-width:480px){
    .logo{font-size:17px;letter-spacing:4px}
    .hbtn-lib span{display:none}
    .hbtn-admin span{display:none}
    .grid{grid-template-columns:repeat(auto-fill,minmax(126px,1fr));gap:9px}
    .card{border-radius:9px}
    .cont-card{flex:0 0 185px}
    .slide-card{flex:0 0 100px}
    .slide-cover{width:100px;height:138px}
    .slide-cover-ph{width:100px;height:138px}
    .scard-grid{grid-template-columns:1fr 1fr}
}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>

<!-- PAGE LOADER -->
<div id="page-loader">
  <div class="bw-logo-load">BLACKWATCH</div>
  <div class="loader-bar"><div class="loader-bar-fill"></div></div>
</div>

<header>
<div class="header-inner">
    <!-- Logo -->
    <a href="/" class="logo">
        <div class="logo-dot"></div>
        BLACKWATCH
    </a>

    <!-- Center Search -->
    <div class="header-search-wrap">
        <span class="header-search-icon">🔍</span>
        <input class="header-search" type="text" placeholder="Поиск манги..." id="header-search-inp" oninput="onSearch(this.value);document.getElementById('search').value=this.value" autocomplete="off">
    </div>

    <!-- Right Actions -->
    <div class="header-actions">
        <button class="theme-btn" onclick="toggleTheme()" title="Тема" id="theme-btn">🌙</button>
        <button class="hbtn hbtn-ghost" onclick="openRandom()" title="Случайная манга">🎲</button>
        <?php if ($currentAccount): ?>
        <?php $isHdrAdmin = in_array((int)($currentAccount['tg_user_id']??0), $hardcodedAdmins) || !empty($currentAccount['is_admin']); ?>
        <a href="/profile" class="hbtn" style="gap:6px">
            👤 <span><?=htmlspecialchars($currentAccount['username'])?><?php if($isHdrAdmin):?> <span style="color:#ef4444;font-size:10px;font-weight:700">⚡</span><?php endif;?></span>
        </a>
        <?php else: ?>
        <a href="/login" class="hbtn">Войти</a>
        <a href="/register" class="hbtn hbtn-accent">Регистрация</a>
        <?php endif; ?>
        <button class="hbtn hbtn-admin" id="admin-btn" onclick="openAdminPanel()">⚙️ <span>Админ</span></button>
    </div>
</div>
</header>

<!-- SIDEBAR ICONS -->
<div class="sidebar-icons">
    <div class="sidebar-rail">
        <a href="/library" class="sidebar-icon-btn" title="Библиотека" style="text-decoration:none">📚</a>
        <button class="sidebar-icon-btn" onclick="openMessagesModal()" title="Сообщения" id="messages-btn" style="position:relative">
            💬
            <span class="sidebar-badge" id="messages-badge" style="display:none"></span>
            <span class="sidebar-badge" id="msg-badge" style="display:none"></span>
        </button>
        <?php if ($currentAccount): ?>
        <a href="/profile" class="sidebar-icon-btn" title="Профиль — <?=htmlspecialchars($currentAccount['username'])?>" style="text-decoration:none">👤</a>
        <?php endif; ?>
        <a href="https://t.me/<?=htmlspecialchars($botUsername)?>" target="_blank" class="sidebar-icon-btn" title="Telegram-бот" style="text-decoration:none">🤖</a>
        <button class="sidebar-icon-btn" onclick="openSupportModal()" title="Поддержка">🛟</button>
        <div style="width:100%;height:1px;background:var(--border);margin:2px 0"></div>
        <button class="sidebar-icon-btn theme-btn" onclick="toggleTheme()" title="Тема" id="theme-btn-side">🌙</button>
    </div>
</div>

<div class="wrap">
    <!-- ПОИСК (mobile) -->
    <div class="top-search-wrap" id="mobile-search-row" style="display:none;padding:16px 0 0">
        <span class="search-icon">🔍</span>
        <input class="search" type="text" placeholder="Поиск манги..." id="search" oninput="onSearch(this.value)" autocomplete="off">
        <div class="search-dropdown" id="search-dropdown"></div>
    </div>
    <!-- Desktop hidden search input sync -->
    <input id="search" type="hidden" style="display:none" value="">

    <!-- ═══ HERO BANNER ═══ -->
    <div class="hero-banner" id="hero-banner">
        <div class="hero-track" id="hero-track">
            <div class="hero-slide">
                <div class="hero-slide-bg" style="background:#101014"></div>
                <div class="hero-slide-grad"></div>
                <div class="hero-slide-grad2"></div>
                <div class="hero-slide-accentbar"></div>
                <div class="hero-content">
                    <div class="hero-label">ТОПОВАЯ МАНГА</div>
                    <div class="hero-rank">#1 Топ недели</div>
                    <h1 class="hero-title">BLACKWATCH</h1>
                    <p class="hero-desc">Загрузка лучших произведений...</p>
                    <div class="hero-actions">
                        <button class="hero-btn hero-btn-primary" onclick="load(true)">▶ Читать сейчас</button>
                    </div>
                </div>
            </div>
        </div>
        <button class="hero-arr hero-arr-left" id="hero-prev" onclick="heroSlide(-1)">‹</button>
        <button class="hero-arr hero-arr-right" id="hero-next" onclick="heroSlide(1)">›</button>
        <div class="hero-dots" id="hero-dots"></div>
        <div class="hero-thumbs" id="hero-thumbs"></div>
    </div>

    <!-- НОВИНКИ -->
    <div class="new-section" id="new-section" style="display:none">
        <div class="sec-box">
        <div class="sec-header">
            <div class="sec-title"><div class="sec-bar"></div>Новинки<span class="sec-count" id="new-count">0</span></div>
        </div>
        <div class="slider-wrap">
            <div class="sarrow left hidden" id="sl-left" onclick="slideLeft()">‹</div>
            <div class="slider-outer"><div class="slider-track" id="slider-track"></div></div>
            <div class="sarrow right hidden" id="sl-right" onclick="slideRight()">›</div>
        </div>
        </div>
    </div>

    <!-- ТОП НЕДЕЛИ — MANGA слайдер -->
    <div id="top-week-section" style="display:none" class="top-week-section">
        <div class="sec-box">
        <div class="top-week-accent-line"></div>
        <div class="sec-header" style="margin-bottom:14px">
            <div class="sec-title"><div class="sec-bar" style="background:#f59e0b"></div>Топ недели<span class="sec-count" style="margin-left:2px"><span id="top-week-count">0</span></span></div>
            <div style="display:flex;gap:5px">
                <button id="tw-left" onclick="topWeekSlide(-1)" class="sarrow" style="width:30px;height:30px;font-size:15px">‹</button>
                <button id="tw-right" onclick="topWeekSlide(1)" class="sarrow" style="width:30px;height:30px;font-size:15px">›</button>
            </div>
        </div>
        <div style="position:relative;overflow:hidden">
            <div id="top-week-track" style="display:flex;gap:12px;transition:transform .35s cubic-bezier(.4,0,.2,1)"></div>
        </div>
        </div>
    </div>

    <!-- ПРОДОЛЖИТЬ -->
    <div class="cont-section" id="cont-section" style="display:none">
        <div class="sec-box">
        <div class="sec-header"><div class="sec-title"><div class="sec-bar" style="background:#3b82f6"></div>Продолжить читать</div></div>
        <div class="cont-list" id="cont-list"></div>
        </div>
    </div>

    <!-- ТОП НЕДЕЛИ — USERS -->
    <?php
    try {
        $topWeek = $pdo->query("SELECT a.username, pc.avatar_url,
            COALESCE(ux.level,1) as level,
            COALESCE(ux.weekly_pages,0) as weekly_pages,
            COALESCE(ux.weekly_chapters,0) as weekly_chapters,
            COALESCE(ux.total_xp,0) as total_xp
            FROM user_xp ux
            JOIN accounts a ON a.id=ux.account_id
            LEFT JOIN profile_customizations pc ON pc.account_id=ux.account_id
            WHERE ux.weekly_pages > 0 OR ux.weekly_chapters > 0
            ORDER BY ux.weekly_pages DESC
            LIMIT 5")->fetchAll();
    } catch(Exception $e) { $topWeek = []; }
    if (!empty($topWeek)):
    ?>
    <div class="cont-section" style="margin-bottom:18px">
        <div class="sec-header" style="margin-bottom:12px">
            <div class="sec-title"><span>🏆</span>Топ недели <a href="/rankings" style="font-size:10px;color:var(--muted);text-decoration:none;font-weight:500;margin-left:8px">Все →</a></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach($topWeek as $i => $tw): ?>
        <a href="/u/<?=htmlspecialchars($tw['username'])?>" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px;padding:9px 13px;transition:all .18s" onmouseover="this.style.borderColor='var(--border2)'" onmouseout="this.style.borderColor='var(--border)'">
            <div style="width:22px;font-size:13px;font-weight:800;color:<?=$i===0?'#f59e0b':($i===1?'#9ca3af':($i===2?'#b45309':'var(--muted)'))?>;text-align:center"><?=$i+1?></div>
            <div style="width:32px;height:32px;border-radius:50%;background:#1a1a2e;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px">
                <?php if(!empty($tw['avatar_url'])): ?><img src="<?=htmlspecialchars($tw['avatar_url'])?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?>👤<?php endif; ?>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:600;color:var(--text2)"><?=htmlspecialchars($tw['username'])?></div>
                <div style="font-size:10px;color:var(--muted)">Ур. <?=(int)$tw['level']?> · <?=(int)$tw['weekly_pages']?> стр. за неделю</div>
            </div>
            <div style="font-size:10px;color:var(--muted);text-align:right"><?=(int)$tw['weekly_chapters']?> гл.</div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FILTERS -->
    <div class="filters">
        <button class="filter-btn active" id="f-new" onclick="setFilter('new')">🕒 Новые</button>
        <button class="filter-btn" id="f-popular" onclick="setFilter('popular')">🔥 Популярные</button>
        <button class="filter-btn" id="f-alpha" onclick="setFilter('alpha')">🔤 А-Я</button>
        <button class="filter-btn" id="f-genre-tag" onclick="toggleGenreFilter()" style="gap:5px">🏷 Жанр/Тег</button>
        <span class="stats-label" id="stats">Манг: <strong><?=(int)$total?></strong></span>
    </div>

    <!-- GENRE/TAG FILTER PANEL -->
    <div id="genre-filter-panel" style="display:none;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:14px">
        <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">🎭 Жанры</div>
        <div id="gfp-genres" style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px;max-height:100px;overflow-y:auto"></div>
        <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">🏷 Теги</div>
        <div id="gfp-tags" style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px;max-height:140px;overflow-y:auto"></div>
        <button onclick="clearGenreFilter()" style="padding:5px 13px;background:transparent;border:1px solid var(--border);border-radius:20px;color:var(--muted);font-size:11px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.borderColor='var(--border2)'" onmouseout="this.style.borderColor='var(--border)'">✕ Сбросить</button>
    </div>

    <div class="grid" id="grid"></div>
    <button class="load-more" id="more" onclick="load()" style="display:none">Загрузить ещё</button>
</div>


<!-- MODAL: ADD MANGA -->
<div class="modal-overlay" id="add-modal" onclick="if(event.target===this)closeAddModal()">
<div class="modal">
    <button class="modal-x" onclick="closeAddModal()">✕</button>
    <div class="modal-head">Добавить мангу</div>
    <div class="modal-sub">Загрузи обложку и страницы</div>
    <div class="fg">
        <div class="toggle-row">
            <button class="toggle-btn active" id="type-single" onclick="setMangaType('single')">📄 Обычная</button>
            <button class="toggle-btn" id="type-series" onclick="setMangaType('series')">📚 Серия</button>
        </div>
    </div>
    <div class="fg"><label class="fl">Название</label><input class="fi" type="text" id="manga-title" placeholder="Название манги..."></div>
    <div class="fg"><label class="fl">Описание</label><textarea class="fta" id="manga-desc" placeholder="Краткое описание..."></textarea></div>
    <div class="fg" id="add-genres-wrap" style="display:none">
        <label class="fl">Жанры</label>
        <div id="add-genres-list" style="display:flex;flex-wrap:wrap;gap:5px;margin-top:4px"></div>
    </div>
    <div class="fg" id="add-tags-wrap" style="display:none">
        <label class="fl">Теги</label>
        <div id="add-tags-list" style="display:flex;flex-wrap:wrap;gap:5px;margin-top:4px"></div>
    </div>
    <div class="fg">
        <label class="fl">Обложка</label>
        <div class="upload-zone" id="cover-zone">
            <input type="file" id="cover-input" accept="image/*" onchange="onCoverChange(this)">
            <div class="upload-icon">🖼</div>
            <div class="upload-text"><strong>Загрузить обложку</strong><br>JPG, PNG, WebP</div>
            <div class="upload-preview" id="cover-preview"></div>
        </div>
    </div>
    <div id="pages-section">
        <div class="fg">
            <label class="fl">Страницы</label>
            <div class="file-tabs">
                <div class="file-tab active" id="tab-zip" onclick="switchTab('zip')">📦 ZIP</div>
                <div class="file-tab" id="tab-photos" onclick="switchTab('photos')">📸 Фото</div>
            </div>
            <div class="file-panel active" id="panel-zip">
                <div class="upload-zone" id="zip-zone">
                    <input type="file" id="zip-input" accept=".zip" onchange="onZipChange(this)">
                    <div class="upload-icon">📦</div>
                    <div class="upload-text"><strong>ZIP-архив страниц</strong><br>Сортировка по дате</div>
                    <div class="upload-preview" id="zip-preview"></div>
                </div>
            </div>
            <div class="file-panel" id="panel-photos">
                <div class="upload-zone" id="photos-zone">
                    <input type="file" id="photos-input" accept="image/*" multiple onchange="onPhotosChange(this)">
                    <div class="upload-icon">📸</div>
                    <div class="upload-text"><strong>Выбери страницы</strong><br>001.jpg, 002.jpg...</div>
                    <div class="upload-preview" id="photos-preview"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="upbar" id="upload-progress"><div class="upbar-fill" id="upload-progress-fill"></div></div>
    <button class="sbtn" id="submit-btn" onclick="submitManga()"><span class="btn-text">🚀 Опубликовать</span><div class="spinner"></div></button>
    <div class="result-banner" id="result-banner"></div>
</div>
</div>

<!-- MODAL: ADMIN NOTIFICATIONS -->
<div class="modal-overlay" id="admin-messages-modal" onclick="if(event.target===this)closeAdminMessagesModal()">
<div class="modal messages-modal">
    <button class="modal-x" onclick="closeAdminMessagesModal()">✕</button>
    <div class="modal-head">📢 Уведомления</div>
    <div class="modal-sub">Сообщения от администрации</div>
    <div class="msg-list" id="msg-list"><div style="color:var(--muted);text-align:center;padding:20px;font-size:13px">Загрузка...</div></div>
</div>
</div>

<!-- MODAL: SUPPORT -->
<div class="modal-overlay" id="support-modal" onclick="if(event.target===this)closeSupportModal()">
<div class="modal support-modal">
    <button class="modal-x" onclick="closeSupportModal()">✕</button>
    <div class="modal-head">🛟 Поддержка</div>
    <div class="modal-sub">Напиши нам — мы ответим!</div>
    <div class="fg"><label class="fl">Твоё сообщение</label><textarea class="fta" id="support-text" placeholder="Опиши проблему или задай вопрос..." style="min-height:110px"></textarea></div>
    <button class="sbtn" onclick="sendSupport()"><span class="btn-text">📨 Отправить</span><div class="spinner"></div></button>
    <div class="result-banner" id="support-result"></div>
</div>
</div>

<!-- MODAL: ADMIN PANEL -->
<div class="modal-overlay" id="admin-modal" onclick="if(event.target===this)closeAdminPanel()">
<div class="modal admin-modal">
    <button class="modal-x" onclick="closeAdminPanel()">✕</button>
    <div class="modal-head">⚙️ Админ-панель</div>
    <div class="admin-tabs">
        <button class="atab active" onclick="switchAdminTab('stats')">📊 Статистика</button>
        <button class="atab" onclick="switchAdminTab('messages')">📨 Сообщения</button>
        <button class="atab" onclick="switchAdminTab('edit')">✏️ Редактирование</button>
        <button class="atab" onclick="switchAdminTab('add-chapter')">📚 Добавить главу</button>
        <button class="atab" onclick="switchAdminTab('tags')">🏷 Теги/Жанры</button>
    </div>

    <!-- STATS -->
    <div class="apanel active" id="panel-stats">
        <div class="stats-layout">
            <div class="stats-left">
                <div class="stats-tabs">
                    <button class="stab active" onclick="showStatsView('grid')">📊 Статистика</button>
                    <button class="stab" onclick="showStatsView('archive')">🗂 Архив</button>
                </div>
                <div id="stats-grid-view">
                    <div class="scard-grid" id="stat-grid"><div style="color:var(--muted);grid-column:1/-1;padding:10px 0;font-size:12px">Загрузка...</div></div>
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:7px">Топ по лайкам</div>
                    <div class="top-list" id="top-list"></div>
                    <button class="edit-manga-btn" onclick="switchAdminTab('edit')">✏️ Редактировать мангу</button>
                </div>
                <div id="stats-archive-view" style="display:none">
                    <div class="archive-list" id="archive-list"><div style="color:var(--muted);font-size:12px">Загрузка...</div></div>
                    <div class="pagination" id="archive-pagination"></div>
                </div>
            </div>
            <div class="stats-right">
                <div class="func-title">Функционал</div>
                <button class="func-btn func-green" onclick="closeAdminPanel();openAddModal()">➕ Добавить мангу<br><small style="font-size:10px;opacity:0.8">ZIP, обложка, описание</small></button>
                <div class="func-orange-row">
                    <button class="func-btn func-amber" onclick="switchAdminTab('add-chapter')">📚 Добавить серию</button>
                    <button class="func-btn func-amber" onclick="switchAdminTab('add-chapter')">📑 Добавить главу</button>
                </div>
                <button class="func-btn func-purple" onclick="switchAdminTab('messages')">📨 Написать всем <span id="suggest-badge" style="background:rgba(255,255,255,0.2);border-radius:10px;padding:1px 7px;font-size:10px"></span></button>
                <button class="func-btn func-blue" id="suggest-btn" onclick="showStatsView('suggestions')">💡 Предложки <span id="suggest-badge2" style="background:rgba(255,255,255,0.2);border-radius:10px;padding:1px 7px;font-size:10px"></span></button>
                <div class="admins-wrap" style="margin-top:14px">
                    <div class="admin-lbl">Список админов</div>
                    <div id="admins-list"><div style="color:var(--muted);font-size:11px">Загрузка...</div></div>
                    <button onclick="toggleAdminAddPanel()" id="admin-add-toggle-btn" style="width:100%;margin-top:8px;padding:8px 10px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:8px;color:#ef4444;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s" onmouseover="this.style.background='rgba(239,68,68,0.15)'" onmouseout="this.style.background='rgba(239,68,68,0.08)'">➕ Добавить нового администратора</button>
                    <div id="admin-add-panel" style="display:none;margin-top:9px;background:rgba(239,68,68,0.04);border:1px solid rgba(239,68,68,0.18);border-radius:10px;padding:12px">
                        <div style="font-size:11px;font-weight:700;color:#ef4444;margin-bottom:9px">⚡ Назначить администратора</div>
                        <input id="ap-admin-input" type="text" placeholder="Email или TG ID" style="width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:12px;padding:8px 10px;font-family:inherit;outline:none;margin-bottom:7px">
                        <input id="ap-admin-tag" type="text" placeholder="Тег (например: Редактор)" style="width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:12px;padding:8px 10px;font-family:inherit;outline:none;margin-bottom:7px">
                        <div style="display:flex;gap:6px">
                            <button onclick="submitAddAdmin()" style="flex:1;padding:8px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.35);border-radius:7px;color:#ef4444;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit">&#10003; Назначить</button>
                            <button onclick="toggleAdminAddPanel()" style="padding:8px 12px;background:transparent;border:1px solid var(--border);border-radius:7px;color:var(--muted);font-size:11px;cursor:pointer;font-family:inherit">Отмена</button>
                        </div>
                        <div id="ap-admin-result" style="font-size:11px;margin-top:7px"></div>
                    </div>
                </div>
            </div>
        </div>
        <div id="stats-suggestions-view" style="display:none;margin-top:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px">
                <div style="font-size:12px;font-weight:700">💡 Предложения пользователей</div>
                <button onclick="showStatsView('grid')" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:12px">← Назад</button>
            </div>
            <div class="suggest-preview" id="suggest-list"><div style="color:var(--muted);font-size:12px">Загрузка...</div></div>
            <div class="pagination" id="suggest-pagination"></div>
        </div>
    </div>

    <!-- MESSAGES panel -->
    <div class="apanel" id="panel-messages">
        <div class="msg-compose">
            <label class="fl" style="margin-bottom:6px">Написать всем пользователям</label>
            <textarea id="admin-msg-text" placeholder="Введи сообщение..."></textarea>
            <button class="msg-send-btn" onclick="sendAdminMessage()">📨 Отправить всем</button>
        </div>
        <div class="func-title" style="margin-top:14px">Отправленные сообщения</div>
        <div class="msg-list" id="admin-msg-list"><div style="color:var(--muted);font-size:12px;padding:10px 0">Загрузка...</div></div>
    </div>

    <!-- EDIT -->
    <div class="apanel" id="panel-edit">
        <div class="esearch-row">
            <input class="esearch-inp" id="edit-search-input" type="text" placeholder="🔍 Поиск манги...">
            <button class="esearch-btn" onclick="searchMangaEdit()">Найти</button>
        </div>
        <div class="manga-edit-list" id="manga-edit-list"><div style="color:var(--muted);padding:10px 0;font-size:12px">Введи название или оставь пустым</div></div>
        <div class="pagination" id="edit-pagination"></div>
        <div id="edit-manga-form-wrap" style="display:none">
            <button class="back-edit-btn" onclick="backToMangaList()">← Назад к списку</button>
            <div class="edit-form-wrap" id="edit-manga-form"></div>
        </div>
    </div>

    <!-- ADD CHAPTER -->
    <div class="apanel" id="panel-add-chapter">        <div class="fg">
            <label class="fl">Манга / Серия</label>
            <div class="esearch-row">
                <input class="esearch-inp" id="ch-manga-search" type="text" placeholder="Поиск серии...">
                <button class="esearch-btn" onclick="searchMangaForChapter()">Найти</button>
            </div>
            <div class="manga-edit-list" id="ch-manga-list" style="max-height:160px"><div style="color:var(--muted);padding:9px 0;font-size:12px">Найдите серию выше</div></div>
        </div>
        <div id="ch-add-form" style="display:none">
            <div class="add-ch-form">
                <h4>➕ Новая глава</h4>
                <div class="ch-inputs">
                    <input type="number" id="ch-num" placeholder="Номер (1, 2, 2.5...)" step="0.1" min="0">
                    <input type="text" id="ch-title-input" placeholder="Название (необязательно)">
                </div>
                <div class="file-tabs">
                    <div class="file-tab active" id="ch-tab-zip" onclick="switchChTab('zip')">📦 ZIP</div>
                    <div class="file-tab" id="ch-tab-photos" onclick="switchChTab('photos')">📸 Фото</div>
                </div>
                <div class="file-panel active" id="ch-panel-zip">
                    <div class="upload-zone" id="ch-zip-zone" style="padding:13px">
                        <input type="file" id="ch-zip-input" accept=".zip" onchange="onChZipChange(this)">
                        <div class="upload-icon" style="font-size:20px">📦</div>
                        <div class="upload-text" style="font-size:11px">ZIP со страницами</div>
                        <div class="upload-preview" id="ch-zip-preview"></div>
                    </div>
                </div>
                <div class="file-panel" id="ch-panel-photos">
                    <div class="upload-zone" id="ch-photos-zone" style="padding:13px">
                        <input type="file" id="ch-photos-input" accept="image/*" multiple onchange="onChPhotosChange(this)">
                        <div class="upload-icon" style="font-size:20px">📸</div>
                        <div class="upload-text" style="font-size:11px">Страницы главы</div>
                        <div class="upload-preview" id="ch-photos-preview"></div>
                    </div>
                </div>
                <div class="upbar" id="ch-upload-progress"><div class="upbar-fill" id="ch-upload-fill"></div></div>
                <button class="sbtn" id="ch-submit-btn" onclick="submitChapter()" style="margin-top:7px"><span class="btn-text">📤 Загрузить главу</span><div class="spinner"></div></button>
                <div class="result-banner" id="ch-result-banner"></div>
            </div>
        </div>
    </div>

    <!-- PANEL: TAGS & GENRES -->
    <div class="apanel" id="panel-tags">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

            <!-- ЖАНРЫ -->
            <div>
                <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:10px;display:flex;align-items:center;gap:6px">🎭 Жанры <span id="genre-count-badge" style="background:rgba(255,255,255,0.07);border-radius:10px;padding:1px 7px;font-size:10px;color:var(--muted)">0</span></div>
                <!-- Добавить жанр -->
                <div style="display:flex;gap:6px;margin-bottom:10px">
                    <input id="new-genre-name" placeholder="Название" style="flex:1;background:var(--card2);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:7px 10px;font-size:12px;outline:none;font-family:inherit">
                    <input id="new-genre-slug" placeholder="slug (action)" style="flex:1;background:var(--card2);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:7px 10px;font-size:12px;outline:none;font-family:inherit">
                    <button onclick="addGenre()" style="padding:7px 12px;background:rgba(124,92,255,0.15);border:1px solid rgba(124,92,255,0.3);border-radius:7px;color:#a78bfa;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .15s" onmouseover="this.style.background='rgba(124,92,255,0.25)'" onmouseout="this.style.background='rgba(124,92,255,0.15)'">➕ Добавить</button>
                </div>
                <div id="genres-manage-list" style="display:flex;flex-direction:column;gap:4px;max-height:350px;overflow-y:auto"></div>
            </div>

            <!-- ТЕГИ -->
            <div>
                <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:10px;display:flex;align-items:center;gap:6px">🏷 Теги <span id="tag-count-badge" style="background:rgba(255,255,255,0.07);border-radius:10px;padding:1px 7px;font-size:10px;color:var(--muted)">0</span></div>
                <!-- Добавить тег -->
                <div style="display:flex;gap:6px;margin-bottom:6px">
                    <input id="new-tag-name" placeholder="Название" style="flex:1;background:var(--card2);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:7px 10px;font-size:12px;outline:none;font-family:inherit">
                    <input id="new-tag-slug" placeholder="slug (isekai)" style="flex:1;background:var(--card2);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:7px 10px;font-size:12px;outline:none;font-family:inherit">
                </div>
                <div style="display:flex;gap:6px;margin-bottom:10px;align-items:center">
                    <label style="font-size:11px;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:5px"><input type="checkbox" id="new-tag-nsfw"> 🔞 NSFW</label>
                    <button onclick="addTag()" style="padding:7px 12px;background:rgba(124,92,255,0.15);border:1px solid rgba(124,92,255,0.3);border-radius:7px;color:#a78bfa;font-size:12px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.background='rgba(124,92,255,0.25)'" onmouseout="this.style.background='rgba(124,92,255,0.15)'">➕ Добавить</button>
                </div>
                <div id="tags-manage-list" style="display:flex;flex-direction:column;gap:4px;max-height:350px;overflow-y:auto"></div>
            </div>

        </div>
        <!-- Кнопки внизу -->
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button onclick="reseedAllTags()" style="padding:8px 16px;background:rgba(124,92,255,0.1);border:1px solid rgba(124,92,255,0.3);border-radius:8px;color:#a78bfa;font-size:12px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.background='rgba(124,92,255,0.2)'" onmouseout="this.style.background='rgba(124,92,255,0.1)'">🔄 Загрузить все дефолтные теги и жанры</button>
            <button onclick="dedupTagsGenres()" style="padding:8px 16px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;color:#f87171;font-size:12px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.background='rgba(239,68,68,0.2)'" onmouseout="this.style.background='rgba(239,68,68,0.1)'">🧹 Удалить дубли</button>
            <span style="font-size:11px;color:var(--muted)">Если жанров больше 15 или тегов больше 54 — нажми «Удалить дубли»</span>
        </div>
    </div>

</div>
</div>

<script>
// ===== THEME =====
(function(){
    const saved=localStorage.getItem('bw_theme')||'dark';
    if(saved==='light')document.body.classList.add('light');
    const icon = saved==='light'?'🌙':'☀️';
    if(document.getElementById('theme-btn')) document.getElementById('theme-btn').textContent=icon;
    if(document.getElementById('theme-btn-side')) document.getElementById('theme-btn-side').textContent=icon;
})();
function toggleTheme(){
    const isLight=document.body.classList.toggle('light');
    localStorage.setItem('bw_theme',isLight?'light':'dark');
    const icon=isLight?'🌙':'☀️';
    if(document.getElementById('theme-btn')) document.getElementById('theme-btn').textContent=icon;
    if(document.getElementById('theme-btn-side')) document.getElementById('theme-btn-side').textContent=icon;
}

// ===== TG =====
(function(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';}}catch(e){}})();
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u){document.cookie='tg_user_id='+u+';max-age='+(86400*30)+';path=/';return u;}const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function showToast(msg){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className='toast';t.innerText=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2600);}

// ===== ADMIN CHECK =====
async function checkAdmin(){
    try{const res=await fetch('/api/check-admin?tg_user_id='+getTgUser());const data=await res.json();if(data.is_admin)document.getElementById('admin-btn').classList.add('visible');}catch(e){}
}

// ===== CATALOG =====
let page=0,q='',loading=false,hasMore=true,currentSort='new',activeGenre='',activeTag='';
const grid=document.getElementById('grid'),moreBtn=document.getElementById('more'),statsDiv=document.getElementById('stats');
function setFilter(sort){if(currentSort===sort)return;currentSort=sort;['new','popular','alpha'].forEach(s=>document.getElementById('f-'+s).classList.toggle('active',s===sort));load(true);}

// ===== GENRE/TAG FILTER =====
let _genreTagsData=null;
async function toggleGenreFilter(){
    const panel=document.getElementById('genre-filter-panel');
    const isOpen=panel.style.display!=='none';
    panel.style.display=isOpen?'none':'block';
    if(!isOpen){
        if(!_genreTagsData){
            try{const res=await fetch('/api/genres');_genreTagsData=await res.json();}catch(e){}
        }
        renderGenreFilterPanel();
    }
}
function renderGenreFilterPanel(){
    if(!_genreTagsData)return;
    const genresEl=document.getElementById('gfp-genres');
    const tagsEl=document.getElementById('gfp-tags');
    genresEl.innerHTML=(_genreTagsData.genres||[]).map(g=>`<button onclick="selectGenre('${g.slug}')" id="gf-g-${g.slug}" class="filter-tag-btn${activeGenre===g.slug?' active-tag':''}">${escapeHtml(g.name)}</button>`).join('');
    tagsEl.innerHTML=(_genreTagsData.tags||[]).map(t=>`<button onclick="selectTag('${t.slug}')" id="gf-t-${t.slug}" class="filter-tag-btn${activeTag===t.slug?' active-tag':''}${t.is_nsfw?' nsfw-tag':''}">${escapeHtml(t.name)}${t.is_nsfw?' 🔞':''}</button>`).join('');
}
function selectGenre(slug){
    activeGenre=activeGenre===slug?'':slug;
    activeTag='';
    document.getElementById('f-genre-tag').classList.toggle('active',!!(activeGenre||activeTag));
    renderGenreFilterPanel();
    load(true);
}
function selectTag(slug){
    activeTag=activeTag===slug?'':slug;
    activeGenre='';
    document.getElementById('f-genre-tag').classList.toggle('active',!!(activeGenre||activeTag));
    renderGenreFilterPanel();
    load(true);
}
function clearGenreFilter(){activeGenre='';activeTag='';document.getElementById('f-genre-tag').classList.remove('active');renderGenreFilterPanel();load(true);}

// Load genres for add form
async function loadGenresForAddForm(){
    if(_genreTagsData){renderAddForm(_genreTagsData);return;}
    try{const res=await fetch('/api/genres');_genreTagsData=await res.json();renderAddForm(_genreTagsData);}catch(e){}
}
function renderAddForm(data){
    const gw=document.getElementById('add-genres-wrap'),tw=document.getElementById('add-tags-wrap');
    const gl=document.getElementById('add-genres-list'),tl=document.getElementById('add-tags-list');
    if(!gl||!tl)return;
    if(data.genres?.length){gw.style.display='block';gl.innerHTML=data.genres.map(g=>`<label style="font-size:11px;cursor:pointer;padding:3px 9px;border:1px solid var(--border);border-radius:20px;display:inline-flex;align-items:center;gap:3px;transition:all .15s"><input type="checkbox" data-add-gid="${g.id}" style="display:none" onchange="this.closest('label').style.background=this.checked?'var(--card2)':'transparent';this.closest('label').style.borderColor=this.checked?'var(--border2)':'var(--border)'">${escapeHtml(g.name)}</label>`).join('');}
    if(data.tags?.length){tw.style.display='block';tl.innerHTML=data.tags.map(t=>`<label style="font-size:11px;cursor:pointer;padding:3px 9px;border:1px solid ${t.is_nsfw?'rgba(239,68,68,0.3)':'var(--border)'};border-radius:20px;display:inline-flex;align-items:center;gap:3px;transition:all .15s"><input type="checkbox" data-add-tid="${t.id}" style="display:none" onchange="this.closest('label').style.background=this.checked?'var(--card2)':'transparent';this.closest('label').style.borderColor=this.checked?'var(--border2)':this.dataset.nsfw?'rgba(239,68,68,0.3)':'var(--border)'">${escapeHtml(t.name)}${t.is_nsfw?' 🔞':''}</label>`).join('');}
}

// ===== SEARCH DROPDOWN =====
let searchTimeout;
const searchDrop=document.getElementById('search-dropdown');
function onSearch(val){
    clearTimeout(searchTimeout);
    q=val.trim();
    if(!q){searchDrop.classList.remove('open');load(true);return;}
    searchTimeout=setTimeout(async()=>{
        load(true);
        try{
            const res=await fetch(`/api/manga?page=0&q=${encodeURIComponent(q)}&sort=new`);
            const data=await res.json();
            if(!data.items.length){searchDrop.innerHTML='<div style="padding:14px;color:var(--muted);font-size:12px;text-align:center">Ничего не найдено</div>';searchDrop.classList.add('open');return;}
            searchDrop.innerHTML=data.items.slice(0,6).map(m=>{
                let src=m.cover_display||'';if(src&&src.startsWith('tg://'))src='';
                return `<a class="sd-item" href="/read/${m.id}">
                    ${src?`<img class="sd-cover" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';this.nextSibling.style.display='flex'">`:''}<div class="sd-cover-ph" style="${src?'display:none':'display:flex'}">📖</div>
                    <div class="sd-info"><div class="sd-title">${escapeHtml(m.title)}</div><div class="sd-meta">${m.is_series?'📚 Серия':'📄 Манга'}${m.likes>0?' · ♥ '+m.likes:''}</div></div>
                </a>`;
            }).join('');
            searchDrop.classList.add('open');
        }catch(e){}
    },280);
}
document.addEventListener('click',e=>{if(!e.target.closest('.top-search-wrap'))searchDrop.classList.remove('open');});

async function load(reset=false){
    if(loading)return;loading=true;
    if(reset){page=0;grid.innerHTML='';hasMore=true;moreBtn.style.display='none';}
    if(page===0&&!grid.children.length)grid.innerHTML='<div class="empty">📖 Загрузка...</div>';
    try{
        const _gp=activeGenre?'&genre='+encodeURIComponent(activeGenre):activeTag?'&tag='+encodeURIComponent(activeTag):'';
        const res=await fetch('/api/manga?page='+page+'&q='+encodeURIComponent(q)+'&sort='+currentSort+_gp);
        const data=await res.json();
        if(page===0){grid.innerHTML='';statsDiv.innerHTML=q?`Найдено: <strong>${data.total}</strong>`:`Манг: <strong>${data.total}</strong>`;}
        if(!data.items.length&&page===0){grid.innerHTML='<div class="empty">😔 Ничего не найдено</div>';loading=false;return;}
        const delay=reset?0:0;
        data.items.forEach((m,i)=>{
            const src=(m.cover_display&&!m.cover_display.startsWith('tg://'))?m.cover_display:'';
            const covId='cv'+m.id,phId='ph'+m.id;
            const el=document.createElement('a');
            el.className='card';el.href='/read/'+m.id;
            el.style.animationDelay=(i*30)+'ms';
            el.innerHTML=`${m.is_new?'<div class="card-new-badge">Новое</div>':''}
                ${src?`<img class="cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="document.getElementById('${covId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`:'' }
                <div class="cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:36px">📖</span></div>
                <div class="info">
                    <div class="title">${escapeHtml(m.title)}</div>
                    ${m.is_series?'<div class="card-series-badge">📚 Серия</div>':''}
                    ${m.avg_rating>0?`<div class="card-rating"><span class="card-stars">${'★'.repeat(Math.round(m.avg_rating/2))}${'☆'.repeat(5-Math.round(m.avg_rating/2))}</span><span class="card-rating-val">${m.avg_rating}</span></div>`:(m.likes>0?`<div class="likes">♥ ${m.likes}</div>`:'')}
                </div>`;
            grid.appendChild(el);
        });
        hasMore=data.items.length>=data.limit;
        moreBtn.style.display=hasMore?'block':'none';
        page++;
    }catch(e){if(page===0)grid.innerHTML='<div class="empty">❌ Ошибка загрузки</div>';}
    loading=false;
}

async function openRandom(){
    try{const res=await fetch('/api/random?tg_user_id='+getTgUser());const d=await res.json();if(d.id)window.location.href='/read/'+d.id;else showToast('😔 Нет манги');}catch(e){}
}

// ===== NEW MANGA SLIDER =====
let sliderPos=0,sliderItems=[];
async function loadNew(){
    try{const res=await fetch('/api/new-manga');const data=await res.json();sliderItems=data.items||[];if(!sliderItems.length)return;const sec=document.getElementById('new-section');sec.style.display='block';document.getElementById('new-count').textContent=sliderItems.length;
    const track=document.getElementById('slider-track');
    track.innerHTML=sliderItems.map(m=>{const src=(m.cover_display&&!m.cover_display.startsWith('tg://'))?m.cover_display:'';return`<a class="slide-card" href="/read/${m.id}">${src?`<img class="slide-cover" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';this.nextSibling.style.display='flex'">`:''}<div class="slide-cover-ph" style="${src?'display:none':'display:flex'}">📖</div><div class="slide-title">${escapeHtml(m.title)}</div></a>`;}).join('');
    updateSliderArrows();}catch(e){}
}
function slideLeft(){if(sliderPos>0){sliderPos--;updateSlider();}}
function slideRight(){sliderPos++;updateSlider();}
function updateSlider(){document.getElementById('slider-track').style.transform=`translateX(-${sliderPos*130}px)`;updateSliderArrows();}
function updateSliderArrows(){const t=document.getElementById('slider-track');const maxPos=Math.max(0,sliderItems.length-Math.floor(t.parentElement.offsetWidth/130));document.getElementById('sl-left').classList.toggle('hidden',sliderPos<=0);document.getElementById('sl-right').classList.toggle('hidden',sliderPos>=maxPos);}

// ===== CONTINUE READING =====
async function loadContinue(){
    try{const tgId=getTgUser();if(!tgId)return;const res=await fetch('/api/progress?tg_user_id='+tgId);const data=await res.json();
    if(!data.items?.length)return;const sec=document.getElementById('cont-section');sec.style.display='block';
    const list=document.getElementById('cont-list');
    list.innerHTML=data.items.map(m=>{
        const pct=m.total_pages>0?Math.round(m.page_num/m.total_pages*100):0;
        let src=m.cover_imgbb_url||'';if(!src&&m.file_id)src='/api/cover/'+m.file_id;
        const covId='cc'+m.id,phId='cp'+m.id;
        const href=m.chapter_id?`/view-chapter/${m.chapter_id}`:`/read/${m.id}`;
        let btnLabel;
        if(m.is_series){if(m.is_first_chapter)btnLabel='Читать →';else if(m.total_pages>0)btnLabel=`Гл.${m.chapter_num} · ${m.page_num}/${m.total_pages}`;else btnLabel=`Гл. ${m.chapter_num||1}`;}
        else{btnLabel=m.total_pages>0?`Стр. ${m.page_num}/${m.total_pages}`:'Читать →';}
        const chapterLabel=m.chapter_id&&!m.is_first_chapter?`<div class="cont-chapter">Гл. ${escapeHtml(String(m.chapter_num||''))}</div>`:(m.is_series&&m.is_first_chapter?'<div class="cont-chapter">Серия</div>':'');
        return `<a class="cont-card" href="${href}"><div class="cont-cover-wrap">${src?`<img class="cont-cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';document.getElementById('${phId}').style.display='flex'">`:''}<div class="cont-cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:16px">📖</span></div></div><div class="cont-body"><div class="cont-title">${escapeHtml(m.title)}</div>${chapterLabel}${m.total_pages>0&&!m.is_first_chapter?`<div class="cont-bar-bg"><div class="cont-bar-fill" style="width:${pct}%"></div></div>`:''}<div class="cont-btn">${btnLabel}</div></div></a>`;
    }).join('');}catch(e){}
}

// ===== MESSAGES MODAL =====
function openAdminMessagesModal(){document.getElementById('admin-messages-modal').classList.add('open');loadAdminNotifications();}
function closeAdminMessagesModal(){document.getElementById('admin-messages-modal').classList.remove('open');}
async function loadAdminNotifications(){
    try{const res=await fetch('/api/admin/messages');const data=await res.json();const list=document.getElementById('msg-list');
    if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);text-align:center;padding:20px;font-size:13px">📭 Сообщений нет</div>';return;}
    list.innerHTML=data.items.map(m=>`<div class="msg-item"><div class="msg-text">${escapeHtml(m.text)}</div><div class="msg-meta">${new Date(m.created_at).toLocaleString('ru-RU')}</div></div>`).join('');}catch(e){}
}

// ===== SUPPORT MODAL =====
function openSupportModal(){document.getElementById('support-modal').classList.add('open');}
function closeSupportModal(){document.getElementById('support-modal').classList.remove('open');}
async function sendSupport(){const text=document.getElementById('support-text').value.trim();if(!text){showToast('Введи сообщение');return;}
try{const res=await fetch('/api/suggest',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text,tg_user_id:getTgUser()})});const d=await res.json();const b=document.getElementById('support-result');if(d.success){b.className='result-banner success open';b.innerHTML='✅ Сообщение отправлено!';document.getElementById('support-text').value='';}else{b.className='result-banner error open';b.innerHTML='❌ Ошибка';}}catch(e){}}

// ===== ADD MANGA MODAL =====
let coverFile=null,photoFiles=[],currentMangaType='single';
function openAddModal(){document.getElementById('add-modal').classList.add('open');loadGenresForAddForm();}
function closeAddModal(){document.getElementById('add-modal').classList.remove('open');}
function setMangaType(t){currentMangaType=t;document.getElementById('type-single').classList.toggle('active',t==='single');document.getElementById('type-series').classList.toggle('active',t==='series');document.getElementById('pages-section').style.display=t==='single'?'block':'none';}
function switchTab(tab){['zip','photos'].forEach(t=>{document.getElementById('tab-'+t).classList.toggle('active',t===tab);document.getElementById('panel-'+t).classList.toggle('active',t===tab);});}
function onCoverChange(input){if(!input.files[0])return;coverFile=input.files[0];const preview=document.getElementById('cover-preview');const reader=new FileReader();reader.onload=e=>{preview.innerHTML=`<img class="preview-img" src="${e.target.result}" style="width:70px;height:94px">`};reader.readAsDataURL(coverFile);}
function onPhotosChange(input){photoFiles=Array.from(input.files).sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'}));if(!photoFiles.length)return;const preview=document.getElementById('photos-preview');preview.innerHTML=`<div class="preview-count">📸 ${photoFiles.length} фото</div>`;photoFiles.slice(0,5).forEach(f=>{const r=new FileReader();r.onload=e=>{const img=document.createElement('img');img.className='preview-img';img.src=e.target.result;preview.appendChild(img);};r.readAsDataURL(f);});}
async function onZipChange(input){if(!input.files[0])return;const zipFile=input.files[0];const preview=document.getElementById('zip-preview');preview.innerHTML=`<div class="preview-count">⏳ Распаковка...</div>`;try{const{JSZip}=await loadJSZip();const zip=await JSZip.loadAsync(zipFile);const allowed=['jpg','jpeg','png','webp','gif'];const files=[];zip.forEach((relPath,file)=>{if(file.dir)return;const ext=relPath.split('.').pop().toLowerCase();if(!allowed.includes(ext))return;files.push({path:relPath,file,lastMod:file.date||new Date(0),name:relPath.split('/').pop()});});files.sort((a,b)=>{const dt=a.lastMod-b.lastMod;if(dt!==0)return dt;return a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'});});const blobs=[];for(const{path,file}of files){const ext=path.split('.').pop().toLowerCase();const mime={'jpg':'image/jpeg','jpeg':'image/jpeg','png':'image/png','webp':'image/webp','gif':'image/gif'}[ext]||'image/jpeg';const blob=await file.async('blob');blobs.push(new File([blob],path.replace(/\//g,'_'),{type:mime,lastModified:file.date?file.date.getTime():0}));}photoFiles=blobs;preview.innerHTML=`<div class="preview-count">📦 ${blobs.length} страниц</div>`;}catch(e){preview.innerHTML=`<div class="preview-count" style="color:#ff5050">❌ ${escapeHtml(e.message)}</div>`;}}
let _jszip=null;
async function loadJSZip(){if(_jszip)return _jszip;await new Promise((res,rej)=>{const s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';s.onload=res;s.onerror=rej;document.head.appendChild(s);});_jszip=window;return _jszip;}
async function uploadOneToImgbb(blob,keys){for(const key of keys){try{const b64=await new Promise((res,rej)=>{const r=new FileReader();r.onload=e=>res(e.target.result.split(',')[1]);r.onerror=()=>rej(new Error('read error'));r.readAsDataURL(blob);});const fd=new FormData();fd.append('key',key);fd.append('image',b64);const r=await fetch('https://api.imgbb.com/1/upload',{method:'POST',body:fd});if(r.ok){const d=await r.json();if(d?.data?.url)return d.data.url;}}catch(e){}}return null;}
function showResult(type,msg){const b=document.getElementById('result-banner');if(!type){b.className='result-banner';b.innerHTML='';return;}b.className='result-banner '+type+' open';b.innerHTML=msg;b.scrollIntoView({behavior:'smooth',block:'nearest'});}
async function submitManga(){
    const title=document.getElementById('manga-title').value.trim();const desc=document.getElementById('manga-desc').value.trim();const isSeries=currentMangaType==='series';
    if(!title){showResult('error','❌ Введи название!');return;}
    if(!isSeries&&!photoFiles.length&&!coverFile){showResult('error','❌ Загрузи обложку или страницы!');return;}
    const btn=document.getElementById('submit-btn');btn.disabled=true;btn.classList.add('loading');showResult('','');
    const pb=document.getElementById('upload-progress'),pf=document.getElementById('upload-progress-fill');pb.classList.add('active');pf.style.width='2%';
    try{
        const keysRes=await fetch('/api/imgbb-keys?tg_user_id='+getTgUser());const keysData=await keysRes.json();
        if(!keysData.success||!keysData.keys?.length){showResult('error','❌ Нет доступа к ключам');btn.disabled=false;btn.classList.remove('loading');return;}
        const keys=keysData.keys;let coverUrl=null;
        if(coverFile){pf.style.width='5%';coverUrl=await uploadOneToImgbb(coverFile,keys);}
        const pageUrls=[];
        if(!isSeries&&photoFiles.length){const total=photoFiles.length;for(let i=0;i<total;i++){pf.style.width=(5+Math.round((i/total)*88))+'%';const t=btn.querySelector('.btn-text');if(t)t.textContent=`⬆️ ${i+1}/${total}`;const url=await uploadOneToImgbb(photoFiles[i],keys);if(url)pageUrls.push(url);}}
        pf.style.width='95%';
        const addGenreIds=[...document.querySelectorAll('#add-genres-list input[type=checkbox][data-add-gid]:checked')].map(el=>parseInt(el.dataset.addGid));
        const addTagIds=[...document.querySelectorAll('#add-tags-list input[type=checkbox][data-add-tid]:checked')].map(el=>parseInt(el.dataset.addTid));
        const res=await fetch('/api/save-manga',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title,description:desc,cover_url:coverUrl,page_urls:pageUrls,tg_user_id:getTgUser(),is_series:isSeries,genre_ids:addGenreIds,tag_ids:addTagIds})});
        const data=await res.json();pf.style.width='100%';
        if(data.success){showResult('success',`✅ <strong>Манга добавлена!</strong><br>${isSeries?'📚 Серия создана<br>':''}${data.pages>0?`📄 ${data.pages} страниц<br>`:''}${data.telegraph?`🔗 <a href="${escapeHtml(data.telegraph)}" target="_blank">Telegraph</a><br>`:''}<a href="${escapeHtml(data.site_url)}" target="_blank">🌐 Открыть →</a>`);setTimeout(()=>{load(true);loadNew();},1500);}
        else{showResult('error','❌ '+(data.error||'Неизвестная ошибка'));}
    }catch(e){showResult('error','❌ '+e.message);}
    const t=btn.querySelector('.btn-text');if(t)t.textContent='🚀 Опубликовать';btn.disabled=false;btn.classList.remove('loading');
}
['cover-zone','zip-zone','photos-zone'].forEach(zId=>{const z=document.getElementById(zId);if(!z)return;z.addEventListener('dragover',e=>{e.preventDefault();z.classList.add('drag');});z.addEventListener('dragleave',()=>z.classList.remove('drag'));z.addEventListener('drop',e=>{e.preventDefault();z.classList.remove('drag');const inp=z.querySelector('input[type=file]');if(inp&&e.dataTransfer.files.length){const dt=new DataTransfer();Array.from(e.dataTransfer.files).forEach(f=>dt.items.add(f));inp.files=dt.files;inp.dispatchEvent(new Event('change'));}});});

// ===== ADMIN PANEL =====
function openAdminPanel(){document.getElementById('admin-modal').classList.add('open');loadAdminStats();loadAdmins();}
function closeAdminPanel(){document.getElementById('admin-modal').classList.remove('open');}
function switchAdminTab(tab){
    const tabs=['stats','messages','edit','add-chapter','tags'];
    document.querySelectorAll('.atab').forEach((t,i)=>t.classList.toggle('active',tabs[i]===tab));
    document.querySelectorAll('.apanel').forEach(p=>p.classList.remove('active'));
    document.getElementById('panel-'+tab).classList.add('active');
    if(tab==='stats')loadAdminStats();
    if(tab==='messages')loadAdminMessages();
    if(tab==='edit')loadMangaEditList('',0);
    if(tab==='tags')loadTagsPanel();
}
function showStatsView(view){
    document.getElementById('stats-grid-view').style.display=view==='grid'?'block':'none';
    document.getElementById('stats-archive-view').style.display=view==='archive'?'block':'none';
    document.getElementById('stats-suggestions-view').style.display=view==='suggestions'?'block':'none';
    if(view==='archive')loadArchive(0);
    if(view==='suggestions')loadSuggestions(0);
}
async function loadAdminStats(){
    try{const res=await fetch('/api/admin/stats?tg_user_id='+getTgUser());const data=await res.json();
    if(data.error){document.getElementById('stat-grid').innerHTML='<div style="color:var(--muted);grid-column:1/-1;font-size:12px">Нет прав</div>';return;}
    document.getElementById('stat-grid').innerHTML=`
        <div class="scard"><div class="scard-num">${data.manga_count}</div><div class="scard-lbl">📚 Манг</div></div>
        <div class="scard green"><div class="scard-num">${data.users_count}</div><div class="scard-lbl">👤 Юзеров</div></div>
        <div class="scard orange"><div class="scard-num">${data.votes_count}</div><div class="scard-lbl">👍 Голосов</div></div>
        <div class="scard"><div class="scard-num">${data.chapters_count}</div><div class="scard-lbl">📖 Глав</div></div>
        <div class="scard green"><div class="scard-num">${data.new_today}</div><div class="scard-lbl">🔥 Сегодня</div></div>
        <div class="scard"><div class="scard-num">${data.suggest_count}</div><div class="scard-lbl">💡 Предложек</div></div>`;
    document.getElementById('top-list').innerHTML=(data.top_manga||[]).map(m=>`<div class="top-row"><div class="top-name">${escapeHtml(m.title)}</div><div class="top-likes">♥ ${m.likes}</div></div>`).join('');
    const sb=document.getElementById('suggest-badge2');if(sb)sb.textContent=data.suggest_count>0?data.suggest_count:'';}catch(e){}
}
async function loadArchive(pg){
    try{const res=await fetch(`/api/admin/archive?page=${pg}&tg_user_id=`+getTgUser());const data=await res.json();
    document.getElementById('archive-list').innerHTML=data.items.map(a=>`<div class="aitem"><div class="atype">${escapeHtml(a.action_type)}</div><div class="atext">${escapeHtml(a.action_text)}</div><div class="adate">${new Date(a.created_at).toLocaleString('ru-RU')}</div></div>`).join('');
    const totalPages=Math.ceil(data.total/20);let pages='';if(pg>0)pages+=`<button class="page-btn" onclick="loadArchive(${pg-1})">← Назад</button>`;if(totalPages>1)pages+=`<span style="color:var(--muted);font-size:11px">${pg+1}/${totalPages}</span>`;if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadArchive(${pg+1})">Вперёд →</button>`;
    document.getElementById('archive-pagination').innerHTML=pages;}catch(e){}
}
async function loadSuggestions(pg){
    try{const res=await fetch(`/api/admin/suggestions?page=${pg}&tg_user_id=`+getTgUser());const data=await res.json();
    document.getElementById('suggest-list').innerHTML=data.items.map(s=>`<div class="sug-item"><div class="sug-text">${escapeHtml(s.text)}</div><div class="sug-meta">User #${s.user_id} · ${new Date(s.created_at).toLocaleDateString('ru-RU')}</div><button class="sug-read-btn" onclick="markSuggestion(${s.id},this)">✓ Прочитано</button></div>`).join('')||'<div style="color:var(--muted);font-size:12px">Новых нет</div>';
    const totalPages=Math.ceil(data.total/15);let pages='';if(pg>0)pages+=`<button class="page-btn" onclick="loadSuggestions(${pg-1})">← Назад</button>`;if(totalPages>1)pages+=`<span style="color:var(--muted);font-size:11px">${pg+1}/${totalPages}</span>`;if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadSuggestions(${pg+1})">Вперёд →</button>`;
    document.getElementById('suggest-pagination').innerHTML=pages;}catch(e){}
}
async function markSuggestion(id,btn){try{await fetch(`/api/admin/suggestions/${id}/status`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({status:'read',tg_user_id:getTgUser()})});btn.closest('.sug-item').remove();}catch(e){}}
async function loadAdmins(){try{const res=await fetch('/api/admin/admins?tg_user_id='+getTgUser());const data=await res.json();if(!data.admins)return;document.getElementById('admins-list').innerHTML=data.admins.map(a=>`<div class="admin-row"><div><div class="admin-tag">${escapeHtml(a.tag)}</div><div class="admin-id">ID: ${a.user_id}</div></div><button class="copy-btn" onclick="navigator.clipboard?.writeText?.('${a.user_id}');showToast('📋 Скопировано')">Копировать</button></div>`).join('');}catch(e){}}
function toggleAdminAddPanel(){const p=document.getElementById('admin-add-panel');p.style.display=p.style.display==='none'?'block':'none';if(p.style.display==='block'){document.getElementById('ap-admin-input').focus();document.getElementById('ap-admin-result').textContent='';}}
async function submitAddAdmin(){const input=document.getElementById('ap-admin-input').value.trim();const tag=document.getElementById('ap-admin-tag').value.trim()||'Администратор';const result=document.getElementById('ap-admin-result');if(!input){result.style.color='var(--red)';result.textContent='❌ Введи email или TG ID';return;}let endpoint='/api/admin/assign';let body={tag,action:'add'};if(/^\d+$/.test(input)){endpoint='/api/admin/assign-by-tgid';body.tg_id=parseInt(input);}else{body.email=input;}try{const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const d=await res.json();if(d.success){result.style.color='var(--green)';result.textContent='✅ '+d.message;document.getElementById('ap-admin-input').value='';document.getElementById('ap-admin-tag').value='';setTimeout(()=>{toggleAdminAddPanel();loadAdmins();},1500);}else{result.style.color='var(--red)';result.textContent='❌ '+d.error;}}catch(e){result.style.color='var(--red)';result.textContent='❌ Ошибка сети';}}

// ===== ADMIN MESSAGES =====
async function loadAdminMessages(){
    try{const res=await fetch('/api/admin/messages?tg_user_id='+getTgUser());const data=await res.json();
    const list=document.getElementById('admin-msg-list');
    if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);font-size:12px;padding:10px 0">Сообщений нет</div>';return;}
    list.innerHTML=data.items.map(m=>`<div class="msg-item" id="amsg-${m.id}"><button class="msg-del" onclick="deleteAdminMessage(${m.id})">Удалить</button><div class="msg-text">${escapeHtml(m.text)}</div><div class="msg-meta">${new Date(m.created_at).toLocaleString('ru-RU')}</div></div>`).join('');}catch(e){}
}
async function sendAdminMessage(){
    const text=document.getElementById('admin-msg-text').value.trim();
    if(!text){showToast('Введи сообщение');return;}
    try{const res=await fetch('/api/admin/messages/send',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text,tg_user_id:getTgUser()})});const d=await res.json();if(d.success){showToast('✅ Сообщение отправлено');document.getElementById('admin-msg-text').value='';loadAdminMessages();}else showToast('❌ Ошибка');}catch(e){}
}
async function deleteAdminMessage(id){if(!confirm('Удалить сообщение?'))return;try{await fetch(`/api/admin/messages/${id}/delete`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tg_user_id:getTgUser()})});const el=document.getElementById('amsg-'+id);if(el)el.remove();showToast('🗑 Удалено');}catch(e){}}

// ===== EDIT =====
let editQuery='',editPage=0;
async function loadMangaEditList(q='',pg=0){
    editQuery=q;editPage=pg;
    try{const res=await fetch(`/api/admin/manga-list?q=${encodeURIComponent(q)}&page=${pg}&tg_user_id=`+getTgUser());const data=await res.json();
    const list=document.getElementById('manga-edit-list');
    if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);padding:10px 0;font-size:12px">Ничего не найдено</div>';document.getElementById('edit-pagination').innerHTML='';return;}
    list.innerHTML=data.items.map(m=>`<div class="meitem" onclick="openEditManga(${m.id})"><div class="me-cover">📖</div><div><div class="me-title">${escapeHtml(m.title)}</div><div class="me-meta">${m.is_series?'📚 Серия':'📄 Обычная'}</div></div></div>`).join('');
    const totalPages=Math.ceil(data.total/10);let pages='';if(pg>0)pages+=`<button class="page-btn" onclick="loadMangaEditList('${escapeHtml(editQuery)}',${pg-1})">← Назад</button>`;if(totalPages>1)pages+=`<span style="color:var(--muted);font-size:11px">${pg+1}/${totalPages}</span>`;if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadMangaEditList('${escapeHtml(editQuery)}',${pg+1})">Вперёд →</button>`;
    document.getElementById('edit-pagination').innerHTML=pages;}catch(e){}
}
function searchMangaEdit(){loadMangaEditList(document.getElementById('edit-search-input').value.trim(),0);}
document.getElementById('edit-search-input').addEventListener('keydown',e=>{if(e.key==='Enter')searchMangaEdit();});
async function openEditManga(mangaId){
    try{const res=await fetch(`/api/admin/manga/${mangaId}?tg_user_id=`+getTgUser());const manga=await res.json();
    document.getElementById('manga-edit-list').style.display='none';document.getElementById('edit-pagination').style.display='none';document.querySelector('#panel-edit .esearch-row').style.display='none';
    const fw=document.getElementById('edit-manga-form-wrap');fw.style.display='block';
    let chaptersHtml='';
    if(manga.is_series&&manga.chapters?.length){chaptersHtml=`<div style="margin-top:12px"><div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:6px">Главы</div><div class="ch-admin-list">${manga.chapters.map(ch=>`<div class="ch-admin-item"><span style="font-size:11px;font-weight:600">Гл. ${ch.chapter_num}${ch.title?' — '+escapeHtml(ch.title):''}</span><button class="del-ch-btn" onclick="deleteChapter(${ch.id},this)">🗑</button></div>`).join('')}</div></div>`;}
    // Load genres/tags for the form
    let allGenres=[], allTags=[], mangaGenreIds=new Set(), mangaTagIds=new Set();
    try{const gr=await fetch('/api/genres');const gd=await gr.json();allGenres=gd.genres||[];allTags=gd.tags||[];}catch(e){}
    try{const mgr=await fetch(`/api/manga/${mangaId}/genres`);const mgd=await mgr.json();
    mgd.genres?.forEach(g=>mangaGenreIds.add(g.id));mgd.tags?.forEach(t=>mangaTagIds.add(t.id));}catch(e){}
    const genresHtml=allGenres.length?`<div class="ef"><label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">🎭 Жанры</label><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;max-height:120px;overflow-y:auto;padding:4px 0">${allGenres.map(g=>`<label style="font-size:10px;cursor:pointer;padding:3px 8px;border:1px solid ${mangaGenreIds.has(g.id)?'var(--border2)':'var(--border)'};border-radius:20px;background:${mangaGenreIds.has(g.id)?'rgba(124,92,255,0.15)':'transparent'};transition:all .15s;display:inline-flex;align-items:center;gap:3px"><input type="checkbox" data-gid="${g.id}" ${mangaGenreIds.has(g.id)?'checked':''} style="display:none" onchange="this.closest('label').style.background=this.checked?'rgba(124,92,255,0.15)':'transparent';this.closest('label').style.borderColor=this.checked?'var(--border2)':'var(--border)'">${escapeHtml(g.name)}</label>`).join('')}</div></div>`:'';
    const tagsHtml=allTags.length?`<div class="ef" style="margin-top:8px"><label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">🏷 Теги</label><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;max-height:140px;overflow-y:auto;padding:4px 0">${allTags.map(t=>`<label style="font-size:10px;cursor:pointer;padding:3px 8px;border:1px solid ${mangaTagIds.has(t.id)?'var(--border2)':t.is_nsfw?'rgba(239,68,68,0.3)':'var(--border)'};border-radius:20px;background:${mangaTagIds.has(t.id)?'rgba(124,92,255,0.15)':'transparent'};transition:all .15s;display:inline-flex;align-items:center;gap:3px"><input type="checkbox" data-tid="${t.id}" ${mangaTagIds.has(t.id)?'checked':''} style="display:none" onchange="this.closest('label').style.background=this.checked?'rgba(124,92,255,0.15)':'transparent';this.closest('label').style.borderColor=this.checked?'var(--border2)':'var(--border)'">${escapeHtml(t.name)}${t.is_nsfw?' 🔞':''}</label>`).join('')}</div></div>`:'';
    document.getElementById('edit-manga-form').innerHTML=`
        <div class="ef"><label>Название</label><input type="text" id="ef-title" value="${escapeHtml(manga.title)}"></div>
        <div class="ef"><label>Описание</label><textarea id="ef-desc">${escapeHtml(manga.description||'')}</textarea></div>
        <div class="ef"><label>Ссылка Telegraph</label><input type="text" id="ef-link" value="${escapeHtml(manga.telegraph_url||'')}"></div>
        <div class="ef"><label>URL обложки</label><input type="text" id="ef-cover" value="${escapeHtml(manga.cover_imgbb_url||'')}"></div>
        ${manga.cover_imgbb_url?`<img src="${escapeHtml(manga.cover_imgbb_url)}" style="width:64px;height:86px;object-fit:cover;border-radius:8px;margin-bottom:9px">`:''}
        ${genresHtml}${tagsHtml}
        ${chaptersHtml}
        <div class="edit-actions">
            <button class="save-btn" onclick="saveMangaEdit(${mangaId})">💾 Сохранить</button>
            <button class="del-btn" onclick="deleteManga(${mangaId})">🗑 Удалить</button>
        </div>
        <div class="result-banner" id="ef-result"></div>`;}catch(e){showToast('❌ Ошибка загрузки');}
}
function backToMangaList(){document.getElementById('edit-manga-form-wrap').style.display='none';document.getElementById('manga-edit-list').style.display='flex';document.getElementById('edit-pagination').style.display='flex';document.querySelector('#panel-edit .esearch-row').style.display='flex';}
async function saveMangaEdit(mangaId){try{
    const res=await fetch(`/api/admin/manga/${mangaId}?tg_user_id=`+getTgUser(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title:document.getElementById('ef-title').value.trim(),description:document.getElementById('ef-desc').value.trim(),telegraph_url:document.getElementById('ef-link').value.trim(),cover_imgbb_url:document.getElementById('ef-cover').value.trim()})});
    const data=await res.json();
    const b=document.getElementById('ef-result');
    // Save genres/tags
    const genreIds=[...document.querySelectorAll('#edit-manga-form input[type=checkbox][data-gid]:checked')].map(el=>parseInt(el.dataset.gid));
    const tagIds=[...document.querySelectorAll('#edit-manga-form input[type=checkbox][data-tid]:checked')].map(el=>parseInt(el.dataset.tid));
    if(genreIds.length>=0||tagIds.length>=0){try{await fetch(`/api/admin/manga/${mangaId}/genres?tg_user_id=`+getTgUser(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({genre_ids:genreIds,tag_ids:tagIds})});}catch(e){}}
    if(data.success){b.className='result-banner success open';b.innerHTML='✅ Сохранено!';load(true);}else{b.className='result-banner error open';b.innerHTML='❌ Ошибка';}
}catch(e){showToast('❌ Ошибка');}}
async function deleteManga(mangaId){if(!confirm('Удалить эту мангу?'))return;try{const res=await fetch(`/api/admin/manga/${mangaId}/delete?tg_user_id=`+getTgUser(),{method:'POST'});const data=await res.json();if(data.success){showToast('🗑 Удалено');backToMangaList();loadMangaEditList(editQuery,editPage);load(true);}}catch(e){}}
async function deleteChapter(chapterId,btn){if(!confirm('Удалить главу?'))return;try{const res=await fetch(`/api/admin/chapter/${chapterId}/delete?tg_user_id=`+getTgUser(),{method:'POST'});const data=await res.json();if(data.success){btn.closest('.ch-admin-item').remove();showToast('🗑 Глава удалена');}}catch(e){}}

// ===== ADD CHAPTER =====
let selectedMangaId=null,chPhotoFiles=[];
async function searchMangaForChapter(){
    const q=document.getElementById('ch-manga-search').value.trim();
    try{const res=await fetch(`/api/admin/manga-list?q=${encodeURIComponent(q)}&page=0&tg_user_id=`+getTgUser());const data=await res.json();const list=document.getElementById('ch-manga-list');if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);padding:9px 0;font-size:12px">Ничего не найдено</div>';return;}list.innerHTML=data.items.map(m=>`<div class="meitem" onclick="selectMangaForChapter(${m.id},'${escapeHtml(m.title).replace(/'/g,"\\'")}')"><div class="me-cover">📖</div><div><div class="me-title">${escapeHtml(m.title)}</div><div class="me-meta">${m.is_series?'📚 Серия':'📄 Обычная'}</div></div></div>`).join('');}catch(e){}
}
function selectMangaForChapter(id,title){selectedMangaId=id;document.getElementById('ch-add-form').style.display='block';document.getElementById('ch-manga-list').innerHTML=`<div style="background:rgba(124,92,255,0.07);border:1px solid rgba(124,92,255,0.22);border-radius:9px;padding:8px 11px;font-weight:600;color:var(--accent);font-size:12px">✅ ${escapeHtml(title)}</div>`;showToast('Выбрана: '+title);}
document.getElementById('ch-manga-search').addEventListener('keydown',e=>{if(e.key==='Enter')searchMangaForChapter();});
function switchChTab(tab){['zip','photos'].forEach(t=>{document.getElementById('ch-tab-'+t).classList.toggle('active',t===tab);document.getElementById('ch-panel-'+t).classList.toggle('active',t===tab);});}
function onChPhotosChange(input){chPhotoFiles=Array.from(input.files).sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'}));document.getElementById('ch-photos-preview').innerHTML=`<div class="preview-count">📸 ${chPhotoFiles.length} стр.</div>`;}
async function onChZipChange(input){if(!input.files[0])return;const preview=document.getElementById('ch-zip-preview');preview.innerHTML=`<div class="preview-count">⏳ Распаковка...</div>`;try{const{JSZip}=await loadJSZip();const zip=await JSZip.loadAsync(input.files[0]);const allowed=['jpg','jpeg','png','webp','gif'];const files=[];zip.forEach((relPath,file)=>{if(file.dir)return;const ext=relPath.split('.').pop().toLowerCase();if(!allowed.includes(ext))return;files.push({path:relPath,file,lastMod:file.date||new Date(0),name:relPath.split('/').pop()});});files.sort((a,b)=>{const dt=a.lastMod-b.lastMod;if(dt!==0)return dt;return a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'});});const blobs=[];for(const{path,file}of files){const ext=path.split('.').pop().toLowerCase();const mime={'jpg':'image/jpeg','jpeg':'image/jpeg','png':'image/png','webp':'image/webp','gif':'image/gif'}[ext]||'image/jpeg';const blob=await file.async('blob');blobs.push(new File([blob],path.replace(/\//g,'_'),{type:mime}));}chPhotoFiles=blobs;preview.innerHTML=`<div class="preview-count">📦 ${blobs.length} стр.</div>`;}catch(e){preview.innerHTML=`<div class="preview-count" style="color:#ff5050">❌ ${escapeHtml(e.message)}</div>`;}}
async function submitChapter(){
    if(!selectedMangaId){showToast('❌ Выбери мангу!');return;}
    const chNum=parseFloat(document.getElementById('ch-num').value);const chTitle=document.getElementById('ch-title-input').value.trim();
    if(!chNum||chNum<0){showToast('❌ Укажи номер!');return;}if(!chPhotoFiles.length){showToast('❌ Загрузи страницы!');return;}
    const btn=document.getElementById('ch-submit-btn');btn.disabled=true;btn.classList.add('loading');
    const pb=document.getElementById('ch-upload-progress'),pf=document.getElementById('ch-upload-fill');pb.classList.add('active');pf.style.width='2%';
    const rb=document.getElementById('ch-result-banner');rb.className='result-banner';rb.innerHTML='';
    try{
        const keysRes=await fetch('/api/imgbb-keys?tg_user_id='+getTgUser());const keysData=await keysRes.json();if(!keysData.success){showToast('❌ Нет доступа');btn.disabled=false;btn.classList.remove('loading');return;}
        const keys=keysData.keys;const pageUrls=[];const total=chPhotoFiles.length;
        for(let i=0;i<total;i++){pf.style.width=(2+Math.round((i/total)*90))+'%';const t=btn.querySelector('.btn-text');if(t)t.textContent=`⬆️ ${i+1}/${total}`;const url=await uploadOneToImgbb(chPhotoFiles[i],keys);if(url)pageUrls.push(url);}
        pf.style.width='95%';
        const res=await fetch('/api/save-chapter',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:selectedMangaId,chapter_num:chNum,chapter_title:chTitle,page_urls:pageUrls,tg_user_id:getTgUser()})});
        const data=await res.json();pf.style.width='100%';
        if(data.success){rb.className='result-banner success open';rb.innerHTML=`✅ Глава ${chNum} добавлена! ${pageUrls.length} стр.${data.telegraph?`<br><a href="${escapeHtml(data.telegraph)}" target="_blank">📄 Telegraph</a>`:''}`;document.getElementById('ch-num').value='';document.getElementById('ch-title-input').value='';chPhotoFiles=[];document.getElementById('ch-zip-preview').innerHTML='';document.getElementById('ch-photos-preview').innerHTML='';}
        else{rb.className='result-banner error open';rb.innerHTML='❌ '+(data.error||'Ошибка');}
    }catch(e){rb.className='result-banner error open';rb.innerHTML='❌ '+e.message;}
    const t=btn.querySelector('.btn-text');if(t)t.textContent='📤 Загрузить главу';btn.disabled=false;btn.classList.remove('loading');
}

// ===== TAGS & GENRES ADMIN PANEL =====
async function loadTagsPanel(){
    try{
        const res=await fetch('/api/genres?_='+Date.now());
        const data=await res.json();
        if(data.error){showToast('❌ Ошибка API: '+data.error);console.error('[TagsPanel] error:',data.error);}
        renderGenreManageList(data.genres||[]);
        renderTagManageList(data.tags||[]);
        document.getElementById('genre-count-badge').textContent=data.genres?.length||0;
        document.getElementById('tag-count-badge').textContent=data.tags?.length||0;
    }catch(e){showToast('❌ Ошибка загрузки: '+e.message);console.error('[TagsPanel]',e);}
}
function renderGenreManageList(genres){
    const el=document.getElementById('genres-manage-list');
    if(!genres.length){el.innerHTML='<div style="color:var(--muted);font-size:12px;padding:8px 0">Жанров нет. Нажми «Загрузить все дефолтные»</div>';return;}
    el.innerHTML=genres.map(g=>`
        <div style="display:flex;align-items:center;gap:8px;padding:6px 9px;background:var(--card2);border:1px solid var(--border);border-radius:7px">
            <span style="flex:1;font-size:12px;color:var(--text)">${escapeHtml(g.name)}</span>
            <span style="font-size:10px;color:var(--muted);font-family:monospace">${escapeHtml(g.slug)}</span>
            <button onclick="deleteGenre(${g.id},this)" style="padding:3px 8px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);border-radius:5px;color:#f87171;font-size:11px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.background='rgba(239,68,68,0.2)'" onmouseout="this.style.background='rgba(239,68,68,0.1)'">🗑</button>
        </div>`).join('');
}
function renderTagManageList(tags){
    const el=document.getElementById('tags-manage-list');
    if(!tags.length){el.innerHTML='<div style="color:var(--muted);font-size:12px;padding:8px 0">Тегов нет. Нажми «Загрузить все дефолтные»</div>';return;}
    el.innerHTML=tags.map(t=>`
        <div style="display:flex;align-items:center;gap:8px;padding:6px 9px;background:var(--card2);border:1px solid ${t.is_nsfw?'rgba(239,68,68,0.2)':'var(--border)'};border-radius:7px">
            <span style="flex:1;font-size:12px;color:${t.is_nsfw?'#f87171':'var(--text)'}">${escapeHtml(t.name)}${t.is_nsfw?' 🔞':''}</span>
            <span style="font-size:10px;color:var(--muted);font-family:monospace">${escapeHtml(t.slug)}</span>
            <button onclick="deleteTag(${t.id},this)" style="padding:3px 8px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);border-radius:5px;color:#f87171;font-size:11px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.background='rgba(239,68,68,0.2)'" onmouseout="this.style.background='rgba(239,68,68,0.1)'">🗑</button>
        </div>`).join('');
}
async function addGenre(){
    const name=document.getElementById('new-genre-name').value.trim();
    const slug=document.getElementById('new-genre-slug').value.trim().toLowerCase().replace(/[^a-z0-9\-]/g,'');
    if(!name||!slug){showToast('❌ Заполни название и slug');return;}
    try{
        const res=await fetch('/api/admin/genres/add?tg_user_id='+getTgUser(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,slug})});
        const data=await res.json();
        if(data.success){showToast('✅ Жанр добавлен');document.getElementById('new-genre-name').value='';document.getElementById('new-genre-slug').value='';loadTagsPanel();}
        else showToast('❌ '+(data.error||'Ошибка'));
    }catch(e){showToast('❌ Ошибка');}
}
async function deleteGenre(id,btn){
    if(!confirm('Удалить жанр? Он будет убран у всех манг'))return;
    btn.disabled=true;
    try{
        const res=await fetch(`/api/admin/genres/${id}/delete?tg_user_id=`+getTgUser(),{method:'POST'});
        const data=await res.json();
        if(data.success){showToast('🗑 Жанр удалён');loadTagsPanel();}
        else showToast('❌ '+(data.error||'Ошибка'));
    }catch(e){showToast('❌ Ошибка');btn.disabled=false;}
}
async function addTag(){
    const name=document.getElementById('new-tag-name').value.trim();
    const slug=document.getElementById('new-tag-slug').value.trim().toLowerCase().replace(/[^a-z0-9\-]/g,'');
    const isNsfw=document.getElementById('new-tag-nsfw').checked;
    if(!name||!slug){showToast('❌ Заполни название и slug');return;}
    try{
        const res=await fetch('/api/admin/tags/add?tg_user_id='+getTgUser(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,slug,is_nsfw:isNsfw})});
        const data=await res.json();
        if(data.success){showToast('✅ Тег добавлен');document.getElementById('new-tag-name').value='';document.getElementById('new-tag-slug').value='';document.getElementById('new-tag-nsfw').checked=false;loadTagsPanel();_genreTagsData=null;}
        else showToast('❌ '+(data.error||'Ошибка'));
    }catch(e){showToast('❌ Ошибка');}
}
async function deleteTag(id,btn){
    if(!confirm('Удалить тег? Он будет убран у всех манг'))return;
    btn.disabled=true;
    try{
        const res=await fetch(`/api/admin/tags/${id}/delete?tg_user_id=`+getTgUser(),{method:'POST'});
        const data=await res.json();
        if(data.success){showToast('🗑 Тег удалён');_genreTagsData=null;loadTagsPanel();}
        else showToast('❌ '+(data.error||'Ошибка'));
    }catch(e){showToast('❌ Ошибка');btn.disabled=false;}
}
async function dedupTagsGenres(){
    if(!confirm('Удалить дубли жанров и тегов? Оставит первый вариант каждого.'))return;
    try{
        const res=await fetch('/api/admin/dedup-genres?tg_user_id='+getTgUser(),{method:'POST'});
        const data=await res.json();
        if(data.success){
            _genreTagsData=null;
            showToast(`✅ Готово! Тегов: ${data.tags}, жанров: ${data.genres}`);
            setTimeout(()=>loadTagsPanel(), 300);
        } else showToast('❌ '+(data.error||'Ошибка'));
    }catch(e){showToast('❌ Ошибка');}
}
async function reseedAllTags(){
    if(!confirm('Загрузить все стандартные теги и жанры? Дубли не добавятся.'))return;
    try{
        const res=await fetch('/api/admin/reseed-tags?tg_user_id='+getTgUser(),{method:'POST'});
        const data=await res.json();
        if(data.success){
            _genreTagsData=null; // сбросить кеш
            showToast(`✅ Готово! Тегов: ${data.tags}, жанров: ${data.genres}`);
            // Подождать и перезагрузить
            setTimeout(()=>loadTagsPanel(), 300);
        }
        else showToast('❌ '+(data.error||'Ошибка'));
    }catch(e){showToast('❌ Ошибка');}
}
// Авто-генерация slug из названия
document.addEventListener('DOMContentLoaded',()=>{
    const autoSlug=(nameId,slugId)=>{
        const nameEl=document.getElementById(nameId),slugEl=document.getElementById(slugId);
        if(nameEl&&slugEl)nameEl.addEventListener('input',()=>{if(!slugEl.dataset.manual)slugEl.value=nameEl.value.toLowerCase().replace(/ё/g,'e').replace(/[а-яёА-ЯЁ]/g,c=>({'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ж':'zh','з':'z','и':'i','й':'j','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'h','ц':'ts','ч':'ch','ш':'sh','щ':'shch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'}[c.toLowerCase()]||c)).replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');});
        if(slugEl)slugEl.addEventListener('input',()=>slugEl.dataset.manual='1');
    };
    autoSlug('new-genre-name','new-genre-slug');
    autoSlug('new-tag-name','new-tag-slug');
});

// ===== INIT =====
// Hide page loader
(function(){
    function hideLoader(){var l=document.getElementById('page-loader');if(l){l.style.transition='opacity 0.3s ease';l.style.opacity='0';l.style.visibility='hidden';setTimeout(function(){if(l.parentNode)l.parentNode.removeChild(l);},350);}}
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',hideLoader);}
    else{hideLoader();}
    setTimeout(hideLoader,1500);
})();

// ===== HERO CAROUSEL =====
let _heroData=[],_heroCur=0,_heroTimer=null;
async function loadHero(){
    try{
        const res=await fetch('/api/top-week');const data=await res.json();
        if(!data.success||!data.items?.length)return;
        _heroData=data.items.slice(0,6);
        const track=document.getElementById('hero-track');
        const dots=document.getElementById('hero-dots');
        const thumbs=document.getElementById('hero-thumbs');
        if(!track)return;
        track.innerHTML=_heroData.map((m,i)=>{
            const src=(m.cover_display&&!m.cover_display.startsWith('tg://'))?m.cover_display:'';
            const rat=m.avg_rating>0?`<div class="hero-stat">★ <strong>${m.avg_rating}</strong></div>`:'';
            const lk=m.likes>0?`<div class="hero-stat">♥ <strong>${m.likes}</strong></div>`:'';
            const vw=m.weekly_views>0?`<div class="hero-stat">👁 <strong>${m.weekly_views}</strong></div>`:'';
            return `<div class="hero-slide">
                <div class="hero-slide-bg" style="${src?`background-image:url('${escapeHtml(src)}');`:''}background-color:#101014"></div>
                <div class="hero-slide-grad"></div><div class="hero-slide-grad2"></div>
                <div class="hero-slide-accentbar"></div>
                <div class="hero-content">
                    <div class="hero-label">ТОП НЕДЕЛИ</div>
                    <div class="hero-rank">#${i+1} ${m.is_series?'📚 Серия':'📄 Манга'}</div>
                    <h1 class="hero-title">${escapeHtml(m.title)}</h1>
                    ${m.description?`<p class="hero-desc">${escapeHtml(m.description)}</p>`:''}
                    <div class="hero-stats">${rat}${lk}${vw}</div>
                    <div class="hero-actions">
                        <a href="/read/${m.id}" class="hero-btn hero-btn-primary">▶ Читать сейчас</a>
                        <button class="hero-btn hero-btn-secondary" onclick="event.stopPropagation();showToast('🔖 Добавлено в библиотеку')">🔖 Сохранить</button>
                    </div>
                </div>
            </div>`;
        }).join('');
        if(dots)dots.innerHTML=_heroData.map((_,i)=>`<div class="hero-dot${i===0?' active':''}" onclick="heroGo(${i})"></div>`).join('');
        if(thumbs)thumbs.innerHTML=_heroData.map((m,i)=>{
            const src=(m.cover_display&&!m.cover_display.startsWith('tg://'))?m.cover_display:'';
            return `<div class="hero-thumb${i===0?' active':''}" onclick="heroGo(${i})">${src?`<img src="${escapeHtml(src)}" alt="">`:''}</div>`;
        }).join('');
        heroGo(0);
        _heroTimer=setInterval(()=>heroGo((_heroCur+1)%_heroData.length),5500);
    }catch(e){}
}
function heroGo(idx){
    _heroCur=Math.max(0,Math.min(_heroData.length-1,idx));
    const t=document.getElementById('hero-track');if(t)t.style.transform=`translateX(-${_heroCur*100}%)`;
    document.querySelectorAll('.hero-dot').forEach((d,i)=>d.classList.toggle('active',i===_heroCur));
    document.querySelectorAll('.hero-thumb').forEach((d,i)=>d.classList.toggle('active',i===_heroCur));
}
function heroSlide(dir){
    heroGo((_heroCur+dir+_heroData.length)%_heroData.length);
    clearInterval(_heroTimer);_heroTimer=setInterval(()=>heroGo((_heroCur+1)%_heroData.length),5500);
}
// touch swipe for hero
(function(){let sx=0;
    document.addEventListener('touchstart',e=>{if(e.target.closest('#hero-banner'))sx=e.touches[0].clientX;},{passive:true});
    document.addEventListener('touchend',e=>{if(!e.target.closest('#hero-banner'))return;const d=sx-e.changedTouches[0].clientX;if(Math.abs(d)>40)heroSlide(d>0?1:-1);},{passive:true});
})();
// mobile search reveal
(function(){
    function check(){const msr=document.getElementById('mobile-search-row');const hsr=document.getElementById('search-dropdown');if(window.innerWidth<=768){if(msr)msr.style.display='block';}else{if(msr)msr.style.display='none';}}
    check();window.addEventListener('resize',check);
})();

load();loadNew();loadContinue();checkAdmin();
document.addEventListener('DOMContentLoaded',function(){loadHero();loadTopWeek();setInterval(loadTopWeek,30*60*1000);});


// ===== НОВЫЕ JS ФУНКЦИИ =====

// Функции для комментариев
async function loadComments(mangaId) {
    if (!mangaId) return;
    const section = document.getElementById('comments-section');
    if (!section) return;
    
    try {
        const res = await fetch(`/api/comments/${mangaId}`);
        const data = await res.json();
        if (data.success && data.comments) {
            const listDiv = document.getElementById('comments-list');
            const emptyDiv = document.getElementById('comments-empty');
            const count = document.getElementById('comments-count');
            
            count.textContent = `(${data.comments.length})`;
            
            if (data.comments.length === 0) {
                emptyDiv.style.display = 'block';
                listDiv.innerHTML = '';
            } else {
                emptyDiv.style.display = 'none';
                listDiv.innerHTML = data.comments.map(c => `
                    <div style="padding:10px;background:var(--card2);border:1px solid var(--border);border-radius:8px">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#7c5cff,#5a4ca0);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600;flex-shrink:0">${c.username[0].toUpperCase()}</div>
                            <div>
                                <div style="font-weight:600;font-size:12px;color:var(--text)">${escapeHtml(c.username)}</div>
                                <div style="font-size:10px;color:var(--muted)">${new Date(c.created_at).toLocaleDateString('ru-RU')}</div>
                            </div>
                        </div>
                        <div style="font-size:13px;color:var(--text2);line-height:1.4;padding-left:36px;margin-top:-24px;padding-top:24px">${escapeHtml(c.text)}</div>
                    </div>
                `).join('');
            }
            section.style.display = 'block';
        }
    } catch (e) { console.error(e); }
}

async function submitComment() {
    const input = document.getElementById('comment-input');
    const text = input.value.trim();
    if (!text || text.length > 500) {
        showToast('Комментарий должен быть 1-500 символов');
        return;
    }
    
    const mangaId = new URLSearchParams(location.search).get('id') || location.pathname.split('/').pop();
    
    try {
        const res = await fetch(`/api/comments/${mangaId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text })
        });
        const data = await res.json();
        if (data.success) {
            input.value = '';
            loadComments(mangaId);
            showToast('✓ Комментарий добавлен!');
        }
    } catch (e) {
        showToast('Ошибка отправки комментария');
    }
}

// ===== ПОЛНЫЙ ЧАТ =====
let chatCurrentUserId = null;
let chatCurrentUsername = null;
let chatPollInterval = null;
let chatLastMsgCount = 0;

const EMOJIS = ['😀','😂','😍','🥰','😎','😭','😤','🤔','😮','🥺','❤️','🔥','👍','👎','✨','💯','🎉','😈','🤣','😊','🙄','😅','🫡','💀','🫶','💪','🎮','📖','⭐','🏆'];

function openMessagesModal() {
    const modal = document.getElementById('messages-modal');
    if (!modal) return;
    modal.style.display = 'block';
    showChatList();
}

function closeMessagesModal() {
    const modal = document.getElementById('messages-modal');
    if (modal) modal.style.display = 'none';
    if (chatPollInterval) { clearInterval(chatPollInterval); chatPollInterval = null; }
    chatCurrentUserId = null;
    chatCurrentUsername = null;
    const emojiPicker = document.getElementById('emoji-picker');
    if (emojiPicker) emojiPicker.style.display = 'none';
}

function showChatList() {
    chatCurrentUserId = null;
    chatCurrentUsername = null;
    if (chatPollInterval) { clearInterval(chatPollInterval); chatPollInterval = null; }
    document.getElementById('chat-list-view').style.display = 'flex';
    document.getElementById('chat-dialog-view').style.display = 'none';
    document.getElementById('chat-back-btn').style.display = 'none';
    document.getElementById('chat-header-name').textContent = 'Сообщения';
    document.getElementById('chat-header-sub').textContent = 'Личные сообщения';
    document.getElementById('chat-header-avatar').textContent = '💬';
    loadMessagesList();
}

async function loadMessagesList() {
    try {
        const res = await fetch('/api/messages');
        const data = await res.json();
        const listDiv = document.getElementById('messages-list');
        if (!listDiv) return;
        if (!data.success || !data.messages || data.messages.length === 0) {
            listDiv.innerHTML = '<div class="chat-empty">📭<br>Нет диалогов<br><span style="font-size:10px;margin-top:4px;display:block">Найди пользователя выше и напиши первым</span></div>';
            return;
        }
        const totalUnread = data.messages.reduce((s, m) => s + (m.unread_count || 0), 0);
        updateChatBadge(totalUnread);
        listDiv.innerHTML = data.messages.map(m => `
            <div class="message-item${m.unread_count>0?' unread':''}" onclick="openDialog(${m.other_id}, '${escapeHtml(m.other_username)}')">
                <div class="message-avatar">👤</div>
                <div class="message-content">
                    <div class="message-username">${escapeHtml(m.other_username)}</div>
                    <div class="message-preview">${m.last_text ? escapeHtml(m.last_text.substring(0,40)) : 'Нет сообщений'}</div>
                </div>
                ${m.unread_count>0 ? `<div class="unread-dot"></div>` : ''}
            </div>
        `).join('');
    } catch (e) { console.error(e); }
}

function updateChatBadge(count) {
    const badges = document.querySelectorAll('#messages-badge,#msg-badge');
    const chatBtns = document.querySelectorAll('.sidebar-icon-btn[onclick*="openMessagesModal"]');
    badges.forEach(b => b.style.display = count > 0 ? 'block' : 'none');
    chatBtns.forEach(b => { if(count>0) b.classList.add('has-unread'); else b.classList.remove('has-unread'); });
}

async function openDialog(userId, username) {
    chatCurrentUserId = userId;
    chatCurrentUsername = username;
    document.getElementById('chat-list-view').style.display = 'none';
    const dialogView = document.getElementById('chat-dialog-view');
    dialogView.style.display = 'flex';
    dialogView.style.flexDirection = 'column';
    const backBtn = document.getElementById('chat-back-btn');
    backBtn.style.display = 'flex';
    document.getElementById('chat-header-name').textContent = username;
    document.getElementById('chat-header-sub').textContent = 'В сети · пишет...';
    document.getElementById('chat-header-avatar').textContent = '👤';
    document.getElementById('chat-input').value = '';
    document.getElementById('chat-input').style.height = 'auto';
    const emojiPicker = document.getElementById('emoji-picker');
    if (emojiPicker && !document.getElementById('emoji-grid').innerHTML) {
        document.getElementById('emoji-grid').innerHTML = EMOJIS.map(e => `<button onclick="insertEmoji('${e}')" style="background:none;border:none;font-size:20px;cursor:pointer;padding:2px;border-radius:4px;transition:transform .1s" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'">${e}</button>`).join('');
    }
    await loadDialogMessages();
    if (chatPollInterval) clearInterval(chatPollInterval);
    chatPollInterval = setInterval(loadDialogMessages, 3000);
    setTimeout(() => document.getElementById('chat-input').focus(), 100);
}

async function loadDialogMessages() {
    if (!chatCurrentUserId) return;
    try {
        const res = await fetch(`/api/messages/${chatCurrentUserId}`);
        const data = await res.json();
        if (!data.success) return;
        const area = document.getElementById('chat-messages-area');
        const msgs = data.messages || [];
        const isNew = msgs.length !== chatLastMsgCount;
        chatLastMsgCount = msgs.length;
        if (msgs.length === 0) {
            area.innerHTML = '<div class="chat-empty">👋<br>Начни разговор первым!</div>';
            return;
        }
        const wasAtBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 60;
        area.innerHTML = msgs.map(m => {
            // Determine if message is mine by comparing usernames
            const isMine = m.sender_username !== chatCurrentUsername;
            const time = new Date(m.created_at).toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'});
            return `<div class="chat-bubble-wrap ${isMine?'mine':'theirs'}">
                <div class="chat-bubble ${isMine?'mine':'theirs'}">${escapeHtml(m.text)}</div>
                <div class="chat-time">${time}</div>
            </div>`;
        }).join('');
        if (isNew || wasAtBottom) area.scrollTop = area.scrollHeight;
    } catch(e) { console.error(e); }
}

async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text || !chatCurrentUserId) return;
    input.value = '';
    input.style.height = 'auto';
    try {
        const res = await fetch('/api/messages', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({recipient_id: chatCurrentUserId, text})
        });
        const data = await res.json();
        if (data.success) {
            await loadDialogMessages();
        } else {
            showToast('❌ Ошибка отправки — войди в аккаунт');
        }
    } catch(e) { showToast('❌ Нет соединения'); }
}

function toggleEmojiPicker() {
    const picker = document.getElementById('emoji-picker');
    picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
}

function insertEmoji(emoji) {
    const input = document.getElementById('chat-input');
    const pos = input.selectionStart;
    input.value = input.value.slice(0, pos) + emoji + input.value.slice(input.selectionEnd);
    input.selectionStart = input.selectionEnd = pos + emoji.length;
    input.focus();
    autoResizeChat(input);
}

function autoResizeChat(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 100) + 'px';
}

function searchUsers(query) {
    if (!query || query.length < 2) {
        document.getElementById('user-search-results').innerHTML = '';
        return;
    }
    fetch(`/api/search-users?q=${encodeURIComponent(query)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.users) {
                document.getElementById('user-search-results').innerHTML = data.users.map(u => `
                    <div class="user-search-item" onclick="openDialog(${u.id},'${escapeHtml(u.username)}');document.getElementById('message-search').value='';">
                        <div class="user-search-avatar">👤</div>
                        <div style="flex:1;min-width:0"><div style="font-weight:600;color:var(--text);font-size:12px">${escapeHtml(u.username)}</div></div>
                    </div>`).join('');
            }
        }).catch(e => console.error(e));
}

// Проверка непрочитанных для значков
async function checkUnreadMessages() {
    try {
        const res = await fetch('/api/messages');
        const data = await res.json();
        if (data.success && data.messages) {
            const total = data.messages.reduce((s, m) => s + (m.unread_count||0), 0);
            updateChatBadge(total);
        }
    } catch(e) {}
}

function selectMessageUser(userId, username) {
    openDialog(userId, username);
}

// Функции для топа недели
let topWeekOffset = 0;
let topWeekItemCount = 0;
const TOP_WEEK_VISIBLE = 4; // видимых карточек

async function loadTopWeek() {
    try {
        const res = await fetch('/api/top-week');
        const data = await res.json();
        if (data.success && data.items && data.items.length > 0) {
            const section = document.getElementById('top-week-section');
            const track = document.getElementById('top-week-track');
            if (!section || !track) return;
            
            topWeekItemCount = data.items.length;
            document.getElementById('top-week-count').textContent = topWeekItemCount;
            section.style.display = 'block';
            
            track.innerHTML = data.items.map((m, i) => `
                <a href="/read/${m.id}" style="flex:0 0 158px;text-decoration:none;color:var(--text);transition:transform 0.2s" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                    <div style="position:relative;border-radius:8px;overflow:hidden;margin-bottom:7px;box-shadow:0 4px 16px rgba(0,0,0,0.4)">
                        ${i===0?'<div style="position:absolute;top:0;left:0;right:0;bottom:0;border-radius:8px;border:2px solid var(--accent);z-index:2;pointer-events:none"></div>':''}
                        <div style="position:absolute;top:7px;left:7px;background:${i===0?'var(--accent)':i===1?'rgba(156,163,175,0.9)':i===2?'rgba(180,83,9,0.9)':'rgba(0,0,0,0.65)'};color:#fff;font-weight:800;width:22px;height:22px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:11px;z-index:10;box-shadow:0 2px 8px rgba(0,0,0,0.4)">${i+1}</div>
                        ${m.cover_display ? `<img src="${escapeHtml(m.cover_display)}" style="width:100%;height:210px;object-fit:cover;display:block;background:var(--border)" alt="">` : '<div style="width:100%;height:210px;background:var(--card2);display:flex;align-items:center;justify-content:center;font-size:32px">📖</div>'}
                    </div>
                    <div style="font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;color:var(--text2);margin-bottom:3px">${escapeHtml(m.title)}</div>
                    <div style="font-size:10px;color:var(--muted)">👁 ${m.weekly_views||0} · ♥ ${m.likes||0}</div>
                </a>
            `).join('');
            topWeekOffset = 0;
            updateTopWeekArrows();
        }
    } catch (e) {
        console.error('Top week error:', e);
    }
}

function topWeekSlide(dir) {
    const cardWidth = 170; // 160px + 10px gap
    const maxOffset = Math.max(0, topWeekItemCount - TOP_WEEK_VISIBLE);
    topWeekOffset = Math.max(0, Math.min(maxOffset, topWeekOffset + dir));
    const track = document.getElementById('top-week-track');
    if (track) track.style.transform = `translateX(-${topWeekOffset * cardWidth}px)`;
    updateTopWeekArrows();
}

function updateTopWeekArrows() {
    const left = document.getElementById('tw-left');
    const right = document.getElementById('tw-right');
    if (left) left.style.opacity = topWeekOffset > 0 ? '1' : '0.3';
    if (right) right.style.opacity = topWeekOffset < Math.max(0, topWeekItemCount - TOP_WEEK_VISIBLE) ? '1' : '0.3';
}

// Touch swipe for top week
(function(){
    let startX = 0;
    document.addEventListener('touchstart', e => {
        if (e.target.closest('#top-week-track')) startX = e.touches[0].clientX;
    }, {passive:true});
    document.addEventListener('touchend', e => {
        if (!e.target.closest('#top-week-section')) return;
        const diff = startX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) topWeekSlide(diff > 0 ? 1 : -1);
    }, {passive:true});
})();

// Функции для уровня пользователя
async function loadUserLevel(accountId) {
    try {
        const res = await fetch(`/api/user-level/${accountId}`);
        const data = await res.json();
        if (data.success) {
            const container = document.querySelector('[data-level-container]');
            if (container) {
                container.innerHTML = `
                    <div style="background:linear-gradient(135deg,#7c5cff 0%,#5a4ca0 100%);border-radius:10px;padding:12px;color:#fff;margin:12px 0">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div style="font-size:24px;font-weight:800">⭐ ${data.level}</div>
                            <div style="flex:1">
                                <div style="font-size:11px;opacity:0.9;margin-bottom:4px">Уровень пользователя</div>
                                <div style="height:4px;background:rgba(255,255,255,0.2);border-radius:2px;overflow:hidden">
                                    <div style="height:100%;background:rgba(255,255,255,0.8);width:${data.progress}%;transition:width 0.3s"></div>
                                </div>
                                <div style="font-size:9px;margin-top:3px;opacity:0.85">${data.current_xp}/${data.xp_for_level} XP</div>
                            </div>
                        </div>
                    </div>
                `;
            }
        }
    } catch (e) {
        console.error(e);
    }
}

// Event слушатели
document.addEventListener('DOMContentLoaded', function() {
    // Топ недели на главной
    if (document.getElementById('top-week-section')) {
        loadTopWeek();
        setInterval(loadTopWeek, 30 * 60 * 1000);
    }
    
    // Комментарии на странице чтения
    if (document.getElementById('comments-section')) {
        const mangaId = new URLSearchParams(location.search).get('id') || location.pathname.split('/').pop();
        loadComments(mangaId);
    }
    
    // Уровень в профиле
    const accountId = new URLSearchParams(location.search).get('id');
    if (accountId && document.querySelector('[data-level-container]')) {
        loadUserLevel(accountId);
    }
    
    // Проверка непрочитанных сообщений каждые 15 сек
    checkUnreadMessages();
    setInterval(checkUnreadMessages, 15000);
    
    // Auto-open chat if redirected from profile page "Write message"
    const pendingChat = sessionStorage.getItem('openChatWith');
    if (pendingChat) {
        try {
            const chatData = JSON.parse(pendingChat);
            sessionStorage.removeItem('openChatWith');
            setTimeout(() => {
                openMessagesModal();
                setTimeout(() => openDialog(chatData.id, chatData.username), 200);
            }, 300);
        } catch(e) {}
    }
});

// Закрывать модаль при клике снаружи
document.addEventListener('click', function(e) {
    const modal = document.getElementById('messages-modal');
    if (modal && modal.style.display !== 'none' && !e.target.closest('#messages-modal') && !e.target.closest('#messages-btn') && !e.target.closest('.sidebar-icon-btn')) {
        closeMessagesModal();
    }
    // Закрывать emoji picker
    if (!e.target.closest('#emoji-picker') && !e.target.closest('[onclick*="toggleEmojiPicker"]')) {
        const picker = document.getElementById('emoji-picker');
        if (picker) picker.style.display = 'none';
    }
});

</script>
<!-- nsfw_modal inline (no external file needed) -->

<!-- ===== СООБЩЕНИЯ МОДАЛЬ (ПОЛНЫЙ ЧАТ) ===== -->
<div id="messages-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:500;backdrop-filter:blur(8px);animation:fadeIn 0.2s">
    <div style="position:absolute;right:0;top:0;bottom:0;width:100%;max-width:480px;background:var(--bg2);border-left:1px solid var(--border);display:flex;flex-direction:column;animation:slideInRight 0.3s cubic-bezier(0.4,0,0.2,1)">

        <!-- ШАПКА ЧАТ-ПАНЕЛИ -->
        <div id="chat-header" style="display:flex;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid var(--border);flex-shrink:0;background:rgba(12,12,12,0.6);backdrop-filter:blur(12px)">
            <div id="chat-back-btn" onclick="showChatList()" style="display:none;width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:14px;cursor:pointer;display:none;align-items:center;justify-content:center;flex-shrink:0" title="Назад">‹</div>
            <div id="chat-header-avatar" style="width:32px;height:32px;border-radius:50%;background:var(--card2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0">💬</div>
            <div style="flex:1;min-width:0">
                <div id="chat-header-name" style="font-weight:700;font-size:14px;font-family:'Syne',sans-serif;letter-spacing:0.3px">Сообщения</div>
                <div id="chat-header-sub" style="font-size:10px;color:var(--muted)">Личные сообщения</div>
            </div>
            <button onclick="closeMessagesModal()" style="background:transparent;border:1px solid var(--border);border-radius:7px;color:var(--muted);font-size:13px;cursor:pointer;width:28px;height:28px;display:flex;align-items:center;justify-content:center;transition:all .18s" onmouseover="this.style.color='var(--red)';this.style.borderColor='var(--red)'" onmouseout="this.style.color='var(--muted)';this.style.borderColor='var(--border)'">✕</button>
        </div>

        <!-- СПИСОК ДИАЛОГОВ -->
        <div id="chat-list-view" style="flex:1;display:flex;flex-direction:column;overflow:hidden">
            <!-- Поиск пользователей -->
            <div style="padding:10px 12px;border-bottom:1px solid var(--border);flex-shrink:0">
                <div style="position:relative">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12px;pointer-events:none">🔍</span>
                    <input id="message-search" type="text" placeholder="Найти пользователя..." oninput="searchUsers(this.value)"
                        style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:9px;padding:8px 10px 8px 30px;color:var(--text);font-size:12px;outline:none;font-family:'Inter',sans-serif;transition:border-color .18s"
                        onfocus="this.style.borderColor='var(--border2)'" onblur="this.style.borderColor='var(--border)'">
                </div>
                <div id="user-search-results" style="margin-top:6px;max-height:130px;overflow-y:auto;border-radius:8px;overflow:hidden"></div>
            </div>
            <!-- Список чатов -->
            <div id="messages-list" style="flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent"></div>
        </div>

        <!-- ДИАЛОГ (ПЕРЕПИСКА) -->
        <div id="chat-dialog-view" style="flex:1;display:none;flex-direction:column;overflow:hidden">
            <!-- Сообщения -->
            <div id="chat-messages-area" style="flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:6px;scrollbar-width:thin;scrollbar-color:var(--border) transparent"></div>

            <!-- ПАНЕЛЬ ВВОДА -->
            <div style="padding:10px 12px;border-top:1px solid var(--border);flex-shrink:0;background:rgba(12,12,12,0.4)">
                <!-- Emoji picker -->
                <div id="emoji-picker" style="display:none;padding:8px;border:1px solid var(--border);border-radius:10px;background:var(--card);margin-bottom:8px;max-height:120px;overflow-y:auto">
                    <div style="display:flex;flex-wrap:wrap;gap:4px" id="emoji-grid"></div>
                </div>
                <div style="display:flex;gap:7px;align-items:flex-end">
                    <button onclick="toggleEmojiPicker()" title="Смайлики"
                        style="width:34px;height:34px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:16px;cursor:pointer;flex-shrink:0;transition:all .18s;display:flex;align-items:center;justify-content:center"
                        onmouseover="this.style.borderColor='var(--border2)';this.style.color='var(--text)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">😊</button>
                    <textarea id="chat-input" placeholder="Напиши сообщение..." rows="1"
                        style="flex:1;background:var(--card);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Inter',sans-serif;font-size:13px;padding:9px 12px;outline:none;resize:none;min-height:36px;max-height:100px;line-height:1.45;transition:border-color .18s;scrollbar-width:none"
                        onfocus="this.style.borderColor='var(--border2)'" onblur="this.style.borderColor='var(--border)'"
                        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChatMessage();}" oninput="autoResizeChat(this)"></textarea>
                    <button onclick="sendChatMessage()" title="Отправить"
                        style="width:34px;height:34px;border-radius:8px;border:none;background:var(--text);color:var(--bg);font-size:15px;cursor:pointer;flex-shrink:0;transition:all .18s;display:flex;align-items:center;justify-content:center"
                        onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">➤</button>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
@keyframes fadeIn { from { opacity: 0 } to { opacity: 1 } }
@keyframes slideInRight { from { transform: translateX(100%) } to { transform: translateX(0) } }
.message-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid var(--border); transition: background 0.15s; display: flex; gap: 10px; align-items: center; }
.message-item:hover { background: rgba(255,255,255,0.04) }
.message-item.unread { background: rgba(255,255,255,0.03); }
.message-item.active { background: rgba(255,255,255,0.06); }
.message-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--card2); border:1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0 }
.message-content { flex: 1; min-width: 0 }
.message-username { font-weight: 600; font-size: 12px; color: var(--text); margin-bottom: 2px }
.message-preview { font-size: 11px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.user-search-item { padding: 9px 10px; cursor: pointer; border-bottom: 1px solid var(--border); font-size: 12px; display: flex; gap: 8px; align-items: center; transition: background 0.15s; }
.user-search-item:hover { background: rgba(255,255,255,0.05) }
.user-search-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--card2); display: flex; align-items: center; justify-content: center; font-size: 12px; border:1px solid var(--border); }
/* Пузыри сообщений */
.chat-bubble { max-width: 78%; padding: 8px 12px; border-radius: 12px; font-size: 12px; line-height: 1.5; word-break: break-word; position: relative; }
.chat-bubble.mine { background: var(--card2); border: 1px solid var(--border2); color: var(--text); border-radius: 12px 12px 3px 12px; align-self: flex-end; }
.chat-bubble.theirs { background: var(--card); border: 1px solid var(--border); color: var(--text2); border-radius: 12px 12px 12px 3px; align-self: flex-start; }
.chat-bubble-wrap { display: flex; flex-direction: column; }
.chat-bubble-wrap.mine { align-items: flex-end; }
.chat-bubble-wrap.theirs { align-items: flex-start; }
.chat-time { font-size: 9px; color: var(--muted); margin-top: 3px; padding: 0 2px; }
/* Unread dot */
.unread-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--red); flex-shrink: 0; animation: pulse-red 2s infinite; }
/* Empty state */
.chat-empty { text-align:center; padding: 40px 20px; color: var(--muted); font-size: 12px; }
/* Новое сообщение мигает */
@keyframes msgPop { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
.chat-bubble { animation: msgPop 0.18s ease; }
/* Кнопка чата мигает при новом сообщении */
@keyframes chatGlow { 0%,100%{box-shadow:0 0 0 0 rgba(248,113,113,0.5)} 50%{box-shadow:0 0 0 6px rgba(248,113,113,0)} }
.sidebar-icon-btn.has-unread { animation: chatGlow 2s infinite; border-color: rgba(248,113,113,0.5); color: var(--red); }
</style>


<!-- ===== КОММЕНТАРИИ ===== -->
<div id="comments-section" style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin:20px 0;display:none">
    <div style="font-size:14px;font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:6px">
        💬 Комментарии <span style="background:rgba(255,255,255,0.08);color:var(--muted);border-radius:20px;padding:2px 8px;font-size:11px;font-weight:600" id="comments-count">(0)</span>
    </div>
    <div id="comments-form" style="margin-bottom:16px;display:<?php echo isset($currentAccount) && $currentAccount ? 'block' : 'none'; ?>">
        <textarea id="comment-input" placeholder="Поделитесь мнением о манге..." style="width:100%;background:var(--card2);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;font-family:Outfit,sans-serif;font-size:13px;resize:none;outline:none;min-height:70px;max-height:150px;line-height:1.4"></textarea>
        <button onclick="submitComment()" style="margin-top:8px;padding:8px 16px;background:var(--accent);border:none;border-radius:6px;color:#fff;cursor:pointer;font-weight:600;font-size:13px;transition:all 0.2s" onmouseover="this.style.transform='translateY(-1px)';this.style.opacity='0.9'" onmouseout="this.style.transform='translateY(0)';this.style.opacity='1'">
            📤 Отправить комментарий
        </button>
    </div>
    <div id="comments-list" style="max-height:500px;overflow-y:auto;display:flex;flex-direction:column;gap:8px"></div>
    <div id="comments-empty" style="text-align:center;color:var(--muted);font-size:12px;padding:20px 0">Комментариев еще нет. Будьте первым!</div>
</div>

</body>
</html>