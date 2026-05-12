<?php
// =============================================
// MANGABOT — Сайт-каталог
// Один файл: каталог + поиск + ридер
<<<<<<< HEAD
// Render.com + Neon PostgreSQL
// =============================================

=======
// Render.com + Railway MySQL
// =============================================

// ENV переменные (те же что у бота)
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
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
    die("DB Error: " . $e->getMessage());
}

<<<<<<< HEAD
$botUsername = getenv('BOT_USERNAME') ?: 'Manga123Manga123bot';
$siteUrl     = rtrim(getenv('SITE_URL') ?: 'https://blackwatch-manga.onrender.com', '/');
=======
$botUsername = getenv('BOT_USERNAME') ?: 'Manga123Manga123bot'; // установите в ENV
$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://blackwatch-manga.onrender.com', '/');
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59

// =============================================
// РОУТИНГ
// =============================================
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
<<<<<<< HEAD
$path       = parse_url($requestUri, PHP_URL_PATH);
$path       = rtrim($path, '/') ?: '/';

=======
$path = parse_url($requestUri, PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';


if (isset($_GET['ajax'])) {
    $offset = (int)$_GET['offset'];
    $stmt = $pdo->prepare("SELECT * FROM manga ORDER BY id DESC LIMIT 12 OFFSET ?");
    $stmt->execute([$offset]);
    $mangas = $stmt->fetchAll();

    foreach ($mangas as $m) {
        echo '
        <a href="/read/'.$m['id'].'" class="manga-card">
            <img src="'.$m['cover_id'].'" class="manga-cover">
            <div class="manga-info">
                <div class="manga-title">'.htmlspecialchars($m['title']).'</div>
                <div class="manga-likes">❤️ '.$m['likes'].'</div>
            </div>
        </a>';
    }
    exit; // Важно остановить выполнение, чтобы не грузить весь сайт целиком
}
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
// /read/123 — ридер манги
if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $mangaId = (int)$m[1];
    renderReader($pdo, $mangaId, $siteUrl, $botUsername);
    exit;
}

