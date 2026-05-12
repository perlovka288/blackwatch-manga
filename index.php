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
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
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
} catch (Exception $e) {}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

session_start();
if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);


# =========================
# API MANGA
# =========================
if ($path === '/api/manga') {
    header('Content-Type: application/json');
    $page = max(0, (int)($_GET['page'] ?? 0));
    $q = trim($_GET['q'] ?? '');
    $limit = 24;
    $offset = $page * $limit;

    if ($q) {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->execute(["%{$q}%", $limit, $offset]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");
        $count->execute(["%{$q}%"]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, likes, dislikes, cover_imgbb_url FROM manga ORDER BY id DESC LIMIT ? OFFSET ?");
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
            // FIX: возвращаем реальный URL обложки, null если нет
            'cover_display' => !empty($m['cover_imgbb_url']) ? $m['cover_imgbb_url'] : null
        ];
    }
    echo json_encode(['items' => $items, 'total' => (int)$count->fetchColumn(), 'limit' => $limit]);
    exit;
}


# =========================
# API PAGES — FIX: правильная выборка страниц
# =========================
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json');
    $id = (int)$m[1];
    // FIX: выбираем page_url и сортируем по page_order
    $stmt = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? AND page_url IS NOT NULL AND page_url != '' ORDER BY page_order ASC");
    $stmt->execute([$id]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['pages' => array_values($pages)]);
    exit;
}


