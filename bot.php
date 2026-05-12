<?php
// ОТЛАДКА - временно, потом удалить
// file_put_contents('bot_debug.txt', date('Y-m-d H:i:s') . ' - ' . file_get_contents("php://input") . "\n", FILE_APPEND);

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

set_time_limit(900);
ini_set('memory_limit', '512M');

$token  = getenv('BOT_TOKEN');
$apiUrl = "https://api.telegram.org/bot$token";
$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://blackwatch-manga.onrender.com', '/');

// PDO подключение к PostgreSQL (Neon)
try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        getenv('DB_HOST'),
        getenv('DB_PORT') ?: '5432',
        getenv('DB_NAME')
    );
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die("DB Error: " . $e->getMessage());
}

// Создание таблиц если не существует
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga (id SERIAL PRIMARY KEY, title TEXT NOT NULL, description TEXT, telegraph_url TEXT, cover_imgbb_url TEXT, likes INT DEFAULT 0, dislikes INT DEFAULT 0, added_by BIGINT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archive (id SERIAL PRIMARY KEY, action_type VARCHAR(50) NOT NULL, action_text TEXT NOT NULL, action_by BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tags (user_id BIGINT PRIMARY KEY, tag_name VARCHAR(100) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_pages (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (user_id BIGINT PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (user_id BIGINT NOT NULL, manga_id INT NOT NULL, vote_type VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (user_id BIGINT NOT NULL, manga_id INT NOT NULL, status VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS suggestions (id SERIAL PRIMARY KEY, user_id BIGINT NOT NULL, text TEXT NOT NULL, status VARCHAR(20) DEFAULT 'new', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS temp_data (user_id BIGINT PRIMARY KEY, step VARCHAR(50) NOT NULL, title TEXT, description TEXT, pages TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_admins (user_id BIGINT PRIMARY KEY)");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga DROP COLUMN IF EXISTS cover_id");
} catch (Exception $e) {}

$superAdmins = [1710365896, 1181510470];
$admins = [1710365896, 1181510470];

try {
    $stmtAdmins = $pdo->query("SELECT user_id FROM bot_admins");
    while($row = $stmtAdmins->fetch()) {
        if(!in_array($row['user_id'], $admins)) $admins[] = $row['user_id'];
    }
} catch (Exception $e) {}

$imgbbKey = '58ff4596fd55028a81cbf8c4e38388e1';

// --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---

function tgPost($url, $data) {
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
    return $result;
}

function downloadFile($url) {
    $tempName = tempnam(sys_get_temp_dir(), 'manga_img_');
    $ch = curl_init($url);
    $fp = fopen($tempName, 'wb');
    if (!$fp) return false;
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($httpCode == 200 && filesize($tempName) > 0) return $tempName;
    if (file_exists($tempName)) @unlink($tempName);
    return false;
}

function uploadToImgbb($tempFile, $apiKey) {
    $imageData = base64_encode(file_get_contents($tempFile));
    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $apiKey, 'image' => $imageData]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($response, true);
    return $res['data']['url'] ?? false;
}

function getTelegramImageUrl($token, $fileId) {
    $ch = curl_init("https://api.telegram.org/bot{$token}/getFile?file_id={$fileId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return false;
    $data = json_decode($res, true);
    if (!isset($data['result']['file_path'])) return false;
    return "https://api.telegram.org/file/bot{$token}/" . $data['result']['file_path'];
}

function getPromoImageUrl($pdo, $imgbbKey) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM bot_settings WHERE setting_key = 'promo_imgbb_url'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['setting_value'])) {
            return $row['setting_value'];
        }
    } catch (Exception $e) {}
    $promoPath = __DIR__ . '/promo.jpg';
    if (!file_exists($promoPath)) return null;
    $imageData = base64_encode(file_get_contents($promoPath));
    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $imgbbKey, 'image' => $imageData]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($response, true);
    $url = $res['data']['url'] ?? null;
    if ($url) {
        try {
            $pdo->prepare("INSERT INTO bot_settings (setting_key, setting_value) VALUES ('promo_imgbb_url', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value")->execute([$url]);
        } catch (Exception $e) {}
    }
    return $url;
}

function extractAndSortZip($zipPath, $extractDir) {
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $raw = file_get_contents($zipPath);
    if ($raw === false) return false;
    $len = strlen($raw);
    $eocdPos = false;
    for ($i = $len - 22; $i >= max(0, $len - 65557); $i--) {
        if (substr($raw, $i, 4) === "\x50\x4b\x05\x06") {
            $eocdPos = $i;
            break;
        }
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
        $entries[] = [
            'name'       => $fname,
            'base'       => $base,
            'mtime'      => $mtime,
            'compress'   => $compress,
            'compSize'   => $compSize,
            'origSize'   => $origSize,
            'dataOffset' => $dataOffset,
        ];
    }
    if (empty($entries)) return false;
    usort($entries, function($a, $b) {
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
        } else {
            continue;
        }
        if (file_put_contents($outPath, $fileData) === false) continue;
        $extractedPaths[] = $outPath;
    }
    return empty($extractedPaths) ? false : $extractedPaths;
}

// FIX: createTelegraphPage — загружает страницы правильно через ImgBB URL
function createTelegraphPage($title, $imageUrls, $token, $pdo, $imgbbKey) {
    $nodes = [];
    $imageUrls = array_values(array_unique($imageUrls));
    $promoUrl = getPromoImageUrl($pdo, $imgbbKey);
    if ($promoUrl) $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $promoUrl]];
    foreach ($imageUrls as $url) {
        if (!empty($url)) {
            $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $url]];
        }
    }
    if (empty($nodes)) return false;

    // Telegraph access token — укажите свой, если нужно заменить
    $accessToken = '192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831';

    $postData = [
        'title'          => mb_substr($title, 0, 256),
        'author_name'    => 'Manga Reader',
        'content'        => json_encode($nodes, JSON_UNESCAPED_UNICODE),
        'return_content' => 'true',
    ];
    $ch = curl_init("https://api.telegra.ph/createPage?access_token=" . $accessToken);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $rawResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("Telegraph cURL error: " . $curlErr);
        return false;
    }

    $res = json_decode($rawResponse, true);
    if (!isset($res['ok']) || !$res['ok']) {
        error_log("Telegraph API error: " . $rawResponse);
        return false;
    }
    return $res['result']['url'] ?? false;
}