<<<<<<< HEAD
=======
// /api/manga — JSON для поиска (AJAX)
if ($path === '/api/manga') {
    header('Content-Type: application/json; charset=utf-8');
    $q    = trim($_GET['q'] ?? '');
    $page = max(0, (int)($_GET['page'] ?? 0));
    $limit = 12;
    $offset = $page * $limit;

 if ($q) {
    // Подсчет общего количества с фильтром
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title ILIKE ?");
    $totalStmt->execute(["%$q%"]);
    $total = $totalStmt->fetchColumn();

    // Запрос данных. Используем ILIKE для поиска без учета регистра (фишка Postgres)
    // Я добавил поиск по cover_id и принудительно привел LIMIT/OFFSET к INT
    $stmt = $pdo->prepare("
        SELECT id, title, description, cover_id, likes, file_id 
        FROM manga 
        WHERE title ILIKE ? 
        ORDER BY id DESC 
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset
    );
    $stmt->execute(["%$q%"]);
} else {
    // Подсчет всех записей
    $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();

    // Запрос всех данных
    $stmt = $pdo->prepare("
        SELECT id, title, description, cover_id, likes, file_id 
        FROM manga 
        ORDER BY id DESC 
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset
    );
    $stmt->execute();
}
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
// /api/pages/123 — страницы манги для ридера (AJAX)
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json; charset=utf-8');
    $mangaId = (int)$m[1];
<<<<<<< HEAD
    $stmt    = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt->execute([$mangaId]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
=======
    $stmt = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt->execute([$mangaId]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Если нет в manga_pages, но есть telegra.ph ссылка — редиректим туда
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    if (empty($pages)) {
        $s = $pdo->prepare("SELECT file_id FROM manga WHERE id = ?");
        $s->execute([$mangaId]);
        $row = $s->fetch();
        echo json_encode(['pages' => [], 'telegraph' => $row['file_id'] ?? null]);
    } else {
        echo json_encode(['pages' => $pages, 'telegraph' => null]);
    }
    exit;
}

<<<<<<< HEAD
// /api/manga — JSON для поиска (AJAX)
if ($path === '/api/manga') {
    header('Content-Type: application/json; charset=utf-8');
    $q      = trim($_GET['q'] ?? '');
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $limit  = 12;
    $offset = $page * $limit;

    if ($q) {
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title ILIKE ?");
        $totalStmt->execute(["%$q%"]);
        $total = $totalStmt->fetchColumn();
        $stmt  = $pdo->prepare("
            SELECT id, title, description, cover_id, cover_imgbb_url, likes, file_id
            FROM manga
            WHERE title ILIKE ?
            ORDER BY id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
        $stmt->execute(["%$q%"]);
    } else {
        $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $stmt  = $pdo->prepare("
            SELECT id, title, description, cover_id, cover_imgbb_url, likes, file_id
            FROM manga
            ORDER BY id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
        $stmt->execute();
    }

    $items = $stmt->fetchAll();

    // Используем cover_imgbb_url если есть, иначе cover_id (если это http-ссылка)
    foreach ($items as &$item) {
        if (!empty($item['cover_imgbb_url'])) {
            $item['cover_display'] = $item['cover_imgbb_url'];
        } elseif (!empty($item['cover_id']) && str_starts_with($item['cover_id'], 'http')) {
            $item['cover_display'] = $item['cover_id'];
        } else {
            $item['cover_display'] = null;
        }
    }
    unset($item);

    echo json_encode([
        'items' => $items,
        'total' => (int)$total,
        'page'  => $page,
        'limit' => $limit,
    ]);
    exit;
}

=======
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
// Главная страница
renderCatalog($pdo, $siteUrl, $botUsername);

// =============================================
// РЕНДЕР КАТАЛОГА
// =============================================
function renderCatalog($pdo, $siteUrl, $botUsername) {
<<<<<<< HEAD
    $topManga = $pdo->query("
        SELECT id, title, description, cover_id, cover_imgbb_url, likes
        FROM manga
        WHERE likes > 0
        ORDER BY likes DESC
        LIMIT 5
    ")->fetchAll();
    $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
?>
=======
    // Топ-5 по лайкам для баннера
    $topManga = $pdo->query("SELECT id, title, description, cover_id, likes FROM manga WHERE likes > 0 ORDER BY likes DESC LIMIT 5")->fetchAll();
    // Последние 12 для первоначальной загрузки
    $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
?>
Чтобы сайт выглядел именно так, как ты хочешь (с огромным заголовком BLACKWATCH по центру и синей подписью МАНГА), вот тебе полный код блока от начала документа до конца шапки.

Просто удали всё, что у тебя идет от <!DOCTYPE html> до начала поиска, и вставь этот кусок:

HTML
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLACKWATCH — Каталог манги</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<<<<<<< HEAD
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
=======
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Noto+Sans+JP:wght@400;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    <style>
      :root {
        --bg: #0a0a0f;
        --bg2: #111118;
        --bg3: #1a1a24;
<<<<<<< HEAD
        --accent: #3b82f6;
=======
        --accent: #3b82f6; /* Тот самый синий цвет */
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
        --accent2: #60a5fa;
        --gold: #ffd166;
        --text: #f0f0f5;
        --muted: #7a7a9a;
        --border: #2a2a3a;
        --card-shadow: 0 8px 32px rgba(0,0,0,0.5);
      }
<<<<<<< HEAD
      * { margin: 0; padding: 0; box-sizing: border-box; }
=======

      * { margin: 0; padding: 0; box-sizing: border-box; }

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
      body {
        background: var(--bg);
        color: var(--text);
        font-family: 'Inter', sans-serif;
        min-height: 100vh;
        overflow-x: hidden;
      }

<<<<<<< HEAD
      /* ШАПКА */
      .site-header {
        position: sticky;
        top: 0;
        z-index: 100;
        background: rgba(10,10,15,0.95);
        backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--border);
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
      }
      .header-logo {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 26px;
        letter-spacing: 3px;
        color: var(--text);
        text-decoration: none;
        flex-shrink: 0;
      }
      .header-logo span {
        color: var(--accent);
        font-size: 13px;
        letter-spacing: 2px;
        display: block;
        margin-top: -6px;
      }
      .search-box {
        flex: 1;
        display: flex;
        align-items: center;
        background: var(--bg2);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 0 14px;
        gap: 8px;
        transition: border-color .2s;
        max-width: 500px;
      }
      .search-box:focus-within { border-color: var(--accent); }
      .search-box svg { color: var(--muted); flex-shrink: 0; }
      .search-box input {
        flex: 1;
        background: none;
        border: none;
        outline: none;
        color: var(--text);
        font-size: 14px;
        font-family: inherit;
        padding: 10px 0;
      }
      .search-box input::placeholder { color: var(--muted); }
      .search-clear {
        background: none;
        border: none;
        color: var(--muted);
        cursor: pointer;
        padding: 4px;
        display: none;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: color .2s;
      }
      .search-clear:hover { color: var(--text); }

      /* БРЕНД-ГЕРОЙ */
      .brand-hero {
        text-align: center;
        padding: 48px 20px 32px;
      }
      .brand-hero h1 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: clamp(60px, 12vw, 120px);
        letter-spacing: 8px;
        color: var(--text);
        line-height: 1;
      }
      .brand-hero span {
        display: block;
        color: var(--accent);
        font-size: 14px;
        letter-spacing: 6px;
        text-transform: uppercase;
        margin-top: 4px;
        font-weight: 500;
      }

      /* HERO SLIDER */
      .hero-section {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px 32px;
      }
      .section-label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: var(--muted);
        margin-bottom: 16px;
      }
      .hero-slider {
        display: flex;
        gap: 16px;
        overflow-x: auto;
        scrollbar-width: none;
        padding-bottom: 4px;
      }
      .hero-slider::-webkit-scrollbar { display: none; }
      .hero-card {
        flex-shrink: 0;
        width: 200px;
        height: 280px;
        border-radius: 14px;
        overflow: hidden;
        position: relative;
        text-decoration: none;
        display: block;
        border: 1px solid var(--border);
        transition: transform .2s, box-shadow .2s;
      }
      .hero-card:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(0,0,0,0.6); }
      .hero-card-bg { width: 100%; height: 100%; background: var(--bg3); }
      .hero-card-img { width: 100%; height: 100%; object-fit: cover; }
      .hero-card-overlay {
        position: absolute;
        bottom: 0; left: 0; right: 0;
        background: linear-gradient(transparent, rgba(0,0,0,0.9));
        padding: 32px 12px 12px;
      }
      .hero-card-title {
        font-size: 13px;
        font-weight: 600;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .hero-card-likes {
        font-size: 11px;
        color: var(--gold);
        margin-top: 4px;
      }

      /* КАТАЛОГ */
      .catalog-section {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px 60px;
      }
      .catalog-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
      }
      .total-count { font-size: 13px; color: var(--muted); }
      .total-count strong { color: var(--text); }

      /* СЕТКА КАРТОЧЕК */
      .manga-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 16px;
      }
      @media(min-width: 600px) { .manga-grid { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); } }
      @media(min-width: 900px) { .manga-grid { grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); } }

      .manga-card {
        display: flex;
        flex-direction: column;
        background: var(--bg2);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        text-decoration: none;
        color: var(--text);
        transition: transform .2s, box-shadow .2s, border-color .2s;
      }
      .manga-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--card-shadow);
        border-color: var(--accent);
      }
      .manga-cover {
        width: 100%;
        aspect-ratio: 2/3;
        object-fit: cover;
        display: block;
        background: var(--bg3);
      }
      .manga-cover-placeholder {
        width: 100%;
        aspect-ratio: 2/3;
        background: var(--bg3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        color: var(--muted);
      }
      .manga-info {
        padding: 10px 12px 12px;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 4px;
      }
      .manga-title {
        font-size: 13px;
        font-weight: 600;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }
      .manga-likes {
        font-size: 11px;
        color: var(--gold);
        margin-top: auto;
      }

      /* СКЕЛЕТОН */
      .skeleton-card {
        background: var(--bg2);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
      }
      .skeleton-cover {
        width: 100%;
        aspect-ratio: 2/3;
        background: var(--bg3);
        animation: shimmer 1.4s infinite;
      }
      .skeleton-text {
        height: 12px;
        background: var(--bg3);
        border-radius: 6px;
        margin: 10px 12px 6px;
        animation: shimmer 1.4s infinite;
      }
      .skeleton-text.short { width: 50%; margin-bottom: 12px; }
      @keyframes shimmer {
        0%   { opacity: .5; }
        50%  { opacity: 1; }
        100% { opacity: .5; }
      }

      /* КНОПКА "ЗАГРУЗИТЬ ЕЩЕ" */
      .load-more-wrap { text-align: center; margin-top: 32px; }
      #loadMoreBtn {
        display: none;
        padding: 12px 36px;
        background: var(--bg2);
        border: 1px solid var(--border);
        color: var(--text);
        border-radius: 12px;
        font-size: 14px;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
        transition: border-color .2s, color .2s;
      }
      #loadMoreBtn.visible { display: inline-flex; align-items: center; gap: 8px; }
      #loadMoreBtn:hover { border-color: var(--accent); color: var(--accent); }

      /* ПУСТОЙ РЕЗУЛЬТАТ */
      .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--muted);
      }
      .empty-state .emoji { font-size: 48px; margin-bottom: 16px; }
      .empty-state p { font-size: 16px; }

      .divider {
        height: 1px;
        background: var(--border);
        max-width: 1200px;
        margin: 0 auto 32px;
      }
    </style>
