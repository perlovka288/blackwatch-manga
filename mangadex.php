<?php
/**
 * mangadex.php — MangaDex интеграция (ТЕСТ)
 * Подключается через require из index.php.
 */

if (!isset($pdo)) {
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME'));
    try {
        $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'DB Error: '.$e->getMessage()]); exit; }
}

if (!isset($hardcodedAdmins)) {
    $hardcodedAdmins = [1710365896, 1181510470];
    try { foreach ($pdo->query("SELECT user_id FROM bot_admins") as $row) { if (!in_array((int)$row['user_id'], $hardcodedAdmins)) $hardcodedAdmins[] = (int)$row['user_id']; } } catch (Exception $e) {}
}

if (!isset($imgbbKeys)) {
    $imgbbKeys = ['58ff4596fd55028a81cbf8c4e38388e1','6981ba08e7b2a8743aab2c8ea008f675','f9b8d27fa4029816d643c7814fd60c60','24dbed2ae9fea9369de6a7b68d0c3ee6','c3e6a55335c71a052c1a59b6a2d6d150'];
}

if (!function_exists('getEffectiveUserId')) {
    if (!session_id()) session_start();
    if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);
    function getEffectiveUserId($pdo) {
        $tgUser = $_GET['tg_user_id'] ?? $_POST['tg_user_id'] ?? '';
        if ($tgUser && is_numeric($tgUser)) { if (!headers_sent()) setcookie('tg_user_id', $tgUser, time()+86400*30,'/',''  ,false,false); $_SESSION['tg_user_id']=$tgUser; return (int)$tgUser; }
        if (!empty($_SESSION['tg_user_id']) && is_numeric($_SESSION['tg_user_id'])) return (int)$_SESSION['tg_user_id'];
        if (!empty($_COOKIE['tg_user_id'])  && is_numeric($_COOKIE['tg_user_id']))  { $_SESSION['tg_user_id']=$_COOKIE['tg_user_id']; return (int)$_COOKIE['tg_user_id']; }
        return (int)($_SESSION['guest_id'] ?? 0);
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin($userId, $admins) { return $userId > 0 && in_array((int)$userId, $admins); }
}

function mdexGet($url, $params = []) {
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'BLACKWATCH-MangaReader/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$body) return null;
    return json_decode($body, true);
}

function downloadBinary($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'BLACKWATCH-MangaReader/1.0',CURLOPT_FOLLOWLOCATION=>true]);
    $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code === 200 && $data) ? $data : null;
}

function uploadBlobToImgbb($binaryData, $keys, $retries = 2) {
    $b64 = base64_encode($binaryData);
    foreach ($keys as $key) {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $ch = curl_init('https://api.imgbb.com/1/upload');
            curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['key'=>$key,'image'=>$b64],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>false]);
            $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($httpCode === 200) { $data = json_decode($response, true); if (!empty($data['data']['url'])) return $data['data']['url']; }
            if ($httpCode === 400 || $httpCode === 429) break;
            sleep(1);
        }
    }
    return null;
}

if (!function_exists('createTelegraphPage')) {
    function createTelegraphPage($title, $imageUrls) {
        $nodes = []; foreach ($imageUrls as $url) { if (!empty($url)) $nodes[] = ['tag'=>'img','attrs'=>['src'=>$url]]; }
        if (empty($nodes)) return false;
        $accessToken = '192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831';
        $postData = http_build_query(['access_token'=>$accessToken,'title'=>mb_substr($title,0,256),'author_name'=>'MangaBot','content'=>json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'return_content'=>'false']);
        $ch = curl_init('https://api.telegra.ph/createPage');
        curl_setopt_array($ch, [CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$postData,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>false]);
        $raw = curl_exec($ch); curl_close($ch); $res = json_decode($raw, true);
        if (!isset($res['ok']) || !$res['ok']) return false;
        return $res['result']['url'] ?? false;
    }
}

if (!function_exists('logArchiveEntry')) {
    function logArchiveEntry($pdo, $action_type, $action_text, $userId) {
        try { $pdo->prepare("INSERT INTO bot_archive (action_type,action_text,action_by) VALUES (?,?,?)")->execute([$action_type,$action_text,$userId]); } catch (Exception $e) {}
    }
}