function saveMangaPages($pdo, $mangaId, $pageUrls) {
    $pdo->prepare("DELETE FROM manga_pages WHERE manga_id = ?")->execute([$mangaId]);
    $stmt = $pdo->prepare("INSERT INTO manga_pages (manga_id, page_url, page_order) VALUES (?, ?, ?)");
    foreach ($pageUrls as $i => $url) {
        if (!empty($url)) {
            $stmt->execute([$mangaId, $url, $i]);
        }
    }
}

function getAdminTag($pdo, $adminId) {
    try {
        $stmt = $pdo->prepare("SELECT tag_name FROM admin_tags WHERE user_id = ?");
        $stmt->execute([$adminId]);
        $row = $stmt->fetch();
        return $row ? $row['tag_name'] : "ID: $adminId";
    } catch (Exception $e) { return "ID: $adminId"; }
}

function logArchive($pdo, $type, $text, $by) {
    try { $pdo->prepare("INSERT INTO bot_archive (action_type, action_text, action_by) VALUES (?, ?, ?)")->execute([$type, $text, $by]); } catch (Exception $e) {}
}

function getMainMenu($chatId, $admins) {
    $rows = [
        [['text' => '🔍 Найти мангу'], ['text' => '📚 Моя библиотека']],
        [['text' => '🔥 Топ по лайкам'], ['text' => '🎲 Случайная манга']],
        [['text' => '🌐 Сайт каталога'], ['text' => '💡 Предложить мангу']]
    ];
    if (in_array($chatId, $admins)) $rows[] = [['text' => '⚙️ АДМИН-ПАНЕЛЬ']];
    return json_encode(['keyboard' => $rows, 'resize_keyboard' => true]);
}

$adminKeyboard = json_encode([
    'keyboard' => [
        [['text' => '➕ Добавить через альбом'], ['text' => '📦 Добавить через ZIP']],
        [['text' => '✏️ Редактирование'],         ['text' => '📊 Статистика админов']],
        [['text' => '📥 Читать предложку'],        ['text' => '🗂 Архив бота']],
        [['text' => '❓ FAQ и Команды'],            ['text' => '🔙 Выйти в режим читателя']]
    ],
    'resize_keyboard' => true
]);

$content = file_get_contents("php://input");
$update  = json_decode($content, true);
if (!$update) exit;