</head>
<body>

<!-- ШАПКА -->
<header class="site-header">
    <a href="/" class="header-logo">
        BLACKWATCH
        <span>манга</span>
    </a>
    <div class="search-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input type="text" id="searchInput" placeholder="Поиск манги по названию..." autocomplete="off">
        <button class="search-clear" id="clearBtn" title="Очистить">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>
</header>

<!-- БРЕНД -->
<section class="brand-hero" id="brandHero">
=======
      /* ПРИЛИПАЮЩАЯ ВЕРХНЯЯ ПАНЕЛЬ */
 <section class="brand-hero">
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    <h1>BLACKWATCH</h1>
    <span>манга</span>
</section>

<<<<<<< HEAD
<!-- ТОП ПО ЛАЙКАМ -->
<?php if (!empty($topManga)): ?>
<section class="hero-section" id="heroSection">
    <div class="section-label">🔥 Топ по лайкам</div>
    <div class="hero-slider">
        <?php foreach ($topManga as $t):
            // Выбираем лучший URL для обложки
            if (!empty($t['cover_imgbb_url'])) {
                $coverSrc = htmlspecialchars($t['cover_imgbb_url']);
            } elseif (!empty($t['cover_id']) && str_starts_with($t['cover_id'], 'http')) {
                $coverSrc = htmlspecialchars($t['cover_id']);
            } else {
                $coverSrc = null;
            }
        ?>
        <a href="/read/<?= $t['id'] ?>" class="hero-card">
            <div class="hero-card-bg">
                <?php if ($coverSrc): ?>
                <img class="hero-card-img" src="<?= $coverSrc ?>" alt="<?= htmlspecialchars($t['title'] ?? '') ?>" loading="lazy" onerror="this.style.display='none'">
                <?php endif; ?>
            </div>
            <div class="hero-card-overlay">
                <div class="hero-card-title"><?= htmlspecialchars($t['title'] ?? '') ?></div>
                <div class="hero-card-likes">❤ <?= (int)$t['likes'] ?> лайков</div>
            </div>
        </a>
=======
<div class="search-wrap">
  <form action="/" method="GET" class="search-box">
    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 10px; color: var(--muted);"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="text" name="q" id="searchInput" placeholder="Поиск манги по названию..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off">
    <?php if (!empty($_GET['q'])): ?>
      <a href="/" style="color: var(--muted); text-decoration: none; padding: 5px;">✕</a>
    <?php endif; ?>
  </form>
</div>

<?php if (!empty($topManga) && empty($_GET['q'])): ?>
<section class="hero-section">
    <div class="section-label">Популярное сейчас</div>
    <div class="hero-slider">
        <?php foreach ($topManga as $m): ?>
            <a href="/read/<?= $m['id'] ?>" class="hero-card">
                <div class="hero-card-bg">
                    <?php if (!empty($m['cover_id'])): ?>
                        <img src="<?= $m['cover_id'] ?>" class="hero-card-img" alt="<?= htmlspecialchars($m['title'] ?? '') ?>">
                    <?php endif; ?>
                </div>
                <div class="hero-card-overlay">
                    <div class="hero-card-title"><?= htmlspecialchars($m['title'] ?? '') ?></div>
                    <div class="hero-card-likes">
                        <span class="heart">❤️</span> <?= $m['likes'] ?>
                    </div>
                </div>
            </a>
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<<<<<<< HEAD
<div class="divider"></div>

<!-- КАТАЛОГ -->
<section class="catalog-section">
    <div class="catalog-header">
        <div class="section-label" id="catalogLabel">📚 Каталог</div>
        <div class="total-count">Всего: <strong><?= number_format((int)$total, 0, '.', ' ') ?></strong></div>
    </div>

    <div class="manga-grid" id="mangaGrid">
        <?php for ($i = 0; $i < 12; $i++): ?>
        <div class="skeleton-card">
            <div class="skeleton-cover"></div>
            <div class="skeleton-text"></div>
            <div class="skeleton-text short"></div>
        </div>
        <?php endfor; ?>
    </div>

    <div class="load-more-wrap">
        <button id="loadMoreBtn">Загрузить ещё ↓</button>
    </div>
</section>

<script>
let currentPage  = 0;
let currentQuery = '';
let isLoading    = false;
let hasMore      = true;

const grid        = document.getElementById('mangaGrid');
const loadMoreBtn = document.getElementById('loadMoreBtn');
const searchInput = document.getElementById('searchInput');
const clearBtn    = document.getElementById('clearBtn');
const heroSection = document.getElementById('heroSection');
const brandHero   = document.getElementById('brandHero');
const catalogLabel = document.getElementById('catalogLabel');

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderCard(m) {
    const title = escHtml(m.title || '');
    // Используем cover_display — уже выбран лучший URL на сервере
    const url   = m.cover_display || null;
    const likes = parseInt(m.likes) || 0;

    return `<a href="/read/${m.id}" class="manga-card">
        ${url
            ? `<img class="manga-cover" src="${escHtml(url)}" alt="${title}" loading="lazy" onerror="this.outerHTML='<div class=\\"manga-cover-placeholder\\">📖</div>'">`
            : `<div class="manga-cover-placeholder">📖</div>`}
        <div class="manga-info">
            <div class="manga-title">${title}</div>
            ${likes > 0 ? `<div class="manga-likes">❤ ${likes}</div>` : ''}
        </div>
    </a>`;
}