// ── ROUTING ──────────────────────────────────────────────────────────────────
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ── Search ───────────────────────────────────────────────────────────────────
if ($path === '/api/mdex/search') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }
    $q    = trim($_GET['q'] ?? '');
    $lang = trim($_GET['lang'] ?? 'ru');
    if (!$q) { echo json_encode(['results'=>[]]); exit; }

    // Поиск без фильтра языка — ищем по всей базе MangaDex.
    // Язык применяется только при загрузке глав (/api/mdex/chapters).
    $params = ['title'=>$q,'limit'=>20,'includes[]'=>'cover_art','contentRating[]'=>['safe','suggestive','erotica','pornographic'],'order[relevance]'=>'desc'];

    $data = mdexGet('https://api.mangadex.org/manga', $params);
    if (!$data || empty($data['data'])) { echo json_encode(['results'=>[]]); exit; }

    $results = [];
    foreach ($data['data'] as $manga) {
        $attr  = $manga['attributes'];
        $title = $attr['title']['ru'] ?? $attr['title']['en'] ?? $attr['title']['ja-ro'] ?? $attr['title']['ja'] ?? (is_array($attr['title']) ? reset($attr['title']) : '') ?? '';
        // Ищем русское альтернативное название
        if (!isset($attr['title']['ru']) && !empty($attr['altTitles'])) {
            foreach ($attr['altTitles'] as $alt) { if (isset($alt['ru'])) { $title = $alt['ru']; break; } }
        }
        $coverUrl = null;
        foreach ($manga['relationships'] as $rel) {
            if ($rel['type'] === 'cover_art' && !empty($rel['attributes']['fileName'])) {
                $coverUrl = "https://uploads.mangadex.org/covers/{$manga['id']}/{$rel['attributes']['fileName']}.256.jpg"; break;
            }
        }
        $results[] = ['id'=>$manga['id'],'title'=>$title,'cover_url'=>$coverUrl,'status'=>$attr['status']??'','year'=>$attr['year']??null];
    }
    echo json_encode(['results'=>$results]);
    exit;
}

// ── Chapters ──────────────────────────────────────────────────────────────────
if ($path === '/api/mdex/chapters') {
    header('Content-Type: application/json');
    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }
    $mangaId = trim($_GET['manga_id'] ?? '');
    $lang    = trim($_GET['lang'] ?? 'ru');
    if (!$mangaId) { echo json_encode(['chapters'=>[]]); exit; }

    $allChapters = []; $offset = 0; $limit = 100;
    do {
        $params = ['limit'=>$limit,'offset'=>$offset,'order[chapter]'=>'asc','contentRating[]'=>['safe','suggestive','erotica','pornographic']];
        if ($lang) $params['translatedLanguage[]'] = $lang;
        $data = mdexGet("https://api.mangadex.org/manga/{$mangaId}/feed", $params);
        if (!$data || empty($data['data'])) break;
        foreach ($data['data'] as $ch) {
            $attr = $ch['attributes'];
            $allChapters[] = ['id'=>$ch['id'],'chapter'=>$attr['chapter']??'0','title'=>$attr['title']??'','pages'=>$attr['pages']??0,'lang'=>$attr['translatedLanguage']??$lang];
        }
        $offset += $limit; $total = $data['total'] ?? 0;
    } while ($offset < $total);

    $seen = []; $unique = [];
    foreach ($allChapters as $ch) { $key = $ch['chapter']; if (!isset($seen[$key])) { $seen[$key]=true; $unique[]=$ch; } }
    echo json_encode(['chapters'=>$unique]);
    exit;
}

