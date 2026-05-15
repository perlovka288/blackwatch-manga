<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
register_shutdown_function(function(){
    $e=error_get_last();
    if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_COMPILE_ERROR])){
        error_log('FATAL: '.$e['message'].' in '.$e['file'].':'.  $e['line']);
    }
});
set_time_limit(0);
ini_set('memory_limit', '512M');

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
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS suggestions (
        id SERIAL PRIMARY KEY,
        user_id BIGINT NOT NULL,
        text TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tags (
        user_id BIGINT PRIMARY KEY,
        tag_name VARCHAR(100) NOT NULL
    )");
    // ALTER — добавляем колонки если нет
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS is_series BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS file_id TEXT");
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
$apiUrl          = "https://api.telegram.org/bot{$BOT_TOKEN}";

$IMGBB_KEYS = [
    '58ff4596fd55028a81cbf8c4e38388e1',
    '6981ba08e7b2a8743aab2c8ea008f675',
    'f9b8d27fa4029816d643c7814fd60c60',
    '24dbed2ae9fea9369de6a7b68d0c3ee6',
    'c3e6a55335c71a052c1a59b6a2d6d150',
];

$HARDCODED_ADMINS = [1710365896, 1181510470];

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
    $debugInfo = 'input_len=' . strlen($input) . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ct=' . ($_SERVER['CONTENT_TYPE'] ?? '?');
    http_response_code(200); echo 'DEBUG:' . $debugInfo . '|raw:' . substr($input,0,100); exit;
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

function tgPost(string $url, array $data): string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result ?: '';
}

function sendMsg(int $chatId, string $text, $keyboard = [], string $parseMode = 'HTML'): ?array {
    $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parseMode];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return tgApi('sendMessage', $params);
}

function editMsg(int $chatId, int $msgId, string $text, $keyboard = [], string $parseMode = 'HTML'): ?array {
    $params = ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => $parseMode];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return tgApi('editMessageText', $params);
}

