<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Добавляем колонки если нет
try {
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga_pages ADD COLUMN IF NOT EXISTS page_url TEXT");
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (user_id BIGINT NOT NULL, manga_id INT NOT NULL, vote_type VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (user_id BIGINT NOT NULL, manga_id INT NOT NULL, status VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_progress (user_id BIGINT NOT NULL, manga_id INT NOT NULL, page_num INT DEFAULT 1, total_pages INT DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_admins (user_id BIGINT PRIMARY KEY)");
} catch (Exception $e) {}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

session_start();
if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);

# =========================
# СПИСОК АДМИНОВ (из бота + из БД)
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
# IMGBB KEYS (из бота)
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
        if (!headers_sent()) {
            setcookie('tg_user_id', $tgUser, time() + 86400 * 30, '/', '', false, false);
        }
        $_SESSION['tg_user_id'] = $tgUser;
        return (int)$tgUser;
    }
    if (!empty($_SESSION['tg_user_id']) && is_numeric($_SESSION['tg_user_id'])) {
        return (int)$_SESSION['tg_user_id'];
    }
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
    $tgData = json_encode([
        'chat_id'    => $userId,
        'text'       => $text,
        'parse_mode' => 'Markdown'
    ]);
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $tgData);
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
                if (filter_var($filePathOrUrl, FILTER_VALIDATE_URL)) {
                    $imageData = base64_encode(file_get_contents($filePathOrUrl));
                } else {
                    $imageData = base64_encode(file_get_contents($filePathOrUrl));
                }
                $ch = curl_init('https://api.imgbb.com/1/upload');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $key, 'image' => $imageData]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (!empty($data['data']['url'])) {
                        return $data['data']['url'];
                    }
                }
            } catch (Exception $e) {}
        }
    }
    return null;
}

# =========================
# CREATE TELEGRAPH PAGE
# =========================
function createTelegraphPage($title, $imageUrls) {
    $nodes = [];
    $imageUrls = array_values(array_unique(array_filter($imageUrls)));
    foreach ($imageUrls as $url) {
        if (!empty($url)) {
            $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $url]];
        }
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


# =========================
# API ADD MANGA — добавление манги через сайт (только для админов)
# =========================
if ($path === '/api/add-manga' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    ini_set('max_execution_time', 300);
    ini_set('memory_limit', '512M');

    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) {
        echo json_encode(['success' => false, 'error' => 'Нет прав доступа']);
        exit;
    }

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!$title) {
        echo json_encode(['success' => false, 'error' => 'Название обязательно']);
        exit;
    }

    // --- ОБЛОЖКА ---
    $coverImgbbUrl = null;
    if (!empty($_FILES['cover']['tmp_name']) && $_FILES['cover']['error'] === 0) {
        $coverImgbbUrl = uploadToImgbb($_FILES['cover']['tmp_name'], $imgbbKeys);
    }

    // --- СТРАНИЦЫ: вариант 1 — ZIP ---
    $pageUrls = [];
    if (!empty($_FILES['zip']['tmp_name']) && $_FILES['zip']['error'] === 0) {
        $zipTmp = $_FILES['zip']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($zipTmp) === true) {
            $tmpDir = sys_get_temp_dir() . '/manga_zip_' . uniqid();
            mkdir($tmpDir, 0777, true);
            $zip->extractTo($tmpDir);
            $zip->close();

            // Собираем все изображения
            $allFiles = [];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                        $allFiles[] = $file->getRealPath();
                    }
                }
            }
            // Сортировка по имени
            usort($allFiles, function($a, $b) {
                return strnatcasecmp(basename($a), basename($b));
            });

            foreach ($allFiles as $imgPath) {
                $url = uploadToImgbb($imgPath, $imgbbKeys);
                if ($url) $pageUrls[] = $url;
            }

            // Удаляем tmp
            array_map('unlink', glob("$tmpDir/*.*"));
            @rmdir($tmpDir);
        }
    }

    // --- СТРАНИЦЫ: вариант 2 — фото ---
    if (empty($pageUrls) && !empty($_FILES['photos'])) {
        $photos = $_FILES['photos'];
        $count  = count($photos['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($photos['error'][$i] === 0 && !empty($photos['tmp_name'][$i])) {
                $url = uploadToImgbb($photos['tmp_name'][$i], $imgbbKeys);
                if ($url) $pageUrls[] = $url;
            }
        }
    }

    // --- TELEGRAPH ---
    $telegraphLink = null;
    if (!empty($pageUrls)) {
        $titleWithHeart = '♥ ' . $title;
        $telegraphLink = createTelegraphPage($titleWithHeart, $pageUrls);
    }

    // --- СОХРАНЯЕМ В БД ---
    $titleWithHeart = '♥ ' . $title;
    $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, cover_imgbb_url, added_by) VALUES (?, ?, ?, ?, ?)")
        ->execute([$titleWithHeart, $telegraphLink, $description, $coverImgbbUrl, $userId]);
    $newMangaId = (int)$pdo->lastInsertId();

    // Сохраняем страницы в manga_pages
    if (!empty($pageUrls)) {
        saveMangaPages($pdo, $newMangaId, $pageUrls);
    }

    $siteUrl = rtrim(getenv('SITE_URL') ?: '', '/');

    echo json_encode([
        'success'    => true,
        'manga_id'   => $newMangaId,
        'telegraph'  => $telegraphLink,
        'pages'      => count($pageUrls),
        'site_url'   => "{$siteUrl}/read/{$newMangaId}",
    ]);
    exit;
}


# =========================
# API CHECK ADMIN — проверить является ли пользователь админом
# =========================
if ($path === '/api/check-admin') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    echo json_encode(['is_admin' => isAdmin($userId, $hardcodedAdmins), 'user_id' => $userId]);
    exit;
}


# =========================
# API MANGA — каталог
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
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id, created_at FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");
        $count->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id, created_at FROM manga ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $count = $pdo->query("SELECT COUNT(*) FROM manga");
    }

    $now = date('Y-m-d H:i:s', time() - 86400);
    $items = [];
    foreach ($stmt as $m) {
        $items[] = [
            'id'            => (int)$m['id'],
            'title'         => $m['title'],
            'likes'         => (int)$m['likes'],
            'dislikes'      => (int)$m['dislikes'],
            'cover_display' => !empty($m['cover_imgbb_url']) ? $m['cover_imgbb_url'] : (!empty($m['file_id']) ? 'tg://' . $m['file_id'] : null),
            'is_new'        => ($m['created_at'] >= $now)
        ];
    }
    echo json_encode(['items' => $items, 'total' => (int)$count->fetchColumn(), 'limit' => $limit]);
    exit;
}