// ── Import ────────────────────────────────────────────────────────────────────
if ($path === '/api/mdex/import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(600); ini_set('memory_limit','512M');

    $userId = getEffectiveUserId($pdo);
    if (!isAdmin($userId, $hardcodedAdmins)) { echo json_encode(['success'=>false,'error'=>'Нет прав']); exit; }

    $input         = json_decode(file_get_contents('php://input'), true);
    $action        = $input['action']            ?? '';
    $mdexMangaId   = trim($input['mdex_manga_id']    ?? '');
    $mdexChapterId = trim($input['mdex_chapter_id']  ?? '');
    $chapterNum    = (float)($input['chapter_num']   ?? 0);
    $mangaTitle    = trim($input['manga_title']      ?? '');
    $coverUrl      = trim($input['cover_url']        ?? '');
    $existingMangaId = (int)($input['existing_manga_id'] ?? 0);

    if (!$mdexChapterId) { echo json_encode(['success'=>false,'error'=>'Не указан chapter_id']); exit; }

    $atHome = mdexGet("https://api.mangadex.org/at-home/server/{$mdexChapterId}");
    if (!$atHome || empty($atHome['baseUrl'])) { echo json_encode(['success'=>false,'error'=>'Не удалось получить at-home сервер']); exit; }

    $baseUrl = rtrim($atHome['baseUrl'],'/');
    $hash    = $atHome['chapter']['hash'];
    $pages   = $atHome['chapter']['data'];
    if (empty($pages)) { echo json_encode(['success'=>false,'error'=>'Глава пустая (нет страниц)']); exit; }

    sort($pages);
    $uploadedUrls = []; $errors = 0;
    foreach ($pages as $filename) {
        $binary = downloadBinary("{$baseUrl}/data/{$hash}/{$filename}");
        if (!$binary) { $errors++; continue; }
        $imgUrl = uploadBlobToImgbb($binary, $imgbbKeys);
        if ($imgUrl) { $uploadedUrls[] = $imgUrl; } else { $errors++; }
        usleep(150000);
    }

    if (empty($uploadedUrls)) { echo json_encode(['success'=>false,'error'=>'Не удалось загрузить ни одной страницы']); exit; }

    $savedCoverUrl = null;
    if ($coverUrl) { $binary = downloadBinary($coverUrl); if ($binary) $savedCoverUrl = uploadBlobToImgbb($binary, $imgbbKeys); }

    $mangaId = 0;
    if ($action === 'new_manga') {
        if (!$mangaTitle) $mangaTitle = 'Манга с MangaDex';
        $insert = $pdo->prepare("INSERT INTO manga (title,description,cover_imgbb_url,added_by,is_series) VALUES (?,?,?,?,?) RETURNING id");
        $insert->execute(['♥ '.$mangaTitle, "Импортировано с MangaDex (ID: {$mdexMangaId})", $savedCoverUrl, $userId, true]);
        $row = $insert->fetch(); $mangaId = (int)($row['id'] ?? 0);
        if (!$mangaId) $mangaId = (int)$pdo->lastInsertId();
        logArchiveEntry($pdo, 'add_manga', "Импорт с MangaDex: ♥ {$mangaTitle}", $userId);
    } elseif ($action === 'add_to_existing') {
        $mangaId = $existingMangaId;
        if (!$mangaId) { echo json_encode(['success'=>false,'error'=>'Не указан existing_manga_id']); exit; }
        $pdo->prepare("UPDATE manga SET is_series=TRUE WHERE id=?")->execute([$mangaId]);
    } else {
        echo json_encode(['success'=>false,'error'=>'Неизвестный action: '.$action]); exit;
    }

    if (!$mangaId) { echo json_encode(['success'=>false,'error'=>'Не удалось создать/найти мангу']); exit; }

    $mangaRow = $pdo->prepare("SELECT title FROM manga WHERE id=?"); $mangaRow->execute([$mangaId]); $manga = $mangaRow->fetch();
    $chLabel = "Глава {$chapterNum}";
    $telegraphLink = createTelegraphPage(($manga['title'] ?? $mangaTitle).' — '.$chLabel, $uploadedUrls);

    $chInsert = $pdo->prepare("INSERT INTO manga_chapters (manga_id,chapter_num,title,telegraph_url) VALUES (?,?,?,?) RETURNING id");
    $chInsert->execute([$mangaId, $chapterNum ?: 1, null, $telegraphLink]);
    $chRow = $chInsert->fetch(); $chapterId = (int)($chRow['id'] ?? 0);

    if ($chapterId && !empty($uploadedUrls)) {
        $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id=?")->execute([$chapterId]);
        $pageStmt = $pdo->prepare("INSERT INTO manga_chapter_pages (chapter_id,page_url,page_order) VALUES (?,?,?)");
        foreach ($uploadedUrls as $i => $url) { $pageStmt->execute([$chapterId, $url, $i]); }
    }

    logArchiveEntry($pdo, 'add_chapter', "Импорт с MangaDex: Гл.{$chapterNum} для манги ID:{$mangaId}", $userId);
    $siteUrl = rtrim(getenv('SITE_URL') ?: '', '/');
    echo json_encode(['success'=>true,'manga_id'=>$mangaId,'chapter_id'=>$chapterId,'pages_total'=>count($pages),'pages_ok'=>count($uploadedUrls),'pages_errors'=>$errors,'telegraph'=>$telegraphLink,'site_url'=>"{$siteUrl}/read/{$mangaId}"]);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error'=>'Not found']);