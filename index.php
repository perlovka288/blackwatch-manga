<?php
// =============================================
// MANGABOT — Сайт-каталог
// Один файл: каталог + поиск + ридер
// Render.com + Neon PostgreSQL
// =============================================

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

$botUsername = getenv('BOT_USERNAME') ?: 'Manga123Manga123bot';
$siteUrl     = rtrim(getenv('SITE_URL') ?: 'https://blackwatch-manga.onrender.com', '/');

// =============================================
// РОУТИНГ
// =============================================
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path       = parse_url($requestUri, PHP_URL_PATH);
$path       = rtrim($path, '/') ?: '/';

// /read/123 — ридер манги
if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $mangaId = (int)$m[1];
    renderReader($pdo, $mangaId, $siteUrl, $botUsername);
    exit;
}

// /api/pages/123 — страницы манги для ридера (AJAX)
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json; charset=utf-8');
    $mangaId = (int)$m[1];
    $stmt    = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt->execute([$mangaId]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
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

// Главная страница
renderCatalog($pdo, $siteUrl, $botUsername);

// =============================================
// РЕНДЕР КАТАЛОГА
// =============================================
function renderCatalog($pdo, $siteUrl, $botUsername) {
    $topManga = $pdo->query("
        SELECT id, title, description, cover_id, cover_imgbb_url, likes
        FROM manga
        WHERE likes > 0
        ORDER BY likes DESC
        LIMIT 5
    ")->fetchAll();
    $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLACKWATCH — Каталог манги</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
      :root {
        --bg: #0a0a0f;
        --bg2: #111118;
        --bg3: #1a1a24;
        --accent: #3b82f6;
        --accent2: #60a5fa;
        --gold: #ffd166;
        --text: #f0f0f5;
        --muted: #7a7a9a;
        --border: #2a2a3a;
        --card-shadow: 0 8px 32px rgba(0,0,0,0.5);
      }
      * { margin: 0; padding: 0; box-sizing: border-box; }
      body {
        background: var(--bg);
        color: var(--text);
        font-family: 'Inter', sans-serif;
        min-height: 100vh;
        overflow-x: hidden;
      }

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
    <h1>BLACKWATCH</h1>
    <span>манга</span>
</section>

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
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

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
loadManga(true);

loadMoreBtn.addEventListener('click', () => loadManga(false));

let searchTimer;
searchInput.addEventListener('input', () => {
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
        <h1 style="font-size:80px">404</h1><p>Манга не найдена</p><a href="/" style="color:#3b82f6">← Каталог</a></body></html>';
        return;
    }

    $stmt2 = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt2->execute([$mangaId]);
    $pages = $stmt2->fetchAll(PDO::FETCH_COLUMN);

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
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title ?> — BLACKWATCH Reader</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #050508;
    --bg2: #0f0f17;
    --accent: #3b82f6;
    --text: #f0f0f5;
    --muted: #6a6a8a;
    --border: #1e1e2e;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; min-height: 100vh; }

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
  .back-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
    text-decoration: none;
    font-size: 13px;
    padding: 6px 10px;
    border-radius: 8px;
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
  }
  .mode-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
  .mode-btn:hover:not(.active) { color: var(--text); border-color: #3a3a4e; }

  .reader-body { padding-top: 56px; min-height: 100vh; }

  #singleMode {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: calc(100vh - 56px);
  }
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
  .page-img-wrap {
    width: 100%;
    min-height: calc(100vh - 120px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 16px;
  }
  #currentPageImg {
    max-width: 100%;
    height: auto;
    display: block;
    border-radius: 4px;
    box-shadow: 0 4px 40px rgba(0,0,0,0.8);
  }
  .page-loading {
    width: 100%;
    min-height: 400px;
    display: none;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    flex-direction: column;
    gap: 12px;
  }
  .page-loading.show { display: flex; }
  .spinner {
    width: 40px; height: 40px;
    border: 3px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

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
  .page-indicator { font-size: 14px; color: var(--muted); text-align: center; min-width: 80px; }
  .page-indicator strong { color: var(--text); }

  .click-left, .click-right {
    position: fixed;
    top: 56px; bottom: 60px;
    width: 40%;
    z-index: 10;
    cursor: pointer;
  }
  .click-left { left: 0; }
  .click-right { right: 0; }

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

  /* TELEGRAPH FALLBACK */
  .telegraph-wrap {
    padding: 48px 24px;
    max-width: 600px;
    margin: 0 auto;
    text-align: center;
  }
  .telegraph-box {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 40px 32px;
  }
  .telegraph-box .icon { font-size: 48px; margin-bottom: 20px; }
  .telegraph-box h2 {
    font-size: 22px;
    margin-bottom: 12px;
    font-family: 'Bebas Neue', sans-serif;
    letter-spacing: 1px;
  }
  .telegraph-box p { color: var(--muted); font-size: 14px; line-height: 1.6; margin-bottom: 24px; }
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
  .read-btn-tg {
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
  .read-btn-tg:hover { opacity: .85; }
</style>
</head>
<body>

<div class="reader-header">
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
</div>

<div class="reader-body">

<?php if ($hasWebReader): ?>
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

<?php if ($hasWebReader): ?>
<script>
const pages = <?= json_encode($pages) ?>;
let current = 0;
const total = pages.length;

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
}

showPage(0);
preload(1);

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
});
</script>
<?php endif; ?>

</body>
</html>
<?php
}