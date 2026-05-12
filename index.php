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
            'id' => (int)$m['id'],
            'title' => $m['title'],
            'likes' => (int)$m['likes'],
            'dislikes' => (int)$m['dislikes'],
            'cover_display' => $m['cover_imgbb_url'] ?? null
        ];
    }
    echo json_encode(['items' => $items, 'total' => (int)$count->fetchColumn(), 'limit' => $limit]);
    exit;
}

# =========================
# API PAGES
# =========================
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json');
    $id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id=? ORDER BY page_order");
    $stmt->execute([$id]);
    echo json_encode(['pages' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    exit;
}

# =========================
# API VOTE
# =========================
if ($path === '/api/vote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    $tgUser = $input['tg_user_id'] ?? '';
    $userId = $tgUser ? (int)$tgUser : $_SESSION['guest_id'];
    
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
        echo json_encode(['success' => true, 'likes' => $stats['likes'], 'dislikes' => $stats['dislikes']]);
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
    $input = json_decode(file_get_contents('php://input'), true);
    
    $tgUser = $input['tg_user_id'] ?? '';
    $userId = $tgUser ? (int)$tgUser : $_SESSION['guest_id'];
    
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
# API LIBRARY
# =========================
if ($path === '/api/library') {
    header('Content-Type: application/json');
    $tgUser = $_GET['tg_user_id'] ?? '';
    $userId = $tgUser ? (int)$tgUser : $_SESSION['guest_id'];
    
    $stmt = $pdo->prepare("SELECT m.id, m.title, m.cover_imgbb_url, s.status FROM user_manga_status s JOIN manga m ON s.manga_id = m.id WHERE s.user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['items' => $stmt->fetchAll()]);
    exit;
}

# =========================
# VIEWER (РИДЕР)
# =========================
if (preg_match('#^/view/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title, telegraph_url FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404'); }
    $title = htmlspecialchars($manga['title']);
    $telegraphUrl = htmlspecialchars($manga['telegraph_url'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $title ?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#000;color:#fff;overflow:hidden;font-family:sans-serif}.reader{height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0a;flex-direction:column;gap:20px}.reader img{max-width:100%;max-height:80vh;object-fit:contain}.nav{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer}.nav:hover{background:rgba(255,255,255,0.05)}.prev{left:0}.next{right:0}.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.8);padding:8px 16px;border-radius:999px;z-index:100;font-size:14px}.back{position:fixed;top:20px;left:20px;z-index:100;color:#fff;text-decoration:none;background:rgba(0,0,0,0.6);padding:10px 18px;border-radius:30px}.telegraph-link{background:#7c5cff;color:#fff;padding:12px 24px;border-radius:40px;text-decoration:none}</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<a href="/read/<?= $id ?>" class="back">← Назад</a>
<div class="counter"><span id="counter">1</span></div>
<div class="nav prev" onclick="prevPage()"></div><div class="nav next" onclick="nextPage()"></div>
<div class="reader"><div class="loading" id="loading">📖 Загрузка...</div><img id="page" style="display:none"><div id="telegraph-fallback" style="display:none; text-align:center"><p style="margin-bottom:15px">❌ Страницы не найдены</p><a href="<?= $telegraphUrl ?>" target="_blank" class="telegraph-link">📄 Читать в Telegraph</a></div></div>
<script>
let pages=[],current=0;
async function init(){
    const loading=document.getElementById('loading'),img=document.getElementById('page'),fallback=document.getElementById('telegraph-fallback');
    try{
        const res=await fetch('/api/pages/<?= $id ?>'),data=await res.json();
        pages=data.pages;
        if(pages.length===0){loading.style.display='none';fallback.style.display='block';return}
        loading.style.display='none';img.style.display='block';render()
    }catch(error){loading.style.display='none';fallback.style.display='block'}
}
function render(){if(!pages[current])return;document.getElementById('page').src=pages[current];document.getElementById('counter').innerText=(current+1)+' / '+pages.length}
function nextPage(){if(current<pages.length-1){current++;render()}}
function prevPage(){if(current>0){current--;render()}}
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight')nextPage();if(e.key==='ArrowLeft')prevPage()});
let touchStartX=0;
document.addEventListener('touchstart',e=>{touchStartX=e.changedTouches[0].screenX});
document.addEventListener('touchend',e=>{let endX=e.changedTouches[0].screenX;if(endX<touchStartX-50)nextPage();if(endX>touchStartX+50)prevPage()});
init();
</script>
</body>
</html>
<?php exit; }

# =========================
# MANGA PAGE
# =========================
if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, telegraph_url, likes, dislikes FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404'); }
    ?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($manga['title']) ?></title>
<style>
:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--muted:#8e8ea0;--accent:#7c5cff}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:sans-serif}
.wrap{max-width:1100px;margin:auto;padding:40px 20px}
.back-link{color:var(--muted);text-decoration:none;margin-bottom:30px;display:inline-block}
.back-link:hover{color:var(--accent)}
.box{display:flex;gap:40px;flex-wrap:wrap;background:var(--card);border-radius:28px;padding:30px;border:1px solid var(--border)}
.cover{width:280px;border-radius:20px;object-fit:cover}
.info{flex:1}
.title{font-size:42px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent)100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:16px}
.desc{color:var(--muted);line-height:1.7;margin-top:20px}
.stats{display:flex;gap:20px;margin-top:20px}
.likes-count,.dislikes-count{padding:8px 16px;border-radius:40px}
.likes-count{background:rgba(255,107,107,0.15);color:#ff6b6b}
.dislikes-count{background:rgba(107,107,107,0.15);color:#aaa}
.status-buttons,.vote-buttons,.read-buttons{display:flex;gap:14px;margin-top:20px;flex-wrap:wrap}
.status-btn,.vote-btn{padding:10px 20px;border-radius:30px;border:none;font-weight:600;cursor:pointer;background:var(--card);color:var(--text);border:1px solid var(--border)}
.status-btn.active{background:var(--accent);color:#fff}
.vote-like{background:rgba(255,107,107,0.2);color:#ff6b6b}
.vote-dislike{background:rgba(107,107,107,0.2);color:#aaa}
.btn{padding:14px 28px;border-radius:14px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:8px}
.primary{background:var(--accent);color:#fff}
.secondary{background:var(--card);color:var(--text);border:1px solid var(--border)}
@media(max-width:768px){.title{font-size:28px;text-align:center}.cover{max-width:250px;margin:0 auto}}
.toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#000;color:#fff;padding:12px 24px;border-radius:50px;z-index:1000;animation:fadeOut 2s forwards}
@keyframes fadeOut{0%{opacity:1}70%{opacity:1}100%{opacity:0;visibility:hidden}}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<div class="wrap">
<a href="/" class="back-link">← Вернуться в каталог</a>
<div class="box">
<?php if(!empty($manga['cover_imgbb_url'])): ?>
<img class="cover" src="<?= htmlspecialchars($manga['cover_imgbb_url']) ?>">
<?php else: ?>
<div class="cover" style="background:linear-gradient(135deg,#1a1a2e,#0a0a0a);display:flex;align-items:center;justify-content:center"><span style="font-size:64px">📖</span></div>
<?php endif; ?>
<div class="info">
<div class="title"><?= htmlspecialchars($manga['title']) ?></div>
<div class="desc"><?= nl2br(htmlspecialchars($manga['description'] ?? 'Описание отсутствует')) ?></div>
<div class="stats"><div class="likes-count">❤ <span id="likes"><?= (int)$manga['likes'] ?></span></div><div class="dislikes-count">💔 <span id="dislikes"><?= (int)$manga['dislikes'] ?></span></div></div>
<div class="status-buttons"><button class="status-btn" id="status-now" onclick="setStatus('now')">⏳ Читаю сейчас</button><button class="status-btn" id="status-read" onclick="setStatus('read')">✅ Прочитано</button></div>
<div class="vote-buttons"><button class="vote-btn vote-like" onclick="vote('like')">👍 Лайк</button><button class="vote-btn vote-dislike" onclick="vote('dislike')">👎 Дизлайк</button></div>
<div class="read-buttons"><a class="btn primary" href="/view/<?= $id ?>">📖 Читать онлайн</a><?php if(!empty($manga['telegraph_url'])): ?><a class="btn secondary" target="_blank" href="<?= htmlspecialchars($manga['telegraph_url']) ?>">📄 Telegraph</a><?php endif; ?></div>
</div></div></div>
<script>
function getTgUser() {
    if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
        return window.Telegram.WebApp.initDataUnsafe.user.id;
    }
    return '';
}

async function vote(type){
    try{
        const res=await fetch('/api/vote',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:<?= $id ?>,vote_type:type,tg_user_id:getTgUser()})});
        const data=await res.json();
        if(data.success){document.getElementById('likes').innerText=data.likes;document.getElementById('dislikes').innerText=data.dislikes;showToast(type=='like'?'👍 Лайк учтён!':'👎 Дизлайк учтён!')}
    }catch(e){}
}
async function setStatus(status){
    try{
        const res=await fetch('/api/status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:<?= $id ?>,status:status,tg_user_id:getTgUser()})});
        const data=await res.json();
        if(data.success){document.querySelectorAll('.status-btn').forEach(btn=>btn.classList.remove('active'));document.getElementById(`status-${status}`).classList.add('active');showToast(status=='now'?'📖 В "Читаю сейчас"!':'✅ В "Прочитано"!')}
    }catch(e){}
}
function showToast(msg){let t=document.createElement('div');t.className='toast';t.innerText=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2000)}
// Загружаем сохранённый статус
(async()=>{try{
    const tgId = getTgUser();
    const res=await fetch('/api/library?tg_user_id=' + tgId),data=await res.json();
    data.items.forEach(i=>{if(i.id==<?= $id ?>)document.getElementById(`status-${i.status}`)?.classList.add('active')})
}catch(e){}})();
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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Моя библиотека</title>
<style>:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--accent:#7c5cff}*{margin:0;padding:0;box-sizing:border-box}body{background:var(--bg);color:var(--text);font-family:sans-serif}.header{padding:20px;background:rgba(7,7,11,0.8);border-bottom:1px solid var(--border)}.logo{font-size:28px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent)100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}.wrap{max-width:1200px;margin:auto;padding:30px 20px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:24px}.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;text-decoration:none;color:#fff}.card:hover{transform:translateY(-5px);border-color:var(--accent)}.cover{width:100%;aspect-ratio:2/3;object-fit:cover}.info{padding:14px}.title{font-size:14px;font-weight:600}.status-badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;margin-top:8px}.status-now{background:#ff6b6b;color:#fff}.status-read{background:#4caf50;color:#fff}.back-link{display:inline-block;margin-bottom:20px;color:var(--accent);text-decoration:none}</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<div class="header"><div class="logo">📚 Моя библиотека</div></div>
<div class="wrap"><a href="/" class="back-link">← На главную</a><div class="grid" id="grid"><div class="loading">📖 Загрузка...</div></div></div>
<script>
function getTgUser() {
    if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initDataUnsafe && window.Telegram.WebApp.initDataUnsafe.user) {
        return window.Telegram.WebApp.initDataUnsafe.user.id;
    }
    return '';
}

async function load(){
    try{
        const tgId = getTgUser();
        const res=await fetch('/api/library?tg_user_id=' + tgId),data=await res.json();
        const grid=document.getElementById('grid');
        if(data.items.length===0){grid.innerHTML='<div style="text-align:center;padding:60px">📭 У вас пока нет добавленной манги</div>';return}
        grid.innerHTML=data.items.map(m=>`<a class="card" href="/read/${m.id}">${m.cover_imgbb_url?`<img class="cover" src="${m.cover_imgbb_url}">`:'<div class="cover" style="display:flex;align-items:center;justify-content:center"><span style="font-size:48px">📖</span></div>'}<div class="info"><div class="title">${escapeHtml(m.title)}</div><div class="status-badge status-${m.status=='now'?'now':'read'}">${m.status=='now'?'⏳ Читаю сейчас':'✅ Прочитано'}</div></div></a>`).join('')
    }catch(e){document.getElementById('grid').innerHTML='<div style="text-align:center;padding:60px">❌ Ошибка загрузки</div>'}
}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML}
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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BLACKWATCH | Manga Reader</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#07070b;--card:#101018;--border:#26263a;--text:#f3f3f7;--muted:#8e8ea0;--accent:#7c5cff}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif}
header{position:sticky;top:0;z-index:100;backdrop-filter:blur(20px);background:rgba(7,7,11,0.8);border-bottom:1px solid var(--border);padding:20px}
.logo{font-size:28px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent)100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.search{width:100%;max-width:500px;margin-top:20px;padding:14px 20px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:#fff}
.search:focus{outline:none;border-color:var(--accent)}
.wrap{max-width:1400px;margin:auto;padding:30px 20px}
.stats{color:var(--muted);margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;text-decoration:none;color:#fff;transition:all 0.3s}
.card:hover{transform:translateY(-6px);border-color:var(--accent)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover}
.info{padding:14px}
.title{font-size:14px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.likes{margin-top:8px;color:#ffd166;font-size:12px}
.load-more{margin:50px auto;display:block;padding:14px 32px;background:var(--accent);color:#fff;border:none;border-radius:50px;cursor:pointer}
.library-btn{position:fixed;bottom:30px;right:30px;background:var(--accent);color:#fff;border:none;padding:15px 20px;border-radius:50px;cursor:pointer;font-weight:600;z-index:100;box-shadow:0 4px 15px rgba(124,92,255,0.3);text-decoration:none}
.library-btn:hover{transform:translateY(-2px)}
@media(max-width:768px){.grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr)}.library-btn{bottom:20px;right:20px;padding:12px 16px;font-size:14px}}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<header><div class="logo">BLACKWATCH<span style="color:var(--accent)">MANGA</span></div><input id="search" class="search" placeholder="🔍 Поиск манги..." autocomplete="off"></header>
<div class="wrap"><div class="stats" id="stats">Всего манги: <strong><?= $total ?></strong></div><div class="grid" id="grid"></div><button class="load-more" id="more" style="display:none">Загрузить ещё</button></div>
<a href="/library" class="library-btn">📚 Моя библиотека</a>
<script>
let page=0,q='',loading=false,hasMore=true,totalItems=<?= $total ?>;
const grid=document.getElementById('grid'),moreBtn=document.getElementById('more'),searchInput=document.getElementById('search'),statsDiv=document.getElementById('stats');
async function load(reset=false){
    if(loading)return;
    loading=true;
    if(reset){page=0;grid.innerHTML='';hasMore=true}
    if(page===0&&grid.children.length===0)grid.innerHTML='<div style="text-align:center;padding:60px">📖 Загрузка манги...</div>';
    try{
        const res=await fetch(`/api/manga?page=${page}&q=${encodeURIComponent(q)}`);
        const data=await res.json();
        if(page===0){grid.innerHTML='';totalItems=data.total;statsDiv.innerHTML=`Всего манги: <strong>${totalItems}</strong>`}
        if(data.items.length===0&&page===0){grid.innerHTML='<div style="text-align:center;padding:60px">😔 Ничего не найдено</div>';moreBtn.style.display='none';hasMore=false;loading=false;return}
        data.items.forEach(m=>{
            const coverUrl=m.cover_display||'';
            grid.insertAdjacentHTML('beforeend',`<a class="card" href="/read/${m.id}">${coverUrl?`<img class="cover" src="${coverUrl}" loading="lazy">`:'<div class="cover" style="display:flex;align-items:center;justify-content:center"><span style="font-size:48px">📖</span></div>'}<div class="info"><div class="title">${escapeHtml(m.title)}</div><div class="likes">❤ ${m.likes}</div></div></a>`);
        });
        hasMore=(page+1)*data.limit<data.total;
        moreBtn.style.display=hasMore?'block':'none';
        page++;
    }catch(error){if(page===0)grid.innerHTML='<div style="text-align:center;padding:60px">❌ Ошибка загрузки</div>'}
    loading=false;
}
function escapeHtml(text){const div=document.createElement('div');div.textContent=text;return div.innerHTML}
let searchTimeout;
searchInput.addEventListener('input',e=>{clearTimeout(searchTimeout);searchTimeout=setTimeout(()=>{q=e.target.value.trim();load(true)},500)});
moreBtn.onclick=()=>load();
load();
</script>
</body>
</html>