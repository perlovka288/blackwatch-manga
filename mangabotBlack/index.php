<?php
// =============================================
// MANGABOT — Сайт-каталог
// Один файл: каталог + поиск + ридер
// Render.com + Railway MySQL
// =============================================

// ENV переменные (те же что у бота)
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

$botUsername = getenv('BOT_USERNAME') ?: 'Manga123Manga123bot'; // установите в ENV
$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://blackwatch-manga.onrender.com', '/');

// =============================================
// РОУТИНГ
// =============================================
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// /read/123 — ридер манги
if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $mangaId = (int)$m[1];
    renderReader($pdo, $mangaId, $siteUrl, $botUsername);
    exit;
}

// /api/manga — JSON для поиска (AJAX)
if ($path === '/api/manga') {
    header('Content-Type: application/json; charset=utf-8');
    $q    = trim($_GET['q'] ?? '');
    $page = max(0, (int)($_GET['page'] ?? 0));
    $limit = 12;
    $offset = $page * $limit;

    if ($q) {
        $total = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title LIKE ?");
        $total->execute(["%$q%"]);
        $total = $total->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, title, description, cover_id, likes, file_id FROM manga WHERE title LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$q%"]);
    } else {
        $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, title, description, cover_id, likes, file_id FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
    }
    $items = $stmt->fetchAll();
    echo json_encode(['items' => $items, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]);
    exit;
}

