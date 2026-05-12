<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

# =========================
# DB
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

    die($e->getMessage());
}

// Проверяем и добавляем нужные колонки если их нет
try {
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga_pages ADD COLUMN IF NOT EXISTS page_url TEXT");
} catch (PDOException $e) {
    // Колонки уже есть или ошибка - игнорируем
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

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

        $stmt = $pdo->prepare("
            SELECT *
            FROM manga
            WHERE LOWER(title) LIKE LOWER(?)
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([
            "%{$q}%",
            $limit,
            $offset
        ]);

        $count = $pdo->prepare("
            SELECT COUNT(*)
            FROM manga
            WHERE LOWER(title) LIKE LOWER(?)
        ");

        $count->execute(["%{$q}%"]);

    } else {

        $stmt = $pdo->prepare("
            SELECT *
            FROM manga
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([$limit, $offset]);

        $count = $pdo->query("SELECT COUNT(*) FROM manga");
    }

    $items = [];

    foreach ($stmt as $m) {

        $items[] = [
            'id' => (int)$m['id'],
            'title' => $m['title'],
            'likes' => (int)$m['likes'],
            'cover_display' => $m['cover_imgbb_url'] ?? null
        ];
    }

    echo json_encode([
        'items' => $items,
        'total' => (int)$count->fetchColumn(),
        'limit' => $limit
    ]);

    exit;
}

# =========================
# API PAGES
# =========================

if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {

    header('Content-Type: application/json');

    $id = (int)$m[1];

    // Проверяем есть ли колонка page_url
    try {
        $stmt = $pdo->prepare("
            SELECT page_url
            FROM manga_pages
            WHERE manga_id=?
            ORDER BY page_order
        ");
        $stmt->execute([$id]);
        $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $pages = [];
    }

    echo json_encode([
        'pages' => $pages
    ]);

    exit;
}

# =========================
# VIEWER (РИДЕР)
# =========================

if (preg_match('#^/view/(\d+)$#', $path, $m)) {

    $id = (int)$m[1];

    $stmt = $pdo->prepare("
        SELECT *
        FROM manga
        WHERE id=?
    ");

    $stmt->execute([$id]);

    $manga = $stmt->fetch();

    if (!$manga) {
        http_response_code(404);
        die('404');
    }

    $title = htmlspecialchars($manga['title']);

?>

<!DOCTYPE html>
<html lang="ru">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title><?= $title ?></title>

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body{
    margin:0;
    background:#000;
    color:#fff;
    overflow:hidden;
    font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
}

.reader{
    height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#0a0a0a;
}

.reader img{
    max-width:100%;
    max-height:100vh;
    object-fit:contain;
}

.nav{
    position:fixed;
    top:0;
    width:50%;
    height:100%;
    z-index:10;
    cursor:pointer;
    transition:background 0.2s;
}

.nav:hover{
    background:rgba(255,255,255,0.05);
}

.prev{
    left:0;
}

.next{
    right:0;
}

.counter{
    position:fixed;
    bottom:20px;
    left:50%;
    transform:translateX(-50%);
    background:rgba(0,0,0,0.8);
    backdrop-filter:blur(10px);
    padding:8px 16px;
    border-radius:999px;
    z-index:100;
    font-size:14px;
    font-weight:500;
    letter-spacing:0.5px;
    border:1px solid rgba(255,255,255,0.1);
}

.back{
    position:fixed;
    top:20px;
    left:20px;
    z-index:100;
    color:#fff;
    text-decoration:none;
    background:rgba(0,0,0,0.6);
    backdrop-filter:blur(10px);
    padding:10px 18px;
    border-radius:30px;
    font-size:14px;
    transition:all 0.2s;
    border:1px solid rgba(255,255,255,0.1);
}

.back:hover{
    background:rgba(255,255,255,0.2);
}

.loading{
    position:fixed;
    top:50%;
    left:50%;
    transform:translate(-50%,-50%);
    font-size:18px;
    color:#7c5cff;
}

@media (max-width: 768px) {
    .counter {
        bottom: 15px;
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .back {
        top: 15px;
        left: 15px;
        padding: 8px 14px;
        font-size: 12px;
    }
}

</style>

</head>

<body>

<a href="/read/<?= $id ?>" class="back">← Назад к манге</a>

<div class="counter">
    <span id="counter">1</span>
</div>

<div class="nav prev" onclick="prevPage()"></div>
<div class="nav next" onclick="nextPage()"></div>

<div class="reader">
    <div class="loading" id="loading">📖 Загрузка...</div>
    <img id="page" style="display:none">
</div>

<script>

let pages = [];
let current = 0;

async function init(){

    const loading = document.getElementById('loading');
    const img = document.getElementById('page');
    
    try {
        const res = await fetch('/api/pages/<?= $id ?>');
        const data = await res.json();

        pages = data.pages;
        
        if (pages.length === 0) {
            loading.innerHTML = '❌ Страницы не найдены';
            return;
        }
        
        loading.style.display = 'none';
        img.style.display = 'block';
        
        render();
        
        if (pages.length > 1) {
            const preload = new Image();
            preload.src = pages[1];
        }
        
    } catch (error) {
        loading.innerHTML = '❌ Ошибка загрузки страниц';
        console.error(error);
    }
}

function render(){

    if(!pages[current]) return;

    const img = document.getElementById('page');
    img.src = pages[current];
    
    document.getElementById('counter').innerText =
        (current + 1) + ' / ' + pages.length;
        
    if (current + 1 < pages.length) {
        const preload = new Image();
        preload.src = pages[current + 1];
    }
}

function nextPage(){

    if(current < pages.length - 1){

        current++;
        render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function prevPage(){

    if(current > 0){

        current--;
        render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

document.addEventListener('keydown', e => {

    if(e.key === 'ArrowRight') nextPage();
    if(e.key === 'ArrowLeft') prevPage();
    if(e.key === 'Home') {
        current = 0;
        render();
    }
    if(e.key === 'End') {
        current = pages.length - 1;
        render();
    }
});

let touchStartX = 0;
let touchEndX = 0;

document.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].screenX;
});

document.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    if (touchEndX < touchStartX - 50) nextPage();
    if (touchEndX > touchStartX + 50) prevPage();
});

init();

</script>

</body>
</html>

<?php
exit;
}

# =========================
# MANGA PAGE (СТРАНИЦА МАНГИ)
# =========================

if (preg_match('#^/read/(\d+)$#', $path, $m)) {

    $id = (int)$m[1];

    // Получаем данные манги с проверкой на существование колонок
    try {
        // Пробуем получить telegraph_url
        $stmt = $pdo->prepare("
            SELECT id, title, description, cover_imgbb_url, telegraph_url, likes, file_id
            FROM manga
            WHERE id=?
        ");
        $stmt->execute([$id]);
        $manga = $stmt->fetch();
    } catch (PDOException $e) {
        // Если колонки нет, пробуем без них
        $stmt = $pdo->prepare("
            SELECT id, title, description, cover_imgbb_url, likes, file_id
            FROM manga
            WHERE id=?
        ");
        $stmt->execute([$id]);
        $manga = $stmt->fetch();
        $manga['telegraph_url'] = $manga['file_id'] ?? null;
    }

    if (!$manga) {
        http_response_code(404);
        die('404');
    }
    
    // Определяем URL для чтения
    $readUrl = !empty($manga['telegraph_url']) ? $manga['telegraph_url'] : ($manga['file_id'] ?? '#');
    $coverUrl = $manga['cover_imgbb_url'] ?? '';

?>

<!DOCTYPE html>
<html lang="ru">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title><?= htmlspecialchars($manga['title']) ?></title>

<style>

:root{
 --bg:#07070b;
 --card:#101018;
 --soft:#181824;
 --border:#26263a;
 --text:#f3f3f7;
 --muted:#8e8ea0;
 --accent:#7c5cff;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
}

.wrap{
    max-width:1100px;
    margin:auto;
    padding:40px 20px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color:var(--muted);
    text-decoration:none;
    margin-bottom: 30px;
    transition: color 0.2s;
}

.back-link:hover {
    color:var(--accent);
}

.box{
    display:flex;
    gap:40px;
    flex-wrap:wrap;
    background:var(--card);
    border-radius: 28px;
    padding: 30px;
    border: 1px solid var(--border);
}

.cover{
    width:280px;
    border-radius:20px;
    object-fit:cover;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.info{
    flex:1;
}

.title{
    font-size:42px;
    font-weight:800;
    background: linear-gradient(135deg, #fff 0%, var(--accent) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 16px;
}

.desc{
    color:var(--muted);
    line-height:1.7;
    margin-top:20px;
    font-size: 16px;
}

.likes{
    margin-top:20px;
    font-size: 18px;
    color: #ffd166;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,209,102,0.1);
    padding: 8px 16px;
    border-radius: 40px;
}

.buttons{
    display:flex;
    gap:14px;
    margin-top:30px;
    flex-wrap:wrap;
}

.btn{
    padding:14px 28px;
    border-radius:14px;
    text-decoration:none;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn:hover {
    transform: translateY(-2px);
}

.btn:active {
    transform: translateY(0);
}

.primary{
    background:var(--accent);
    color:#fff;
    border: none;
}

.secondary{
    background:var(--soft);
    color:var(--text);
    border:1px solid var(--border);
}

.secondary:hover {
    border-color: var(--accent);
    background: var(--card);
}

@media (max-width: 768px){
    .box{
        padding: 20px;
        gap: 25px;
    }
    
    .cover{
        width: 100%;
        max-width: 250px;
        margin: 0 auto;
    }
    
    .title{
        font-size: 28px;
        text-align: center;
    }
    
    .desc{
        font-size: 14px;
        text-align: center;
    }
    
    .likes{
        justify-content: center;
    }
    
    .buttons{
        justify-content: center;
    }
    
    .back-link {
        margin-bottom: 20px;
    }
}

</style>

</head>

<body>

<div class="wrap">

    <a href="/" class="back-link">
        ← Вернуться в каталог
    </a>

    <div class="box">

        <?php if (!empty($coverUrl)): ?>
            <img class="cover" src="<?= htmlspecialchars($coverUrl) ?>" alt="<?= htmlspecialchars($manga['title']) ?>">
        <?php else: ?>
            <div class="cover" style="background: linear-gradient(135deg, #1a1a2e 0%, #0a0a0a 100%); display: flex; align-items: center; justify-content: center;">
                <span style="font-size: 64px;">📖</span>
            </div>
        <?php endif; ?>

        <div class="info">

            <div class="title">
                <?= htmlspecialchars($manga['title']) ?>
            </div>

            <div class="desc">
                <?= nl2br(htmlspecialchars($manga['description'] ?? 'Описание отсутствует')) ?>
            </div>

            <div class="likes">
                ❤ <?= (int)$manga['likes'] ?> лайков
            </div>

            <div class="buttons">

                <a class="btn primary" href="/view/<?= $id ?>">
                    📖 Читать онлайн
                </a>

                <?php if (!empty($readUrl) && $readUrl !== '#'): ?>
                    <a class="btn secondary" target="_blank" href="<?= htmlspecialchars($readUrl) ?>">
                        📄 Читать в Telegraph
                    </a>
                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

</body>
</html>

<?php
exit;
}

# =========================
# HOME (ГЛАВНАЯ - КАТАЛОГ)
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

:root{
 --bg:#07070b;
 --card:#101018;
 --soft:#181824;
 --border:#26263a;
 --text:#f3f3f7;
 --muted:#8e8ea0;
 --accent:#7c5cff;
 --accent-glow:rgba(124,92,255,0.2);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
}

header{
    position:sticky;
    top:0;
    z-index:100;
    backdrop-filter:blur(20px);
    background:rgba(7,7,11,0.8);
    border-bottom:1px solid var(--border);
    padding:20px;
}

.logo{
    font-size:28px;
    font-weight:800;
    background: linear-gradient(135deg, #fff 0%, var(--accent) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: -0.5px;
}

.search{
    width:100%;
    max-width: 500px;
    margin-top:20px;
    padding:14px 20px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:50px;
    color:#fff;
    font-size: 16px;
    transition: all 0.2s;
}

.search:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
}

.search::placeholder {
    color: var(--muted);
}

.wrap{
    max-width:1400px;
    margin:auto;
    padding:30px 20px;
}

.stats {
    color: var(--muted);
    font-size: 14px;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
    gap:24px;
    margin-top:10px;
}

.card{
    background:linear-gradient(180deg,#131320,#0d0d16);
    border:1px solid var(--border);
    border-radius:20px;
    overflow:hidden;
    text-decoration:none;
    color:#fff;
    transition:all 0.3s ease;
    cursor: pointer;
}

.card:hover{
    transform:translateY(-6px);
    border-color:var(--accent);
    box-shadow: 0 10px 30px rgba(124,92,255,0.2);
}

.cover{
    width:100%;
    aspect-ratio:2/3;
    object-fit:cover;
    background: linear-gradient(135deg, #1a1a2e 0%, #0a0a0a 100%);
}

.info{
    padding:14px;
}

.title{
    font-size:14px;
    font-weight:600;
    line-height:1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.likes{
    margin-top:8px;
    color:#ffd166;
    font-size:12px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.load-more{
    margin:50px auto 20px;
    display:block;
    padding:14px 32px;
    border:none;
    border-radius:50px;
    background:var(--accent);
    color:#fff;
    font-weight:600;
    cursor:pointer;
    transition:all 0.2s;
}

.load-more:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(124,92,255,0.4);
}

.load-more:active {
    transform: translateY(0);
}

.loading-state {
    text-align: center;
    padding: 60px;
    color: var(--muted);
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--muted);
}

.empty-state h3 {
    font-size: 24px;
    margin-bottom: 10px;
    color: var(--text);
}

@media (max-width: 768px){
    header {
        padding: 15px;
    }
    
    .logo {
        font-size: 22px;
    }
    
    .search {
        margin-top: 15px;
        padding: 12px 16px;
        font-size: 14px;
    }
    
    .wrap {
        padding: 20px 15px;
    }
    
    .grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 15px;
    }
    
    .title {
        font-size: 12px;
    }
    
    .stats {
        font-size: 12px;
        margin-bottom: 15px;
    }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card {
    animation: fadeInUp 0.4s ease forwards;
}

</style>

</head>

<body>

<header>

    <div class="logo">
        BLACKWATCH<span style="background: none; -webkit-text-fill-color: var(--accent);">MANGA</span>
    </div>

    <input
        id="search"
        class="search"
        placeholder="🔍 Поиск манги по названию..."
        autocomplete="off"
    >

</header>

<div class="wrap">

    <div class="stats" id="stats">
        Всего манги: <strong><?= $total ?></strong>
    </div>

    <div class="grid" id="grid"></div>

    <button class="load-more" id="more" style="display: none;">
        Загрузить ещё
    </button>

</div>

<script>

let page = 0;
let q = '';
let loading = false;
let hasMore = true;
let totalItems = <?= $total ?>;

const grid = document.getElementById('grid');
const moreBtn = document.getElementById('more');
const searchInput = document.getElementById('search');
const statsDiv = document.getElementById('stats');

async function load(reset = false){

    if(loading) return;

    loading = true;

    if(reset){
        page = 0;
        grid.innerHTML = '';
        hasMore = true;
    }

    if (page === 0 && grid.children.length === 0) {
        grid.innerHTML = '<div class="loading-state">📖 Загрузка манги...</div>';
    }

    try {
        const res = await fetch(
            `/api/manga?page=${page}&q=${encodeURIComponent(q)}`
        );

        const data = await res.json();

        if (page === 0) {
            grid.innerHTML = '';
            totalItems = data.total;
            statsDiv.innerHTML = `Всего манги: <strong>${totalItems}</strong>`;
        }

        if (data.items.length === 0 && page === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <h3>😔 Ничего не найдено</h3>
                    <p>Попробуй изменить поисковый запрос</p>
                </div>
            `;
            moreBtn.style.display = 'none';
            hasMore = false;
            loading = false;
            return;
        }

        data.items.forEach(m => {

            const coverUrl = m.cover_display || '';

            grid.insertAdjacentHTML('beforeend', `

                <a class="card" href="/read/${m.id}">

                    ${coverUrl ? 
                        `<img class="cover" src="${coverUrl}" loading="lazy" alt="${escapeHtml(m.title)}" onerror="this.src=''">` :
                        `<div class="cover" style="display: flex; align-items: center; justify-content: center;"><span style="font-size: 48px;">📖</span></div>`
                    }

                    <div class="info">

                        <div class="title">
                            ${escapeHtml(m.title)}
                        </div>

                        <div class="likes">
                            ❤ ${m.likes}
                        </div>

                    </div>

                </a>

            `);
        });

        hasMore = (page + 1) * data.limit < data.total;

        moreBtn.style.display = hasMore ? 'block' : 'none';

        page++;

    } catch (error) {
        console.error('Error loading manga:', error);
        if (page === 0) {
            grid.innerHTML = '<div class="empty-state"><h3>❌ Ошибка загрузки</h3><p>Пожалуйста, обнови страницу</p></div>';
        }
    }

    loading = false;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

let searchTimeout;
searchInput.addEventListener('input', e => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        q = e.target.value.trim();
        load(true);
    }, 500);
});

moreBtn.onclick = () => load();

load();

let scrollTimeout;
window.addEventListener('scroll', () => {
    if (scrollTimeout) clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(() => {
        if (!hasMore || loading) return;
        
        const scrollPosition = window.innerHeight + window.scrollY;
        const threshold = document.body.offsetHeight - 500;
        
        if (scrollPosition >= threshold) {
            load();
        }
    }, 200);
});

</script>

</body>
</html>