// =============================================
// CALLBACK QUERY
// =============================================
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId   = $callback['message']['chat']['id'];
    $data     = $callback['data'];
    $msgId    = $callback['message']['message_id'];

    if (strpos($data, 'vote_') === 0) {
        $parts = explode('_', $data);
        $type = $parts[1];
        $mId = $parts[2];
        $check = $pdo->prepare("SELECT vote_type FROM votes WHERE user_id = ? AND manga_id = ?");
        $check->execute([$chatId, $mId]);
        $existing = $check->fetch();
        if ($existing) {
            if ($existing['vote_type'] !== $type) {
                $oldCol = ($existing['vote_type'] == 'like') ? 'likes' : 'dislikes';
                $newCol = ($type == 'like') ? 'likes' : 'dislikes';
                $pdo->prepare("UPDATE votes SET vote_type = ? WHERE user_id = ? AND manga_id = ?")->execute([$type, $chatId, $mId]);
                $pdo->prepare("UPDATE manga SET $oldCol = $oldCol - 1, $newCol = $newCol + 1 WHERE id = ?")->execute([$mId]);
                $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                $stmt->execute([$mId]);
                updateMangaMessage($chatId, $msgId, $stmt->fetch(), $apiUrl);
            }
        } else {
            $col = ($type == 'like') ? 'likes' : 'dislikes';
            $pdo->prepare("INSERT INTO votes (user_id, manga_id, vote_type) VALUES (?, ?, ?)")->execute([$chatId, $mId, $type]);
            $pdo->prepare("UPDATE manga SET $col = $col + 1 WHERE id = ?")->execute([$mId]);
            $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
            $stmt->execute([$mId]);
            updateMangaMessage($chatId, $msgId, $stmt->fetch(), $apiUrl);
        }
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'show_') === 0) {
        $mId = str_replace('show_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        if ($m) sendMangaCard($chatId, $m, $apiUrl, $siteUrl);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'search_page_') === 0) {
        $parts = explode('_', str_replace('search_page_', '', $data));
        $page = (int)array_pop($parts);
        $q    = urldecode(implode('_', $parts));
        $c    = getSearchData($pdo, $q, $page);
        tgPost($apiUrl . "/editMessageText", [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'text'         => $c['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_decode($c['reply_markup'])
        ]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'stat_') === 0) {
        $parts  = explode('_', $data);
        $status = $parts[1];
        $mId    = $parts[2];
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON CONFLICT (user_id, manga_id) DO UPDATE SET status = EXCLUDED.status")->execute([$chatId, $mId, $status]);
        $labels = ['now' => '📖 Читаете сейчас!', 'read' => '✅ Отмечено как прочитанное!'];
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => $labels[$status] ?? 'Сохранено']);
        exit;
    }

    if (strpos($data, 'edit_page_') === 0) {
        $raw2   = str_replace('edit_page_', '', $data);
        $pParts = explode('_', $raw2);
        $page   = (int)array_pop($pParts);
        $q      = urldecode(implode('_', $pParts));
        $c      = getEditSearchData($pdo, $q, $page);
        tgPost($apiUrl . "/editMessageText", [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'text'         => $c['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_decode($c['reply_markup'])
        ]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'edit_manga_') === 0) {
        $mId = str_replace('edit_manga_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        if ($m) sendEditMangaMenu($chatId, $m, $apiUrl);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'editfield_') === 0) {
        $raw2   = str_replace('editfield_', '', $data);
        $uParts = explode('_', $raw2);
        $mId    = array_pop($uParts);
        $field  = implode('_', $uParts);
        $hints = [
            'cover' => "🖼 Отправьте новое фото-обложку:",
            'title' => "📖 Введите новое название (без ❤️, добавится автоматически):",
            'desc'  => "📝 Введите новое описание:",
            'link'  => "🔗 Введите новую ссылку (Telegra.ph или другую):",
        ];
        $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, ?, ?) ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId, "edit_wait_$field", json_encode(['manga_id' => $mId])]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        sendSimpleMsg($chatId, $hints[$field] ?? "Введите новое значение:", $apiUrl);
        exit;
    }

    if (strpos($data, 'delete_confirm_') === 0) {
        $mId = str_replace('delete_confirm_', '', $data);
        $stmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        if ($m) {
            $kb = ['inline_keyboard' => [[
                ['text' => '✅ Да, удалить',  'callback_data' => 'delete_yes_' . $mId],
                ['text' => '❌ Отмена',        'callback_data' => 'delete_cancel_' . $mId],
            ]]];
            tgPost($apiUrl . "/sendMessage", ['chat_id' => $chatId, 'text' => "🗑 *Удалить мангу «{$m['title']}»?*\n\n_Это действие необратимо._", 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
        }
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'delete_yes_') === 0) {
        $mId = str_replace('delete_yes_', '', $data);
        $stmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        if ($m) {
            $pdo->prepare("DELETE FROM manga WHERE id = ?")->execute([$mId]);
            $pdo->prepare("DELETE FROM manga_pages WHERE manga_id = ?")->execute([$mId]);
            logArchive($pdo, 'delete_manga', "Удалена манга: {$m['title']}", $chatId);
            tgPost($apiUrl . "/editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "🗑 *Манга «{$m['title']}» удалена.*", 'parse_mode' => 'Markdown']);
        }
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '🗑 Удалено']);
        exit;
    }

    if (strpos($data, 'delete_cancel_') === 0) {
        tgPost($apiUrl . "/editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "❌ Удаление отменено."]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '❌ Отменено']);
        exit;
    }

    if (strpos($data, 'view_suggest_') === 0) {
        $sId = str_replace('view_suggest_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ?");
        $stmt->execute([$sId]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("UPDATE suggestions SET status = 'read' WHERE id = ?")->execute([$sId]);
            $kb = ['inline_keyboard' => [[['text' => '✅ Рассмотрено', 'callback_data' => 'done_suggest_' . $sId]]]];
            tgPost($apiUrl . "/sendMessage", ['chat_id' => $chatId, 'text' => "📩 *Предложение от пользователя:*\n\n_" . $s['text'] . "_", 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
        }
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'done_suggest_') === 0) {
        $sId = str_replace('done_suggest_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ?");
        $stmt->execute([$sId]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("UPDATE suggestions SET status = 'done' WHERE id = ?")->execute([$sId]);
            tgPost($apiUrl . "/sendMessage", ['chat_id' => $s['user_id'], 'text' => "📬 *Ваше предложение рассмотрено!*\n\nСпасибо за активность — администраторы ознакомились с вашим сообщением. 🙏", 'parse_mode' => 'Markdown']);
            tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '✅ Пользователь уведомлён']);
        }
        exit;
    }

    if (strpos($data, 'archive_page_') === 0) {
        $page = (int)str_replace('archive_page_', '', $data);
        $archiveData = getArchiveData($pdo, $admins, $page);
        tgPost($apiUrl . "/editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $archiveData['text'], 'parse_mode' => 'Markdown', 'reply_markup' => json_decode($archiveData['reply_markup'])]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'bind_cover_') === 0) {
        $mId = str_replace('bind_cover_', '', $data);
        $stateRow = $pdo->prepare("SELECT pages FROM temp_data WHERE user_id = ? AND step = 'wait_cover_choice'");
        $stateRow->execute([$chatId]);
        $stateRow = $stateRow->fetch();
        if ($stateRow) {
            $stateData = json_decode($stateRow['pages'], true);
            $imageUrl = $stateData['cover_image_url'] ?? null;
            if ($imageUrl) {
                $tempFile = downloadFile($imageUrl);
                if ($tempFile) {
                    $imgbbUrl = uploadToImgbb($tempFile, $imgbbKey);
                    @unlink($tempFile);
                    if ($imgbbUrl) $pdo->prepare("UPDATE manga SET cover_imgbb_url = ? WHERE id = ?")->execute([$imgbbUrl, $mId]);
                }
                $titleStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
                $titleStmt->execute([$mId]);
                $titleRow = $titleStmt->fetch();
                logArchive($pdo, 'set_cover', "Установлена обложка для манги: " . ($titleRow['title'] ?? '?'), $chatId);
                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '✅ Обложка привязана!']);
                tgPost($apiUrl . "/editMessageCaption", ['chat_id' => $chatId, 'message_id' => $msgId, 'caption' => "✅ *Обложка успешно привязана* к манге *{$titleRow['title']}*!", 'parse_mode' => 'Markdown']);
            }
        }
        exit;
    }

    exit;
}