async function loadManga(reset = false) {
    if (isLoading) return;
    isLoading = true;

    if (reset) {
        currentPage = 0;
        hasMore     = true;
        loadMoreBtn.classList.remove('visible');
        grid.innerHTML = Array(12).fill(
            '<div class="skeleton-card"><div class="skeleton-cover"></div><div class="skeleton-text"></div><div class="skeleton-text short"></div></div>'
        ).join('');
    }

    try {
        const params = new URLSearchParams({ q: currentQuery, page: currentPage });
        const res    = await fetch(`/api/manga?${params}`);
        const data   = await res.json();

        if (reset) grid.innerHTML = '';

        if (!data.items || (data.items.length === 0 && currentPage === 0)) {
            grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="emoji">🔍</div><p>Ничего не найдено</p></div>';
            loadMoreBtn.classList.remove('visible');
            isLoading = false;
            return;
        }

        data.items.forEach(m => grid.insertAdjacentHTML('beforeend', renderCard(m)));

        const loaded = (currentPage + 1) * data.limit;
        hasMore      = loaded < data.total;
        loadMoreBtn.classList.toggle('visible', hasMore);
        currentPage++;
    } catch (e) {
        console.error('Load error:', e);
    }

    isLoading = false;
}

// Первичная загрузка
=======
<section class="catalog-section">
    <div class="catalog-header">
        <div class="section-label"><?= !empty($_GET['q']) ? 'Результаты поиска' : 'Весь каталог' ?></div>
        <div class="total-count">Всего: <strong><?= $total ?></strong></div>
    </div>

    <div class="manga-grid" id="mangaGrid">
        <?php
        // Запрос для карточек (последние добавленные)
        $q = $_GET['q'] ?? '';
        if ($q) {
            $stmt = $pdo->prepare("SELECT * FROM manga WHERE title ILIKE ? ORDER BY id DESC LIMIT 12");
            $stmt->execute(['%' . $q . '%']);
        } else {
            $stmt = $pdo->query("SELECT * FROM manga ORDER BY id DESC LIMIT 12");
        }
        $mangas = $stmt->fetchAll();

        foreach ($mangas as $m): ?>
            <a href="/read/<?= $m['id'] ?>" class="manga-card">
                <?php if (!empty($m['cover_id'])): ?>
                    <img src="<?= $m['cover_id'] ?>" class="manga-cover" alt="<?= htmlspecialchars($m['title'] ?? '') ?>">
                <?php else: ?>
                    <div class="manga-cover-placeholder">📖</div>
                <?php endif; ?>
                <div class="manga-info">
                    <div class="manga-title"><?= htmlspecialchars($m['title'] ?? '') ?></div>
                    <div class="manga-likes"><span class="heart">❤️</span> <?= $m['likes'] ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($total > 12 && empty($q)): ?>
    <div class="load-more-wrap">
        <button id="loadMoreBtn" class="visible">Показать еще</button>
    </div>
    <?php endif; ?>
</section>

<section class="brand-hero">
    <h1>BLACKWATCH</h1>
    <span>манга</span>
</section>

<div class="search-wrap">
  <form action="/" method="GET" class="search-box">
    <input type="text" name="q" id="searchInput" placeholder="Найти мангу..." value="<?= htmlspecialchars($q ?? '') ?>">
  </form>
</div>
</header>

<div class="search-wrap">
  <div class="search-box">
    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="text" id="searchInput" placeholder="Поиск манги по названию...">
    <button class="search-clear" id="clearBtn" title="Очистить">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>

<?php if (!empty($topManga)): ?>
<div class="hero-section" id="heroSection">
  <div class="catalog-header" style="max-width:none; padding: 0;">
    <div class="section-label">🔥 Топ по лайкам</div>
  </div>
  <div class="hero-slider">
    <?php foreach ($topManga as $t): ?>
    <a href="/read/<?= $t['id'] ?>" class="hero-card">
      <div class="hero-card-bg">
        <?php if ($t['cover_id']): ?>
        <img class="hero-card-img" src="https://api.telegram.org/file/bot<?= htmlspecialchars(getenv('BOT_TOKEN')) ?>/<?= htmlspecialchars($t['cover_id']) ?>" onerror="this.style.display='none'" loading="lazy">
        <?php endif; ?>
      </div>
      <div class="hero-card-overlay">
        <div class="hero-card-title"><?= htmlspecialchars(preg_replace('/^❤️\s*/u', '', $t['title'])) ?></div>
        <div class="hero-card-likes">❤ <?= (int)$t['likes'] ?> лайков</div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="divider" style="margin-top: 28px;"></div>

<div class="catalog-section">
  <div class="catalog-header">
    <div class="section-label" style="margin-bottom: 0;">📚 Каталог</div>
    <div class="total-count">Всего: <strong><?= number_format($total, 0, '.', ' ') ?></strong></div>
  </div>

  <div class="manga-grid" id="mangaGrid">
    <?php
    // Скелетон (12 карточек)
    for ($i = 0; $i < 12; $i++) {
        echo '<div class="skeleton-card"><div class="skeleton-cover"></div><div class="skeleton-text"></div><div class="skeleton-text short"></div></div>';
    }
    ?>
  </div>

  <div class="load-more-wrap">
    <button id="loadMoreBtn">Загрузить ещё</button>
  </div>
</div>

<script>
const siteUrl = '<?= $siteUrl ?>';
let currentPage = 0;
let currentQuery = '';
let isLoading = false;
let hasMore = true;

const grid = document.getElementById('mangaGrid');
const loadMoreBtn = document.getElementById('loadMoreBtn');
const searchInput = document.getElementById('searchInput');
const clearBtn = document.getElementById('clearBtn');
const heroSection = document.getElementById('heroSection');

function coverUrl(coverId) {
  if (!coverId) return null;
  // cover_id это Telegram file_id — но для отображения на сайте нужен ImgBB URL
  // Если cover_id начинается с http — это уже прямой URL
  if (coverId.startsWith('http')) return coverId;
  // Иначе — Telegram file — не можем отобразить напрямую, покажем плейсхолдер
  return null;
}