# =========================
# API VOTE
# =========================
if ($path === '/api/vote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    // FIX: используем Telegram user_id если передан (синхронизация с ботом)
    $tgUser = $input['tg_user_id'] ?? '';
    $userId = $tgUser ? (int)$tgUser : (int)$_SESSION['guest_id'];

    $mangaId = (int)($input['manga_id'] ?? 0);
    $voteType = $input['vote_type'] ?? '';

    if ($mangaId && in_array($voteType, ['like', 'dislike'])) {
        $check = $pdo->prepare("SELECT vote_type FROM votes WHERE user_id = ? AND manga_id = ?");
        $check->execute([$userId, $mangaId]);
        $existing = $check->fetch();

        if ($existing) {
            if ($existing['vote_type'] !== $voteType) {
                $pdo->prepare("UPDATE votes SET vote_type = ? WHERE user_id = ? AND manga_id = ?")->execute([$voteType, $userId, $mangaId]);
                if ($voteType == 'like') {
                    $pdo->prepare("UPDATE manga SET likes = likes + 1, dislikes = dislikes - 1 WHERE id = ?")->execute([$mangaId]);
                } else {
                    $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1, likes = likes - 1 WHERE id = ?")->execute([$mangaId]);
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
# API STATUS — FIX: синхронизация с Telegram user_id
# =========================
if ($path === '/api/status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    $tgUser = $input['tg_user_id'] ?? '';
    $userId = $tgUser ? (int)$tgUser : (int)$_SESSION['guest_id'];

    $mangaId = (int)($input['manga_id'] ?? 0);
    $status = $input['status'] ?? '';

    if ($mangaId && in_array($status, ['now', 'read'])) {
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON CONFLICT (user_id, manga_id) DO UPDATE SET status = EXCLUDED.status")->execute([$userId, $mangaId, $status]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}


# =========================
# API LIBRARY — FIX: синхронизация с Telegram user_id
# =========================
if ($path === '/api/library') {
    header('Content-Type: application/json');
    $tgUser = $_GET['tg_user_id'] ?? '';
    $userId = $tgUser ? (int)$tgUser : (int)$_SESSION['guest_id'];

    $stmt = $pdo->prepare("SELECT m.id, m.title, m.cover_imgbb_url, s.status FROM user_manga_status s JOIN manga m ON s.manga_id = m.id WHERE s.user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['items' => $stmt->fetchAll()]);
    exit;
}


# =========================
# VIEWER (РИДЕР) — FIX: улучшенный ридер с fallback
# =========================
if (preg_match('#^/view/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title, telegraph_url FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404 - Манга не найдена'); }
    $title = htmlspecialchars($manga['title']);
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
#loading{position:absolute;color:#aaa;font-size:16px;text-align:center}
.fallback{position:absolute;text-align:center;display:none}
.fallback p{margin-bottom:16px;color:#aaa}
.telegraph-link{background:#7c5cff;color:#fff;padding:12px 24px;border-radius:40px;text-decoration:none;font-weight:600}
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
    };
    img.onerror = () => {
        // Пробуем следующую если не загрузилась
        if (current < pages.length - 1) { current++; render(); }
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
# MANGA PAGE (карточка)
# =========================
if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, telegraph_url, likes, dislikes FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404 - Манга не найдена'); }

    // Проверяем наличие страниц
    $pagesCount = $pdo->prepare("SELECT COUNT(*) FROM manga_pages WHERE manga_id = ? AND page_url IS NOT NULL AND page_url != ''");
    $pagesCount->execute([$id]);
    $hasPages = (int)$pagesCount->fetchColumn() > 0;
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
.status-buttons,.vote-buttons,.read-buttons{display:flex;gap:12px;margin-top:18px;flex-wrap:wrap}
.status-btn{padding:10px 20px;border-radius:30px;border:1px solid var(--border);font-weight:600;cursor:pointer;background:var(--card);color:var(--text);font-size:14px;transition:all 0.2s}
.status-btn:hover{border-color:var(--accent)}
.status-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.vote-btn{padding:10px 20px;border-radius:30px;border:none;font-weight:600;cursor:pointer;font-size:14px;transition:all 0.2s}
.vote-like{background:rgba(255,107,107,0.15);color:#ff6b6b}
.vote-like:hover{background:rgba(255,107,107,0.3)}
.vote-dislike{background:rgba(150,150,150,0.15);color:#aaa}
.vote-dislike:hover{background:rgba(150,150,150,0.25)}
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
<?php if (!empty($manga['cover_imgbb_url'])): ?>
    <img class="cover" src="<?= htmlspecialchars($manga['cover_imgbb_url']) ?>" alt="Обложка">
<?php else: ?>
    <div class="cover-placeholder"><span style="font-size:72px">📖</span></div>
<?php endif; ?>
<div class="info">
    <div class="title"><?= htmlspecialchars($manga['title']) ?></div>
    <div class="desc"><?= nl2br(htmlspecialchars($manga['description'] ?? 'Описание отсутствует')) ?></div>
    <div class="stats">
        <div class="stat-pill likes-count">❤️ <span id="likes"><?= (int)$manga['likes'] ?></span> лайков</div>
        <div class="stat-pill dislikes-count">💔 <span id="dislikes"><?= (int)$manga['dislikes'] ?></span></div>
    </div>
    <div class="status-buttons">
        <button class="status-btn" id="status-now"  onclick="setStatus('now')">⏳ Читаю сейчас</button>
        <button class="status-btn" id="status-read" onclick="setStatus('read')">✅ Прочитано</button>
    </div>
    <div class="vote-buttons">
        <button class="vote-btn vote-like"    onclick="vote('like')">👍 Лайк</button>
        <button class="vote-btn vote-dislike" onclick="vote('dislike')">👎 Дизлайк</button>
    </div>
    <div class="read-buttons">
        <?php if ($hasPages): ?>
        <a class="btn primary" href="/view/<?= $id ?>">📖 Читать онлайн</a>
        <?php endif; ?>
        <?php if (!empty($manga['telegraph_url'])): ?>
        <a class="btn secondary" target="_blank" href="<?= htmlspecialchars($manga['telegraph_url']) ?>">📄 Telegraph</a>
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
            return window.Telegram.WebApp.initDataUnsafe.user.id;
        }
    } catch(e) {}
    return '';
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
            document.getElementById('likes').innerText   = data.likes;
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
            document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('status-' + status).classList.add('active');
            showToast(status == 'now' ? '📖 Добавлено в "Читаю сейчас"!' : '✅ Добавлено в "Прочитано"!');
        }
    } catch(e) {}
}

function showToast(msg) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = 'toast';
    t.innerText = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2600);
}

// Загружаем сохранённый статус
(async () => {
    try {
        const tgId = getTgUser();
        const res  = await fetch('/api/library?tg_user_id=' + tgId);
        const data = await res.json();
        data.items.forEach(i => {
            if (i.id == <?= $id ?>) {
                const btn = document.getElementById('status-' + i.status);
                if (btn) btn.classList.add('active');
            }
        });
    } catch(e) {}
})();
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
:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--accent:#7c5cff}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif}
.header{padding:20px 24px;background:rgba(7,7,11,0.9);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px}
.logo{font-size:24px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.wrap{max-width:1200px;margin:auto;padding:30px 20px}
.back-link{display:inline-block;margin-bottom:20px;color:var(--accent);text-decoration:none;font-weight:600}
.back-link:hover{opacity:0.8}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:20px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.3s}
.card:hover{transform:translateY(-4px);border-color:var(--accent)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.info{padding:14px}
.title{font-size:13px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;margin-top:8px;font-weight:600}
.badge-now{background:rgba(255,107,107,0.2);color:#ff6b6b}
.badge-read{background:rgba(76,175,80,0.2);color:#4caf50}
.empty{text-align:center;padding:80px 20px;color:var(--muted)}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<div class="header"><div class="logo">📚 Моя библиотека</div></div>
<div class="wrap">
    <a href="/" class="back-link">← На главную</a>
    <div class="grid" id="grid"><div class="empty">📖 Загрузка...</div></div>
</div>
<script>
function getTgUser() {
    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
            return window.Telegram.WebApp.initDataUnsafe.user.id;
        }
    } catch(e) {}
    return '';
}

function escapeHtml(t) {
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}

async function load() {
    try {
        const tgId = getTgUser();
        const res  = await fetch('/api/library?tg_user_id=' + tgId);
        const data = await res.json();
        const grid = document.getElementById('grid');
        if (!data.items || data.items.length === 0) {
            grid.innerHTML = '<div class="empty">📭 У вас пока нет добавленной манги<br><br><a href="/" style="color:var(--accent)">Перейти в каталог →</a></div>';
            return;
        }
        grid.innerHTML = data.items.map(m => `
            <a class="card" href="/read/${m.id}">
                ${m.cover_imgbb_url
                    ? `<img class="cover" src="${escapeHtml(m.cover_imgbb_url)}" loading="lazy" alt="">`
                    : `<div class="cover-ph"><span style="font-size:48px">📖</span></div>`}
                <div class="info">
                    <div class="title">${escapeHtml(m.title)}</div>
                    <span class="badge badge-${m.status == 'now' ? 'now' : 'read'}">
                        ${m.status == 'now' ? '⏳ Читаю сейчас' : '✅ Прочитано'}
                    </span>
                </div>
            </a>`).join('');
    } catch(e) {
        document.getElementById('grid').innerHTML = '<div class="empty">❌ Ошибка загрузки</div>';
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
let page = 0, q = '', loading = false, hasMore = true;
const grid       = document.getElementById('grid');
const moreBtn    = document.getElementById('more');
const searchInput = document.getElementById('search');
const statsDiv   = document.getElementById('stats');

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
            // FIX: показываем обложку если есть cover_display, иначе эмодзи
            const coverHtml = m.cover_display
                ? `<img class="cover" src="${escapeHtml(m.cover_display)}" loading="lazy" alt="">`
                : `<div class="cover-ph"><span style="font-size:52px">📖</span></div>`;
            grid.insertAdjacentHTML('beforeend',
                `<a class="card" href="/read/${m.id}">
                    ${coverHtml}
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