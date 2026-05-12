<?php
// Логирование ошибок для отладки
file_put_contents('bot_debug.txt', date('Y-m-d H:i:s') . ' - ' . file_get_contents("php://input") . "\n", FILE_APPEND);
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
set_time_limit(900);
ini_set('memory_limit', '512M');

$token  = getenv('BOT_TOKEN');
$apiUrl = "https://api.telegram.org/bot$token";
$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://blackwatch-manga.onrender.com', '/');
$imgbbKey = '58ff4596fd55028a81cbf8c4e38388e1';

// Подключение к БД (Neon PostgreSQL) [cite: 306, 307]
try {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME'));
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Авто-создание всех необходимых таблиц 
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga (id SERIAL PRIMARY KEY, title TEXT, cover_imgbb_url TEXT, telegraph_url TEXT, likes INT DEFAULT 0, dislikes INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_pages (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS temp_data (user_id BIGINT PRIMARY KEY, step VARCHAR(50) NOT NULL, title TEXT, pages TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (user_id BIGINT NOT NULL, manga_id INT NOT NULL, vote_type VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (user_id BIGINT NOT NULL, manga_id INT NOT NULL, status VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
} catch (PDOException $e) { die("DB Error: " . $e->getMessage()); }

$admins = [1710365896, 1181510470]; // Список админов [cite: 320]

// Универсальная функция запросов к TG [cite: 323-325]
function tgPost($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = curl_exec($ch); curl_close($ch); return $r;
}

// Загрузка на ImgBB [cite: 329-331]
function uploadToImgbb($file, $apiKey) {
    $imageData = base64_encode(file_get_contents($file));
    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $apiKey, 'image' => $imageData]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    return $res['data']['url'] ?? false;
}

// Работа с ZIP [cite: 341-368]
function extractAndSortZip($zipPath) {
    $zip = new ZipArchive; $pages = []; $tempDir = 'temp_' . time();
    if ($zip->open($zipPath) === TRUE) {
        mkdir($tempDir); $zip->extractTo($tempDir);
        $files = array_diff(scandir($tempDir), array('.', '..')); natsort($files);
        foreach ($files as $f) {
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp'])) $pages[] = $tempDir . '/' . $f;
        }
        $zip->close();
    }
    return ['pages' => $pages, 'dir' => $tempDir];
}
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

$message = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;

if ($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $userId = $message['from']['id'];

    $stmt = $pdo->prepare("SELECT * FROM temp_data WHERE user_id = ?");
    $stmt->execute([$chatId]);
    $temp = $stmt->fetch();
    $step = $temp['step'] ?? '';

    // Кнопки главного меню [cite: 380-381]
    if ($text === '/start' || $text === '🔙 Назад') {
        $kb = [[['text'=>'🔍 Найти мангу'],['text'=>'📚 Моя библиотека']],[['text'=>'🌐 Каталог','web_app'=>['url'=>$siteUrl]]]];
        if (in_array($userId, $admins)) $kb[] = [['text'=>'⚙️ АДМИН-ПАНЕЛЬ']];
        tgPost($apiUrl."/sendMessage", ['chat_id'=>$chatId, 'text'=>"Добро пожаловать в BLACKWATCH!", 'reply_markup'=>['keyboard'=>$kb,'resize_keyboard'=>true]]);
        exit;
    }

    // Логика Админки [cite: 382]
    if (in_array($userId, $admins)) {
        if ($text === '⚙️ АДМИН-ПАНЕЛЬ') {
            $akb = [[['text'=>'➕ Добавить через альбом'],['text'=>'📦 Добавить через ZIP']],[['text'=>'🔙 Назад']]];
            tgPost($apiUrl."/sendMessage",['chat_id'=>$chatId,'text'=>"🛠 Панель управления:",'reply_markup'=>['keyboard'=>$akb,'resize_keyboard'=>true]]);
            exit;
        }

        // Добавление через альбом
        if ($text === '➕ Добавить через альбом') {
            $pdo->prepare("INSERT INTO temp_data (user_id, step) VALUES (?, 'wait_title') ON CONFLICT (user_id) DO UPDATE SET step='wait_title'")->execute([$chatId]);
            tgPost($apiUrl."/sendMessage",['chat_id'=>$chatId,'text'=>"Введите название новой манги:"]);
            exit;
        }

        if ($step === 'wait_title') {
            $pdo->prepare("UPDATE temp_data SET title = ?, step = 'wait_pages', pages = '[]' WHERE user_id = ?")->execute([$text, $chatId]);
            tgPost($apiUrl."/sendMessage",['chat_id'=>$chatId,'text'=>"Теперь присылайте страницы (картинки). Когда закончите, нажмите кнопку ниже:", 'reply_markup'=>['keyboard'=>[[['text'=>'✅ Завершить загрузку']]],'resize_keyboard'=>true]]);
            exit;
        }

        if ($text === '✅ Завершить загрузку' && $step === 'wait_pages') {
            $pdo->prepare("INSERT INTO manga (title) VALUES (?)")->execute([$temp['title']]);
            $mId = $pdo->lastInsertId();
            $pages = json_decode($temp['pages'], true);
            foreach ($pages as $idx => $pUrl) {
                $pdo->prepare("INSERT INTO manga_pages (manga_id, page_url, page_order) VALUES (?,?,?)")->execute([$mId, $pUrl, $idx]);
            }
            $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
            tgPost($apiUrl."/sendMessage",['chat_id'=>$chatId,'text'=>"✅ Манга успешно добавлена!"]);
            exit;
        }

        if ($step === 'wait_pages' && isset($message['photo'])) {
            $fId = end($message['photo'])['file_id'];
            $fInfo = json_decode(file_get_contents($apiUrl."/getFile?file_id=$fId"), true);
            $pUrl = uploadToImgbb("https://api.telegram.org/file/bot$token/".$fInfo['result']['file_path'], $imgbbKey);
            $currPages = json_decode($temp['pages'], true);
            $currPages[] = $pUrl;
            $pdo->prepare("UPDATE temp_data SET pages = ? WHERE user_id = ?")->execute([json_encode($currPages), $chatId]);
        }
    }
}