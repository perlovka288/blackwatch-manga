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
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(200);
    die('DB Error: ' . $e->getMessage());
}

# --- Создаём таблицы ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga (
        id SERIAL PRIMARY KEY,
        title TEXT NOT NULL,
        file_id TEXT,
        description TEXT,
        likes INT DEFAULT 0,
        dislikes INT DEFAULT 0,
        added_by BIGINT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        cover_imgbb_url TEXT,
        telegraph_url TEXT,
        is_series BOOLEAN DEFAULT FALSE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_pages (
        id SERIAL PRIMARY KEY,
        manga_id INT NOT NULL,
        page_url TEXT NOT NULL,
        page_order INT NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapters (
        id SERIAL PRIMARY KEY,
        manga_id INT NOT NULL,
        chapter_num FLOAT NOT NULL DEFAULT 1,
        title TEXT,
        telegraph_url TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapter_pages (
        id SERIAL PRIMARY KEY,
        chapter_id INT NOT NULL,
        page_url TEXT NOT NULL,
        page_order INT NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (
        user_id BIGINT NOT NULL,
        manga_id INT NOT NULL,
        vote_type VARCHAR(10) NOT NULL,
        PRIMARY KEY (user_id, manga_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (
        user_id BIGINT NOT NULL,
        manga_id INT NOT NULL,
        status VARCHAR(10) NOT NULL,
        PRIMARY KEY (user_id, manga_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_progress (
        user_id BIGINT NOT NULL,
        manga_id INT NOT NULL,
        page_num INT DEFAULT 1,
        total_pages INT DEFAULT 0,
        chapter_id INT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, manga_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_admins (user_id BIGINT PRIMARY KEY)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archive (
        id SERIAL PRIMARY KEY,
        action_type VARCHAR(50) NOT NULL,
        action_text TEXT NOT NULL,
        action_by BIGINT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id BIGINT PRIMARY KEY,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_states (
        user_id BIGINT PRIMARY KEY,
        state TEXT NOT NULL,
        data JSONB DEFAULT '{}',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // ALTER
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS is_series BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE manga_pages ADD COLUMN IF NOT EXISTS page_url TEXT");
    $pdo->exec("ALTER TABLE reading_progress ADD COLUMN IF NOT EXISTS chapter_id INT DEFAULT NULL");
} catch (Exception $e) {
    // таблицы уже есть — ок
}

# =========================
# КОНСТАНТЫ
# =========================
$BOT_TOKEN       = getenv('BOT_TOKEN');
$SITE_URL        = rtrim(getenv('SITE_URL') ?: '', '/');
$BOT_USERNAME    = getenv('BOT_USERNAME') ?: 'blackwatch_manga_bot';
$TELEGRAPH_TOKEN = '192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831';

$IMGBB_KEYS = [
    '58ff4596fd55028a81cbf8c4e38388e1',
    '6981ba08e7b2a8743aab2c8ea008f675',
    'f9b8d27fa4029816d643c7814fd60c60',
    '24dbed2ae9fea9369de6a7b68d0c3ee6',
    'c3e6a55335c71a052c1a59b6a2d6d150',
];

$HARDCODED_ADMINS = [1710365896, 1181510470];

# --- Загрузить всех админов из БД ---
try {
    $stmtA = $pdo->query("SELECT user_id FROM bot_admins");
    foreach ($stmtA as $row) {
        $uid = (int)$row['user_id'];
        if (!in_array($uid, $HARDCODED_ADMINS)) $HARDCODED_ADMINS[] = $uid;
    }
} catch (Exception $e) {}

# =========================
# ПОЛУЧИТЬ АПДЕЙТ
# =========================
$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(200);
    echo 'OK';
    exit;
}

# =========================
# ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
# =========================

function isAdmin(int $userId, array $admins): bool {
    return in_array($userId, $admins);
}

function tgApi(string $method, array $params = []): ?array {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return null;
    return json_decode($res, true);
}

function sendMsg(int $chatId, string $text, array $keyboard = [], string $parseMode = 'HTML'): ?array {
    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => $parseMode,
    ];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return tgApi('sendMessage', $params);
}

function editMsg(int $chatId, int $msgId, string $text, array $keyboard = [], string $parseMode = 'HTML'): ?array {
    $params = [
        'chat_id'    => $chatId,
        'message_id' => $msgId,
        'text'       => $text,
        'parse_mode' => $parseMode,
    ];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return tgApi('editMessageText', $params);
}

function answerCallback(string $callbackId, string $text = '', bool $alert = false): void {
    tgApi('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => $text,
        'show_alert'        => $alert,
    ]);
}

function sendPhoto(int $chatId, string $photoUrl, string $caption = '', array $keyboard = []): ?array {
    $params = [
        'chat_id'    => $chatId,
        'photo'      => $photoUrl,
        'caption'    => $caption,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return tgApi('sendPhoto', $params);
}

function editCaption(int $chatId, int $msgId, string $caption, array $keyboard = []): ?array {
    $params = [
        'chat_id'    => $chatId,
        'message_id' => $msgId,
        'caption'    => $caption,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return tgApi('editMessageCaption', $params);
}

function deleteMsg(int $chatId, int $msgId): void {
    tgApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
}

function inlineKb(array $rows): array {
    return ['inline_keyboard' => $rows];
}

function replyKb(array $rows, bool $resize = true): array {
    return [
        'keyboard'        => $rows,
        'resize_keyboard' => $resize,
        'one_time_keyboard' => false,
    ];
}

function removeKb(): array {
    return ['remove_keyboard' => true];
}

# =========================
# СОСТОЯНИЯ FSM
# =========================

function getState(PDO $pdo, int $userId): ?array {
    try {
        $stmt = $pdo->prepare("SELECT state, data FROM bot_states WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return ['state' => $row['state'], 'data' => json_decode($row['data'], true) ?: []];
    } catch (Exception $e) { return null; }
}

function setState(PDO $pdo, int $userId, string $state, array $data = []): void {
    try {
        $pdo->prepare("INSERT INTO bot_states (user_id, state, data, updated_at) VALUES (?, ?, ?, NOW())
            ON CONFLICT (user_id) DO UPDATE SET state = EXCLUDED.state, data = EXCLUDED.data, updated_at = NOW()")
            ->execute([$userId, $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    } catch (Exception $e) {}
}

function clearState(PDO $pdo, int $userId): void {
    try {
        $pdo->prepare("DELETE FROM bot_states WHERE user_id = ?")->execute([$userId]);
    } catch (Exception $e) {}
}

# =========================
# АРХИВ
# =========================
function logArchive(PDO $pdo, string $type, string $text, int $userId): void {
    try {
        $pdo->prepare("INSERT INTO bot_archive (action_type, action_text, action_by) VALUES (?, ?, ?)")
            ->execute([$type, $text, $userId]);
    } catch (Exception $e) {}
}

# =========================
# REGISTER USER
# =========================
function registerUser(PDO $pdo, int $userId): void {
    try {
        $pdo->prepare("INSERT INTO users (user_id) VALUES (?) ON CONFLICT DO NOTHING")->execute([$userId]);
    } catch (Exception $e) {}
}

# =========================
# СКАЧАТЬ ФАЙЛ ИЗ TELEGRAM
# =========================
function downloadTgFile(string $fileId): ?string {
    global $BOT_TOKEN;
    $res = tgApi('getFile', ['file_id' => $fileId]);
    if (empty($res['ok']) || empty($res['result']['file_path'])) return null;
    $filePath = $res['result']['file_path'];
    $url      = "https://api.telegram.org/file/bot{$BOT_TOKEN}/{$filePath}";
    $ctx      = stream_context_create(['http' => ['timeout' => 90, 'user_agent' => 'Mozilla/5.0']]);
    $data     = @file_get_contents($url, false, $ctx);
    if ($data === false) return null;
    $tmpFile = tempnam(sys_get_temp_dir(), 'tg_');
    file_put_contents($tmpFile, $data);
    return $tmpFile;
}

# =========================
# IMGBB UPLOAD
# =========================
function uploadToImgbb(string $filePath, array $keys, int $retries = 2): ?string {
    foreach ($keys as $key) {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $imageData = base64_encode(file_get_contents($filePath));
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
                if ($httpCode === 400 || $httpCode === 429) break;
            } catch (Exception $e) {}
        }
    }
    return null;
}

function uploadTgFileToImgbb(string $fileId, array $keys): ?string {
    $tmpFile = downloadTgFile($fileId);
    if (!$tmpFile) return null;
    $url = uploadToImgbb($tmpFile, $keys);
    @unlink($tmpFile);
    return $url;
}

# =========================
# ZIP — СОРТИРОВКА ПО ДАТЕ МОДИФИКАЦИИ
# (парсим бинарно как в index.php)
# =========================
function extractAndSortZip(string $zipPath): array {
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $raw = file_get_contents($zipPath);
    if ($raw === false) return [];
    $len = strlen($raw);

    // Найти EOCD
    $eocdPos = false;
    for ($i = $len - 22; $i >= max(0, $len - 65557); $i--) {
        if (substr($raw, $i, 4) === "\x50\x4b\x05\x06") { $eocdPos = $i; break; }
    }
    if ($eocdPos === false) return [];

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
        $mtime  = @mktime($hour, $minute, $second, $month, $day, $year);

        $entries[] = [
            'base'       => $base,
            'ext'        => $ext,
            'mtime'      => $mtime,
            'compress'   => $compress,
            'compSize'   => $compSize,
            'origSize'   => $origSize,
            'dataOffset' => $dataOffset,
        ];
    }

    if (empty($entries)) return [];

    usort($entries, function ($a, $b) {
        if ($a['mtime'] === $b['mtime']) return strnatcasecmp($a['base'], $b['base']);
        return $a['mtime'] - $b['mtime'];
    });

    $extractDir = sys_get_temp_dir() . '/zip_' . uniqid();
    @mkdir($extractDir, 0777, true);
    $paths = [];

    foreach ($entries as $entry) {
        $outPath = $extractDir . '/' . $entry['base'];
        if ($entry['compress'] === 0) {
            $fileData = substr($raw, $entry['dataOffset'], $entry['origSize']);
        } elseif ($entry['compress'] === 8) {
            $compressed = substr($raw, $entry['dataOffset'], $entry['compSize']);
            $fileData = @gzinflate($compressed);
            if ($fileData === false) continue;
        } else { continue; }

        if (file_put_contents($outPath, $fileData) !== false) {
            $paths[] = $outPath;
        }
    }

    return $paths;
}

# =========================
# TELEGRAPH PAGE
# =========================
function createTelegraphPage(string $title, array $imageUrls): ?string {
    global $TELEGRAPH_TOKEN;
    $nodes = [];
    $imageUrls = array_values(array_unique(array_filter($imageUrls)));
    foreach ($imageUrls as $url) {
        if (!empty($url)) $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $url]];
    }
    if (empty($nodes)) return null;

    $postData = http_build_query([
        'access_token'   => $TELEGRAPH_TOKEN,
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
    if (empty($res['ok'])) return null;
    return $res['result']['url'] ?? null;
}

# =========================
# СОХРАНИТЬ СТРАНИЦЫ В БД
# =========================
function saveMangaPages(PDO $pdo, int $mangaId, array $pageUrls): void {
    $pdo->prepare("DELETE FROM manga_pages WHERE manga_id = ?")->execute([$mangaId]);
    $stmt = $pdo->prepare("INSERT INTO manga_pages (manga_id, page_url, page_order) VALUES (?, ?, ?)");
    foreach ($pageUrls as $i => $url) {
        if (!empty($url)) $stmt->execute([$mangaId, $url, $i]);
    }
}

function saveChapterPages(PDO $pdo, int $chapterId, array $pageUrls): void {
    $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id = ?")->execute([$chapterId]);
    $stmt = $pdo->prepare("INSERT INTO manga_chapter_pages (chapter_id, page_url, page_order) VALUES (?, ?, ?)");
    foreach ($pageUrls as $i => $url) {
        if (!empty($url)) $stmt->execute([$chapterId, $url, $i]);
    }
}

# =========================
# ГЛАВНОЕ МЕНЮ
# =========================
function mainMenuKb(): array {
    return replyKb([
        [['text' => '📚 Каталог'], ['text' => '🔍 Поиск']],
        [['text' => '📖 Моя библиотека'], ['text' => '🎲 Случайная манга']],
    ]);
}

function adminMenuKb(): array {
    return replyKb([
        [['text' => '📚 Каталог'], ['text' => '🔍 Поиск']],
        [['text' => '📖 Моя библиотека'], ['text' => '🎲 Случайная манга']],
        [['text' => '➕ Добавить мангу'], ['text' => '📚 Добавить серию']],
        [['text' => '📑 Добавить главу'], ['text' => '📊 Статистика']],
        [['text' => '📋 Архив действий']],
    ]);
}

function startText(string $firstName): string {
    return "👋 Привет, <b>{$firstName}</b>!\n\nДобро пожаловать в <b>BLACKWATCH</b> — manga reader bot.\n\nВыбери действие в меню ниже 👇";
}

# =========================
# ПОКАЗАТЬ МАНГУ (карточка)
# =========================
function mangaCaption(array $manga, ?int $userId = null): string {
    $title = htmlspecialchars($manga['title'], ENT_QUOTES);
    $desc  = !empty($manga['description']) ? "\n\n" . htmlspecialchars(mb_substr($manga['description'], 0, 300), ENT_QUOTES) : '';
    $isSeries = !empty($manga['is_series']);
    $type = $isSeries ? '📚 Серия глав' : '📄 Манга';
    $likes    = (int)($manga['likes'] ?? 0);
    $dislikes = (int)($manga['dislikes'] ?? 0);
    return "<b>{$title}</b>\n{$type}{$desc}\n\n👍 {$likes}  👎 {$dislikes}";
}

function mangaInlineKb(int $mangaId, bool $isSeries, string $siteUrl, ?string $telegraphUrl = null): array {
    $rows = [];
    if ($isSeries) {
        $rows[] = [['text' => '📚 Главы', 'callback_data' => "chapters:{$mangaId}:0"]];
    } else {
        $btnRead = ['text' => '📖 Читать', 'url' => "{$siteUrl}/read/{$mangaId}"];
        if ($telegraphUrl) {
            $rows[] = [$btnRead, ['text' => '📄 Telegraph', 'url' => $telegraphUrl]];
        } else {
            $rows[] = [$btnRead];
        }
    }
    $rows[] = [
        ['text' => '👍 Лайк',    'callback_data' => "vote:like:{$mangaId}"],
        ['text' => '👎 Дизлайк', 'callback_data' => "vote:dislike:{$mangaId}"],
    ];
    $rows[] = [
        ['text' => '📖 Читаю',       'callback_data' => "status:now:{$mangaId}"],
        ['text' => '🔖 Буду читать', 'callback_data' => "status:will:{$mangaId}"],
        ['text' => '✅ Прочитано',   'callback_data' => "status:read:{$mangaId}"],
    ];
    return inlineKb($rows);
}

# =========================
# КАТАЛОГ
# =========================
function showCatalog(int $chatId, PDO $pdo, string $siteUrl, int $page = 0, string $q = '', string $sort = 'new'): void {
    $limit  = 8;
    $offset = $page * $limit;
    $orderBy = 'id DESC';
    if ($sort === 'popular') $orderBy = 'likes DESC, id DESC';
    if ($sort === 'alpha')   $orderBy = 'title ASC';

    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, is_series, telegraph_url FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");
        $cntStmt->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, is_series, telegraph_url FROM manga ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $cntStmt = $pdo->query("SELECT COUNT(*) FROM manga");
    }
    $items = $stmt->fetchAll();
    $total = (int)$cntStmt->fetchColumn();

    if (empty($items)) {
        sendMsg($chatId, $q ? "😔 По запросу <b>" . htmlspecialchars($q) . "</b> ничего не найдено." : "😔 Каталог пуст.");
        return;
    }

    $text = $q ? "🔍 Результаты по запросу <b>" . htmlspecialchars($q) . "</b>:\n\n" : "📚 <b>Каталог манги</b>\n\n";
    foreach ($items as $i => $m) {
        $serLabel = $m['is_series'] ? ' 📚' : '';
        $text .= ($offset + $i + 1) . ". <b>" . htmlspecialchars($m['title']) . "</b>{$serLabel} — 👍 {$m['likes']}\n";
    }
    $text .= "\nВсего: {$total}";

    // Кнопки манги
    $rows = [];
    foreach ($items as $m) {
        $rows[] = [['text' => ($m['is_series'] ? '📚 ' : '📖 ') . mb_substr($m['title'], 0, 40), 'callback_data' => "manga:{$m['id']}"]];
    }

    // Пагинация
    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '← Назад', 'callback_data' => "catalog:{$page-1}:{$sort}:" . urlencode($q)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => 'Вперёд →', 'callback_data' => "catalog:{$page+1}:{$sort}:" . urlencode($q)];
    if (!empty($navRow)) $rows[] = $navRow;

    // Сортировка
    $sortRow = [
        ['text' => ($sort === 'new' ? '✅ ' : '') . '🕒 Новые', 'callback_data' => "catalog:0:new:" . urlencode($q)],
        ['text' => ($sort === 'popular' ? '✅ ' : '') . '🔥 Топ',  'callback_data' => "catalog:0:popular:" . urlencode($q)],
        ['text' => ($sort === 'alpha' ? '✅ ' : '') . '🔤 А-Я',   'callback_data' => "catalog:0:alpha:" . urlencode($q)],
    ];
    $rows[] = $sortRow;

    sendMsg($chatId, $text, inlineKb($rows));
}

# =========================
# ПОКАЗАТЬ ОДНУ МАНГУ
# =========================
function showManga(int $chatId, int $mangaId, PDO $pdo, string $siteUrl): void {
    $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
    $stmt->execute([$mangaId]);
    $manga = $stmt->fetch();
    if (!$manga) { sendMsg($chatId, '❌ Манга не найдена.'); return; }

    $caption = mangaCaption($manga);
    $kb = mangaInlineKb($mangaId, (bool)$manga['is_series'], $siteUrl, $manga['telegraph_url']);

    if (!empty($manga['cover_imgbb_url'])) {
        $res = sendPhoto($chatId, $manga['cover_imgbb_url'], $caption, $kb);
        if (empty($res['ok'])) sendMsg($chatId, $caption, $kb);
    } else {
        sendMsg($chatId, $caption, $kb);
    }
}

# =========================
# ПОКАЗАТЬ ГЛАВЫ
# =========================
function showChapters(int $chatId, int $msgId, int $mangaId, PDO $pdo, string $siteUrl, int $page = 0): void {
    $limit  = 10;
    $offset = $page * $limit;
    $stmt   = $pdo->prepare("SELECT id, chapter_num, title, telegraph_url FROM manga_chapters WHERE manga_id = ? ORDER BY chapter_num ASC LIMIT ? OFFSET ?");
    $stmt->execute([$mangaId, $limit, $offset]);
    $chapters = $stmt->fetchAll();
    $total    = (int)$pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE manga_id = ?")->execute([$mangaId]) ? (int)$pdo->query("SELECT COUNT(*) FROM manga_chapters WHERE manga_id = {$mangaId}")->fetchColumn() : 0;

    // пересчёт
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE manga_id = ?");
    $cStmt->execute([$mangaId]);
    $total = (int)$cStmt->fetchColumn();

    $mangaTitleStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
    $mangaTitleStmt->execute([$mangaId]);
    $mangaTitle = $mangaTitleStmt->fetchColumn() ?: 'Серия';

    if (empty($chapters)) {
        tgApi('editMessageReplyMarkup', [
            'chat_id' => $chatId, 'message_id' => $msgId,
            'reply_markup' => inlineKb([[['text' => '← Назад', 'callback_data' => "manga:{$mangaId}"]]])
        ]);
        sendMsg($chatId, '📭 Глав пока нет.');
        return;
    }

    $rows = [];
    foreach ($chapters as $ch) {
        $chLabel = "Глава {$ch['chapter_num']}" . ($ch['title'] ? ": {$ch['title']}" : '');
        $url = !empty($ch['telegraph_url']) ? $ch['telegraph_url'] : "{$siteUrl}/view-chapter/{$ch['id']}";
        $rows[] = [['text' => $chLabel, 'url' => $url]];
    }

    // Пагинация
    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '←', 'callback_data' => "chapters:{$mangaId}:" . ($page - 1)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => '→', 'callback_data' => "chapters:{$mangaId}:" . ($page + 1)];
    if (!empty($navRow)) $rows[] = $navRow;
    $rows[] = [['text' => '← К манге', 'callback_data' => "manga:{$mangaId}"]];

    $text = "📚 <b>" . htmlspecialchars($mangaTitle) . "</b>\nГлав: {$total}";
    editMsg($chatId, $msgId, $text, inlineKb($rows));
}

# =========================
# СТАТИСТИКА
# =========================
function showStats(int $chatId, PDO $pdo): void {
    $mangaCount    = (int)$pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
    $seriesCount   = (int)$pdo->query("SELECT COUNT(*) FROM manga WHERE is_series = TRUE")->fetchColumn();
    $chaptersCount = (int)$pdo->query("SELECT COUNT(*) FROM manga_chapters")->fetchColumn();
    $usersCount    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $votesCount    = (int)$pdo->query("SELECT COUNT(*) FROM votes")->fetchColumn();
    $pagesCount    = (int)$pdo->query("SELECT COUNT(*) FROM manga_pages")->fetchColumn();
    $newToday      = (int)$pdo->query("SELECT COUNT(*) FROM manga WHERE created_at >= NOW() - INTERVAL '24 hours'")->fetchColumn();

    $topManga = $pdo->query("SELECT title, likes FROM manga ORDER BY likes DESC LIMIT 5")->fetchAll();
    $topText  = '';
    foreach ($topManga as $i => $m) {
        $topText .= "\n" . ($i + 1) . ". " . htmlspecialchars($m['title']) . " — 👍 {$m['likes']}";
    }

    $text = "📊 <b>Статистика BLACKWATCH</b>\n\n"
        . "📚 Манг в каталоге: <b>{$mangaCount}</b>\n"
        . "📖 Серий: <b>{$seriesCount}</b>\n"
        . "📑 Глав: <b>{$chaptersCount}</b>\n"
        . "📄 Страниц: <b>{$pagesCount}</b>\n"
        . "👥 Пользователей: <b>{$usersCount}</b>\n"
        . "🗳 Голосов: <b>{$votesCount}</b>\n"
        . "🆕 Добавлено за 24ч: <b>{$newToday}</b>\n"
        . "\n🏆 <b>Топ по лайкам:</b>{$topText}";

    sendMsg($chatId, $text);
}

# =========================
# АРХИВ
# =========================
function showArchive(int $chatId, PDO $pdo, int $page = 0): void {
    $limit  = 10;
    $offset = $page * $limit;
    $stmt   = $pdo->prepare("SELECT * FROM bot_archive ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    $items = $stmt->fetchAll();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();

    if (empty($items)) { sendMsg($chatId, '📋 Архив пуст.'); return; }

    $typeEmoji = [
        'add_manga' => '➕', 'edit_manga' => '✏️', 'delete_manga' => '🗑',
        'add_chapter' => '📚', 'delete_chapter' => '🗑', 'add_series' => '📖',
    ];
    $text = "📋 <b>Архив действий</b> (стр. " . ($page + 1) . "):\n\n";
    foreach ($items as $a) {
        $emoji = $typeEmoji[$a['action_type']] ?? '•';
        $date  = date('d.m.Y H:i', strtotime($a['created_at']));
        $text .= "{$emoji} <b>{$a['action_type']}</b>\n";
        $text .= htmlspecialchars(mb_substr($a['action_text'], 0, 100)) . "\n";
        $text .= "<i>{$date}</i>\n\n";
    }

    $rows   = [];
    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '← Назад', 'callback_data' => "archive:" . ($page - 1)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => 'Вперёд →', 'callback_data' => "archive:" . ($page + 1)];
    if (!empty($navRow)) $rows[] = $navRow;

    sendMsg($chatId, $text, $rows ? inlineKb($rows) : []);
}

# =========================
# БИБЛИОТЕКА
# =========================
function showLibrary(int $chatId, int $userId, PDO $pdo, string $siteUrl): void {
    $stmt = $pdo->prepare("SELECT m.id, m.title, m.is_series, s.status FROM user_manga_status s JOIN manga m ON s.manga_id = m.id WHERE s.user_id = ? ORDER BY s.status ASC");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        sendMsg($chatId, "📭 Твоя библиотека пуста.\n\nНайди мангу в каталоге и добавь её!", inlineKb([[['text' => '📚 Открыть каталог', 'callback_data' => 'catalog:0:new:']]]));
        return;
    }

    $statusLabel = ['now' => '📖 Читаю', 'will' => '🔖 Буду читать', 'read' => '✅ Прочитано'];
    $grouped = ['now' => [], 'will' => [], 'read' => []];
    foreach ($items as $m) $grouped[$m['status']][] = $m;

    $text  = "📚 <b>Моя библиотека</b>\n\n";
    $rows  = [];
    foreach (['now', 'will', 'read'] as $s) {
        if (empty($grouped[$s])) continue;
        $text .= "<b>" . $statusLabel[$s] . " (" . count($grouped[$s]) . ")</b>\n";
        foreach ($grouped[$s] as $m) {
            $text .= "• " . htmlspecialchars($m['title']) . "\n";
            $rows[] = [['text' => mb_substr($m['title'], 0, 50), 'callback_data' => "manga:{$m['id']}"]];
        }
        $text .= "\n";
    }

    $rows[] = [['text' => '🌐 Открыть на сайте', 'url' => "{$siteUrl}/library"]];
    sendMsg($chatId, $text, inlineKb($rows));
}

# =========================
# ОБРАБОТКА ZIP И ЗАГРУЗКА СТРАНИЦ
# =========================
function processAndUploadZip(string $zipPath, array $imgbbKeys, int $chatId, ?int $totalNotifyThreshold = 5): array {
    $files = extractAndSortZip($zipPath);
    if (empty($files)) return [];

    $total    = count($files);
    $urls     = [];
    $notifyAt = max(1, (int)floor($total / 4));

    sendMsg($chatId, "📦 Извлечено страниц: <b>{$total}</b>\n⏳ Начинаю загрузку на ImgBB...");

    foreach ($files as $i => $filePath) {
        $url = uploadToImgbb($filePath, $imgbbKeys);
        @unlink($filePath);
        if ($url) $urls[] = $url;

        // Уведомляем о прогрессе
        if ($total >= $totalNotifyThreshold && (($i + 1) % $notifyAt === 0 || ($i + 1) === $total)) {
            $pct = round(($i + 1) / $total * 100);
            sendMsg($chatId, "⬆️ Загружено: <b>" . ($i + 1) . "/{$total}</b> ({$pct}%)");
        }
    }

    // Удаляем временную директорию
    if (!empty($files)) {
        @rmdir(dirname($files[0]));
    }

    return $urls;
}

function processAndUploadPhotos(array $fileIds, array $imgbbKeys, int $chatId): array {
    $total = count($fileIds);
    $urls  = [];
    $notifyAt = max(1, (int)floor($total / 4));

    if ($total > 1) sendMsg($chatId, "🖼 Фотографий: <b>{$total}</b>\n⏳ Загружаю на ImgBB...");

    foreach ($fileIds as $i => $fileId) {
        $url = uploadTgFileToImgbb($fileId, $imgbbKeys);
        if ($url) $urls[] = $url;

        if ($total >= 5 && (($i + 1) % $notifyAt === 0 || ($i + 1) === $total)) {
            $pct = round(($i + 1) / $total * 100);
            sendMsg($chatId, "⬆️ Загружено: <b>" . ($i + 1) . "/{$total}</b> ({$pct}%)");
        }
    }

    return $urls;
}

# =========================
# ВЫБОР СЕРИИ ДЛЯ ДОБАВЛЕНИЯ ГЛАВЫ
# =========================
function showSeriesList(int $chatId, PDO $pdo, int $page = 0, string $q = ''): void {
    $limit  = 8;
    $offset = $page * $limit;
    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE is_series = TRUE AND LOWER(title) LIKE LOWER(?) ORDER BY title ASC LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE is_series = TRUE AND LOWER(title) LIKE LOWER(?)");
        $cStmt->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE is_series = TRUE ORDER BY title ASC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $cStmt = $pdo->query("SELECT COUNT(*) FROM manga WHERE is_series = TRUE");
    }
    $items = $stmt->fetchAll();
    $total = (int)$cStmt->fetchColumn();

    if (empty($items)) {
        sendMsg($chatId, "📭 Серий не найдено.\n\nСначала создай серию командой /addseries или кнопкой «📚 Добавить серию».");
        return;
    }

    $rows = [];
    foreach ($items as $m) {
        $rows[] = [['text' => mb_substr($m['title'], 0, 50), 'callback_data' => "selch:{$m['id']}"]];
    }

    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '←', 'callback_data' => "seriespick:{$page-1}:" . urlencode($q)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => '→', 'callback_data' => "seriespick:{$page+1}:" . urlencode($q)];
    if (!empty($navRow)) $rows[] = $navRow;

    sendMsg($chatId, "📚 Выбери серию для добавления главы:", inlineKb($rows));
}

# =========================
# ГОЛОСОВАНИЕ
# =========================
function handleVote(int $userId, int $mangaId, string $voteType, PDO $pdo): array {
    $check = $pdo->prepare("SELECT vote_type FROM votes WHERE user_id = ? AND manga_id = ?");
    $check->execute([$userId, $mangaId]);
    $existing = $check->fetch();

    if ($existing) {
        if ($existing['vote_type'] !== $voteType) {
            $pdo->prepare("UPDATE votes SET vote_type = ? WHERE user_id = ? AND manga_id = ?")->execute([$voteType, $userId, $mangaId]);
            if ($voteType === 'like') {
                $pdo->prepare("UPDATE manga SET likes = likes + 1, dislikes = GREATEST(0, dislikes - 1) WHERE id = ?")->execute([$mangaId]);
            } else {
                $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1, likes = GREATEST(0, likes - 1) WHERE id = ?")->execute([$mangaId]);
            }
        }
    } else {
        $pdo->prepare("INSERT INTO votes (user_id, manga_id, vote_type) VALUES (?, ?, ?)")->execute([$userId, $mangaId, $voteType]);
        if ($voteType === 'like') {
            $pdo->prepare("UPDATE manga SET likes = likes + 1 WHERE id = ?")->execute([$mangaId]);
        } else {
            $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1 WHERE id = ?")->execute([$mangaId]);
        }
    }

    $stmt = $pdo->prepare("SELECT likes, dislikes FROM manga WHERE id = ?");
    $stmt->execute([$mangaId]);
    return $stmt->fetch() ?: ['likes' => 0, 'dislikes' => 0];
}

# =========================
# РАЗБОР АПДЕЙТА
# =========================
$message       = $update['message']        ?? null;
$callbackQuery = $update['callback_query'] ?? null;

$userId   = 0;
$chatId   = 0;
$msgId    = 0;
$text     = '';
$firstName = '';

if ($message) {
    $userId    = (int)($message['from']['id'] ?? 0);
    $chatId    = (int)($message['chat']['id'] ?? 0);
    $msgId     = (int)($message['message_id'] ?? 0);
    $text      = trim($message['text'] ?? '');
    $firstName = $message['from']['first_name'] ?? 'друг';
} elseif ($callbackQuery) {
    $userId    = (int)($callbackQuery['from']['id'] ?? 0);
    $chatId    = (int)($callbackQuery['message']['chat']['id'] ?? 0);
    $msgId     = (int)($callbackQuery['message']['message_id'] ?? 0);
    $text      = $callbackQuery['data'] ?? '';
    $firstName = $callbackQuery['from']['first_name'] ?? 'друг';
}

if (!$userId || !$chatId) {
    http_response_code(200); echo 'OK'; exit;
}

registerUser($pdo, $userId);

$isAdmin = isAdmin($userId, $HARDCODED_ADMINS);
$state   = getState($pdo, $userId);

# =========================
# CALLBACK QUERY
# =========================
if ($callbackQuery) {
    $cbId   = $callbackQuery['id'];
    $cbData = $callbackQuery['data'] ?? '';

    // noop
    if ($cbData === 'noop') {
        answerCallback($cbId);
        http_response_code(200); echo 'OK'; exit;
    }

    // catalog:page:sort:q
    if (preg_match('/^catalog:(\d+):(\w+):(.*)$/', $cbData, $m)) {
        answerCallback($cbId);
        $pg   = (int)$m[1];
        $sort = $m[2];
        $q    = urldecode($m[3]);
        deleteMsg($chatId, $msgId);
        showCatalog($chatId, $pdo, $SITE_URL, $pg, $q, $sort);
        http_response_code(200); echo 'OK'; exit;
    }

    // manga:id
    if (preg_match('/^manga:(\d+)$/', $cbData, $m)) {
        answerCallback($cbId);
        showManga($chatId, (int)$m[1], $pdo, $SITE_URL);
        http_response_code(200); echo 'OK'; exit;
    }

    // chapters:manga_id:page
    if (preg_match('/^chapters:(\d+):(\d+)$/', $cbData, $m)) {
        answerCallback($cbId);
        showChapters($chatId, $msgId, (int)$m[1], $pdo, $SITE_URL, (int)$m[2]);
        http_response_code(200); echo 'OK'; exit;
    }

    // vote:type:manga_id
    if (preg_match('/^vote:(like|dislike):(\d+)$/', $cbData, $m)) {
        $voteType = $m[1];
        $mangaId  = (int)$m[2];
        $stats    = handleVote($userId, $mangaId, $voteType, $pdo);
        answerCallback($cbId, $voteType === 'like' ? '👍 Лайк учтён!' : '👎 Дизлайк учтён!');
        http_response_code(200); echo 'OK'; exit;
    }

    // status:type:manga_id
    if (preg_match('/^status:(now|will|read):(\d+)$/', $cbData, $m)) {
        $statusType = $m[1];
        $mangaId    = (int)$m[2];
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON CONFLICT (user_id, manga_id) DO UPDATE SET status = EXCLUDED.status")
            ->execute([$userId, $mangaId, $statusType]);
        $labels = ['now' => '📖 Отмечено: Читаю!', 'will' => '🔖 Добавлено в список!', 'read' => '✅ Отмечено как прочитанное!'];
        answerCallback($cbId, $labels[$statusType], true);
        http_response_code(200); echo 'OK'; exit;
    }

    // archive:page
    if (preg_match('/^archive:(\d+)$/', $cbData, $m)) {
        answerCallback($cbId);
        deleteMsg($chatId, $msgId);
        showArchive($chatId, $pdo, (int)$m[1]);
        http_response_code(200); echo 'OK'; exit;
    }

    // seriespick:page:q (пагинация выбора серии)
    if (preg_match('/^seriespick:(\d+):(.*)$/', $cbData, $m)) {
        answerCallback($cbId);
        deleteMsg($chatId, $msgId);
        showSeriesList($chatId, $pdo, (int)$m[1], urldecode($m[2]));
        http_response_code(200); echo 'OK'; exit;
    }

    // selch:manga_id — выбрана серия для добавления главы
    if (preg_match('/^selch:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        $mangaId = (int)$m[1];
        $mStmt   = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $mStmt->execute([$mangaId]);
        $mangaTitle = $mStmt->fetchColumn();
        setState($pdo, $userId, 'add_chapter', ['step' => 'num', 'manga_id' => $mangaId, 'manga_title' => $mangaTitle]);
        deleteMsg($chatId, $msgId);
        sendMsg($chatId, "📚 Серия: <b>" . htmlspecialchars($mangaTitle) . "</b>\n\nВведи <b>номер главы</b> (можно дробный: 1, 2, 2.5):");
        http_response_code(200); echo 'OK'; exit;
    }

    // cancel_state
    if ($cbData === 'cancel_state') {
        answerCallback($cbId);
        clearState($pdo, $userId);
        deleteMsg($chatId, $msgId);
        sendMsg($chatId, '❌ Отменено.', $isAdmin ? adminMenuKb() : mainMenuKb());
        http_response_code(200); echo 'OK'; exit;
    }

    answerCallback($cbId);
    http_response_code(200); echo 'OK'; exit;
}

# =========================
# MESSAGE HANDLER
# =========================
if (!$message) {
    http_response_code(200); echo 'OK'; exit;
}

# --- Обработка состояний FSM ---
if ($state) {
    $currentState = $state['state'];
    $stateData    = $state['data'];

    # ========================
    # FSM: ADD_MANGA
    # ========================
    if ($currentState === 'add_manga') {
        $step = $stateData['step'] ?? 'title';

        // Отмена
        if ($text === '/cancel' || $text === '❌ Отмена') {
            clearState($pdo, $userId);
            sendMsg($chatId, '❌ Добавление манги отменено.', adminMenuKb());
            http_response_code(200); echo 'OK'; exit;
        }

        if ($step === 'title') {
            if (empty($text)) {
                sendMsg($chatId, '❌ Введи название манги:');
            } else {
                $stateData['title'] = $text;
                $stateData['step']  = 'desc';
                setState($pdo, $userId, 'add_manga', $stateData);
                sendMsg($chatId, "✅ Название: <b>" . htmlspecialchars($text) . "</b>\n\nВведи <b>описание</b> (или напиши «пропустить»):");
            }
        } elseif ($step === 'desc') {
            $stateData['description'] = ($text === 'пропустить' || $text === '-') ? '' : $text;
            $stateData['step']        = 'cover';
            setState($pdo, $userId, 'add_manga', $stateData);
            sendMsg($chatId, "✅ Описание сохранено.\n\nОтправь <b>обложку</b> (фото), или напиши «пропустить»:");
        } elseif ($step === 'cover') {
            $coverUrl = null;
            if (!empty($message['photo'])) {
                $photo      = end($message['photo']);
                $fileId     = $photo['file_id'];
                sendMsg($chatId, '⏳ Загружаю обложку на ImgBB...');
                $coverUrl = uploadTgFileToImgbb($fileId, $IMGBB_KEYS);
                if (!$coverUrl) sendMsg($chatId, '⚠️ Не удалось загрузить обложку, продолжаем без неё.');
            } elseif ($text !== 'пропустить' && $text !== '-' && filter_var($text, FILTER_VALIDATE_URL)) {
                $coverUrl = $text;
            }
            $stateData['cover_url'] = $coverUrl;
            $stateData['step']      = 'pages';
            $stateData['pages']     = [];
            setState($pdo, $userId, 'add_manga', $stateData);
            sendMsg($chatId,
                "✅ Обложка " . ($coverUrl ? 'загружена' : 'пропущена') . ".\n\n"
                . "Теперь отправь <b>страницы манги</b>:\n"
                . "• 📦 ZIP-архив — страницы будут отсортированы по дате внутри архива\n"
                . "• 📸 Фото — отправляй по одному или альбомом\n\n"
                . "Когда закончишь — напиши /done",
                inlineKb([[['text' => '✅ Готово (/done)', 'callback_data' => 'noop'], ['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]])
            );
        } elseif ($step === 'pages') {
            // /done — завершить
            if ($text === '/done' || $text === 'готово') {
                $pages = $stateData['pages'] ?? [];
                if (empty($pages) && empty($stateData['cover_url'])) {
                    sendMsg($chatId, '⚠️ Нет страниц и обложки. Добавь хотя бы что-то или отмени (/cancel).');
                    http_response_code(200); echo 'OK'; exit;
                }
                clearState($pdo, $userId);
                sendMsg($chatId, '⏳ Создаю Telegraph страницу и сохраняю мангу...');

                $telegraphUrl = null;
                if (!empty($pages)) {
                    $telegraphUrl = createTelegraphPage('♥ ' . $stateData['title'], $pages);
                }

                $insertStmt = $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, cover_imgbb_url, added_by, is_series) VALUES (?, ?, ?, ?, ?, FALSE) RETURNING id");
                $insertStmt->execute(['♥ ' . $stateData['title'], $telegraphUrl, $stateData['description'] ?? '', $stateData['cover_url'], $userId]);
                $row      = $insertStmt->fetch();
                $mangaId  = (int)($row['id'] ?? 0);
                if (!$mangaId) $mangaId = (int)$pdo->lastInsertId();

                if ($mangaId && !empty($pages)) {
                    saveMangaPages($pdo, $mangaId, $pages);
                }

                logArchive($pdo, 'add_manga', "Добавлена манга: ♥ {$stateData['title']}", $userId);

                $resultText = "✅ <b>Манга добавлена!</b>\n\n"
                    . "📖 " . htmlspecialchars('♥ ' . $stateData['title']) . "\n"
                    . "📄 Страниц: " . count($pages) . "\n";
                if ($telegraphUrl) $resultText .= "📰 Telegraph: {$telegraphUrl}\n";
                $resultText .= "🌐 Сайт: {$SITE_URL}/read/{$mangaId}";

                sendMsg($chatId, $resultText, $isAdmin ? adminMenuKb() : mainMenuKb());
                http_response_code(200); echo 'OK'; exit;
            }

            // ZIP документ
            if (!empty($message['document'])) {
                $doc      = $message['document'];
                $fileName = strtolower($doc['file_name'] ?? '');
                if (str_ends_with($fileName, '.zip')) {
                    sendMsg($chatId, '⏳ Скачиваю ZIP-архив...');
                    $zipPath = downloadTgFile($doc['file_id']);
                    if (!$zipPath) {
                        sendMsg($chatId, '❌ Не удалось скачать архив. Попробуй ещё раз.');
                    } else {
                        $urls = processAndUploadZip($zipPath, $IMGBB_KEYS, $chatId);
                        @unlink($zipPath);
                        if (!empty($urls)) {
                            $stateData['pages'] = array_merge($stateData['pages'] ?? [], $urls);
                            setState($pdo, $userId, 'add_manga', $stateData);
                            $total = count($stateData['pages']);
                            sendMsg($chatId, "✅ Загружено страниц из ZIP: <b>" . count($urls) . "</b>\nВсего страниц: <b>{$total}</b>\n\nОтправь ещё или напиши /done");
                        } else {
                            sendMsg($chatId, '❌ Не удалось загрузить страницы из архива.');
                        }
                    }
                } else {
                    sendMsg($chatId, '⚠️ Поддерживаются только ZIP-архивы.');
                }
                http_response_code(200); echo 'OK'; exit;
            }

            // Фото
            if (!empty($message['photo'])) {
                $photo  = end($message['photo']);
                $fileId = $photo['file_id'];
                $url    = uploadTgFileToImgbb($fileId, $IMGBB_KEYS);
                if ($url) {
                    $stateData['pages'][] = $url;
                    setState($pdo, $userId, 'add_manga', $stateData);
                    $total = count($stateData['pages']);
                    sendMsg($chatId, "✅ Страница {$total} загружена. Отправь ещё или /done");
                } else {
                    sendMsg($chatId, '❌ Не удалось загрузить фото. Попробуй ещё раз.');
                }
                http_response_code(200); echo 'OK'; exit;
            }
        }

        http_response_code(200); echo 'OK'; exit;
    }

    # ========================
    # FSM: ADD_SERIES
    # ========================
    if ($currentState === 'add_series') {
        $step = $stateData['step'] ?? 'title';

        if ($text === '/cancel' || $text === '❌ Отмена') {
            clearState($pdo, $userId);
            sendMsg($chatId, '❌ Отменено.', adminMenuKb());
            http_response_code(200); echo 'OK'; exit;
        }

        if ($step === 'title') {
            if (empty($text)) {
                sendMsg($chatId, '❌ Введи название серии:');
            } else {
                $stateData['title'] = $text;
                $stateData['step']  = 'desc';
                setState($pdo, $userId, 'add_series', $stateData);
                sendMsg($chatId, "✅ Название: <b>" . htmlspecialchars($text) . "</b>\n\nВведи <b>описание</b> (или «пропустить»):");
            }
        } elseif ($step === 'desc') {
            $stateData['description'] = ($text === 'пропустить' || $text === '-') ? '' : $text;
            $stateData['step']        = 'cover';
            setState($pdo, $userId, 'add_series', $stateData);
            sendMsg($chatId, "✅ Описание сохранено.\n\nОтправь <b>обложку</b> (фото) или «пропустить»:");
        } elseif ($step === 'cover') {
            $coverUrl = null;
            if (!empty($message['photo'])) {
                $photo  = end($message['photo']);
                sendMsg($chatId, '⏳ Загружаю обложку...');
                $coverUrl = uploadTgFileToImgbb($photo['file_id'], $IMGBB_KEYS);
            }
            $stateData['cover_url'] = $coverUrl;
            clearState($pdo, $userId);

            $insertStmt = $pdo->prepare("INSERT INTO manga (title, description, cover_imgbb_url, added_by, is_series) VALUES (?, ?, ?, ?, TRUE) RETURNING id");
            $insertStmt->execute(['♥ ' . $stateData['title'], $stateData['description'] ?? '', $coverUrl, $userId]);
            $row     = $insertStmt->fetch();
            $mangaId = (int)($row['id'] ?? 0);
            if (!$mangaId) $mangaId = (int)$pdo->lastInsertId();

            logArchive($pdo, 'add_series', "Создана серия: ♥ {$stateData['title']}", $userId);

            sendMsg($chatId,
                "✅ <b>Серия создана!</b>\n\n📚 " . htmlspecialchars('♥ ' . $stateData['title']) . "\nID: {$mangaId}\n\n"
                . "Теперь добавляй главы через «📑 Добавить главу»\n🌐 {$SITE_URL}/read/{$mangaId}",
                adminMenuKb()
            );
        }

        http_response_code(200); echo 'OK'; exit;
    }

    # ========================
    # FSM: ADD_CHAPTER
    # ========================
    if ($currentState === 'add_chapter') {
        $step = $stateData['step'] ?? 'num';

        if ($text === '/cancel' || $text === '❌ Отмена') {
            clearState($pdo, $userId);
            sendMsg($chatId, '❌ Отменено.', adminMenuKb());
            http_response_code(200); echo 'OK'; exit;
        }

        if ($step === 'num') {
            $num = (float)str_replace(',', '.', $text);
            if ($num <= 0) {
                sendMsg($chatId, '❌ Введи корректный номер главы (например: 1, 2, 2.5):');
            } else {
                $stateData['chapter_num'] = $num;
                $stateData['step']        = 'title';
                setState($pdo, $userId, 'add_chapter', $stateData);
                sendMsg($chatId, "✅ Глава <b>{$num}</b>\n\nВведи <b>название главы</b> (или «пропустить»):");
            }
        } elseif ($step === 'title') {
            $stateData['chapter_title'] = ($text === 'пропустить' || $text === '-') ? '' : $text;
            $stateData['step']          = 'pages';
            $stateData['pages']         = [];
            setState($pdo, $userId, 'add_chapter', $stateData);
            $chNum  = $stateData['chapter_num'];
            $chTitle = $stateData['chapter_title'] ? ": {$stateData['chapter_title']}" : '';
            sendMsg($chatId,
                "✅ Глава {$chNum}{$chTitle}\n\nОтправь <b>страницы главы</b>:\n"
                . "• 📦 ZIP-архив (сортировка по дате внутри архива)\n"
                . "• 📸 Фото по одному или альбомом\n\n"
                . "Когда закончишь — /done",
                inlineKb([[['text' => '✅ Готово (/done)', 'callback_data' => 'noop'], ['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]])
            );
        } elseif ($step === 'pages') {
            if ($text === '/done' || $text === 'готово') {
                $pages = $stateData['pages'] ?? [];
                if (empty($pages)) {
                    sendMsg($chatId, '⚠️ Нет страниц! Добавь хотя бы одну страницу или отмени (/cancel).');
                    http_response_code(200); echo 'OK'; exit;
                }

                clearState($pdo, $userId);
                sendMsg($chatId, '⏳ Создаю Telegraph страницу...');

                $mangaId    = (int)$stateData['manga_id'];
                $mangaTitle = $stateData['manga_title'] ?? 'Манга';
                $chNum      = (float)$stateData['chapter_num'];
                $chTitle    = $stateData['chapter_title'] ?? '';
                $chLabel    = "Глава {$chNum}" . ($chTitle ? ": {$chTitle}" : '');

                $telegraphUrl = createTelegraphPage($mangaTitle . ' — ' . $chLabel, $pages);

                $chInsert = $pdo->prepare("INSERT INTO manga_chapters (manga_id, chapter_num, title, telegraph_url) VALUES (?, ?, ?, ?) RETURNING id");
                $chInsert->execute([$mangaId, $chNum, $chTitle ?: null, $telegraphUrl]);
                $chRow     = $chInsert->fetch();
                $chapterId = (int)($chRow['id'] ?? 0);

                if ($chapterId && !empty($pages)) {
                    saveChapterPages($pdo, $chapterId, $pages);
                }

                // Помечаем как серию
                $pdo->prepare("UPDATE manga SET is_series = TRUE WHERE id = ?")->execute([$mangaId]);

                logArchive($pdo, 'add_chapter', "Добавлена глава {$chNum} для манги: {$mangaTitle}", $userId);

                $resultText = "✅ <b>Глава добавлена!</b>\n\n"
                    . "📚 " . htmlspecialchars($mangaTitle) . "\n"
                    . "📑 {$chLabel}\n"
                    . "📄 Страниц: " . count($pages) . "\n";
                if ($telegraphUrl) $resultText .= "📰 Telegraph: {$telegraphUrl}\n";
                $resultText .= "🌐 {$SITE_URL}/read/{$mangaId}";

                sendMsg($chatId, $resultText, adminMenuKb());
                http_response_code(200); echo 'OK'; exit;
            }

            // ZIP
            if (!empty($message['document'])) {
                $doc      = $message['document'];
                $fileName = strtolower($doc['file_name'] ?? '');
                if (str_ends_with($fileName, '.zip')) {
                    sendMsg($chatId, '⏳ Скачиваю ZIP...');
                    $zipPath = downloadTgFile($doc['file_id']);
                    if ($zipPath) {
                        $urls = processAndUploadZip($zipPath, $IMGBB_KEYS, $chatId);
                        @unlink($zipPath);
                        if (!empty($urls)) {
                            $stateData['pages'] = array_merge($stateData['pages'] ?? [], $urls);
                            setState($pdo, $userId, 'add_chapter', $stateData);
                            $total = count($stateData['pages']);
                            sendMsg($chatId, "✅ Из ZIP загружено: <b>" . count($urls) . "</b> стр.\nВсего: <b>{$total}</b>\n\nОтправь ещё или /done");
                        } else {
                            sendMsg($chatId, '❌ Не удалось загрузить из ZIP.');
                        }
                    } else {
                        sendMsg($chatId, '❌ Не удалось скачать архив.');
                    }
                }
                http_response_code(200); echo 'OK'; exit;
            }

            // Фото
            if (!empty($message['photo'])) {
                $photo = end($message['photo']);
                $url   = uploadTgFileToImgbb($photo['file_id'], $IMGBB_KEYS);
                if ($url) {
                    $stateData['pages'][] = $url;
                    setState($pdo, $userId, 'add_chapter', $stateData);
                    $total = count($stateData['pages']);
                    sendMsg($chatId, "✅ Страница {$total} загружена. Ещё или /done");
                } else {
                    sendMsg($chatId, '❌ Не удалось загрузить фото.');
                }
                http_response_code(200); echo 'OK'; exit;
            }
        }

        http_response_code(200); echo 'OK'; exit;
    }

    # ========================
    # FSM: SEARCH
    # ========================
    if ($currentState === 'search') {
        clearState($pdo, $userId);
        if (!empty($text) && $text[0] !== '/') {
            showCatalog($chatId, $pdo, $SITE_URL, 0, $text);
        }
        http_response_code(200); echo 'OK'; exit;
    }
}

# =========================
# КОМАНДЫ И КНОПКИ
# =========================
$cmd = '';
if ($text && $text[0] === '/') {
    $parts = explode(' ', $text, 2);
    $cmd   = strtolower($parts[0]);
    $cmdArg = $parts[1] ?? '';
} else {
    $cmdArg = '';
}

// /start
if ($cmd === '/start' || $text === '🏠 Главная') {
    clearState($pdo, $userId);
    sendMsg($chatId, startText($firstName), $isAdmin ? adminMenuKb() : mainMenuKb());
    http_response_code(200); echo 'OK'; exit;
}

// /help
if ($cmd === '/help') {
    $helpText = "📖 <b>Справка BLACKWATCH</b>\n\n"
        . "/start — главное меню\n"
        . "/catalog — каталог манги\n"
        . "/search [запрос] — поиск\n"
        . "/random — случайная манга\n"
        . "/library — моя библиотека\n";
    if ($isAdmin) {
        $helpText .= "\n<b>Команды админа:</b>\n"
            . "/add — добавить мангу\n"
            . "/addseries — создать серию\n"
            . "/addchapter — добавить главу\n"
            . "/stats — статистика\n"
            . "/archive — архив действий\n"
            . "/addadmin [user_id] — добавить админа\n"
            . "/delete [manga_id] — удалить мангу\n";
    }
    sendMsg($chatId, $helpText, $isAdmin ? adminMenuKb() : mainMenuKb());
    http_response_code(200); echo 'OK'; exit;
}

// Каталог
if ($cmd === '/catalog' || $text === '📚 Каталог') {
    clearState($pdo, $userId);
    showCatalog($chatId, $pdo, $SITE_URL);
    http_response_code(200); echo 'OK'; exit;
}

// Поиск
if ($cmd === '/search' || $text === '🔍 Поиск') {
    clearState($pdo, $userId);
    if ($cmd === '/search' && !empty($cmdArg)) {
        showCatalog($chatId, $pdo, $SITE_URL, 0, $cmdArg);
    } else {
        setState($pdo, $userId, 'search', []);
        sendMsg($chatId, "🔍 Введи название манги для поиска:");
    }
    http_response_code(200); echo 'OK'; exit;
}

// Случайная
if ($cmd === '/random' || $text === '🎲 Случайная манга') {
    clearState($pdo, $userId);
    $rStmt = $pdo->prepare("SELECT id FROM manga WHERE id NOT IN (SELECT manga_id FROM user_manga_status WHERE user_id = ? AND status = 'read') ORDER BY RANDOM() LIMIT 1");
    $rStmt->execute([$userId]);
    $row = $rStmt->fetch();
    if (!$row) $row = $pdo->query("SELECT id FROM manga ORDER BY RANDOM() LIMIT 1")->fetch();
    if ($row) {
        showManga($chatId, (int)$row['id'], $pdo, $SITE_URL);
    } else {
        sendMsg($chatId, '😔 Каталог пока пуст.');
    }
    http_response_code(200); echo 'OK'; exit;
}

// Библиотека
if ($cmd === '/library' || $text === '📖 Моя библиотека') {
    clearState($pdo, $userId);
    showLibrary($chatId, $userId, $pdo, $SITE_URL);
    http_response_code(200); echo 'OK'; exit;
}

// Статистика (только админ)
if (($cmd === '/stats' || $text === '📊 Статистика') && $isAdmin) {
    clearState($pdo, $userId);
    showStats($chatId, $pdo);
    http_response_code(200); echo 'OK'; exit;
}

// Архив (только админ)
if (($cmd === '/archive' || $text === '📋 Архив действий') && $isAdmin) {
    clearState($pdo, $userId);
    showArchive($chatId, $pdo, 0);
    http_response_code(200); echo 'OK'; exit;
}

// Добавить мангу (только админ)
if (($cmd === '/add' || $text === '➕ Добавить мангу') && $isAdmin) {
    clearState($pdo, $userId);
    setState($pdo, $userId, 'add_manga', ['step' => 'title', 'pages' => []]);
    sendMsg($chatId,
        "➕ <b>Добавление новой манги</b>\n\n"
        . "Введи <b>название</b> манги:\n\n"
        . "Для отмены — /cancel",
        inlineKb([[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]])
    );
    http_response_code(200); echo 'OK'; exit;
}

// Добавить серию (только админ)
if (($cmd === '/addseries' || $text === '📚 Добавить серию') && $isAdmin) {
    clearState($pdo, $userId);
    setState($pdo, $userId, 'add_series', ['step' => 'title']);
    sendMsg($chatId,
        "📚 <b>Создание новой серии (тайтла)</b>\n\n"
        . "Серия — это манга с несколькими главами.\n"
        . "После создания серии ты сможешь добавлять главы.\n\n"
        . "Введи <b>название</b> серии:",
        inlineKb([[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]])
    );
    http_response_code(200); echo 'OK'; exit;
}

// Добавить главу (только админ)
if (($cmd === '/addchapter' || $text === '📑 Добавить главу') && $isAdmin) {
    clearState($pdo, $userId);
    // Проверяем есть ли вообще серии
    $seriesCount = (int)$pdo->query("SELECT COUNT(*) FROM manga WHERE is_series = TRUE")->fetchColumn();
    if ($seriesCount === 0) {
        sendMsg($chatId, "📭 У тебя ещё нет серий.\n\nСначала создай серию через «📚 Добавить серию».");
        http_response_code(200); echo 'OK'; exit;
    }

    if ($cmdArg) {
        // Поиск по аргументу команды
        showSeriesList($chatId, $pdo, 0, $cmdArg);
    } else {
        showSeriesList($chatId, $pdo, 0, '');
    }
    http_response_code(200); echo 'OK'; exit;
}

// /addadmin (только хардкод-админ)
if ($cmd === '/addadmin' && in_array($userId, [1710365896, 1181510470])) {
    $newAdminId = (int)trim($cmdArg);
    if ($newAdminId > 0) {
        try {
            $pdo->prepare("INSERT INTO bot_admins (user_id) VALUES (?) ON CONFLICT DO NOTHING")->execute([$newAdminId]);
            logArchive($pdo, 'add_admin', "Добавлен новый админ: {$newAdminId}", $userId);
            sendMsg($chatId, "✅ Пользователь <b>{$newAdminId}</b> добавлен в администраторы.");
        } catch (Exception $e) {
            sendMsg($chatId, '❌ Ошибка: ' . $e->getMessage());
        }
    } else {
        sendMsg($chatId, '❌ Используй: /addadmin [user_id]');
    }
    http_response_code(200); echo 'OK'; exit;
}

// /delete (только админ)
if ($cmd === '/delete' && $isAdmin) {
    $mangaId = (int)trim($cmdArg);
    if ($mangaId > 0) {
        $tStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $tStmt->execute([$mangaId]);
        $row = $tStmt->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM manga WHERE id = ?")->execute([$mangaId]);
            $pdo->prepare("DELETE FROM manga_pages WHERE manga_id = ?")->execute([$mangaId]);
            $pdo->prepare("DELETE FROM manga_chapters WHERE manga_id = ?")->execute([$mangaId]);
            logArchive($pdo, 'delete_manga', "Удалена манга: {$row['title']}", $userId);
            sendMsg($chatId, "🗑 Манга <b>" . htmlspecialchars($row['title']) . "</b> удалена.");
        } else {
            sendMsg($chatId, "❌ Манга с ID {$mangaId} не найдена.");
        }
    } else {
        sendMsg($chatId, '❌ Используй: /delete [manga_id]');
    }
    http_response_code(200); echo 'OK'; exit;
}

// /cancel — общая отмена
if ($cmd === '/cancel' || $text === '❌ Отмена') {
    clearState($pdo, $userId);
    sendMsg($chatId, '❌ Действие отменено.', $isAdmin ? adminMenuKb() : mainMenuKb());
    http_response_code(200); echo 'OK'; exit;
}

// /done — если нет активного состояния
if ($cmd === '/done') {
    sendMsg($chatId, '⚠️ Нет активного действия. Используй /add, /addseries или /addchapter.');
    http_response_code(200); echo 'OK'; exit;
}

// Быстрый поиск по тексту (если не команда и нет состояния)
if ($text && $text[0] !== '/' && strlen($text) >= 2 && strlen($text) <= 100) {
    // Проверим — может это поиск?
    // Не показываем каталог автоматически, чтобы не было спама
    // Просто показываем подсказку
}

// Дефолтный ответ
sendMsg($chatId,
    "👋 Привет! Используй меню ниже или напиши /help\n\n🌐 Сайт: {$SITE_URL}",
    $isAdmin ? adminMenuKb() : mainMenuKb()
);

http_response_code(200);
echo 'OK';