function renderCard(m) {
  const title = m.title.replace(/^❤️\s*/u, '');
  const url = coverUrl(m.cover_id);

  return `
    <a href="/read/${m.id}" class="manga-card">
      ${url
        ? `<img class="manga-cover" src="${url}" alt="${escHtml(title)}" loading="lazy" onerror="this.outerHTML='<div class=\\"manga-cover-placeholder\\">📖</div>'">`
        : `<div class="manga-cover-placeholder">📖</div>`}
      <div class="manga-info">
        <div class="manga-title">${escHtml(title)}</div>
        ${m.likes > 0 ? `<div class="manga-likes"><span class="heart">❤</span> ${m.likes}</div>` : ''}
      </div>
    </a>`;
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function loadManga(reset = false) {
  if (isLoading) return;
  isLoading = true;

  if (reset) {
    currentPage = 0;
    hasMore = true;
    grid.innerHTML = Array(12).fill('<div class="skeleton-card"><div class="skeleton-cover"></div><div class="skeleton-text"></div><div class="skeleton-text short"></div></div>').join('');
    loadMoreBtn.classList.remove('visible');
  }

  const params = new URLSearchParams({ q: currentQuery, page: currentPage });
  const res = await fetch(`/api/manga?${params}`);
  const data = await res.json();

  if (reset) grid.innerHTML = '';

  if (data.items.length === 0 && currentPage === 0) {
    grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="emoji">🔍</div><p>Ничего не найдено</p></div>';
    loadMoreBtn.classList.remove('visible');
    isLoading = false;
    return;
  }

  data.items.forEach(m => {
    grid.insertAdjacentHTML('beforeend', renderCard(m));
  });

  const loaded = currentPage * data.limit + data.items.length;
  hasMore = loaded < data.total;
  loadMoreBtn.classList.toggle('visible', hasMore);
  currentPage++;
  isLoading = false;
}

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
loadManga(true);

loadMoreBtn.addEventListener('click', () => loadManga(false));

let searchTimer;
searchInput.addEventListener('input', () => {
<<<<<<< HEAD
    const q = searchInput.value.trim();
    clearBtn.style.display       = q ? 'flex' : 'none';
    if (heroSection) heroSection.style.display = q ? 'none' : '';
    if (brandHero)   brandHero.style.display   = q ? 'none' : '';
    catalogLabel.textContent = q ? '🔎 Результаты поиска' : '📚 Каталог';

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        currentQuery = q;
        loadManga(true);
    }, 350);
});

clearBtn.addEventListener('click', () => {
    searchInput.value            = '';
    clearBtn.style.display       = 'none';
    if (heroSection) heroSection.style.display = '';
    if (brandHero)   brandHero.style.display   = '';
    catalogLabel.textContent     = '📚 Каталог';
    currentQuery                 = '';
    loadManga(true);
});
</script>

=======
  const q = searchInput.value.trim();
  clearBtn.style.display = q ? 'flex' : 'none';
  // Скрываем/показываем hero
  if (heroSection) heroSection.style.display = q ? 'none' : '';

  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    currentQuery = q;
    loadManga(true);
  }, 350);
});

clearBtn.addEventListener('click', () => {
  searchInput.value = '';
  clearBtn.style.display = 'none';
  if (heroSection) heroSection.style.display = '';
  currentQuery = '';
  loadManga(true);
});
</script>
let offset = 12;
const loadMoreBtn = document.getElementById('loadMoreBtn');
const mangaGrid = document.getElementById('mangaGrid');

if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', async () => {
        loadMoreBtn.innerText = 'Загрузка...';
        
        // Отправляем запрос на сервер за следующей порцией
        const response = await fetch(`?ajax=1&offset=${offset}`);
        const html = await response.text();
        
        if (html.trim().length > 0) {
            mangaGrid.insertAdjacentHTML('beforeend', html);
            offset += 12;
            loadMoreBtn.innerText = 'Показать еще';
        } else {
            loadMoreBtn.style.display = 'none'; // Если манги больше нет
        }
    });
}
</script>
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
</body>
</html>
<?php
}

// =============================================
// РЕНДЕР РИДЕРА
// =============================================
function renderReader($pdo, $mangaId, $siteUrl, $botUsername) {
    $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
    $stmt->execute([$mangaId]);
    $manga = $stmt->fetch();

    if (!$manga) {
        http_response_code(404);
        echo '<!DOCTYPE html><html><body style="background:#0a0a0f;color:#fff;text-align:center;padding:80px;font-family:sans-serif">
<<<<<<< HEAD
        <h1 style="font-size:80px">404</h1><p>Манга не найдена</p><a href="/" style="color:#3b82f6">← Каталог</a></body></html>';
        return;
    }

=======
        <h1 style="font-size:80px">404</h1><p>Манга не найдена</p><a href="/" style="color:#e63946">← Каталог</a></body></html>';
        return;
    }

    // Страницы из manga_pages
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    $stmt2 = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt2->execute([$mangaId]);
    $pages = $stmt2->fetchAll(PDO::FETCH_COLUMN);

<<<<<<< HEAD
    $title        = htmlspecialchars(preg_replace('/^❤️\s*/u', '', $manga['title']));
    $telegraphUrl = $manga['file_id'];
    $hasWebReader = !empty($pages);

    // Обложка для мета-тега
    if (!empty($manga['cover_imgbb_url'])) {
        $coverMeta = $manga['cover_imgbb_url'];
    } elseif (!empty($manga['cover_id']) && str_starts_with($manga['cover_id'], 'http')) {
        $coverMeta = $manga['cover_id'];
    } else {
        $coverMeta = '';
    }
?>
=======
    $title = preg_replace('/^❤️\s*/u', '', $manga['title']);
    $telegraphUrl = $manga['file_id'];
    $hasWebReader = !empty($pages);

    ?>
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<<<<<<< HEAD
<title><?= $title ?> — BLACKWATCH Reader</title>
=======
<title><?= htmlspecialchars($title) ?> — MangaBot Reader</title>
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #050508;
    --bg2: #0f0f17;
<<<<<<< HEAD
    --accent: #3b82f6;
=======
    --accent: #e63946;
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    --text: #f0f0f5;
    --muted: #6a6a8a;
    --border: #1e1e2e;
  }
<<<<<<< HEAD
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; min-height: 100vh; }

=======

  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; min-height: 100vh; }

  /* ТОПБАР */
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .reader-header {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 200;
    height: 56px;
    background: rgba(5,5,8,0.97);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    padding: 0 16px;
    gap: 12px;
  }
<<<<<<< HEAD
=======

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .back-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
    text-decoration: none;
    font-size: 13px;
    padding: 6px 10px;
    border-radius: 8px;
