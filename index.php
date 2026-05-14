<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

# =========================
# DATABASE (Neon PostgreSQL)
# =========================
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
    getenv('DB_HOST'),
    getenv('DB_PORT') ?: '5432',
    getenv('DB_NAME')
);

try {
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// Создаём/обновляем таблицы
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga (id SERIAL PRIMARY KEY, title TEXT NOT NULL, file_id TEXT, description TEXT, likes INT DEFAULT 0, dislikes INT DEFAULT 0, added_by BIGINT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, cover_imgbb_url TEXT, telegraph_url TEXT, is_series BOOLEAN DEFAULT FALSE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_pages (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapters (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, chapter_num FLOAT NOT NULL DEFAULT 1, title TEXT, telegraph_url TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapter_pages (id SERIAL PRIMARY KEY, chapter_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (user_id BIGINT NOT NULL, manga_id INT NOT NULL, vote_type VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (user_id BIGINT NOT NULL, manga_id INT NOT NULL, status VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_progress (user_id BIGINT NOT NULL, manga_id INT NOT NULL, page_num INT DEFAULT 1, total_pages INT DEFAULT 0, chapter_id INT DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_admins (user_id BIGINT PRIMARY KEY)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archive (id SERIAL PRIMARY KEY, action_type VARCHAR(50) NOT NULL, action_text TEXT NOT NULL, action_by BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (user_id BIGINT PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    // ALTER существующих таблиц
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS is_series BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE manga_pages ADD COLUMN IF NOT EXISTS page_url TEXT");
    $pdo->exec("ALTER TABLE reading_progress ADD COLUMN IF NOT EXISTS chapter_id INT DEFAULT NULL");
} catch (Exception $e) {}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

session_start();
if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);

# =========================
# СПИСОК АДМИНОВ
# =========================
$hardcodedAdmins = [1710365896, 1181510470];
try {
    $stmtAdmins = $pdo->query("SELECT user_id FROM bot_admins");
    foreach ($stmtAdmins as $row) {
        if (!in_array((int)$row['user_id'], $hardcodedAdmins)) {
            $hardcodedAdmins[] = (int)$row['user_id'];
        }
    }
} catch (Exception $e) {}

function isAdmin($userId, $admins) {
    return $userId > 0 && in_array((int)$userId, $admins);
}

# =========================
# IMGBB KEYS
# =========================
$imgbbKeys = [
    '58ff4596fd55028a81cbf8c4e38388e1',
    '6981ba08e7b2a8743aab2c8ea008f675',
    'f9b8d27fa4029816d643c7814fd60c60',
    '24dbed2ae9fea9369de6a7b68d0c3ee6',
    'c3e6a55335c71a052c1a59b6a2d6d150',
];

# =========================
# ПОЛУЧИТЬ ID ПОЛЬЗОВАТЕЛЯ
# =========================
function getEffectiveUserId($pdo) {
    $tgUser = $_GET['tg_user_id'] ?? $_POST['tg_user_id'] ?? '';
    if ($tgUser && is_numeric($tgUser)) {
        if (!headers_sent()) setcookie('tg_user_id', $tgUser, time() + 86400 * 30, '/', '', false, false);
        $_SESSION['tg_user_id'] = $tgUser;
        return (int)$tgUser;
    }
    if (!empty($_SESSION['tg_user_id']) && is_numeric($_SESSION['tg_user_id'])) return (int)$_SESSION['tg_user_id'];
    if (!empty($_COOKIE['tg_user_id']) && is_numeric($_COOKIE['tg_user_id'])) {
        $_SESSION['tg_user_id'] = $_COOKIE['tg_user_id'];
        return (int)$_COOKIE['tg_user_id'];
    }
    return (int)$_SESSION['guest_id'];
}

# =========================
# TELEGRAM NOTIFY
# =========================
function sendTgNotify($userId, $text) {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken || !$userId) return;
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $userId, 'text' => $text, 'parse_mode' => 'Markdown']));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    @curl_exec($ch);
    curl_close($ch);
}

# =========================
# UPLOAD TO IMGBB
# =========================
function uploadToImgbb($filePathOrUrl, $apiKeys, $retries = 2) {
    $keys = is_array($apiKeys) ? $apiKeys : [$apiKeys];
    foreach ($keys as $key) {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $imageData = base64_encode(file_get_contents($filePathOrUrl));
                $ch = curl_init('https://api.imgbb.com/1/upload');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $key, 'image' => $imageData]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 90);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (!empty($data['data']['url'])) return $data['data']['url'];
                }
                if ($httpCode === 400 || $httpCode === 429) break; // переход к следующему ключу
            } catch (Exception $e) {}
        }
    }
    return null;
}

# =========================
# ZIP SORT (как в bot.php — по дате модификации файлов внутри архива!)
# =========================
function extractAndSortZip($zipPath, $extractDir) {
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $raw = file_get_contents($zipPath);
    if ($raw === false) return false;
    $len = strlen($raw);
    $eocdPos = false;
    for ($i = $len - 22; $i >= max(0, $len - 65557); $i--) {
        if (substr($raw, $i, 4) === "\x50\x4b\x05\x06") { $eocdPos = $i; break; }
    }
    if ($eocdPos === false) return false;
    $cdCount  = unpack('v', substr($raw, $eocdPos + 10, 2))[1];
    $cdOffset = unpack('V', substr($raw, $eocdPos + 16, 4))[1];
    $entries = [];
    $pos = $cdOffset;
    for ($n = 0; $n < $cdCount; $n++) {
        if ($pos + 46 > $len) break;
        if (substr($raw, $pos, 4) !== "\x50\x4b\x01\x02") break;
        $modTime  = unpack('v', substr($raw, $pos + 12, 2))[1];
        $modDate  = unpack('v', substr($raw, $pos + 14, 2))[1];
        $compSize = unpack('V', substr($raw, $pos + 20, 4))[1];
        $origSize = unpack('V', substr($raw, $pos + 24, 4))[1];
        $fnameLen = unpack('v', substr($raw, $pos + 28, 2))[1];
        $extraLen = unpack('v', substr($raw, $pos + 30, 2))[1];
        $cmtLen   = unpack('v', substr($raw, $pos + 32, 2))[1];
        $compress = unpack('v', substr($raw, $pos + 10, 2))[1];
        $localHdrOffset = unpack('V', substr($raw, $pos + 42, 4))[1];
        $fname = substr($raw, $pos + 46, $fnameLen);
        $pos  += 46 + $fnameLen + $extraLen + $cmtLen;
        if (substr($fname, -1) === '/') continue;
        $base = basename($fname);
        if ($base === '' || $base[0] === '.') continue;
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) continue;
        $lhPos = $localHdrOffset;
        if ($lhPos + 30 > $len) continue;
        if (substr($raw, $lhPos, 4) !== "\x50\x4b\x03\x04") continue;
        $lFnameLen = unpack('v', substr($raw, $lhPos + 26, 2))[1];
        $lExtraLen = unpack('v', substr($raw, $lhPos + 28, 2))[1];
        $dataOffset = $lhPos + 30 + $lFnameLen + $lExtraLen;
        $second = ($modTime & 0x1F) * 2;
        $minute = ($modTime >> 5) & 0x3F;
        $hour   = ($modTime >> 11) & 0x1F;
        $day    = $modDate & 0x1F;
        $month  = ($modDate >> 5) & 0x0F;
        $year   = (($modDate >> 9) & 0x7F) + 1980;
        $mtime  = mktime($hour, $minute, $second, $month, $day, $year);
        $entries[] = ['name' => $fname, 'base' => $base, 'mtime' => $mtime, 'compress' => $compress,
                      'compSize' => $compSize, 'origSize' => $origSize, 'dataOffset' => $dataOffset];
    }
    if (empty($entries)) return false;
    // Сортировка: сначала по дате (как в боте), при равной дате — натуральная по имени
    usort($entries, function ($a, $b) {
        if ($a['mtime'] === $b['mtime']) return strnatcasecmp($a['base'], $b['base']);
        return $a['mtime'] - $b['mtime'];
    });
    $extractedPaths = [];
    foreach ($entries as $entry) {
        $outPath = $extractDir . '/' . $entry['base'];
        if ($entry['compress'] === 0) {
            $fileData = substr($raw, $entry['dataOffset'], $entry['origSize']);
        } elseif ($entry['compress'] === 8) {
            $compressed = substr($raw, $entry['dataOffset'], $entry['compSize']);
            $fileData = @gzinflate($compressed);
            if ($fileData === false) continue;
        } else { continue; }
        if (file_put_contents($outPath, $fileData) === false) continue;
        $extractedPaths[] = $outPath;
    }
    return empty($extractedPaths) ? false : $extractedPaths;
}

# =========================
# CREATE TELEGRAPH PAGE
# =========================
function createTelegraphPage($title, $imageUrls) {
    $nodes = [];
    $imageUrls = array_values(array_unique(array_filter($imageUrls)));
    foreach ($imageUrls as $url) {
        if (!empty($url)) $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $url]];
    }
    if (empty($nodes)) return false;
    $accessToken = '192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831';
    $postData = http_build_query([
        'access_token'   => $accessToken,
        'title'          => mb_substr($title, 0, 256),
        'author_name'    => 'MangaBot',
        'content'        => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'return_content' => 'false',
    ]);
    $ch = curl_init("https://api.telegra.ph/createPage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $rawResponse = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($rawResponse, true);
    if (!isset($res['ok']) || !$res['ok']) return false;
    return $res['result']['url'] ?? false;
}

# =========================
# SAVE MANGA PAGES TO DB
# =========================
function saveMangaPages($pdo, $mangaId, $pageUrls) {
    $pdo->prepare("DELETE FROM manga_pages WHERE manga_id = ?")->execute([$mangaId]);
    $stmt = $pdo->prepare("INSERT INTO manga_pages (manga_id, page_url, page_order) VALUES (?, ?, ?)");
    foreach ($pageUrls as $i => $url) {
        if (!empty($url)) $stmt->execute([$mangaId, $url, $i]);
    }
}

function saveChapterPages($pdo, $chapterId, $pageUrls) {
    $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id = ?")->execute([$chapterId]);
    $stmt = $pdo->prepare("INSERT INTO manga_chapter_pages (chapter_id, page_url, page_order) VALUES (?, ?, ?)");
    foreach ($pageUrls as $i => $url) {
        if (!empty($url)) $stmt->execute([$chapterId, $url, $i]);
    }
}

function logArchiveEntry($pdo, $action_type, $action_text, $userId) {
    try {
        $pdo->prepare("INSERT INTO bot_archive (action_type, action_text, action_by) VALUES (?, ?, ?)")->execute([$action_type, $action_text, $userId]);
    } catch (Exception $e) {}
}

function extractImgFromContent($nodes) {
    $urls = [];
    if (!is_array($nodes)) return $urls;
    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        if (isset($node['tag']) && $node['tag'] === 'img' && !empty($node['attrs']['src'])) {
            $src = $node['attrs']['src'];
            $urls[] = strpos($src, 'http') === 0 ? $src : 'https://telegra.ph' . $src;
        }
        if (!empty($node['children'])) $urls = array_merge($urls, extractImgFromContent($node['children']));
    }
    return $urls;
}

# =========================
# API CHECK ADMIN
# =========================
if ($path === '/api/check-admin') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    echo json_encode(['is_admin' => isAdmin($userId, $hardcodedAdmins), 'user_id' => $userId]);
    exit;
}

# =========================
# API IMGBB-KEYS
# =========================
if ($path === '/api/imgbb-keys') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['success' => false, 'keys' => []]); exit; }
    echo json_encode(['success' => true, 'keys' => $imgbbKeys]);
    exit;
}

# =========================
# API SAVE-MANGA (принимает текст + готовые URL)
# =========================
if ($path === '/api/save-manga' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $userId = getEffectiveUserId($pdo);
        if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['success' => false, 'error' => 'Нет прав доступа']); exit; }
        $input       = json_decode(file_get_contents('php://input'), true);
        $title       = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $coverUrl    = trim($input['cover_url'] ?? '');
        $pageUrls    = array_values(array_filter($input['page_urls'] ?? []));
        $isSeries    = !empty($input['is_series']);
        if (!$title) { echo json_encode(['success' => false, 'error' => 'Название обязательно']); exit; }
        $telegraphLink = null;
        if (!empty($pageUrls) && !$isSeries) {
            $telegraphLink = createTelegraphPage('♥ ' . $title, $pageUrls);
        }
        $insertStmt = $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, cover_imgbb_url, added_by, is_series) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
        $insertStmt->execute(['♥ ' . $title, $telegraphLink, $description, $coverUrl ?: null, $userId, $isSeries]);
        $row        = $insertStmt->fetch(PDO::FETCH_ASSOC);
        $newMangaId = (int)($row['id'] ?? 0);
        if (!$newMangaId) $newMangaId = (int)$pdo->lastInsertId();
        if (!$newMangaId) { echo json_encode(['success' => false, 'error' => 'Не удалось получить ID']); exit; }
        if (!empty($pageUrls) && !$isSeries) saveMangaPages($pdo, $newMangaId, $pageUrls);
        logArchiveEntry($pdo, 'add_manga', "Добавлена манга: ♥ $title", $userId);
        $siteUrl = rtrim(getenv('SITE_URL') ?: '', '/');
        echo json_encode(['success' => true, 'manga_id' => $newMangaId, 'telegraph' => $telegraphLink, 'pages' => count($pageUrls), 'site_url' => "{$siteUrl}/read/{$newMangaId}"]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Исключение: ' . $e->getMessage()]);
    }
    exit;
}

