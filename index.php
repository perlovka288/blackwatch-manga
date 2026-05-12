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
    $pdo = new PDO(
        $dsn,
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// Добавляем нужные колонки если их нет
try {
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga_pages ADD COLUMN IF NOT EXISTS page_url TEXT");
} catch (Exception $e) {}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

# =========================
# API MANGA (СПИСОК МАНГИ)
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
# API PAGES (СТРАНИЦЫ ДЛЯ РИДЕРА)
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
# API VOTE (ЛАЙК/ДИЗЛАЙК)
# =========================

if ($path === '/api/vote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $mangaId = (int)($input['manga_id'] ?? 0);
    $voteType = $input['vote_type'] ?? '';
    
    // Получаем или создаём user_id из сессии
    session_start();
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = rand(100000, 999999);
    }
    $userId = $_SESSION['user_id'];
    
    if ($mangaId && in_array($voteType, ['like', 'dislike'])) {
        // Проверяем существующий голос
        $check = $pdo->prepare("SELECT vote_type FROM votes WHERE user_id = ? AND manga_id = ?");
        $check->execute([$userId, $mangaId]);
        $existing = $check->fetch();
        
        if ($existing) {
            if ($existing['vote_type'] !== $voteType) {
                // Меняем голос
                $pdo->prepare("UPDATE votes SET vote_type = ? WHERE user_id = ? AND manga_id = ?")->execute([$voteType, $userId, $mangaId]);
                if ($voteType == 'like') {
                    $pdo->prepare("UPDATE manga SET likes = likes + 1, dislikes = dislikes - 1 WHERE id = ?")->execute([$mangaId]);
                } else {
                    $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1, likes = likes - 1 WHERE id = ?")->execute([$mangaId]);
                }
            }
        } else {
            // Новый голос
            $pdo->prepare("INSERT INTO votes (user_id, manga_id, vote_type) VALUES (?, ?, ?)")->execute([$userId, $mangaId, $voteType]);
            if ($voteType == 'like') {
                $pdo->prepare("UPDATE manga SET likes = likes + 1 WHERE id = ?")->execute([$mangaId]);
            } else {
                $pdo->prepare("UPDATE manga SET dislikes = dislikes + 1 WHERE id = ?")->execute([$mangaId]);
            }
        }
        
        // Получаем обновлённые данные
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
# API STATUS (ЧИТАЮ/ПРОЧИТАНО)
# =========================

if ($path === '/api/status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $mangaId = (int)($input['manga_id'] ?? 0);
    $status = $input['status'] ?? '';
    
    session_start();
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = rand(100000, 999999);
    }
    $userId = $_SESSION['user_id'];
    
    if ($mangaId && in_array($status, ['now', 'read'])) {
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON CONFLICT (user_id, manga_id) DO UPDATE SET status = EXCLUDED.status")->execute([$userId, $mangaId, $status]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

# =========================
# VIEWER (РИДЕР ДЛЯ ЧТЕНИЯ)
# =========================

if (preg_match('#^/view/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404'); }
    $title = htmlspecialchars($manga['title']);
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?> - Читать мангу онлайн</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#000;color:#fff;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
.reader{height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0a}
.reader img{max-width:100%;max-height:100vh;object-fit:contain}
.nav{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer;transition:background 0.2s}
.nav:hover{background:rgba(255,255,255,0.05)}
.prev{left:0}
.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.8);backdrop-filter:blur(10px);padding:8px 16px;border-radius:999px;z-index:100;font-size:14px;border:1px solid rgba(255,255,255,0.1)}
.back{position:fixed;top:20px;left:20px;z-index:100;color:#fff;text-decoration:none;background:rgba(0,0,0,0.6);backdrop-filter:blur(10px);padding:10px 18px;border-radius:30px;font-size:14px;transition:all 0.2s;border:1px solid rgba(255,255,255,0.1)}
.back:hover{background:rgba(255,255,255,0.2)}
.loading{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);font-size:18px;color:#7c5cff}
@media(max-width:768px){.counter{bottom:15px;padding:6px 12px;font-size:12px}.back{top:15px;left:15px;padding:8px 14px}}
</style>
</head>
<body>
<a href="/read/<?= $id ?>" class="back">← Назад</a>
<div class="counter"><span id="counter">1</span></div>
<div class="nav prev" onclick="prevPage()"></div>
<div class="nav next" onclick="nextPage()"></div>
<div class="reader"><div class="loading" id="loading">📖 Загрузка...</div><img id="page" style="display:none"></div>
<script>
let pages=[],current=0;
async function init(){
    const loading=document.getElementById('loading'),img=document.getElementById('page');
    try{
        const res=await fetch('/api/pages/<?= $id ?>'),data=await res.json();
        pages=data.pages;
        if(pages.length===0){loading.innerHTML='❌ Страницы не найдены';return}
        loading.style.display='none';img.style.display='block';render();
        if(pages.length>1){const preload=new Image();preload.src=pages[1]}
    }catch(error){loading.innerHTML='❌ Ошибка загрузки страниц'}
}
function render(){if(!pages[current])return;document.getElementById('page').src=pages[current];document.getElementById('counter').innerText=(current+1)+' / '+pages.length;if(current+1<pages.length){const preload=new Image();preload.src=pages[current+1]}}
function nextPage(){if(current<pages.length-1){current++;render();window.scrollTo({top:0,behavior:'smooth'})}}
function prevPage(){if(current>0){current--;render();window.scrollTo({top:0,behavior:'smooth'})}}
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight')nextPage();if(e.key==='ArrowLeft')prevPage();if(e.key==='Home'){current=0;render()}if(e.key==='End'){current=pages.length-1;render()}});
let touchStartX=0;
document.addEventListener('touchstart',e=>{touchStartX=e.changedTouches[0].screenX});
document.addEventListener('touchend',e=>{let endX=e.changedTouches[0].screenX;if(endX<touchStartX-50)nextPage();if(endX>touchStartX+50)prevPage()});
init();
</script>
</body>
</html>
<?php exit; }

# =========================
# MANGA PAGE (СТРАНИЦА МАНГИ) С КНОПКАМИ
# =========================

if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, telegraph_url, likes, dislikes FROM manga WHERE id=?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();
    if (!$manga) { http_response_code(404); die('404'); }
    $coverUrl = $manga['cover_imgbb_url'] ?? '';
    $telegraphUrl = $manga['telegraph_url'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($manga['title']) ?> - BLACKWATCH MANGA</title>
<style>
:root{--bg:#07070b;--card:#101018;--soft:#181824;--border:#26263a;--text:#f3f3f7;--muted:#8e8ea0;--accent:#7c5cff;--like:#ff6b6b;--dislike:#6b6b6b}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
.wrap{max-width:1100px;margin:auto;padding:40px 20px}
.back-link{display:inline-flex;align-items:center;gap:8px;color:var(--muted);text-decoration:none;margin-bottom:30px;transition:color 0.2s}
.back-link:hover{color:var(--accent)}
.box{display:flex;gap:40px;flex-wrap:wrap;background:var(--card);border-radius:28px;padding:30px;border:1px solid var(--border)}
.cover{width:280px;border-radius:20px;object-fit:cover;box-shadow:0 10px 30px rgba(0,0,0,0.3)}
.info{flex:1}
.title{font-size:42px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent)100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:16px}
.desc{color:var(--muted);line-height:1.7;margin-top:20px;font-size:16px}
.stats{display:flex;gap:20px;margin-top:20px;flex-wrap:wrap}
.likes-count,.dislikes-count{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:40px;font-size:16px}
.likes-count{background:rgba(255,107,107,0.15);color:var(--like)}
.dislikes-count{background:rgba(107,107,107,0.15);color:var(--dislike)}
.status-buttons{display:flex;gap:14px;margin-top:20px;flex-wrap:wrap}
.status-btn{padding:10px 20px;border-radius:30px;border:none;font-weight:600;cursor:pointer;transition:all 0.2s;background:var(--soft);color:var(--text)}
.status-btn.active{background:var(--accent);color:#fff}
.status-btn:hover{transform:translateY(-2px)}
.vote-buttons{display:flex;gap:14px;margin-top:20px;flex-wrap:wrap}
.vote-btn{padding:12px 24px;border-radius:40px;border:none;font-weight:600;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;gap:8px}
.vote-like{background:rgba(255,107,107,0.2);color:var(--like)}
.vote-like:hover{background:var(--like);color:#fff;transform:translateY(-2px)}
.vote-dislike{background:rgba(107,107,107,0.2);color:var(--dislike)}
.vote-dislike:hover{background:var(--dislike);color:#fff;transform:translateY(-2px)}
.read-buttons{display:flex;gap:14px;margin-top:20px;flex-wrap:wrap}
.btn{padding:14px 28px;border-radius:14px;text-decoration:none;font-weight:600;transition:all 0.2s;display:inline-flex;align-items:center;gap:8px}
.btn:hover{transform:translateY(-2px)}
.btn:active{transform:translateY(0)}
.primary{background:var(--accent);color:#fff;border:none}
.secondary{background:var(--soft);color:var(--text);border:1px solid var(--border)}
.secondary:hover{border-color:var(--accent);background:var(--card)}
@media(max-width:768px){.box{padding:20px;gap:25px}.cover{width:100%;max-width:250px;margin:0 auto}.title{font-size:28px;text-align:center}.desc{font-size:14px;text-align:center}.stats{justify-content:center}.status-buttons{justify-content:center}.vote-buttons{justify-content:center}.read-buttons{justify-content:center}.back-link{margin-bottom:20px}}
.toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.9);color:#fff;padding:12px 24px;border-radius:50px;z-index:1000;font-size:14px;animation:fadeOut 2s ease forwards}
@keyframes fadeOut{0%{opacity:1}70%{opacity:1}100%{opacity:0;visibility:hidden}}
</style>
</head>
<body>
<div class="wrap">
<a href="/" class="back-link">← Вернуться в каталог</a>
<div class="box">
<?php if(!empty($coverUrl)): ?>
<img class="cover" src="<?= htmlspecialchars($coverUrl) ?>" alt="<?= htmlspecialchars($manga['title']) ?>">
<?php else: ?>
<div class="cover" style="background:linear-gradient(135deg,#1a1a2e,#0a0a0a);display:flex;align-items:center;justify-content:center"><span style="font-size:64px">📖</span></div>
<?php endif; ?>
<div class="info">
<div class="title"><?= htmlspecialchars($manga['title']) ?></div>
<div class="desc"><?= nl2br(htmlspecialchars($manga['description'] ?? 'Описание отсутствует')) ?></div>
<div class="stats">
<div class="likes-count" id="likes-count">❤ <span id="likes"><?= (int)$manga['likes'] ?></span></div>
<div class="dislikes-count" id="dislikes-count">💔 <span id="dislikes"><?= (int)$manga['dislikes'] ?></span></div>
</div>
<div class="status-buttons">
<button class="status-btn" id="status-now" onclick="setStatus('now')">⏳ Читаю сейчас</button>
<button class="status-btn" id="status-read" onclick="setStatus('read')">✅ Прочитано</button>
</div>
<div class="vote-buttons">
<button class="vote-btn vote-like" onclick="vote('like')">👍 Лайк</button>
<button class="vote-btn vote-dislike" onclick="vote('dislike')">👎 Дизлайк</button>
</div>
<div class="read-buttons">
<a class="btn primary" href="/view/<?= $id ?>">📖 Читать онлайн</a>
<?php if(!empty($telegraphUrl)): ?>
<a class="btn secondary" target="_blank" href="<?= htmlspecialchars($telegraphUrl) ?>">📄 Читать в Telegraph</a>
<?php endif; ?>
</div>
</div>
</div>
</div>
<script>
let currentStatus = null;
async function vote(type){
    try{
        const res=await fetch('/api/vote',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({manga_id:<?= $id ?>,vote_type:type})
        });
        const data=await res.json();
        if(data.success){
            document.getElementById('likes').innerText=data.likes;
            document.getElementById('dislikes').innerText=data.dislikes;
            showToast(type=='like'?'👍 Лайк учтён!':'👎 Дизлайк учтён!');
        }
    }catch(e){console.error(e)}
}
async function setStatus(status){
    try{
        const res=await fetch('/api/status',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({manga_id:<?= $id ?>,status:status})
        });
        const data=await res.json();
        if(data.success){
            document.querySelectorAll('.status-btn').forEach(btn=>btn.classList.remove('active'));
            document.getElementById(`status-${status}`).classList.add('active');
            currentStatus=status;
            showToast(status=='now'?'📖 Добавлено в "Читаю сейчас"!':'✅ Добавлено в "Прочитано"!');
        }
    }catch(e){console.error(e)}
}
function showToast(msg){
    let toast=document.createElement('div');
    toast.className='toast';
    toast.innerText=msg;
    document.body.appendChild(toast);
    setTimeout(()=>toast.remove(),2000);
}
</script>
</body>
</html>
<?php exit; }

# =========================
# HOME (КАТАЛОГ - ГЛАВНАЯ)
# =========================

$total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BLACKWATCH | Manga Reader - Читай мангу онлайн бесплатно</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#07070b;--card:#101018;--soft:#181824;--border:#26263a;--text:#f3f3f7;--muted:#8e8ea0;--accent:#7c5cff;--accent-glow:rgba(124,92,255,0.2)}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',-apple-system,sans-serif}
header{position:sticky;top:0;z-index:100;backdrop-filter:blur(20px);background:rgba(7,7,11,0.8);border-bottom:1px solid var(--border);padding:20px}
.logo{font-size:28px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent)100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;letter-spacing:-0.5px}
.logo span{background:none;-webkit-text-fill-color:var(--accent)}
.search{width:100%;max-width:500px;margin-top:20px;padding:14px 20px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:#fff;font-size:16px;transition:all 0.2s}
.search:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow)}
.search::placeholder{color:var(--muted)}
.wrap{max-width:1400px;margin:auto;padding:30px 20px}
.stats{color:var(--muted);font-size:14px;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:24px;margin-top:10px}
.card{background:linear-gradient(180deg,#131320,#0d0d16);border:1px solid var(--border);border-radius:20px;overflow:hidden;text-decoration:none;color:#fff;transition:all 0.3s ease;cursor:pointer}
.card:hover{transform:translateY(-6px);border-color:var(--accent);box-shadow:0 10px 30px rgba(124,92,255,0.2)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.info{padding:14px}
.title{font-size:14px;font-weight:600;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.likes{margin-top:8px;color:#ffd166;font-size:12px;display:flex;align-items:center;gap:4px}
.load-more{margin:50px auto 20px;display:block;padding:14px 32px;border:none;border-radius:50px;background:var(--accent);color:#fff;font-weight:600;cursor:pointer;transition:all 0.2s}
.load-more:hover{transform:translateY(-2px);box-shadow:0 5px 20px rgba(124,92,255,0.4)}
.loading-state{text-align:center;padding:60px;color:var(--muted)}
.empty-state{text-align:center;padding:80px 20px;color:var(--muted)}
.empty-state h3{font-size:24px;margin-bottom:10px;color:var(--text)}
@media(max-width:768px){header{padding:15px}.logo{font-size:22px}.search{margin-top:15px;padding:12px 16px;font-size:14px}.wrap{padding:20px 15px}.grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:15px}.title{font-size:12px}.stats{font-size:12px}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.card{animation:fadeInUp 0.4s ease forwards}
</style>
</head>
<body>
<header>
<div class="logo">BLACKWATCH<span>MANGA</span></div>
<input id="search" class="search" placeholder="🔍 Поиск манги по названию..." autocomplete="off">
</header>
<div class="wrap">
<div class="stats" id="stats">Всего манги: <strong><?= $total ?></strong></div>
<div class="grid" id="grid"></div>
<button class="load-more" id="more" style="display:none">Загрузить ещё</button>
</div>
<script>
let page=0,q='',loading=false,hasMore=true,totalItems=<?= $total ?>;
const grid=document.getElementById('grid'),moreBtn=document.getElementById('more'),searchInput=document.getElementById('search'),statsDiv=document.getElementById('stats');
async function load(reset=false){
    if(loading)return;
    loading=true;
    if(reset){page=0;grid.innerHTML='';hasMore=true}
    if(page===0&&grid.children.length===0)grid.innerHTML='<div class="loading-state">📖 Загрузка манги...</div>';
    try{
        const res=await fetch(`/api/manga?page=${page}&q=${encodeURIComponent(q)}`);
        const data=await res.json();
        if(page===0){grid.innerHTML='';totalItems=data.total;statsDiv.innerHTML=`Всего манги: <strong>${totalItems}</strong>`}
        if(data.items.length===0&&page===0){grid.innerHTML='<div class="empty-state"><h3>😔 Ничего не найдено</h3><p>Попробуй изменить поисковый запрос</p></div>';moreBtn.style.display='none';hasMore=false;loading=false;return}
        data.items.forEach(m=>{
            const coverUrl=m.cover_display||'';
            grid.insertAdjacentHTML('beforeend',`
                <a class="card" href="/read/${m.id}">
                    ${coverUrl?`<img class="cover" src="${coverUrl}" loading="lazy" alt="${escapeHtml(m.title)}" onerror="this.src=''">`:'<div class="cover" style="display:flex;align-items:center;justify-content:center"><span style="font-size:48px">📖</span></div>'}
                    <div class="info"><div class="title">${escapeHtml(m.title)}</div><div class="likes">❤ ${m.likes}</div></div>
                </a>
            `);
        });
        hasMore=(page+1)*data.limit<data.total;
        moreBtn.style.display=hasMore?'block':'none';
        page++;
    }catch(error){if(page===0)grid.innerHTML='<div class="empty-state"><h3>❌ Ошибка загрузки</h3><p>Пожалуйста, обнови страницу</p></div>'}
    loading=false;
}
function escapeHtml(text){const div=document.createElement('div');div.textContent=text;return div.innerHTML}
let searchTimeout;
searchInput.addEventListener('input',e=>{clearTimeout(searchTimeout);searchTimeout=setTimeout(()=>{q=e.target.value.trim();load(true)},500)});
moreBtn.onclick=()=>load();
load();
let scrollTimeout;
window.addEventListener('scroll',()=>{if(scrollTimeout)clearTimeout(scrollTimeout);scrollTimeout=setTimeout(()=>{if(!hasMore||loading)return;if(window.innerHeight+window.scrollY>=document.body.offsetHeight-500)load()},200)});
</script>
</body>
</html>