<<<<<<< HEAD
    border: 1px solid var(--border);
    white-space: nowrap;
    transition: color .2s, background .2s;
  }
  .back-btn:hover { color: var(--text); background: var(--bg2); }
  .reader-title {
    flex: 1;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 18px;
    letter-spacing: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .mode-toggle { display: flex; gap: 6px; flex-shrink: 0; }
=======
    transition: color .2s, background .2s;
    border: 1px solid var(--border);
    white-space: nowrap;
  }
  .back-btn:hover { color: var(--text); background: var(--bg2); }

  .reader-title {
    flex: 1;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Bebas Neue', sans-serif;
    letter-spacing: 1px;
    font-size: 18px;
  }

  .mode-toggle {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
  }

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .mode-btn {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
    font-family: inherit;
    font-weight: 500;
    transition: all .2s;
<<<<<<< HEAD
  }
  .mode-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
  .mode-btn:hover:not(.active) { color: var(--text); border-color: #3a3a4e; }

  .reader-body { padding-top: 56px; min-height: 100vh; }

=======
    white-space: nowrap;
  }
  .mode-btn.active {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
  }
  .mode-btn:hover:not(.active) { color: var(--text); border-color: #3a3a4e; }

  /* КОНТЕНТ РИДЕРА */
  .reader-body { padding-top: 56px; min-height: 100vh; }

  /* === РЕЖИМ: ОДНА СТРАНИЦА (стрелки) === */
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  #singleMode {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: calc(100vh - 56px);
<<<<<<< HEAD
  }
=======
    padding: 0;
  }

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .page-viewer {
    position: relative;
    width: 100%;
    max-width: 780px;
    margin: 0 auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
<<<<<<< HEAD
=======

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .page-img-wrap {
    width: 100%;
    min-height: calc(100vh - 120px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 16px;
  }
<<<<<<< HEAD
=======

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  #currentPageImg {
    max-width: 100%;
    height: auto;
    display: block;
    border-radius: 4px;
    box-shadow: 0 4px 40px rgba(0,0,0,0.8);
<<<<<<< HEAD
  }
  .page-loading {
    width: 100%;
    min-height: 400px;
    display: none;
=======
    transition: opacity .25s;
  }
  #currentPageImg.fading { opacity: 0; }

  .page-loading {
    width: 100%;
    min-height: 400px;
    display: flex;
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    align-items: center;
    justify-content: center;
    color: var(--muted);
    flex-direction: column;
    gap: 12px;
<<<<<<< HEAD
  }
  .page-loading.show { display: flex; }
=======
    display: none;
  }
  .page-loading.show { display: flex; }

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .spinner {
    width: 40px; height: 40px;
    border: 3px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

<<<<<<< HEAD
=======
  /* НАВИГАЦИЯ */
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .page-nav {
    width: 100%;
    max-width: 780px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    gap: 12px;
    background: rgba(5,5,8,0.8);
    position: sticky;
    bottom: 0;
    backdrop-filter: blur(8px);
    border-top: 1px solid var(--border);
  }
<<<<<<< HEAD
=======

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .nav-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    background: var(--bg2);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    font-family: inherit;
    transition: all .2s;
    text-decoration: none;
  }
  .nav-btn:hover { border-color: var(--accent); color: var(--accent); }
  .nav-btn:disabled { opacity: 0.3; pointer-events: none; }
<<<<<<< HEAD
  .page-indicator { font-size: 14px; color: var(--muted); text-align: center; min-width: 80px; }
  .page-indicator strong { color: var(--text); }

=======

  .page-indicator {
    font-size: 14px;
    color: var(--muted);
    text-align: center;
    min-width: 80px;
  }
  .page-indicator strong { color: var(--text); }

  /* Клик по сторонам для листания */
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .click-left, .click-right {
    position: fixed;
    top: 56px; bottom: 60px;
    width: 40%;
    z-index: 10;
    cursor: pointer;
  }
  .click-left { left: 0; }
  .click-right { right: 0; }

<<<<<<< HEAD
=======
  /* === РЕЖИМ: ВСЕ СТРАНИЦЫ (прокрутка) === */
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  #scrollMode {
    display: none;
    max-width: 780px;
    margin: 0 auto;
    padding: 16px 16px 60px;
  }
  #scrollMode img {
    width: 100%;
    display: block;
    margin-bottom: 4px;
    border-radius: 2px;
  }
<<<<<<< HEAD

  /* TELEGRAPH FALLBACK */
  .telegraph-wrap {
=======
  #scrollMode img:first-child { border-radius: 4px 4px 0 0; }
  #scrollMode img:last-child { border-radius: 0 0 4px 4px; margin-bottom: 0; }

  /* === TELEGRAPH FALLBACK === */
  #telegraphMode {
    display: none;
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    padding: 48px 24px;
    max-width: 600px;
    margin: 0 auto;
    text-align: center;
  }
<<<<<<< HEAD
=======

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .telegraph-box {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 40px 32px;
  }
<<<<<<< HEAD
  .telegraph-box .icon { font-size: 48px; margin-bottom: 20px; }
  .telegraph-box h2 {
    font-size: 22px;
    margin-bottom: 12px;
    font-family: 'Bebas Neue', sans-serif;
    letter-spacing: 1px;
  }
  .telegraph-box p { color: var(--muted); font-size: 14px; line-height: 1.6; margin-bottom: 24px; }
=======

  .telegraph-box .icon { font-size: 48px; margin-bottom: 20px; }
  .telegraph-box h2 { font-size: 22px; margin-bottom: 12px; font-family: 'Bebas Neue', sans-serif; letter-spacing: 1px; }
  .telegraph-box p { color: var(--muted); font-size: 14px; line-height: 1.6; margin-bottom: 24px; }

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
  .read-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--accent);
    color: #fff;
    padding: 12px 28px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    transition: opacity .2s;
  }
  .read-btn:hover { opacity: .85; }
