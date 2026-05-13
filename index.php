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
    // ИСПРАВЛЕНО: статус теперь поддерживает 'will' (буду читать)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (user_id BIGINT NOT NULL, manga_id INT NOT NULL, status VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
} catch (Exception $e) {}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

session_start();
if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);

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
# API MANGA — каталог
# =========================
if ($path === '/api/manga') {
    header('Content-Type: application/json');
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $q      = trim($_GET['q'] ?? '');
    $limit  = 24;
    $offset = $page * $limit;

    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");
        $count->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url, file_id FROM manga ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $count = $pdo->query("SELECT COUNT(*) FROM manga");
    }

    $items = [];
    foreach ($stmt as $m) {
        $items[] = [
            'id'            => (int)$m['id'],
            'title'         => $m['title'],
            'likes'         => (int)$m['likes'],
            'dislikes'      => (int)$m['dislikes'],
            'cover_display' => !empty($m['cover_imgbb_url']) ? $m['cover_imgbb_url'] : (!empty($m['file_id']) ? 'tg://' . $m['file_id'] : null)
        ];
    }
    echo json_encode(['items' => $items, 'total' => (int)$count->fetchColumn(), 'limit' => $limit]);
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
                    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
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
# API STATUS — сохранить статус читателя (now / read / will)
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

    // ИСПРАВЛЕНО: добавлен 'will' (буду читать)
    if ($mangaId && in_array($status, ['now', 'read', 'will'])) {
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON CONFLICT (user_id, manga_id) DO UPDATE SET status = EXCLUDED.status")->execute([$userId, $mangaId, $status]);

        // Уведомляем Telegram бота если пользователь авторизован через TG
        $realTgUser = !empty($input['tg_user_id']) && is_numeric($input['tg_user_id']);
        if ($realTgUser && $userId > 0) {
            $botToken = getenv('BOT_TOKEN');
            if ($botToken) {
                $stmtM = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
                $stmtM->execute([$mangaId]);
                $mangaRow = $stmtM->fetch();
                $mangaTitle = $mangaRow['title'] ?? "Манга #$mangaId";
                $labels = ['now' => '📖 Читаю', 'will' => '🔖 Буду читать', 'read' => '✅ Прочитано'];
                $label = $labels[$status] ?? $status;
                $tgMsg = "🔄 *Статус обновлён с сайта*\n\n📖 *$mangaTitle*\n\n$label";
                $tgData = json_encode([
                    'chat_id'    => $userId,
                    'text'       => $tgMsg,
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
        }

        echo json_encode(['success' => true, 'user_id' => $userId]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}


# =========================
# API LIBRARY — библиотека пользователя
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
# VIEWER (РИДЕР) — постраничный просмотр
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

function nextPage() { if (current < pages.length - 1) { current++; render(); } }
function prevPage() { if (current > 0) { current--; render(); } }

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

    // Проверяем наличие страниц
    $pagesCount = $pdo->prepare("SELECT COUNT(*) FROM manga_pages WHERE manga_id = ? AND page_url IS NOT NULL AND page_url != ''");
    $pagesCount->execute([$id]);
    $hasPages = (int)$pagesCount->fetchColumn() > 0;

    // Текущий статус пользователя для этой манги
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
/* ИСПРАВЛЕНО: добавлены стили кнопок статуса */
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

    <!-- ГОЛОСОВАНИЕ -->
    <div class="vote-buttons">
        <button class="vote-btn vote-like"    onclick="vote('like')">👍 Лайк</button>
        <button class="vote-btn vote-dislike" onclick="vote('dislike')">👎 Дизлайк</button>
    </div>

    <!-- СТАТУС ЧТЕНИЯ (НОВЫЙ БЛОК) -->
    <div class="status-section">
        <div class="status-label">Мой статус</div>
        <div class="status-buttons">
            <button class="status-btn <?= $currentStatus === 'now'  ? 'active-now'  : '' ?>" id="btn-now"  onclick="setStatus('now')">📖 Читаю</button>
            <button class="status-btn <?= $currentStatus === 'will' ? 'active-will' : '' ?>" id="btn-will" onclick="setStatus('will')">🔖 Буду читать</button>
            <button class="status-btn <?= $currentStatus === 'read' ? 'active-read' : '' ?>" id="btn-read" onclick="setStatus('read')">✅ Прочитано</button>
        </div>
    </div>

    <!-- КНОПКИ ЧИТАТЬ -->
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
    // Проверяем URL параметр
    const urlParams = new URLSearchParams(window.location.search);
    const urlTgId = urlParams.get('tg_user_id');
    if (urlTgId) {
        document.cookie = 'tg_user_id=' + urlTgId + ';max-age=' + (86400*30) + ';path=/';
        return urlTgId;
    }
    const match = document.cookie.match(/tg_user_id=(\d+)/);
    return match ? match[1] : '';
}
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

// НОВАЯ ФУНКЦИЯ: установка статуса
async function setStatus(status) {
    try {
        const res = await fetch('/api/status', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({manga_id: <?= $id ?>, status: status, tg_user_id: getTgUser()})
        });
        const data = await res.json();
        if (data.success) {
            // Сбрасываем все кнопки
            ['now','will','read'].forEach(s => {
                const btn = document.getElementById('btn-' + s);
                btn.className = 'status-btn';
            });
            // Активируем нажатую
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
# LIBRARY PAGE — Моя библиотека
# ИСПРАВЛЕНО: 3 секции: Читаю / Буду читать / Прочитано
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
/* Секции библиотеки */
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

function sectionHtml(icon, title, items, statusClass) {
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
        // Разбиваем по статусам
        const now  = data.items.filter(i => i.status === 'now');
        const will = data.items.filter(i => i.status === 'will');
        const read = data.items.filter(i => i.status === 'read');

        let html = '';
        if (now.length  > 0) html += sectionHtml('📖', 'Читаю сейчас', now, 'now');
        if (will.length > 0) html += sectionHtml('🔖', 'Буду читать',  will, 'will');
        if (read.length > 0) html += sectionHtml('✅', 'Прочитано',    read, 'read');
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
header{position:sticky;top:0;z-index:100;backdrop-filter:blur(20px);background:rgba(7,7,11,0.85);border-bottom:1px solid var(--border);padding:16px 24px}
.header-inner{max-width:1400px;margin:auto;display:flex;flex-wrap:wrap;align-items:center;gap:16px}
.logo{font-size:26px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;white-space:nowrap}
.search{flex:1;min-width:200px;max-width:500px;padding:12px 20px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:var(--text);font-size:15px;font-family:inherit}
.search:focus{outline:none;border-color:var(--accent)}
.search::placeholder{color:var(--muted)}
.wrap{max-width:1400px;margin:auto;padding:28px 20px}
.stats{color:var(--muted);margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border);font-size:14px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:22px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.3s}
.card:hover{transform:translateY(-6px);border-color:var(--accent);box-shadow:0 8px 30px rgba(124,92,255,0.15)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.info{padding:14px}
.title{font-size:13px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4}
.likes{margin-top:8px;color:#ffd166;font-size:12px}
.load-more{margin:50px auto;display:block;padding:14px 36px;background:var(--accent);color:#fff;border:none;border-radius:50px;cursor:pointer;font-size:15px;font-weight:600;font-family:inherit;transition:all 0.2s}
.load-more:hover{background:#6a4ee0;transform:translateY(-2px)}
.library-btn{position:fixed;bottom:28px;right:28px;background:var(--accent);color:#fff;border:none;padding:14px 22px;border-radius:50px;cursor:pointer;font-weight:600;z-index:100;box-shadow:0 4px 20px rgba(124,92,255,0.4);text-decoration:none;font-size:14px;font-family:inherit;transition:all 0.2s}
.library-btn:hover{transform:translateY(-2px);box-shadow:0 6px 25px rgba(124,92,255,0.5)}
.empty{text-align:center;padding:80px 20px;color:var(--muted)}
@media(max-width:768px){
    .grid{grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:14px}
    .library-btn{bottom:18px;right:18px;padding:12px 18px;font-size:13px}
}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<header>
    <div class="header-inner">
        <div class="logo">BLACKWATCH<span style="color:var(--accent)">MANGA</span></div>
        <input id="search" class="search" placeholder="🔍 Поиск манги..." autocomplete="off">
    </div>
</header>
<div class="wrap">
    <div class="stats" id="stats">Всего манги: <strong><?= (int)$total ?></strong></div>
    <div class="grid" id="grid"></div>
    <button class="load-more" id="more" style="display:none">Загрузить ещё</button>
</div>
<a href="/library" class="library-btn">📚 Моя библиотека</a>
<script>
(function() {
    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
            const id = window.Telegram.WebApp.initDataUnsafe.user.id;
            document.cookie = 'tg_user_id=' + id + ';max-age=' + (86400*30) + ';path=/';
        }
    } catch(e) {}
})();

let page = 0, q = '', loading = false, hasMore = true;
const grid        = document.getElementById('grid');
const moreBtn     = document.getElementById('more');
const searchInput = document.getElementById('search');
const statsDiv    = document.getElementById('stats');

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function load(reset = false) {
    if (loading) return;
    loading = true;
    if (reset) { page = 0; grid.innerHTML = ''; hasMore = true; moreBtn.style.display = 'none'; }
    if (page === 0 && grid.children.length === 0) {
        grid.innerHTML = '<div class="empty">📖 Загрузка...</div>';
    }
    try {
        const res  = await fetch(`/api/manga?page=${page}&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        if (page === 0) {
            grid.innerHTML = '';
            statsDiv.innerHTML = q
                ? `Найдено: <strong>${data.total}</strong> (из ${<?= (int)$total ?>} манг)`
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
            grid.insertAdjacentHTML('beforeend',
                `<a class="card" href="/read/${m.id}">
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
load();
</script>
</body>
</html>