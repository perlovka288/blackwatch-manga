<?php
/**
 * mangadex.php вЂ” MangaDex РёРЅС‚РµРіСЂР°С†РёСЏ (РўР•РЎРў)
 * РџСЂРѕРєСЃРёСЂСѓРµС‚ Р·Р°РїСЂРѕСЃС‹ Рє MangaDex API Рё СЃРєР°С‡РёРІР°РµС‚ СЃС‚СЂР°РЅРёС†С‹ РЅР° СЃРµСЂРІРµСЂРµ,
 * Р·Р°С‚РµРј Р·Р°РіСЂСѓР¶Р°РµС‚ РёС… РЅР° ImgBB Рё СЃРѕС…СЂР°РЅСЏРµС‚ РєР°Рє РѕР±С‹С‡РЅСѓСЋ РјР°РЅРіСѓ/РіР»Р°РІСѓ.
 *
 * Endpoints:
 *   GET  /api/mdex/search?q=...&lang=ru         вЂ” РїРѕРёСЃРє РјР°РЅРіРё
 *   GET  /api/mdex/chapters?manga_id=...&lang=ru вЂ” СЃРїРёСЃРѕРє РіР»Р°РІ
 *   POST /api/mdex/import                        вЂ” СЃРєР°С‡Р°С‚СЊ РіР»Р°РІСѓ Рё РґРѕР±Р°РІРёС‚СЊ РІ Р‘Р”
 *
 * РњРѕР¶РµС‚ Р±С‹С‚СЊ РїРѕРґРєР»СЋС‡С‘РЅ С‡РµСЂРµР· require РёР· index.php (С‚РѕРіРґР° $pdo, $hardcodedAdmins, $imgbbKeys
 * СѓР¶Рµ РѕРїСЂРµРґРµР»РµРЅС‹) РёР»Рё Р·Р°РїСѓС‰РµРЅ РЅР°РїСЂСЏРјСѓСЋ (С‚РѕРіРґР° РёРЅРёС†РёР°Р»РёР·РёСЂСѓРµС‚ РІСЃС‘ СЃР°Рј).
 */

// Р•СЃР»Рё РІС‹Р·РІР°РЅ РЅР°РїСЂСЏРјСѓСЋ (РЅРµ С‡РµСЂРµР· require РёР· index.php) вЂ” РёРЅРёС†РёР°Р»РёР·РёСЂСѓРµРј СЃР°РјРё
if (!isset($pdo)) {
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

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
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
        exit;
    }
}

if (!isset($hardcodedAdmins)) {
    $hardcodedAdmins = [1710365896, 1181510470];
    try {
        foreach ($pdo->query("SELECT user_id FROM bot_admins") as $row) {
            if (!in_array((int)$row['user_id'], $hardcodedAdmins)) {
                $hardcodedAdmins[] = (int)$row['user_id'];
            }
        }
    } catch (Exception $e) {}
}

if (!isset($imgbbKeys)) {
    $imgbbKeys = [
        '58ff4596fd55028a81cbf8c4e38388e1',
        '6981ba08e7b2a8743aab2c8ea008f675',
        'f9b8d27fa4029816d643c7814fd60c60',
        '24dbed2ae9fea9369de6a7b68d0c3ee6',
        'c3e6a55335c71a052c1a59b6a2d6d150',
    ];
}

function getEffectiveUserId($pdo) {
    $tgUser = $_GET['tg_user_id'] ?? $_POST['tg_user_id'] ?? '';
    if ($tgUser && is_numeric($tgUser)) {
        if (!headers_sent()) setcookie('tg_user_id', $tgUser, time()+86400*30, '/', '', false, false);
        $_SESSION['tg_user_id'] = $tgUser;
        return (int)$tgUser;
    }
    if (!empty($_SESSION['tg_user_id']) && is_numeric($_SESSION['tg_user_id'])) return (int)$_SESSION['tg_user_id'];
    if (!empty($_COOKIE['tg_user_id'])  && is_numeric($_COOKIE['tg_user_id']))  {
        $_SESSION['tg_user_id'] = $_COOKIE['tg_user_id'];
        return (int)$_COOKIE['tg_user_id'];
    }
    return (int)$_SESSION['guest_id'];
}

// ==============================
// Helper: MangaDex HTTP GET
// ==============================
function mdexGet($url, $params = []) {
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'BLACKWATCH-MangaReader/1.0 (contact@example.com)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    return json_decode($body, true);
}