<<<<<<< HEAD
  .read-btn-tg {
=======

  .read-btn-secondary {
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #229ED9;
    color: #fff;
    padding: 12px 28px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    transition: opacity .2s;
    margin-top: 12px;
  }
<<<<<<< HEAD
  .read-btn-tg:hover { opacity: .85; }
=======
  .read-btn-secondary:hover { opacity: .85; }

  /* INFO BLOCK под ридером */
  .manga-info-block {
    max-width: 780px;
    margin: 24px auto 0;
    padding: 20px;
    background: var(--bg2);
    border-radius: 12px;
    border: 1px solid var(--border);
    display: none; /* показываем только в одиночном режиме */
  }
  .manga-info-block.show { display: block; }
  .manga-info-block h3 { font-size: 16px; margin-bottom: 8px; font-family: 'Bebas Neue', sans-serif; letter-spacing: 1px; }
  .manga-info-block p { font-size: 13px; color: var(--muted); line-height: 1.6; }

  /* НЕТ СТРАНИЦ */
  #noPages { display: none; }
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
</style>
</head>
<body>

<div class="reader-header">
<<<<<<< HEAD
    <a href="/" class="back-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="m15 18-6-6 6-6"/>
        </svg>
        Каталог
    </a>
    <div class="reader-title"><?= $title ?></div>
    <?php if ($hasWebReader): ?>
    <div class="mode-toggle">
        <button class="mode-btn active" id="btnSingle">← →</button>
        <button class="mode-btn" id="btnScroll">↕</button>
    </div>
    <?php endif; ?>
=======
  <a href="/" class="back-btn">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
    Каталог
  </a>
  <div class="reader-title"><?= htmlspecialchars($title) ?></div>
  <?php if ($hasWebReader): ?>
  <div class="mode-toggle">
    <button class="mode-btn active" id="btnSingle">← →</button>
    <button class="mode-btn" id="btnScroll">↕</button>
  </div>
  <?php endif; ?>
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
</div>

<div class="reader-body">

<?php if ($hasWebReader): ?>
<<<<<<< HEAD
    <div class="click-left"  id="clickLeft"></div>
    <div class="click-right" id="clickRight"></div>

    <div id="singleMode">
        <div class="page-viewer">
            <div class="page-img-wrap">
                <div class="page-loading show" id="pageLoading">
                    <div class="spinner"></div>
                    <span>Загрузка...</span>
                </div>
                <img id="currentPageImg" src="" alt="Страница" style="display:none">
            </div>
        </div>
        <div class="page-nav">
            <button class="nav-btn" id="prevBtn" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Назад
            </button>
            <div class="page-indicator"><strong id="curPage">1</strong> / <span id="totalPages">?</span></div>
            <button class="nav-btn" id="nextBtn">
                Вперёд
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="m9 18 6-6-6-6"/>
                </svg>
            </button>
        </div>
    </div>

    <div id="scrollMode"></div>

    <?php if ($telegraphUrl): ?>
    <div style="max-width:780px;margin:24px auto;padding:0 16px 32px;">
        <a href="<?= htmlspecialchars($telegraphUrl) ?>" target="_blank" class="nav-btn"
           style="justify-content:center;width:100%;max-width:300px;margin:0 auto;display:flex;text-decoration:none;">
            📖 Также читать на Telegra.ph
        </a>
    </div>
    <?php endif; ?>

<?php else: ?>
    <div class="telegraph-wrap">
        <div class="telegraph-box">
            <div class="icon">📖</div>
            <h2><?= $title ?></h2>
            <p><?= htmlspecialchars(mb_substr($manga['description'] ?? '', 0, 200)) ?>...</p>
            <?php if ($telegraphUrl): ?>
            <a href="<?= htmlspecialchars($telegraphUrl) ?>" target="_blank" class="read-btn">
                Читать на Telegra.ph
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
            </a><br>
            <?php endif; ?>
            <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank" class="read-btn-tg">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z"/>
                </svg>
                Открыть в боте
            </a>
            <p style="margin-top:16px;font-size:12px;color:var(--muted)">
                💡 Веб-ридер доступен для манги, добавленной через ZIP-архив
            </p>
        </div>
    </div>
<?php endif; ?>

</div>
=======
  <!-- Клик по краям -->
  <div class="click-left" id="clickLeft"></div>
  <div class="click-right" id="clickRight"></div>

  <!-- РЕЖИМ: одна страница -->
  <div id="singleMode">
    <div class="page-viewer">
      <div class="page-img-wrap">
        <div class="page-loading show" id="pageLoading">
          <div class="spinner"></div>
          <span>Загрузка...</span>
        </div>
        <img id="currentPageImg" src="" alt="Страница" style="display:none">
      </div>
    </div>
    <div class="page-nav">
      <button class="nav-btn" id="prevBtn" disabled>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
        Назад
      </button>
      <div class="page-indicator"><strong id="curPage">1</strong> / <span id="totalPages">?</span></div>
      <button class="nav-btn" id="nextBtn">
        Вперёд
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
      </button>
    </div>
  </div>

  <!-- РЕЖИМ: прокрутка -->
  <div id="scrollMode"></div>

  <?php if ($telegraphUrl): ?>
  <div style="max-width:780px;margin:24px auto;padding:0 16px 32px;">
    <a href="<?= htmlspecialchars($telegraphUrl) ?>" target="_blank" class="nav-btn" style="justify-content:center;width:100%;max-width:300px;margin:0 auto;display:flex;text-decoration:none;">
      📖 Также читать на Telegra.ph
    </a>
  </div>
  <?php endif; ?>

<?php else: ?>
  <!-- НЕТ СТРАНИЦ В БД — только Telegraph -->
  <div id="telegraphMode" style="display:block">
    <div class="telegraph-box">
      <div class="icon">📖</div>
      <h2><?= htmlspecialchars($title) ?></h2>
      <p><?= htmlspecialchars(mb_substr($manga['description'] ?? '', 0, 200)) ?>...</p>
      <?php if ($telegraphUrl): ?>
      <a href="<?= htmlspecialchars($telegraphUrl) ?>" target="_blank" class="read-btn">
        Читать на Telegra.ph
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      </a>
      <br>
      <?php endif; ?>
      <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank" class="read-btn-secondary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z"/></svg>
        Открыть в боте
      </a>
      <p style="margin-top:16px;font-size:12px;color:var(--muted)">
        💡 Веб-ридер доступен для манги, добавленной через ZIP-архив
      </p>
    </div>
  </div>
<?php endif; ?>

</div><!-- /reader-body -->
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59

<?php if ($hasWebReader): ?>
<script>
const pages = <?= json_encode($pages) ?>;
let current = 0;
const total = pages.length;

<<<<<<< HEAD
const img          = document.getElementById('currentPageImg');
const loading      = document.getElementById('pageLoading');
const curPageEl    = document.getElementById('curPage');
const totalPagesEl = document.getElementById('totalPages');
const prevBtn      = document.getElementById('prevBtn');
const nextBtn      = document.getElementById('nextBtn');
const singleMode   = document.getElementById('singleMode');
const scrollMode   = document.getElementById('scrollMode');
const btnSingle    = document.getElementById('btnSingle');
const btnScroll    = document.getElementById('btnScroll');
const clickLeft    = document.getElementById('clickLeft');
const clickRight   = document.getElementById('clickRight');

totalPagesEl.textContent = total;

const preloadCache = {};
function preload(idx) {
    if (idx >= 0 && idx < total && !preloadCache[idx]) {
        const i = new Image();
        i.src = pages[idx];
        preloadCache[idx] = i;
    }
}

function showPage(idx) {
    if (idx < 0 || idx >= total) return;
    current = idx;
    curPageEl.textContent = idx + 1;
    prevBtn.disabled = idx === 0;
    nextBtn.disabled = idx === total - 1;

    loading.classList.add('show');
    img.style.display = 'none';

    const newImg = new Image();
    newImg.onload = () => {
        img.src = newImg.src;
        img.style.display = 'block';
        loading.classList.remove('show');
        preload(idx + 1);
        preload(idx + 2);
        window.scrollTo({ top: 56, behavior: 'smooth' });
    };
    newImg.onerror = () => {
        loading.classList.remove('show');
        img.style.display = 'none';
    };
    newImg.src = pages[idx];
=======
const img = document.getElementById('currentPageImg');
const loading = document.getElementById('pageLoading');
const curPageEl = document.getElementById('curPage');
const totalPagesEl = document.getElementById('totalPages');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
const singleMode = document.getElementById('singleMode');
const scrollMode = document.getElementById('scrollMode');
const btnSingle = document.getElementById('btnSingle');
const btnScroll = document.getElementById('btnScroll');
const clickLeft = document.getElementById('clickLeft');
const clickRight = document.getElementById('clickRight');

totalPagesEl.textContent = total;

// Предзагрузка следующей
const preloadCache = {};
function preload(idx) {
  if (idx >= 0 && idx < total && !preloadCache[idx]) {
    const i = new Image();
    i.src = pages[idx];
    preloadCache[idx] = i;
  }
}

function showPage(idx) {
  if (idx < 0 || idx >= total) return;
  current = idx;
  curPageEl.textContent = idx + 1;
  prevBtn.disabled = idx === 0;
  nextBtn.disabled = idx === total - 1;

  loading.classList.add('show');
  img.style.display = 'none';
  img.classList.remove('fading');

  const newImg = new Image();
  newImg.onload = () => {
    img.src = newImg.src;
    img.style.display = 'block';
    loading.classList.remove('show');
    preload(idx + 1);
    preload(idx + 2);
    // Скроллим к верху страницы при переходе
    window.scrollTo({ top: 56, behavior: 'smooth' });
  };
  newImg.onerror = () => {
    loading.classList.remove('show');
    img.src = '';
    img.style.display = 'none';
  };
  newImg.src = pages[idx];
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
}

showPage(0);
preload(1);

<<<<<<< HEAD
prevBtn.addEventListener('click',  () => showPage(current - 1));
nextBtn.addEventListener('click',  () => showPage(current + 1));
clickLeft.addEventListener('click',  () => showPage(current - 1));
clickRight.addEventListener('click', () => showPage(current + 1));

document.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') showPage(current + 1);
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   showPage(current - 1);
});