# =========================
# API SAVE-CHAPTER (добавить главу в серию)
# =========================
if ($path === '/api/save-chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $userId = getEffectiveUserId($pdo);
        if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['success' => false, 'error' => 'Нет прав']); exit; }
        $input      = json_decode(file_get_contents('php://input'), true);
        $mangaId    = (int)($input['manga_id'] ?? 0);
        $chapterNum = (float)($input['chapter_num'] ?? 1);
        $chTitle    = trim($input['chapter_title'] ?? '');
        $pageUrls   = array_values(array_filter($input['page_urls'] ?? []));
        if (!$mangaId) { echo json_encode(['success' => false, 'error' => 'Не указан manga_id']); exit; }
        // Проверяем что манга существует
        $mangaStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $mangaStmt->execute([$mangaId]);
        $manga = $mangaStmt->fetch();
        if (!$manga) { echo json_encode(['success' => false, 'error' => 'Манга не найдена']); exit; }
        // Создаём telegraph страницу для главы
        $telegraphLink = null;
        if (!empty($pageUrls)) {
            $chapterLabel = "Глава $chapterNum" . ($chTitle ? ": $chTitle" : '');
            $telegraphLink = createTelegraphPage($manga['title'] . ' — ' . $chapterLabel, $pageUrls);
        }
        $chInsert = $pdo->prepare("INSERT INTO manga_chapters (manga_id, chapter_num, title, telegraph_url) VALUES (?, ?, ?, ?) RETURNING id");
        $chInsert->execute([$mangaId, $chapterNum, $chTitle ?: null, $telegraphLink]);
        $chRow = $chInsert->fetch();
        $chapterId = (int)($chRow['id'] ?? 0);
        if ($chapterId && !empty($pageUrls)) saveChapterPages($pdo, $chapterId, $pageUrls);
        // Помечаем мангу как серию
        $pdo->prepare("UPDATE manga SET is_series = TRUE WHERE id = ?")->execute([$mangaId]);
        logArchiveEntry($pdo, 'add_chapter', "Добавлена глава $chapterNum для манги: {$manga['title']}", $userId);
        echo json_encode(['success' => true, 'chapter_id' => $chapterId, 'telegraph' => $telegraphLink]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

# =========================
# API MANGA LIST
# =========================
if ($path === '/api/manga') {
    header('Content-Type: application/json');
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $q      = trim($_GET['q'] ?? '');
    $sort   = $_GET['sort'] ?? 'new';
    $limit  = 24;
    $offset = $page * $limit;
    $orderBy = 'id DESC';
    if ($sort === 'popular') $orderBy = 'likes DESC, id DESC';
    if ($sort === 'alpha')   $orderBy = 'title ASC';
    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id, created_at, is_series FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");
        $count->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id, created_at, is_series FROM manga ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $count = $pdo->query("SELECT COUNT(*) FROM manga");
    }
    $now = date('Y-m-d H:i:s', time() - 86400);
    $items = [];
    foreach ($stmt as $m) {
        // Количество глав если серия
        $chapCount = 0;
        if ($m['is_series']) {
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE manga_id = ?");
            $cStmt->execute([$m['id']]);
            $chapCount = (int)$cStmt->fetchColumn();
        }
        $items[] = [
            'id'            => (int)$m['id'],
            'title'         => $m['title'],
            'likes'         => (int)$m['likes'],
            'dislikes'      => (int)$m['dislikes'],
            'cover_display' => !empty($m['cover_imgbb_url']) ? $m['cover_imgbb_url'] : (!empty($m['file_id']) ? 'tg://' . $m['file_id'] : null),
            'is_new'        => ($m['created_at'] >= $now),
            'is_series'     => (bool)$m['is_series'],
            'chapter_count' => $chapCount,
        ];
    }
    echo json_encode(['items' => $items, 'total' => (int)$count->fetchColumn(), 'limit' => $limit]);
    exit;
}

# =========================
# API NEW MANGA
# =========================
if ($path === '/api/new-manga') {
    header('Content-Type: application/json');
    $since = date('Y-m-d H:i:s', time() - 86400);
    $stmt  = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id, created_at, is_series FROM manga WHERE created_at >= ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$since]);
    $items = [];
    foreach ($stmt as $m) {
        $items[] = [
            'id'            => (int)$m['id'],
            'title'         => $m['title'],
            'likes'         => (int)$m['likes'],
            'cover_display' => !empty($m['cover_imgbb_url']) ? $m['cover_imgbb_url'] : null,
            'is_series'     => (bool)$m['is_series'],
        ];
    }
    echo json_encode(['items' => $items]);
    exit;
}

# =========================
# API RANDOM MANGA
# =========================
if ($path === '/api/random') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    $stmt = $pdo->prepare("SELECT id FROM manga WHERE id NOT IN (SELECT manga_id FROM user_manga_status WHERE user_id = ? AND status = 'read') ORDER BY RANDOM() LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) $row = $pdo->query("SELECT id FROM manga ORDER BY RANDOM() LIMIT 1")->fetch();
    echo json_encode(['id' => $row ? (int)$row['id'] : null]);
    exit;
}

# =========================
# API CHAPTERS — список глав манги
# =========================
if (preg_match('#^/api/chapters/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json');
    $mangaId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, chapter_num, title, telegraph_url, created_at FROM manga_chapters WHERE manga_id = ? ORDER BY chapter_num ASC");
    $stmt->execute([$mangaId]);
    $chapters = $stmt->fetchAll();
    // Добавляем количество страниц к каждой главе
    foreach ($chapters as &$ch) {
        $pStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_chapter_pages WHERE chapter_id = ?");
        $pStmt->execute([$ch['id']]);
        $ch['page_count'] = (int)$pStmt->fetchColumn();
    }
    echo json_encode(['chapters' => $chapters]);
    exit;
}

# =========================
# API CHAPTER PAGES
# =========================
if (preg_match('#^/api/chapter-pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json');
    $chapterId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT page_url FROM manga_chapter_pages WHERE chapter_id = ? ORDER BY page_order ASC");
    $stmt->execute([$chapterId]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Если нет страниц, попробуем из telegraph
    if (empty($pages)) {
        $chStmt = $pdo->prepare("SELECT telegraph_url FROM manga_chapters WHERE id = ?");
        $chStmt->execute([$chapterId]);
        $ch = $chStmt->fetch();
        if (!empty($ch['telegraph_url'])) {
            $tUrl = $ch['telegraph_url'];
            $tPath = ltrim(parse_url($tUrl, PHP_URL_PATH), '/');
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0']]);
            $apiResp = @file_get_contents("https://api.telegra.ph/getPage/" . $tPath . "?return_content=true", false, $ctx);
            if ($apiResp) {
                $apiData = json_decode($apiResp, true);
                if (!empty($apiData['ok']) && !empty($apiData['result']['content'])) {
                    $pages = extractImgFromContent($apiData['result']['content']);
                }
            }
        }
    }
    echo json_encode(['pages' => array_values($pages)]);
    exit;
}

# =========================
# API PAGES (обычная манга)
# =========================
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json');
    $id   = (int)$m[1];
    $stmt = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? AND page_url IS NOT NULL AND page_url != '' ORDER BY page_order ASC");
    $stmt->execute([$id]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($pages)) {
        $mangaStmt = $pdo->prepare("SELECT telegraph_url FROM manga WHERE id = ?");
        $mangaStmt->execute([$id]);
        $manga = $mangaStmt->fetch();
        $tUrl  = $manga['telegraph_url'] ?? null;
        if ($tUrl) {
            $tPath = ltrim(parse_url($tUrl, PHP_URL_PATH), '/');
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0']]);
            $apiResp = @file_get_contents("https://api.telegra.ph/getPage/" . $tPath . "?return_content=true", false, $ctx);
            if ($apiResp) {
                $apiData = json_decode($apiResp, true);
                if (!empty($apiData['ok']) && !empty($apiData['result']['content'])) {
                    $pages = extractImgFromContent($apiData['result']['content']);
                }
            }
            if (!empty($pages) && strpos($pages[0], 'ibb.co') !== false) array_shift($pages);
        }
        if (empty($pages)) { echo json_encode(['pages' => [], 'telegraph_url' => $tUrl]); exit; }
    }
    echo json_encode(['pages' => array_values($pages)]);
    exit;
}