# =========================
# API NEW MANGA — новинки за 24ч
# =========================
if ($path === '/api/new-manga') {
    header('Content-Type: application/json');
    $since = date('Y-m-d H:i:s', time() - 86400);
    $stmt  = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id, created_at FROM manga WHERE created_at >= ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$since]);
    $items = [];
    foreach ($stmt as $m) {
        $items[] = [
            'id'            => (int)$m['id'],
            'title'         => $m['title'],
            'likes'         => (int)$m['likes'],
            'cover_display' => !empty($m['cover_imgbb_url']) ? $m['cover_imgbb_url'] : (!empty($m['file_id']) ? 'tg://' . $m['file_id'] : null)
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
    if ($row) {
        echo json_encode(['id' => (int)$row['id']]);
    } else {
        $row2 = $pdo->query("SELECT id FROM manga ORDER BY RANDOM() LIMIT 1")->fetch();
        echo json_encode(['id' => $row2 ? (int)$row2['id'] : null]);
    }
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
    if ($mangaId && $userId) {
        $pdo->prepare("INSERT INTO reading_progress (user_id, manga_id, page_num, total_pages, updated_at) VALUES (?, ?, ?, ?, NOW()) ON CONFLICT (user_id, manga_id) DO UPDATE SET page_num = EXCLUDED.page_num, total_pages = EXCLUDED.total_pages, updated_at = NOW()")->execute([$userId, $mangaId, $pageNum, $totalPages]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($path === '/api/progress') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    $stmt = $pdo->prepare("
        SELECT m.id, m.title, m.cover_imgbb_url, m.file_id,
               rp.page_num, rp.total_pages, rp.updated_at,
               s.status
        FROM user_manga_status s
        JOIN manga m ON s.manga_id = m.id
        LEFT JOIN reading_progress rp ON rp.manga_id = m.id AND rp.user_id = s.user_id
        WHERE s.user_id = ? AND s.status IN ('now', 'will')
        ORDER BY rp.updated_at DESC NULLS LAST
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();
    echo json_encode(['items' => $items]);
    exit;
}


# =========================
# API COVER — прокси для Telegram file_id обложек
# =========================
if (preg_match('#^/api/cover/(.+)$#', $path, $m)) {
    $fileId = $m[1];
    $token  = getenv('BOT_TOKEN');
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $res = @file_get_contents("https://api.telegram.org/bot{$token}/getFile?file_id=" . urlencode($fileId), false, $ctx);
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data['result']['file_path'])) {
            $imgUrl = "https://api.telegram.org/file/bot{$token}/" . $data['result']['file_path'];
            header("Location: " . $imgUrl, true, 302);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

# =========================
# API PAGES — страницы манги
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
            if (empty($pages)) {
                $html = @file_get_contents($tUrl, false, $ctx);
                if ($html) {
                    preg_match_all('/<img[^>]+src=["\'']([^"\']+)["\''][^>]*>/i', $html, $matches);
                    foreach ($matches[1] ?? [] as $src) {
                        if (strpos($src, 'http') === 0) {
                            $pages[] = $src;
                        } elseif (strpos($src, '/') === 0) {
                            $pages[] = 'https://telegra.ph' . $src;
                        }
                    }
                }
            }
            if (!empty($pages) && strpos($pages[0], 'ibb.co') !== false) {
                array_shift($pages);
            }
        }

        if (empty($pages)) {
            echo json_encode(['pages' => [], 'telegraph_url' => $tUrl]);
            exit;
        }
    }

    echo json_encode(['pages' => array_values($pages)]);
    exit;
}

function extractImgFromContent($nodes) {
    $urls = [];
    if (!is_array($nodes)) return $urls;
    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        if (isset($node['tag']) && $node['tag'] === 'img' && !empty($node['attrs']['src'])) {
            $src = $node['attrs']['src'];
            if (strpos($src, 'http') === 0) {
                $urls[] = $src;
            } elseif (strpos($src, '/') === 0) {
                $urls[] = 'https://telegra.ph' . $src;
            }
        }
        if (!empty($node['children'])) {
            $urls = array_merge($urls, extractImgFromContent($node['children']));
        }
    }
    return $urls;
}


# =========================
# API VOTE
# =========================
if ($path === '/api/vote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input   = json_decode(file_get_contents('php://input'), true);
    $userId  = getEffectiveUserId($pdo);
    if (!empty($input['tg_user_id']) && is_numeric($input['tg_user_id'])) {
        $userId = (int)$input['tg_user_id'];
    }
    $mangaId  = (int)($input['manga_id'] ?? 0);
    $voteType = $input['vote_type'] ?? '';

    if ($mangaId && in_array($voteType, ['like', 'dislike'])) {
        $check = $pdo->prepare("SELECT vote_type FROM votes WHERE user_id = ? AND manga_id = ?");
        $check->execute([$userId, $mangaId]);
        $existing = $check->fetch();

        if ($existing) {
            if ($existing['vote_type'] !== $voteType) {
                $pdo->prepare("UPDATE votes SET vote_type = ? WHERE user_id = ? AND manga_id = ?")->execute([$voteType, $userId, $mangaId]);
                if ($voteType == 'like') {
                    $pdo->prepare("UPDATE manga SET likes = likes + 1, dislikes = GREATEST(0, dislikes - 1) WHERE id = ?")->execute([$mangaId]);
                } else {
                    $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1, likes = GREATEST(0, likes - 1) WHERE id = ?")->execute([$mangaId]);
                }
            }
        } else {
            $pdo->prepare("INSERT INTO votes (user_id, manga_id, vote_type) VALUES (?, ?, ?)")->execute([$userId, $mangaId, $voteType]);
            if ($voteType == 'like') {
                $pdo->prepare("UPDATE manga SET likes = likes + 1 WHERE id = ?")->execute([$mangaId]);
            } else {
                $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1 WHERE id = ?")->execute([$mangaId]);
            }
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

        $realTgUser = !empty($input['tg_user_id']) && is_numeric($input['tg_user_id']);
        if ($realTgUser && $userId > 0) {
            $stmtM = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
            $stmtM->execute([$mangaId]);
            $mangaRow   = $stmtM->fetch();
            $mangaTitle = $mangaRow['title'] ?? "Манга #$mangaId";
            $labels = ['now' => '📖 Читаю', 'will' => '🔖 Буду читать', 'read' => '✅ Прочитано'];
            $label  = $labels[$status] ?? $status;
            $siteUrl = rtrim(getenv('SITE_URL') ?: '', '/');
            $tgMsg  = "🔄 *Статус обновлён с сайта*\n\n📖 *{$mangaTitle}*\n\n{$label}\n\n[Открыть мангу]({$siteUrl}/read/{$mangaId}?tg_user_id={$userId})";
            sendTgNotify($userId, $tgMsg);
        }

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
# VIEWER (РИДЕР)
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
.prev{left:0}
.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);padding:8px 20px;border-radius:999px;z-index:100;font-size:14px;pointer-events:none}
.back{position:fixed;top:16px;left:16px;z-index:200;color:#fff;text-decoration:none;background:rgba(0,0,0,0.7);padding:10px 18px;border-radius:30px;font-size:14px;border:1px solid rgba(255,255,255,0.1)}
.back:hover{background:rgba(124,92,255,0.6)}
#loading{position:absolute;color:#aaa;font-size:16px;text-align:center;padding:20px}
.fallback{position:absolute;text-align:center;display:none;padding:20px}
.fallback p{margin-bottom:16px;color:#aaa}
.telegraph-link{background:#7c5cff;color:#fff;padding:12px 24px;border-radius:40px;text-decoration:none;font-weight:600;display:inline-block}
.telegraph-link:hover{background:#6a4ee0}
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
        <p>❌ Страницы не найдены в базе данных</p>
        <?php if ($telegraphUrl): ?>
        <a href="<?= $telegraphUrl ?>" target="_blank" class="telegraph-link">📄 Читать в Telegraph</a>
        <?php endif; ?>
    </div>
</div>
<script>
let pages = [], current = 0;
const loadingEl  = document.getElementById('loading');
const pageEl     = document.getElementById('page');
const fallbackEl = document.getElementById('fallback');
const counterEl  = document.getElementById('counter');
const mangaId    = <?= $id ?>;

function getTgUser() {
    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
            const id = window.Telegram.WebApp.initDataUnsafe.user.id;
            document.cookie = 'tg_user_id=' + id + ';max-age=' + (86400*30) + ';path=/';
            return id;
        }
    } catch(e) {}
    const urlParams = new URLSearchParams(window.location.search);
    const urlTgId = urlParams.get('tg_user_id');
    if (urlTgId) return urlTgId;
    const match = document.cookie.match(/tg_user_id=(\d+)/);
    return match ? match[1] : '';
}

function getSavedPage() {
    try { return parseInt(localStorage.getItem('progress_' + mangaId) || '0'); } catch(e) { return 0; }
}
function saveProgress(p) {
    try { localStorage.setItem('progress_' + mangaId, p); } catch(e) {}
    fetch('/api/progress', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({manga_id: mangaId, page_num: p + 1, total_pages: pages.length, tg_user_id: getTgUser()})
    }).catch(() => {});
}

async function init() {
    try {
        const res  = await fetch('/api/pages/<?= $id ?>');
        const data = await res.json();
        pages = data.pages || [];
        if (pages.length === 0) {
            loadingEl.style.display = 'none';
            fallbackEl.style.display = 'block';
            return;
        }
        loadingEl.style.display = 'none';
        current = getSavedPage();
        if (current >= pages.length) current = 0;
        render();
    } catch (e) {
        loadingEl.style.display = 'none';
        fallbackEl.style.display = 'block';
    }
}

function render() {
    if (!pages[current]) return;
    pageEl.style.display = 'none';
    const img = new Image();
    img.onload = () => {
        pageEl.src = pages[current];
        pageEl.style.display = 'block';
        counterEl.innerText = (current + 1) + ' / ' + pages.length;
    };
    img.onerror = () => {
        if (current < pages.length - 1) { current++; render(); }
        else { fallbackEl.style.display = 'block'; }
    };
    img.src = pages[current];
    counterEl.innerText = (current + 1) + ' / ' + pages.length;
}

function nextPage() { if (current < pages.length - 1) { current++; render(); saveProgress(current); } }
function prevPage() { if (current > 0) { current--; render(); saveProgress(current); } }

document.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') nextPage();
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   prevPage();
});

let touchStartX = 0, touchStartY = 0;
document.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].screenX;
    touchStartY = e.changedTouches[0].screenY;
}, {passive: true});
document.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].screenX - touchStartX;
    const dy = e.changedTouches[0].screenY - touchStartY;
    if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 40) {
        if (dx < 0) nextPage(); else prevPage();
    }
}, {passive: true});

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
    $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, file_id, telegraph_url, likes, dislikes FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404 - Манга не найдена'); }

    $pagesCount = $pdo->prepare("SELECT COUNT(*) FROM manga_pages WHERE manga_id = ? AND page_url IS NOT NULL AND page_url != ''");
    $pagesCount->execute([$id]);
    $hasPages = (int)$pagesCount->fetchColumn() > 0;

    $userId = getEffectiveUserId($pdo);
    $stmtStatus = $pdo->prepare("SELECT status FROM user_manga_status WHERE user_id = ? AND manga_id = ?");
    $stmtStatus->execute([$userId, $id]);
    $currentStatus = $stmtStatus->fetchColumn() ?: '';
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($manga['title']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--muted:#8e8ea0;--accent:#7c5cff}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif}
.wrap{max-width:1100px;margin:auto;padding:40px 20px}
.back-link{color:var(--muted);text-decoration:none;margin-bottom:30px;display:inline-block;font-size:14px}
.back-link:hover{color:var(--accent)}
.box{display:flex;gap:40px;flex-wrap:wrap;background:var(--card);border-radius:28px;padding:30px;border:1px solid var(--border)}
.cover{width:280px;min-height:400px;border-radius:20px;object-fit:cover;flex-shrink:0}
.cover-placeholder{width:280px;min-height:400px;border-radius:20px;background:linear-gradient(135deg,#1a1a2e,#0a0a0a);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.info{flex:1;min-width:0}
.title{font-size:36px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:16px;line-height:1.2}
.desc{color:var(--muted);line-height:1.8;margin-top:20px;font-size:15px}
.stats{display:flex;gap:16px;margin-top:20px}
.stat-pill{padding:8px 16px;border-radius:40px;font-size:14px;font-weight:600}
.likes-count{background:rgba(255,107,107,0.12);color:#ff6b6b}
.dislikes-count{background:rgba(150,150,150,0.12);color:#aaa}
.status-section{margin-top:20px}
.status-label{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px}
.status-buttons,.vote-buttons,.read-buttons{display:flex;gap:10px;flex-wrap:wrap}
.status-btn{padding:10px 18px;border-radius:30px;border:2px solid var(--border);font-weight:600;cursor:pointer;background:transparent;color:var(--muted);font-size:13px;transition:all 0.2s;font-family:inherit}
.status-btn:hover{border-color:var(--accent);color:var(--text)}
.status-btn.active-now{background:rgba(255,165,0,0.15);border-color:#ffa500;color:#ffa500}
.status-btn.active-read{background:rgba(76,175,80,0.15);border-color:#4caf50;color:#4caf50}
.status-btn.active-will{background:rgba(124,92,255,0.15);border-color:var(--accent);color:var(--accent)}
.vote-buttons{margin-top:16px}
.vote-btn{padding:10px 20px;border-radius:30px;border:none;font-weight:600;cursor:pointer;font-size:14px;transition:all 0.2s;font-family:inherit}
.vote-like{background:rgba(255,107,107,0.15);color:#ff6b6b}
.vote-like:hover{background:rgba(255,107,107,0.3)}
.vote-dislike{background:rgba(150,150,150,0.15);color:#aaa}
.vote-dislike:hover{background:rgba(150,150,150,0.25)}
.read-buttons{margin-top:18px;gap:12px}
.btn{padding:14px 26px;border-radius:14px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:8px;font-size:15px;transition:all 0.2s}
.primary{background:var(--accent);color:#fff}
.primary:hover{background:#6a4ee0;transform:translateY(-1px)}
.secondary{background:var(--card);color:var(--text);border:1px solid var(--border)}
.secondary:hover{border-color:var(--accent)}
.toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#1a1a2e;color:#fff;padding:12px 24px;border-radius:50px;z-index:1000;border:1px solid var(--accent);animation:fadeOut 2.5s forwards;white-space:nowrap}
@keyframes fadeOut{0%{opacity:1}70%{opacity:1}100%{opacity:0;visibility:hidden}}
@media(max-width:768px){
    .box{flex-direction:column;align-items:center;padding:20px}
    .cover{width:100%;max-width:300px}
    .cover-placeholder{width:100%;max-width:300px;min-height:300px}
    .title{font-size:26px;text-align:center}
    .info{width:100%}
}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<div class="wrap">
<a href="/" class="back-link">← Вернуться в каталог</a>
<div class="box">
<?php
$coverSrc = !empty($manga['cover_imgbb_url']) ? $manga['cover_imgbb_url'] : (!empty($manga['file_id']) ? '/api/cover/' . $manga['file_id'] : null);
?>
<?php if ($coverSrc): ?>
    <img class="cover" src="<?= htmlspecialchars($coverSrc) ?>" alt="Обложка"
         onerror="this.style.display='none';document.getElementById('cover-ph').style.display='flex'">
    <div class="cover-placeholder" id="cover-ph" style="display:none"><span style="font-size:72px">📖</span></div>
<?php else: ?>
    <div class="cover-placeholder" id="cover-ph"><span style="font-size:72px">📖</span></div>
<?php endif; ?>
<div class="info">
    <div class="title"><?= htmlspecialchars($manga['title']) ?></div>
    <div class="desc"><?= nl2br(htmlspecialchars($manga['description'] ?? 'Описание отсутствует')) ?></div>
    <div class="stats">
        <div class="stat-pill likes-count">❤️ <span id="likes"><?= (int)$manga['likes'] ?></span> лайков</div>
        <div class="stat-pill dislikes-count">💔 <span id="dislikes"><?= (int)$manga['dislikes'] ?></span></div>
    </div>

    <div class="vote-buttons">
        <button class="vote-btn vote-like"    onclick="vote('like')">👍 Лайк</button>
        <button class="vote-btn vote-dislike" onclick="vote('dislike')">👎 Дизлайк</button>
    </div>

    <div class="status-section">
        <div class="status-label">Мой статус</div>
        <div class="status-buttons">
            <button class="status-btn <?= $currentStatus === 'now'  ? 'active-now'  : '' ?>" id="btn-now"  onclick="setStatus('now')">📖 Читаю</button>
            <button class="status-btn <?= $currentStatus === 'will' ? 'active-will' : '' ?>" id="btn-will" onclick="setStatus('will')">🔖 Буду читать</button>
            <button class="status-btn <?= $currentStatus === 'read' ? 'active-read' : '' ?>" id="btn-read" onclick="setStatus('read')">✅ Прочитано</button>
        </div>
    </div>

    <div class="read-buttons">
        <?php if ($hasPages): ?>
        <a class="btn primary" href="/view/<?= $id ?>">📖 Читать на сайте</a>
        <?php endif; ?>
        <?php if (!empty($manga['telegraph_url'])): ?>
        <a class="btn secondary" target="_blank" href="<?= htmlspecialchars($manga['telegraph_url']) ?>">📄 Читать в Telegraph</a>
        <?php endif; ?>
        <?php if (!$hasPages && empty($manga['telegraph_url'])): ?>
        <div style="color:var(--muted);padding:10px 0;font-size:14px">⚠️ Страницы ещё не загружены</div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>
<script>
function getTgUser() {
    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
            const id = window.Telegram.WebApp.initDataUnsafe.user.id;
            document.cookie = 'tg_user_id=' + id + ';max-age=' + (86400*30) + ';path=/';
            return id;
        }
    } catch(e) {}
    const urlParams = new URLSearchParams(window.location.search);
    const urlTgId = urlParams.get('tg_user_id');
    if (urlTgId) {
        document.cookie = 'tg_user_id=' + urlTgId + ';max-age=' + (86400*30) + ';path=/';
        return urlTgId;
    }
    const match = document.cookie.match(/tg_user_id=(\d+)/);
    return match ? match[1] : '';
}

async function vote(type) {
    try {
        const res = await fetch('/api/vote', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({manga_id: <?= $id ?>, vote_type: type, tg_user_id: getTgUser()})
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('likes').innerText    = data.likes;
            document.getElementById('dislikes').innerText = data.dislikes;
            showToast(type == 'like' ? '👍 Лайк учтён!' : '👎 Дизлайк учтён!');
        }
    } catch(e) {}
}

async function setStatus(status) {
    try {
        const res = await fetch('/api/status', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({manga_id: <?= $id ?>, status: status, tg_user_id: getTgUser()})
        });
        const data = await res.json();
        if (data.success) {
            ['now','will','read'].forEach(s => {
                const btn = document.getElementById('btn-' + s);
                btn.className = 'status-btn';
            });
            const activeClass = {now: 'active-now', will: 'active-will', read: 'active-read'};
            document.getElementById('btn-' + status).classList.add(activeClass[status]);
            const labels = {now: '📖 Отмечено: Читаю!', will: '🔖 Добавлено в список!', read: '✅ Отмечено как прочитанное!'};
            showToast(labels[status]);
        }
    } catch(e) {}
}

function showToast(msg) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = 'toast';
    t.innerText = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2800);
}
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
.back-link:hover{opacity:0.8}
.section{margin-bottom:40px}
.section-title{font-size:18px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.section-title span{font-size:22px}
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
.empty-section{color:var(--muted);font-size:14px;padding:16px 0}
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
function getTgUser() {
    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
            const id = window.Telegram.WebApp.initDataUnsafe.user.id;
            document.cookie = 'tg_user_id=' + id + ';max-age=' + (86400*30) + ';path=/';
            return id;
        }
    } catch(e) {}
    const urlParams = new URLSearchParams(window.location.search);
    const urlTgId = urlParams.get('tg_user_id');
    if (urlTgId) {
        document.cookie = 'tg_user_id=' + urlTgId + ';max-age=' + (86400*30) + ';path=/';
        return urlTgId;
    }
    const match = document.cookie.match(/tg_user_id=(\d+)/);
    return match ? match[1] : '';
}

function escapeHtml(t) {
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}

function cardHtml(m) {
    const coverId = 'cv-' + m.id;
    const phId    = 'ph-' + m.id;
    let coverSrc  = m.cover_imgbb_url || '';
    if (!coverSrc && m.file_id) coverSrc = '/api/cover/' + m.file_id;
    const imgHtml = coverSrc
        ? `<img class="cover" id="${coverId}" src="${escapeHtml(coverSrc)}" loading="lazy" alt=""
               onerror="document.getElementById('${coverId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`
        : '';
    const phStyle = coverSrc ? 'display:none' : 'display:flex';
    const badgeMap = {now: 'badge-now', will: 'badge-will', read: 'badge-read'};
    const labelMap = {now: '📖 Читаю', will: '🔖 Буду читать', read: '✅ Прочитано'};
    return `<a class="card" href="/read/${m.id}">
        ${imgHtml}
        <div class="cover-ph" id="${phId}" style="${phStyle}"><span style="font-size:40px">📖</span></div>
        <div class="info">
            <div class="title">${escapeHtml(m.title)}</div>
            <span class="badge ${badgeMap[m.status] || ''}">${labelMap[m.status] || ''}</span>
        </div>
    </a>`;
}

function sectionHtml(icon, title, items) {
    const gridContent = items.length > 0
        ? items.map(cardHtml).join('')
        : `<div class="empty-section">Список пуст</div>`;
    return `<div class="section">
        <div class="section-title"><span>${icon}</span>${title} <span style="color:var(--muted);font-size:14px;font-weight:400">(${items.length})</span></div>
        <div class="grid">${gridContent}</div>
    </div>`;
}

async function load() {
    try {
        const tgId = getTgUser();
        const res  = await fetch('/api/library?tg_user_id=' + tgId);
        const data = await res.json();
        const content = document.getElementById('content');
        if (!data.items || data.items.length === 0) {
            content.innerHTML = '<div class="empty-page">📭 У вас пока нет добавленной манги<br><br><a href="/" style="color:var(--accent)">Перейти в каталог →</a></div>';
            return;
        }
        const now  = data.items.filter(i => i.status === 'now');
        const will = data.items.filter(i => i.status === 'will');
        const read = data.items.filter(i => i.status === 'read');

        let html = '';
        if (now.length  > 0) html += sectionHtml('📖', 'Читаю сейчас', now);
        if (will.length > 0) html += sectionHtml('🔖', 'Буду читать',  will);
        if (read.length > 0) html += sectionHtml('✅', 'Прочитано',    read);
        if (!html) html = '<div class="empty-page">📭 У вас пока нет добавленной манги<br><br><a href="/" style="color:var(--accent)">Перейти в каталог →</a></div>';

        content.innerHTML = html;
    } catch(e) {
        document.getElementById('content').innerHTML = '<div class="empty-page">❌ Ошибка загрузки</div>';
    }
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

/* ===== HEADER ===== */
header{position:sticky;top:0;z-index:100;backdrop-filter:blur(20px);background:rgba(7,7,11,0.92);border-bottom:1px solid var(--border);padding:14px 24px}
.header-inner{max-width:1400px;margin:auto;display:flex;flex-wrap:wrap;align-items:center;gap:12px}
.logo{font-size:24px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;white-space:nowrap;text-decoration:none}
.search{flex:1;min-width:180px;max-width:420px;padding:11px 20px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:var(--text);font-size:14px;font-family:inherit}
.search:focus{outline:none;border-color:var(--accent)}
.search::placeholder{color:var(--muted)}
.header-right{display:flex;align-items:center;gap:10px;margin-left:auto}
.bot-link{display:flex;align-items:center;gap:7px;padding:9px 16px;background:rgba(124,92,255,0.12);border:1px solid rgba(124,92,255,0.35);border-radius:50px;color:var(--accent);text-decoration:none;font-size:13px;font-weight:600;transition:all 0.2s;white-space:nowrap}
.bot-link:hover{background:rgba(124,92,255,0.22);border-color:var(--accent)}
.lib-btn{display:flex;align-items:center;gap:7px;padding:9px 18px;background:var(--accent);border:none;border-radius:50px;color:#fff;text-decoration:none;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;white-space:nowrap;font-family:inherit}
.lib-btn:hover{background:#6a4ee0;transform:translateY(-1px)}
.random-btn{display:flex;align-items:center;gap:7px;padding:9px 16px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:50px;color:var(--text);text-decoration:none;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s;white-space:nowrap;font-family:inherit}
.random-btn:hover{border-color:var(--accent);color:var(--accent)}

/* ===== ADD MANGA BUTTON ===== */
.add-manga-btn{display:none;align-items:center;gap:7px;padding:9px 18px;background:linear-gradient(135deg,#00c853,#00897b);border:none;border-radius:50px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;white-space:nowrap;font-family:inherit}
.add-manga-btn:hover{opacity:0.88;transform:translateY(-1px)}
.add-manga-btn.visible{display:flex}

/* ===== MAIN WRAP ===== */
.wrap{max-width:1400px;margin:auto;padding:24px 20px}

/* ===== НОВИНКИ ===== */
.new-section{background:linear-gradient(135deg,rgba(124,92,255,0.08) 0%,rgba(16,16,24,0.9) 100%);border:1px solid rgba(124,92,255,0.25);border-radius:22px;padding:22px 22px 18px;margin-bottom:28px;position:relative;overflow:hidden}
.new-section::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),transparent)}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.section-title-row{display:flex;align-items:center;gap:10px}
.section-label{font-size:17px;font-weight:800;color:#fff}
.section-count{font-size:12px;color:var(--muted);background:var(--card);border:1px solid var(--border);padding:3px 10px;border-radius:20px}
.slider-wrap{position:relative}
.slider-track-outer{overflow:hidden;border-radius:14px}
.slider-track{display:flex;gap:14px;transition:transform 0.35s cubic-bezier(.4,0,.2,1);will-change:transform}
.slider-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:10;width:36px;height:36px;border-radius:50%;background:rgba(7,7,11,0.92);border:1px solid var(--border);color:#fff;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;user-select:none}
.slider-arrow:hover{background:var(--accent);border-color:var(--accent)}
.slider-arrow.left{left:-14px}
.slider-arrow.right{right:-14px}
.slider-arrow.hidden{opacity:0;pointer-events:none}
.slide-card{flex:0 0 140px;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.25s;position:relative}
.slide-card:hover{transform:translateY(-4px);border-color:var(--accent)}
.slide-cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}
.slide-cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.slide-info{padding:10px}
.slide-title{font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4}
.new-badge{position:absolute;top:7px;right:7px;background:linear-gradient(135deg,#ff4d6d,#ff6b35);color:#fff;font-size:9px;font-weight:800;padding:3px 7px;border-radius:8px;letter-spacing:0.5px;text-transform:uppercase;box-shadow:0 2px 8px rgba(255,77,109,0.4)}

/* ===== ПРОДОЛЖИТЬ ЧИТАТЬ ===== */
.continue-section{background:var(--card);border:1px solid var(--border);border-radius:22px;padding:20px 24px;margin-bottom:28px;position:relative;overflow:hidden}
.continue-section::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,#ffa500,#ff6b35,transparent)}
.cont-list{display:flex;gap:12px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
.cont-list::-webkit-scrollbar{display:none}
.cont-card{flex:0 0 240px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:14px;overflow:hidden;text-decoration:none;color:var(--text);display:flex;gap:0;transition:all 0.25s;position:relative}
.cont-card:hover{border-color:#ffa500;transform:translateY(-2px);box-shadow:0 6px 20px rgba(255,165,0,0.12)}
.cont-cover-wrap{width:60px;flex-shrink:0;position:relative}
.cont-cover{width:100%;height:100%;object-fit:cover;display:block;min-height:90px}
.cont-cover-ph{width:100%;min-height:90px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.cont-body{flex:1;padding:10px 12px;display:flex;flex-direction:column;justify-content:space-between;min-width:0}
.cont-title{font-size:12px;font-weight:700;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;margin-bottom:6px}
.cont-chapter{font-size:10px;color:var(--muted);margin-bottom:8px}
.cont-bar-wrap{margin-bottom:8px}
.cont-bar-bg{height:3px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden}
.cont-bar-fill{height:100%;background:linear-gradient(90deg,#ffa500,#ff6b35);border-radius:2px;transition:width 0.4s}
.cont-btn{display:inline-block;padding:5px 12px;background:rgba(255,165,0,0.15);border:1px solid rgba(255,165,0,0.4);border-radius:20px;color:#ffa500;font-size:10px;font-weight:700;text-align:center;transition:all 0.2s;white-space:nowrap;align-self:flex-start}
.cont-card:hover .cont-btn{background:rgba(255,165,0,0.25);border-color:#ffa500}

/* ===== FILTERS ===== */
.filters{display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap}
.filter-btn{padding:8px 18px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s;font-family:inherit}
.filter-btn:hover{border-color:var(--accent);color:var(--text)}
.filter-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}
.stats{color:var(--muted);font-size:13px;margin-left:auto}

/* ===== CATALOG GRID ===== */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:20px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.3s;position:relative}
.card:hover{transform:translateY(-6px);border-color:var(--accent);box-shadow:0 8px 30px rgba(124,92,255,0.15)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.info{padding:14px}
.title{font-size:13px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4}
.likes{margin-top:8px;color:#ffd166;font-size:12px}
.card-new-badge{position:absolute;top:8px;left:8px;background:linear-gradient(135deg,#ff4d6d,#ff6b35);color:#fff;font-size:9px;font-weight:800;padding:3px 8px;border-radius:8px;letter-spacing:0.5px;text-transform:uppercase;box-shadow:0 2px 8px rgba(255,77,109,0.4)}

.load-more{margin:40px auto;display:block;padding:14px 36px;background:var(--accent);color:#fff;border:none;border-radius:50px;cursor:pointer;font-size:15px;font-weight:600;font-family:inherit;transition:all 0.2s}
.load-more:hover{background:#6a4ee0;transform:translateY(-2px)}
.empty{text-align:center;padding:80px 20px;color:var(--muted)}

/* ===== MODAL ADD MANGA ===== */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:28px;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;padding:32px;position:relative;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.modal::-webkit-scrollbar{width:5px}
.modal::-webkit-scrollbar-track{background:transparent}
.modal::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}
.modal-close{position:absolute;top:18px;right:18px;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,0.06);border:1px solid var(--border);color:var(--muted);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s}
.modal-close:hover{background:rgba(255,80,80,0.15);color:#ff5050;border-color:#ff5050}
.modal-title{font-size:22px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:24px}
.form-group{margin-bottom:20px}
.form-label{display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px}
.form-input,.form-textarea{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:14px;color:var(--text);font-family:'Inter',sans-serif;font-size:14px;padding:12px 16px;transition:border-color 0.2s;resize:none}
.form-input:focus,.form-textarea:focus{outline:none;border-color:var(--accent)}
.form-textarea{min-height:90px}

/* File upload zones */
.upload-zone{border:2px dashed var(--border);border-radius:16px;padding:28px 20px;text-align:center;cursor:pointer;transition:all 0.25s;position:relative;overflow:hidden}
.upload-zone:hover,.upload-zone.drag{border-color:var(--accent);background:rgba(124,92,255,0.05)}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-icon{font-size:34px;margin-bottom:10px}
.upload-text{font-size:13px;color:var(--muted);line-height:1.5}
.upload-text strong{color:var(--text)}
.upload-preview{margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
.preview-img{width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid var(--border)}
.preview-cover{width:80px;height:110px;object-fit:cover;border-radius:10px;border:2px solid var(--accent)}
.preview-count{background:rgba(124,92,255,0.15);border:1px solid var(--accent);border-radius:10px;padding:6px 14px;font-size:12px;font-weight:700;color:var(--accent)}

/* File type tabs */
.file-tabs{display:flex;gap:8px;margin-bottom:14px}
.file-tab{flex:1;padding:10px;border-radius:12px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all 0.2s;text-align:center}
.file-tab:hover{border-color:var(--accent);color:var(--text)}
.file-tab.active{background:rgba(124,92,255,0.15);border-color:var(--accent);color:var(--accent)}
.file-panel{display:none}
.file-panel.active{display:block}

/* Submit */
.submit-btn{width:100%;padding:16px;border-radius:16px;background:linear-gradient(135deg,var(--accent),#5a3fc0);border:none;color:#fff;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit;transition:all 0.2s;margin-top:8px;position:relative;overflow:hidden}
.submit-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 24px rgba(124,92,255,0.35)}
.submit-btn:disabled{opacity:0.5;cursor:not-allowed}
.submit-btn .spinner{display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 0.7s linear infinite;margin:0 auto}
.submit-btn.loading .btn-text{display:none}
.submit-btn.loading .spinner{display:block}
@keyframes spin{to{transform:rotate(360deg)}}

/* Result banner */
.result-banner{border-radius:16px;padding:18px 20px;margin-top:16px;font-size:14px;line-height:1.6;display:none}
.result-banner.success{background:rgba(0,200,83,0.1);border:1px solid rgba(0,200,83,0.4);color:#00c853}
.result-banner.error{background:rgba(255,80,80,0.1);border:1px solid rgba(255,80,80,0.4);color:#ff5050}
.result-banner.open{display:block}
.result-banner a{color:inherit;text-decoration:underline;font-weight:700}

/* Progress bar upload */
.upload-progress{height:4px;background:rgba(255,255,255,0.06);border-radius:2px;margin-top:12px;overflow:hidden;display:none}
.upload-progress.active{display:block}
.upload-progress-fill{height:100%;background:linear-gradient(90deg,var(--accent),#00c853);border-radius:2px;width:0%;transition:width 0.4s}

@media(max-width:900px){
    .header-right{gap:7px}
    .bot-link span.btn-text{display:none}
    .random-btn span.btn-text{display:none}
}
@media(max-width:700px){
    .grid{grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:14px}
    .header-inner{gap:8px}
    .lib-btn span.btn-text{display:none}
    .cont-card{flex:0 0 210px}
    .add-manga-btn span.btn-text{display:none}
    .modal{padding:22px 18px}
}
@media(max-width:480px){
    header{padding:10px 14px}
    .wrap{padding:16px 12px}
    .new-section,.continue-section{padding:16px 14px 14px}
    .slider-arrow{display:none}
}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<header>
    <div class="header-inner">
        <a href="/" class="logo">BLACKWATCH<span style="color:var(--accent)">MANGA</span></a>
        <input id="search" class="search" placeholder="🔍 Поиск манги..." autocomplete="off">
        <div class="header-right">
            <!-- Кнопка добавить мангу — показывается только для админов -->
            <button class="add-manga-btn" id="add-manga-btn" onclick="openAddModal()" title="Добавить мангу">
                ➕ <span class="btn-text">Добавить мангу</span>
            </button>
            <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank" class="bot-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z"/></svg>
                <span class="btn-text">Бот</span>
            </a>
            <button class="random-btn" onclick="goRandom()" title="Случайная манга">
                🎲 <span class="btn-text">Случайная</span>
            </button>
            <a href="/library" class="lib-btn">
                📚 <span class="btn-text">Библиотека</span>
            </a>
        </div>
    </div>
</header>

<div class="wrap">
    <!-- ===== НОВИНКИ ЗА 24 ЧАСА ===== -->
    <div class="new-section" id="new-section" style="display:none">
        <div class="section-header">
            <div class="section-title-row">
                <span style="font-size:20px">🔥</span>
                <div class="section-label">Новинки за 24 часа</div>
                <div class="section-count" id="new-count">0</div>
            </div>
        </div>
        <div class="slider-wrap" id="new-slider-wrap">
            <button class="slider-arrow left hidden" id="new-prev" onclick="slideNew(-1)">&#8592;</button>
            <div class="slider-track-outer">
                <div class="slider-track" id="new-track"></div>
            </div>
            <button class="slider-arrow right hidden" id="new-next" onclick="slideNew(1)">&#8594;</button>
        </div>
    </div>

    <!-- ===== ПРОДОЛЖИТЬ ЧИТАТЬ ===== -->
    <div class="continue-section" id="cont-section" style="display:none">
        <div class="section-header">
            <div class="section-title-row">
                <span style="font-size:20px">📖</span>
                <div class="section-label">Продолжить читать</div>
                <div class="section-count" id="cont-count">0</div>
            </div>
        </div>
        <div class="cont-list" id="cont-list"></div>
    </div>

    <!-- ===== ФИЛЬТРЫ ===== -->
    <div class="filters">
        <button class="filter-btn active" id="f-new"     onclick="setFilter('new')">🆕 Новые</button>
        <button class="filter-btn"        id="f-popular" onclick="setFilter('popular')">🔥 Популярные</button>
        <button class="filter-btn"        id="f-alpha"   onclick="setFilter('alpha')">🔤 А→Я</button>
        <div class="stats" id="stats">Всего манги: <strong><?= (int)$total ?></strong></div>
    </div>

    <div class="grid" id="grid"></div>
    <button class="load-more" id="more" style="display:none">Загрузить ещё</button>
</div>

<!-- ===== МОДАЛЬНОЕ ОКНО: ДОБАВИТЬ МАНГУ ===== -->
<div class="modal-overlay" id="add-modal">
    <div class="modal">
        <button class="modal-close" onclick="closeAddModal()">✕</button>
        <div class="modal-title">➕ Добавить мангу</div>

        <!-- 1. ОБЛОЖКА -->
        <div class="form-group">
            <label class="form-label">1. Обложка</label>
            <div class="upload-zone" id="cover-zone">
                <input type="file" id="cover-input" accept="image/*" onchange="onCoverChange(this)">
                <div class="upload-icon">🖼️</div>
                <div class="upload-text"><strong>Кликни или перетащи</strong><br>JPG, PNG, WEBP</div>
                <div class="upload-preview" id="cover-preview"></div>
            </div>
        </div>

        <!-- 2. НАЗВАНИЕ -->
        <div class="form-group">
            <label class="form-label">2. Название манги</label>
            <input type="text" class="form-input" id="manga-title" placeholder="Введи название..." maxlength="200">
        </div>

        <!-- 3. ОПИСАНИЕ -->
        <div class="form-group">
            <label class="form-label">3. Описание</label>
            <textarea class="form-textarea" id="manga-desc" placeholder="Краткое описание манги..." rows="3"></textarea>
        </div>

        <!-- 4. СТРАНИЦЫ -->
        <div class="form-group">
            <label class="form-label">4. Страницы манги</label>
            <div class="file-tabs">
                <button class="file-tab active" id="tab-zip"    onclick="switchTab('zip')">📦 ZIP архив</button>
                <button class="file-tab"        id="tab-photos" onclick="switchTab('photos')">🖼️ Фото по одному</button>
            </div>

            <!-- ZIP вариант -->
            <div class="file-panel active" id="panel-zip">
                <div class="upload-zone" id="zip-zone">
                    <input type="file" id="zip-input" accept=".zip" onchange="onZipChange(this)">
                    <div class="upload-icon">📦</div>
                    <div class="upload-text"><strong>Загрузить ZIP</strong><br>Все страницы упакованные в архив<br><span style="font-size:11px;color:#666">Сортировка по имени файла</span></div>
                    <div class="upload-preview" id="zip-preview"></div>
                </div>
            </div>

            <!-- Фото вариант -->
            <div class="file-panel" id="panel-photos">
                <div class="upload-zone" id="photos-zone">
                    <input type="file" id="photos-input" accept="image/*" multiple onchange="onPhotosChange(this)">
                    <div class="upload-icon">📸</div>
                    <div class="upload-text"><strong>Выбери все страницы</strong><br>Зажми Ctrl/Cmd для выбора нескольких файлов</div>
                    <div class="upload-preview" id="photos-preview"></div>
                </div>
            </div>
        </div>

        <!-- Прогресс -->
        <div class="upload-progress" id="upload-progress">
            <div class="upload-progress-fill" id="upload-progress-fill"></div>
        </div>

        <!-- Кнопка submit -->
        <button class="submit-btn" id="submit-btn" onclick="submitManga()">
            <span class="btn-text">🚀 Опубликовать мангу</span>
            <div class="spinner"></div>
        </button>

        <!-- Результат -->
        <div class="result-banner" id="result-banner"></div>
    </div>
</div>

<script>
// ===== INIT TG ID =====
(function() {
    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
            const id = window.Telegram.WebApp.initDataUnsafe.user.id;
            document.cookie = 'tg_user_id=' + id + ';max-age=' + (86400*30) + ';path=/';
        }
    } catch(e) {}
})();

function getTgUser() {
    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
            const id = window.Telegram.WebApp.initDataUnsafe.user.id;
            document.cookie = 'tg_user_id=' + id + ';max-age=' + (86400*30) + ';path=/';
            return id;
        }
    } catch(e) {}
    const urlParams = new URLSearchParams(window.location.search);
    const urlTgId = urlParams.get('tg_user_id');
    if (urlTgId) {
        document.cookie = 'tg_user_id=' + urlTgId + ';max-age=' + (86400*30) + ';path=/';
        return urlTgId;
    }
    const match = document.cookie.match(/tg_user_id=(\d+)/);
    return match ? match[1] : '';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ===== ПРОВЕРКА АДМИНА =====
async function checkAdmin() {
    try {
        const tgId = getTgUser();
        const res  = await fetch('/api/check-admin?tg_user_id=' + tgId);
        const data = await res.json();
        if (data.is_admin) {
            document.getElementById('add-manga-btn').classList.add('visible');
        }
    } catch(e) {}
}

// ===== КАТАЛОГ =====
let page = 0, q = '', loading = false, hasMore = true, currentSort = 'new';
const grid        = document.getElementById('grid');
const moreBtn     = document.getElementById('more');
const searchInput = document.getElementById('search');
const statsDiv    = document.getElementById('stats');

function setFilter(sort) {
    if (currentSort === sort) return;
    currentSort = sort;
    ['new','popular','alpha'].forEach(s => {
        document.getElementById('f-' + s).classList.toggle('active', s === sort);
    });
    load(true);
}

async function load(reset = false) {
    if (loading) return;
    loading = true;
    if (reset) { page = 0; grid.innerHTML = ''; hasMore = true; moreBtn.style.display = 'none'; }
    if (page === 0 && grid.children.length === 0) {
        grid.innerHTML = '<div class="empty">📖 Загрузка...</div>';
    }
    try {
        const res  = await fetch(`/api/manga?page=${page}&q=${encodeURIComponent(q)}&sort=${currentSort}`);
        const data = await res.json();
        if (page === 0) {
            grid.innerHTML = '';
            statsDiv.innerHTML = q
                ? `Найдено: <strong>${data.total}</strong> (из <?= (int)$total ?> манг)`
                : `Всего манги: <strong>${data.total}</strong>`;
        }
        if (data.items.length === 0 && page === 0) {
            grid.innerHTML = '<div class="empty">😔 Ничего не найдено</div>';
            moreBtn.style.display = 'none';
            loading = false;
            return;
        }
        data.items.forEach(m => {
            const coverId = 'cover-' + m.id;
            const phId    = 'ph-' + m.id;
            let coverSrc = m.cover_display || '';
            if (coverSrc.startsWith('tg://')) {
                coverSrc = '/api/cover/' + coverSrc.slice(5);
            }
            const coverHtml = coverSrc
                ? `<img class="cover" id="${coverId}" src="${escapeHtml(coverSrc)}" loading="lazy" alt=""
                       onerror="document.getElementById('${coverId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`
                : '';
            const phStyle = m.cover_display ? 'display:none' : 'display:flex';
            const newBadge = m.is_new ? `<div class="card-new-badge">NEW</div>` : '';
            grid.insertAdjacentHTML('beforeend',
                `<a class="card" href="/read/${m.id}">
                    ${newBadge}
                    ${coverHtml}
                    <div class="cover-ph" id="${phId}" style="${phStyle}"><span style="font-size:52px">📖</span></div>
                    <div class="info">
                        <div class="title">${escapeHtml(m.title)}</div>
                        <div class="likes">❤ ${m.likes}</div>
                    </div>
                </a>`
            );
        });
        hasMore = (page + 1) * data.limit < data.total;
        moreBtn.style.display = hasMore ? 'block' : 'none';
        page++;
    } catch (e) {
        if (page === 0) grid.innerHTML = '<div class="empty">❌ Ошибка загрузки</div>';
    }
    loading = false;
}

let searchTimeout;
searchInput.addEventListener('input', e => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        q = e.target.value.trim();
        load(true);
    }, 400);
});
moreBtn.onclick = () => load();

// ===== СЛУЧАЙНАЯ МАНГА =====
async function goRandom() {
    try {
        const tgId = getTgUser();
        const res  = await fetch('/api/random' + (tgId ? '?tg_user_id=' + tgId : ''));
        const data = await res.json();
        if (data.id) window.location.href = '/read/' + data.id;
    } catch(e) {}
}

// ===== СЛАЙДЕР НОВИНОК =====
let newOffset = 0;
const NEW_CARD_W = 154;

async function loadNew() {
    try {
        const res  = await fetch('/api/new-manga');
        const data = await res.json();
        const items = data.items || [];
        const section = document.getElementById('new-section');
        const track   = document.getElementById('new-track');
        if (items.length === 0) return;
        section.style.display = 'block';
        document.getElementById('new-count').textContent = items.length;
        track.innerHTML = items.map(m => {
            let coverSrc = m.cover_display || '';
            if (coverSrc.startsWith('tg://')) coverSrc = '/api/cover/' + coverSrc.slice(5);
            const cid = 'nc-' + m.id, pid = 'np-' + m.id;
            const imgHtml = coverSrc
                ? `<img class="slide-cover" id="${cid}" src="${escapeHtml(coverSrc)}" loading="lazy" alt=""
                       onerror="document.getElementById('${cid}').style.display='none';document.getElementById('${pid}').style.display='flex'">`
                : '';
            const phStyle = coverSrc ? 'display:none' : 'display:flex';
            return `<a class="slide-card" href="/read/${m.id}">
                <div class="new-badge">NEW</div>
                ${imgHtml}
                <div class="slide-cover-ph" id="${pid}" style="${phStyle}"><span style="font-size:36px">📖</span></div>
                <div class="slide-info"><div class="slide-title">${escapeHtml(m.title)}</div></div>
            </a>`;
        }).join('');
        updateNewArrows(items.length);
    } catch(e) {}
}

function updateNewArrows(total) {
    const outer = document.querySelector('#new-slider-wrap .slider-track-outer');
    const visible = Math.floor(outer.offsetWidth / NEW_CARD_W);
    const maxOffset = Math.max(0, total - visible);
    document.getElementById('new-prev').classList.toggle('hidden', newOffset <= 0);
    document.getElementById('new-next').classList.toggle('hidden', newOffset >= maxOffset);
    document.getElementById('new-track').style.transform = `translateX(-${newOffset * NEW_CARD_W}px)`;
}

function slideNew(dir) {
    const track  = document.getElementById('new-track');
    const total  = track.children.length;
    const outer  = document.querySelector('#new-slider-wrap .slider-track-outer');
    const visible = Math.floor(outer.offsetWidth / NEW_CARD_W);
    const maxOffset = Math.max(0, total - visible);
    newOffset = Math.max(0, Math.min(maxOffset, newOffset + dir));
    updateNewArrows(total);
}

// ===== ПРОДОЛЖИТЬ ЧИТАТЬ =====
async function loadContinue() {
    try {
        const tgId = getTgUser();
        const url = tgId ? '/api/progress?tg_user_id=' + tgId : '/api/progress';
        const res  = await fetch(url);
        const data = await res.json();
        const items = (data.items || []).filter(i => i);
        const section = document.getElementById('cont-section');
        const list    = document.getElementById('cont-list');
        if (items.length === 0) return;
        section.style.display = 'block';
        document.getElementById('cont-count').textContent = items.length;

        list.innerHTML = items.map(m => {
            let coverSrc = m.cover_imgbb_url || '';
            if (!coverSrc && m.file_id) coverSrc = '/api/cover/' + m.file_id;
            const cid = 'cc-' + m.id, pid = 'cp-' + m.id;
            const imgHtml = coverSrc
                ? `<img class="cont-cover" id="${cid}" src="${escapeHtml(coverSrc)}" loading="lazy" alt=""
                       onerror="document.getElementById('${cid}').style.display='none';document.getElementById('${pid}').style.display='flex'">`
                : '';
            const phStyle = coverSrc ? 'display:none' : 'display:flex';

            const pg  = parseInt(m.page_num) || 1;
            const tot = parseInt(m.total_pages) || 0;
            const pct = tot > 0 ? Math.min(100, Math.round((pg / tot) * 100)) : 0;

            let chapterLine = '';
            if (tot > 0) {
                chapterLine = `Глава 1 — ${pg} из ${tot}`;
            } else if (m.status === 'will') {
                chapterLine = '🔖 Буду читать';
            } else {
                chapterLine = '📖 Читаю';
            }

            const barHtml = tot > 0
                ? `<div class="cont-bar-wrap">
                       <div class="cont-bar-bg">
                           <div class="cont-bar-fill" style="width:${pct}%"></div>
                       </div>
                   </div>`
                : '';

            const btnLabel = m.status === 'will' ? '▶ Начать' : '▶ Продолжить';

            return `<a class="cont-card" href="/view/${m.id}">
                <div class="cont-cover-wrap">
                    ${imgHtml}
                    <div class="cont-cover-ph" id="${pid}" style="${phStyle}"><span style="font-size:22px">📖</span></div>
                </div>
                <div class="cont-body">
                    <div>
                        <div class="cont-title">${escapeHtml(m.title)}</div>
                        <div class="cont-chapter">${chapterLine}</div>
                        ${barHtml}
                    </div>
                    <span class="cont-btn">${btnLabel}</span>
                </div>
            </a>`;
        }).join('');
    } catch(e) {}
}

// ===== MODAL: ДОБАВИТЬ МАНГУ =====
let coverFile = null;
let zipFile   = null;
let photoFiles = [];
let currentTab = 'zip';

function openAddModal() {
    document.getElementById('add-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
    resetForm();
}

function closeAddModal() {
    document.getElementById('add-modal').classList.remove('open');
    document.body.style.overflow = '';
}

// Закрыть по клику на оверлей
document.getElementById('add-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});

function resetForm() {
    coverFile = null;
    zipFile = null;
    photoFiles = [];
    document.getElementById('manga-title').value = '';
    document.getElementById('manga-desc').value = '';
    document.getElementById('cover-input').value = '';
    document.getElementById('zip-input').value = '';
    document.getElementById('photos-input').value = '';
    document.getElementById('cover-preview').innerHTML = '';
    document.getElementById('zip-preview').innerHTML = '';
    document.getElementById('photos-preview').innerHTML = '';
    const banner = document.getElementById('result-banner');
    banner.className = 'result-banner';
    banner.innerHTML = '';
    const btn = document.getElementById('submit-btn');
    btn.disabled = false;
    btn.classList.remove('loading');
    document.getElementById('upload-progress').classList.remove('active');
    document.getElementById('upload-progress-fill').style.width = '0%';
}

function switchTab(tab) {
    currentTab = tab;
    document.getElementById('tab-zip').classList.toggle('active', tab === 'zip');
    document.getElementById('tab-photos').classList.toggle('active', tab === 'photos');
    document.getElementById('panel-zip').classList.toggle('active', tab === 'zip');
    document.getElementById('panel-photos').classList.toggle('active', tab === 'photos');
}

function onCoverChange(input) {
    const file = input.files[0];
    if (!file) return;
    coverFile = file;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('cover-preview').innerHTML =
            `<img class="preview-cover" src="${e.target.result}" alt="Обложка">`;
    };
    reader.readAsDataURL(file);
}

function onZipChange(input) {
    const file = input.files[0];
    if (!file) return;
    zipFile = file;
    const mb = (file.size / 1024 / 1024).toFixed(1);
    document.getElementById('zip-preview').innerHTML =
        `<div class="preview-count">📦 ${escapeHtml(file.name)} (${mb} MB)</div>`;
}

function onPhotosChange(input) {
    photoFiles = Array.from(input.files);
    if (!photoFiles.length) return;
    const preview = document.getElementById('photos-preview');
    preview.innerHTML = `<div class="preview-count">📸 ${photoFiles.length} фото</div>`;
    // Показываем первые 5 превью
    const toShow = photoFiles.slice(0, 5);
    toShow.forEach(f => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.createElement('img');
            img.className = 'preview-img';
            img.src = e.target.result;
            preview.appendChild(img);
        };
        reader.readAsDataURL(f);
    });
}

// Drag & drop support
['cover-zone','zip-zone','photos-zone'].forEach(zoneId => {
    const zone = document.getElementById(zoneId);
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('drag');
        const input = zone.querySelector('input[type=file]');
        if (input && e.dataTransfer.files.length) {
            // Симулируем change event
            const dt = new DataTransfer();
            Array.from(e.dataTransfer.files).forEach(f => dt.items.add(f));
            input.files = dt.files;
            input.dispatchEvent(new Event('change'));
        }
    });
});

async function submitManga() {
    const title = document.getElementById('manga-title').value.trim();
    const desc  = document.getElementById('manga-desc').value.trim();

    if (!title) {
        showResult('error', '❌ Введи название манги!');
        return;
    }

    // Проверяем что есть хотя бы что-то
    const hasZip    = currentTab === 'zip'    && zipFile;
    const hasPhotos = currentTab === 'photos' && photoFiles.length > 0;

    // Разрешаем публикацию даже без страниц (можно добавить потом)

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.classList.add('loading');
    showResult('', '');

    // Прогресс-анимация
    const progressBar  = document.getElementById('upload-progress');
    const progressFill = document.getElementById('upload-progress-fill');
    progressBar.classList.add('active');

    // Имитируем плавный прогресс во время загрузки
    let fakeProgress = 0;
    const progressInterval = setInterval(() => {
        if (fakeProgress < 85) {
            fakeProgress += Math.random() * 4 + 1;
            progressFill.style.width = Math.min(85, fakeProgress) + '%';
        }
    }, 400);

    try {
        const formData = new FormData();
        formData.append('title', title);
        formData.append('description', desc);
        formData.append('tg_user_id', getTgUser());

        if (coverFile) formData.append('cover', coverFile);
        if (hasZip)    formData.append('zip', zipFile);
        if (hasPhotos) {
            photoFiles.forEach(f => formData.append('photos[]', f));
        }

        const res  = await fetch('/api/add-manga', { method: 'POST', body: formData });
        const data = await res.json();

        clearInterval(progressInterval);
        progressFill.style.width = '100%';

        if (data.success) {
            showResult('success',
                `✅ <strong>Манга успешно добавлена!</strong><br>` +
                (data.pages > 0 ? `📄 Страниц загружено: ${data.pages}<br>` : '') +
                (data.telegraph ? `🔗 Telegraph: <a href="${escapeHtml(data.telegraph)}" target="_blank">открыть</a><br>` : '') +
                `🌐 <a href="${escapeHtml(data.site_url)}" target="_blank">Открыть на сайте →</a>`
            );
            // Перезагружаем каталог
            setTimeout(() => { load(true); loadNew(); }, 1500);
        } else {
            showResult('error', '❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
        }
    } catch(e) {
        clearInterval(progressInterval);
        showResult('error', '❌ Ошибка соединения: ' + e.message);
    }

    btn.disabled = false;
    btn.classList.remove('loading');
}

function showResult(type, msg) {
    const banner = document.getElementById('result-banner');
    if (!type || !msg) {
        banner.className = 'result-banner';
        banner.innerHTML = '';
        return;
    }
    banner.className = 'result-banner ' + type + ' open';
    banner.innerHTML = msg;
    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ===== INIT =====
load();
loadNew();
loadContinue();
checkAdmin();
</script>
</body>
</html>