let touchX = 0;
document.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; });
document.addEventListener('touchend',   e => {
    const dx = e.changedTouches[0].clientX - touchX;
    if (Math.abs(dx) > 50) dx < 0 ? showPage(current + 1) : showPage(current - 1);
});

btnSingle.addEventListener('click', () => {
    singleMode.style.display = '';
    scrollMode.style.display = 'none';
    clickLeft.style.display  = '';
    clickRight.style.display = '';
    btnSingle.classList.add('active');
    btnScroll.classList.remove('active');
});

btnScroll.addEventListener('click', () => {
    singleMode.style.display = 'none';
    scrollMode.style.display = 'block';
    clickLeft.style.display  = 'none';
    clickRight.style.display = 'none';
    btnSingle.classList.remove('active');
    btnScroll.classList.add('active');
    if (!scrollMode.children.length) {
        const frag = document.createDocumentFragment();
        pages.forEach((url, i) => {
            const el  = document.createElement('img');
            el.loading = 'lazy';
            el.src     = url;
            el.alt     = `Страница ${i + 1}`;
            frag.appendChild(el);
        });
        scrollMode.appendChild(frag);
    }
    window.scrollTo({ top: 56 });
=======
prevBtn.addEventListener('click', () => showPage(current - 1));
nextBtn.addEventListener('click', () => showPage(current + 1));
clickLeft.addEventListener('click', () => showPage(current - 1));
clickRight.addEventListener('click', () => showPage(current + 1));

// Клавиши
document.addEventListener('keydown', e => {
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') showPage(current + 1);
  if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   showPage(current - 1);
});

// Touch-свайп
let touchX = 0;
document.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; });
document.addEventListener('touchend', e => {
  const dx = e.changedTouches[0].clientX - touchX;
  if (Math.abs(dx) > 50) {
    dx < 0 ? showPage(current + 1) : showPage(current - 1);
  }
});

// Переключение режимов
btnSingle.addEventListener('click', () => {
  singleMode.style.display = '';
  scrollMode.style.display = 'none';
  clickLeft.style.display = '';
  clickRight.style.display = '';
  btnSingle.classList.add('active');
  btnScroll.classList.remove('active');
  document.body.style.overflowY = '';
});

btnScroll.addEventListener('click', () => {
  singleMode.style.display = 'none';
  clickLeft.style.display = 'none';
  clickRight.style.display = 'none';
  scrollMode.style.display = 'block';
  btnSingle.classList.remove('active');
  btnScroll.classList.add('active');

  // Лениво загружаем все страницы
  if (!scrollMode.children.length) {
    const frag = document.createDocumentFragment();
    pages.forEach((url, i) => {
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.src = url;
      img.alt = `Страница ${i + 1}`;
      frag.appendChild(img);
    });
    scrollMode.appendChild(frag);
  }
  window.scrollTo({ top: 56 });
>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
});
</script>
<?php endif; ?>

</body>
</html>
<?php
<<<<<<< HEAD
}
=======
}

>>>>>>> 7304ad90445212e425dd4949cf09e929d5345e59
