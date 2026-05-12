<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// База данных [cite: 722-723]
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME'));
try {
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { die("DB Error"); }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API для получения списка манги [cite: 733-740]
if ($path === '/api/manga') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $stmt = $q ? 
        $pdo->prepare("SELECT * FROM manga WHERE title ILIKE ? ORDER BY id DESC") : 
        $pdo->prepare("SELECT * FROM manga ORDER BY id DESC");
    $q ? $stmt->execute(["%$q%"]) : $stmt->execute();
    echo json_encode(['items' => $stmt->fetchAll()]);
    exit;
}

// API страниц для ридера [cite: 741-742]
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id=? ORDER BY page_order");
    $stmt->execute([$m[1]]);
    echo json_encode(['pages' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLACKWATCH CATALOG</title>
    <style>
        body { background: #0f0f0f; color: white; font-family: sans-serif; margin: 0; padding: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; }
        .card { background: #1a1a1a; border-radius: 10px; overflow: hidden; text-decoration: none; color: white; transition: 0.2s; }
        .card:active { transform: scale(0.95); }
        .cover { width: 100%; height: 220px; object-fit: cover; background: #222; }
        .info { padding: 10px; font-size: 14px; text-align: center; }
    </style>
</head>
<body>
    <div class="grid" id="catalog"></div>
    <script>
        async function load() {
            const res = await fetch('/api/manga');
            const data = await res.json();
            const container = document.getElementById('catalog');
            data.items.forEach(m => {
                container.innerHTML += `
                    <a href="/read/${m.id}" class="card">
                        <img class="cover" src="${m.cover_imgbb_url || 'https://via.placeholder.com/150x220?text=No+Cover'}">
                        <div class="info">${m.title}</div>
                    </a>`;
            });
        }
        load();
    </script>
</body>
</html>