// ==============================
// Helper: download binary (image)
// ==============================
function downloadBinary($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'BLACKWATCH-MangaReader/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $data) ? $data : null;
}

// ==============================
// Helper: upload blob to ImgBB
// ==============================
function uploadBlobToImgbb($binaryData, $keys, $retries = 2) {
    $b64 = base64_encode($binaryData);
    foreach ($keys as $key) {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $ch = curl_init('https://api.imgbb.com/1/upload');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['key' => $key, 'image' => $b64],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (!empty($data['data']['url'])) return $data['data']['url'];
            }
            if ($httpCode === 400 || $httpCode === 429) break;
            sleep(1);
        }
    }
    return null;
}

// ==============================
// Helper: Telegraph page
// ==============================
if (!function_exists('createTelegraphPage')) {
function createTelegraphPage($title, $imageUrls) {
    $nodes = [];
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
    $ch = curl_init('https://api.telegra.ph/createPage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($raw, true);
    if (!isset($res['ok']) || !$res['ok']) return false;
    return $res['result']['url'] ?? false;
}

if (!function_exists('logArchiveEntry')) {
function logArchiveEntry($pdo, $action_type, $action_text, $userId) {
    try {
        $pdo->prepare("INSERT INTO bot_archive (action_type,action_text,action_by) VALUES (?,?,?)")
            ->execute([$action_type, $action_text, $userId]);
    } catch (Exception $e) {}
}

// ==============================
// ROUTING
// ==============================
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// в”Ђв”Ђ Search manga on MangaDex в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
if ($path === '/api/mdex/search') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) {
        echo json_encode(['success' => false, 'error' => 'РќРµС‚ РїСЂР°РІ']); exit;
    }
    $q    = trim($_GET['q'] ?? '');
    $lang = trim($_GET['lang'] ?? 'ru');
    if (!$q) { echo json_encode(['results' => []]); exit; }

    $data = mdexGet('https://api.mangadex.org/manga', [
        'title'                      => $q,
        'limit'                      => 20,
        'availableTranslatedLanguage[]' => $lang,
        'includes[]'                 => 'cover_art',
        'contentRating[]'            => ['safe', 'suggestive', 'erotica', 'pornographic'],
        'order[relevance]'           => 'desc',
    ]);

    if (!$data || empty($data['data'])) {
        echo json_encode(['results' => []]); exit;
    }

    $results = [];
    foreach ($data['data'] as $manga) {
        $attr    = $manga['attributes'];
        $title   = $attr['title']['en']
            ?? $attr['title']['ru']
            ?? $attr['title']['ja-ro']
            ?? $attr['title']['ja']
            ?? (is_array($attr['title']) ? reset($attr['title']) : '')
            ?? '';

        // Cover filename
        $coverUrl = null;
        foreach ($manga['relationships'] as $rel) {
            if ($rel['type'] === 'cover_art' && !empty($rel['attributes']['fileName'])) {
                $coverUrl = "https://uploads.mangadex.org/covers/{$manga['id']}/{$rel['attributes']['fileName']}.256.jpg";
                break;
            }
        }

        $results[] = [
            'id'        => $manga['id'],
            'title'     => $title,
            'cover_url' => $coverUrl,
            'status'    => $attr['status'] ?? '',
            'year'      => $attr['year'] ?? null,
        ];
    }
    echo json_encode(['results' => $results]);
    exit;
}

// в”Ђв”Ђ Get chapters of a MangaDex manga в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
if ($path === '/api/mdex/chapters') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) {
        echo json_encode(['success' => false, 'error' => 'РќРµС‚ РїСЂР°РІ']); exit;
    }
    $mangaId = trim($_GET['manga_id'] ?? '');
    $lang    = trim($_GET['lang'] ?? 'ru');
    if (!$mangaId) { echo json_encode(['chapters' => []]); exit; }

    $allChapters = [];
    $offset      = 0;
    $limit       = 100;
    do {
        $data = mdexGet("https://api.mangadex.org/manga/{$mangaId}/feed", [
            'translatedLanguage[]' => $lang,
            'limit'                => $limit,
            'offset'               => $offset,
            'order[chapter]'       => 'asc',
            'contentRating[]'      => ['safe', 'suggestive', 'erotica', 'pornographic'],
        ]);
        if (!$data || empty($data['data'])) break;
        foreach ($data['data'] as $ch) {
            $attr = $ch['attributes'];
            $allChapters[] = [
                'id'      => $ch['id'],
                'chapter' => $attr['chapter'] ?? '0',
                'title'   => $attr['title'] ?? '',
                'pages'   => $attr['pages'] ?? 0,
                'lang'    => $attr['translatedLanguage'] ?? $lang,
            ];
        }
        $offset += $limit;
        $total = $data['total'] ?? 0;
    } while ($offset < $total);

    // РЈР±РёСЂР°РµРј РґСѓР±Р»Рё РїРѕ РЅРѕРјРµСЂСѓ РіР»Р°РІС‹ (Р±РµСЂС‘Рј РїРµСЂРІС‹Р№)
    $seen = [];
    $unique = [];
    foreach ($allChapters as $ch) {
        $key = $ch['chapter'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $ch;
        }
    }

    echo json_encode(['chapters' => $unique]);
    exit;
}