// =============================================
// MESSAGE
// =============================================
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId  = $message['chat']['id'];
    $text    = $message['text'] ?? $message['caption'] ?? '';

    $stmtState = $pdo->prepare("SELECT * FROM temp_data WHERE user_id = ?");
    $stmtState->execute([$chatId]);
    $userState = $stmtState->fetch();

    try {
        $pdo->prepare("INSERT INTO users (user_id) VALUES (?) ON CONFLICT (user_id) DO NOTHING")->execute([$chatId]);
    } catch (Exception $e) {}

    if (in_array($chatId, $admins)) {

        // --- Шаги редактирования ---
        if ($userState && strpos($userState['step'], 'edit_wait_') === 0) {
            $field     = str_replace('edit_wait_', '', $userState['step']);
            $stateData = json_decode($userState['pages'], true);
            $mId       = $stateData['manga_id'] ?? null;
            if ($mId) {
                if ($field === 'cover') {
                    if (isset($message['photo'])) {
                        $photo = end($message['photo']);
                        $fileId = $photo['file_id'];
                        $imageUrl = getTelegramImageUrl($token, $fileId);
                        if ($imageUrl) {
                            $tempFile = downloadFile($imageUrl);
                            if ($tempFile) {
                                $imgbbUrl = uploadToImgbb($tempFile, $imgbbKey);
                                @unlink($tempFile);
                                if ($imgbbUrl) $pdo->prepare("UPDATE manga SET cover_imgbb_url = ? WHERE id = ?")->execute([$imgbbUrl, $mId]);
                            }
                            logArchive($pdo, 'edit_manga', "Изменена обложка манги ID: $mId", $chatId);
                            $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                            $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                            $stmt->execute([$mId]);
                            $m = $stmt->fetch();
                            tgPost($apiUrl . "/sendPhoto", ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => "✅ *Обложка обновлена!*\n\nМанга: *{$m['title']}*", 'parse_mode' => 'Markdown']);
                            sendEditMangaMenu($chatId, $m, $apiUrl);
                        } else {
                            sendSimpleMsg($chatId, "❌ Не удалось получить URL изображения", $apiUrl);
                        }
                    } else {
                        sendSimpleMsg($chatId, "🖼 Пожалуйста, отправьте *фото* как новую обложку:", $apiUrl);
                    }
                } else {
                    if (!empty($text)) {
                        $colMap = ['title' => 'title', 'desc' => 'description', 'link' => 'telegraph_url'];
                        $col = $colMap[$field] ?? null;
                        if ($col) {
                            $newVal = $text;
                            if ($col === 'title' && strpos($newVal, '❤️') !== 0) $newVal = '❤️ ' . $newVal;
                            $pdo->prepare("UPDATE manga SET $col = ? WHERE id = ?")->execute([$newVal, $mId]);
                            $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                            $stmt->execute([$mId]);
                            $m = $stmt->fetch();
                            $fieldNames = ['title' => 'Название', 'desc' => 'Описание', 'link' => 'Ссылка'];
                            $fieldName  = $fieldNames[$field] ?? $field;
                            logArchive($pdo, 'edit_manga', "Изменено поле «$fieldName» манги: " . ($m['title'] ?? '?'), $chatId);
                            $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                            sendSimpleMsg($chatId, "✅ *$fieldName обновлено!*", $apiUrl);
                            sendEditMangaMenu($chatId, $m, $apiUrl);
                        }
                    }
                }
            }
            exit;
        }

        // --- Поиск для редактирования ---
        if ($userState && $userState['step'] == 'wait_edit_search') {
            $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
            $c = getEditSearchData($pdo, trim($text), 0);
            sendSimpleMsg($chatId, $c['text'], $apiUrl, $c['reply_markup']);
            exit;
        }

        // --- Привязка обложки по подписи +название (с фото) ---
        if (isset($message['photo']) && !empty($text) && strpos(trim($text), '+') === 0) {
            $searchTitle = trim(mb_substr(trim($text), 1));
            $photo = end($message['photo']);
            $fileId = $photo['file_id'];
            $imageUrl = getTelegramImageUrl($token, $fileId);
            if (!empty($searchTitle) && $imageUrl) {
                $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? LIMIT 5");
                $stmt->execute(['%' . $searchTitle . '%']);
                $found = $stmt->fetchAll();
                if (count($found) === 1) {
                    $tempFile = downloadFile($imageUrl);
                    if ($tempFile) {
                        $imgbbUrl = uploadToImgbb($tempFile, $imgbbKey);
                        @unlink($tempFile);
                        if ($imgbbUrl) $pdo->prepare("UPDATE manga SET cover_imgbb_url = ? WHERE id = ?")->execute([$imgbbUrl, $found[0]['id']]);
                    }
                    logArchive($pdo, 'set_cover', "Установлена обложка для манги: {$found[0]['title']}", $chatId);
                    tgPost($apiUrl . "/sendPhoto", ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => "✅ *Обложка привязана* к манге *{$found[0]['title']}*!", 'parse_mode' => 'Markdown']);
                } elseif (count($found) > 1) {
                    $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, 'wait_cover_choice', ?) ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId, json_encode(['cover_image_url' => $imageUrl, 'cover_file_id' => $fileId])]);
                    $btns = [];
                    foreach ($found as $r) $btns[] = [['text' => '📘 ' . $r['title'], 'callback_data' => 'bind_cover_' . $r['id']]];
                    tgPost($apiUrl . "/sendPhoto", ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => "🔎 *Найдено несколько манг*\n\n_Выберите, к какой привязать обложку:_", 'parse_mode' => 'Markdown', 'reply_markup' => json_encode(['inline_keyboard' => $btns])]);
                } else {
                    tgPost($apiUrl . "/sendPhoto", ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => "❌ *Манга «$searchTitle» не найдена.*", 'parse_mode' => 'Markdown']);
                }
                exit;
            }
        }

        // --- ZIP обработка ---
        if (isset($message['document'])) {
            $doc      = $message['document'];
            $mimeType = $doc['mime_type'] ?? '';
            $fileName = $doc['file_name'] ?? '';
            $isZip = ($mimeType === 'application/zip' || $mimeType === 'application/x-zip-compressed' || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'zip');
            if ($isZip && $userState && $userState['step'] === 'wait_zip') {
                sendSimpleMsg($chatId, "📦 _ZIP получен. Распаковываю и сортирую по дате..._", $apiUrl);
                $ch = curl_init("https://api.telegram.org/bot$token/getFile?file_id=" . $doc['file_id']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $fileInfoJson = curl_exec($ch);
                curl_close($ch);
                $fileInfo = json_decode($fileInfoJson, true);
                if (!isset($fileInfo['result']['file_path'])) {
                    sendSimpleMsg($chatId, "❌ *Не удалось получить файл от Telegram.*\n\n_Telegram Bot API не отдаёт файлы больше 20 МБ._", $apiUrl);
                    exit;
                }
                $zipUrl  = "https://api.telegram.org/file/bot$token/" . $fileInfo['result']['file_path'];
                $zipTemp = downloadFile($zipUrl);
                if (!$zipTemp) {
                    sendSimpleMsg($chatId, "❌ *Ошибка скачивания ZIP-архива.*", $apiUrl);
                    exit;
                }
                $extractDir = sys_get_temp_dir() . '/manga_zip_' . $chatId . '_' . time();
                @mkdir($extractDir, 0777, true);
                $extractedFiles = extractAndSortZip($zipTemp, $extractDir);
                @unlink($zipTemp);
                if (!$extractedFiles) {
                    sendSimpleMsg($chatId, "❌ *Не удалось распаковать ZIP.* Убедитесь, что архив содержит фото.", $apiUrl);
                    exit;
                }
                $count = count($extractedFiles);
                sendSimpleMsg($chatId, "📸 _Распаковано $count фото. Загружаю на ImgBB..._", $apiUrl);
                $imgUrls = [];
                foreach ($extractedFiles as $imgPath) {
                    $url = uploadToImgbb($imgPath, $imgbbKey);
                    @unlink($imgPath);
                    if ($url) $imgUrls[] = $url;
                }
                @rmdir($extractDir);
                if (empty($imgUrls)) {
                    sendSimpleMsg($chatId, "❌ *Не удалось загрузить фото на сервер.*", $apiUrl);
                    exit;
                }
                $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, 'wait_title_zip', ?) ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId, json_encode(['imgbb_urls' => $imgUrls])]);
                sendSimpleMsg($chatId, "✅ _Загружено " . count($imgUrls) . " фото!\n\n*Шаг 2 из 4:* Введите *название* манги:_", $apiUrl);
                exit;
            }
        }

        // --- Быстрое добавление через команду +Название|ссылка|описание ---
        if (!isset($message['photo']) && strpos(trim($text), '+') === 0 && strpos($text, '|') !== false) {
            $raw2   = trim(mb_substr(trim($text), 1));
            $parts  = array_map('trim', explode('|', $raw2));
            if (count($parts) >= 3) {
                $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, added_by) VALUES (?, ?, ?, ?)")->execute(['❤️ ' . $parts[0], $parts[1], $parts[2], $chatId]);
                logArchive($pdo, 'add_manga', "Опубликована манга: {$parts[0]}", $chatId);
                sendSimpleMsg($chatId, "✅ Манга добавлена в каталог!\n\n_Чтобы добавить обложку — отправьте фото с подписью:_\n`+{$parts[0]}`", $apiUrl);
            }
            exit;
        }

        // --- Начало загрузки через альбом ---
        if ($text == "➕ Добавить через альбом") {
            $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, 'wait_pages', '[]') ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId]);
            sendSimpleMsg($chatId, "🖼 *Шаг 1 из 4:* Отправьте страницы главы *альбомом* или по одному фото.\n\n_Когда загрузите всё — напишите:_ *стоп*", $apiUrl);
            exit;
        }

        // --- Начало загрузки через ZIP ---
        if ($text == "📦 Добавить через ZIP") {
            $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, 'wait_zip', '[]') ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId]);
            sendSimpleMsg($chatId, "📦 *Шаг 1 из 4:* Отправьте ZIP-архив с фото главы.\n\n⚠️ *Ограничение Telegram API:* файлы до 20 МБ.", $apiUrl);
            exit;
        }

        // --- Редактирование ---
        if ($text == "✏️ Редактирование") {
            $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, 'wait_edit_search', '[]') ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId]);
            $c = getEditSearchData($pdo, '', 0);
            sendSimpleMsg($chatId, "✏️ *Редактирование манги*\n\n_Выберите мангу из списка:_", $apiUrl, $c['reply_markup']);
            exit;
        }

        // --- Шаги загрузки через альбом ---
        if ($userState) {
            if ($userState['step'] == 'wait_pages') {
                if (isset($message['photo'])) {
                    // Принимаем фото (в т.ч. из альбома — они приходят по одному)
                    $pages = json_decode($userState['pages'], true);
                    $pages[] = end($message['photo'])['file_id'];
                    $pdo->prepare("UPDATE temp_data SET pages = ? WHERE user_id = ?")->execute([json_encode($pages), $chatId]);
                    // Тихо принимаем, не отвечаем на каждое фото (чтобы не спамить)
                    exit;
                } elseif (mb_strtolower(trim($text)) == 'стоп') {
                    $pages = json_decode($userState['pages'], true);
                    if (empty($pages)) {
                        sendSimpleMsg($chatId, "❌ *Нет загруженных фото.* Сначала отправьте фото, потом напишите *стоп*.", $apiUrl);
                        $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                        exit;
                    }
                    sendSimpleMsg($chatId, "⏳ _Обрабатываю " . count($pages) . " страниц. Загружаю на ImgBB (может занять пару минут)..._", $apiUrl);
                    $imgbbUrls = [];
                    foreach ($pages as $fileId) {
                        $tgUrl = getTelegramImageUrl($token, $fileId);
                        if ($tgUrl) {
                            $temp = downloadFile($tgUrl);
                            if ($temp) {
                                $upl = uploadToImgbb($temp, $imgbbKey);
                                @unlink($temp);
                                if ($upl) $imgbbUrls[] = $upl;
                            }
                        }
                    }
                    if (empty($imgbbUrls)) {
                        sendSimpleMsg($chatId, "❌ *Не удалось загрузить фото на сервер.*", $apiUrl);
                        $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                        exit;
                    }
                    $pdo->prepare("UPDATE temp_data SET pages = ?, step = 'wait_title' WHERE user_id = ?")->execute([json_encode($imgbbUrls), $chatId]);
                    sendSimpleMsg($chatId, "✅ Загружено " . count($imgbbUrls) . " фото!\n\n*Шаг 2 из 4:* Введите *название* манги:", $apiUrl);
                    exit;
                }
                // Если пришло что-то другое (не фото и не стоп) — подсказываем
                sendSimpleMsg($chatId, "📷 Отправляйте фото страниц. Когда всё загрузите — напишите *стоп*.", $apiUrl);
                exit;
            }

            if ($userState['step'] == 'wait_title') {
                if (!empty(trim($text))) {
                    $pdo->prepare("UPDATE temp_data SET title = ?, step = 'wait_desc' WHERE user_id = ?")->execute([trim($text), $chatId]);
                    sendSimpleMsg($chatId, "📝 *Шаг 3 из 4:* Введите *описание* манги:", $apiUrl);
                }
                exit;
            }

            if ($userState['step'] == 'wait_desc') {
                if (!empty(trim($text))) {
                    $pdo->prepare("UPDATE temp_data SET description = ?, step = 'wait_cover' WHERE user_id = ?")->execute([trim($text), $chatId]);
                    sendSimpleMsg($chatId, "🖼 *Шаг 4 из 4:* Отправьте фото, которое станет *обложкой*:", $apiUrl);
                }
                exit;
            }

            if ($userState['step'] == 'wait_cover') {
                if (isset($message['photo'])) {
                    $photo = end($message['photo']);
                    $fileId = $photo['file_id'];
                    $pageUrls = json_decode($userState['pages'], true);
                    sendSimpleMsg($chatId, "⏳ _Генерирую Telegraph-страницу..._", $apiUrl);
                    $coverUrl = getTelegramImageUrl($token, $fileId);
                    $coverImgbbUrl = null;
                    if ($coverUrl) {
                        $tempFile = downloadFile($coverUrl);
                        if ($tempFile) {
                            $coverImgbbUrl = uploadToImgbb($tempFile, $imgbbKey);
                            @unlink($tempFile);
                        }
                    }
                    $telegraphLink = createTelegraphPage($userState['title'], $pageUrls, $token, $pdo, $imgbbKey);
                    if ($telegraphLink) {
                        $titleWithHeart = '❤️ ' . $userState['title'];
                        $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, cover_imgbb_url, added_by) VALUES (?, ?, ?, ?, ?)")->execute([$titleWithHeart, $telegraphLink, $userState['description'], $coverImgbbUrl, $chatId]);
                        $newMangaId = $pdo->lastInsertId();
                        saveMangaPages($pdo, $newMangaId, $pageUrls);
                        logArchive($pdo, 'add_manga', "Опубликована манга: {$userState['title']}", $chatId);
                        sendSimpleMsg($chatId, "🚀 *Успех!* Манга добавлена в каталог.\n\n🔗 Telegra.ph: $telegraphLink\n🌐 Сайт: {$siteUrl}/read/{$newMangaId}", $apiUrl, $adminKeyboard);
                    } else {
                        sendSimpleMsg($chatId, "❌ *Ошибка при создании страницы Telegraph.*\n\nПроверьте access_token в коде бота.", $apiUrl, $adminKeyboard);
                    }
                    $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                } else {
                    sendSimpleMsg($chatId, "🖼 Пожалуйста, отправьте *фото* обложки:", $apiUrl);
                }
                exit;
            }

            // --- ZIP шаги ---
            if ($userState['step'] == 'wait_title_zip') {
                if (!empty(trim($text))) {
                    $pdo->prepare("UPDATE temp_data SET title = ?, step = 'wait_desc_zip' WHERE user_id = ?")->execute([trim($text), $chatId]);
                    sendSimpleMsg($chatId, "📝 *Шаг 3 из 4:* Введите *описание* манги:", $apiUrl);
                }
                exit;
            }

            if ($userState['step'] == 'wait_desc_zip') {
                if (!empty(trim($text))) {
                    $pdo->prepare("UPDATE temp_data SET description = ?, step = 'wait_cover_zip' WHERE user_id = ?")->execute([trim($text), $chatId]);
                    sendSimpleMsg($chatId, "🖼 *Шаг 4 из 4:* Отправьте фото, которое станет *обложкой*:", $apiUrl);
                }
                exit;
            }

            if ($userState['step'] == 'wait_cover_zip') {
                if (isset($message['photo'])) {
                    $photo = end($message['photo']);
                    $fileId = $photo['file_id'];
                    $stateData = json_decode($userState['pages'], true);
                    $imgUrls = $stateData['imgbb_urls'] ?? [];
                    sendSimpleMsg($chatId, "⏳ _Генерирую Telegraph-страницу..._", $apiUrl);
                    $coverUrl = getTelegramImageUrl($token, $fileId);
                    $coverImgbbUrl = null;
                    if ($coverUrl) {
                        $tempFile = downloadFile($coverUrl);
                        if ($tempFile) {
                            $coverImgbbUrl = uploadToImgbb($tempFile, $imgbbKey);
                            @unlink($tempFile);
                        }
                    }
                    $telegraphLink = createTelegraphPage($userState['title'], $imgUrls, $token, $pdo, $imgbbKey);
                    if ($telegraphLink) {
                        $titleWithHeart = '❤️ ' . $userState['title'];
                        $pdo->prepare("INSERT INTO manga (title, telegraph_url, description, cover_imgbb_url, added_by) VALUES (?, ?, ?, ?, ?)")->execute([$titleWithHeart, $telegraphLink, $userState['description'], $coverImgbbUrl, $chatId]);
                        $newMangaId = $pdo->lastInsertId();
                        saveMangaPages($pdo, $newMangaId, $imgUrls);
                        logArchive($pdo, 'add_manga', "Опубликована манга (ZIP): {$userState['title']}", $chatId);
                        sendSimpleMsg($chatId, "🚀 *Успех!* Манга добавлена в каталог.\n\n🔗 Telegra.ph: $telegraphLink\n🌐 Сайт: {$siteUrl}/read/{$newMangaId}", $apiUrl, $adminKeyboard);
                    } else {
                        sendSimpleMsg($chatId, "❌ *Ошибка Telegraph.*\n\nПроверьте access_token в коде бота.", $apiUrl, $adminKeyboard);
                    }
                    $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                } else {
                    sendSimpleMsg($chatId, "🖼 Пожалуйста, отправьте *фото* обложки:", $apiUrl);
                }
                exit;
            }
        }

        // --- Удаление по команде -название ---
        if (strpos(trim($text), '-') === 0) {
            $search = trim(ltrim(trim($text), '- '));
            $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? LIMIT 5");
            $stmt->execute(["%$search%"]);
            $results = $stmt->fetchAll();
            if ($results) {
                $btns = [];
                foreach ($results as $r) $btns[] = [['text' => '🗑 Удалить: ' . $r['title'], 'callback_data' => 'delete_confirm_' . $r['id']]];
                sendSimpleMsg($chatId, "🔎 *Найдено в базе.* Выберите для удаления:", $apiUrl, json_encode(['inline_keyboard' => $btns]));
            } else {
                sendSimpleMsg($chatId, "❌ Ничего не найдено по запросу *«$search»*", $apiUrl);
            }
            exit;
        }
    }

    // --- Обычный пользователь ---
    if ($userState && $userState['step'] == 'wait_search') {
        $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
        $query = trim($text);
        $c = getSearchData($pdo, $query, 0);
        sendSimpleMsg($chatId, $c['text'], $apiUrl, $c['reply_markup']);
        exit;
    }

    switch ($text) {
        case "/start":
        case "🔙 Выйти в режим читателя":
            sendSimpleMsg($chatId, "👋 *Добро пожаловать!*\n\n_Выберите действие:_", $apiUrl, getMainMenu($chatId, $admins));
            break;
        case "🌐 Сайт каталога":
            sendSimpleMsg($chatId, "🌐 *Открыть каталог на сайте:*\n\n{$siteUrl}", $apiUrl);
            break;
        case "⚙️ АДМИН-ПАНЕЛЬ":
            if (in_array($chatId, $admins)) sendSimpleMsg($chatId, "🛡 *Панель управления*", $apiUrl, $adminKeyboard);
            break;
        case "📊 Статистика админов":
            $stmt = $pdo->query("SELECT added_by, COUNT(*) as cnt FROM manga GROUP BY added_by ORDER BY cnt DESC");
            $report = "🏆 *Рейтинг:*\n\n";
            $place = 1;
            while ($row = $stmt->fetch()) {
                $tagName = getAdminTag($pdo, $row['added_by']);
                $report .= "$place. *$tagName* — _{$row['cnt']} шт_\n";
                $place++;
            }
            sendSimpleMsg($chatId, $report, $apiUrl);
            break;
        case "📥 Читать предложку":
            $stmt = $pdo->query("SELECT id, text FROM suggestions WHERE status = 'new' ORDER BY id DESC LIMIT 10");
            $rows = $stmt->fetchAll();
            if (empty($rows)) sendSimpleMsg($chatId, "📭 *Пусто.*", $apiUrl);
            else {
                $btns = [];
                foreach ($rows as $row) $btns[] = [['text' => "✉️ " . mb_substr($row['text'], 0, 30) . '...', 'callback_data' => "view_suggest_" . $row['id']]];
                sendSimpleMsg($chatId, "📋 *Новые предложения:*", $apiUrl, json_encode(['inline_keyboard' => $btns]));
            }
            break;
        case "🗂 Архив бота":
            if (in_array($chatId, $admins)) {
                $archiveData = getArchiveData($pdo, $admins, 0);
                sendSimpleMsg($chatId, $archiveData['text'], $apiUrl, $archiveData['reply_markup']);
            }
            break;
        case "❓ FAQ и Команды":
            if (in_array($chatId, $admins)) sendSimpleMsg($chatId, "📖 *ИНСТРУКЦИЯ*\n\n`+Название | ссылка | описание` — добавление\n`+Название` (с фото) — обложка\n\n*Загрузка манги:*\n1. Нажмите ➕ Добавить через альбом\n2. Отправьте все страницы фото\n3. Напишите *стоп*\n4. Введите название, описание, обложку", $apiUrl);
            break;
        case "🔍 Найти мангу":
            $c = getSearchData($pdo, '', 0);
            $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, 'wait_search', '[]') ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId]);
            sendSimpleMsg($chatId, "📚 *Весь каталог:*", $apiUrl, $c['reply_markup']);
            break;
        case "📚 Моя библиотека":
            sendLibrary($chatId, $pdo, $apiUrl);
            break;
        case "🔥 Топ по лайкам":
            $stmt = $pdo->query("SELECT title, likes FROM manga WHERE likes > 0 ORDER BY likes DESC LIMIT 10");
            $res = $stmt->fetchAll();
            $msg = "🔥 *Популярное:*\n\n";
            if ($res) foreach ($res as $k => $v) $msg .= ($k + 1) . ". *{$v['title']}* — 👍 _{$v['likes']}_\n";
            else $msg .= "_Пусто._";
            sendSimpleMsg($chatId, $msg, $apiUrl);
            break;
        case "🎲 Случайная манга":
            $stmt = $pdo->prepare("SELECT m.* FROM manga m LEFT JOIN user_manga_status s ON m.id = s.manga_id AND s.user_id = ? WHERE s.status IS NULL OR s.status != 'read' ORDER BY RANDOM() LIMIT 1");
            $stmt->execute([$chatId]);
            $random = $stmt->fetch();
            if ($random) sendMangaCard($chatId, $random, $apiUrl, $siteUrl);
            else sendSimpleMsg($chatId, "😮 Вы всё прочитали!", $apiUrl);
            break;
        case "💡 Предложить мангу":
            $pdo->prepare("INSERT INTO temp_data (user_id, step, pages) VALUES (?, 'wait_suggest', '[]') ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, pages = EXCLUDED.pages")->execute([$chatId]);
            sendSimpleMsg($chatId, "💡 *Напишите название манги, которую хотите предложить:*", $apiUrl);
            break;
        default:
            if ($userState && $userState['step'] == 'wait_suggest' && !empty($text)) {
                $pdo->prepare("INSERT INTO suggestions (user_id, text) VALUES (?, ?)")->execute([$chatId, $text]);
                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                sendSimpleMsg($chatId, "🙏 *Спасибо!* Предложение передано администраторам.", $apiUrl);
            }
            break;
    }
}