// /api/pages/123 — страницы манги для ридера (AJAX)
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json; charset=utf-8');
    $mangaId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt->execute([$mangaId]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Если нет в manga_pages, но есть telegra.ph ссылка — редиректим туда
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

// Главная страница
renderCatalog($pdo, $siteUrl, $botUsername);

// =============================================
// РЕНДЕР КАТАЛОГА
// =============================================
function renderCatalog($pdo, $siteUrl, $botUsername) {
    // Топ-5 по лайкам для баннера
    $topManga = $pdo->query("SELECT id, title, description, cover_id, likes FROM manga WHERE likes > 0 ORDER BY likes DESC LIMIT 5")->fetchAll();
    // Последние 12 для первоначальной загрузки
    $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MangaBot — Каталог манги</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Noto+Sans+JP:wght@400;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0a0a0f;
    --bg2: #111118;
    --bg3: #1a1a24;
    --accent: #e63946;
    --accent2: #ff6b6b;
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
  header {
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(10,10,15,0.92);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 64px;
  }

  .logo {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 28px;
    letter-spacing: 3px;
    color: var(--text);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .logo span { color: var(--accent); }

  .header-right {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .tg-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #229ED9;
    color: #fff;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: opacity .2s;
  }
  .tg-btn:hover { opacity: .85; }
  .tg-btn svg { width: 18px; height: 18px; }

  /* ПОИСК */
  .search-wrap {
    padding: 32px 24px 0;
    max-width: 700px;
    margin: 0 auto;
  }

  .search-box {
    display: flex;
    align-items: center;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 0 16px;
    transition: border-color .2s;
  }
  .search-box:focus-within { border-color: var(--accent); }

  .search-icon {
    color: var(--muted);
    margin-right: 10px;
    flex-shrink: 0;
  }

  #searchInput {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--text);
    font-size: 16px;
    padding: 14px 0;
    font-family: inherit;
  }
  #searchInput::placeholder { color: var(--muted); }

  .search-clear {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    padding: 4px;
    display: none;
    border-radius: 4px;
  }
  .search-clear:hover { color: var(--text); }

  /* HERO SLIDER (топ по лайкам) */
  .hero-section {
    padding: 28px 24px 0;
    max-width: 1200px;
    margin: 0 auto;
  }

  .section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: 14px;
  }

  .hero-slider {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 4px;
  }
  .hero-slider::-webkit-scrollbar { display: none; }

  .hero-card {
    flex-shrink: 0;
    width: 280px;
    height: 160px;
    border-radius: 14px;
    overflow: hidden;
    scroll-snap-align: start;
    position: relative;
    cursor: pointer;
    transition: transform .25s;
    text-decoration: none;
  }
  .hero-card:hover { transform: scale(1.02); }

  .hero-card-bg {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    overflow: hidden;
  }

  .hero-card-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.55;
    transition: opacity .3s;
  }
  .hero-card:hover .hero-card-img { opacity: 0.7; }

  .hero-card-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, transparent 60%);
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: 16px;
  }

  .hero-card-title {
    font-family: 'Noto Sans JP', sans-serif;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.3;
    color: #fff;
    text-shadow: 0 2px 8px rgba(0,0,0,0.8);
  }

  .hero-card-likes {
    margin-top: 6px;
    font-size: 12px;
    color: var(--gold);
    display: flex;
    align-items: center;
    gap: 4px;
  }

  /* КАТАЛОГ */
  .catalog-section {
    padding: 32px 24px 80px;
    max-width: 1200px;
    margin: 0 auto;
  }

  .catalog-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
  }

  .total-count {
    font-size: 13px;
    color: var(--muted);
  }
  .total-count strong { color: var(--text); }

  .manga-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 20px;
  }

  @media (max-width: 600px) {
    .manga-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
  }

  .manga-card {
    background: var(--bg2);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border);
    cursor: pointer;
    transition: transform .2s, box-shadow .2s, border-color .2s;
    text-decoration: none;
    display: block;
    animation: fadeIn .4s ease;
  }
  .manga-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--card-shadow);
    border-color: var(--accent);
  }

  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .manga-cover {
    width: 100%;
    aspect-ratio: 2/3;
    object-fit: cover;
    background: var(--bg3);
    display: block;
  }

  .manga-cover-placeholder {
    width: 100%;
    aspect-ratio: 2/3;
    background: linear-gradient(135deg, var(--bg3) 0%, #1e1e2e 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
  }

  .manga-info {
    padding: 10px 12px 12px;
  }

  .manga-title {
    font-size: 13px;
    font-weight: 600;
    line-height: 1.35;
    font-family: 'Noto Sans JP', sans-serif;
    color: var(--text);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .manga-likes {
    margin-top: 6px;
    font-size: 11px;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .manga-likes .heart { color: var(--accent); }

  /* LOAD MORE */
  .load-more-wrap {
    text-align: center;
    margin-top: 36px;
  }

  #loadMoreBtn {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
    padding: 12px 32px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: background .2s, border-color .2s;
    display: none;
  }
  #loadMoreBtn:hover { background: var(--bg3); border-color: var(--accent); }
  #loadMoreBtn.visible { display: inline-block; }

  /* СКЕЛЕТОН */
  .skeleton {
    background: linear-gradient(90deg, var(--bg3) 25%, #22223a 50%, var(--bg3) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 8px;
  }

  @keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
  }

  .skeleton-card {
    background: var(--bg2);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border);
  }
  .skeleton-cover {
    width: 100%;
    aspect-ratio: 2/3;
    background: linear-gradient(90deg, var(--bg3) 25%, #22223a 50%, var(--bg3) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
  }
  .skeleton-text {
    height: 12px;
    margin: 10px 12px 6px;
    background: linear-gradient(90deg, var(--bg3) 25%, #22223a 50%, var(--bg3) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 4px;
  }
  .skeleton-text.short { width: 60%; margin-bottom: 12px; }

  /* ПУСТО */
  .empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--muted);
  }
  .empty-state .emoji { font-size: 48px; margin-bottom: 16px; }
  .empty-state p { font-size: 16px; }

  /* ДЕКОРАТИВНАЯ ЛИНИЯ */
  .divider {
    height: 1px;
    background: linear-gradient(to right, transparent, var(--border), transparent);
    margin: 0 24px;
  }

  /* Скрытый hero если нет топа */
  .hero-section.hidden { display: none; }
</style>
</head>
<body>

<header>
  <a href="/" class="logo">Manga<span>Bot</span></a>
  <div class="header-right">
    <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank" class="tg-btn">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z"/></svg>
      Бот в Telegram
    </a>
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

loadManga(true);

loadMoreBtn.addEventListener('click', () => loadManga(false));

let searchTimer;
searchInput.addEventListener('input', () => {
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
        <h1 style="font-size:80px">404</h1><p>Манга не найдена</p><a href="/" style="color:#e63946">← Каталог</a></body></html>';
        return;
    }

    // Страницы из manga_pages
    $stmt2 = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt2->execute([$mangaId]);
    $pages = $stmt2->fetchAll(PDO::FETCH_COLUMN);

    $title = preg_replace('/^❤️\s*/u', '', $manga['title']);
    $telegraphUrl = $manga['file_id'];
    $hasWebReader = !empty($pages);

    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — MangaBot Reader</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #050508;
    --bg2: #0f0f17;
    --accent: #e63946;
    --text: #f0f0f5;
    --muted: #6a6a8a;
    --border: #1e1e2e;
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; min-height: 100vh; }

  /* ТОПБАР */
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
  #singleMode {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: calc(100vh - 56px);
    padding: 0;
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
    transition: opacity .25s;
  }
  #currentPageImg.fading { opacity: 0; }

  .page-loading {
    width: 100%;
    min-height: 400px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    flex-direction: column;
    gap: 12px;
    display: none;
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

  /* НАВИГАЦИЯ */
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

  .page-indicator {
    font-size: 14px;
    color: var(--muted);
    text-align: center;
    min-width: 80px;
  }
  .page-indicator strong { color: var(--text); }

  /* Клик по сторонам для листания */
  .click-left, .click-right {
    position: fixed;
    top: 56px; bottom: 60px;
    width: 40%;
    z-index: 10;
    cursor: pointer;
  }
  .click-left { left: 0; }
  .click-right { right: 0; }

  /* === РЕЖИМ: ВСЕ СТРАНИЦЫ (прокрутка) === */
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
  #scrollMode img:first-child { border-radius: 4px 4px 0 0; }
  #scrollMode img:last-child { border-radius: 0 0 4px 4px; margin-bottom: 0; }

  /* === TELEGRAPH FALLBACK === */
  #telegraphMode {
    display: none;
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
  .telegraph-box h2 { font-size: 22px; margin-bottom: 12px; font-family: 'Bebas Neue', sans-serif; letter-spacing: 1px; }
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

  .read-btn-secondary {
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
</style>
</head>
<body>

<div class="reader-header">
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
</div>

<div class="reader-body">

<?php if ($hasWebReader): ?>
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

<?php if ($hasWebReader): ?>
<script>
const pages = <?= json_encode($pages) ?>;
let current = 0;
const total = pages.length;

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
}

showPage(0);
preload(1);

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
});
</script>
<?php endif; ?>

</body>
</html>
<?php
}