// в”Ђв”Ђ Import: download pages and save to DB в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
if ($path === '/api/mdex/import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Increase limits for long downloads
    set_time_limit(600);
    ini_set('memory_limit', '512M');

    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) {
        echo json_encode(['success' => false, 'error' => 'РќРµС‚ РїСЂР°РІ']); exit;
    }

    $input      = json_decode(file_get_contents('php://input'), true);
    $action     = $input['action'] ?? '';       // 'new_manga' | 'add_chapter' | 'add_to_existing'
    $mdexMangaId  = trim($input['mdex_manga_id'] ?? '');
    $mdexChapterId = trim($input['mdex_chapter_id'] ?? '');
    $chapterNum  = (float)($input['chapter_num'] ?? 0);
    $mangaTitle  = trim($input['manga_title'] ?? '');
    $coverUrl    = trim($input['cover_url'] ?? '');   // MangaDex cover URL (already web URL)
    $existingMangaId = (int)($input['existing_manga_id'] ?? 0);
    $lang        = trim($input['lang'] ?? 'ru');

    if (!$mdexChapterId) {
        echo json_encode(['success' => false, 'error' => 'РќРµ СѓРєР°Р·Р°РЅ chapter_id']); exit;
    }

    // 1. Get at-home server info
    $atHome = mdexGet("https://api.mangadex.org/at-home/server/{$mdexChapterId}");
    if (!$atHome || empty($atHome['baseUrl'])) {
        echo json_encode(['success' => false, 'error' => 'РќРµ СѓРґР°Р»РѕСЃСЊ РїРѕР»СѓС‡РёС‚СЊ at-home СЃРµСЂРІРµСЂ']); exit;
    }

    $baseUrl  = rtrim($atHome['baseUrl'], '/');
    $hash     = $atHome['chapter']['hash'];
    $pages    = $atHome['chapter']['data'];        // original quality
    // $pages = $atHome['chapter']['dataSaver']; // compressed

    if (empty($pages)) {
        echo json_encode(['success' => false, 'error' => 'Р“Р»Р°РІР° РїСѓСЃС‚Р°СЏ (РЅРµС‚ СЃС‚СЂР°РЅРёС†)']); exit;
    }

    // Sort pages by filename (they come ordered, but just in case)
    sort($pages);

    // 2. Download + upload to ImgBB (СЃС‚СЂР°РЅРёС†Р° Р·Р° СЃС‚СЂР°РЅРёС†РµР№)
    $uploadedUrls = [];
    $errors       = 0;
    foreach ($pages as $i => $filename) {
        $pageUrl  = "{$baseUrl}/data/{$hash}/{$filename}";
        $binary   = downloadBinary($pageUrl);
        if (!$binary) {
            $errors++;
            continue;
        }
        $imgUrl = uploadBlobToImgbb($binary, $imgbbKeys);
        if ($imgUrl) {
            $uploadedUrls[] = $imgUrl;
        } else {
            $errors++;
        }
        // Small delay to be polite to MangaDex
        usleep(150000); // 150ms
    }

    if (empty($uploadedUrls)) {
        echo json_encode(['success' => false, 'error' => 'РќРµ СѓРґР°Р»РѕСЃСЊ Р·Р°РіСЂСѓР·РёС‚СЊ РЅРё РѕРґРЅРѕР№ СЃС‚СЂР°РЅРёС†С‹']); exit;
    }

    // 3. Upload cover to ImgBB if provided
    $savedCoverUrl = null;
    if ($coverUrl) {
        $binary = downloadBinary($coverUrl);
        if ($binary) $savedCoverUrl = uploadBlobToImgbb($binary, $imgbbKeys);
        if (!$savedCoverUrl) $savedCoverUrl = null; // fallback: no cover
    }

    // 4. Determine manga_id and action
    $mangaId = 0;

    if ($action === 'new_manga') {
        // Create new manga entry
        if (!$mangaTitle) $mangaTitle = 'РњР°РЅРіР° СЃ MangaDex';
        $telegraphLink = null;
        // For single chapter treated as standalone manga
        $isSeries = true; // always series when imported from mdex
        $insert = $pdo->prepare(
            "INSERT INTO manga (title,description,cover_imgbb_url,added_by,is_series) VALUES (?,?,?,?,?) RETURNING id"
        );
        $insert->execute(['в™Ґ ' . $mangaTitle, "РРјРїРѕСЂС‚РёСЂРѕРІР°РЅРѕ СЃ MangaDex (ID: {$mdexMangaId})", $savedCoverUrl, $userId, true]);
        $row = $insert->fetch();
        $mangaId = (int)($row['id'] ?? 0);
        if (!$mangaId) $mangaId = (int)$pdo->lastInsertId();
        logArchiveEntry($pdo, 'add_manga', "РРјРїРѕСЂС‚ СЃ MangaDex: в™Ґ {$mangaTitle}", $userId);

    } elseif ($action === 'add_to_existing') {
        $mangaId = $existingMangaId;
        if (!$mangaId) {
            echo json_encode(['success' => false, 'error' => 'РќРµ СѓРєР°Р·Р°РЅ existing_manga_id']); exit;
        }
        // Ensure it's marked as series
        $pdo->prepare("UPDATE manga SET is_series=TRUE WHERE id=?")->execute([$mangaId]);
    } else {
        echo json_encode(['success' => false, 'error' => 'РќРµРёР·РІРµСЃС‚РЅС‹Р№ action']); exit;
    }

    if (!$mangaId) {
        echo json_encode(['success' => false, 'error' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕР·РґР°С‚СЊ/РЅР°Р№С‚Рё РјР°РЅРіСѓ']); exit;
    }

    // 5. Create Telegraph page
    $mangaRow = $pdo->prepare("SELECT title FROM manga WHERE id=?");
    $mangaRow->execute([$mangaId]);
    $manga = $mangaRow->fetch();
    $chLabel = "Р“Р»Р°РІР° {$chapterNum}";
    $telegraphLink = createTelegraphPage(($manga['title'] ?? $mangaTitle) . ' вЂ” ' . $chLabel, $uploadedUrls);

    // 6. Save chapter
    $chInsert = $pdo->prepare(
        "INSERT INTO manga_chapters (manga_id,chapter_num,title,telegraph_url) VALUES (?,?,?,?) RETURNING id"
    );
    $chInsert->execute([$mangaId, $chapterNum ?: 1, null, $telegraphLink]);
    $chRow = $chInsert->fetch();
    $chapterId = (int)($chRow['id'] ?? 0);

    // 7. Save chapter pages
    if ($chapterId && !empty($uploadedUrls)) {
        $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id=?")->execute([$chapterId]);
        $pageStmt = $pdo->prepare("INSERT INTO manga_chapter_pages (chapter_id,page_url,page_order) VALUES (?,?,?)");
        foreach ($uploadedUrls as $i => $url) {
            $pageStmt->execute([$chapterId, $url, $i]);
        }
    }

    logArchiveEntry($pdo, 'add_chapter', "РРјРїРѕСЂС‚ СЃ MangaDex: Р“Р».{$chapterNum} РґР»СЏ РјР°РЅРіРё ID:{$mangaId}", $userId);

    $siteUrl = rtrim(getenv('SITE_URL') ?: '', '/');
    echo json_encode([
        'success'       => true,
        'manga_id'      => $mangaId,
        'chapter_id'    => $chapterId,
        'pages_total'   => count($pages),
        'pages_ok'      => count($uploadedUrls),
        'pages_errors'  => $errors,
        'telegraph'     => $telegraphLink,
        'site_url'      => "{$siteUrl}/read/{$mangaId}",
    ]);
    exit;
}

// Fallback
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found']);