<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

// Подключение к PostgreSQL
try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        getenv('DB_HOST'),
        getenv('DB_PORT') ?: '5432',
        getenv('DB_NAME')
    );
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die("DB Error: " . $e->getMessage());
}

// Получаем путь из URL
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// API endpoint для получения страниц манги
if (preg_match('#^/api/pages/(\d+)$#', $path, $matches)) {
    $mangaId = (int)$matches[1];
    
    $stmt = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC");
    $stmt->execute([$mangaId]);
    $pages = $stmt->fetchAll();
    
    $urls = array_column($pages, 'page_url');
    
    header('Content-Type: application/json');
    echo json_encode(['pages' => $urls]);
    exit;
}

// API endpoint для получения информации о манге
if (preg_match('#^/api/manga/(\d+)$#', $path, $matches)) {
    $mangaId = (int)$matches[1];
    
    $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, telegraph_url, likes, dislikes FROM manga WHERE id = ?");
    $stmt->execute([$mangaId]);
    $manga = $stmt->fetch();
    
    if (!$manga) {
        http_response_code(404);
        echo json_encode(['error' => 'Manga not found']);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode($manga);
    exit;
}

// Страница ридера
if (preg_match('#^/read/(\d+)$#', $path, $matches)) {
    $mangaId = (int)$matches[1];
    
    $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, telegraph_url FROM manga WHERE id = ?");
    $stmt->execute([$mangaId]);
    $manga = $stmt->fetch();
    
    if (!$manga) {
        http_response_code(404);
        echo "<h1>Манга не найдена</h1>";
        exit;
    }
    
    $stmtPages = $pdo->prepare("SELECT COUNT(*) as count FROM manga_pages WHERE manga_id = ?");
    $stmtPages->execute([$mangaId]);
    $pageCount = $stmtPages->fetch()['count'];
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?= htmlspecialchars($manga['title']) ?> - Читать мангу онлайн</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0a0a0a;
            color: #e0e0e0;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            border-bottom: 1px solid #2a2a4e;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .title-section {
            flex: 1;
        }
        
        .title-section h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
            color: #ff6b6b;
        }
        
        .title-section p {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .nav-btn {
            background: #2a2a4e;
            border: none;
            color: #e0e0e0;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .nav-btn:hover {
            background: #3a3a6e;
            transform: translateY(-1px);
        }
        
        .nav-btn:active {
            transform: translateY(0);
        }
        
        .reader-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-container {
            background: #141414;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .page-image {
            width: 100%;
            height: auto;
            display: block;
        }
        
        .controls {
            position: sticky;
            bottom: 20px;
            background: rgba(26, 26, 46, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            padding: 12px 20px;
            display: flex;
            justify-content: center;
            gap: 20px;
            max-width: 500px;
            margin: 0 auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            border: 1px solid #2a2a4e;
        }
        
        .control-btn {
            background: #2a2a4e;
            border: none;
            color: #e0e0e0;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .control-btn:hover:not(:disabled) {
            background: #ff6b6b;
            transform: translateY(-2px);
        }
        
        .control-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .page-info {
            background: #2a2a4e;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .loading {
            text-align: center;
            padding: 60px 20px;
            font-size: 1.2rem;
            color: #ff6b6b;
        }
        
        .error {
            text-align: center;
            padding: 60px 20px;
            color: #ff6b6b;
            background: #1a1a2e;
            border-radius: 12px;
            margin: 20px;
        }
        
        .back-link {
            display: inline-block;
            margin: 20px;
            color: #ff6b6b;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .title-section h1 {
                font-size: 1.2rem;
            }
            
            .controls {
                padding: 10px 15px;
                gap: 10px;
            }
            
            .control-btn {
                padding: 8px 16px;
                font-size: 14px;
            }
            
            .page-info {
                font-size: 12px;
                padding: 6px 12px;
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .page-container {
            animation: fadeIn 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="title-section">
                <h1><?= htmlspecialchars($manga['title']) ?></h1>
                <p><?= htmlspecialchars(mb_substr($manga['description'], 0, 100)) ?>...</p>
            </div>
            <div class="nav-buttons">
                <button class="nav-btn" onclick="window.location.href='/'">🏠 Каталог</button>
                <button class="nav-btn" onclick="window.open('<?= htmlspecialchars($manga['telegraph_url']) ?>', '_blank')">📖 Telegra.ph</button>
            </div>
        </div>
    </div>
    
    <div class="reader-container" id="reader-container">
        <div class="loading" id="loading">📖 Загрузка страниц...</div>
    </div>
    
    <div class="controls" id="controls" style="display: none;">
        <button class="control-btn" id="prevBtn" onclick="prevPage()">◀ Назад</button>
        <span class="page-info" id="pageInfo">Страница 0 / 0</span>
        <button class="control-btn" id="nextBtn" onclick="nextPage()">Вперед ▶</button>
    </div>
    
    <script>
        let pages = [];
        let currentPage = 0;
        
        async function loadPages() {
            const loading = document.getElementById('loading');
            const container = document.getElementById('reader-container');
            const controls = document.getElementById('controls');
            
            try {
                const response = await fetch('/api/pages/<?= $mangaId ?>');
                const data = await response.json();
                
                if (data.pages && data.pages.length > 0) {
                    pages = data.pages;
                    loading.style.display = 'none';
                    controls.style.display = 'flex';
                    
                    // Загружаем первую страницу
                    currentPage = 0;
                    renderCurrentPage();
                    
                    // Предзагрузка следующих страниц
                    preloadImages();
                } else {
                    loading.innerHTML = '❌ Страницы не найдены для этой манги';
                }
            } catch (error) {
                loading.innerHTML = '❌ Ошибка загрузки страниц. Пожалуйста, попробуйте позже.';
                console.error('Error loading pages:', error);
            }
        }
        
        function renderCurrentPage() {
            const container = document.getElementById('reader-container');
            const pageInfo = document.getElementById('pageInfo');
            
            if (pages.length === 0) return;
            
            const pageUrl = pages[currentPage];
            const pageHtml = `
                <div class="page-container">
                    <img class="page-image" src="${pageUrl}" alt="Страница ${currentPage + 1}" loading="lazy">
                </div>
            `;
            
            container.innerHTML = pageHtml;
            pageInfo.textContent = `Страница ${currentPage + 1} / ${pages.length}`;
            
            // Обновляем состояние кнопок
            document.getElementById('prevBtn').disabled = currentPage === 0;
            document.getElementById('nextBtn').disabled = currentPage === pages.length - 1;
            
            // Прокручиваем к началу
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function preloadImages() {
            // Предзагружаем соседние страницы
            const indicesToPreload = [currentPage + 1, currentPage + 2, currentPage - 1];
            indicesToPreload.forEach(index => {
                if (index >= 0 && index < pages.length && pages[index]) {
                    const img = new Image();
                    img.src = pages[index];
                }
            });
        }
        
        function nextPage() {
            if (currentPage < pages.length - 1) {
                currentPage++;
                renderCurrentPage();
                preloadImages();
            }
        }
        
        function prevPage() {
            if (currentPage > 0) {
                currentPage--;
                renderCurrentPage();
                preloadImages();
            }
        }
        
        // Обработка клавиш
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') {
                nextPage();
            } else if (e.key === 'ArrowLeft') {
                prevPage();
            }
        });
        
        // Загружаем страницы при загрузке
        loadPages();
    </script>
</body>
</html>
    <?php
    exit;
}

// Главная страница - каталог
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manga Reader - Каталог манги</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 100%);
            color: #e0e0e0;
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 40px 20px;
            text-align: center;
            border-bottom: 1px solid #2a2a4e;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #ff6b6b, #ff8e8e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.8;
        }
        
        .search-section {
            padding: 30px 20px;
            background: rgba(26, 26, 46, 0.5);
        }
        
        .search-container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .search-input {
            width: 100%;
            padding: 15px 20px;
            font-size: 1rem;
            border: 2px solid #2a2a4e;
            border-radius: 50px;
            background: #1a1a2e;
            color: #e0e0e0;
            outline: none;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: #ff6b6b;
            box-shadow: 0 0 10px rgba(255, 107, 107, 0.3);
        }
        
        .search-input::placeholder {
            color: #666;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .manga-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }
        
        .manga-card {
            background: #1a1a2e;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #2a2a4e;
        }
        
        .manga-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border-color: #ff6b6b;
        }
        
        .manga-cover {
            width: 100%;
            height: 400px;
            object-fit: cover;
            background: #0a0a0a;
        }
        
        .manga-info {
            padding: 20px;
        }
        
        .manga-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: #ff6b6b;
        }
        
        .manga-desc {
            font-size: 0.9rem;
            opacity: 0.7;
            line-height: 1.4;
            margin-bottom: 15px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .manga-stats {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
        }
        
        .manga-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .manga-stats .likes {
            color: #ff6b6b;
        }
        
        .manga-stats .dislikes {
            color: #888;
        }
        
        .loading, .no-results {
            text-align: center;
            padding: 60px 20px;
            font-size: 1.2rem;
            color: #ff6b6b;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 50px;
            flex-wrap: wrap;
        }
        
        .page-btn {
            background: #2a2a4e;
            border: none;
            color: #e0e0e0;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .page-btn:hover {
            background: #ff6b6b;
            transform: translateY(-2px);
        }
        
        .page-btn.active {
            background: #ff6b6b;
        }
        
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 1.8rem;
            }
            
            .manga-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 20px;
            }
            
            .manga-cover {
                height: 350px;
            }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .manga-card {
            animation: fadeInUp 0.5s ease forwards;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📚 Manga Reader</h1>
        <p>Читай мангу онлайн бесплатно</p>
    </div>
    
    <div class="search-section">
        <div class="search-container">
            <input type="text" class="search-input" id="searchInput" placeholder="🔍 Поиск манги по названию..." onkeyup="handleSearch(event)">
        </div>
    </div>
    
    <div class="container">
        <div id="mangaGrid" class="manga-grid">
            <div class="loading">📖 Загрузка каталога...</div>
        </div>
        <div id="pagination" class="pagination"></div>
    </div>
    
    <script>
        let currentPage = 1;
        let currentQuery = '';
        let totalPages = 1;
        
        async function loadManga(page = 1, query = '') {
            const grid = document.getElementById('mangaGrid');
            grid.innerHTML = '<div class="loading">📖 Загрузка каталога...</div>';
            
            try {
                let url = `/api/manga?page=${page}&limit=12`;
                if (query) {
                    url += `&search=${encodeURIComponent(query)}`;
                }
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.manga && data.manga.length > 0) {
                    renderMangaGrid(data.manga);
                    totalPages = data.totalPages;
                    renderPagination(page, totalPages);
                } else {
                    grid.innerHTML = '<div class="no-results">😔 Манга не найдена. Попробуйте другой поисковый запрос.</div>';
                    document.getElementById('pagination').innerHTML = '';
                }
            } catch (error) {
                grid.innerHTML = '<div class="no-results">❌ Ошибка загрузки каталога. Пожалуйста, обновите страницу.</div>';
                console.error('Error loading manga:', error);
            }
        }
        
        function renderMangaGrid(mangaList) {
            const grid = document.getElementById('mangaGrid');
            grid.innerHTML = '';
            
            mangaList.forEach(manga => {
                const card = document.createElement('div');
                card.className = 'manga-card';
                card.onclick = () => window.location.href = `/read/${manga.id}`;
                
                const coverHtml = manga.cover_imgbb_url 
                    ? `<img class="manga-cover" src="${manga.cover_imgbb_url}" alt="${escapeHtml(manga.title)}" loading="lazy">`
                    : `<div class="manga-cover" style="display: flex; align-items: center; justify-content: center; background: #2a2a4e;">
                           <span style="font-size: 3rem;">📖</span>
                       </div>`;
                
                card.innerHTML = `
                    ${coverHtml}
                    <div class="manga-info">
                        <div class="manga-title">${escapeHtml(manga.title)}</div>
                        <div class="manga-desc">${escapeHtml(manga.description || 'Описание отсутствует')}</div>
                        <div class="manga-stats">
                            <span class="likes">👍 ${manga.likes || 0}</span>
                            <span class="dislikes">👎 ${manga.dislikes || 0}</span>
                        </div>
                    </div>
                `;
                
                grid.appendChild(card);
            });
        }
        
        function renderPagination(current, total) {
            const paginationDiv = document.getElementById('pagination');
            if (total <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }
            
            let html = '';
            
            if (current > 1) {
                html += `<button class="page-btn" onclick="goToPage(${current - 1})">◀ Назад</button>`;
            }
            
            const startPage = Math.max(1, current - 2);
            const endPage = Math.min(total, current + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                if (i === current) {
                    html += `<button class="page-btn active" disabled>${i}</button>`;
                } else {
                    html += `<button class="page-btn" onclick="goToPage(${i})">${i}</button>`;
                }
            }
            
            if (current < total) {
                html += `<button class="page-btn" onclick="goToPage(${current + 1})">Вперед ▶</button>`;
            }
            
            paginationDiv.innerHTML = html;
        }
        
        function goToPage(page) {
            currentPage = page;
            loadManga(currentPage, currentQuery);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function handleSearch(event) {
            if (event.key === 'Enter') {
                currentQuery = event.target.value.trim();
                currentPage = 1;
                loadManga(currentPage, currentQuery);
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Загружаем мангу при загрузке страницы
        loadManga(1, '');
    </script>
</body>
</html>

<?php
// API endpoint для получения списка манги с пагинацией
if (preg_match('#^/api/manga$#', $path)) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $offset = ($page - 1) * $limit;
    
    if ($search) {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title LIKE ?");
        $stmtCount->execute(["%$search%"]);
        $total = $stmtCount->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT id, title, description, cover_imgbb_url, likes, dislikes FROM manga WHERE title LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$search%"]);
    } else {
        $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $stmt = $pdo->query("SELECT id, title, description, cover_imgbb_url, likes, dislikes FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");
    }
    
    $manga = $stmt->fetchAll();
    $totalPages = ceil($total / $limit);
    
    header('Content-Type: application/json');
    echo json_encode([
        'manga' => $manga,
        'currentPage' => $page,
        'totalPages' => $totalPages,
        'total' => $total
    ]);
    exit;
}