// =============================================
// ФУНКЦИИ
// =============================================

function getArchiveData($pdo, $admins, $page) {
    $limit = 10; $offset = $page * $limit;
    $total = $pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();
    $stmt = $pdo->prepare("SELECT *, to_char(created_at, 'DD.MM.YYYY HH24:MI') as formatted_time FROM bot_archive ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (empty($rows)) return ['text' => "🗂 *Архив пуст*", 'reply_markup' => json_encode(['inline_keyboard' => []])];
    $text = "🗂 *АРХИВ*\n\n";
    foreach ($rows as $row) {
        $time = $row['formatted_time'] ?? $row['created_at'];
        $text .= "🕐 $time\n💬 {$row['action_text']}\n─────────────────────\n";
    }
    $nav = [];
    if ($page > 0) $nav[] = ['text' => '⬅️ Назад', 'callback_data' => 'archive_page_' . ($page - 1)];
    $totalPages = ceil($total / $limit);
    if (($offset + $limit) < $total) $nav[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'archive_page_' . ($page + 1)];
    return ['text' => $text, 'reply_markup' => json_encode(['inline_keyboard' => [ $nav ]])];
}

function getSearchData($pdo, $query, $page) {
    $limit = 5; $offset = $page * $limit; $q = trim($query);
    if ($q === '') {
        $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, title FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
    } else {
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title LIKE ?");
        $totalStmt->execute(["%$q%"]);
        $total = $totalStmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$q%"]);
    }
    $list = $stmt->fetchAll();
    if (empty($list)) return ['text' => "😔 *Ничего не найдено.*", 'reply_markup' => json_encode(['inline_keyboard' => []])];
    $btns = [];
    foreach ($list as $m) $btns[] = [['text' => "📘 " . $m['title'], 'callback_data' => 'show_' . $m['id']]];
    $nav = [];
    if ($page > 0) $nav[] = ['text' => '⬅️ Назад', 'callback_data' => 'search_page_' . urlencode($q) . '_' . ($page - 1)];
    if (($offset + $limit) < $total) $nav[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'search_page_' . urlencode($q) . '_' . ($page + 1)];
    if (!empty($nav)) $btns[] = $nav;
    return ['text' => "_Выберите произведение:_", 'reply_markup' => json_encode(['inline_keyboard' => $btns])];
}

function getEditSearchData($pdo, $query, $page) {
    $limit = 5; $offset = $page * $limit; $q = trim($query);
    if ($q === '') {
        $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, title FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
    } else {
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title LIKE ?");
        $totalStmt->execute(["%$q%"]);
        $total = $totalStmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$q%"]);
    }
    $list = $stmt->fetchAll();
    if (empty($list)) return ['text' => "😔 *Ничего не найдено.*", 'reply_markup' => json_encode(['inline_keyboard' => []])];
    $btns = [];
    foreach ($list as $m) $btns[] = [['text' => "✏️ " . $m['title'], 'callback_data' => 'edit_manga_' . $m['id']]];
    $nav = [];
    if ($page > 0) $nav[] = ['text' => '⬅️ Назад', 'callback_data' => 'edit_page_' . urlencode($q) . '_' . ($page - 1)];
    if (($offset + $limit) < $total) $nav[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'edit_page_' . urlencode($q) . '_' . ($page + 1)];
    if (!empty($nav)) $btns[] = $nav;
    return ['text' => "_Выберите мангу для редактирования:_", 'reply_markup' => json_encode(['inline_keyboard' => $btns])];
}

function sendEditMangaMenu($chatId, $m, $apiUrl) {
    $mId = $m['id'];
    $desc = mb_substr(strip_tags($m['description'] ?? ''), 0, 80);
    $text = "✏️ *Редактирование манги*\n\n📖 *Название:* {$m['title']}\n📝 *Описание:* _{$desc}..._";
    $kb = ['inline_keyboard' => [
        [['text' => '🖼 Изменить обложку', 'callback_data' => 'editfield_cover_' . $mId], ['text' => '📖 Изменить название', 'callback_data' => 'editfield_title_' . $mId]],
        [['text' => '📝 Изменить описание', 'callback_data' => 'editfield_desc_' . $mId], ['text' => '🔗 Изменить ссылку', 'callback_data' => 'editfield_link_' . $mId]],
        [['text' => '🗑 Удалить мангу', 'callback_data' => 'delete_confirm_' . $mId]]
    ]];
    if (!empty($m['cover_imgbb_url'])) {
        tgPost($apiUrl . "/sendPhoto", ['chat_id' => $chatId, 'photo' => $m['cover_imgbb_url'], 'caption' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
    } else {
        tgPost($apiUrl . "/sendMessage", ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
    }
}

function sendMangaCard($chatId, $m, $apiUrl, $siteUrl = '') {
    if (!$m) return;
    $text = "📖 *" . $m['title'] . "*\n\n" . $m['description'] . "\n\n━━━━━━━━━━━━━━━━━\n👍 _{$m['likes']} лайков_  |  👎 _{$m['dislikes']} дизлайков_";
    $readButtons = [['text' => '📖 Читать (Telegra.ph)', 'url' => $m['telegraph_url']]];
    if ($siteUrl) $readButtons[] = ['text' => '🌐 Читать на сайте', 'url' => $siteUrl . '/read/' . $m['id']];
    $kb = ['inline_keyboard' => [
        $readButtons,
        [['text' => '⏳ Читаю сейчас', 'callback_data' => 'stat_now_' . $m['id']], ['text' => '✅ Прочитано', 'callback_data' => 'stat_read_' . $m['id']]],
        [['text' => '👍 Лайк', 'callback_data' => 'vote_like_' . $m['id']], ['text' => '👎 Дизлайк', 'callback_data' => 'vote_dislike_' . $m['id']]]
    ]];
    if (!empty($m['cover_imgbb_url'])) {
        tgPost($apiUrl . "/sendPhoto", ['chat_id' => $chatId, 'photo' => $m['cover_imgbb_url'], 'caption' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
    } else {
        tgPost($apiUrl . "/sendMessage", ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
    }
}

function updateMangaMessage($chatId, $msgId, $m, $apiUrl) {
    $text = "📖 *" . $m['title'] . "*\n\n" . $m['description'] . "\n\n━━━━━━━━━━━━━━━━━\n👍 _{$m['likes']} лайков_  |  👎 _{$m['dislikes']} дизлайков_";
    $kb = ['inline_keyboard' => [
        [['text' => '📖 Читать (Telegra.ph)', 'url' => $m['telegraph_url']]],
        [['text' => '⏳ Читаю сейчас', 'callback_data' => 'stat_now_' . $m['id']], ['text' => '✅ Прочитано', 'callback_data' => 'stat_read_' . $m['id']]],
        [['text' => '👍 Лайк', 'callback_data' => 'vote_like_' . $m['id']], ['text' => '👎 Дизлайк', 'callback_data' => 'vote_dislike_' . $m['id']]]
    ]];
    $method = !empty($m['cover_imgbb_url']) ? "editMessageCaption" : "editMessageText";
    $param = !empty($m['cover_imgbb_url']) ? "caption" : "text";
    tgPost($apiUrl . "/$method", ['chat_id' => $chatId, 'message_id' => $msgId, $param => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
}

function sendLibrary($chatId, $pdo, $apiUrl) {
    $stmt = $pdo->prepare("SELECT m.title, s.status FROM user_manga_status s JOIN manga m ON s.manga_id = m.id WHERE s.user_id = ?");
    $stmt->execute([$chatId]);
    $res = $stmt->fetchAll();
    $now = []; $read = [];
    foreach ($res as $i) {
        if ($i['status'] == 'now') $now[] = "🔹 " . $i['title'];
        else $read[] = "✅ " . $i['title'];
    }
    $msg = "📚 *ТВОЯ ЛИЧНАЯ БИБЛИОТЕКА*\n━━━━━━━━━━━━━━━━━━━━━\n\n⏳ *Сейчас читаю:*\n" . ($now ? implode("\n", $now) : "_Пока ничего_") . "\n\n🏆 *Прочитано:*\n" . ($read ? implode("\n", $read) : "_Список пуст_");
    sendSimpleMsg($chatId, $msg, $apiUrl);
}

function sendSimpleMsg($chatId, $text, $apiUrl, $kb = null) {
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($kb) $data['reply_markup'] = is_string($kb) ? json_decode($kb, true) : $kb;
    return tgPost($apiUrl . "/sendMessage", $data);
}

// КОНЕЦ ФАЙЛА