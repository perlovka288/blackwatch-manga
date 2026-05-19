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
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_comments (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, account_id INT NOT NULL, text TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_messages (id SERIAL PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL, text TEXT NOT NULL, is_read BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_weekly_stats (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, week_start TIMESTAMP NOT NULL, views INT DEFAULT 0, likes INT DEFAULT 0, comments INT DEFAULT 0, score INT DEFAULT 0, UNIQUE(manga_id, week_start))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tags (id SERIAL PRIMARY KEY, name TEXT NOT NULL UNIQUE, slug VARCHAR(100) NOT NULL UNIQUE, is_nsfw BOOLEAN DEFAULT FALSE, manga_count INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS genres (id SERIAL PRIMARY KEY, name TEXT NOT NULL UNIQUE, slug VARCHAR(100) NOT NULL UNIQUE, manga_count INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_tags (manga_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (manga_id, tag_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_genres (manga_id INT NOT NULL, genre_id INT NOT NULL, PRIMARY KEY (manga_id, genre_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (id SERIAL PRIMARY KEY, email TEXT NOT NULL, code VARCHAR(6) NOT NULL, expires_at TIMESTAMP NOT NULL, used BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_customizations (account_id INT PRIMARY KEY, avatar_url TEXT DEFAULT NULL, banner_url TEXT DEFAULT NULL, banner_color VARCHAR(20) DEFAULT '#1a1a2e', bio TEXT DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS friendships (id SERIAL PRIMARY KEY, requester_id INT NOT NULL, addressee_id INT NOT NULL, status VARCHAR(20) DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(requester_id, addressee_id))");
    
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS is_verified BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS verify_code VARCHAR(6) DEFAULT NULL");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS verify_expires TIMESTAMP DEFAULT NULL");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS profile_privacy VARCHAR(20) DEFAULT 'public'");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS is_admin BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS admin_tag VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS user_xp INT DEFAULT 0");
    $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS user_level INT DEFAULT 1");
    $pdo->exec("ALTER TABLE user_manga_status ADD COLUMN IF NOT EXISTS account_id INT DEFAULT NULL");
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_comments_manga ON manga_comments(manga_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_comments_account ON manga_comments(account_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_recipient ON user_messages(recipient_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_sender ON user_messages(sender_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_pair ON user_messages(sender_id, recipient_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_weekly_stats ON manga_weekly_stats(week_start, score)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ums_account_id ON user_manga_status(account_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_tags_manga ON manga_tags(manga_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manga_genres_manga ON manga_genres(manga_id)");
    
    try {
        $tagCount = (int)$pdo->query("SELECT COUNT(*) FROM tags")->fetchColumn();
        if ($tagCount === 0) {
            $tagsJson = '[{"id":1,"name":"Реинкарнация","slug":"reincarnation","is_nsfw":false},{"id":2,"name":"Перерождение","slug":"rebirth","is_nsfw":false},{"id":3,"name":"Система","slug":"system","is_nsfw":false},{"id":4,"name":"Подземелья","slug":"dungeons","is_nsfw":false},{"id":5,"name":"Некромант","slug":"necromancer","is_nsfw":false},{"id":6,"name":"Культивация","slug":"cultivation","is_nsfw":false},{"id":7,"name":"Монстродевушки","slug":"monster-girls","is_nsfw":false},{"id":8,"name":"Цундере","slug":"tsundere","is_nsfw":false},{"id":9,"name":"Яндере","slug":"yandere","is_nsfw":false},{"id":10,"name":"Путешествие во времени","slug":"time-travel","is_nsfw":false},{"id":11,"name":"Боги","slug":"gods","is_nsfw":false},{"id":12,"name":"Зомби","slug":"zombies","is_nsfw":false},{"id":13,"name":"Школьная жизнь","slug":"school-life","is_nsfw":false},{"id":14,"name":"Ассасины","slug":"assassins","is_nsfw":false},{"id":15,"name":"Мафия","slug":"mafia","is_nsfw":false},{"id":16,"name":"Виртуальная реальность","slug":"vr","is_nsfw":false},{"id":17,"name":"Игровой мир","slug":"game-world","is_nsfw":false},{"id":18,"name":"Постапокалипсис","slug":"apocalypse","is_nsfw":false},{"id":19,"name":"Игра на выживание","slug":"survival-game","is_nsfw":false},{"id":20,"name":"Кулинария","slug":"cooking","is_nsfw":false},{"id":21,"name":"Драконы","slug":"dragons","is_nsfw":false},{"id":22,"name":"Зверолюди","slug":"beast-people","is_nsfw":false},{"id":23,"name":"Эльфы","slug":"elves","is_nsfw":false},{"id":24,"name":"Тёмное фэнтези","slug":"dark-fantasy","is_nsfw":false},{"id":25,"name":"Герой","slug":"hero","is_nsfw":false},{"id":26,"name":"Злодейка","slug":"villainess","is_nsfw":false},{"id":27,"name":"Строительство королевства","slug":"kingdom-building","is_nsfw":false},{"id":28,"name":"Регрессия","slug":"regression","is_nsfw":false},{"id":29,"name":"Охотники","slug":"hunters","is_nsfw":false},{"id":30,"name":"Гениальный ГГ","slug":"genius-mc","is_nsfw":false},{"id":31,"name":"Антигерой","slug":"antihero","is_nsfw":false},{"id":32,"name":"Ниндзя","slug":"ninja","is_nsfw":false},{"id":33,"name":"Пираты","slug":"pirates","is_nsfw":false},{"id":34,"name":"Космос","slug":"space","is_nsfw":false},{"id":35,"name":"Месть","slug":"revenge","is_nsfw":false},{"id":36,"name":"Турнир","slug":"tournament","is_nsfw":false},{"id":37,"name":"Сильный ГГ","slug":"op-mc","is_nsfw":false},{"id":38,"name":"Слабый в Сильный","slug":"weak-to-strong","is_nsfw":false},{"id":39,"name":"Магическая академия","slug":"magic-academy","is_nsfw":false},{"id":40,"name":"РПГ","slug":"rpg","is_nsfw":false},{"id":41,"name":"MMORPG","slug":"mmorpg","is_nsfw":false},{"id":42,"name":"Гильдии","slug":"guilds","is_nsfw":false},{"id":43,"name":"Любовный треугольник","slug":"love-triangle","is_nsfw":false},{"id":44,"name":"Холодный ГГ","slug":"cold-mc","is_nsfw":false},{"id":45,"name":"Легендарное оружие","slug":"legendary-weapon","is_nsfw":false},{"id":46,"name":"Проклятия","slug":"curses","is_nsfw":false},{"id":47,"name":"Короли","slug":"kings","is_nsfw":false},{"id":48,"name":"Академия","slug":"academy","is_nsfw":false},{"id":49,"name":"Гендер-бендер","slug":"gender-bender","is_nsfw":false},{"id":50,"name":"Суперсилы","slug":"superpowers","is_nsfw":false},{"id":51,"name":"Телепортация","slug":"teleportation","is_nsfw":false},{"id":52,"name":"Взрослый контент","slug":"adult","is_nsfw":true},{"id":53,"name":"18+","slug":"18plus","is_nsfw":true},{"id":54,"name":"NSFW","slug":"nsfw","is_nsfw":true}]';
            $tags = json_decode($tagsJson, true);
            $tagInsert = $pdo->prepare("INSERT INTO tags (name, slug, is_nsfw) VALUES (?, ?, ?) ON CONFLICT DO NOTHING");
            foreach ($tags as $t) { $tagInsert->execute([$t['name'], $t['slug'], $t['is_nsfw'] ? 1 : 0]); }
        }
    } catch(Exception $e) {}
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

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$stmtAdmins = $pdo->query("SELECT user_id FROM bot_admins");
$admins = $stmtAdmins ? array_map(fn($r) => (int)$r['user_id'], $stmtAdmins->fetchAll()) : [];

try {
    $accAdmStmt = $pdo->query("SELECT tg_user_id FROM accounts WHERE is_admin=TRUE AND tg_user_id IS NOT NULL");
    if ($accAdmStmt) {
        foreach ($accAdmStmt->fetchAll() as $row) {
            if ($row['tg_user_id']) $admins[] = (int)$row['tg_user_id'];
        }
    }
} catch(Exception $e) {}

function isAdmin($userId, $admins) { return $userId > 0 && in_array((int)$userId, $admins); }

function isAdminFull($pdo, $userId, $admins) {
    if (isAdmin($userId, $admins)) return true;
    if (isset($_SESSION['account_id'])) {
        try {
            $s = $pdo->prepare("SELECT is_admin FROM accounts WHERE id=? AND is_admin=TRUE");
            $s->execute([$_SESSION['account_id']]);
            if (!empty($s->fetch())) return true;
        } catch(Exception $e) {}
    }
    return false;
}

function getTgUserFromRequest() {
    $u = $_GET['tg_user_id'] ?? $_POST['tg_user_id'] ?? null;
    return $u ? (int)$u : 0;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'POST' && $path === '/api/admin/manga') {
    $id = (int)($_GET['id'] ?? 0);
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("UPDATE manga SET title=?, description=?, telegraph_url=?, cover_imgbb_url=? WHERE id=?");
        $stmt->execute([$data['title'] ?? '', $data['description'] ?? '', $data['telegraph_url'] ?? '', $data['cover_imgbb_url'] ?? '', $id]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/api/admin/manga/add') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("INSERT INTO manga (title, description, telegraph_url, cover_imgbb_url, added_by, created_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id");
        $stmt->execute([$data['title'] ?? 'Новая манга', $data['description'] ?? '', $data['telegraph_url'] ?? '', $data['cover_imgbb_url'] ?? '', $tgU]);
        $id = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'id' => $id]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/admin/manga/(\d+)/delete$#', $path, $m)) {
    $id = (int)$m[1];
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    try {
        $pdo->exec("DELETE FROM manga_comments WHERE manga_id=$id");
        $pdo->exec("DELETE FROM manga_genres WHERE manga_id=$id");
        $pdo->exec("DELETE FROM manga_tags WHERE manga_id=$id");
        $pdo->exec("DELETE FROM manga_chapter_pages WHERE chapter_id IN (SELECT id FROM manga_chapters WHERE manga_id=$id)");
        $pdo->exec("DELETE FROM manga_chapters WHERE manga_id=$id");
        $pdo->exec("DELETE FROM manga_pages WHERE manga_id=$id");
        $pdo->exec("DELETE FROM manga WHERE id=$id");
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/admin/stats') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $mCount = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $uCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $lCount = $pdo->query("SELECT COUNT(*) FROM manga_comments")->fetchColumn();
        $aCount = $pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();
        echo json_encode(['manga' => (int)$mCount, 'users' => (int)$uCount, 'likes' => (int)$lCount, 'actions' => (int)$aCount]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/admin/manga-list') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $q = $_GET['q'] ?? '';
    $page = (int)($_GET['page'] ?? 0);
    $limit = 10;
    $offset = $page * $limit;
    
    try {
        $where = $q ? "WHERE title ILIKE ?" : "";
        $stmt = $pdo->prepare("SELECT id, title, cover_imgbb_url, created_at FROM manga $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $q ? $stmt->execute(["%$q%", $limit, $offset]) : $stmt->execute([$limit, $offset]);
        $items = $stmt->fetchAll();
        
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM manga $where");
        $q ? $countStmt->execute(["%$q%"]) : $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();
        
        echo json_encode(['items' => $items, 'total' => $total, 'page' => $page]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/admin/manga/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id=?");
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        
        $chStmt = $pdo->prepare("SELECT * FROM manga_chapters WHERE manga_id=? ORDER BY chapter_num");
        $chStmt->execute([$id]);
        $chapters = $chStmt->fetchAll();
        
        $gStmt = $pdo->prepare("SELECT g.id FROM genres g JOIN manga_genres mg ON g.id=mg.genre_id WHERE mg.manga_id=?");
        $gStmt->execute([$id]);
        $genres = array_map(fn($r) => $r['id'], $gStmt->fetchAll());
        
        $tStmt = $pdo->prepare("SELECT t.id FROM tags t JOIN manga_tags mt ON t.id=mt.tag_id WHERE mt.manga_id=?");
        $tStmt->execute([$id]);
        $tags = array_map(fn($r) => $r['id'], $tStmt->fetchAll());
        
        echo json_encode(['manga' => $m, 'chapters' => $chapters, 'genres' => $genres, 'tags' => $tags]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/admin/manga/(\d+)/genres$#', $path, $m)) {
    $id = (int)$m[1];
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $pdo->prepare("DELETE FROM manga_genres WHERE manga_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM manga_tags WHERE manga_id=?")->execute([$id]);
        
        foreach ($data['genre_ids'] ?? [] as $gid) {
            $pdo->prepare("INSERT INTO manga_genres (manga_id, genre_id) VALUES (?, ?)")->execute([$id, $gid]);
        }
        foreach ($data['tag_ids'] ?? [] as $tid) {
            $pdo->prepare("INSERT INTO manga_tags (manga_id, tag_id) VALUES (?, ?)")->execute([$id, $tid]);
        }
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/admin/chapter/(\d+)/delete$#', $path, $m)) {
    $chId = (int)$m[1];
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id=?")->execute([$chId]);
        $pdo->prepare("DELETE FROM manga_chapters WHERE id=?")->execute([$chId]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/admin/tags') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $tags = $pdo->query("SELECT * FROM tags ORDER BY name")->fetchAll();
        $genres = $pdo->query("SELECT * FROM genres ORDER BY name")->fetchAll();
        echo json_encode(['tags' => $tags, 'genres' => $genres]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/api/admin/tags/add') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("INSERT INTO tags (name, slug, is_nsfw) VALUES (?, ?, ?) RETURNING id");
        $stmt->execute([$data['name'], $data['slug'], $data['is_nsfw'] ? 1 : 0]);
        echo json_encode(['success' => true, 'id' => $stmt->fetchColumn()]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Тег уже существует']);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/admin/tags/(\d+)/delete$#', $path, $m)) {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $pdo->prepare("DELETE FROM manga_tags WHERE tag_id=?")->execute([(int)$m[1]]);
        $pdo->prepare("DELETE FROM tags WHERE id=?")->execute([(int)$m[1]]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($method === 'POST' && $path === '/api/admin/genres/add') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("INSERT INTO genres (name, slug) VALUES (?, ?) RETURNING id");
        $stmt->execute([$data['name'], $data['slug']]);
        echo json_encode(['success' => true, 'id' => $stmt->fetchColumn()]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Жанр уже существует']);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/admin/genres/(\d+)/delete$#', $path, $m)) {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $pdo->prepare("DELETE FROM manga_genres WHERE genre_id=?")->execute([(int)$m[1]]);
        $pdo->prepare("DELETE FROM genres WHERE id=?")->execute([(int)$m[1]]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($method === 'POST' && $path === '/api/admin/reseed-tags') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $tagsJson = '[{"id":1,"name":"Реинкарнация","slug":"reincarnation","is_nsfw":false},{"id":2,"name":"Перерождение","slug":"rebirth","is_nsfw":false},{"id":3,"name":"Система","slug":"system","is_nsfw":false},{"id":4,"name":"Подземелья","slug":"dungeons","is_nsfw":false},{"id":5,"name":"Некромант","slug":"necromancer","is_nsfw":false},{"id":6,"name":"Культивация","slug":"cultivation","is_nsfw":false},{"id":7,"name":"Монстродевушки","slug":"monster-girls","is_nsfw":false},{"id":8,"name":"Цундере","slug":"tsundere","is_nsfw":false},{"id":9,"name":"Яндере","slug":"yandere","is_nsfw":false},{"id":10,"name":"Путешествие во времени","slug":"time-travel","is_nsfw":false},{"id":11,"name":"Боги","slug":"gods","is_nsfw":false},{"id":12,"name":"Зомби","slug":"zombies","is_nsfw":false},{"id":13,"name":"Школьная жизнь","slug":"school-life","is_nsfw":false},{"id":14,"name":"Ассасины","slug":"assassins","is_nsfw":false},{"id":15,"name":"Мафия","slug":"mafia","is_nsfw":false},{"id":16,"name":"Виртуальная реальность","slug":"vr","is_nsfw":false},{"id":17,"name":"Игровой мир","slug":"game-world","is_nsfw":false},{"id":18,"name":"Постапокалипсис","slug":"apocalypse","is_nsfw":false},{"id":19,"name":"Игра на выживание","slug":"survival-game","is_nsfw":false},{"id":20,"name":"Кулинария","slug":"cooking","is_nsfw":false},{"id":21,"name":"Драконы","slug":"dragons","is_nsfw":false},{"id":22,"name":"Зверолюди","slug":"beast-people","is_nsfw":false},{"id":23,"name":"Эльфы","slug":"elves","is_nsfw":false},{"id":24,"name":"Тёмное фэнтези","slug":"dark-fantasy","is_nsfw":false},{"id":25,"name":"Герой","slug":"hero","is_nsfw":false},{"id":26,"name":"Злодейка","slug":"villainess","is_nsfw":false},{"id":27,"name":"Строительство королевства","slug":"kingdom-building","is_nsfw":false},{"id":28,"name":"Регрессия","slug":"regression","is_nsfw":false},{"id":29,"name":"Охотники","slug":"hunters","is_nsfw":false},{"id":30,"name":"Гениальный ГГ","slug":"genius-mc","is_nsfw":false},{"id":31,"name":"Антигерой","slug":"antihero","is_nsfw":false},{"id":32,"name":"Ниндзя","slug":"ninja","is_nsfw":false},{"id":33,"name":"Пираты","slug":"pirates","is_nsfw":false},{"id":34,"name":"Космос","slug":"space","is_nsfw":false},{"id":35,"name":"Месть","slug":"revenge","is_nsfw":false},{"id":36,"name":"Турнир","slug":"tournament","is_nsfw":false},{"id":37,"name":"Сильный ГГ","slug":"op-mc","is_nsfw":false},{"id":38,"name":"Слабый в Сильный","slug":"weak-to-strong","is_nsfw":false},{"id":39,"name":"Магическая академия","slug":"magic-academy","is_nsfw":false},{"id":40,"name":"РПГ","slug":"rpg","is_nsfw":false},{"id":41,"name":"MMORPG","slug":"mmorpg","is_nsfw":false},{"id":42,"name":"Гильдии","slug":"guilds","is_nsfw":false},{"id":43,"name":"Любовный треугольник","slug":"love-triangle","is_nsfw":false},{"id":44,"name":"Холодный ГГ","slug":"cold-mc","is_nsfw":false},{"id":45,"name":"Легендарное оружие","slug":"legendary-weapon","is_nsfw":false},{"id":46,"name":"Проклятия","slug":"curses","is_nsfw":false},{"id":47,"name":"Короли","slug":"kings","is_nsfw":false},{"id":48,"name":"Академия","slug":"academy","is_nsfw":false},{"id":49,"name":"Гендер-бендер","slug":"gender-bender","is_nsfw":false},{"id":50,"name":"Суперсилы","slug":"superpowers","is_nsfw":false},{"id":51,"name":"Телепортация","slug":"teleportation","is_nsfw":false},{"id":52,"name":"Взрослый контент","slug":"adult","is_nsfw":true},{"id":53,"name":"18+","slug":"18plus","is_nsfw":true},{"id":54,"name":"NSFW","slug":"nsfw","is_nsfw":true}]';
        $tags = json_decode($tagsJson, true);
        $tagInsert = $pdo->prepare("INSERT INTO tags (name, slug, is_nsfw) VALUES (?, ?, ?) ON CONFLICT DO NOTHING");
        foreach ($tags as $t) { $tagInsert->execute([$t['name'], $t['slug'], $t['is_nsfw'] ? 1 : 0]); }
        
        $defaultGenres = [
            ['Экшен','action'],['Романтика','romance'],['Фэнтези','fantasy'],['Комедия','comedy'],
            ['Драма','drama'],['Ужасы','horror'],['Мистика','mystery'],['Приключения','adventure'],
            ['Боевые искусства','martial-arts'],['Психология','psychology'],['Сёнен','shounen'],
            ['Сёдзё','shoujo'],['Сейнен','seinen'],['Иссекай','isekai'],['Спорт','sports'],
        ];
        $gInsert = $pdo->prepare("INSERT INTO genres (name, slug) VALUES (?, ?) ON CONFLICT DO NOTHING");
        foreach ($defaultGenres as $g) { $gInsert->execute($g); }
        
        $tCount = count($tags);
        $gCount = count($defaultGenres);
        echo json_encode(['success' => true, 'tags' => $tCount, 'genres' => $gCount]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/admin/messages') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $msgs = $pdo->query("SELECT * FROM admin_messages WHERE is_deleted=FALSE ORDER BY created_at DESC LIMIT 50")->fetchAll();
        echo json_encode(['messages' => $msgs]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/api/admin/messages/send') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_messages (text, sent_by, created_at) VALUES (?, ?, NOW()) RETURNING id");
        $stmt->execute([$data['text'], $tgU]);
        echo json_encode(['success' => true, 'id' => $stmt->fetchColumn()]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/admin/archive') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $page = (int)($_GET['page'] ?? 0);
    $limit = 20;
    $offset = $page * $limit;
    
    try {
        $items = $pdo->query("SELECT * FROM bot_archive ORDER BY created_at DESC LIMIT $limit OFFSET $offset")->fetchAll();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();
        echo json_encode(['items' => $items, 'total' => $total, 'page' => $page]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/admin/suggestions') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $page = (int)($_GET['page'] ?? 0);
    $limit = 15;
    $offset = $page * $limit;
    
    try {
        $items = $pdo->query("SELECT * FROM suggestions WHERE status='new' ORDER BY created_at DESC LIMIT $limit OFFSET $offset")->fetchAll();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM suggestions WHERE status='new'")->fetchColumn();
        echo json_encode(['items' => $items, 'total' => $total, 'page' => $page]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/admin/suggestions/(\d+)/status$#', $path, $m)) {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("UPDATE suggestions SET status=? WHERE id=?");
        $stmt->execute([$data['status'], (int)$m[1]]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/admin/admins') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    try {
        $botAdmins = $pdo->query("SELECT user_id FROM bot_admins")->fetchAll();
        $accAdmins = $pdo->query("SELECT id, email, username FROM accounts WHERE is_admin=TRUE")->fetchAll();
        echo json_encode(['bot_admins' => $botAdmins, 'account_admins' => $accAdmins]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/api/admin/admins/add') {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $input = $data['input'] ?? '';
    
    try {
        if (is_numeric($input)) {
            $stmt = $pdo->prepare("INSERT INTO bot_admins (user_id) VALUES (?) ON CONFLICT DO NOTHING");
            $stmt->execute([(int)$input]);
        } else {
            $stmt = $pdo->prepare("UPDATE accounts SET is_admin=TRUE WHERE email=? OR username=?");
            $stmt->execute([$input, $input]);
        }
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/admin/admins/remove$#', $path)) {
    $tgU = getTgUserFromRequest();
    if (!isAdmin($tgU, $admins)) { http_response_code(403); die(json_encode(['success' => false])); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;
    $type = $data['type'] ?? 'bot';
    
    try {
        if ($type === 'bot') {
            $pdo->prepare("DELETE FROM bot_admins WHERE user_id=?")->execute([(int)$id]);
        } else {
            $pdo->prepare("UPDATE accounts SET is_admin=FALSE WHERE id=?")->execute([(int)$id]);
        }
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($method === 'GET' && $path === '/api/check-admin') {
    $tgU = getTgUserFromRequest();
    $isAdm = isAdminFull($pdo, $tgU, $admins);
    echo json_encode(['is_admin' => $isAdm, 'user_id' => $tgU]);
    exit;
}

if ($path === '/admin') {
    $tgU = getTgUserFromRequest();
    if (!isAdminFull($pdo, $tgU, $admins)) {
        http_response_code(403);
        echo "Access Denied";
        exit;
    }
    
    ?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLACKWATCH Admin Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f0f1e 0%, #1a1a2e 100%);
            color: #e0e0e0;
            min-height: 100vh;
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background: rgba(15, 15, 30, 0.95);
            border-right: 2px solid #ff3366;
            padding: 24px 16px;
            overflow-y: auto;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 1px solid #333;
        }
        
        .sidebar-icon {
            font-size: 24px;
        }
        
        .sidebar-title {
            font-size: 16px;
            font-weight: 700;
            color: #ff3366;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .nav-items {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .nav-item {
            padding: 14px 16px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            border: 2px solid transparent;
            color: #b0b0b0;
        }
        
        .nav-item:hover {
            background: rgba(255, 51, 102, 0.1);
            color: #ff3366;
            border-color: #ff3366;
        }
        
        .nav-item.active {
            background: rgba(255, 51, 102, 0.2);
            color: #ff3366;
            border-color: #ff3366;
            box-shadow: 0 0 12px rgba(255, 51, 102, 0.3);
        }
        
        .nav-icon {
            font-size: 18px;
            min-width: 20px;
        }
        
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 32px;
        }
        
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        
        .panel-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #ff3366 0%, #ff66b2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .search-box {
            display: flex;
            gap: 8px;
        }
        
        .search-input {
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.08);
            border: 2px solid rgba(255, 51, 102, 0.3);
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 14px;
            transition: all 0.3s ease;
            min-width: 250px;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #ff3366;
            background: rgba(255, 51, 102, 0.08);
            box-shadow: 0 0 12px rgba(255, 51, 102, 0.2);
        }
        
        .search-input::placeholder {
            color: #888;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #ff3366 0%, #ff66b2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            box-shadow: 0 0 20px rgba(255, 51, 102, 0.4);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: rgba(255, 51, 102, 0.2);
            color: #ff3366;
            border: 2px solid #ff3366;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 51, 102, 0.3);
        }
        
        .btn-danger {
            background: rgba(220, 53, 69, 0.3);
            color: #ff6b7a;
            border: 2px solid #ff6b7a;
        }
        
        .btn-danger:hover {
            background: rgba(220, 53, 69, 0.5);
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: rgba(255, 51, 102, 0.08);
            border: 2px solid rgba(255, 51, 102, 0.2);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            border-color: #ff3366;
            background: rgba(255, 51, 102, 0.12);
            box-shadow: 0 0 20px rgba(255, 51, 102, 0.15);
        }
        
        .stat-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #ff3366;
        }
        
        .content-panel {
            background: rgba(15, 15, 30, 0.6);
            border: 2px solid rgba(255, 51, 102, 0.2);
            border-radius: 12px;
            padding: 24px;
            display: none;
        }
        
        .content-panel.active {
            display: block;
        }
        
        .manga-list {
            display: grid;
            gap: 12px;
        }
        
        .manga-item {
            background: rgba(255, 51, 102, 0.08);
            border: 1px solid rgba(255, 51, 102, 0.2);
            border-radius: 8px;
            padding: 16px;
            display: flex;
            gap: 16px;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .manga-item:hover {
            background: rgba(255, 51, 102, 0.12);
            border-color: #ff3366;
        }
        
        .manga-thumb {
            width: 60px;
            height: 80px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 6px;
            overflow: hidden;
        }
        
        .manga-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .manga-info {
            flex: 1;
        }
        
        .manga-name {
            font-weight: 600;
            font-size: 14px;
            color: #e0e0e0;
        }
        
        .manga-date {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-label {
            display: block;
            font-size: 12px;
            color: #aaa;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 2px solid rgba(255, 51, 102, 0.2);
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #ff3366;
            background: rgba(255, 51, 102, 0.08);
            box-shadow: 0 0 12px rgba(255, 51, 102, 0.2);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .table th {
            background: rgba(255, 51, 102, 0.1);
            padding: 12px;
            text-align: left;
            font-size: 12px;
            color: #ff3366;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(255, 51, 102, 0.3);
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid rgba(255, 51, 102, 0.1);
            font-size: 14px;
        }
        
        .table tbody tr:hover {
            background: rgba(255, 51, 102, 0.05);
        }
        
        .checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #ff3366;
        }
        
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .tag {
            background: rgba(255, 51, 102, 0.2);
            color: #ff3366;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .tag.nsfw {
            background: rgba(255, 100, 100, 0.3);
            color: #ff6b7a;
        }
        
        .pagination {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .pagination button {
            padding: 8px 12px;
            background: rgba(255, 51, 102, 0.2);
            border: 1px solid rgba(255, 51, 102, 0.4);
            border-radius: 6px;
            color: #ff3366;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .pagination button:hover, .pagination button.active {
            background: rgba(255, 51, 102, 0.4);
            border-color: #ff3366;
        }
        
        .loading {
            text-align: center;
            color: #ff3366;
            padding: 40px;
        }
        
        .spinner {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255, 51, 102, 0.3);
            border-top-color: #ff3366;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 1024px) {
            .admin-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 2px solid #ff3366;
            }
            
            .nav-items {
                flex-direction: row;
                flex-wrap: wrap;
            }
            
            .grid-2 {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                padding: 16px 12px;
            }
            
            .sidebar-title {
                font-size: 14px;
            }
            
            .main-content {
                padding: 16px;
            }
            
            .panel-title {
                font-size: 20px;
            }
            
            .search-input {
                min-width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-icon">🛡️</div>
                <div class="sidebar-title">BLACKWATCH</div>
            </div>
            <div class="nav-items">
                <div class="nav-item active" onclick="switchPanel('dashboard')">
                    <div class="nav-icon">📊</div>
                    <div>Дашборд</div>
                </div>
                <div class="nav-item" onclick="switchPanel('manga')">
                    <div class="nav-icon">📚</div>
                    <div>Манга</div>
                </div>
                <div class="nav-item" onclick="switchPanel('tags')">
                    <div class="nav-icon">🏷️</div>
                    <div>Теги & Жанры</div>
                </div>
                <div class="nav-item" onclick="switchPanel('messages')">
                    <div class="nav-icon">💬</div>
                    <div>Рассылка</div>
                </div>
                <div class="nav-item" onclick="switchPanel('suggestions')">
                    <div class="nav-icon">💡</div>
                    <div>Предложения</div>
                </div>
                <div class="nav-item" onclick="switchPanel('archive')">
                    <div class="nav-icon">📜</div>
                    <div>Журнал</div>
                </div>
                <div class="nav-item" onclick="switchPanel('admins')">
                    <div class="nav-icon">👑</div>
                    <div>Администраторы</div>
                </div>
            </div>
        </div>
        
        <div class="main-content">
            <!-- Dashboard Panel -->
            <div class="content-panel active" id="panel-dashboard">
                <div class="panel-header">
                    <div class="panel-title">📊 Дашборд</div>
                </div>
                <div class="stats-grid" id="stats-container">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
            
            <!-- Manga Panel -->
            <div class="content-panel" id="panel-manga">
                <div class="panel-header">
                    <div class="panel-title">📚 Управление мангой</div>
                    <div class="search-box">
                        <input class="search-input" id="manga-search" type="text" placeholder="🔍 Поиск по названию...">
                        <button class="btn btn-primary" onclick="addNewManga()">➕ Добавить</button>
                    </div>
                </div>
                <div class="manga-list" id="manga-list">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
                <div id="manga-pagination" class="pagination"></div>
            </div>
            
            <!-- Tags Panel -->
            <div class="content-panel" id="panel-tags">
                <div class="panel-header">
                    <div class="panel-title">🏷️ Теги & Жанры</div>
                    <button class="btn btn-primary" onclick="reseedTags()">⚡ Загрузить все</button>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <!-- Genres -->
                    <div>
                        <h3 style="margin-bottom: 16px; color: #ff3366;">Жанры</h3>
                        <div class="form-group">
                            <input class="form-input" id="new-genre-name" placeholder="Название жанра">
                        </div>
                        <div class="form-group">
                            <input class="form-input" id="new-genre-slug" placeholder="Slug (english)">
                        </div>
                        <button class="btn btn-primary" style="width: 100%; margin-bottom: 16px;" onclick="addGenre()">Добавить жанр</button>
                        <div id="genres-list" style="max-height: 400px; overflow-y: auto;"></div>
                    </div>
                    
                    <!-- Tags -->
                    <div>
                        <h3 style="margin-bottom: 16px; color: #ff3366;">Теги</h3>
                        <div class="form-group">
                            <input class="form-input" id="new-tag-name" placeholder="Название тега">
                        </div>
                        <div class="form-group">
                            <input class="form-input" id="new-tag-slug" placeholder="Slug (english)">
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="new-tag-nsfw" class="checkbox">
                                <span>NSFW контент</span>
                            </label>
                        </div>
                        <button class="btn btn-primary" style="width: 100%; margin-bottom: 16px;" onclick="addTag()">Добавить тег</button>
                        <div id="tags-list" style="max-height: 400px; overflow-y: auto;"></div>
                    </div>
                </div>
            </div>
            
            <!-- Messages Panel -->
            <div class="content-panel" id="panel-messages">
                <div class="panel-header">
                    <div class="panel-title">💬 Рассылка сообщений</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Сообщение для всех пользователей</label>
                    <textarea class="form-input form-textarea" id="message-text" placeholder="Введите текст сообщения..."></textarea>
                </div>
                <button class="btn btn-primary" style="width: 100%; margin-bottom: 24px;" onclick="sendMessage()">Отправить</button>
                <h3 style="margin-bottom: 16px; color: #ff3366;">История сообщений</h3>
                <div id="messages-list" class="table">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
            
            <!-- Suggestions Panel -->
            <div class="content-panel" id="panel-suggestions">
                <div class="panel-header">
                    <div class="panel-title">💡 Предложения пользователей</div>
                </div>
                <div id="suggestions-list" style="display: grid; gap: 12px;">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
            
            <!-- Archive Panel -->
            <div class="content-panel" id="panel-archive">
                <div class="panel-header">
                    <div class="panel-title">📜 Журнал действий</div>
                </div>
                <table class="table" id="archive-table">
                    <thead>
                        <tr>
                            <th>Действие</th>
                            <th>Детали</th>
                            <th>Пользователь</th>
                            <th>Время</th>
                        </tr>
                    </thead>
                    <tbody id="archive-list"></tbody>
                </table>
                <div id="archive-pagination" class="pagination"></div>
            </div>
            
            <!-- Admins Panel -->
            <div class="content-panel" id="panel-admins">
                <div class="panel-header">
                    <div class="panel-title">👑 Администраторы</div>
                </div>
                <div style="margin-bottom: 24px;">
                    <label class="form-label">Добавить администратора (ID или email)</label>
                    <div class="search-box">
                        <input class="form-input" id="admin-input" style="flex: 1;" placeholder="Telegram ID или email...">
                        <button class="btn btn-primary" onclick="addAdmin()">Добавить</button>
                    </div>
                </div>
                <h3 style="margin-bottom: 16px; color: #ff3366;">Bot Admins</h3>
                <div id="bot-admins-list" class="table"></div>
                <h3 style="margin-top: 24px; margin-bottom: 16px; color: #ff3366;">Account Admins</h3>
                <div id="account-admins-list" class="table"></div>
            </div>
        </div>
    </div>
    
    <script>
        let currentMangaPage = 0;
        let currentSuggestionsPage = 0;
        let currentArchivePage = 0;
        const getTgUser = () => new URLSearchParams(window.location.search).get('tg_user_id') || localStorage.getItem('tg_user_id') || 0;
        
        function switchPanel(panel) {
            document.querySelectorAll('.content-panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            
            document.getElementById('panel-' + panel).classList.add('active');
            event.target.closest('.nav-item').classList.add('active');
            
            const loaders = {
                dashboard: loadDashboard,
                manga: () => { currentMangaPage = 0; loadManga(''); },
                tags: loadTags,
                messages: loadMessages,
                suggestions: () => { currentSuggestionsPage = 0; loadSuggestions(0); },
                archive: () => { currentArchivePage = 0; loadArchive(0); },
                admins: loadAdmins
            };
            
            if (loaders[panel]) loaders[panel]();
        }
        
        async function loadDashboard() {
            try {
                const r = await fetch('/api/admin/stats?tg_user_id=' + getTgUser());
                const d = await r.json();
                document.getElementById('stats-container').innerHTML = `
                    <div class="stat-card">
                        <div class="stat-label">Всего манги</div>
                        <div class="stat-value">${d.manga}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Пользователей</div>
                        <div class="stat-value">${d.users}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Комментариев</div>
                        <div class="stat-value">${d.likes}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Действий в логе</div>
                        <div class="stat-value">${d.actions}</div>
                    </div>
                `;
            } catch(e) { console.error(e); }
        }
        
        async function loadManga(q = '', page = 0) {
            try {
                currentMangaPage = page;
                const r = await fetch(`/api/admin/manga-list?q=${encodeURIComponent(q)}&page=${page}&tg_user_id=${getTgUser()}`);
                const d = await r.json();
                
                let html = '';
                d.items.forEach(m => {
                    html += `
                        <div class="manga-item" onclick="editManga(${m.id})">
                            <div class="manga-thumb">
                                ${m.cover_imgbb_url ? `<img src="${m.cover_imgbb_url}" alt="">` : '<div style="width:100%;height:100%;background:#333;"></div>'}
                            </div>
                            <div class="manga-info">
                                <div class="manga-name">${m.title}</div>
                                <div class="manga-date">${new Date(m.created_at).toLocaleDateString('ru-RU')}</div>
                            </div>
                            <button class="btn btn-danger btn-small" onclick="event.stopPropagation(); deleteManga(${m.id})">Удалить</button>
                        </div>
                    `;
                });
                
                document.getElementById('manga-list').innerHTML = html || '<p style="color:#999;">Ничего не найдено</p>';
                
                const pages = Math.ceil(d.total / 10);
                let paginationHtml = '';
                for (let i = 0; i < pages; i++) {
                    paginationHtml += `<button ${i === page ? 'class="active"' : ''} onclick="loadManga('${q}', ${i})">${i + 1}</button>`;
                }
                document.getElementById('manga-pagination').innerHTML = paginationHtml;
            } catch(e) { console.error(e); }
        }
        
        document.getElementById('manga-search')?.addEventListener('keyup', (e) => {
            loadManga(e.target.value, 0);
        });
        
        async function addNewManga() {
            const title = prompt('Название манги:');
            if (!title) return;
            
            try {
                const r = await fetch('/api/admin/manga/add?tg_user_id=' + getTgUser(), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({title})
                });
                const d = await r.json();
                if (d.success) {
                    loadManga('', 0);
                }
            } catch(e) { console.error(e); }
        }
        
        async function deleteManga(id) {
            if (!confirm('Удалить мангу?')) return;
            
            try {
                const r = await fetch(`/api/admin/manga/${id}/delete?tg_user_id=${getTgUser()}`, {method: 'POST'});
                const d = await r.json();
                if (d.success) loadManga('', 0);
            } catch(e) { console.error(e); }
        }
        
        async function loadTags() {
            try {
                const r = await fetch('/api/admin/tags?tg_user_id=' + getTgUser());
                const d = await r.json();
                
                let genresHtml = '';
                d.genres.forEach(g => {
                    genresHtml += `
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(255,51,102,0.05);border-radius:6px;margin-bottom:8px;">
                            <div>
                                <div style="font-weight:600;">${g.name}</div>
                                <div style="font-size:12px;color:#888;">${g.slug}</div>
                            </div>
                            <button class="btn btn-danger btn-small" onclick="delGenre(${g.id})">🗑️</button>
                        </div>
                    `;
                });
                document.getElementById('genres-list').innerHTML = genresHtml;
                
                let tagsHtml = '';
                d.tags.forEach(t => {
                    tagsHtml += `
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(255,51,102,0.05);border-radius:6px;margin-bottom:8px;">
                            <div>
                                <div style="font-weight:600;display:flex;align-items:center;gap:8px;">
                                    ${t.name}
                                    ${t.is_nsfw ? '<span class="tag nsfw">18+</span>' : ''}
                                </div>
                                <div style="font-size:12px;color:#888;">${t.slug}</div>
                            </div>
                            <button class="btn btn-danger btn-small" onclick="delTag(${t.id})">🗑️</button>
                        </div>
                    `;
                });
                document.getElementById('tags-list').innerHTML = tagsHtml;
            } catch(e) { console.error(e); }
        }
        
        async function addGenre() {
            const name = document.getElementById('new-genre-name').value.trim();
            const slug = document.getElementById('new-genre-slug').value.trim().toLowerCase();
            if (!name || !slug) { alert('Заполни все поля'); return; }
            
            try {
                const r = await fetch('/api/admin/genres/add?tg_user_id=' + getTgUser(), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({name, slug})
                });
                const d = await r.json();
                if (d.success) {
                    document.getElementById('new-genre-name').value = '';
                    document.getElementById('new-genre-slug').value = '';
                    loadTags();
                } else alert('❌ ' + (d.error || 'Ошибка'));
            } catch(e) { console.error(e); }
        }
        
        async function delGenre(id) {
            if (!confirm('Удалить жанр?')) return;
            try {
                const r = await fetch(`/api/admin/genres/${id}/delete?tg_user_id=${getTgUser()}`, {method: 'POST'});
                const d = await r.json();
                if (d.success) loadTags();
            } catch(e) { console.error(e); }
        }
        
        async function addTag() {
            const name = document.getElementById('new-tag-name').value.trim();
            const slug = document.getElementById('new-tag-slug').value.trim().toLowerCase();
            const nsfw = document.getElementById('new-tag-nsfw').checked;
            if (!name || !slug) { alert('Заполни все поля'); return; }
            
            try {
                const r = await fetch('/api/admin/tags/add?tg_user_id=' + getTgUser(), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({name, slug, is_nsfw: nsfw})
                });
                const d = await r.json();
                if (d.success) {
                    document.getElementById('new-tag-name').value = '';
                    document.getElementById('new-tag-slug').value = '';
                    document.getElementById('new-tag-nsfw').checked = false;
                    loadTags();
                } else alert('❌ ' + (d.error || 'Ошибка'));
            } catch(e) { console.error(e); }
        }
        
        async function delTag(id) {
            if (!confirm('Удалить тег?')) return;
            try {
                const r = await fetch(`/api/admin/tags/${id}/delete?tg_user_id=${getTgUser()}`, {method: 'POST'});
                const d = await r.json();
                if (d.success) loadTags();
            } catch(e) { console.error(e); }
        }
        
        async function reseedTags() {
            if (!confirm('Загрузить все стандартные теги и жанры?')) return;
            try {
                const r = await fetch('/api/admin/reseed-tags?tg_user_id=' + getTgUser(), {method: 'POST'});
                const d = await r.json();
                if (d.success) {
                    alert(`✅ Тегов: ${d.tags}, жанров: ${d.genres}`);
                    loadTags();
                } else alert('❌ ' + (d.error || 'Ошибка'));
            } catch(e) { console.error(e); }
        }
        
        async function loadMessages() {
            try {
                const r = await fetch('/api/admin/messages?tg_user_id=' + getTgUser());
                const d = await r.json();
                
                let html = '<thead><tr><th>Сообщение</th><th>Отправитель</th><th>Время</th><th></th></tr></thead><tbody>';
                d.messages.forEach(m => {
                    html += `
                        <tr>
                            <td>${m.text}</td>
                            <td><code>${m.sent_by}</code></td>
                            <td>${new Date(m.created_at).toLocaleDateString('ru-RU')}</td>
                            <td><button class="btn btn-danger btn-small" onclick="delMessage(${m.id})">🗑️</button></td>
                        </tr>
                    `;
                });
                html += '</tbody>';
                document.getElementById('messages-list').innerHTML = `<table class="table">${html}</table>`;
            } catch(e) { console.error(e); }
        }
        
        async function sendMessage() {
            const text = document.getElementById('message-text').value.trim();
            if (!text) { alert('Напиши сообщение'); return; }
            
            try {
                const r = await fetch('/api/admin/messages/send', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({text, tg_user_id: getTgUser()})
                });
                const d = await r.json();
                if (d.success) {
                    document.getElementById('message-text').value = '';
                    loadMessages();
                }
            } catch(e) { console.error(e); }
        }
        
        async function delMessage(id) {
            if (!confirm('Удалить сообщение?')) return;
            try {
                await fetch(`/api/admin/messages/${id}/delete`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({tg_user_id: getTgUser()})
                });
                loadMessages();
            } catch(e) { console.error(e); }
        }
        
        async function loadSuggestions(page) {
            try {
                const r = await fetch(`/api/admin/suggestions?page=${page}&tg_user_id=${getTgUser()}`);
                const d = await r.json();
                
                let html = '';
                d.items.forEach(s => {
                    html += `
                        <div style="background:rgba(255,51,102,0.08);border:1px solid rgba(255,51,102,0.2);border-radius:8px;padding:16px;">
                            <div style="margin-bottom:8px;"><strong>ID: ${s.user_id}</strong> - ${new Date(s.created_at).toLocaleString('ru-RU')}</div>
                            <div style="color:#bbb;margin-bottom:12px;">${s.text}</div>
                            <button class="btn btn-secondary" onclick="markSug(${s.id})">Прочитано</button>
                        </div>
                    `;
                });
                
                document.getElementById('suggestions-list').innerHTML = html || '<p style="color:#999;">Нет предложений</p>';
            } catch(e) { console.error(e); }
        }
        
        async function markSug(id) {
            try {
                await fetch(`/api/admin/suggestions/${id}/status`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({status: 'read', tg_user_id: getTgUser()})
                });
                loadSuggestions(0);
            } catch(e) { console.error(e); }
        }
        
        async function loadArchive(page) {
            try {
                const r = await fetch(`/api/admin/archive?page=${page}&tg_user_id=${getTgUser()}`);
                const d = await r.json();
                
                let html = '';
                d.items.forEach(a => {
                    html += `
                        <tr>
                            <td><strong>${a.action_type}</strong></td>
                            <td>${a.action_text}</td>
                            <td><code>${a.action_by}</code></td>
                            <td>${new Date(a.created_at).toLocaleString('ru-RU')}</td>
                        </tr>
                    `;
                });
                
                document.getElementById('archive-list').innerHTML = html;
                
                const pages = Math.ceil(d.total / 20);
                let paginationHtml = '';
                for (let i = 0; i < pages; i++) {
                    paginationHtml += `<button ${i === page ? 'class="active"' : ''} onclick="loadArchive(${i})">${i + 1}</button>`;
                }
                document.getElementById('archive-pagination').innerHTML = paginationHtml;
            } catch(e) { console.error(e); }
        }
        
        async function loadAdmins() {
            try {
                const r = await fetch('/api/admin/admins?tg_user_id=' + getTgUser());
                const d = await r.json();
                
                let botHtml = '';
                d.bot_admins.forEach(a => {
                    botHtml += `
                        <tr>
                            <td><code>${a.user_id}</code></td>
                            <td><button class="btn btn-danger btn-small" onclick="removeAdmin(${a.user_id}, 'bot')">Удалить</button></td>
                        </tr>
                    `;
                });
                document.getElementById('bot-admins-list').innerHTML = botHtml ? `<table class="table"><thead><tr><th>Telegram ID</th><th></th></tr></thead><tbody>${botHtml}</tbody></table>` : '<p style="color:#999;">Нет bot admins</p>';
                
                let accHtml = '';
                d.account_admins.forEach(a => {
                    accHtml += `
                        <tr>
                            <td>${a.email}</td>
                            <td>${a.username}</td>
                            <td><button class="btn btn-danger btn-small" onclick="removeAdmin(${a.id}, 'account')">Удалить</button></td>
                        </tr>
                    `;
                });
                document.getElementById('account-admins-list').innerHTML = accHtml ? `<table class="table"><thead><tr><th>Email</th><th>Username</th><th></th></tr></thead><tbody>${accHtml}</tbody></table>` : '<p style="color:#999;">Нет account admins</p>';
            } catch(e) { console.error(e); }
        }
        
        async function addAdmin() {
            const input = document.getElementById('admin-input').value.trim();
            if (!input) { alert('Введи ID или email'); return; }
            
            try {
                const r = await fetch('/api/admin/admins/add?tg_user_id=' + getTgUser(), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({input})
                });
                const d = await r.json();
                if (d.success) {
                    document.getElementById('admin-input').value = '';
                    loadAdmins();
                } else alert('❌ ' + (d.error || 'Ошибка'));
            } catch(e) { console.error(e); }
        }
        
        async function removeAdmin(id, type) {
            if (!confirm('Удалить из администраторов?')) return;
            try {
                const r = await fetch('/api/admin/admins/remove?tg_user_id=' + getTgUser(), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id, type})
                });
                const d = await r.json();
                if (d.success) loadAdmins();
            } catch(e) { console.error(e); }
        }
        
        function editManga(id) {
            alert('Редактирование в будущем обновлении');
        }
        
        // Load initial dashboard
        loadDashboard();
    </script>
</body>
</html>
<?php exit; }

// Default index page view - поиск улучшен
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLACKWATCH - Аниме Манга</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f0f1e 0%, #1a1a2e 100%);
            color: #e0e0e0;
        }
        
        .search-container {
            background: rgba(15, 15, 30, 0.9);
            padding: 20px;
            border-bottom: 2px solid #ff3366;
        }
        
        .search-wrapper {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.08);
            border: 2px solid rgba(255, 51, 102, 0.3);
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #ff3366;
            background: rgba(255, 51, 102, 0.1);
            box-shadow: 0 0 12px rgba(255, 51, 102, 0.2);
        }
        
        .search-input::placeholder {
            color: #888;
        }
        
        .btn-search {
            padding: 12px 24px;
            background: linear-gradient(135deg, #ff3366 0%, #ff66b2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-search:hover {
            box-shadow: 0 0 20px rgba(255, 51, 102, 0.4);
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .search-input {
                min-width: 100%;
                font-size: 16px;
            }
            
            .btn-search {
                width: 100%;
            }
            
            .search-wrapper {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="search-container">
        <div class="search-wrapper">
            <input class="search-input" id="global-search" type="text" placeholder="🔍 Поиск манги, тегов, жанров...">
            <button class="btn-search" onclick="performSearch()">Поиск</button>
        </div>
    </div>
    
    <script>
        const searchInput = document.getElementById('global-search');
        
        searchInput?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') performSearch();
        });
        
        function performSearch() {
            const query = searchInput?.value.trim() || '';
            if (query) {
                // Redirect to search results or handle locally
                window.location.href = '/?search=' + encodeURIComponent(query);
            }
        }
    </script>
</body>
</html>
<?php