# =========================
# API READING PROGRESS
# =========================
if ($path === '/api/progress' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input   = json_decode(file_get_contents('php://input'), true);
    $userId  = getEffectiveUserId($pdo);
    if (!empty($input['tg_user_id']) && is_numeric($input['tg_user_id'])) $userId = (int)$input['tg_user_id'];
    $mangaId    = (int)($input['manga_id'] ?? 0);
    $pageNum    = (int)($input['page_num'] ?? 1);
    $totalPages = (int)($input['total_pages'] ?? 0);
    $chapterId  = isset($input['chapter_id']) ? (int)$input['chapter_id'] : null;
    if ($mangaId && $userId) {
        $pdo->prepare("INSERT INTO reading_progress (user_id, manga_id, page_num, total_pages, chapter_id, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON CONFLICT (user_id, manga_id) DO UPDATE SET page_num = EXCLUDED.page_num, total_pages = EXCLUDED.total_pages, chapter_id = EXCLUDED.chapter_id, updated_at = NOW()")->execute([$userId, $mangaId, $pageNum, $totalPages, $chapterId]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($path === '/api/progress') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    $stmt = $pdo->prepare("SELECT m.id, m.title, m.cover_imgbb_url, m.file_id, rp.page_num, rp.total_pages, rp.updated_at, rp.chapter_id, s.status FROM user_manga_status s JOIN manga m ON s.manga_id = m.id LEFT JOIN reading_progress rp ON rp.manga_id = m.id AND rp.user_id = s.user_id WHERE s.user_id = ? AND s.status IN ('now','will') ORDER BY rp.updated_at DESC NULLS LAST LIMIT 20");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();
    echo json_encode(['items' => $items]);
    exit;
}

# =========================
# API COVER PROXY
# =========================
if (preg_match('#^/api/cover/(.+)$#', $path, $m)) {
    $fileId = $m[1];
    $token  = getenv('BOT_TOKEN');
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $res = @file_get_contents("https://api.telegram.org/bot{$token}/getFile?file_id=" . urlencode($fileId), false, $ctx);
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data['result']['file_path'])) {
            header("Location: https://api.telegram.org/file/bot{$token}/" . $data['result']['file_path'], true, 302);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

# =========================
# API VOTE
# =========================
if ($path === '/api/vote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input    = json_decode(file_get_contents('php://input'), true);
    $userId   = getEffectiveUserId($pdo);
    if (!empty($input['tg_user_id']) && is_numeric($input['tg_user_id'])) $userId = (int)$input['tg_user_id'];
    $mangaId  = (int)($input['manga_id'] ?? 0);
    $voteType = $input['vote_type'] ?? '';
    if ($mangaId && in_array($voteType, ['like', 'dislike'])) {
        $check = $pdo->prepare("SELECT vote_type FROM votes WHERE user_id = ? AND manga_id = ?");
        $check->execute([$userId, $mangaId]);
        $existing = $check->fetch();
        if ($existing) {
            if ($existing['vote_type'] !== $voteType) {
                $pdo->prepare("UPDATE votes SET vote_type = ? WHERE user_id = ? AND manga_id = ?")->execute([$voteType, $userId, $mangaId]);
                if ($voteType == 'like') $pdo->prepare("UPDATE manga SET likes = likes + 1, dislikes = GREATEST(0, dislikes - 1) WHERE id = ?")->execute([$mangaId]);
                else $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1, likes = GREATEST(0, likes - 1) WHERE id = ?")->execute([$mangaId]);
            }
        } else {
            $pdo->prepare("INSERT INTO votes (user_id, manga_id, vote_type) VALUES (?, ?, ?)")->execute([$userId, $mangaId, $voteType]);
            if ($voteType == 'like') $pdo->prepare("UPDATE manga SET likes = likes + 1 WHERE id = ?")->execute([$mangaId]);
            else $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1 WHERE id = ?")->execute([$mangaId]);
        }
        $stmt = $pdo->prepare("SELECT likes, dislikes FROM manga WHERE id = ?");
        $stmt->execute([$mangaId]);
        $stats = $stmt->fetch();
        echo json_encode(['success' => true, 'likes' => (int)$stats['likes'], 'dislikes' => (int)$stats['dislikes']]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

# =========================
# API STATUS
# =========================
if ($path === '/api/status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true);
    $userId = getEffectiveUserId($pdo);
    if (!empty($input['tg_user_id']) && is_numeric($input['tg_user_id'])) {
        $userId = (int)$input['tg_user_id'];
        $_SESSION['tg_user_id'] = $userId;
        if (!headers_sent()) setcookie('tg_user_id', $userId, time() + 86400 * 30, '/', '', false, false);
    }
    $mangaId = (int)($input['manga_id'] ?? 0);
    $status  = $input['status'] ?? '';
    if ($mangaId && in_array($status, ['now', 'read', 'will'])) {
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON CONFLICT (user_id, manga_id) DO UPDATE SET status = EXCLUDED.status")->execute([$userId, $mangaId, $status]);
        echo json_encode(['success' => true, 'user_id' => $userId]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

# =========================
# API LIBRARY
# =========================
if ($path === '/api/library') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    $stmt   = $pdo->prepare("SELECT m.id, m.title, m.cover_imgbb_url, m.file_id, s.status FROM user_manga_status s JOIN manga m ON s.manga_id = m.id WHERE s.user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['items' => $stmt->fetchAll(), 'user_id' => $userId]);
    exit;
}

# =========================
# API ADMIN — статистика
# =========================
if ($path === '/api/admin/stats') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['error' => 'Нет прав']); exit; }
    $mangaCount   = (int)$pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
    $usersCount   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $votesCount   = (int)$pdo->query("SELECT COUNT(*) FROM votes")->fetchColumn();
    $pagesCount   = (int)$pdo->query("SELECT COUNT(*) FROM manga_pages")->fetchColumn();
    $chaptersCount = (int)$pdo->query("SELECT COUNT(*) FROM manga_chapters")->fetchColumn();
    $newToday     = (int)$pdo->query("SELECT COUNT(*) FROM manga WHERE created_at >= NOW() - INTERVAL '24 hours'")->fetchColumn();
    $topManga     = $pdo->query("SELECT title, likes FROM manga ORDER BY likes DESC LIMIT 5")->fetchAll();
    echo json_encode(['manga_count' => $mangaCount, 'users_count' => $usersCount, 'votes_count' => $votesCount, 'pages_count' => $pagesCount, 'chapters_count' => $chaptersCount, 'new_today' => $newToday, 'top_manga' => $topManga]);
    exit;
}

# =========================
# API ADMIN — архив действий
# =========================
if ($path === '/api/admin/archive') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['error' => 'Нет прав']); exit; }
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $limit  = 20;
    $offset = $page * $limit;
    $total  = (int)$pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();
    $stmt   = $pdo->prepare("SELECT * FROM bot_archive ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute();
    echo json_encode(['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page]);
    exit;
}

# =========================
# API ADMIN — список манги для редактирования
# =========================
if ($path === '/api/admin/manga-list') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['error' => 'Нет прав']); exit; }
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $q      = trim($_GET['q'] ?? '');
    $limit  = 10;
    $offset = $page * $limit;
    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title, cover_imgbb_url, created_at, likes, is_series FROM manga WHERE title ILIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$q%"]);
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title ILIKE ?");
        $cStmt->execute(["%$q%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, cover_imgbb_url, created_at, likes, is_series FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
        $cStmt = $pdo->query("SELECT COUNT(*) FROM manga");
    }
    echo json_encode(['items' => $stmt->fetchAll(), 'total' => (int)$cStmt->fetchColumn()]);
    exit;
}

# =========================
# API ADMIN — получить одну мангу для редактирования
# =========================
if (preg_match('#^/api/admin/manga/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['error' => 'Нет прав']); exit; }
    $mId  = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
    $stmt->execute([$mId]);
    $manga = $stmt->fetch();
    if (!$manga) { echo json_encode(['error' => 'Не найдено']); exit; }
    // Добавляем главы если серия
    $chapters = [];
    if ($manga['is_series']) {
        $chStmt = $pdo->prepare("SELECT id, chapter_num, title, created_at FROM manga_chapters WHERE manga_id = ? ORDER BY chapter_num ASC");
        $chStmt->execute([$mId]);
        $chapters = $chStmt->fetchAll();
    }
    $manga['chapters'] = $chapters;
    echo json_encode($manga);
    exit;
}

# =========================
# API ADMIN — обновить мангу
# =========================
if (preg_match('#^/api/admin/manga/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['error' => 'Нет прав']); exit; }
    $mId  = (int)$m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $allowed = ['title', 'description', 'telegraph_url', 'cover_imgbb_url'];
    $updates = [];
    $values  = [];
    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $values[]  = $input[$field];
        }
    }
    if (empty($updates)) { echo json_encode(['success' => false, 'error' => 'Нечего обновлять']); exit; }
    $values[] = $mId;
    $pdo->prepare("UPDATE manga SET " . implode(', ', $updates) . " WHERE id = ?")->execute($values);
    logArchiveEntry($pdo, 'edit_manga', "Отредактирована манга ID: $mId (через сайт)", $userId);
    echo json_encode(['success' => true]);
    exit;
}

# =========================
# API ADMIN — удалить мангу
# =========================
if (preg_match('#^/api/admin/manga/(\d+)/delete$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['error' => 'Нет прав']); exit; }
    $mId = (int)$m[1];
    $titleStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
    $titleStmt->execute([$mId]);
    $row = $titleStmt->fetch();
    $pdo->prepare("DELETE FROM manga WHERE id = ?")->execute([$mId]);
    $pdo->prepare("DELETE FROM manga_pages WHERE manga_id = ?")->execute([$mId]);
    $pdo->prepare("DELETE FROM manga_chapters WHERE manga_id = ?")->execute([$mId]);
    logArchiveEntry($pdo, 'delete_manga', "Удалена манга: " . ($row['title'] ?? "ID $mId"), $userId);
    echo json_encode(['success' => true]);
    exit;
}

# =========================
# API ADMIN — удалить главу
# =========================
if (preg_match('#^/api/admin/chapter/(\d+)/delete$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['error' => 'Нет прав']); exit; }
    $chId = (int)$m[1];
    $pdo->prepare("DELETE FROM manga_chapters WHERE id = ?")->execute([$chId]);
    $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id = ?")->execute([$chId]);
    logArchiveEntry($pdo, 'delete_chapter', "Удалена глава ID: $chId", $userId);
    echo json_encode(['success' => true]);
    exit;
}

# =========================
# VIEWER (РИДЕР) — обычная манга /view/ID
# =========================
if (preg_match('#^/view/(\d+)$#', $path, $m)) {
    $id   = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title, telegraph_url FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404 - Манга не найдена'); }
    $title        = htmlspecialchars($manga['title']);
    $telegraphUrl = htmlspecialchars($manga['telegraph_url'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#000;color:#fff;font-family:sans-serif;overflow:hidden}
.reader{height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0a;position:relative}
#page{max-width:100%;max-height:100vh;object-fit:contain;display:none;user-select:none;-webkit-user-drag:none}
.nav{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer;transition:background 0.2s}
.nav:hover{background:rgba(255,255,255,0.04)}
.prev{left:0}.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);padding:8px 20px;border-radius:999px;z-index:100;font-size:14px;pointer-events:none}
.back{position:fixed;top:16px;left:16px;z-index:200;color:#fff;text-decoration:none;background:rgba(0,0,0,0.7);padding:10px 18px;border-radius:30px;font-size:14px;border:1px solid rgba(255,255,255,0.1)}
.back:hover{background:rgba(124,92,255,0.6)}
#loading{position:absolute;color:#aaa;font-size:16px;text-align:center;padding:20px}
.fallback{position:absolute;text-align:center;display:none;padding:20px}
.fallback p{margin-bottom:16px;color:#aaa}
.telegraph-link{background:#7c5cff;color:#fff;padding:12px 24px;border-radius:40px;text-decoration:none;font-weight:600;display:inline-block}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<a href="/read/<?= $id ?>" class="back">← Назад</a>
<div class="counter"><span id="counter">—</span></div>
<div class="nav prev" onclick="prevPage()"></div>
<div class="nav next" onclick="nextPage()"></div>
<div class="reader">
    <div id="loading">📖 Загрузка страниц...</div>
    <img id="page" alt="Страница манги">
    <div class="fallback" id="fallback">
        <p>❌ Страницы не найдены</p>
        <?php if ($telegraphUrl): ?><a href="<?= $telegraphUrl ?>" target="_blank" class="telegraph-link">📄 Читать в Telegraph</a><?php endif; ?>
    </div>
</div>
<script>
let pages = [], current = 0;
const loadingEl = document.getElementById('loading');
const pageEl    = document.getElementById('page');
const fallbackEl= document.getElementById('fallback');
const counterEl = document.getElementById('counter');
const mangaId   = <?= $id ?>;

function getTgUser() {
    try { if(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initDataUnsafe&&window.Telegram.WebApp.initDataUnsafe.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;} } catch(e){}
    const p=new URLSearchParams(window.location.search);const u=p.get('tg_user_id');if(u)return u;
    const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';
}
function getSavedPage(){try{return parseInt(localStorage.getItem('progress_'+mangaId)||'0');}catch(e){return 0;}}
function saveProgress(p){
    try{localStorage.setItem('progress_'+mangaId,p);}catch(e){}
    fetch('/api/progress',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,page_num:p+1,total_pages:pages.length,tg_user_id:getTgUser()})}).catch(()=>{});
}
async function init(){
    try{
        const res=await fetch('/api/pages/<?= $id ?>');
        const data=await res.json();
        pages=data.pages||[];
        if(pages.length===0){loadingEl.style.display='none';fallbackEl.style.display='block';return;}
        loadingEl.style.display='none';
        current=getSavedPage();if(current>=pages.length)current=0;
        render();
    }catch(e){loadingEl.style.display='none';fallbackEl.style.display='block';}
}
function render(){
    if(!pages[current])return;
    pageEl.style.display='none';
    const img=new Image();
    img.onload=()=>{pageEl.src=pages[current];pageEl.style.display='block';counterEl.innerText=(current+1)+' / '+pages.length;};
    img.onerror=()=>{if(current<pages.length-1){current++;render();}else{fallbackEl.style.display='block';}};
    img.src=pages[current];
    counterEl.innerText=(current+1)+' / '+pages.length;
}
function nextPage(){if(current<pages.length-1){current++;render();saveProgress(current);}}
function prevPage(){if(current>0){current--;render();saveProgress(current);}}
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowDown')nextPage();if(e.key==='ArrowLeft'||e.key==='ArrowUp')prevPage();});
let tx=0,ty=0;
document.addEventListener('touchstart',e=>{tx=e.changedTouches[0].screenX;ty=e.changedTouches[0].screenY;},{passive:true});
document.addEventListener('touchend',e=>{const dx=e.changedTouches[0].screenX-tx;const dy=e.changedTouches[0].screenY-ty;if(Math.abs(dx)>Math.abs(dy)&&Math.abs(dx)>40){if(dx<0)nextPage();else prevPage();}},{passive:true});
init();
</script>
</body>
</html>
<?php exit; }

# =========================
# CHAPTER VIEWER — /view-chapter/ID
# =========================
if (preg_match('#^/view-chapter/(\d+)$#', $path, $m)) {
    $chapterId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT mc.*, m.title as manga_title, m.id as manga_id FROM manga_chapters mc JOIN manga m ON mc.manga_id = m.id WHERE mc.id = ?");
    $stmt->execute([$chapterId]);
    $chapter = $stmt->fetch();
    if (!$chapter) { http_response_code(404); die('404 - Глава не найдена'); }
    // Получаем соседние главы
    $prevCh = $pdo->prepare("SELECT id, chapter_num FROM manga_chapters WHERE manga_id = ? AND chapter_num < ? ORDER BY chapter_num DESC LIMIT 1");
    $prevCh->execute([$chapter['manga_id'], $chapter['chapter_num']]);
    $prevChapter = $prevCh->fetch();
    $nextCh = $pdo->prepare("SELECT id, chapter_num FROM manga_chapters WHERE manga_id = ? AND chapter_num > ? ORDER BY chapter_num ASC LIMIT 1");
    $nextCh->execute([$chapter['manga_id'], $chapter['chapter_num']]);
    $nextChapter = $nextCh->fetch();
    $chTitle = htmlspecialchars($chapter['manga_title'] . ' — Глава ' . $chapter['chapter_num'] . ($chapter['title'] ? ': ' . $chapter['title'] : ''));
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $chTitle ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#000;color:#fff;font-family:sans-serif;overflow:hidden}
.reader{height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0a}
#page{max-width:100%;max-height:100vh;object-fit:contain;display:none;user-select:none}
.nav{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer;transition:background 0.2s}
.nav:hover{background:rgba(255,255,255,0.04)}
.prev{left:0}.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);padding:8px 20px;border-radius:999px;z-index:100;font-size:14px;pointer-events:none}
.top-bar{position:fixed;top:0;left:0;right:0;z-index:200;display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:linear-gradient(180deg,rgba(0,0,0,0.8) 0%,transparent 100%)}
.back{color:#fff;text-decoration:none;background:rgba(0,0,0,0.7);padding:8px 16px;border-radius:30px;font-size:13px;border:1px solid rgba(255,255,255,0.1)}
.back:hover{background:rgba(124,92,255,0.6)}
.ch-nav{display:flex;gap:8px}
.ch-nav a{color:#fff;text-decoration:none;background:rgba(124,92,255,0.5);padding:8px 14px;border-radius:20px;font-size:12px;font-weight:600;transition:all 0.2s}
.ch-nav a:hover{background:rgba(124,92,255,0.8)}
.ch-nav a.disabled{opacity:0.3;pointer-events:none}
#loading{position:absolute;color:#aaa;font-size:16px;text-align:center;padding:20px}
.fallback{position:absolute;text-align:center;display:none;padding:20px}
.chapter-end{position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:300;display:none;align-items:center;justify-content:center;flex-direction:column;gap:20px;text-align:center;padding:24px}
.chapter-end h2{font-size:24px;font-weight:800}
.chapter-end p{color:#aaa;font-size:14px}
.end-btn{padding:14px 28px;border-radius:50px;border:none;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.end-next{background:#7c5cff;color:#fff}
.end-back{background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2)}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<div class="top-bar">
    <a href="/read/<?= $chapter['manga_id'] ?>" class="back">← К манге</a>
    <div class="ch-nav">
        <?php if ($prevChapter): ?>
            <a href="/view-chapter/<?= $prevChapter['id'] ?>">← Гл. <?= $prevChapter['chapter_num'] ?></a>
        <?php else: ?>
            <a class="disabled">← Нет</a>
        <?php endif; ?>
        <?php if ($nextChapter): ?>
            <a href="/view-chapter/<?= $nextChapter['id'] ?>">Гл. <?= $nextChapter['chapter_num'] ?> →</a>
        <?php else: ?>
            <a class="disabled">Нет →</a>
        <?php endif; ?>
    </div>
</div>
<div class="counter"><span id="counter">—</span></div>
<div class="nav prev" onclick="prevPage()"></div>
<div class="nav next" onclick="nextPage()"></div>
<div class="reader">
    <div id="loading">📖 Загрузка главы...</div>
    <img id="page" alt="Страница">
    <div class="fallback" id="fallback"><p>❌ Страницы не найдены</p></div>
</div>
<div class="chapter-end" id="chapter-end">
    <h2>🎉 Глава завершена!</h2>
    <p>Глава <?= $chapter['chapter_num'] ?><?= $chapter['title'] ? ': ' . htmlspecialchars($chapter['title']) : '' ?></p>
    <?php if ($nextChapter): ?>
        <a class="end-btn end-next" href="/view-chapter/<?= $nextChapter['id'] ?>">▶ Читать главу <?= $nextChapter['chapter_num'] ?></a>
    <?php else: ?>
        <p style="color:#7c5cff;font-weight:600">✅ Это последняя глава</p>
    <?php endif; ?>
    <a class="end-btn end-back" href="/read/<?= $chapter['manga_id'] ?>">← К информации о манге</a>
</div>
<script>
let pages=[], current=0;
const loadingEl=document.getElementById('loading');
const pageEl=document.getElementById('page');
const fallbackEl=document.getElementById('fallback');
const counterEl=document.getElementById('counter');
const endScreen=document.getElementById('chapter-end');
const mangaId=<?= $chapter['manga_id'] ?>;
const chapterId=<?= $chapterId ?>;

function getTgUser(){try{if(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initDataUnsafe&&window.Telegram.WebApp.initDataUnsafe.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(window.location.search);const u=p.get('tg_user_id');if(u)return u;const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function saveProgress(p){fetch('/api/progress',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,chapter_id:chapterId,page_num:p+1,total_pages:pages.length,tg_user_id:getTgUser()})}).catch(()=>{});}
async function init(){
    try{
        const res=await fetch('/api/chapter-pages/<?= $chapterId ?>');
        const data=await res.json();
        pages=data.pages||[];
        if(!pages.length){loadingEl.style.display='none';fallbackEl.style.display='block';return;}
        loadingEl.style.display='none';
        render();
    }catch(e){loadingEl.style.display='none';fallbackEl.style.display='block';}
}
function render(){
    if(!pages[current])return;
    pageEl.style.display='none';
    const img=new Image();
    img.onload=()=>{pageEl.src=pages[current];pageEl.style.display='block';counterEl.innerText=(current+1)+' / '+pages.length;};
    img.onerror=()=>{if(current<pages.length-1){current++;render();}else{showEnd();}};
    img.src=pages[current];
    counterEl.innerText=(current+1)+' / '+pages.length;
}
function nextPage(){
    if(current<pages.length-1){current++;render();saveProgress(current);}
    else{showEnd();}
}
function prevPage(){if(current>0){current--;render();saveProgress(current);}}
function showEnd(){endScreen.style.display='flex';saveProgress(pages.length-1);}
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowDown')nextPage();if(e.key==='ArrowLeft'||e.key==='ArrowUp')prevPage();});
let tx=0,ty=0;
document.addEventListener('touchstart',e=>{tx=e.changedTouches[0].screenX;ty=e.changedTouches[0].screenY;},{passive:true});
document.addEventListener('touchend',e=>{const dx=e.changedTouches[0].screenX-tx;const dy=e.changedTouches[0].screenY-ty;if(Math.abs(dx)>Math.abs(dy)&&Math.abs(dx)>40){if(dx<0)nextPage();else prevPage();}},{passive:true});
init();
</script>
</body>
</html>
<?php exit; }

# =========================
# MANGA PAGE (карточка) — /read/ID
# =========================
if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $id   = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, file_id, telegraph_url, likes, dislikes, is_series FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404 - Манга не найдена'); }

    $userId = getEffectiveUserId($pdo);
    $stmtStatus = $pdo->prepare("SELECT status FROM user_manga_status WHERE user_id = ? AND manga_id = ?");
    $stmtStatus->execute([$userId, $id]);
    $currentStatus = $stmtStatus->fetchColumn() ?: '';

    $pagesCount = 0;
    if (!$manga['is_series']) {
        $pagesStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_pages WHERE manga_id = ? AND page_url IS NOT NULL AND page_url != ''");
        $pagesStmt->execute([$id]);
        $pagesCount = (int)$pagesStmt->fetchColumn();
    }

    $coverSrc = !empty($manga['cover_imgbb_url']) ? htmlspecialchars($manga['cover_imgbb_url']) : (!empty($manga['file_id']) ? '/api/cover/' . htmlspecialchars($manga['file_id']) : '');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($manga['title']) ?> | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--accent:#7c5cff;--muted:#8e8ea0}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh}
.back{display:inline-flex;align-items:center;gap:8px;margin:20px 24px;color:var(--muted);text-decoration:none;font-size:14px;transition:color 0.2s}
.back:hover{color:var(--accent)}
.manga-page{max-width:800px;margin:0 auto;padding:0 20px 60px}
.hero{display:flex;gap:28px;margin-bottom:32px;flex-wrap:wrap}
.cover-wrap{flex-shrink:0;width:180px}
.cover-img{width:100%;border-radius:18px;aspect-ratio:2/3;object-fit:cover;box-shadow:0 8px 32px rgba(0,0,0,0.6)}
.cover-ph{width:100%;aspect-ratio:2/3;border-radius:18px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a);font-size:60px}
.meta{flex:1;min-width:200px;display:flex;flex-direction:column;gap:12px}
.manga-title{font-size:24px;font-weight:800;line-height:1.3}
.manga-badges{display:flex;gap:8px;flex-wrap:wrap}
.badge-series{background:rgba(124,92,255,0.2);color:var(--accent);border:1px solid rgba(124,92,255,0.4);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700}
.manga-desc{color:var(--muted);font-size:14px;line-height:1.7}
.vote-row{display:flex;gap:12px;align-items:center}
.vote-btn{padding:9px 20px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--text);font-size:14px;cursor:pointer;font-family:inherit;transition:all 0.2s}
.vote-btn:hover{border-color:var(--accent)}
.vote-btn.like:hover,.vote-btn.like.active{background:rgba(76,175,80,0.15);border-color:#4caf50;color:#4caf50}
.vote-btn.dislike:hover,.vote-btn.dislike.active{background:rgba(244,67,54,0.15);border-color:#f44336;color:#f44336}
.status-row{display:flex;gap:8px;flex-wrap:wrap}
.status-btn{padding:9px 16px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit;font-weight:600;transition:all 0.2s}
.status-btn:hover{border-color:var(--accent);color:var(--text)}
.active-now{background:rgba(255,165,0,0.15);border-color:#ffa500;color:#ffa500}
.active-will{background:rgba(124,92,255,0.15);border-color:var(--accent);color:var(--accent)}
.active-read{background:rgba(76,175,80,0.15);border-color:#4caf50;color:#4caf50}
.read-section{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:24px;margin-bottom:20px}
.read-section h3{font-size:16px;font-weight:700;margin-bottom:16px;color:#fff}
.read-btn{display:inline-flex;align-items:center;gap:10px;padding:14px 28px;border-radius:50px;text-decoration:none;font-weight:700;font-size:15px;transition:all 0.2s;margin-right:10px;margin-bottom:10px}
.read-primary{background:var(--accent);color:#fff}
.read-primary:hover{background:#6a4ee0;transform:translateY(-2px)}
.read-secondary{background:rgba(255,255,255,0.06);color:var(--text);border:1px solid var(--border)}
.read-secondary:hover{border-color:var(--accent);color:var(--accent)}
/* Список глав */
.chapters-list{display:flex;flex-direction:column;gap:8px;max-height:400px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.chapter-item{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:12px;text-decoration:none;color:var(--text);transition:all 0.2s}
.chapter-item:hover{border-color:var(--accent);background:rgba(124,92,255,0.07);transform:translateX(4px)}
.ch-num{font-weight:700;font-size:14px}
.ch-title{font-size:13px;color:var(--muted);margin-left:8px}
.ch-date{font-size:11px;color:var(--muted)}
.ch-pages{font-size:11px;color:var(--muted);background:var(--card);border:1px solid var(--border);padding:2px 8px;border-radius:10px}
.no-chapters{text-align:center;padding:40px 20px;color:var(--muted)}
.toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:rgba(124,92,255,0.95);color:#fff;padding:12px 24px;border-radius:50px;font-size:14px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(124,92,255,0.4);animation:toastIn 0.3s ease}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<a href="/" class="back">← Каталог</a>
<div class="manga-page">
    <div class="hero">
        <div class="cover-wrap">
            <?php if ($coverSrc): ?>
                <img class="cover-img" src="<?= $coverSrc ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="cover-ph" style="display:none">📖</div>
            <?php else: ?>
                <div class="cover-ph">📖</div>
            <?php endif; ?>
        </div>
        <div class="meta">
            <div class="manga-title"><?= htmlspecialchars($manga['title']) ?></div>
            <div class="manga-badges">
                <?php if ($manga['is_series']): ?>
                    <span class="badge-series">📚 Серия глав</span>
                <?php endif; ?>
            </div>
            <?php if ($manga['description']): ?>
                <div class="manga-desc"><?= nl2br(htmlspecialchars($manga['description'])) ?></div>
            <?php endif; ?>
            <div class="vote-row">
                <button class="vote-btn like <?= $currentStatus ? '' : '' ?>" onclick="vote('like')">👍 <span id="likes"><?= (int)$manga['likes'] ?></span></button>
                <button class="vote-btn dislike" onclick="vote('dislike')">👎 <span id="dislikes"><?= (int)$manga['dislikes'] ?></span></button>
            </div>
            <div class="status-row">
                <button class="status-btn <?= $currentStatus === 'now'  ? 'active-now'  : '' ?>" id="btn-now"  onclick="setStatus('now')">📖 Читаю</button>
                <button class="status-btn <?= $currentStatus === 'will' ? 'active-will' : '' ?>" id="btn-will" onclick="setStatus('will')">🔖 Буду читать</button>
                <button class="status-btn <?= $currentStatus === 'read' ? 'active-read' : '' ?>" id="btn-read" onclick="setStatus('read')">✅ Прочитано</button>
            </div>
        </div>
    </div>

    <?php if ($manga['is_series']): ?>
    <div class="read-section">
        <h3>📚 Главы</h3>
        <div class="chapters-list" id="chapters-list">
            <div class="no-chapters">⏳ Загрузка глав...</div>
        </div>
    </div>
    <?php else: ?>
    <div class="read-section">
        <h3>📖 Читать мангу</h3>
        <?php if ($pagesCount > 0 || $manga['telegraph_url']): ?>
            <?php if ($pagesCount > 0): ?>
                <a href="/view/<?= $id ?>" class="read-btn read-primary">📖 Читать на сайте</a>
            <?php endif; ?>
            <?php if ($manga['telegraph_url']): ?>
                <a href="<?= htmlspecialchars($manga['telegraph_url']) ?>" target="_blank" class="read-btn read-secondary">📄 Открыть в Telegraph</a>
            <?php endif; ?>
        <?php else: ?>
            <p style="color:var(--muted)">Страницы пока не загружены</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function getTgUser(){try{if(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initDataUnsafe&&window.Telegram.WebApp.initDataUnsafe.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(window.location.search);const u=p.get('tg_user_id');if(u)return u;const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}

<?php if ($manga['is_series']): ?>
async function loadChapters() {
    try {
        const res  = await fetch('/api/chapters/<?= $id ?>');
        const data = await res.json();
        const list = document.getElementById('chapters-list');
        if (!data.chapters || !data.chapters.length) {
            list.innerHTML = '<div class="no-chapters">📭 Глав пока нет</div>';
            return;
        }
        list.innerHTML = data.chapters.map(ch => {
            const chTitle = ch.title ? `<span class="ch-title">${escHtml(ch.title)}</span>` : '';
            const pagesLabel = ch.page_count > 0 ? `<span class="ch-pages">${ch.page_count} стр.</span>` : '';
            const date = ch.created_at ? new Date(ch.created_at).toLocaleDateString('ru-RU') : '';
            return `<a class="chapter-item" href="/view-chapter/${ch.id}">
                <div style="display:flex;align-items:center;gap:4px">
                    <span class="ch-num">Глава ${ch.chapter_num}</span>${chTitle}
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    ${pagesLabel}
                    <span class="ch-date">${date}</span>
                </div>
            </a>`;
        }).join('');
    } catch(e) {
        document.getElementById('chapters-list').innerHTML = '<div class="no-chapters">❌ Ошибка загрузки</div>';
    }
}
loadChapters();
<?php endif; ?>

function escHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

async function vote(type){
    try{
        const res=await fetch('/api/vote',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:<?= $id ?>,vote_type:type,tg_user_id:getTgUser()})});
        const data=await res.json();
        if(data.success){document.getElementById('likes').innerText=data.likes;document.getElementById('dislikes').innerText=data.dislikes;showToast(type=='like'?'👍 Лайк учтён!':'👎 Дизлайк учтён!');}
    }catch(e){}
}

async function setStatus(status){
    try{
        const res=await fetch('/api/status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:<?= $id ?>,status:status,tg_user_id:getTgUser()})});
        const data=await res.json();
        if(data.success){
            ['now','will','read'].forEach(s=>document.getElementById('btn-'+s).className='status-btn');
            const cls={now:'active-now',will:'active-will',read:'active-read'};
            document.getElementById('btn-'+status).classList.add(cls[status]);
            const lbl={now:'📖 Отмечено: Читаю!',will:'🔖 Добавлено в список!',read:'✅ Отмечено как прочитанное!'};
            showToast(lbl[status]);
        }
    }catch(e){}
}

function showToast(msg){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className='toast';t.innerText=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2800);}
</script>
</body>
</html>
<?php exit; }

# =========================
# LIBRARY PAGE
# =========================
if ($path === '/library') {
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Моя библиотека | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--accent:#7c5cff;--muted:#8e8ea0}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif}
.header{padding:20px 24px;background:rgba(7,7,11,0.9);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px}
.logo{font-size:24px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.wrap{max-width:1200px;margin:auto;padding:30px 20px}
.back-link{display:inline-block;margin-bottom:24px;color:var(--accent);text-decoration:none;font-weight:600}
.section{margin-bottom:40px}
.section-title{font-size:18px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.3s}
.card:hover{transform:translateY(-4px);border-color:var(--accent)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.info{padding:12px}
.title{font-size:12px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:10px;margin-top:8px;font-weight:600}
.badge-now{background:rgba(255,165,0,0.2);color:#ffa500}
.badge-will{background:rgba(124,92,255,0.2);color:var(--accent)}
.badge-read{background:rgba(76,175,80,0.2);color:#4caf50}
.empty-page{text-align:center;padding:80px 20px;color:var(--muted)}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<div class="header"><div class="logo">📚 Моя библиотека</div></div>
<div class="wrap">
    <a href="/" class="back-link">← На главную</a>
    <div id="content"><div class="empty-page">📖 Загрузка...</div></div>
</div>
<script>
function getTgUser(){try{if(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initDataUnsafe&&window.Telegram.WebApp.initDataUnsafe.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(window.location.search);const u=p.get('tg_user_id');if(u){document.cookie='tg_user_id='+u+';max-age='+(86400*30)+';path=/';return u;}const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function cardHtml(m){
    const coverId='cv-'+m.id,phId='ph-'+m.id;
    let src=m.cover_imgbb_url||'';
    if(!src&&m.file_id)src='/api/cover/'+m.file_id;
    const imgHtml=src?`<img class="cover" id="${coverId}" src="${escapeHtml(src)}" loading="lazy" onerror="document.getElementById('${coverId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`:'';
    const phStyle=src?'display:none':'display:flex';
    const badgeMap={now:'badge-now',will:'badge-will',read:'badge-read'};
    const labelMap={now:'📖 Читаю',will:'🔖 Буду читать',read:'✅ Прочитано'};
    return `<a class="card" href="/read/${m.id}">${imgHtml}<div class="cover-ph" id="${phId}" style="${phStyle}"><span style="font-size:40px">📖</span></div><div class="info"><div class="title">${escapeHtml(m.title)}</div><span class="badge ${badgeMap[m.status]||''}">${labelMap[m.status]||''}</span></div></a>`;
}
function sectionHtml(icon,title,items){
    return `<div class="section"><div class="section-title"><span>${icon}</span>${title} <span style="color:var(--muted);font-size:14px;font-weight:400">(${items.length})</span></div><div class="grid">${items.length>0?items.map(cardHtml).join(''):'<div style="color:var(--muted);padding:16px 0">Список пуст</div>'}</div></div>`;
}
async function load(){
    try{
        const tgId=getTgUser();
        const res=await fetch('/api/library?tg_user_id='+tgId);
        const data=await res.json();
        const content=document.getElementById('content');
        if(!data.items||!data.items.length){content.innerHTML='<div class="empty-page">📭 У вас пока нет добавленной манги<br><br><a href="/" style="color:var(--accent)">Перейти в каталог →</a></div>';return;}
        const now=data.items.filter(i=>i.status==='now');
        const will=data.items.filter(i=>i.status==='will');
        const read=data.items.filter(i=>i.status==='read');
        let html='';
        if(now.length>0)html+=sectionHtml('📖','Читаю сейчас',now);
        if(will.length>0)html+=sectionHtml('🔖','Буду читать',will);
        if(read.length>0)html+=sectionHtml('✅','Прочитано',read);
        if(!html)html='<div class="empty-page">📭 Список пуст<br><br><a href="/" style="color:var(--accent)">Перейти в каталог →</a></div>';
        content.innerHTML=html;
    }catch(e){document.getElementById('content').innerHTML='<div class="empty-page">❌ Ошибка загрузки</div>';}
}
load();
</script>
</body>
</html>
<?php exit; }

# =========================
# HOME (КАТАЛОГ)
# =========================
$total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
$botUsername = getenv('BOT_USERNAME') ?: 'blackwatch_manga_bot';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BLACKWATCH | Manga Reader</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--muted:#8e8ea0;--accent:#7c5cff}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif}
header{position:sticky;top:0;z-index:100;backdrop-filter:blur(20px);background:rgba(7,7,11,0.92);border-bottom:1px solid var(--border);padding:14px 24px}
.header-inner{max-width:1400px;margin:auto;display:flex;flex-wrap:wrap;align-items:center;gap:12px}
.logo{font-size:24px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;white-space:nowrap;text-decoration:none}
.search{flex:1;min-width:180px;max-width:420px;padding:11px 20px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:var(--text);font-size:14px;font-family:inherit}
.search:focus{outline:none;border-color:var(--accent)}
.search::placeholder{color:var(--muted)}
.header-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-wrap:wrap}
.bot-link{display:flex;align-items:center;gap:7px;padding:9px 16px;background:rgba(124,92,255,0.12);border:1px solid rgba(124,92,255,0.35);border-radius:50px;color:var(--accent);text-decoration:none;font-size:13px;font-weight:600;transition:all 0.2s;white-space:nowrap}
.bot-link:hover{background:rgba(124,92,255,0.22)}
.lib-btn{display:flex;align-items:center;gap:7px;padding:9px 18px;background:var(--accent);border:none;border-radius:50px;color:#fff;text-decoration:none;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;white-space:nowrap;font-family:inherit}
.lib-btn:hover{background:#6a4ee0;transform:translateY(-1px)}
.random-btn{display:flex;align-items:center;gap:7px;padding:9px 16px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:50px;color:var(--text);text-decoration:none;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s;white-space:nowrap;font-family:inherit}
.random-btn:hover{border-color:var(--accent);color:var(--accent)}
.admin-btn{display:none;align-items:center;gap:7px;padding:9px 18px;background:linear-gradient(135deg,#ff6b35,#f7c59f);border:none;border-radius:50px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;white-space:nowrap;font-family:inherit}
.admin-btn:hover{opacity:0.88;transform:translateY(-1px)}
.admin-btn.visible{display:flex}
.add-manga-btn{display:none;align-items:center;gap:7px;padding:9px 18px;background:linear-gradient(135deg,#00c853,#00897b);border:none;border-radius:50px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;white-space:nowrap;font-family:inherit}
.add-manga-btn:hover{opacity:0.88;transform:translateY(-1px)}
.add-manga-btn.visible{display:flex}
.wrap{max-width:1400px;margin:auto;padding:24px 20px}

/* НОВИНКИ */
.new-section{background:linear-gradient(135deg,rgba(124,92,255,0.08) 0%,rgba(16,16,24,0.9) 100%);border:1px solid rgba(124,92,255,0.25);border-radius:22px;padding:22px 22px 18px;margin-bottom:28px;position:relative;overflow:hidden}
.new-section::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),transparent)}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.section-title-row{display:flex;align-items:center;gap:10px}
.section-label{font-size:17px;font-weight:800;color:#fff}
.slider-wrap{position:relative}
.slider-track-outer{overflow:hidden;border-radius:14px}
.slider-track{display:flex;gap:14px;transition:transform 0.35s cubic-bezier(.4,0,.2,1);will-change:transform}
.slider-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:10;width:36px;height:36px;border-radius:50%;background:rgba(7,7,11,0.92);border:1px solid var(--border);color:#fff;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;user-select:none}
.slider-arrow:hover{background:var(--accent);border-color:var(--accent)}
.slider-arrow.left{left:-14px}.slider-arrow.right{right:-14px}
.slider-arrow.hidden{opacity:0;pointer-events:none}
.slide-card{flex:0 0 140px;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.25s;position:relative}
.slide-card:hover{transform:translateY(-4px);border-color:var(--accent)}
.slide-cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}
.slide-cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.slide-info{padding:10px}
.slide-title{font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4}
.new-badge{position:absolute;top:7px;right:7px;background:linear-gradient(135deg,#ff4d6d,#ff6b35);color:#fff;font-size:9px;font-weight:800;padding:3px 7px;border-radius:8px;text-transform:uppercase}

/* ПРОДОЛЖИТЬ */
.continue-section{background:var(--card);border:1px solid var(--border);border-radius:22px;padding:20px 24px;margin-bottom:28px;position:relative;overflow:hidden}
.continue-section::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,#ffa500,#ff6b35,transparent)}
.cont-list{display:flex;gap:12px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
.cont-list::-webkit-scrollbar{display:none}
.cont-card{flex:0 0 240px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:14px;overflow:hidden;text-decoration:none;color:var(--text);display:flex;transition:all 0.25s}
.cont-card:hover{border-color:#ffa500;transform:translateY(-2px)}
.cont-cover-wrap{width:60px;flex-shrink:0}
.cont-cover{width:100%;height:100%;object-fit:cover;display:block;min-height:90px}
.cont-cover-ph{width:100%;min-height:90px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.cont-body{flex:1;padding:10px 12px;display:flex;flex-direction:column;justify-content:space-between;min-width:0}
.cont-title{font-size:12px;font-weight:700;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;margin-bottom:6px}
.cont-bar-bg{height:3px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden;margin-bottom:8px}
.cont-bar-fill{height:100%;background:linear-gradient(90deg,#ffa500,#ff6b35);border-radius:2px;transition:width 0.4s}
.cont-btn{display:inline-block;padding:5px 12px;background:rgba(255,165,0,0.15);border:1px solid rgba(255,165,0,0.4);border-radius:20px;color:#ffa500;font-size:10px;font-weight:700}

/* FILTERS */
.filters{display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap}
.filter-btn{padding:8px 18px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s;font-family:inherit}
.filter-btn:hover{border-color:var(--accent);color:var(--text)}
.filter-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}
.stats{color:var(--muted);font-size:13px;margin-left:auto}

/* GRID */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:20px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.3s;position:relative}
.card:hover{transform:translateY(-6px);border-color:var(--accent);box-shadow:0 8px 30px rgba(124,92,255,0.15)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.info{padding:14px}
.title{font-size:13px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4}
.likes{margin-top:8px;color:#ffd166;font-size:12px}
.card-badges{display:flex;gap:4px;margin-top:6px;flex-wrap:wrap}
.card-new-badge{position:absolute;top:8px;left:8px;background:linear-gradient(135deg,#ff4d6d,#ff6b35);color:#fff;font-size:9px;font-weight:800;padding:3px 8px;border-radius:8px;text-transform:uppercase}
.card-series-badge{background:rgba(124,92,255,0.2);color:var(--accent);border:1px solid rgba(124,92,255,0.3);padding:3px 8px;border-radius:10px;font-size:10px;font-weight:600}
.load-more{margin:40px auto;display:block;padding:14px 36px;background:var(--accent);color:#fff;border:none;border-radius:50px;cursor:pointer;font-size:15px;font-weight:600;font-family:inherit;transition:all 0.2s}
.load-more:hover{background:#6a4ee0;transform:translateY(-2px)}
.empty{text-align:center;padding:80px 20px;color:var(--muted)}

/* ===== MODAL ADD MANGA ===== */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:28px;width:100%;max-width:620px;max-height:92vh;overflow-y:auto;padding:32px;position:relative;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.modal::-webkit-scrollbar{width:5px}
.modal::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}
.modal-close{position:absolute;top:18px;right:18px;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,0.06);border:1px solid var(--border);color:var(--muted);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s}
.modal-close:hover{background:rgba(255,80,80,0.15);color:#ff5050;border-color:#ff5050}
.modal-title{font-size:22px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:8px}
.modal-subtitle{color:var(--muted);font-size:13px;margin-bottom:24px}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px}
.form-input,.form-textarea,.form-select{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:14px;color:var(--text);font-family:'Inter',sans-serif;font-size:14px;padding:12px 16px;transition:border-color 0.2s;resize:none}
.form-input:focus,.form-textarea:focus,.form-select:focus{outline:none;border-color:var(--accent)}
.form-textarea{min-height:80px}
.form-select{cursor:pointer}
.form-select option{background:#1a1a2e;color:#fff}
.toggle-row{display:flex;gap:8px;margin-bottom:18px}
.toggle-btn{flex:1;padding:10px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:12px;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all 0.2s}
.toggle-btn.active{background:rgba(124,92,255,0.15);border-color:var(--accent);color:var(--accent)}
.upload-zone{border:2px dashed var(--border);border-radius:16px;padding:24px;text-align:center;cursor:pointer;transition:all 0.2s;position:relative}
.upload-zone:hover,.upload-zone.drag{border-color:var(--accent);background:rgba(124,92,255,0.05)}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-icon{font-size:32px;margin-bottom:8px}
.upload-text{font-size:13px;color:var(--muted);line-height:1.5}
.upload-preview{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px;justify-content:center}
.preview-img{width:60px;height:80px;object-fit:cover;border-radius:8px}
.preview-count{font-size:13px;font-weight:600;color:var(--accent);padding:8px;background:rgba(124,92,255,0.1);border-radius:10px;text-align:center;width:100%}
.file-tabs{display:flex;gap:8px;margin-bottom:16px}
.file-tab{flex:1;padding:9px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:12px;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all 0.2s;text-align:center}
.file-tab.active{background:rgba(124,92,255,0.15);border-color:var(--accent);color:var(--accent)}
.file-panel{display:none}.file-panel.active{display:block}
.upload-progress{height:6px;background:rgba(255,255,255,0.06);border-radius:3px;overflow:hidden;margin-bottom:20px;display:none}
.upload-progress.active{display:block}
.upload-progress-fill{height:100%;background:linear-gradient(90deg,var(--accent),#a78bfa);border-radius:3px;width:0;transition:width 0.3s}
.submit-btn{width:100%;padding:16px;background:linear-gradient(135deg,var(--accent),#a78bfa);color:#fff;border:none;border-radius:16px;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit;transition:all 0.2s;display:flex;align-items:center;justify-content:center;gap:10px}
.submit-btn:hover:not(:disabled){opacity:0.88;transform:translateY(-1px)}
.submit-btn:disabled{opacity:0.5;cursor:not-allowed}
.submit-btn.loading .btn-text{opacity:0.7}
.spinner{width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;display:none}
.submit-btn.loading .spinner{display:block}
.result-banner{border-radius:14px;padding:0;max-height:0;overflow:hidden;transition:all 0.3s;font-size:14px;line-height:1.6}
.result-banner.open{padding:14px 18px;max-height:300px;margin-top:16px}
.result-banner.success{background:rgba(76,175,80,0.12);border:1px solid rgba(76,175,80,0.4);color:#81c784}
.result-banner.error{background:rgba(244,67,54,0.12);border:1px solid rgba(244,67,54,0.4);color:#ef9a9a}
.result-banner a{color:inherit;font-weight:700}
@keyframes spin{to{transform:rotate(360deg)}}

/* ===== ADMIN PANEL MODAL ===== */
.admin-modal{max-width:900px}
.admin-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:24px}
.admin-tab{padding:12px 20px;background:transparent;border:none;color:var(--muted);font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all 0.2s}
.admin-tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.admin-tab:hover{color:var(--text)}
.admin-panel{display:none}
.admin-panel.active{display:block}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.stat-card{background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:14px;padding:16px;text-align:center}
.stat-num{font-size:28px;font-weight:800;color:var(--accent)}
.stat-label{font-size:12px;color:var(--muted);margin-top:4px}
.archive-list{display:flex;flex-direction:column;gap:8px;max-height:400px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.archive-item{background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:12px 14px}
.archive-type{font-size:11px;font-weight:700;color:var(--accent);text-transform:uppercase;margin-bottom:4px}
.archive-text{font-size:13px;color:var(--text)}
.archive-date{font-size:11px;color:var(--muted);margin-top:4px}
.edit-search-row{display:flex;gap:8px;margin-bottom:16px}
.edit-search-input{flex:1;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:12px;color:var(--text);font-family:inherit;font-size:14px;padding:10px 14px}
.edit-search-input:focus{outline:none;border-color:var(--accent)}
.edit-search-btn{padding:10px 20px;background:var(--accent);border:none;border-radius:12px;color:#fff;font-weight:700;cursor:pointer;font-family:inherit;font-size:14px}
.manga-edit-list{display:flex;flex-direction:column;gap:8px;max-height:380px;overflow-y:auto;scrollbar-width:thin}
.manga-edit-item{display:flex;align-items:center;gap:12px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:12px;padding:10px 14px;cursor:pointer;transition:all 0.2s}
.manga-edit-item:hover{border-color:var(--accent)}
.manga-edit-cover{width:40px;height:56px;object-fit:cover;border-radius:8px;flex-shrink:0;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center}
.manga-edit-title{font-size:13px;font-weight:600;flex:1}
.manga-edit-meta{font-size:11px;color:var(--muted)}
/* Окно редактирования манги */
.edit-manga-form{background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:16px;padding:20px}
.edit-field{margin-bottom:16px}
.edit-field label{display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px}
.edit-field input,.edit-field textarea{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:inherit;font-size:13px;padding:10px 12px;transition:border-color 0.2s;resize:none}
.edit-field input:focus,.edit-field textarea:focus{outline:none;border-color:var(--accent)}
.edit-field textarea{min-height:80px}
.edit-actions{display:flex;gap:8px;flex-wrap:wrap}
.save-btn{padding:10px 24px;background:var(--accent);border:none;border-radius:12px;color:#fff;font-weight:700;cursor:pointer;font-family:inherit;font-size:14px;transition:all 0.2s}
.save-btn:hover{opacity:0.88}
.delete-btn{padding:10px 20px;background:rgba(244,67,54,0.12);border:1px solid rgba(244,67,54,0.4);border-radius:12px;color:#ef5350;font-weight:700;cursor:pointer;font-family:inherit;font-size:14px;transition:all 0.2s}
.delete-btn:hover{background:rgba(244,67,54,0.25)}
.back-edit-btn{padding:10px 18px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:12px;color:var(--muted);font-weight:600;cursor:pointer;font-family:inherit;font-size:14px}
.chapter-admin-list{display:flex;flex-direction:column;gap:6px;margin-top:12px}
.chapter-admin-item{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;padding:8px 12px}
.del-ch-btn{padding:4px 10px;background:rgba(244,67,54,0.1);border:1px solid rgba(244,67,54,0.3);border-radius:8px;color:#ef5350;font-size:11px;cursor:pointer;font-family:inherit}
/* add chapter form in admin */
.add-ch-form{background:rgba(124,92,255,0.05);border:1px solid rgba(124,92,255,0.2);border-radius:12px;padding:14px;margin-top:12px}
.add-ch-form h4{font-size:13px;font-weight:700;margin-bottom:12px;color:var(--accent)}
.ch-inputs{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.ch-inputs input{flex:1;min-width:100px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:inherit;font-size:13px;padding:9px 12px}
.ch-inputs input:focus{outline:none;border-color:var(--accent)}
.pagination{display:flex;gap:8px;margin-top:16px;justify-content:center}
.page-btn{padding:8px 16px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);cursor:pointer;font-family:inherit;font-size:13px}
.page-btn:hover{border-color:var(--accent);color:var(--accent)}
.page-btn:disabled{opacity:0.4;cursor:not-allowed}
.top-manga-list{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.top-manga-row{display:flex;justify-content:space-between;align-items:center;padding:8px 14px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px}
.top-manga-name{font-size:13px;font-weight:600}
.top-manga-likes{font-size:13px;color:#ffd166;font-weight:700}
.toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:rgba(124,92,255,0.95);color:#fff;padding:12px 24px;border-radius:50px;font-size:14px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(124,92,255,0.4);animation:toastIn 0.3s ease}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}

@media(max-width:600px){
    .header-inner{flex-wrap:wrap}
    .header-right{width:100%}
    .grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
    .stat-grid{grid-template-columns:repeat(2,1fr)}
    .hero{flex-direction:column}
    .admin-tabs{overflow-x:auto;flex-nowrap:nowrap;white-space:nowrap}
}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<header>
    <div class="header-inner">
        <a href="/" class="logo">⚫ BLACKWATCH</a>
        <input class="search" type="text" placeholder="🔍 Поиск манги..." id="search" oninput="onSearch(this.value)">
        <div class="header-right">
            <button class="random-btn" onclick="openRandom()">🎲 Случайная</button>
            <a href="/library" class="lib-btn">📚 Библиотека</a>
            <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank" class="bot-link">🤖 Бот</a>
            <button class="add-manga-btn" id="add-manga-btn" onclick="openAddModal()">➕ Добавить</button>
            <button class="admin-btn" id="admin-btn" onclick="openAdminPanel()">⚙️ Админ-панель</button>
        </div>
    </div>
</header>

<div class="wrap">
    <!-- НОВИНКИ -->
    <div class="new-section" id="new-section" style="display:none">
        <div class="section-header">
            <div class="section-title-row">
                <span style="font-size:20px">🔥</span>
                <span class="section-label">Новинки</span>
                <span class="section-count" id="new-count">0</span>
            </div>
        </div>
        <div class="slider-wrap">
            <div class="slider-arrow left hidden" id="sl-left" onclick="slideLeft()">‹</div>
            <div class="slider-track-outer">
                <div class="slider-track" id="slider-track"></div>
            </div>
            <div class="slider-arrow right hidden" id="sl-right" onclick="slideRight()">›</div>
        </div>
    </div>

    <!-- ПРОДОЛЖИТЬ ЧИТАТЬ -->
    <div class="continue-section" id="continue-section" style="display:none">
        <div class="section-header">
            <div class="section-title-row">
                <span style="font-size:20px">▶</span>
                <span class="section-label">Продолжить читать</span>
            </div>
        </div>
        <div class="cont-list" id="cont-list"></div>
    </div>

    <!-- FILTERS -->
    <div class="filters">
        <button class="filter-btn active" id="f-new"     onclick="setFilter('new')">🕒 Новые</button>
        <button class="filter-btn"        id="f-popular" onclick="setFilter('popular')">🔥 Популярные</button>
        <button class="filter-btn"        id="f-alpha"   onclick="setFilter('alpha')">🔤 А-Я</button>
        <span class="stats" id="stats">Манг: <strong><?= (int)$total ?></strong></span>
    </div>

    <!-- CATALOG -->
    <div class="grid" id="grid"></div>
    <button class="load-more" id="more" onclick="load()" style="display:none">Загрузить ещё</button>
</div>

<!-- MODAL: ADD MANGA -->
<div class="modal-overlay" id="add-modal" onclick="if(event.target===this)closeAddModal()">
<div class="modal">
    <button class="modal-close" onclick="closeAddModal()">✕</button>
    <div class="modal-title">➕ Добавить мангу</div>
    <p class="modal-subtitle">Заполни данные — обложка, страницы и информация о манге</p>

    <div class="form-group">
        <label class="form-label">Тип публикации</label>
        <div class="toggle-row">
            <button class="toggle-btn active" id="type-single" onclick="setMangaType('single')">📄 Обычная манга</button>
            <button class="toggle-btn" id="type-series" onclick="setMangaType('series')">📚 Серия / Тайтл</button>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Название</label>
        <input class="form-input" type="text" id="manga-title" placeholder="Название манги...">
    </div>
    <div class="form-group">
        <label class="form-label">Описание</label>
        <textarea class="form-textarea" id="manga-desc" placeholder="Краткое описание..."></textarea>
    </div>

    <!-- ОБЛОЖКА -->
    <div class="form-group">
        <label class="form-label">Обложка</label>
        <div class="upload-zone" id="cover-zone">
            <input type="file" id="cover-input" accept="image/*" onchange="onCoverChange(this)">
            <div class="upload-icon">🖼</div>
            <div class="upload-text"><strong>Загрузить обложку</strong><br>JPG, PNG, WebP</div>
            <div class="upload-preview" id="cover-preview"></div>
        </div>
    </div>

    <!-- СТРАНИЦЫ (только для обычной манги) -->
    <div id="pages-section">
        <div class="form-group">
            <label class="form-label">Страницы</label>
            <div class="file-tabs">
                <div class="file-tab active" id="tab-zip" onclick="switchTab('zip')">📦 ZIP-архив</div>
                <div class="file-tab" id="tab-photos" onclick="switchTab('photos')">📸 Фотографии</div>
            </div>
            <div class="file-panel active" id="panel-zip">
                <div class="upload-zone" id="zip-zone">
                    <input type="file" id="zip-input" accept=".zip" onchange="onZipChange(this)">
                    <div class="upload-icon">📦</div>
                    <div class="upload-text"><strong>ZIP-архив страниц</strong><br>Сортировка по дате добавления файлов<br><span style="font-size:11px;color:#555">(как в боте — поддерживается авто-сортировка)</span></div>
                    <div class="upload-preview" id="zip-preview"></div>
                </div>
            </div>
            <div class="file-panel" id="panel-photos">
                <div class="upload-zone" id="photos-zone">
                    <input type="file" id="photos-input" accept="image/*" multiple onchange="onPhotosChange(this)">
                    <div class="upload-icon">📸</div>
                    <div class="upload-text"><strong>Выбери страницы</strong><br>Файлы будут отсортированы по имени<br><span style="font-size:11px;color:#555">Для правильного порядка: 001.jpg, 002.jpg...</span></div>
                    <div class="upload-preview" id="photos-preview"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="upload-progress" id="upload-progress">
        <div class="upload-progress-fill" id="upload-progress-fill"></div>
    </div>

    <button class="submit-btn" id="submit-btn" onclick="submitManga()">
        <span class="btn-text">🚀 Опубликовать</span>
        <div class="spinner"></div>
    </button>
    <div class="result-banner" id="result-banner"></div>
</div>
</div>

<!-- MODAL: ADMIN PANEL -->
<div class="modal-overlay" id="admin-modal" onclick="if(event.target===this)closeAdminPanel()">
<div class="modal admin-modal">
    <button class="modal-close" onclick="closeAdminPanel()">✕</button>
    <div class="modal-title">⚙️ Админ-панель</div>
    <div class="admin-tabs">
        <button class="admin-tab active" onclick="switchAdminTab('stats')">📊 Статистика</button>
        <button class="admin-tab" onclick="switchAdminTab('archive')">📋 Архив</button>
        <button class="admin-tab" onclick="switchAdminTab('edit')">✏️ Редактирование</button>
        <button class="admin-tab" onclick="switchAdminTab('add-chapter')">📚 Добавить главу</button>
    </div>

    <!-- STATS -->
    <div class="admin-panel active" id="panel-stats">
        <div class="stat-grid" id="stat-grid"><div style="color:var(--muted)">Загрузка...</div></div>
        <div style="font-size:14px;font-weight:700;margin-bottom:8px;color:var(--muted)">ТОП по лайкам</div>
        <div class="top-manga-list" id="top-manga-list"></div>
    </div>

    <!-- ARCHIVE -->
    <div class="admin-panel" id="panel-archive">
        <div class="archive-list" id="archive-list"><div style="color:var(--muted)">Загрузка...</div></div>
        <div class="pagination" id="archive-pagination"></div>
    </div>

    <!-- EDIT -->
    <div class="admin-panel" id="panel-edit">
        <div class="edit-search-row">
            <input class="edit-search-input" id="edit-search-input" type="text" placeholder="🔍 Поиск манги...">
            <button class="edit-search-btn" onclick="searchMangaEdit()">Найти</button>
        </div>
        <div class="manga-edit-list" id="manga-edit-list"><div style="color:var(--muted);padding:16px 0">Введите название для поиска или оставьте пустым</div></div>
        <div class="pagination" id="edit-pagination"></div>
        <!-- Форма редактирования манги -->
        <div id="edit-manga-form-wrap" style="display:none">
            <button class="back-edit-btn" onclick="backToMangaList()" style="margin-bottom:16px">← Назад к списку</button>
            <div class="edit-manga-form" id="edit-manga-form"></div>
        </div>
    </div>

    <!-- ADD CHAPTER -->
    <div class="admin-panel" id="panel-add-chapter">
        <div style="margin-bottom:16px">
            <div class="form-group">
                <label class="form-label">Манга (серия)</label>
                <div class="edit-search-row">
                    <input class="edit-search-input" id="ch-manga-search" type="text" placeholder="Поиск тайтла...">
                    <button class="edit-search-btn" onclick="searchMangaForChapter()">Найти</button>
                </div>
                <div class="manga-edit-list" id="ch-manga-list" style="max-height:200px"><div style="color:var(--muted);padding:12px 0">Найдите серию выше</div></div>
            </div>
        </div>
        <div id="ch-add-form" style="display:none">
            <div class="add-ch-form">
                <h4>➕ Добавить новую главу</h4>
                <div class="ch-inputs">
                    <input type="number" id="ch-num" placeholder="Номер главы (1, 2, 2.5...)" step="0.1" min="0">
                    <input type="text" id="ch-title-input" placeholder="Название главы (необязательно)">
                </div>
                <div class="form-group">
                    <div class="file-tabs">
                        <div class="file-tab active" id="ch-tab-zip" onclick="switchChTab('zip')">📦 ZIP</div>
                        <div class="file-tab" id="ch-tab-photos" onclick="switchChTab('photos')">📸 Фото</div>
                    </div>
                    <div class="file-panel active" id="ch-panel-zip">
                        <div class="upload-zone" id="ch-zip-zone" style="padding:16px">
                            <input type="file" id="ch-zip-input" accept=".zip" onchange="onChZipChange(this)">
                            <div class="upload-icon" style="font-size:24px">📦</div>
                            <div class="upload-text" style="font-size:12px">ZIP с страницами</div>
                            <div class="upload-preview" id="ch-zip-preview"></div>
                        </div>
                    </div>
                    <div class="file-panel" id="ch-panel-photos">
                        <div class="upload-zone" id="ch-photos-zone" style="padding:16px">
                            <input type="file" id="ch-photos-input" accept="image/*" multiple onchange="onChPhotosChange(this)">
                            <div class="upload-icon" style="font-size:24px">📸</div>
                            <div class="upload-text" style="font-size:12px">Страницы главы</div>
                            <div class="upload-preview" id="ch-photos-preview"></div>
                        </div>
                    </div>
                </div>
                <div class="upload-progress" id="ch-upload-progress"><div class="upload-progress-fill" id="ch-upload-fill"></div></div>
                <button class="submit-btn" id="ch-submit-btn" onclick="submitChapter()" style="margin-top:8px">
                    <span class="btn-text">📤 Загрузить главу</span>
                    <div class="spinner"></div>
                </button>
                <div class="result-banner" id="ch-result-banner"></div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
// ===== INIT TG ID =====
(function(){
    try{if(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initDataUnsafe&&window.Telegram.WebApp.initDataUnsafe.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';}}catch(e){}
})();

function getTgUser(){
    try{if(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initDataUnsafe&&window.Telegram.WebApp.initDataUnsafe.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}
    const p=new URLSearchParams(window.location.search);const u=p.get('tg_user_id');if(u){document.cookie='tg_user_id='+u+';max-age='+(86400*30)+';path=/';return u;}
    const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';
}

function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function showToast(msg){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className='toast';t.innerText=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2800);}

// ===== ПРОВЕРКА АДМИНА =====
async function checkAdmin(){
    try{
        const tgId=getTgUser();
        const res=await fetch('/api/check-admin?tg_user_id='+tgId);
        const data=await res.json();
        if(data.is_admin){
            document.getElementById('add-manga-btn').classList.add('visible');
            document.getElementById('admin-btn').classList.add('visible');
        }
    }catch(e){}
}

// ===== КАТАЛОГ =====
let page=0,q='',loading=false,hasMore=true,currentSort='new';
const grid=document.getElementById('grid');
const moreBtn=document.getElementById('more');
const statsDiv=document.getElementById('stats');

function setFilter(sort){
    if(currentSort===sort)return;
    currentSort=sort;
    ['new','popular','alpha'].forEach(s=>document.getElementById('f-'+s).classList.toggle('active',s===sort));
    load(true);
}

let searchTimeout;
function onSearch(val){
    clearTimeout(searchTimeout);
    searchTimeout=setTimeout(()=>{q=val.trim();load(true);},350);
}

async function load(reset=false){
    if(loading)return;
    loading=true;
    if(reset){page=0;grid.innerHTML='';hasMore=true;moreBtn.style.display='none';}
    if(page===0&&grid.children.length===0)grid.innerHTML='<div class="empty">📖 Загрузка...</div>';
    try{
        const res=await fetch(`/api/manga?page=${page}&q=${encodeURIComponent(q)}&sort=${currentSort}`);
        const data=await res.json();
        if(page===0){
            grid.innerHTML='';
            statsDiv.innerHTML=q?`Найдено: <strong>${data.total}</strong>`:`Манг: <strong>${data.total}</strong>`;
        }
        if(data.items.length===0&&page===0){grid.innerHTML='<div class="empty">😔 Ничего не найдено</div>';loading=false;return;}
        data.items.forEach(m=>{
            const card=document.createElement('a');
            card.className='card';
            card.href='/read/'+m.id;
            const covId='cv'+m.id,phId='ph'+m.id;
            let src=m.cover_display||'';
            if(src.startsWith('tg://')){const fid=src.replace('tg://','');src='/api/cover/'+fid;}
            const seriesBadge=m.is_series?`<span class="card-series-badge">📚 Серия</span>`:'';
            card.innerHTML=`
                ${m.is_new?'<span class="card-new-badge">NEW</span>':''}
                ${src?`<img class="cover" id="${covId}" src="${escapeHtml(src)}" loading="lazy" alt="" onerror="document.getElementById('${covId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`:''}
                <div class="cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:40px">📖</span></div>
                <div class="info">
                    <div class="title">${escapeHtml(m.title)}</div>
                    <div class="likes">👍 ${m.likes}</div>
                    <div class="card-badges">${seriesBadge}${m.is_series&&m.chapter_count?`<span class="card-series-badge">${m.chapter_count} гл.</span>`:''}</div>
                </div>`;
            grid.appendChild(card);
        });
        hasMore=(page+1)*data.limit<data.total;
        moreBtn.style.display=hasMore?'block':'none';
        page++;
    }catch(e){if(page===0)grid.innerHTML='<div class="empty">❌ Ошибка загрузки</div>';}
    loading=false;
}

async function openRandom(){
    try{
        const res=await fetch('/api/random?tg_user_id='+getTgUser());
        const data=await res.json();
        if(data.id)window.location.href='/read/'+data.id;
    }catch(e){}
}

// ===== НОВИНКИ =====
let sliderItems=[],sliderPos=0;
async function loadNew(){
    try{
        const res=await fetch('/api/new-manga');
        const data=await res.json();
        if(!data.items||!data.items.length)return;
        const section=document.getElementById('new-section');
        section.style.display='block';
        document.getElementById('new-count').textContent=data.items.length;
        sliderItems=data.items;
        const track=document.getElementById('slider-track');
        track.innerHTML=data.items.map(m=>{
            const src=m.cover_display||'';
            return `<a class="slide-card" href="/read/${m.id}">
                <span class="new-badge">NEW</span>
                ${src?`<img class="slide-cover" src="${escapeHtml(src)}" loading="lazy" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">`:''}
                <div class="slide-cover-ph" style="${src?'display:none':'display:flex'}"><span style="font-size:30px">📖</span></div>
                <div class="slide-info"><div class="slide-title">${escapeHtml(m.title)}</div></div>
            </a>`;
        }).join('');
        updateSliderArrows();
    }catch(e){}
}

function slideLeft(){if(sliderPos>0){sliderPos--;updateSlider();}}
function slideRight(){const track=document.getElementById('slider-track');const maxPos=Math.max(0,sliderItems.length-Math.floor(track.parentElement.offsetWidth/154));if(sliderPos<maxPos){sliderPos++;updateSlider();}}
function updateSlider(){document.getElementById('slider-track').style.transform=`translateX(-${sliderPos*154}px)`;updateSliderArrows();}
function updateSliderArrows(){
    const track=document.getElementById('slider-track');
    const maxPos=Math.max(0,sliderItems.length-Math.floor(track.parentElement.offsetWidth/154));
    document.getElementById('sl-left').classList.toggle('hidden',sliderPos<=0);
    document.getElementById('sl-right').classList.toggle('hidden',sliderPos>=maxPos);
}

// ===== ПРОДОЛЖИТЬ ЧИТАТЬ =====
async function loadContinue(){
    try{
        const tgId=getTgUser();
        if(!tgId)return;
        const res=await fetch('/api/progress?tg_user_id='+tgId);
        const data=await res.json();
        if(!data.items||!data.items.length)return;
        const section=document.getElementById('continue-section');
        section.style.display='block';
        const list=document.getElementById('cont-list');
        list.innerHTML=data.items.map(m=>{
            const pct=m.total_pages>0?Math.round(m.page_num/m.total_pages*100):0;
            const covId='cc'+m.id,phId='cp'+m.id;
            let src=m.cover_imgbb_url||'';if(!src&&m.file_id)src='/api/cover/'+m.file_id;
            return `<a class="cont-card" href="/read/${m.id}">
                <div class="cont-cover-wrap">
                    ${src?`<img class="cont-cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="document.getElementById('${covId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`:''}
                    <div class="cont-cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:20px">📖</span></div>
                </div>
                <div class="cont-body">
                    <div class="cont-title">${escapeHtml(m.title)}</div>
                    ${m.total_pages>0?`<div class="cont-bar-wrap"><div class="cont-bar-bg"><div class="cont-bar-fill" style="width:${pct}%"></div></div></div>`:''}
                    <div class="cont-btn">${m.total_pages>0?`Стр. ${m.page_num}/${m.total_pages}`:'Читать →'}</div>
                </div>
            </a>`;
        }).join('');
    }catch(e){}
}

// ===== MODAL ADD MANGA =====
let coverFile=null,photoFiles=[],zipFile=null,currentMangaType='single';

function openAddModal(){document.getElementById('add-modal').classList.add('open');}
function closeAddModal(){document.getElementById('add-modal').classList.remove('open');}

function setMangaType(type){
    currentMangaType=type;
    document.getElementById('type-single').classList.toggle('active',type==='single');
    document.getElementById('type-series').classList.toggle('active',type==='series');
    document.getElementById('pages-section').style.display=type==='single'?'block':'none';
}

function switchTab(tab){
    ['zip','photos'].forEach(t=>{
        document.getElementById('tab-'+t).classList.toggle('active',t===tab);
        document.getElementById('panel-'+t).classList.toggle('active',t===tab);
    });
}

function onCoverChange(input){
    if(!input.files[0])return;
    coverFile=input.files[0];
    const preview=document.getElementById('cover-preview');
    const reader=new FileReader();
    reader.onload=e=>{preview.innerHTML=`<img class="preview-img" src="${e.target.result}" style="width:80px;height:110px">`};
    reader.readAsDataURL(coverFile);
}

function onPhotosChange(input){
    photoFiles=Array.from(input.files).sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'}));
    if(!photoFiles.length)return;
    const preview=document.getElementById('photos-preview');
    preview.innerHTML=`<div class="preview-count">📸 ${photoFiles.length} фото (отсортировано по имени)</div>`;
    photoFiles.slice(0,5).forEach(f=>{const r=new FileReader();r.onload=e=>{const img=document.createElement('img');img.className='preview-img';img.src=e.target.result;preview.appendChild(img);};r.readAsDataURL(f);});
}

async function onZipChange(input){
    if(!input.files[0])return;
    zipFile=input.files[0];
    const preview=document.getElementById('zip-preview');
    preview.innerHTML=`<div class="preview-count">⏳ Распаковываю ZIP...</div>`;
    try{
        const {JSZip}=await loadJSZip();
        const zip=await JSZip.loadAsync(zipFile);
        const allowed=['jpg','jpeg','png','webp','gif'];
        // Сортировка по lastModified (как в bot.php — по дате внутри архива)
        const files=[];
        zip.forEach((relPath,file)=>{
            if(file.dir)return;
            const ext=relPath.split('.').pop().toLowerCase();
            if(!allowed.includes(ext))return;
            files.push({path:relPath,file,lastMod:file.date||new Date(0),name:relPath.split('/').pop()});
        });
        // Сортируем: сначала по дате, при равной — натурально по имени
        files.sort((a,b)=>{
            const dt=a.lastMod-b.lastMod;
            if(dt!==0)return dt;
            return a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'});
        });
        const blobs=[];
        for(const {path,file}of files){
            const ext=path.split('.').pop().toLowerCase();
            const mime={'jpg':'image/jpeg','jpeg':'image/jpeg','png':'image/png','webp':'image/webp','gif':'image/gif'}[ext]||'image/jpeg';
            const blob=await file.async('blob');
            blobs.push(new File([blob],path.replace(/\//g,'_'),{type:mime,lastModified:file.date?file.date.getTime():0}));
        }
        photoFiles=blobs;
        zipFile=null;
        preview.innerHTML=`<div class="preview-count">📦 Распаковано: ${blobs.length} страниц (сортировка по дате архива)</div>`;
        blobs.slice(0,5).forEach(f=>{const r=new FileReader();r.onload=e=>{const img=document.createElement('img');img.className='preview-img';img.src=e.target.result;preview.appendChild(img);};r.readAsDataURL(f);});
    }catch(e){preview.innerHTML=`<div class="preview-count" style="color:#ff5050">❌ Ошибка распаковки: ${escapeHtml(e.message)}</div>`;}
}

let _jszip=null;
async function loadJSZip(){
    if(_jszip)return _jszip;
    await new Promise((res,rej)=>{const s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';s.onload=res;s.onerror=rej;document.head.appendChild(s);});
    _jszip=window;return _jszip;
}

async function uploadOneToImgbb(blob,keys){
    for(const key of keys){
        try{
            const b64=await new Promise((res,rej)=>{const r=new FileReader();r.onload=e=>res(e.target.result.split(',')[1]);r.onerror=()=>rej(new Error('read error'));r.readAsDataURL(blob);});
            const fd=new FormData();fd.append('key',key);fd.append('image',b64);
            const r=await fetch('https://api.imgbb.com/1/upload',{method:'POST',body:fd});
            if(r.ok){const d=await r.json();if(d?.data?.url)return d.data.url;}
        }catch(e){}
    }
    return null;
}

function showResult(type,msg){
    const banner=document.getElementById('result-banner');
    if(!type||!msg){banner.className='result-banner';banner.innerHTML='';return;}
    banner.className='result-banner '+type+' open';banner.innerHTML=msg;
    banner.scrollIntoView({behavior:'smooth',block:'nearest'});
}

async function submitManga(){
    const title=document.getElementById('manga-title').value.trim();
    const desc=document.getElementById('manga-desc').value.trim();
    const isSeries=currentMangaType==='series';
    if(!title){showResult('error','❌ Введи название!');return;}
    if(!isSeries&&!photoFiles.length&&!coverFile){showResult('error','❌ Загрузи хотя бы обложку или страницы!');return;}

    const btn=document.getElementById('submit-btn');
    btn.disabled=true;btn.classList.add('loading');showResult('','');
    const progressBar=document.getElementById('upload-progress');
    const progressFill=document.getElementById('upload-progress-fill');
    progressBar.classList.add('active');progressFill.style.width='2%';

    try{
        const keysRes=await fetch('/api/imgbb-keys?tg_user_id='+getTgUser());
        const keysData=await keysRes.json();
        if(!keysData.success||!keysData.keys?.length){showResult('error','❌ Нет доступа к ключам ImgBB');btn.disabled=false;btn.classList.remove('loading');return;}
        const keys=keysData.keys;

        let coverUrl=null;
        if(coverFile){progressFill.style.width='5%';coverUrl=await uploadOneToImgbb(coverFile,keys);}

        const pageUrls=[];
        if(!isSeries&&photoFiles.length){
            const total=photoFiles.length;
            for(let i=0;i<total;i++){
                progressFill.style.width=(5+Math.round((i/total)*88))+'%';
                const t=btn.querySelector('.btn-text');if(t)t.textContent=`⬆️ ${i+1} / ${total}`;
                const url=await uploadOneToImgbb(photoFiles[i],keys);
                if(url)pageUrls.push(url);
            }
        }

        progressFill.style.width='95%';

        const res=await fetch('/api/save-manga',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({title,description:desc,cover_url:coverUrl,page_urls:pageUrls,tg_user_id:getTgUser(),is_series:isSeries})
        });
        const rawText=await res.text();
        let data;try{data=JSON.parse(rawText);}catch(pe){showResult('error','❌ Ошибка сервера');btn.disabled=false;btn.classList.remove('loading');return;}
        progressFill.style.width='100%';

        if(data.success){
            showResult('success',`✅ <strong>Манга добавлена!</strong><br>${isSeries?'📚 Серия создана — теперь добавляй главы через Админ-панель<br>':''}${data.pages>0?`📄 Страниц: ${data.pages}<br>`:''}${data.telegraph?`🔗 Telegraph: <a href="${escapeHtml(data.telegraph)}" target="_blank">открыть</a><br>`:''}<a href="${escapeHtml(data.site_url)}" target="_blank">🌐 Открыть на сайте →</a>`);
            setTimeout(()=>{load(true);loadNew();},1500);
        }else{
            showResult('error','❌ Ошибка: '+(data.error||'Неизвестная'));
        }
    }catch(e){showResult('error','❌ Ошибка: '+e.message);}
    const t=btn.querySelector('.btn-text');if(t)t.textContent='🚀 Опубликовать';
    btn.disabled=false;btn.classList.remove('loading');
}

// ===== DRAG & DROP =====
['cover-zone','zip-zone','photos-zone'].forEach(zoneId=>{
    const zone=document.getElementById(zoneId);
    if(!zone)return;
    zone.addEventListener('dragover',e=>{e.preventDefault();zone.classList.add('drag');});
    zone.addEventListener('dragleave',()=>zone.classList.remove('drag'));
    zone.addEventListener('drop',e=>{
        e.preventDefault();zone.classList.remove('drag');
        const input=zone.querySelector('input[type=file]');
        if(input&&e.dataTransfer.files.length){
            const dt=new DataTransfer();Array.from(e.dataTransfer.files).forEach(f=>dt.items.add(f));
            input.files=dt.files;input.dispatchEvent(new Event('change'));
        }
    });
});

// ===== ADMIN PANEL =====
function openAdminPanel(){
    document.getElementById('admin-modal').classList.add('open');
    loadAdminStats();
}
function closeAdminPanel(){document.getElementById('admin-modal').classList.remove('open');}

function switchAdminTab(tab){
    document.querySelectorAll('.admin-tab').forEach((t,i)=>{
        const tabs=['stats','archive','edit','add-chapter'];
        t.classList.toggle('active',tabs[i]===tab);
    });
    document.querySelectorAll('.admin-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('panel-'+tab).classList.add('active');
    if(tab==='stats')loadAdminStats();
    if(tab==='archive')loadArchive(0);
    if(tab==='edit')loadMangaEditList('',0);
}

async function loadAdminStats(){
    try{
        const res=await fetch('/api/admin/stats?tg_user_id='+getTgUser());
        const data=await res.json();
        document.getElementById('stat-grid').innerHTML=`
            <div class="stat-card"><div class="stat-num">${data.manga_count}</div><div class="stat-label">Манг</div></div>
            <div class="stat-card"><div class="stat-num">${data.chapters_count}</div><div class="stat-label">Глав</div></div>
            <div class="stat-card"><div class="stat-num">${data.users_count}</div><div class="stat-label">Пользователей</div></div>
            <div class="stat-card"><div class="stat-num">${data.votes_count}</div><div class="stat-label">Голосов</div></div>
            <div class="stat-card"><div class="stat-num">${data.pages_count}</div><div class="stat-label">Страниц</div></div>
            <div class="stat-card"><div class="stat-num" style="color:#4caf50">${data.new_today}</div><div class="stat-label">Новых за 24ч</div></div>
        `;
        if(data.top_manga&&data.top_manga.length){
            document.getElementById('top-manga-list').innerHTML=data.top_manga.map((m,i)=>
                `<div class="top-manga-row"><span class="top-manga-name">${i+1}. ${escapeHtml(m.title)}</span><span class="top-manga-likes">👍 ${m.likes}</span></div>`
            ).join('');
        }
    }catch(e){}
}

let archivePage=0;
async function loadArchive(pg=0){
    archivePage=pg;
    try{
        const res=await fetch(`/api/admin/archive?page=${pg}&tg_user_id=`+getTgUser());
        const data=await res.json();
        const list=document.getElementById('archive-list');
        if(!data.items||!data.items.length){list.innerHTML='<div style="color:var(--muted);padding:16px 0">Архив пуст</div>';return;}
        const typeIcons={add_manga:'➕',edit_manga:'✏️',delete_manga:'🗑',add_chapter:'📚',delete_chapter:'🗑',set_cover:'🖼'};
        list.innerHTML=data.items.map(a=>`<div class="archive-item">
            <div class="archive-type">${typeIcons[a.action_type]||'•'} ${a.action_type}</div>
            <div class="archive-text">${escapeHtml(a.action_text)}</div>
            <div class="archive-date">${new Date(a.created_at).toLocaleString('ru-RU')}</div>
        </div>`).join('');
        // Пагинация
        const total=data.total,limit=20;
        const totalPages=Math.ceil(total/limit);
        let pages='';
        if(pg>0)pages+=`<button class="page-btn" onclick="loadArchive(${pg-1})">← Назад</button>`;
        pages+=`<span style="color:var(--muted);font-size:13px">Стр. ${pg+1} / ${totalPages}</span>`;
        if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadArchive(${pg+1})">Вперёд →</button>`;
        document.getElementById('archive-pagination').innerHTML=pages;
    }catch(e){}
}

// ===== EDIT: СПИСОК МАНГИ =====
let editPage=0,editQuery='';
async function loadMangaEditList(query='',pg=0){
    editQuery=query;editPage=pg;
    try{
        const res=await fetch(`/api/admin/manga-list?q=${encodeURIComponent(query)}&page=${pg}&tg_user_id=`+getTgUser());
        const data=await res.json();
        const list=document.getElementById('manga-edit-list');
        if(!data.items||!data.items.length){list.innerHTML='<div style="color:var(--muted);padding:16px 0">Ничего не найдено</div>';document.getElementById('edit-pagination').innerHTML='';return;}
        list.innerHTML=data.items.map(m=>{
            const src=m.cover_imgbb_url||'';
            const serLabel=m.is_series?'<span style="color:var(--accent);font-size:11px"> • Серия</span>':'';
            return `<div class="manga-edit-item" onclick="openEditManga(${m.id})">
                ${src?`<img class="manga-edit-cover" src="${escapeHtml(src)}" style="border-radius:8px;object-fit:cover" onerror="this.style.display='none'">`:`<div class="manga-edit-cover">📖</div>`}
                <div style="flex:1">
                    <div class="manga-edit-title">${escapeHtml(m.title)}${serLabel}</div>
                    <div class="manga-edit-meta">ID: ${m.id} • 👍 ${m.likes} • ${new Date(m.created_at).toLocaleDateString('ru-RU')}</div>
                </div>
                <span style="color:var(--muted);font-size:18px">›</span>
            </div>`;
        }).join('');
        // Пагинация
        const totalPages=Math.ceil(data.total/10);
        let pages='';
        if(pg>0)pages+=`<button class="page-btn" onclick="loadMangaEditList('${escapeHtml(editQuery)}',${pg-1})">← Назад</button>`;
        if(totalPages>1)pages+=`<span style="color:var(--muted);font-size:13px">${pg+1}/${totalPages}</span>`;
        if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadMangaEditList('${escapeHtml(editQuery)}',${pg+1})">Вперёд →</button>`;
        document.getElementById('edit-pagination').innerHTML=pages;
    }catch(e){}
}

function searchMangaEdit(){
    const q=document.getElementById('edit-search-input').value.trim();
    loadMangaEditList(q,0);
}
document.getElementById('edit-search-input').addEventListener('keydown',e=>{if(e.key==='Enter')searchMangaEdit();});

async function openEditManga(mangaId){
    try{
        const res=await fetch(`/api/admin/manga/${mangaId}?tg_user_id=`+getTgUser());
        const manga=await res.json();
        document.getElementById('manga-edit-list').style.display='none';
        document.getElementById('edit-pagination').style.display='none';
        document.querySelector('#panel-edit .edit-search-row').style.display='none';
        const formWrap=document.getElementById('edit-manga-form-wrap');
        formWrap.style.display='block';
        // Строим форму
        let chaptersHtml='';
        if(manga.is_series&&manga.chapters&&manga.chapters.length){
            chaptersHtml=`<div style="margin-top:16px">
                <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--muted)">Главы</div>
                <div class="chapter-admin-list">${manga.chapters.map(ch=>`
                    <div class="chapter-admin-item">
                        <span style="font-size:13px;font-weight:600">Глава ${ch.chapter_num}${ch.title?' — '+escapeHtml(ch.title):''}</span>
                        <button class="del-ch-btn" onclick="deleteChapter(${ch.id},this)">🗑 Удалить</button>
                    </div>`).join('')}
                </div>
            </div>`;
        }
        document.getElementById('edit-manga-form').innerHTML=`
            <div class="edit-field"><label>Название</label><input type="text" id="ef-title" value="${escapeHtml(manga.title)}"></div>
            <div class="edit-field"><label>Описание</label><textarea id="ef-desc">${escapeHtml(manga.description||'')}</textarea></div>
            <div class="edit-field"><label>Ссылка Telegraph</label><input type="text" id="ef-link" value="${escapeHtml(manga.telegraph_url||'')}"></div>
            <div class="edit-field"><label>URL обложки (ImgBB)</label><input type="text" id="ef-cover" value="${escapeHtml(manga.cover_imgbb_url||'')}"></div>
            ${manga.cover_imgbb_url?`<img src="${escapeHtml(manga.cover_imgbb_url)}" style="width:80px;height:110px;object-fit:cover;border-radius:10px;margin-bottom:12px">`:''}
            ${chaptersHtml}
            <div class="edit-actions" style="margin-top:16px">
                <button class="save-btn" onclick="saveMangaEdit(${mangaId})">💾 Сохранить</button>
                <button class="delete-btn" onclick="deleteManga(${mangaId})">🗑 Удалить мангу</button>
            </div>
            <div class="result-banner" id="ef-result"></div>
        `;
    }catch(e){showToast('❌ Ошибка загрузки');}
}

function backToMangaList(){
    document.getElementById('edit-manga-form-wrap').style.display='none';
    document.getElementById('manga-edit-list').style.display='flex';
    document.getElementById('edit-pagination').style.display='flex';
    document.querySelector('#panel-edit .edit-search-row').style.display='flex';
}

async function saveMangaEdit(mangaId){
    const title=document.getElementById('ef-title').value.trim();
    const desc=document.getElementById('ef-desc').value.trim();
    const link=document.getElementById('ef-link').value.trim();
    const cover=document.getElementById('ef-cover').value.trim();
    try{
        const res=await fetch(`/api/admin/manga/${mangaId}?tg_user_id=`+getTgUser(),{
            method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({title,description:desc,telegraph_url:link,cover_imgbb_url:cover})
        });
        const data=await res.json();
        const banner=document.getElementById('ef-result');
        if(data.success){banner.className='result-banner success open';banner.innerHTML='✅ Сохранено!';load(true);}
        else{banner.className='result-banner error open';banner.innerHTML='❌ Ошибка';}
    }catch(e){showToast('❌ Ошибка');}
}

async function deleteManga(mangaId){
    if(!confirm('Удалить эту мангу? Это действие необратимо!'))return;
    try{
        const res=await fetch(`/api/admin/manga/${mangaId}/delete?tg_user_id=`+getTgUser(),{method:'POST'});
        const data=await res.json();
        if(data.success){showToast('🗑 Манга удалена');backToMangaList();loadMangaEditList(editQuery,editPage);load(true);}
    }catch(e){}
}

async function deleteChapter(chapterId,btn){
    if(!confirm('Удалить эту главу?'))return;
    try{
        const res=await fetch(`/api/admin/chapter/${chapterId}/delete?tg_user_id=`+getTgUser(),{method:'POST'});
        const data=await res.json();
        if(data.success){btn.closest('.chapter-admin-item').remove();showToast('🗑 Глава удалена');}
    }catch(e){}
}

// ===== ADD CHAPTER =====
let selectedMangaId=null,chPhotoFiles=[],chZipFile=null;

async function searchMangaForChapter(){
    const q=document.getElementById('ch-manga-search').value.trim();
    try{
        const res=await fetch(`/api/admin/manga-list?q=${encodeURIComponent(q)}&page=0&tg_user_id=`+getTgUser());
        const data=await res.json();
        const list=document.getElementById('ch-manga-list');
        if(!data.items||!data.items.length){list.innerHTML='<div style="color:var(--muted);padding:12px 0">Ничего не найдено</div>';return;}
        list.innerHTML=data.items.map(m=>{
            const src=m.cover_imgbb_url||'';
            return `<div class="manga-edit-item" onclick="selectMangaForChapter(${m.id},'${escapeHtml(m.title).replace(/'/g,"\\'")}')">
                ${src?`<img class="manga-edit-cover" src="${escapeHtml(src)}" style="border-radius:6px;object-fit:cover">`:'<div class="manga-edit-cover">📖</div>'}
                <div><div class="manga-edit-title">${escapeHtml(m.title)}</div><div class="manga-edit-meta">${m.is_series?'📚 Серия':'📄 Обычная'}</div></div>
            </div>`;
        }).join('');
    }catch(e){}
}

function selectMangaForChapter(id,title){
    selectedMangaId=id;
    document.getElementById('ch-add-form').style.display='block';
    document.getElementById('ch-manga-list').innerHTML=`<div style="background:rgba(124,92,255,0.1);border:1px solid rgba(124,92,255,0.3);border-radius:10px;padding:10px 14px;font-weight:600;color:var(--accent)">✅ Выбрано: ${escapeHtml(title)}</div>`;
    showToast('Манга выбрана: '+title);
}

document.getElementById('ch-manga-search').addEventListener('keydown',e=>{if(e.key==='Enter')searchMangaForChapter();});

function switchChTab(tab){
    ['zip','photos'].forEach(t=>{
        document.getElementById('ch-tab-'+t).classList.toggle('active',t===tab);
        document.getElementById('ch-panel-'+t).classList.toggle('active',t===tab);
    });
}

function onChPhotosChange(input){
    chPhotoFiles=Array.from(input.files).sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'}));
    const preview=document.getElementById('ch-photos-preview');
    preview.innerHTML=`<div class="preview-count">📸 ${chPhotoFiles.length} страниц</div>`;
}

async function onChZipChange(input){
    if(!input.files[0])return;
    chZipFile=input.files[0];
    const preview=document.getElementById('ch-zip-preview');
    preview.innerHTML=`<div class="preview-count">⏳ Распаковываю...</div>`;
    try{
        const {JSZip}=await loadJSZip();
        const zip=await JSZip.loadAsync(chZipFile);
        const allowed=['jpg','jpeg','png','webp','gif'];
        const files=[];
        zip.forEach((relPath,file)=>{
            if(file.dir)return;
            const ext=relPath.split('.').pop().toLowerCase();
            if(!allowed.includes(ext))return;
            files.push({path:relPath,file,lastMod:file.date||new Date(0),name:relPath.split('/').pop()});
        });
        files.sort((a,b)=>{const dt=a.lastMod-b.lastMod;if(dt!==0)return dt;return a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'});});
        const blobs=[];
        for(const {path,file}of files){
            const ext=path.split('.').pop().toLowerCase();
            const mime={'jpg':'image/jpeg','jpeg':'image/jpeg','png':'image/png','webp':'image/webp','gif':'image/gif'}[ext]||'image/jpeg';
            const blob=await file.async('blob');
            blobs.push(new File([blob],path.replace(/\//g,'_'),{type:mime}));
        }
        chPhotoFiles=blobs;chZipFile=null;
        preview.innerHTML=`<div class="preview-count">📦 ${blobs.length} страниц</div>`;
    }catch(e){preview.innerHTML=`<div class="preview-count" style="color:#ff5050">❌ ${escapeHtml(e.message)}</div>`;}
}

async function submitChapter(){
    if(!selectedMangaId){showToast('❌ Сначала выберите мангу!');return;}
    const chNum=parseFloat(document.getElementById('ch-num').value);
    const chTitle=document.getElementById('ch-title-input').value.trim();
    if(!chNum||chNum<0){showToast('❌ Укажи номер главы!');return;}
    if(!chPhotoFiles.length){showToast('❌ Загрузи страницы!');return;}

    const btn=document.getElementById('ch-submit-btn');
    btn.disabled=true;btn.classList.add('loading');
    const progressBar=document.getElementById('ch-upload-progress');
    const progressFill=document.getElementById('ch-upload-fill');
    progressBar.classList.add('active');progressFill.style.width='2%';

    const resultBanner=document.getElementById('ch-result-banner');
    resultBanner.className='result-banner';resultBanner.innerHTML='';

    try{
        const keysRes=await fetch('/api/imgbb-keys?tg_user_id='+getTgUser());
        const keysData=await keysRes.json();
        if(!keysData.success){showToast('❌ Нет доступа');btn.disabled=false;btn.classList.remove('loading');return;}
        const keys=keysData.keys;
        const pageUrls=[];
        const total=chPhotoFiles.length;
        for(let i=0;i<total;i++){
            progressFill.style.width=(2+Math.round((i/total)*90))+'%';
            const t=btn.querySelector('.btn-text');if(t)t.textContent=`⬆️ ${i+1}/${total}`;
            const url=await uploadOneToImgbb(chPhotoFiles[i],keys);
            if(url)pageUrls.push(url);
        }
        progressFill.style.width='95%';

        const res=await fetch('/api/save-chapter',{
            method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({manga_id:selectedMangaId,chapter_num:chNum,chapter_title:chTitle,page_urls:pageUrls,tg_user_id:getTgUser()})
        });
        const data=await res.json();
        progressFill.style.width='100%';
        if(data.success){
            resultBanner.className='result-banner success open';
            resultBanner.innerHTML=`✅ Глава ${chNum} добавлена! ${pageUrls.length} стр.<br>${data.telegraph?`<a href="${escapeHtml(data.telegraph)}" target="_blank">📄 Telegraph</a>`:''}`;
            document.getElementById('ch-num').value='';
            document.getElementById('ch-title-input').value='';
            chPhotoFiles=[];
            document.getElementById('ch-zip-preview').innerHTML='';
            document.getElementById('ch-photos-preview').innerHTML='';
        }else{
            resultBanner.className='result-banner error open';
            resultBanner.innerHTML='❌ '+(data.error||'Ошибка');
        }
    }catch(e){resultBanner.className='result-banner error open';resultBanner.innerHTML='❌ '+e.message;}
    const t=btn.querySelector('.btn-text');if(t)t.textContent='📤 Загрузить главу';
    btn.disabled=false;btn.classList.remove('loading');
}

// ===== INIT =====
load();
loadNew();
loadContinue();
checkAdmin();
</script>
</body>
</html>