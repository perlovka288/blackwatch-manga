<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
// --- DB Connection ---
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
    die('DB Error: ' . $e->getMessage());
}
$botUsername = getenv('BOT_USERNAME') ?: 'Manga123Manga123bot';
$siteUrl     = rtrim(getenv('SITE_URL') ?: 'https://blackwatch-manga.onrender.com', '/');
// --- Routing ---
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
// === API: manga pages ===
if (preg_match('#^/api/pages/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)$m[1];
    $stmt = $pdo->prepare('SELECT page_url FROM manga_pages WHERE manga_id = ? ORDER BY page_order ASC');
    $stmt->execute([$id]);
    $pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($pages)) {
        $s = $pdo->prepare('SELECT file_id FROM manga WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch();
        echo json_encode(['pages' => [], 'telegraph' => $r['file_id'] ?? null]);
    } else {
        echo json_encode(['pages' => $pages, 'telegraph' => null]);
    }
    exit;
}
// === API: manga search ===
if ($path === '/api/manga') {
    header('Content-Type: application/json; charset=utf-8');
    $q      = trim($_GET['q'] ?? '');
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $limit  = 12;
    $offset = $page * $limit;
    if ($q) {
        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM manga WHERE title ILIKE ?');
        $totalStmt->execute(["%$q%"]);
        $total = (int)$totalStmt->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT id,title,description,cover_id,cover_imgbb_url,likes,file_id
            FROM manga WHERE title ILIKE ?
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute(["%$q%"]);
    } else {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM manga')->fetchColumn();
        $stmt  = $pdo->query("
            SELECT id,title,description,cover_id,cover_imgbb_url,likes,file_id
            FROM manga ORDER BY id DESC
            LIMIT $limit OFFSET $offset
        ");
    }
    $items = $stmt->fetchAll();
    foreach ($items as &$i) {
        if (!empty($i['cover_imgbb_url'])) {
            $i['cover_display'] = $i['cover_imgbb_url'];
        } elseif (!empty($i['cover_id']) && str_starts_with($i['cover_id'], 'http')) {
            $i['cover_display'] = $i['cover_id'];
        } else {
            $i['cover_display'] = null;
        }
    }
    unset($i);
    echo json_encode([
        'items' => $items,
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
    ]);
    exit;
}
// === Reader page ===
if (preg_match('#^/read/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    renderReader($pdo, $id, $siteUrl, $botUsername);
    exit;
}
// === Main catalog ===
renderCatalog($pdo, $siteUrl, $botUsername);
// =======================================================
// Catalog Renderer
// =======================================================
function renderCatalog($pdo, $siteUrl, $botUsername)
{
    $top = $pdo->query("
        SELECT id,title,description,cover_id,cover_imgbb_url,likes
        FROM manga WHERE likes > 0
        ORDER BY likes DESC
        LIMIT 5
    ")->fetchAll();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM manga')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BLACKWATCH — Каталог манги</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
 --bg:#0a0a0f;--bg2:#111118;--bg3:#1a1a24;--accent:#3b82f6;
 --text:#f0f0f5;--muted:#8585a0;--border:#2a2a3a;
}
body{margin:0;background:var(--bg);color:var(--text);font-family:'Inter',sans-serif}
header{position:sticky;top:0;background:rgba(0,0,0,.8);backdrop-filter:blur(10px);
border-bottom:1px solid var(--border);padding:12px 20px;display:flex;align-items:center;gap:18px}
.header-logo{font-family:'Bebas Neue',sans-serif;color:var(--text);font-size:26px;letter-spacing:3px;text-decoration:none}
.header-logo span{color:var(--accent);font-size:13px;display:block;margin-top:-6px}
.search-box{flex:1;display:flex;align-items:center;background:var(--bg2);
border:1px solid var(--border);border-radius:10px;padding:0 12px}
.search-box input{flex:1;background:none;border:none;color:var(--text);padding:10px;font-size:14px}
.manga-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}
.manga-card{display:flex;flex-direction:column;background:var(--bg2);border:1px solid var(--border);
border-radius:12px;overflow:hidden;text-decoration:none;color:var(--text)}
.manga-card:hover{border-color:var(--accent);transform:translateY(-3px)}
.manga-cover{width:100%;aspect-ratio:2/3;object-fit:cover;background:var(--bg3)}
.manga-info{padding:10px 12px}
.manga-title{font-size:13px;font-weight:600;line-height:1.4}
.manga-likes{font-size:11px;color:#ffd166;margin-top:4px}
</style>
</head>
<body>
<header>
  <a href="/" class="header-logo">BLACKWATCH<span>манга</span></a>
  <div class="search-box">
    <input id="searchInput" placeholder="Поиск манги ..." autocomplete="off">
  </div>
</header>
<section style="padding:40px 20px;text-align:center">
  <h1 style="font-family:'Bebas Neue',sans-serif;letter-spacing:8px;margin:0">BLACKWATCH</h1>
  <span style="color:var(--accent);letter-spacing:4px;text-transform:uppercase">манга</span>
</section>
<?php if($top): ?>
<section style="max-width:1200px;margin:auto;padding:0 20px 40px">
  <div style="font-size:12px;color:var(--muted);margin-bottom:12px">🔥 Топ по лайкам</div>
  <div style="display:flex;gap:16px;overflow-x:auto;padding-bottom:6px">
  <?php foreach($top as $t):
        $img=$t['cover_imgbb_url']?:$t['cover_id']; ?>
    <a href="/read/<?= $t['id'] ?>" class="manga-card" style="width:200px;flex-shrink:0">
      <?php if($img): ?><img src="<?= htmlspecialchars($img) ?>" class="manga-cover" loading="lazy"><?php endif; ?>
      <div class="manga-info">
        <div class="manga-title"><?= htmlspecialchars($t['title']) ?></div>
        <div class="manga-likes">❤ <?= (int)$t['likes'] ?></div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<section class="catalog-section" style="max-width:1200px;margin:auto;padding:0 20px 80px">
  <div class="catalog-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
    <div class="section-label">📚 Каталог</div>
    <div class="total-count" style="font-size:13px;color:var(--muted)">Всего: <strong><?= $total ?></strong></div>
  </div>
  <div id="mangaGrid" class="manga-grid"></div>
  <div style="text-align:center;margin-top:32px">
    <button id="loadMoreBtn" style="padding:10px 24px;border:1px solid var(--border);
    background:var(--bg2);color:var(--text);border-radius:8px">Загрузить ещё</button>
  </div>
</section>
<script>
let page=0,isLoading=false,hasMore=true,q='';
const grid=document.getElementById('mangaGrid'),btn=document.getElementById('loadMoreBtn'),
input=document.getElementById('searchInput');
async function load(reset=false){
 if(isLoading)return;isLoading=true;
 if(reset){page=0;grid.innerHTML='';}
 const res=await fetch(`/api/manga?q=${encodeURIComponent(q)}&page=${page}`);
 const d=await res.json();
 if(reset)grid.innerHTML='';
 if(!d.items.length&&page===0){grid.innerHTML='<p>Ничего не найдено</p>';btn.style.display='none';return;}
 d.items.forEach(m=>{
   const url=m.cover_display||'',title=m.title.replace(/^❤️\s*/,'');
   grid.insertAdjacentHTML('beforeend',
   `<a href="/read/${m.id}" class="manga-card">
     ${url?`<img src="${url}" class="manga-cover" onerror="this.style.display='none'">`
           :`<div class='manga-cover' style='display:flex;align-items:center;justify-content:center'>📖</div>`}
     <div class='manga-info'><div class='manga-title'>${title}</div>
     ${m.likes>0?`<div class='manga-likes'>❤ ${m.likes}</div>`:''}</div></a>`);
 });
 hasMore=(page+1)*d.limit<d.total;btn.style.display=hasMore?'inline-block':'none';page++;
 isLoading=false;
}
input.addEventListener('input',()=>{q=input.value.trim();load(true);});
btn.addEventListener('click',()=>load(false));
load(true);
</script>
</body>
</html>
<?php
}
// =======================================================
// Reader Renderer
// =======================================================
function renderReader($pdo,$id,$siteUrl,$botUsername){
 $stmt=$pdo->prepare('SELECT * FROM manga WHERE id=?');$stmt->execute([$id]);$m=$stmt->fetch();
 if(!$m){http_response_code(404);echo '<h1>404 Манга не найдена</h1>';return;}
 $pages=$pdo->prepare('SELECT page_url FROM manga_pages WHERE manga_id=? ORDER BY page_order');
 $pages->execute([$id]);$list=$pages->fetchAll(PDO::FETCH_COLUMN);
 $title=htmlspecialchars(preg_replace('/^❤️\s*/u','',$m['title']));
 $cover=$m['cover_imgbb_url']?:($m['cover_id']??'');
?>
<!DOCTYPE html><html lang="ru"><head>
<meta charset="utf-8"><title><?= $title ?> — BLACKWATCH Reader</title>
<style>
body{background:#0a0a0f;color:#f0f0f5;font-family:sans-serif;text-align:center}
img{max-width:100%}
</style></head><body>
<a href="/" style="color:#3b82f6;text-decoration:none">&larr; Назад</a>
<h2><?= $title ?></h2>
<?php if($list): foreach($list as $p): ?>
<img src="<?= htmlspecialchars($p) ?>" loading="lazy"><br>
<?php endforeach; else: ?>
<p>Нет страниц. <a href="<?= htmlspecialchars($m['file_id']) ?>" target="_blank">Читать на Telegraph</a></p>
<?php endif; ?>
</body></html>
<?php }