function answerCallback(string $callbackId, string $text = '', bool $alert = false): void {
    tgApi('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert]);
}

function sendPhoto(int $chatId, string $photoUrl, string $caption = '', $keyboard = []): ?array {
    $params = ['chat_id' => $chatId, 'photo' => $photoUrl, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return tgApi('sendPhoto', $params);
}

function editCaption(int $chatId, int $msgId, string $caption, $keyboard = []): ?array {
    $params = ['chat_id' => $chatId, 'message_id' => $msgId, 'caption' => $caption, 'parse_mode' => 'HTML'];
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
    return ['keyboard' => $rows, 'resize_keyboard' => $resize, 'one_time_keyboard' => false];
}

function removeKb(): array {
    return ['remove_keyboard' => true];
}

# =========================
# FSM СОСТОЯНИЯ
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
# TELEGRAM FILE DOWNLOAD
# =========================
function downloadTgFile(string $fileId): ?string {
    global $BOT_TOKEN;
    $res = tgApi('getFile', ['file_id' => $fileId]);
    if (empty($res['ok']) || empty($res['result']['file_path'])) return null;
    $filePath = $res['result']['file_path'];
    $url = "https://api.telegram.org/file/bot{$BOT_TOKEN}/{$filePath}";
    $ch = curl_init($url);
    $tmpFile = tempnam(sys_get_temp_dir(), 'tg_');
    $fp = fopen($tmpFile, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($httpCode === 200 && filesize($tmpFile) > 0) return $tmpFile;
    @unlink($tmpFile);
    return null;
}

function getTelegramFileUrl(string $fileId): ?string {
    global $BOT_TOKEN;
    $res = tgApi('getFile', ['file_id' => $fileId]);
    if (empty($res['ok']) || empty($res['result']['file_path'])) return null;
    return "https://api.telegram.org/file/bot{$BOT_TOKEN}/" . $res['result']['file_path'];
}

# =========================
# IMGBB UPLOAD
# =========================
function uploadToImgbb(string $filePath, array $keys, int $retries = 2): ?string {
    if (!file_exists($filePath) || filesize($filePath) === 0) return null;
    $imageData = base64_encode(file_get_contents($filePath));
    foreach ($keys as $key) {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
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
            $errMsg = json_decode($response, true)['error']['message'] ?? '';
            if ($httpCode === 400 || $httpCode === 429 || stripos($errMsg, 'limit') !== false) break;
            if ($attempt < $retries) sleep(1);
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
# ZIP — сортировка по дате модификации
# =========================
function extractAndSortZip(string $zipPath): array {
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $raw = file_get_contents($zipPath);
    if ($raw === false) return [];
    $len = strlen($raw);
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
        $entries[] = ['base' => $base, 'ext' => $ext, 'mtime' => $mtime, 'compress' => $compress, 'compSize' => $compSize, 'origSize' => $origSize, 'dataOffset' => $dataOffset];
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
        if (file_put_contents($outPath, $fileData) !== false) $paths[] = $outPath;
    }
    return $paths;
}

# =========================
# TELEGRAPH
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
# СОХРАНИТЬ СТРАНИЦЫ
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
# UPLOAD ZIP + PHOTOS (с прогрессом)
# =========================
function processAndUploadZip(string $zipPath, array $imgbbKeys, int $chatId): array {
    $files = extractAndSortZip($zipPath);
    if (empty($files)) return [];
    $total = count($files);
    $urls = [];
    $notifyAt = max(1, (int)floor($total / 4));
    sendMsg($chatId, "📦 Извлечено страниц: <b>{$total}</b>\n⏳ Загружаю на ImgBB...");
    foreach ($files as $i => $filePath) {
        $url = uploadToImgbb($filePath, $imgbbKeys);
        @unlink($filePath);
        if ($url) $urls[] = $url;
        if ($total >= 5 && (($i + 1) % $notifyAt === 0 || ($i + 1) === $total)) {
            $pct = round(($i + 1) / $total * 100);
            sendMsg($chatId, "⬆️ Загружено: <b>" . ($i + 1) . "/{$total}</b> ({$pct}%)");
        }
    }
    if (!empty($files)) @rmdir(dirname($files[0]));
    return $urls;
}

function processAndUploadPhotos(array $fileIds, array $imgbbKeys, int $chatId): array {
    $total = count($fileIds);
    $urls = [];
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
# МЕНЮ
# =========================
function mainMenuKb(): array {
    return replyKb([
        [['text' => '🔍 Найти мангу'],    ['text' => '📚 Моя библиотека']],
        [['text' => '🔥 Топ по лайкам'], ['text' => '🎲 Случайная манга']],
        [['text' => '🌐 Сайт каталога'], ['text' => '💡 Поддержка']],
    ]);
}

function adminMenuKb(): array {
    return replyKb([
        [['text' => '🔍 Найти мангу'],    ['text' => '📚 Моя библиотека']],
        [['text' => '🔥 Топ по лайкам'], ['text' => '🎲 Случайная манга']],
        [['text' => '⚙️ Админ-панель'],   ['text' => '🌐 Сайт каталога']],
        [['text' => '💡 Поддержка']],
    ]);
}

function adminPanelKb(): array {
    return replyKb([
        [['text' => '➕ Добавить через альбом'], ['text' => '📦 Добавить через ZIP']],
        [['text' => '📚 Добавить серию'],         ['text' => '📑 Добавить главу']],
        [['text' => '✏️ Редактирование'],          ['text' => '📊 Статистика']],
        [['text' => '📥 Предложки'],               ['text' => '🗂 Архив бота']],
        [['text' => '❓ FAQ и Команды'],            ['text' => '🔙 Режим читателя']],
    ]);
}

function startText(string $firstName): string {
    return "👋 Привет, <b>{$firstName}</b>!\n\nДобро пожаловать в <b>BLACKWATCH</b> — manga reader bot.\n\nВыбери действие в меню ниже 👇";
}

# =========================
# КАРТОЧКА МАНГИ
# =========================
function mangaCaption(array $manga): string {
    $title = htmlspecialchars($manga['title'], ENT_QUOTES);
    $desc  = !empty($manga['description']) ? "\n\n" . htmlspecialchars(mb_substr($manga['description'], 0, 300), ENT_QUOTES) : '';
    $isSeries = !empty($manga['is_series']);
    $type = $isSeries ? '📚 Серия глав' : '📄 Манга';
    $likes    = (int)($manga['likes'] ?? 0);
    $dislikes = (int)($manga['dislikes'] ?? 0);
    return "<b>{$title}</b>\n{$type}{$desc}\n\n👍 {$likes}  👎 {$dislikes}";
}

function sendMangaCard(int $chatId, array $manga, string $siteUrl, int $userId = 0): void {
    $mangaId = (int)$manga['id'];
    $isSeries = !empty($manga['is_series']);
    $caption = mangaCaption($manga);

    $rows = [];
    if ($isSeries) {
        $rows[] = [['text' => '📚 Главы', 'callback_data' => "chapters:{$mangaId}:0"]];
    } else {
        $btnRead = ['text' => '📖 Читать', 'url' => "{$siteUrl}/read/{$mangaId}"];
        if (!empty($manga['telegraph_url'])) {
            $rows[] = [$btnRead, ['text' => '📄 Telegraph', 'url' => $manga['telegraph_url']]];
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
    $kb = ['inline_keyboard' => $rows];

    $cover = $manga['cover_imgbb_url'] ?? ($manga['file_id'] ?? null);
    if ($cover) {
        $res = sendPhoto($chatId, $cover, $caption, $kb);
        if (empty($res['ok'])) sendMsg($chatId, $caption, $kb);
    } else {
        sendMsg($chatId, $caption, $kb);
    }
}

function updateMangaMessage(int $chatId, int $msgId, array $manga, string $siteUrl): void {
    $mangaId = (int)$manga['id'];
    $isSeries = !empty($manga['is_series']);
    $caption = mangaCaption($manga);
    $rows = [];
    if ($isSeries) {
        $rows[] = [['text' => '📚 Главы', 'callback_data' => "chapters:{$mangaId}:0"]];
    } else {
        $btnRead = ['text' => '📖 Читать', 'url' => "{$siteUrl}/read/{$mangaId}"];
        if (!empty($manga['telegraph_url'])) {
            $rows[] = [$btnRead, ['text' => '📄 Telegraph', 'url' => $manga['telegraph_url']]];
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
    $kb = ['inline_keyboard' => $rows];
    if (!empty($manga['cover_imgbb_url'])) {
        editCaption($chatId, $msgId, $caption, $kb);
    } else {
        editMsg($chatId, $msgId, $caption, $kb);
    }
}

# =========================
# КАТАЛОГ / ПОИСК
# =========================
function showCatalog(int $chatId, PDO $pdo, string $siteUrl, int $page = 0, string $q = '', string $sort = 'new'): void {
    $limit  = 8;
    $offset = $page * $limit;
    $orderBy = match($sort) { 'popular' => 'likes DESC, id DESC', default => 'id DESC' };
    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, is_series, telegraph_url FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");
        $cStmt->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, is_series, telegraph_url FROM manga ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $cStmt = $pdo->query("SELECT COUNT(*) FROM manga");
    }
    $items = $stmt->fetchAll();
    $total = (int)$cStmt->fetchColumn();

    if (empty($items)) {
        sendMsg($chatId, $q ? "😔 По запросу <b>" . htmlspecialchars($q) . "</b> ничего не найдено." : "😔 Каталог пуст.");
        return;
    }

    $text = $q ? "🔍 <b>" . htmlspecialchars($q) . "</b>:\n\n" : "📚 <b>Каталог манги</b>\n\n";
    foreach ($items as $i => $m) {
        $serLabel = $m['is_series'] ? ' 📚' : '';
        $text .= ($offset + $i + 1) . ". <b>" . htmlspecialchars($m['title']) . "</b>{$serLabel} — 👍 {$m['likes']}\n";
    }
    $text .= "\nВсего: {$total}";

    $rows = [];
    foreach ($items as $m) {
        $rows[] = [['text' => ($m['is_series'] ? '📚 ' : '📖 ') . mb_substr($m['title'], 0, 40), 'callback_data' => "manga:{$m['id']}"]];
    }

    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '← Назад', 'callback_data' => "catalog:" . ($page - 1) . ":{$sort}:" . urlencode($q)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => 'Вперёд →', 'callback_data' => "catalog:" . ($page + 1) . ":{$sort}:" . urlencode($q)];
    if (!empty($navRow)) $rows[] = $navRow;

    $sortRow = [
        ['text' => ($sort === 'new' ? '✅ ' : '') . '🕒 Новые',   'callback_data' => "catalog:0:new:" . urlencode($q)],
        ['text' => ($sort === 'popular' ? '✅ ' : '') . '🔥 Топ', 'callback_data' => "catalog:0:popular:" . urlencode($q)],
    ];
    $rows[] = $sortRow;

    sendMsg($chatId, $text, ['inline_keyboard' => $rows]);
}

# =========================
# ГЛАВЫ
# =========================
function showChapters(int $chatId, int $msgId, int $mangaId, PDO $pdo, string $siteUrl, int $page = 0): void {
    $limit  = 10;
    $offset = $page * $limit;
    $stmt   = $pdo->prepare("SELECT id, chapter_num, title, telegraph_url FROM manga_chapters WHERE manga_id = ? ORDER BY chapter_num ASC LIMIT ? OFFSET ?");
    $stmt->execute([$mangaId, $limit, $offset]);
    $chapters = $stmt->fetchAll();
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE manga_id = ?");
    $cStmt->execute([$mangaId]);
    $total = (int)$cStmt->fetchColumn();

    $mangaStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
    $mangaStmt->execute([$mangaId]);
    $mangaTitle = $mangaStmt->fetchColumn() ?: 'Серия';

    if (empty($chapters)) {
        $kb = ['inline_keyboard' => [[['text' => '← К манге', 'callback_data' => "back_to_manga:{$mangaId}"]]]]; 
        $er = tgApi('editMessageCaption', ['chat_id' => $chatId, 'message_id' => $msgId, 'caption' => '📭 Глав пока нет.', 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
        if (empty($er['ok'])) { $er2 = editMsg($chatId, $msgId, '📭 Глав пока нет.', $kb); if (empty($er2['ok'])) { deleteMsg($chatId, $msgId); sendMsg($chatId, '📭 Глав пока нет.', $kb); } }
        return;
    }

    $rows = [];
    foreach ($chapters as $ch) {
        $chLabel = "Глава {$ch['chapter_num']}" . ($ch['title'] ? ": {$ch['title']}" : '');
        $url = !empty($ch['telegraph_url']) ? $ch['telegraph_url'] : "{$siteUrl}/view-chapter/{$ch['id']}";
        $rows[] = [['text' => $chLabel, 'url' => $url]];
    }

    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '←', 'callback_data' => "chapters:{$mangaId}:" . ($page - 1)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => '→', 'callback_data' => "chapters:{$mangaId}:" . ($page + 1)];
    if (!empty($navRow)) $rows[] = $navRow;
    $rows[] = [['text' => '← К манге', 'callback_data' => "back_to_manga:{$mangaId}"]];

    $text = "📚 <b>" . htmlspecialchars($mangaTitle) . "</b>\nГлав: {$total}";
    $kb = ['inline_keyboard' => $rows];

    // Редактируем текущее сообщение (с фото — editCaption, текст — editMsg)
    $er = tgApi('editMessageCaption', ['chat_id' => $chatId, 'message_id' => $msgId, 'caption' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
    if (empty($er['ok'])) {
        $er2 = editMsg($chatId, $msgId, $text, $kb);
        if (empty($er2['ok'])) {
            deleteMsg($chatId, $msgId);
            sendMsg($chatId, $text, $kb);
        }
    }
}

function showLibrary(int $chatId, int $userId, PDO $pdo, string $siteUrl): void {
    $stmt = $pdo->prepare("SELECT m.id, m.title, m.is_series, s.status FROM user_manga_status s JOIN manga m ON s.manga_id = m.id WHERE s.user_id = ? ORDER BY s.status ASC");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        sendMsg($chatId, "📭 Твоя библиотека пуста.\n\nНайди мангу в каталоге и добавь её!", ['inline_keyboard' => [[['text' => '📚 Открыть каталог', 'callback_data' => 'catalog:0:new:']]]]);
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
    sendMsg($chatId, $text, ['inline_keyboard' => $rows]);
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

    $stmt   = $pdo->query("SELECT added_by, COUNT(*) as cnt FROM manga GROUP BY added_by ORDER BY cnt DESC LIMIT 5");
    $admRep = '';
    $place  = 1;
    while ($row = $stmt->fetch()) {
        try {
            $tagStmt = $pdo->prepare("SELECT tag_name FROM admin_tags WHERE user_id = ?");
            $tagStmt->execute([$row['added_by']]);
            $tagRow = $tagStmt->fetch();
            $tag = $tagRow ? $tagRow['tag_name'] : "ID:{$row['added_by']}";
        } catch (Exception $e) { $tag = "ID:{$row['added_by']}"; }
        $admRep .= "\n{$place}. <b>{$tag}</b> — {$row['cnt']} шт.";
        $place++;
    }

    $topManga = $pdo->query("SELECT title, likes FROM manga ORDER BY likes DESC LIMIT 5")->fetchAll();
    $topText  = '';
    foreach ($topManga as $i => $m) {
        $topText .= "\n" . ($i + 1) . ". " . htmlspecialchars($m['title']) . " — 👍 {$m['likes']}";
    }

    $text = "📊 <b>Статистика BLACKWATCH</b>\n\n"
        . "📚 Манг: <b>{$mangaCount}</b>\n"
        . "📖 Серий: <b>{$seriesCount}</b>\n"
        . "📑 Глав: <b>{$chaptersCount}</b>\n"
        . "📄 Страниц: <b>{$pagesCount}</b>\n"
        . "👥 Пользователей: <b>{$usersCount}</b>\n"
        . "🗳 Голосов: <b>{$votesCount}</b>\n"
        . "🆕 За 24ч: <b>{$newToday}</b>\n"
        . "\n🏆 <b>Топ по лайкам:</b>{$topText}"
        . "\n\n📋 <b>Рейтинг добавлений:</b>{$admRep}";
    sendMsg($chatId, $text);
}

# =========================
# АРХИВ
# =========================
function showArchive(int $chatId, PDO $pdo, int $page = 0): void {
    $limit  = 10;
    $offset = $page * $limit;
    $stmt   = $pdo->prepare("SELECT *, to_char(created_at, 'DD.MM.YYYY HH24:MI') as fmt_time FROM bot_archive ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    $items = $stmt->fetchAll();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();

    if (empty($items)) { sendMsg($chatId, '📋 Архив пуст.'); return; }

    $text = "🗂 <b>Архив действий</b> (стр. " . ($page + 1) . "):\n\n";
    foreach ($items as $a) {
        $time = $a['fmt_time'] ?? $a['created_at'];
        try {
            $tagStmt = $pdo->prepare("SELECT tag_name FROM admin_tags WHERE user_id = ?");
            $tagStmt->execute([$a['action_by']]);
            $tagRow = $tagStmt->fetch();
            $tag = $tagRow ? "@{$tagRow['tag_name']}" : "ID:{$a['action_by']}";
        } catch (Exception $e) { $tag = "ID:{$a['action_by']}"; }
        $text .= "🕐 <i>{$time}</i> [{$tag}]\n";
        $text .= htmlspecialchars(mb_substr($a['action_text'], 0, 120)) . "\n─────────\n";
    }

    $rows = [];
    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '← Назад', 'callback_data' => "archive:" . ($page - 1)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => 'Вперёд →', 'callback_data' => "archive:" . ($page + 1)];
    if (!empty($navRow)) $rows[] = $navRow;

    sendMsg($chatId, $text, $rows ? ['inline_keyboard' => $rows] : []);
}

# =========================
# СПИСОК СЕРИЙ (для добавления главы)
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
        sendMsg($chatId, "📭 Серий не найдено.\n\nСначала создай серию через «📚 Добавить серию».");
        return;
    }

    $rows = [];
    foreach ($items as $m) {
        $rows[] = [['text' => mb_substr($m['title'], 0, 50), 'callback_data' => "selch:{$m['id']}"]];
    }

    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '←', 'callback_data' => "seriespick:" . ($page - 1) . ":" . urlencode($q)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => '→', 'callback_data' => "seriespick:" . ($page + 1) . ":" . urlencode($q)];
    if (!empty($navRow)) $rows[] = $navRow;

    sendMsg($chatId, "📚 Выбери серию для добавления главы:", ['inline_keyboard' => $rows]);
}

# =========================
# РЕДАКТИРОВАНИЕ — меню выбора манги
# =========================
function showEditSearch(int $chatId, PDO $pdo, string $q = '', int $page = 0): void {
    $limit  = 6;
    $offset = $page * $limit;
    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");
        $cStmt->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title FROM manga ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $cStmt = $pdo->query("SELECT COUNT(*) FROM manga");
    }
    $items = $stmt->fetchAll();
    $total = (int)$cStmt->fetchColumn();

    if (empty($items)) { sendMsg($chatId, "😔 Ничего не найдено."); return; }

    $rows = [];
    foreach ($items as $m) {
        $rows[] = [['text' => '✏️ ' . mb_substr($m['title'], 0, 45), 'callback_data' => "edit_manga:{$m['id']}"]];
    }
    $navRow = [];
    if ($page > 0) $navRow[] = ['text' => '←', 'callback_data' => "edit_page:{$page}:" . urlencode($q)];
    $totalPages = max(1, (int)ceil($total / $limit));
    $navRow[] = ['text' => ($page + 1) . '/' . $totalPages, 'callback_data' => 'noop'];
    if (($page + 1) < $totalPages) $navRow[] = ['text' => '→', 'callback_data' => "edit_page:" . ($page + 1) . ":" . urlencode($q)];
    if (!empty($navRow)) $rows[] = $navRow;

    sendMsg($chatId, "✏️ <b>Выбери мангу для редактирования:</b>", ['inline_keyboard' => $rows]);
}

function sendEditMangaMenu(int $chatId, array $m): void {
    $mId  = $m['id'];
    $desc = mb_substr(strip_tags($m['description'] ?? ''), 0, 80);
    $text = "<b>✏️ Редактирование: " . htmlspecialchars($m['title']) . "</b>\n\n📝 " . htmlspecialchars($desc);
    $kb   = ['inline_keyboard' => [
        [['text' => '🖼 Обложку', 'callback_data' => "editfield:cover:{$mId}"], ['text' => '📖 Название', 'callback_data' => "editfield:title:{$mId}"]],
        [['text' => '📝 Описание', 'callback_data' => "editfield:desc:{$mId}"], ['text' => '🔗 Ссылку', 'callback_data' => "editfield:link:{$mId}"]],
        [['text' => '📑 Добавить главу', 'callback_data' => "addchap:{$mId}"]],
        [['text' => '🗑 Удалить мангу', 'callback_data' => "delete_confirm:{$mId}"]],
    ]];
    $cover = $m['cover_imgbb_url'] ?? ($m['file_id'] ?? null);
    if ($cover) {
        $res = tgApi('sendPhoto', ['chat_id' => $chatId, 'photo' => $cover, 'caption' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
        if (empty($res['ok'])) sendMsg($chatId, $text, $kb);
    } else {
        sendMsg($chatId, $text, $kb);
    }
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

$userId    = 0;
$chatId    = 0;
$msgId     = 0;
$text      = '';
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

if (!$userId || !$chatId) { http_response_code(200); echo 'OK'; exit; }

registerUser($pdo, $userId);

$isAdmin = isAdmin($userId, $HARDCODED_ADMINS);
$state   = getState($pdo, $userId);

# =========================
# CALLBACK QUERY
# =========================
if ($callbackQuery) {
    $cbId   = $callbackQuery['id'];
    $cbData = $callbackQuery['data'] ?? '';

    if ($cbData === 'noop') { answerCallback($cbId); http_response_code(200); echo 'OK'; exit; }

    // catalog:page:sort:q
    if (preg_match('/^catalog:(\d+):(\w+):(.*)$/', $cbData, $m)) {
        answerCallback($cbId);
        deleteMsg($chatId, $msgId);
        showCatalog($chatId, $pdo, $SITE_URL, (int)$m[1], urldecode($m[3]), $m[2]);
        http_response_code(200); echo 'OK'; exit;
    }

    // manga:id
    if (preg_match('/^manga:(\d+)$/', $cbData, $m)) {
        answerCallback($cbId);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([(int)$m[1]]);
        $manga = $stmt->fetch();
        if ($manga) {
            deleteMsg($chatId, $msgId);
            sendMangaCard($chatId, $manga, $SITE_URL, $userId);
        } else sendMsg($chatId, '❌ Манга не найдена.');
        http_response_code(200); echo 'OK'; exit;
    }

    // chapters:manga_id:page
    if (preg_match('/^chapters:(\d+):(\d+)$/', $cbData, $m)) {
        answerCallback($cbId);
        showChapters($chatId, $msgId, (int)$m[1], $pdo, $SITE_URL, (int)$m[2]);
        http_response_code(200); echo 'OK'; exit;
    }

    // back_to_manga:manga_id — вернуться к карточке манги из списка глав
    if (preg_match('/^back_to_manga:(\d+)$/', $cbData, $m)) {
        answerCallback($cbId);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([(int)$m[1]]);
        $manga = $stmt->fetch();
        if ($manga) {
            $caption = mangaCaption($manga);
            $mangaId = (int)$manga['id'];
            $rows = [];
            $rows[] = [['text' => '📚 Главы', 'callback_data' => "chapters:{$mangaId}:0"]];
            $rows[] = [
                ['text' => '👍 Лайк',    'callback_data' => "vote:like:{$mangaId}"],
                ['text' => '👎 Дизлайк', 'callback_data' => "vote:dislike:{$mangaId}"],
            ];
            $rows[] = [
                ['text' => '📖 Читаю',       'callback_data' => "status:now:{$mangaId}"],
                ['text' => '🔖 Буду читать', 'callback_data' => "status:will:{$mangaId}"],
                ['text' => '✅ Прочитано',   'callback_data' => "status:read:{$mangaId}"],
            ];
            $kb = ['inline_keyboard' => $rows];
            $cover = $manga['cover_imgbb_url'] ?? ($manga['file_id'] ?? null);
            if ($cover) {
                // Пробуем сначала editCaption (если текущее — фото), потом удаляем и шлём фото
                $er = editCaption($chatId, $msgId, $caption, $kb);
                if (empty($er['ok'])) {
                    deleteMsg($chatId, $msgId);
                    $res = sendPhoto($chatId, $cover, $caption, $kb);
                    if (empty($res['ok'])) sendMsg($chatId, $caption, $kb);
                }
            } else {
                $er = editMsg($chatId, $msgId, $caption, $kb);
                if (empty($er['ok'])) {
                    deleteMsg($chatId, $msgId);
                    sendMsg($chatId, $caption, $kb);
                }
            }
        } else {
            sendMsg($chatId, '❌ Манга не найдена.');
        }
        http_response_code(200); echo 'OK'; exit;
    }

    // vote:type:manga_id
    if (preg_match('/^vote:(like|dislike):(\d+)$/', $cbData, $m)) {
        $stats = handleVote($userId, (int)$m[2], $m[1], $pdo);
        answerCallback($cbId, $m[1] === 'like' ? '👍 Лайк учтён!' : '👎 Дизлайк учтён!');
        // Обновляем сообщение
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([(int)$m[2]]);
        $manga = $stmt->fetch();
        if ($manga) updateMangaMessage($chatId, $msgId, $manga, $SITE_URL);
        http_response_code(200); echo 'OK'; exit;
    }

    // status:type:manga_id
    if (preg_match('/^status:(now|will|read):(\d+)$/', $cbData, $m)) {
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON CONFLICT (user_id, manga_id) DO UPDATE SET status = EXCLUDED.status")
            ->execute([$userId, (int)$m[2], $m[1]]);
        $labels = ['now' => '📖 Отмечено: Читаю!', 'will' => '🔖 Добавлено в список!', 'read' => '✅ Отмечено как прочитанное!'];
        answerCallback($cbId, $labels[$m[1]], true);
        http_response_code(200); echo 'OK'; exit;
    }

    // archive:page
    if (preg_match('/^archive:(\d+)$/', $cbData, $m)) {
        answerCallback($cbId);
        deleteMsg($chatId, $msgId);
        showArchive($chatId, $pdo, (int)$m[1]);
        http_response_code(200); echo 'OK'; exit;
    }

    // seriespick:page:q
    if (preg_match('/^seriespick:(\d+):(.*)$/', $cbData, $m)) {
        answerCallback($cbId);
        deleteMsg($chatId, $msgId);
        showSeriesList($chatId, $pdo, (int)$m[1], urldecode($m[2]));
        http_response_code(200); echo 'OK'; exit;
    }

    // selch:manga_id — выбор серии для главы
    if (preg_match('/^selch:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        $mangaId = (int)$m[1];
        $mStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $mStmt->execute([$mangaId]);
        $mangaTitle = $mStmt->fetchColumn();
        setState($pdo, $userId, 'add_chapter', ['step' => 'num', 'manga_id' => $mangaId, 'manga_title' => $mangaTitle]);
        deleteMsg($chatId, $msgId);
        sendMsg($chatId, "📚 Серия: <b>" . htmlspecialchars($mangaTitle) . "</b>\n\nВведи <b>номер главы</b> (можно дробный: 1, 2, 2.5):", ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]);
        http_response_code(200); echo 'OK'; exit;
    }

    // addchap:manga_id — добавить главу прямо из редактирования
    if (preg_match('/^addchap:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        $mangaId = (int)$m[1];
        $mStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $mStmt->execute([$mangaId]);
        $mangaTitle = $mStmt->fetchColumn();
        setState($pdo, $userId, 'add_chapter', ['step' => 'num', 'manga_id' => $mangaId, 'manga_title' => $mangaTitle]);
        sendMsg($chatId, "📚 Серия: <b>" . htmlspecialchars($mangaTitle) . "</b>\n\nВведи <b>номер главы</b>:", ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]);
        http_response_code(200); echo 'OK'; exit;
    }

    // edit_manga:id
    if (preg_match('/^edit_manga:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([(int)$m[1]]);
        $manga = $stmt->fetch();
        if ($manga) sendEditMangaMenu($chatId, $manga);
        http_response_code(200); echo 'OK'; exit;
    }

    // edit_page:page:q
    if (preg_match('/^edit_page:(\d+):(.*)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        deleteMsg($chatId, $msgId);
        showEditSearch($chatId, $pdo, urldecode($m[2]), (int)$m[1]);
        http_response_code(200); echo 'OK'; exit;
    }

    // editfield:field:manga_id
    if (preg_match('/^editfield:(cover|title|desc|link):(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        $field = $m[1];
        $mangaId = (int)$m[2];
        $hints = [
            'cover' => "🖼 Отправь новое фото-обложку:",
            'title' => "📖 Введи новое название:",
            'desc'  => "📝 Введи новое описание:",
            'link'  => "🔗 Введи новую ссылку (Telegraph или другую):",
        ];
        setState($pdo, $userId, 'edit_field', ['field' => $field, 'manga_id' => $mangaId]);
        sendMsg($chatId, $hints[$field], ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]);
        http_response_code(200); echo 'OK'; exit;
    }

    // delete_confirm:manga_id
    if (preg_match('/^delete_confirm:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        $mangaId = (int)$m[1];
        $stmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $stmt->execute([$mangaId]);
        $row = $stmt->fetch();
        if ($row) {
            $kb = ['inline_keyboard' => [[
                ['text' => '✅ Да, удалить',  'callback_data' => "delete_yes:{$mangaId}"],
                ['text' => '❌ Отмена',        'callback_data' => "delete_no:{$mangaId}"],
            ]]];
            sendMsg($chatId, "🗑 Удалить <b>" . htmlspecialchars($row['title']) . "</b>?\n\n<i>Это действие необратимо.</i>", $kb);
        }
        http_response_code(200); echo 'OK'; exit;
    }

    // delete_yes:manga_id
    if (preg_match('/^delete_yes:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId, '🗑 Удалено');
        $mangaId = (int)$m[1];
        $stmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $stmt->execute([$mangaId]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM manga WHERE id = ?")->execute([$mangaId]);
            $pdo->prepare("DELETE FROM manga_pages WHERE manga_id = ?")->execute([$mangaId]);
            $pdo->prepare("DELETE FROM manga_chapters WHERE manga_id = ?")->execute([$mangaId]);
            $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id IN (SELECT id FROM manga_chapters WHERE manga_id = ?)")->execute([$mangaId]);
            logArchive($pdo, 'delete_manga', "Удалена манга: {$row['title']}", $userId);
            editMsg($chatId, $msgId, "🗑 Манга <b>" . htmlspecialchars($row['title']) . "</b> удалена.");
        }
        http_response_code(200); echo 'OK'; exit;
    }

    // delete_no
    if (preg_match('/^delete_no:(\d+)$/', $cbData, $m)) {
        answerCallback($cbId, '❌ Отменено');
        editMsg($chatId, $msgId, "❌ Удаление отменено.");
        http_response_code(200); echo 'OK'; exit;
    }

    // suggest callbacks
    if (preg_match('/^view_suggest:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId);
        $sId = (int)$m[1];
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ?");
        $stmt->execute([$sId]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("UPDATE suggestions SET status = 'read' WHERE id = ?")->execute([$sId]);
            $kb = ['inline_keyboard' => [[['text' => '✅ Рассмотрено', 'callback_data' => "done_suggest:{$sId}"]]]];
            sendMsg($chatId, "📩 <b>Предложение от пользователя (ID: {$s['user_id']}):</b>\n\n" . htmlspecialchars($s['text']), $kb);
        }
        http_response_code(200); echo 'OK'; exit;
    }

    if (preg_match('/^done_suggest:(\d+)$/', $cbData, $m) && $isAdmin) {
        answerCallback($cbId, '✅ Пользователь уведомлён');
        $sId = (int)$m[1];
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ?");
        $stmt->execute([$sId]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("UPDATE suggestions SET status = 'done' WHERE id = ?")->execute([$sId]);
            sendMsg((int)$s['user_id'], "📬 <b>Ваше предложение рассмотрено!</b>\n\nСпасибо за активность — администраторы ознакомились с вашим сообщением. 🙏");
            editMsg($chatId, $msgId, "✅ Предложение рассмотрено, пользователь уведомлён.");
        }
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
if (!$message) { http_response_code(200); echo 'OK'; exit; }

// Команда отмены в любой момент
if ($text === '/cancel' || $text === '❌ Отмена') {
    clearState($pdo, $userId);
    sendMsg($chatId, '❌ Действие отменено.', $isAdmin ? adminMenuKb() : mainMenuKb());
    http_response_code(200); echo 'OK'; exit;
}

# =========================
# FSM ОБРАБОТКА
# =========================

// Все тексты кнопок меню — они НЕ должны перехватываться FSM
$ALL_MENU_BUTTONS = [
    '🔍 Найти мангу', '📚 Моя библиотека', '🔥 Топ по лайкам', '🎲 Случайная манга',
    '🌐 Сайт каталога', '💡 Поддержка', '⚙️ Админ-панель', '🔙 Режим читателя',
    '➕ Добавить через альбом', '📦 Добавить через ZIP', '📚 Добавить серию',
    '📑 Добавить главу', '✏️ Редактирование', '📊 Статистика', '📥 Предложки',
    '🗂 Архив бота', '❓ FAQ и Команды', '🏠 Главная',
];

// Если текст — это кнопка меню, очищаем FSM и даём управление обработчикам кнопок
if ($state && $text && in_array($text, $ALL_MENU_BUTTONS)) {
    clearState($pdo, $userId);
    $state = null; // сбрасываем, чтобы FSM-блок не запустился
}

if ($state) {
    $currentState = $state['state'];
    $stateData    = $state['data'];

    # ---- FSM: ADD_MANGA (через альбом — фото по одному) ----
    if ($currentState === 'add_manga') {
        $step = $stateData['step'] ?? 'title';

        if ($step === 'title') {
            if (!empty($text)) {
                $stateData['title'] = $text;
                $stateData['step']  = 'desc';
                setState($pdo, $userId, 'add_manga', $stateData);
                sendMsg($chatId, "✅ Название: <b>" . htmlspecialchars($text) . "</b>\n\nВведи <b>описание</b> (или «пропустить»):");
            }
        } elseif ($step === 'desc') {
            $stateData['description'] = ($text === 'пропустить' || $text === '-') ? '' : $text;
            $stateData['step']        = 'cover';
            setState($pdo, $userId, 'add_manga', $stateData);
            sendMsg($chatId, "✅ Описание сохранено.\n\nОтправь <b>обложку</b> (фото) или «пропустить»:");
        } elseif ($step === 'cover') {
            $coverUrl = null;
            if (!empty($message['photo'])) {
                sendMsg($chatId, '⏳ Загружаю обложку...');
                $photo = end($message['photo']);
                $coverUrl = uploadTgFileToImgbb($photo['file_id'], $IMGBB_KEYS);
                if (!$coverUrl) sendMsg($chatId, '⚠️ Не удалось загрузить обложку, продолжаем без неё.');
            }
            $stateData['cover_url'] = $coverUrl;
            $stateData['step']      = 'pages';
            $stateData['pages']     = [];
            $stateData['file_ids']  = [];
            setState($pdo, $userId, 'add_manga', $stateData);
            sendMsg($chatId,
                "✅ Обложка " . ($coverUrl ? 'загружена' : 'пропущена') . ".\n\n"
                . "Отправь <b>страницы манги</b>:\n"
                . "• 📸 Фото по одному\n\n"
                . "Когда всё загрузишь — напиши <b>стоп</b> или /done",
                ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]
            );
        } elseif ($step === 'pages') {
            if ($text === '/done' || mb_strtolower($text) === 'стоп') {
                $fileIds = $stateData['file_ids'] ?? [];
                if (empty($fileIds)) {
                    sendMsg($chatId, '⚠️ Нет страниц! Отправь хотя бы одно фото или /cancel.');
                    http_response_code(200); echo 'OK'; exit;
                }
                clearState($pdo, $userId);
                $urls = processAndUploadPhotos($fileIds, $IMGBB_KEYS, $chatId);
                sendMsg($chatId, '⏳ Создаю Telegraph страницу...');
                $telegraphUrl = null;
                if (!empty($urls)) $telegraphUrl = createTelegraphPage('♥ ' . $stateData['title'], $urls);
                $insertStmt = $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, cover_imgbb_url, added_by, is_series) VALUES (?, ?, ?, ?, ?, FALSE) RETURNING id");
                $insertStmt->execute(['♥ ' . $stateData['title'], $telegraphUrl, $stateData['description'] ?? '', $stateData['cover_url'], $userId]);
                $row = $insertStmt->fetch();
                $mangaId = (int)($row['id'] ?? 0);
                if ($mangaId && !empty($urls)) saveMangaPages($pdo, $mangaId, $urls);
                logArchive($pdo, 'add_manga', "Добавлена манга: ♥ {$stateData['title']}", $userId);
                $resultText = "✅ <b>Манга добавлена!</b>\n📖 " . htmlspecialchars('♥ ' . $stateData['title']) . "\n📄 Страниц: " . count($urls);
                if ($telegraphUrl) $resultText .= "\n📰 {$telegraphUrl}";
                $resultText .= "\n🌐 {$SITE_URL}/read/{$mangaId}";
                sendMsg($chatId, $resultText, adminMenuKb());
                http_response_code(200); echo 'OK'; exit;
            }
            if (!empty($message['photo'])) {
                $photo = end($message['photo']);
                $stateData['file_ids'][] = $photo['file_id'];
                setState($pdo, $userId, 'add_manga', $stateData);
                $total = count($stateData['file_ids']);
                sendMsg($chatId, "📸 Страница {$total} принята. Отправь ещё или напиши <b>стоп</b>");
                http_response_code(200); echo 'OK'; exit;
            }
        }
        http_response_code(200); echo 'OK'; exit;
    }

    # ---- FSM: ADD_MANGA_ZIP ----
    if ($currentState === 'add_manga_zip') {
        $step = $stateData['step'] ?? 'zip';

        if ($step === 'zip') {
            if (!empty($message['document'])) {
                $doc      = $message['document'];
                $fileName = strtolower($doc['file_name'] ?? '');
                if (substr($fileName, -4) === '.zip') {
                    sendMsg($chatId, '⏳ Скачиваю ZIP-архив...');
                    $zipPath = downloadTgFile($doc['file_id']);
                    if (!$zipPath) {
                        sendMsg($chatId, '❌ Не удалось скачать архив. Telegram ограничивает файлы больше 20 МБ.');
                    } else {
                        $urls = processAndUploadZip($zipPath, $IMGBB_KEYS, $chatId);
                        @unlink($zipPath);
                        if (!empty($urls)) {
                            $stateData['pages'] = $urls;
                            $stateData['step']  = 'title';
                            setState($pdo, $userId, 'add_manga_zip', $stateData);
                            sendMsg($chatId, "✅ Загружено страниц: <b>" . count($urls) . "</b>\n\nТеперь введи <b>название</b> манги:", ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]);
                        } else {
                            sendMsg($chatId, '❌ Не удалось загрузить страницы из архива. Проверь формат файлов (JPG/PNG/WEBP).');
                        }
                    }
                } else {
                    sendMsg($chatId, '⚠️ Поддерживаются только ZIP-архивы.');
                }
            }
        } elseif ($step === 'title') {
            if (!empty($text)) {
                $stateData['title'] = $text;
                $stateData['step']  = 'desc';
                setState($pdo, $userId, 'add_manga_zip', $stateData);
                sendMsg($chatId, "✅ Название: <b>" . htmlspecialchars($text) . "</b>\n\nВведи <b>описание</b> (или «пропустить»):");
            }
        } elseif ($step === 'desc') {
            $stateData['description'] = ($text === 'пропустить' || $text === '-') ? '' : $text;
            $stateData['step']        = 'cover';
            setState($pdo, $userId, 'add_manga_zip', $stateData);
            sendMsg($chatId, "✅ Описание сохранено.\n\nОтправь <b>обложку</b> (фото) или «пропустить»:");
        } elseif ($step === 'cover') {
            $coverUrl = null;
            if (!empty($message['photo'])) {
                sendMsg($chatId, '⏳ Загружаю обложку...');
                $photo = end($message['photo']);
                $coverUrl = uploadTgFileToImgbb($photo['file_id'], $IMGBB_KEYS);
            }
            clearState($pdo, $userId);
            sendMsg($chatId, '⏳ Создаю Telegraph страницу...');
            $urls = $stateData['pages'] ?? [];
            $telegraphUrl = !empty($urls) ? createTelegraphPage('♥ ' . $stateData['title'], $urls) : null;
            $insertStmt = $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, cover_imgbb_url, added_by, is_series) VALUES (?, ?, ?, ?, ?, FALSE) RETURNING id");
            $insertStmt->execute(['♥ ' . $stateData['title'], $telegraphUrl, $stateData['description'] ?? '', $coverUrl, $userId]);
            $row = $insertStmt->fetch();
            $mangaId = (int)($row['id'] ?? 0);
            if ($mangaId && !empty($urls)) saveMangaPages($pdo, $mangaId, $urls);
            logArchive($pdo, 'add_manga', "Добавлена манга (ZIP): ♥ {$stateData['title']}", $userId);
            $resultText = "🚀 <b>Манга добавлена!</b>\n📖 " . htmlspecialchars('♥ ' . $stateData['title']) . "\n📄 Страниц: " . count($urls);
            if ($telegraphUrl) $resultText .= "\n📰 {$telegraphUrl}";
            $resultText .= "\n🌐 {$SITE_URL}/read/{$mangaId}";
            sendMsg($chatId, $resultText, adminMenuKb());
        }
        http_response_code(200); echo 'OK'; exit;
    }

    # ---- FSM: ADD_SERIES ----
    if ($currentState === 'add_series') {
        $step = $stateData['step'] ?? 'title';
        if ($step === 'title') {
            if (!empty($text)) {
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
                sendMsg($chatId, '⏳ Загружаю обложку...');
                $photo = end($message['photo']);
                $coverUrl = uploadTgFileToImgbb($photo['file_id'], $IMGBB_KEYS);
            }
            clearState($pdo, $userId);
            $insertStmt = $pdo->prepare("INSERT INTO manga (title, description, cover_imgbb_url, added_by, is_series) VALUES (?, ?, ?, ?, TRUE) RETURNING id");
            $insertStmt->execute(['♥ ' . $stateData['title'], $stateData['description'] ?? '', $coverUrl, $userId]);
            $row = $insertStmt->fetch();
            $mangaId = (int)($row['id'] ?? 0);
            logArchive($pdo, 'add_series', "Создана серия: ♥ {$stateData['title']}", $userId);
            sendMsg($chatId,
                "✅ <b>Серия создана!</b>\n📚 " . htmlspecialchars('♥ ' . $stateData['title']) . "\nID: {$mangaId}\n\n"
                . "Теперь добавляй главы через «📑 Добавить главу»\n🌐 {$SITE_URL}/read/{$mangaId}",
                adminMenuKb()
            );
        }
        http_response_code(200); echo 'OK'; exit;
    }

    # ---- FSM: ADD_CHAPTER ----
    if ($currentState === 'add_chapter') {
        $step = $stateData['step'] ?? 'num';
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
            $stateData['file_ids']      = [];
            setState($pdo, $userId, 'add_chapter', $stateData);
            $chNum   = $stateData['chapter_num'];
            $chTitle = $stateData['chapter_title'] ? ": {$stateData['chapter_title']}" : '';
            sendMsg($chatId,
                "✅ Глава {$chNum}{$chTitle}\n\nОтправь страницы главы:\n"
                . "• 📦 ZIP-архив\n• 📸 Фото по одному\n\nКогда закончишь — напиши <b>стоп</b> или /done",
                ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]
            );
        } elseif ($step === 'pages') {
            if ($text === '/done' || mb_strtolower($text) === 'стоп') {
                $fileIds = $stateData['file_ids'] ?? [];
                $pages   = $stateData['pages'] ?? [];
                // Если есть fileIds — загружаем сначала
                if (!empty($fileIds)) {
                    $uploadedUrls = processAndUploadPhotos($fileIds, $IMGBB_KEYS, $chatId);
                    $pages = array_merge($pages, $uploadedUrls);
                }
                if (empty($pages)) {
                    sendMsg($chatId, '⚠️ Нет страниц! Добавь хотя бы одну или /cancel.');
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
                $chRow = $chInsert->fetch();
                $chapterId = (int)($chRow['id'] ?? 0);
                if ($chapterId && !empty($pages)) saveChapterPages($pdo, $chapterId, $pages);
                $pdo->prepare("UPDATE manga SET is_series = TRUE WHERE id = ?")->execute([$mangaId]);
                logArchive($pdo, 'add_chapter', "Добавлена глава {$chNum} для: {$mangaTitle}", $userId);
                $resultText = "✅ <b>Глава добавлена!</b>\n📚 " . htmlspecialchars($mangaTitle) . "\n📑 {$chLabel}\n📄 Страниц: " . count($pages);
                if ($telegraphUrl) $resultText .= "\n📰 {$telegraphUrl}";
                $resultText .= "\n🌐 {$SITE_URL}/read/{$mangaId}";
                sendMsg($chatId, $resultText, adminMenuKb());
                http_response_code(200); echo 'OK'; exit;
            }

            // ZIP
            if (!empty($message['document'])) {
                $doc = $message['document'];
                if (substr(strtolower($doc['file_name'] ?? ''), -4) === '.zip') {
                    sendMsg($chatId, '⏳ Скачиваю ZIP...');
                    $zipPath = downloadTgFile($doc['file_id']);
                    if ($zipPath) {
                        $urls = processAndUploadZip($zipPath, $IMGBB_KEYS, $chatId);
                        @unlink($zipPath);
                        if (!empty($urls)) {
                            $stateData['pages'] = array_merge($stateData['pages'] ?? [], $urls);
                            setState($pdo, $userId, 'add_chapter', $stateData);
                            $total = count($stateData['pages']);
                            sendMsg($chatId, "✅ Из ZIP загружено: <b>" . count($urls) . "</b> стр.\nВсего: <b>{$total}</b>\n\nОтправь ещё или <b>стоп</b>");
                        } else {
                            sendMsg($chatId, '❌ Не удалось загрузить из ZIP.');
                        }
                    } else {
                        sendMsg($chatId, '❌ Не удалось скачать архив (лимит Telegram — 20 МБ).');
                    }
                }
                http_response_code(200); echo 'OK'; exit;
            }

            // Фото
            if (!empty($message['photo'])) {
                $photo = end($message['photo']);
                $stateData['file_ids'][] = $photo['file_id'];
                setState($pdo, $userId, 'add_chapter', $stateData);
                $total = count($stateData['file_ids']) + count($stateData['pages'] ?? []);
                sendMsg($chatId, "📸 Страница {$total} принята. Ещё или <b>стоп</b>");
                http_response_code(200); echo 'OK'; exit;
            }
        }
        http_response_code(200); echo 'OK'; exit;
    }

    # ---- FSM: EDIT_FIELD ----
    if ($currentState === 'edit_field') {
        $field   = $stateData['field'] ?? '';
        $mangaId = (int)($stateData['manga_id'] ?? 0);

        if ($field === 'cover') {
            if (!empty($message['photo'])) {
                $photo = end($message['photo']);
                sendMsg($chatId, '⏳ Загружаю обложку...');
                $imgbbUrl = uploadTgFileToImgbb($photo['file_id'], $IMGBB_KEYS);
                if ($imgbbUrl) {
                    $pdo->prepare("UPDATE manga SET cover_imgbb_url = ? WHERE id = ?")->execute([$imgbbUrl, $mangaId]);
                    clearState($pdo, $userId);
                    logArchive($pdo, 'edit_manga', "Изменена обложка манги ID: {$mangaId}", $userId);
                    sendMsg($chatId, "✅ Обложка обновлена!");
                    $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                    $stmt->execute([$mangaId]);
                    $manga = $stmt->fetch();
                    if ($manga) sendEditMangaMenu($chatId, $manga);
                } else {
                    sendMsg($chatId, '❌ Не удалось загрузить фото. Попробуй ещё раз.');
                }
            } else {
                sendMsg($chatId, '🖼 Отправь фото как новую обложку:');
            }
        } else {
            if (!empty($text)) {
                $colMap = ['title' => 'title', 'desc' => 'description', 'link' => 'telegraph_url'];
                $col = $colMap[$field] ?? null;
                if ($col) {
                    $newVal = $text;
                    if ($col === 'title' && strpos($newVal, '♥') !== 0) $newVal = '♥ ' . ltrim($newVal, '♥ ');
                    $pdo->prepare("UPDATE manga SET {$col} = ? WHERE id = ?")->execute([$newVal, $mangaId]);
                    clearState($pdo, $userId);
                    $fieldNames = ['title' => 'Название', 'desc' => 'Описание', 'link' => 'Ссылка'];
                    logArchive($pdo, 'edit_manga', "Изменено поле «" . ($fieldNames[$field] ?? $field) . "» манги ID: {$mangaId}", $userId);
                    sendMsg($chatId, "✅ " . ($fieldNames[$field] ?? 'Поле') . " обновлено!");
                    $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                    $stmt->execute([$mangaId]);
                    $manga = $stmt->fetch();
                    if ($manga) sendEditMangaMenu($chatId, $manga);
                }
            }
        }
        http_response_code(200); echo 'OK'; exit;
    }

    # ---- FSM: SEARCH ----
    if ($currentState === 'search') {
        clearState($pdo, $userId);
        if (!empty($text) && $text[0] !== '/') {
            showCatalog($chatId, $pdo, $SITE_URL, 0, $text);
        }
        http_response_code(200); echo 'OK'; exit;
    }

    # ---- FSM: SUGGEST ----
    if ($currentState === 'suggest') {
        clearState($pdo, $userId);
        if (!empty($text) && $text[0] !== '/') {
            $pdo->prepare("INSERT INTO suggestions (user_id, text) VALUES (?, ?)")->execute([$userId, $text]);
            // Уведомляем всех админов
            foreach ($HARDCODED_ADMINS as $adminId) {
                sendMsg($adminId, "💡 <b>Новое предложение</b> от пользователя {$userId}:\n\n" . htmlspecialchars(mb_substr($text, 0, 500)));
            }
            sendMsg($chatId, "🙏 <b>Спасибо!</b> Предложение передано администраторам.", $isAdmin ? adminMenuKb() : mainMenuKb());
        }
        http_response_code(200); echo 'OK'; exit;
    }

    # ---- FSM: EDIT_SEARCH ----
    if ($currentState === 'edit_search') {
        clearState($pdo, $userId);
        if (!empty($text) && $text[0] !== '/') {
            showEditSearch($chatId, $pdo, $text, 0);
        }
        http_response_code(200); echo 'OK'; exit;
    }
}

# =========================
# КОМАНДЫ И КНОПКИ
# =========================
$cmd = '';
$cmdArg = '';
if ($text && $text[0] === '/') {
    $parts = explode(' ', $text, 2);
    $cmd   = strtolower($parts[0]);
    $cmdArg = trim($parts[1] ?? '');
}

// /start
if ($cmd === '/start' || $text === '🏠 Главная') {
    clearState($pdo, $userId);

    // Обработка привязки TG к аккаунту: /start link_TOKEN
    if (preg_match('/^\/start link_([a-f0-9]{32})$/', $text, $linkMatch)) {
        $linkToken = $linkMatch[1];
        try {
            $accStmt = $pdo->prepare("SELECT id, username, tg_user_id FROM accounts WHERE tg_link_token=?");
            $accStmt->execute([$linkToken]);
            $linkAcc = $accStmt->fetch();

            if (!$linkAcc) {
                sendMsg($chatId, "❌ <b>Токен недействителен или уже использован.</b>\n\nПолучи новую команду в профиле на сайте.");
            } elseif ($linkAcc['tg_user_id'] && (int)$linkAcc['tg_user_id'] !== $userId) {
                sendMsg($chatId, "⚠️ Этот аккаунт уже привязан к другому Telegram.");
            } else {
                $pdo->prepare("UPDATE accounts SET tg_user_id=?, tg_link_token=NULL WHERE id=?")->execute([$userId, (int)$linkAcc['id']]);
                $siteUrl = rtrim(getenv('SITE_URL') ?: '', '/');
                sendMsg($chatId, "✅ <b>Telegram успешно привязан!</b>\n\nАккаунт: <b>{$linkAcc['username']}</b>\n\nТеперь твоя библиотека и прогресс синхронизированы между сайтом и ботом.\n\n🌐 <a href=\"{$siteUrl}/profile\">Открыть профиль</a>");
            }
        } catch (Exception $e) {
            sendMsg($chatId, "❌ Ошибка привязки. Попробуй ещё раз.");
        }
        http_response_code(200); echo 'OK'; exit;
    }

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
            . "/add — добавить мангу (альбом)\n"
            . "/addzip — добавить мангу (ZIP)\n"
            . "/addseries — создать серию\n"
            . "/addchapter — добавить главу\n"
            . "/edit — редактировать мангу\n"
            . "/stats — статистика\n"
            . "/archive — архив действий\n"
            . "/delete [id] — удалить мангу\n"
            . "/addadmin [user_id] — добавить админа\n";
    }
    sendMsg($chatId, $helpText, $isAdmin ? adminMenuKb() : mainMenuKb());
    http_response_code(200); echo 'OK'; exit;
}

// Каталог / Поиск
if ($cmd === '/catalog' || $text === '🔍 Найти мангу') {
    clearState($pdo, $userId);
    if ($cmd === '/catalog' && !empty($cmdArg)) {
        showCatalog($chatId, $pdo, $SITE_URL, 0, $cmdArg);
    } else {
        setState($pdo, $userId, 'search', []);
        sendMsg($chatId, "🔍 Введи название манги для поиска:\n\n(или пришли любой текст)");
        // Также показываем весь каталог
        showCatalog($chatId, $pdo, $SITE_URL, 0, '', 'new');
    }
    http_response_code(200); echo 'OK'; exit;
}

if ($cmd === '/search') {
    clearState($pdo, $userId);
    if (!empty($cmdArg)) {
        showCatalog($chatId, $pdo, $SITE_URL, 0, $cmdArg);
    } else {
        setState($pdo, $userId, 'search', []);
        sendMsg($chatId, "🔍 Введи название манги для поиска:");
    }
    http_response_code(200); echo 'OK'; exit;
}

// Топ
if ($text === '🔥 Топ по лайкам') {
    clearState($pdo, $userId);
    showCatalog($chatId, $pdo, $SITE_URL, 0, '', 'popular');
    http_response_code(200); echo 'OK'; exit;
}

// Случайная
if ($cmd === '/random' || $text === '🎲 Случайная манга') {
    clearState($pdo, $userId);
    $rStmt = $pdo->prepare("SELECT * FROM manga WHERE id NOT IN (SELECT manga_id FROM user_manga_status WHERE user_id = ? AND status = 'read') ORDER BY RANDOM() LIMIT 1");
    $rStmt->execute([$userId]);
    $row = $rStmt->fetch();
    if (!$row) $row = $pdo->query("SELECT * FROM manga ORDER BY RANDOM() LIMIT 1")->fetch();
    if ($row) sendMangaCard($chatId, $row, $SITE_URL, $userId);
    else sendMsg($chatId, '😔 Каталог пока пуст.');
    http_response_code(200); echo 'OK'; exit;
}

// Библиотека
if ($cmd === '/library' || $text === '📚 Моя библиотека') {
    clearState($pdo, $userId);
    showLibrary($chatId, $userId, $pdo, $SITE_URL);
    http_response_code(200); echo 'OK'; exit;
}

// Сайт
if ($text === '🌐 Сайт каталога') {
    sendMsg($chatId, "🌐 <b>Открыть каталог:</b>\n{$SITE_URL}?tg_user_id={$userId}");
    http_response_code(200); echo 'OK'; exit;
}

// Поддержка
if ($text === '💡 Поддержка') {
    clearState($pdo, $userId);
    setState($pdo, $userId, 'suggest', []);
    sendMsg($chatId, "💡 <b>Поддержка / Предложения</b>\n\nНапиши своё предложение или вопрос — оно будет отправлено администраторам:");
    http_response_code(200); echo 'OK'; exit;
}

// ======= ADMIN BUTTONS =======

// Режим читателя
if ($text === '🔙 Режим читателя' && $isAdmin) {
    clearState($pdo, $userId);
    sendMsg($chatId, "👋 Вышел в режим читателя.", adminMenuKb());
    http_response_code(200); echo 'OK'; exit;
}

// Админ-панель
if ($text === '⚙️ Админ-панель' && $isAdmin) {
    clearState($pdo, $userId);
    sendMsg($chatId, "🛡 <b>Админ-панель</b>\n\nВыбери действие:", adminPanelKb());
    http_response_code(200); echo 'OK'; exit;
}

// Добавить через альбом
if (($cmd === '/add' || $text === '➕ Добавить через альбом') && $isAdmin) {
    clearState($pdo, $userId);
    setState($pdo, $userId, 'add_manga', ['step' => 'title', 'file_ids' => [], 'pages' => []]);
    sendMsg($chatId,
        "➕ <b>Добавление манги через альбом</b>\n\nВведи <b>название</b> манги:\n\nДля отмены — /cancel",
        ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]
    );
    http_response_code(200); echo 'OK'; exit;
}

// Добавить через ZIP
if (($cmd === '/addzip' || $text === '📦 Добавить через ZIP') && $isAdmin) {
    clearState($pdo, $userId);
    setState($pdo, $userId, 'add_manga_zip', ['step' => 'zip']);
    sendMsg($chatId,
        "📦 <b>Добавление манги через ZIP</b>\n\nОтправь ZIP-архив со страницами.\n\n⚠️ Максимальный размер: 20 МБ (ограничение Telegram).\nСтраницы будут отсортированы по дате внутри архива.",
        ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]
    );
    http_response_code(200); echo 'OK'; exit;
}

// Добавить серию
if (($cmd === '/addseries' || $text === '📚 Добавить серию') && $isAdmin) {
    clearState($pdo, $userId);
    setState($pdo, $userId, 'add_series', ['step' => 'title']);
    sendMsg($chatId,
        "📚 <b>Создание новой серии</b>\n\nСерия — это манга с несколькими главами.\n\nВведи <b>название</b> серии:",
        ['inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'cancel_state']]]]
    );
    http_response_code(200); echo 'OK'; exit;
}

// Добавить главу
if (($cmd === '/addchapter' || $text === '📑 Добавить главу') && $isAdmin) {
    clearState($pdo, $userId);
    $seriesCount = (int)$pdo->query("SELECT COUNT(*) FROM manga WHERE is_series = TRUE")->fetchColumn();
    if ($seriesCount === 0) {
        sendMsg($chatId, "📭 Серий ещё нет.\n\nСначала создай серию через «📚 Добавить серию».");
        http_response_code(200); echo 'OK'; exit;
    }
    showSeriesList($chatId, $pdo, 0, $cmdArg);
    http_response_code(200); echo 'OK'; exit;
}

// Редактирование
if (($cmd === '/edit' || $text === '✏️ Редактирование') && $isAdmin) {
    clearState($pdo, $userId);
    setState($pdo, $userId, 'edit_search', []);
    showEditSearch($chatId, $pdo, '', 0);
    http_response_code(200); echo 'OK'; exit;
}

// Статистика
if (($cmd === '/stats' || $text === '📊 Статистика') && $isAdmin) {
    clearState($pdo, $userId);
    showStats($chatId, $pdo);
    http_response_code(200); echo 'OK'; exit;
}

// Архив
if (($cmd === '/archive' || $text === '🗂 Архив бота') && $isAdmin) {
    clearState($pdo, $userId);
    showArchive($chatId, $pdo, 0);
    http_response_code(200); echo 'OK'; exit;
}

// Предложки
if (($text === '📥 Предложки') && $isAdmin) {
    clearState($pdo, $userId);
    $stmt = $pdo->query("SELECT id, text, user_id FROM suggestions WHERE status = 'new' ORDER BY id DESC LIMIT 15");
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        sendMsg($chatId, "📭 Новых предложений нет.");
    } else {
        $btns = [];
        foreach ($rows as $row) {
            $btns[] = [['text' => "✉️ " . mb_substr($row['text'], 0, 35) . '...', 'callback_data' => "view_suggest:{$row['id']}"]];
        }
        sendMsg($chatId, "📋 <b>Новые предложения (" . count($rows) . "):</b>", ['inline_keyboard' => $btns]);
    }
    http_response_code(200); echo 'OK'; exit;
}

// FAQ
if ($text === '❓ FAQ и Команды' && $isAdmin) {
    sendMsg($chatId, "📖 <b>ИНСТРУКЦИЯ АДМИНА</b>\n\n"
        . "<b>Добавить мангу:</b>\n"
        . "1. «➕ Добавить через альбом» — фото по одному\n"
        . "2. «📦 Добавить через ZIP» — ZIP-архив (макс. 20 МБ)\n\n"
        . "<b>Добавить серию с главами:</b>\n"
        . "1. «📚 Добавить серию» — создаёт серию\n"
        . "2. «📑 Добавить главу» — добавляет главу к серии\n"
        . "   Можно и через «✏️ Редактирование» → «📑 Добавить главу»\n\n"
        . "<b>Редактирование:</b>\n"
        . "«✏️ Редактирование» — выбери мангу и измени поля\n\n"
        . "<b>Команды:</b>\n"
        . "/add, /addzip, /addseries, /addchapter\n"
        . "/edit, /stats, /archive\n"
        . "/delete [manga_id]\n"
        . "/addadmin [user_id]");
    http_response_code(200); echo 'OK'; exit;
}

// /addadmin
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

// /delete
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

// /done — если нет состояния
if ($cmd === '/done') {
    sendMsg($chatId, '⚠️ Нет активного действия.');
    http_response_code(200); echo 'OK'; exit;
}

// Быстрый поиск по тексту (если не команда, не кнопка меню)
$menuTexts = [
    '🔍 Найти мангу', '📚 Моя библиотека', '🔥 Топ по лайкам', '🎲 Случайная манга',
    '🌐 Сайт каталога', '💡 Поддержка', '⚙️ Админ-панель', '🔙 Режим читателя',
    '➕ Добавить через альбом', '📦 Добавить через ZIP', '📚 Добавить серию',
    '📑 Добавить главу', '✏️ Редактирование', '📊 Статистика', '📥 Предложки',
    '🗂 Архив бота', '❓ FAQ и Команды', '🏠 Главная',
];
if ($text && $text[0] !== '/' && !in_array($text, $menuTexts) && strlen($text) >= 2 && strlen($text) <= 100) {
    // Быстрый поиск без перехода в FSM
    showCatalog($chatId, $pdo, $SITE_URL, 0, $text);
    http_response_code(200); echo 'OK'; exit;
}

// Дефолт
sendMsg($chatId,
    "👋 Используй меню ниже или /help\n\n🌐 {$SITE_URL}",
    $isAdmin ? adminMenuKb() : mainMenuKb()
);

http_response_code(200);
echo 'OK';