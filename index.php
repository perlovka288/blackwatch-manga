<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME'));
try {
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { die("DB Error: " . $e->getMessage()); }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga (id SERIAL PRIMARY KEY, title TEXT NOT NULL, file_id TEXT, description TEXT, likes INT DEFAULT 0, dislikes INT DEFAULT 0, added_by BIGINT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, cover_imgbb_url TEXT, telegraph_url TEXT, is_series BOOLEAN DEFAULT FALSE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_pages (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapters (id SERIAL PRIMARY KEY, manga_id INT NOT NULL, chapter_num FLOAT NOT NULL DEFAULT 1, title TEXT, telegraph_url TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_chapter_pages (id SERIAL PRIMARY KEY, chapter_id INT NOT NULL, page_url TEXT NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (user_id BIGINT NOT NULL, manga_id INT NOT NULL, vote_type VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_manga_status (user_id BIGINT NOT NULL, manga_id INT NOT NULL, status VARCHAR(50) NOT NULL, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_progress (user_id BIGINT NOT NULL, manga_id INT NOT NULL, page_num INT DEFAULT 1, total_pages INT DEFAULT 0, chapter_id INT DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_admins (user_id BIGINT PRIMARY KEY)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archive (id SERIAL PRIMARY KEY, action_type VARCHAR(50) NOT NULL, action_text TEXT NOT NULL, action_by BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (user_id BIGINT PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS suggestions (id SERIAL PRIMARY KEY, user_id BIGINT NOT NULL, text TEXT NOT NULL, status VARCHAR(20) DEFAULT 'new', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tags (user_id BIGINT PRIMARY KEY, tag_name VARCHAR(100) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_custom_statuses (id SERIAL PRIMARY KEY, user_id BIGINT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(20) NOT NULL DEFAULT '#7c5cff', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS manga_ratings (user_id BIGINT NOT NULL, manga_id INT NOT NULL, rating INT NOT NULL CHECK(rating BETWEEN 1 AND 10), PRIMARY KEY (user_id, manga_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_messages (id SERIAL PRIMARY KEY, text TEXT NOT NULL, sent_by BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, is_deleted BOOLEAN DEFAULT FALSE)");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS cover_imgbb_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS telegraph_url TEXT");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS dislikes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE manga ADD COLUMN IF NOT EXISTS is_series BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE manga_pages ADD COLUMN IF NOT EXISTS page_url TEXT");
    $pdo->exec("ALTER TABLE reading_progress ADD COLUMN IF NOT EXISTS chapter_id INT DEFAULT NULL");
} catch (Exception $e) {}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
session_start();
if (!isset($_SESSION['guest_id'])) $_SESSION['guest_id'] = rand(1000000, 9999999);

$hardcodedAdmins = [1710365896, 1181510470];
try {
    $stmtAdmins = $pdo->query("SELECT user_id FROM bot_admins");
    foreach ($stmtAdmins as $row) { if (!in_array((int)$row['user_id'], $hardcodedAdmins)) $hardcodedAdmins[] = (int)$row['user_id']; }
} catch (Exception $e) {}

function isAdmin($userId, $admins) { return $userId > 0 && in_array((int)$userId, $admins); }

$imgbbKeys = ['58ff4596fd55028a81cbf8c4e38388e1','6981ba08e7b2a8743aab2c8ea008f675','f9b8d27fa4029816d643c7814fd60c60','24dbed2ae9fea9369de6a7b68d0c3ee6','c3e6a55335c71a052c1a59b6a2d6d150'];

function getEffectiveUserId($pdo) {
    $tgUser = $_GET['tg_user_id'] ?? $_POST['tg_user_id'] ?? '';
    if ($tgUser && is_numeric($tgUser)) { if (!headers_sent()) setcookie('tg_user_id', $tgUser, time()+86400*30,'/',''  ,false,false); $_SESSION['tg_user_id']=$tgUser; return (int)$tgUser; }
    if (!empty($_SESSION['tg_user_id']) && is_numeric($_SESSION['tg_user_id'])) return (int)$_SESSION['tg_user_id'];
    if (!empty($_COOKIE['tg_user_id']) && is_numeric($_COOKIE['tg_user_id'])) { $_SESSION['tg_user_id']=$_COOKIE['tg_user_id']; return (int)$_COOKIE['tg_user_id']; }
    return (int)$_SESSION['guest_id'];
}

function sendTgNotify($userId, $text) {
    $botToken = getenv('BOT_TOKEN'); if (!$botToken||!$userId) return;
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$userId,'text'=>$text,'parse_mode'=>'HTML']),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false]);
    @curl_exec($ch); curl_close($ch);
}

function uploadToImgbb($filePathOrUrl, $apiKeys, $retries=2) {
    $keys = is_array($apiKeys)?$apiKeys:[$apiKeys];
    foreach ($keys as $key) {
        for ($attempt=0;$attempt<=$retries;$attempt++) {
            try {
                $imageData=base64_encode(file_get_contents($filePathOrUrl));
                $ch=curl_init('https://api.imgbb.com/1/upload');
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['key'=>$key,'image'=>$imageData],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>false]);
                $response=curl_exec($ch); $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                if ($httpCode===200){$data=json_decode($response,true);if(!empty($data['data']['url']))return $data['data']['url'];}
                if ($httpCode===400||$httpCode===429) break;
            } catch (Exception $e) {}
        }
    }
    return null;
}

function extractAndSortZip($zipPath, $extractDir) {
    $allowed=['jpg','jpeg','png','webp','gif']; $raw=file_get_contents($zipPath); if($raw===false)return false;
    $len=strlen($raw); $eocdPos=false;
    for($i=$len-22;$i>=max(0,$len-65557);$i--){if(substr($raw,$i,4)==="\x50\x4b\x05\x06"){$eocdPos=$i;break;}}
    if($eocdPos===false)return false;
    $cdCount=unpack('v',substr($raw,$eocdPos+10,2))[1]; $cdOffset=unpack('V',substr($raw,$eocdPos+16,4))[1];
    $entries=[];$pos=$cdOffset;
    for($n=0;$n<$cdCount;$n++){
        if($pos+46>$len)break; if(substr($raw,$pos,4)!=="\x50\x4b\x01\x02")break;
        $modTime=unpack('v',substr($raw,$pos+12,2))[1]; $modDate=unpack('v',substr($raw,$pos+14,2))[1];
        $compSize=unpack('V',substr($raw,$pos+20,4))[1]; $origSize=unpack('V',substr($raw,$pos+24,4))[1];
        $fnameLen=unpack('v',substr($raw,$pos+28,2))[1]; $extraLen=unpack('v',substr($raw,$pos+30,2))[1];
        $cmtLen=unpack('v',substr($raw,$pos+32,2))[1]; $compress=unpack('v',substr($raw,$pos+10,2))[1];
        $localHdrOffset=unpack('V',substr($raw,$pos+42,4))[1]; $fname=substr($raw,$pos+46,$fnameLen);
        $pos+=46+$fnameLen+$extraLen+$cmtLen;
        if(substr($fname,-1)==='/')continue; $base=basename($fname);
        if($base===''||$base[0]==='.')continue; $ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));
        if(!in_array($ext,$allowed))continue;
        $lhPos=$localHdrOffset; if($lhPos+30>$len)continue; if(substr($raw,$lhPos,4)!=="\x50\x4b\x03\x04")continue;
        $lFnameLen=unpack('v',substr($raw,$lhPos+26,2))[1]; $lExtraLen=unpack('v',substr($raw,$lhPos+28,2))[1];
        $dataOffset=$lhPos+30+$lFnameLen+$lExtraLen;
        $second=($modTime&0x1F)*2; $minute=($modTime>>5)&0x3F; $hour=($modTime>>11)&0x1F;
        $day=$modDate&0x1F; $month=($modDate>>5)&0x0F; $year=(($modDate>>9)&0x7F)+1980;
        $mtime=mktime($hour,$minute,$second,$month,$day,$year);
        $entries[]=['name'=>$fname,'base'=>$base,'mtime'=>$mtime,'compress'=>$compress,'compSize'=>$compSize,'origSize'=>$origSize,'dataOffset'=>$dataOffset];
    }
    if(empty($entries))return false;
    usort($entries,function($a,$b){if($a['mtime']===$b['mtime'])return strnatcasecmp($a['base'],$b['base']);return $a['mtime']-$b['mtime'];});
    $extractedPaths=[];
    foreach($entries as $entry){
        $outPath=$extractDir.'/'.$entry['base'];
        if($entry['compress']===0){$fileData=substr($raw,$entry['dataOffset'],$entry['origSize']);}
        elseif($entry['compress']===8){$compressed=substr($raw,$entry['dataOffset'],$entry['compSize']);$fileData=@gzinflate($compressed);if($fileData===false)continue;}
        else{continue;}
        if(file_put_contents($outPath,$fileData)===false)continue;
        $extractedPaths[]=$outPath;
    }
    return empty($extractedPaths)?false:$extractedPaths;
}

function createTelegraphPage($title, $imageUrls) {
    $nodes=[]; $imageUrls=array_values(array_unique(array_filter($imageUrls)));
    foreach($imageUrls as $url){if(!empty($url))$nodes[]=['tag'=>'img','attrs'=>['src'=>$url]];}
    if(empty($nodes))return false;
    $accessToken='192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831';
    $postData=http_build_query(['access_token'=>$accessToken,'title'=>mb_substr($title,0,256),'author_name'=>'MangaBot','content'=>json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'return_content'=>'false']);
    $ch=curl_init("https://api.telegra.ph/createPage");
    curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$postData,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>false]);
    $rawResponse=curl_exec($ch); curl_close($ch);
    $res=json_decode($rawResponse,true);
    if(!isset($res['ok'])||!$res['ok'])return false;
    return $res['result']['url']??false;
}

function saveMangaPages($pdo,$mangaId,$pageUrls){
    $pdo->prepare("DELETE FROM manga_pages WHERE manga_id=?")->execute([$mangaId]);
    $stmt=$pdo->prepare("INSERT INTO manga_pages (manga_id,page_url,page_order) VALUES (?,?,?)");
    foreach($pageUrls as $i=>$url){if(!empty($url))$stmt->execute([$mangaId,$url,$i]);}
}

function saveChapterPages($pdo,$chapterId,$pageUrls){
    $pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id=?")->execute([$chapterId]);
    $stmt=$pdo->prepare("INSERT INTO manga_chapter_pages (chapter_id,page_url,page_order) VALUES (?,?,?)");
    foreach($pageUrls as $i=>$url){if(!empty($url))$stmt->execute([$chapterId,$url,$i]);}
}

function logArchiveEntry($pdo,$action_type,$action_text,$userId){
    try{$pdo->prepare("INSERT INTO bot_archive (action_type,action_text,action_by) VALUES (?,?,?)")->execute([$action_type,$action_text,$userId]);}catch(Exception $e){}
}

function extractImgFromContent($nodes){
    $urls=[];if(!is_array($nodes))return $urls;
    foreach($nodes as $node){
        if(!is_array($node))continue;
        if(isset($node['tag'])&&$node['tag']==='img'&&!empty($node['attrs']['src'])){$src=$node['attrs']['src'];$urls[]=strpos($src,'http')===0?$src:'https://telegra.ph'.$src;}
        if(!empty($node['children']))$urls=array_merge($urls,extractImgFromContent($node['children']));
    }
    return $urls;
}

# ========================= API =========================

if ($path==='/api/check-admin'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);echo json_encode(['is_admin'=>isAdmin($userId,$hardcodedAdmins),'user_id'=>$userId]);exit;}

if ($path==='/api/imgbb-keys'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['success'=>false,'keys'=>[]]);exit;}echo json_encode(['success'=>true,'keys'=>$imgbbKeys]);exit;}

if ($path==='/api/save-manga'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    try{
        $userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}
        $input=json_decode(file_get_contents('php://input'),true);
        $title=trim($input['title']??'');$description=trim($input['description']??'');$coverUrl=trim($input['cover_url']??'');$pageUrls=array_values(array_filter($input['page_urls']??[]));$isSeries=!empty($input['is_series']);
        if(!$title){echo json_encode(['success'=>false,'error'=>'Название обязательно']);exit;}
        $telegraphLink=null;if(!empty($pageUrls)&&!$isSeries)$telegraphLink=createTelegraphPage('♥ '.$title,$pageUrls);
        $insertStmt=$pdo->prepare("INSERT INTO manga (title,telegraph_url,description,cover_imgbb_url,added_by,is_series) VALUES (?,?,?,?,?,?) RETURNING id");
        $insertStmt->execute(['♥ '.$title,$telegraphLink,$description,$coverUrl?:null,$userId,$isSeries]);
        $row=$insertStmt->fetch(PDO::FETCH_ASSOC);$newMangaId=(int)($row['id']??0);
        if(!$newMangaId)$newMangaId=(int)$pdo->lastInsertId();
        if(!$newMangaId){echo json_encode(['success'=>false,'error'=>'Не удалось получить ID']);exit;}
        if(!empty($pageUrls)&&!$isSeries)saveMangaPages($pdo,$newMangaId,$pageUrls);
        logArchiveEntry($pdo,'add_manga',"Добавлена манга: ♥ $title",$userId);
        $siteUrl=rtrim(getenv('SITE_URL')?:'','/');
        echo json_encode(['success'=>true,'manga_id'=>$newMangaId,'telegraph'=>$telegraphLink,'pages'=>count($pageUrls),'site_url'=>"{$siteUrl}/read/{$newMangaId}"]);
    }catch(Throwable $e){echo json_encode(['success'=>false,'error'=>'Исключение: '.$e->getMessage()]);}
    exit;
}

if ($path==='/api/save-chapter'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    try{
        $userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}
        $input=json_decode(file_get_contents('php://input'),true);$mangaId=(int)($input['manga_id']??0);$chapterNum=(float)($input['chapter_num']??1);$chTitle=trim($input['chapter_title']??'');$pageUrls=array_values(array_filter($input['page_urls']??[]));
        if(!$mangaId){echo json_encode(['success'=>false,'error'=>'Не указан manga_id']);exit;}
        $mangaStmt=$pdo->prepare("SELECT title FROM manga WHERE id=?");$mangaStmt->execute([$mangaId]);$manga=$mangaStmt->fetch();
        if(!$manga){echo json_encode(['success'=>false,'error'=>'Манга не найдена']);exit;}
        $telegraphLink=null;
        if(!empty($pageUrls)){$chapterLabel="Глава $chapterNum".($chTitle?": $chTitle":'');$telegraphLink=createTelegraphPage($manga['title'].' — '.$chapterLabel,$pageUrls);}
        $chInsert=$pdo->prepare("INSERT INTO manga_chapters (manga_id,chapter_num,title,telegraph_url) VALUES (?,?,?,?) RETURNING id");
        $chInsert->execute([$mangaId,$chapterNum,$chTitle?:null,$telegraphLink]);$chRow=$chInsert->fetch();$chapterId=(int)($chRow['id']??0);
        if($chapterId&&!empty($pageUrls))saveChapterPages($pdo,$chapterId,$pageUrls);
        $pdo->prepare("UPDATE manga SET is_series=TRUE WHERE id=?")->execute([$mangaId]);
        logArchiveEntry($pdo,'add_chapter',"Добавлена глава $chapterNum для манги: {$manga['title']}",$userId);
        echo json_encode(['success'=>true,'chapter_id'=>$chapterId,'telegraph'=>$telegraphLink]);
    }catch(Throwable $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}
    exit;
}

if ($path==='/api/manga'){
    header('Content-Type: application/json');
    $page=max(0,(int)($_GET['page']??0));$q=trim($_GET['q']??'');$sort=$_GET['sort']??'new';$limit=24;$offset=$page*$limit;
    $orderBy='id DESC';if($sort==='popular')$orderBy='likes DESC, id DESC';if($sort==='alpha')$orderBy='title ASC';
    if($q){$stmt=$pdo->prepare("SELECT id,title,likes,dislikes,cover_imgbb_url,file_id,created_at,is_series FROM manga WHERE LOWER(title) LIKE LOWER(?) ORDER BY $orderBy LIMIT ? OFFSET ?");$stmt->execute(["%{$q}%",$limit,$offset]);$count=$pdo->prepare("SELECT COUNT(*) FROM manga WHERE LOWER(title) LIKE LOWER(?)");$count->execute(["%{$q}%"]);}
    else{$stmt=$pdo->prepare("SELECT id,title,likes,dislikes,cover_imgbb_url,file_id,created_at,is_series FROM manga ORDER BY $orderBy LIMIT ? OFFSET ?");$stmt->execute([$limit,$offset]);$count=$pdo->query("SELECT COUNT(*) FROM manga");}
    $now=date('Y-m-d H:i:s',time()-86400);$items=[];
    foreach($stmt as $m){
        $chapCount=0;if($m['is_series']){$cStmt=$pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE manga_id=?");$cStmt->execute([$m['id']]);$chapCount=(int)$cStmt->fetchColumn();}
        $rStmt=$pdo->prepare("SELECT AVG(rating) as avg FROM manga_ratings WHERE manga_id=?");$rStmt->execute([$m['id']]);$rRow=$rStmt->fetch();$avgRating=$rRow['avg']?round((float)$rRow['avg'],1):0;
        $items[]=['id'=>(int)$m['id'],'title'=>$m['title'],'likes'=>(int)$m['likes'],'dislikes'=>(int)$m['dislikes'],'cover_display'=>!empty($m['cover_imgbb_url'])?$m['cover_imgbb_url']:(!empty($m['file_id'])?'tg://'.$m['file_id']:null),'is_new'=>($m['created_at']>=$now),'is_series'=>(bool)$m['is_series'],'chapter_count'=>$chapCount,'avg_rating'=>$avgRating];
    }
    echo json_encode(['items'=>$items,'total'=>(int)$count->fetchColumn(),'limit'=>$limit]);exit;
}

if ($path==='/api/new-manga'){
    header('Content-Type: application/json');$since=date('Y-m-d H:i:s',time()-86400);
    $stmt=$pdo->prepare("SELECT id,title,likes,dislikes,cover_imgbb_url,file_id,created_at,is_series FROM manga WHERE created_at>=? ORDER BY created_at DESC LIMIT 20");$stmt->execute([$since]);
    $items=[];foreach($stmt as $m){$items[]=['id'=>(int)$m['id'],'title'=>$m['title'],'likes'=>(int)$m['likes'],'cover_display'=>!empty($m['cover_imgbb_url'])?$m['cover_imgbb_url']:null,'is_series'=>(bool)$m['is_series']];}
    echo json_encode(['items'=>$items]);exit;
}

if ($path==='/api/random'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);$stmt=$pdo->prepare("SELECT id FROM manga WHERE id NOT IN (SELECT manga_id FROM user_manga_status WHERE user_id=? AND status='read') ORDER BY RANDOM() LIMIT 1");$stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)$row=$pdo->query("SELECT id FROM manga ORDER BY RANDOM() LIMIT 1")->fetch();echo json_encode(['id'=>$row?(int)$row['id']:null]);exit;}

if (preg_match('#^/api/chapters/(\d+)$#',$path,$m)){header('Content-Type: application/json');$mangaId=(int)$m[1];$stmt=$pdo->prepare("SELECT id,chapter_num,title,telegraph_url,created_at FROM manga_chapters WHERE manga_id=? ORDER BY chapter_num ASC");$stmt->execute([$mangaId]);$chapters=$stmt->fetchAll();foreach($chapters as &$ch){$pStmt=$pdo->prepare("SELECT COUNT(*) FROM manga_chapter_pages WHERE chapter_id=?");$pStmt->execute([$ch['id']]);$ch['page_count']=(int)$pStmt->fetchColumn();}echo json_encode(['chapters'=>$chapters]);exit;}

if (preg_match('#^/api/chapter-pages/(\d+)$#',$path,$m)){
    header('Content-Type: application/json');$chapterId=(int)$m[1];
    $stmt=$pdo->prepare("SELECT page_url FROM manga_chapter_pages WHERE chapter_id=? ORDER BY page_order ASC");$stmt->execute([$chapterId]);$pages=$stmt->fetchAll(PDO::FETCH_COLUMN);
    if(empty($pages)){$chStmt=$pdo->prepare("SELECT telegraph_url FROM manga_chapters WHERE id=?");$chStmt->execute([$chapterId]);$ch=$chStmt->fetch();if(!empty($ch['telegraph_url'])){$tPath=ltrim(parse_url($ch['telegraph_url'],PHP_URL_PATH),'/');$ctx=stream_context_create(['http'=>['timeout'=>10,'user_agent'=>'Mozilla/5.0']]);$apiResp=@file_get_contents("https://api.telegra.ph/getPage/".$tPath."?return_content=true",false,$ctx);if($apiResp){$apiData=json_decode($apiResp,true);if(!empty($apiData['ok'])&&!empty($apiData['result']['content']))$pages=extractImgFromContent($apiData['result']['content']);}}}
    echo json_encode(['pages'=>array_values($pages)]);exit;
}

if (preg_match('#^/api/pages/(\d+)$#',$path,$m)){
    header('Content-Type: application/json');$id=(int)$m[1];
    $stmt=$pdo->prepare("SELECT page_url FROM manga_pages WHERE manga_id=? AND page_url IS NOT NULL AND page_url!='' ORDER BY page_order ASC");$stmt->execute([$id]);$pages=$stmt->fetchAll(PDO::FETCH_COLUMN);
    if(empty($pages)){$mangaStmt=$pdo->prepare("SELECT telegraph_url FROM manga WHERE id=?");$mangaStmt->execute([$id]);$manga=$mangaStmt->fetch();$tUrl=$manga['telegraph_url']??null;if($tUrl){$tPath=ltrim(parse_url($tUrl,PHP_URL_PATH),'/');$ctx=stream_context_create(['http'=>['timeout'=>10,'user_agent'=>'Mozilla/5.0']]);$apiResp=@file_get_contents("https://api.telegra.ph/getPage/".$tPath."?return_content=true",false,$ctx);if($apiResp){$apiData=json_decode($apiResp,true);if(!empty($apiData['ok'])&&!empty($apiData['result']['content']))$pages=extractImgFromContent($apiData['result']['content']);}if(!empty($pages)&&strpos($pages[0],'ibb.co')!==false)array_shift($pages);if(empty($pages)){echo json_encode(['pages'=>[],'telegraph_url'=>$tUrl]);exit;}}}
    echo json_encode(['pages'=>array_values($pages)]);exit;
}

if ($path==='/api/progress'&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$input=json_decode(file_get_contents('php://input'),true);$userId=getEffectiveUserId($pdo);if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id']))$userId=(int)$input['tg_user_id'];$mangaId=(int)($input['manga_id']??0);$pageNum=(int)($input['page_num']??1);$totalPages=(int)($input['total_pages']??0);$chapterId=isset($input['chapter_id'])?(int)$input['chapter_id']:null;if($mangaId&&$userId){$pdo->prepare("INSERT INTO reading_progress (user_id,manga_id,page_num,total_pages,chapter_id,updated_at) VALUES (?,?,?,?,?,NOW()) ON CONFLICT (user_id,manga_id) DO UPDATE SET page_num=EXCLUDED.page_num,total_pages=EXCLUDED.total_pages,chapter_id=EXCLUDED.chapter_id,updated_at=NOW()")->execute([$userId,$mangaId,$pageNum,$totalPages,$chapterId]);echo json_encode(['success'=>true]);}else{echo json_encode(['success'=>false]);}exit;}

if ($path==='/api/progress'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);
$stmt=$pdo->prepare("SELECT m.id,m.title,m.cover_imgbb_url,m.file_id,m.is_series,rp.page_num,rp.total_pages,rp.updated_at,rp.chapter_id,s.status,mc.chapter_num FROM user_manga_status s JOIN manga m ON s.manga_id=m.id LEFT JOIN reading_progress rp ON rp.manga_id=m.id AND rp.user_id=s.user_id LEFT JOIN manga_chapters mc ON mc.id=rp.chapter_id WHERE s.user_id=? AND s.status IN ('now','will') ORDER BY rp.updated_at DESC NULLS LAST LIMIT 20");
$stmt->execute([$userId]);$rows=$stmt->fetchAll();
foreach($rows as &$row){
    if($row['is_series'] && empty($row['chapter_id'])){
        $fStmt=$pdo->prepare("SELECT id,chapter_num,title,telegraph_url FROM manga_chapters WHERE manga_id=? ORDER BY chapter_num ASC LIMIT 1");
        $fStmt->execute([$row['id']]);$first=$fStmt->fetch();
        if($first){$row['chapter_id']=$first['id'];$row['chapter_num']=$first['chapter_num'];$row['chapter_title']=$first['title'];$row['chapter_telegraph']=$first['telegraph_url'];$row['is_first_chapter']=true;}
    }
}unset($row);
echo json_encode(['items'=>$rows]);exit;}

if (preg_match('#^/api/cover/(.+)$#',$path,$m)){$fileId=$m[1];$token=getenv('BOT_TOKEN');$ctx=stream_context_create(['http'=>['timeout'=>10]]);$res=@file_get_contents("https://api.telegram.org/bot{$token}/getFile?file_id=".urlencode($fileId),false,$ctx);if($res){$data=json_decode($res,true);if(!empty($data['result']['file_path'])){header("Location: https://api.telegram.org/file/bot{$token}/".$data['result']['file_path'],true,302);exit;}}http_response_code(404);exit;}

if ($path==='/api/vote'&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$input=json_decode(file_get_contents('php://input'),true);$userId=getEffectiveUserId($pdo);if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id']))$userId=(int)$input['tg_user_id'];$mangaId=(int)($input['manga_id']??0);$voteType=$input['vote_type']??'';if($mangaId&&in_array($voteType,['like','dislike'])){$check=$pdo->prepare("SELECT vote_type FROM votes WHERE user_id=? AND manga_id=?");$check->execute([$userId,$mangaId]);$existing=$check->fetch();if($existing){if($existing['vote_type']!==$voteType){$pdo->prepare("UPDATE votes SET vote_type=? WHERE user_id=? AND manga_id=?")->execute([$voteType,$userId,$mangaId]);if($voteType=='like')$pdo->prepare("UPDATE manga SET likes=likes+1,dislikes=GREATEST(0,dislikes-1) WHERE id=?")->execute([$mangaId]);else $pdo->prepare("UPDATE manga SET dislikes=dislikes+1,likes=GREATEST(0,likes-1) WHERE id=?")->execute([$mangaId]);}}else{$pdo->prepare("INSERT INTO votes (user_id,manga_id,vote_type) VALUES (?,?,?)")->execute([$userId,$mangaId,$voteType]);if($voteType=='like')$pdo->prepare("UPDATE manga SET likes=likes+1 WHERE id=?")->execute([$mangaId]);else $pdo->prepare("UPDATE manga SET dislikes=dislikes+1 WHERE id=?")->execute([$mangaId]);}$stmt=$pdo->prepare("SELECT likes,dislikes FROM manga WHERE id=?");$stmt->execute([$mangaId]);$stats=$stmt->fetch();echo json_encode(['success'=>true,'likes'=>(int)$stats['likes'],'dislikes'=>(int)$stats['dislikes']]);exit;}echo json_encode(['success'=>false]);exit;}

if ($path==='/api/status'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $input=json_decode(file_get_contents('php://input'),true);
    $userId=getEffectiveUserId($pdo);
    if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id'])){$userId=(int)$input['tg_user_id'];$_SESSION['tg_user_id']=$userId;if(!headers_sent())setcookie('tg_user_id',$userId,time()+86400*30,'/',''  ,false,false);}
    $mangaId=(int)($input['manga_id']??0);$status=$input['status']??'';
    if($mangaId&&$status){
        $pdo->prepare("INSERT INTO user_manga_status (user_id,manga_id,status) VALUES (?,?,?) ON CONFLICT (user_id,manga_id) DO UPDATE SET status=EXCLUDED.status")->execute([$userId,$mangaId,$status]);
        echo json_encode(['success'=>true,'user_id'=>$userId]);exit;
    }
    echo json_encode(['success'=>false]);exit;
}

if ($path==='/api/library'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);$stmt=$pdo->prepare("SELECT m.id,m.title,m.cover_imgbb_url,m.file_id,s.status, COALESCE((SELECT AVG(r.rating) FROM manga_ratings r WHERE r.manga_id=m.id),0) as avg_rating FROM user_manga_status s JOIN manga m ON s.manga_id=m.id WHERE s.user_id=?");$stmt->execute([$userId]);$items=$stmt->fetchAll();foreach($items as &$row){$row['avg_rating']=round((float)$row['avg_rating'],1);}unset($row);echo json_encode(['items'=>$items,'user_id'=>$userId]);exit;}

if ($path==='/api/suggest'&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$input=json_decode(file_get_contents('php://input'),true);$userId=getEffectiveUserId($pdo);$text=trim($input['text']??'');if(!$text){echo json_encode(['success'=>false,'error'=>'Пустое сообщение']);exit;}$pdo->prepare("INSERT INTO suggestions (user_id,text) VALUES (?,?)")->execute([$userId,$text]);foreach($hardcodedAdmins as $adminId)sendTgNotify($adminId,"💡 Новое предложение от #$userId:\n".mb_substr($text,0,400));echo json_encode(['success'=>true]);exit;}

// API: Custom statuses
if ($path==='/api/custom-statuses'){
    header('Content-Type: application/json');
    $userId=getEffectiveUserId($pdo);
    $stmt=$pdo->prepare("SELECT id,name,color FROM user_custom_statuses WHERE user_id=? ORDER BY created_at ASC");
    $stmt->execute([$userId]);
    echo json_encode(['items'=>$stmt->fetchAll()]);exit;
}

if ($path==='/api/custom-statuses/add'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $input=json_decode(file_get_contents('php://input'),true);
    $userId=getEffectiveUserId($pdo);
    $name=trim($input['name']??'');$color=trim($input['color']??'#7c5cff');
    if(!$name){echo json_encode(['success'=>false,'error'=>'Нет названия']);exit;}
    $pdo->prepare("INSERT INTO user_custom_statuses (user_id,name,color) VALUES (?,?,?)")->execute([$userId,$name,$color]);
    echo json_encode(['success'=>true]);exit;
}

if (preg_match('#^/api/custom-statuses/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $userId=getEffectiveUserId($pdo);
    $pdo->prepare("DELETE FROM user_custom_statuses WHERE id=? AND user_id=?")->execute([(int)$m[1],$userId]);
    echo json_encode(['success'=>true]);exit;
}

// API: Ratings
if ($path==='/api/rate'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $input=json_decode(file_get_contents('php://input'),true);
    $userId=getEffectiveUserId($pdo);
    if(!empty($input['tg_user_id'])&&is_numeric($input['tg_user_id']))$userId=(int)$input['tg_user_id'];
    $mangaId=(int)($input['manga_id']??0);$rating=(int)($input['rating']??0);
    if($mangaId&&$rating>=1&&$rating<=10){
        $pdo->prepare("INSERT INTO manga_ratings (user_id,manga_id,rating) VALUES (?,?,?) ON CONFLICT (user_id,manga_id) DO UPDATE SET rating=EXCLUDED.rating")->execute([$userId,$mangaId,$rating]);
        $avg=$pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM manga_ratings WHERE manga_id=?");$avg->execute([$mangaId]);$r=$avg->fetch();
        echo json_encode(['success'=>true,'avg'=>round((float)$r['avg'],1),'count'=>(int)$r['cnt']]);exit;
    }
    echo json_encode(['success'=>false]);exit;
}

if (preg_match('#^/api/rating/(\d+)$#',$path,$m)){
    header('Content-Type: application/json');$mangaId=(int)$m[1];$userId=getEffectiveUserId($pdo);
    $avg=$pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM manga_ratings WHERE manga_id=?");$avg->execute([$mangaId]);$r=$avg->fetch();
    $myR=$pdo->prepare("SELECT rating FROM manga_ratings WHERE manga_id=? AND user_id=?");$myR->execute([$mangaId,$userId]);$my=$myR->fetchColumn();
    echo json_encode(['avg'=>round((float)$r['avg'],1),'count'=>(int)$r['cnt'],'my_rating'=>$my?:(int)$my]);exit;
}

// API: Admin messages
if ($path==='/api/admin/messages'&&$_SERVER['REQUEST_METHOD']==='GET'){
    header('Content-Type: application/json');
    $stmt=$pdo->query("SELECT id,text,sent_by,created_at FROM admin_messages WHERE is_deleted=FALSE ORDER BY created_at DESC");
    echo json_encode(['items'=>$stmt->fetchAll()]);exit;
}

if ($path==='/api/admin/messages/send'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}
    $input=json_decode(file_get_contents('php://input'),true);$text=trim($input['text']??'');
    if(!$text){echo json_encode(['success'=>false,'error'=>'Пустое сообщение']);exit;}
    $pdo->prepare("INSERT INTO admin_messages (text,sent_by) VALUES (?,?)")->execute([$text,$userId]);
    echo json_encode(['success'=>true]);exit;
}

if (preg_match('#^/api/admin/messages/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['success'=>false,'error'=>'Нет прав']);exit;}
    $pdo->prepare("UPDATE admin_messages SET is_deleted=TRUE WHERE id=?")->execute([(int)$m[1]]);
    echo json_encode(['success'=>true]);exit;
}

if ($path==='/api/admin/stats'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$mangaCount=(int)$pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();$usersCount=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();$votesCount=(int)$pdo->query("SELECT COUNT(*) FROM votes")->fetchColumn();$pagesCount=(int)$pdo->query("SELECT COUNT(*) FROM manga_pages")->fetchColumn();$chaptersCount=(int)$pdo->query("SELECT COUNT(*) FROM manga_chapters")->fetchColumn();$newToday=(int)$pdo->query("SELECT COUNT(*) FROM manga WHERE created_at>=NOW()-INTERVAL '24 hours'")->fetchColumn();$topManga=$pdo->query("SELECT title,likes FROM manga ORDER BY likes DESC LIMIT 5")->fetchAll();$suggestCount=(int)$pdo->query("SELECT COUNT(*) FROM suggestions WHERE status='new'")->fetchColumn();$msgCount=(int)$pdo->query("SELECT COUNT(*) FROM admin_messages WHERE is_deleted=FALSE")->fetchColumn();echo json_encode(['manga_count'=>$mangaCount,'users_count'=>$usersCount,'votes_count'=>$votesCount,'pages_count'=>$pagesCount,'chapters_count'=>$chaptersCount,'new_today'=>$newToday,'top_manga'=>$topManga,'suggest_count'=>$suggestCount,'msg_count'=>$msgCount]);exit;}

if ($path==='/api/admin/archive'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$page=max(0,(int)($_GET['page']??0));$limit=20;$offset=$page*$limit;$total=(int)$pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();$stmt=$pdo->prepare("SELECT * FROM bot_archive ORDER BY created_at DESC LIMIT $limit OFFSET $offset");$stmt->execute();echo json_encode(['items'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page]);exit;}

if ($path==='/api/admin/manga-list'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$page=max(0,(int)($_GET['page']??0));$q=trim($_GET['q']??'');$limit=10;$offset=$page*$limit;if($q){$stmt=$pdo->prepare("SELECT id,title,cover_imgbb_url,created_at,likes,is_series FROM manga WHERE title ILIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");$stmt->execute(["%$q%"]);$cStmt=$pdo->prepare("SELECT COUNT(*) FROM manga WHERE title ILIKE ?");$cStmt->execute(["%$q%"]);}else{$stmt=$pdo->prepare("SELECT id,title,cover_imgbb_url,created_at,likes,is_series FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");$stmt->execute();$cStmt=$pdo->query("SELECT COUNT(*) FROM manga");}echo json_encode(['items'=>$stmt->fetchAll(),'total'=>(int)$cStmt->fetchColumn()]);exit;}

if (preg_match('#^/api/admin/manga/(\d+)$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='GET'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$mId=(int)$m[1];$stmt=$pdo->prepare("SELECT * FROM manga WHERE id=?");$stmt->execute([$mId]);$manga=$stmt->fetch();if(!$manga){echo json_encode(['error'=>'Не найдено']);exit;}$chapters=[];if($manga['is_series']){$chStmt=$pdo->prepare("SELECT id,chapter_num,title,created_at FROM manga_chapters WHERE manga_id=? ORDER BY chapter_num ASC");$chStmt->execute([$mId]);$chapters=$chStmt->fetchAll();}$manga['chapters']=$chapters;echo json_encode($manga);exit;}

if (preg_match('#^/api/admin/manga/(\d+)$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$mId=(int)$m[1];$input=json_decode(file_get_contents('php://input'),true);$allowed=['title','description','telegraph_url','cover_imgbb_url'];$updates=[];$values=[];foreach($allowed as $field){if(isset($input[$field])){$updates[]="$field=?";$values[]=$input[$field];}}if(empty($updates)){echo json_encode(['success'=>false,'error'=>'Нечего обновлять']);exit;}$values[]=$mId;$pdo->prepare("UPDATE manga SET ".implode(', ',$updates)." WHERE id=?")->execute($values);logArchiveEntry($pdo,'edit_manga',"Отредактирована манга ID: $mId (через сайт)",$userId);echo json_encode(['success'=>true]);exit;}

if (preg_match('#^/api/admin/manga/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$mId=(int)$m[1];$titleStmt=$pdo->prepare("SELECT title FROM manga WHERE id=?");$titleStmt->execute([$mId]);$row=$titleStmt->fetch();$pdo->prepare("DELETE FROM manga WHERE id=?")->execute([$mId]);$pdo->prepare("DELETE FROM manga_pages WHERE manga_id=?")->execute([$mId]);$pdo->prepare("DELETE FROM manga_chapters WHERE manga_id=?")->execute([$mId]);logArchiveEntry($pdo,'delete_manga',"Удалена манга: ".($row['title']??"ID $mId"),$userId);echo json_encode(['success'=>true]);exit;}

if (preg_match('#^/api/admin/chapter/(\d+)/delete$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$chId=(int)$m[1];$pdo->prepare("DELETE FROM manga_chapters WHERE id=?")->execute([$chId]);$pdo->prepare("DELETE FROM manga_chapter_pages WHERE chapter_id=?")->execute([$chId]);logArchiveEntry($pdo,'delete_chapter',"Удалена глава ID: $chId",$userId);echo json_encode(['success'=>true]);exit;}

if ($path==='/api/admin/suggestions'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$status=$_GET['status']??'new';$page=max(0,(int)($_GET['page']??0));$limit=15;$offset=$page*$limit;$total=(int)$pdo->query("SELECT COUNT(*) FROM suggestions WHERE status='".addslashes($status)."'")->fetchColumn();$stmt=$pdo->prepare("SELECT * FROM suggestions WHERE status=? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");$stmt->execute([$status]);echo json_encode(['items'=>$stmt->fetchAll(),'total'=>$total]);exit;}

if (preg_match('#^/api/admin/suggestions/(\d+)/status$#',$path,$m)&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$input=json_decode(file_get_contents('php://input'),true);$newStatus=$input['status']??'read';$pdo->prepare("UPDATE suggestions SET status=? WHERE id=?")->execute([$newStatus,(int)$m[1]]);echo json_encode(['success'=>true]);exit;}

if ($path==='/api/admin/admins'){header('Content-Type: application/json');$userId=getEffectiveUserId($pdo);if(!isAdmin($userId,$hardcodedAdmins)){echo json_encode(['error'=>'Нет прав']);exit;}$admins=[];foreach($hardcodedAdmins as $id){$tag=null;try{$s=$pdo->prepare("SELECT tag_name FROM admin_tags WHERE user_id=?");$s->execute([$id]);$tag=$s->fetchColumn();}catch(Exception $e){}$admins[]=['user_id'=>$id,'tag'=>$tag?:"ID: $id"];}echo json_encode(['admins'=>$admins]);exit;}

# ========================= VIEWERS =========================

if (preg_match('#^/view/(\d+)$#',$path,$m)){
    $id=(int)$m[1];$stmt=$pdo->prepare("SELECT id,title,telegraph_url FROM manga WHERE id=?");$stmt->execute([$id]);$manga=$stmt->fetch();
    if(!$manga){http_response_code(404);die('404');}
    $title=htmlspecialchars($manga['title']);$telegraphUrl=htmlspecialchars($manga['telegraph_url']??'');
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"><title><?=$title?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#000;color:#fff;font-family:sans-serif;overflow:hidden;touch-action:none}
.reader{height:100dvh;display:flex;align-items:center;justify-content:center;background:#0a0a0a;position:relative}
#page{max-width:100%;max-height:100dvh;object-fit:contain;display:none;user-select:none;-webkit-user-drag:none}
.nav-area{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer}
.nav-area.prev{left:0}.nav-area.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);padding:8px 20px;border-radius:999px;z-index:100;font-size:14px;pointer-events:none;white-space:nowrap}
.back-btn{position:fixed;top:16px;left:16px;z-index:200;color:#fff;text-decoration:none;background:rgba(0,0,0,0.7);padding:10px 18px;border-radius:30px;font-size:14px;border:1px solid rgba(255,255,255,0.15);backdrop-filter:blur(10px)}
#loading{position:absolute;color:#aaa;font-size:16px;text-align:center;padding:20px}
.fallback{position:absolute;text-align:center;display:none;padding:20px}
.fallback p{margin-bottom:16px;color:#aaa}
.telegraph-link{background:#7c5cff;color:#fff;padding:12px 24px;border-radius:40px;text-decoration:none;font-weight:600;display:inline-block}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<a href="/read/<?=$id?>" class="back-btn">← Назад</a>
<div class="counter"><span id="counter">—</span></div>
<div class="nav-area prev" id="nav-prev"></div>
<div class="nav-area next" id="nav-next"></div>
<div class="reader"><div id="loading">📖 Загрузка...</div><img id="page" alt=""><div class="fallback" id="fallback"><p>❌ Страницы не найдены</p><?php if($telegraphUrl):?><a href="<?=$telegraphUrl?>" target="_blank" class="telegraph-link">📄 Telegraph</a><?php endif;?></div></div>
<script>
let pages=[],current=0;
const loadEl=document.getElementById('loading'),pageEl=document.getElementById('page'),fallEl=document.getElementById('fallback'),cntEl=document.getElementById('counter');
const mangaId=<?=$id?>;
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u)return u;const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function saveProgress(p){try{localStorage.setItem('progress_'+mangaId,p);}catch(e){}fetch('/api/progress',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,page_num:p+1,total_pages:pages.length,tg_user_id:getTgUser()})}).catch(()=>{});}
async function init(){try{const res=await fetch('/api/pages/<?=$id?>');const data=await res.json();pages=data.pages||[];if(!pages.length){loadEl.style.display='none';fallEl.style.display='block';return;}loadEl.style.display='none';let saved=0;try{saved=parseInt(localStorage.getItem('progress_'+mangaId)||'0');}catch(e){}current=saved>=pages.length?0:saved;render();}catch(e){loadEl.style.display='none';fallEl.style.display='block';}}
function render(){if(!pages[current])return;pageEl.style.display='none';const img=new Image();img.onload=()=>{pageEl.src=pages[current];pageEl.style.display='block';cntEl.innerText=(current+1)+' / '+pages.length;};img.onerror=()=>{if(current<pages.length-1){current++;render();}else{fallEl.style.display='block';}};img.src=pages[current];cntEl.innerText=(current+1)+' / '+pages.length;}
function nextPage(){if(current<pages.length-1){current++;render();saveProgress(current);}}
function prevPage(){if(current>0){current--;render();saveProgress(current);}}
document.getElementById('nav-next').onclick=nextPage;
document.getElementById('nav-prev').onclick=prevPage;
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowDown')nextPage();if(e.key==='ArrowLeft'||e.key==='ArrowUp')prevPage();});
let tx=0,ty=0;
document.addEventListener('touchstart',e=>{tx=e.changedTouches[0].screenX;ty=e.changedTouches[0].screenY;},{passive:true});
document.addEventListener('touchend',e=>{const dx=e.changedTouches[0].screenX-tx;const dy=e.changedTouches[0].screenY-ty;if(Math.abs(dx)>Math.abs(dy)&&Math.abs(dx)>40){if(dx<0)nextPage();else prevPage();}},{passive:true});
init();
</script></body></html><?php exit;}

if (preg_match('#^/view-chapter/(\d+)$#',$path,$m)){
    $chapterId=(int)$m[1];$stmt=$pdo->prepare("SELECT mc.*,m.title as manga_title,m.id as manga_id FROM manga_chapters mc JOIN manga m ON mc.manga_id=m.id WHERE mc.id=?");$stmt->execute([$chapterId]);$chapter=$stmt->fetch();
    if(!$chapter){http_response_code(404);die('404');}
    $prevCh=$pdo->prepare("SELECT id,chapter_num FROM manga_chapters WHERE manga_id=? AND chapter_num<? ORDER BY chapter_num DESC LIMIT 1");$prevCh->execute([$chapter['manga_id'],$chapter['chapter_num']]);$prevChapter=$prevCh->fetch();
    $nextCh=$pdo->prepare("SELECT id,chapter_num FROM manga_chapters WHERE manga_id=? AND chapter_num>? ORDER BY chapter_num ASC LIMIT 1");$nextCh->execute([$chapter['manga_id'],$chapter['chapter_num']]);$nextChapter=$nextCh->fetch();
    $chTitle=htmlspecialchars($chapter['manga_title'].' — Глава '.$chapter['chapter_num'].($chapter['title']?': '.$chapter['title']:''));
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"><title><?=$chTitle?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#000;color:#fff;font-family:sans-serif;overflow:hidden;touch-action:none}
.reader{height:100dvh;display:flex;align-items:center;justify-content:center;background:#0a0a0a}
#page{max-width:100%;max-height:100dvh;object-fit:contain;display:none;user-select:none}
.nav-area{position:fixed;top:0;width:50%;height:100%;z-index:10;cursor:pointer}
.nav-area.prev{left:0}.nav-area.next{right:0}
.counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);padding:8px 20px;border-radius:999px;z-index:100;font-size:14px;pointer-events:none}
.top-bar{position:fixed;top:0;left:0;right:0;z-index:200;display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:linear-gradient(180deg,rgba(0,0,0,0.85) 0%,transparent 100%);pointer-events:none}
.top-bar>*{pointer-events:all}
.back-btn{color:#fff;text-decoration:none;background:rgba(0,0,0,0.7);padding:8px 16px;border-radius:30px;font-size:13px;border:1px solid rgba(255,255,255,0.15);backdrop-filter:blur(8px)}
.ch-nav{display:flex;gap:8px}
.ch-nav a{color:#fff;text-decoration:none;background:rgba(124,92,255,0.6);padding:8px 14px;border-radius:20px;font-size:12px;font-weight:700;backdrop-filter:blur(8px)}
.ch-nav a.disabled{opacity:0.3;pointer-events:none;background:rgba(255,255,255,0.1)}
#loading{position:absolute;color:#aaa;font-size:16px}
.fallback{position:absolute;text-align:center;display:none;padding:20px}
.chapter-end{position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:300;display:none;align-items:center;justify-content:center;flex-direction:column;gap:20px;text-align:center;padding:24px;backdrop-filter:blur(4px)}
.chapter-end h2{font-size:24px;font-weight:800}.chapter-end p{color:#aaa;font-size:14px}
.end-btn{padding:14px 28px;border-radius:50px;border:none;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-family:sans-serif}
.end-next{background:#7c5cff;color:#fff}.end-back{background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2)}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<div class="top-bar">
    <a href="/read/<?=$chapter['manga_id']?>" class="back-btn">← К манге</a>
    <div class="ch-nav">
        <?php if($prevChapter):?><a href="/view-chapter/<?=$prevChapter['id']?>">← Гл. <?=$prevChapter['chapter_num']?></a><?php else:?><a class="disabled">← Нет</a><?php endif;?>
        <?php if($nextChapter):?><a href="/view-chapter/<?=$nextChapter['id']?>">Гл. <?=$nextChapter['chapter_num']?> →</a><?php else:?><a class="disabled">Нет →</a><?php endif;?>
    </div>
</div>
<div class="counter"><span id="counter">—</span></div>
<div class="nav-area prev" id="nav-prev"></div>
<div class="nav-area next" id="nav-next"></div>
<div class="reader"><div id="loading">📖 Загрузка...</div><img id="page" alt=""><div class="fallback" id="fallback"><p>❌ Страницы не найдены</p></div></div>
<div class="chapter-end" id="chapter-end">
    <h2>🎉 Глава завершена!</h2>
    <p>Глава <?=$chapter['chapter_num']?><?=$chapter['title']?': '.htmlspecialchars($chapter['title']):''?></p>
    <?php if($nextChapter):?><a class="end-btn end-next" href="/view-chapter/<?=$nextChapter['id']?>">▶ Читать главу <?=$nextChapter['chapter_num']?></a><?php else:?><p style="color:#7c5cff;font-weight:600">✅ Это последняя глава</p><?php endif;?>
    <a class="end-btn end-back" href="/read/<?=$chapter['manga_id']?>">← К информации о манге</a>
</div>
<script>
let pages=[],current=0;
const loadEl=document.getElementById('loading'),pageEl=document.getElementById('page'),fallEl=document.getElementById('fallback'),cntEl=document.getElementById('counter'),endEl=document.getElementById('chapter-end');
const mangaId=<?=$chapter['manga_id']?>,chapterId=<?=$chapterId?>;
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function saveProgress(p){fetch('/api/progress',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,chapter_id:chapterId,page_num:p+1,total_pages:pages.length,tg_user_id:getTgUser()})}).catch(()=>{});}
async function init(){try{const res=await fetch('/api/chapter-pages/<?=$chapterId?>');const data=await res.json();pages=data.pages||[];if(!pages.length){loadEl.style.display='none';fallEl.style.display='block';return;}loadEl.style.display='none';render();}catch(e){loadEl.style.display='none';fallEl.style.display='block';}}
function render(){if(!pages[current])return;pageEl.style.display='none';const img=new Image();img.onload=()=>{pageEl.src=pages[current];pageEl.style.display='block';cntEl.innerText=(current+1)+' / '+pages.length;};img.onerror=()=>{if(current<pages.length-1){current++;render();}else{showEnd();}};img.src=pages[current];cntEl.innerText=(current+1)+' / '+pages.length;}
function nextPage(){if(current<pages.length-1){current++;render();saveProgress(current);}else{showEnd();}}
function prevPage(){if(current>0){current--;render();saveProgress(current);}}
function showEnd(){endEl.style.display='flex';saveProgress(pages.length-1);}
document.getElementById('nav-next').onclick=nextPage;
document.getElementById('nav-prev').onclick=prevPage;
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowDown')nextPage();if(e.key==='ArrowLeft'||e.key==='ArrowUp')prevPage();});
let tx=0,ty=0;
document.addEventListener('touchstart',e=>{tx=e.changedTouches[0].screenX;ty=e.changedTouches[0].screenY;},{passive:true});
document.addEventListener('touchend',e=>{const dx=e.changedTouches[0].screenX-tx;const dy=e.changedTouches[0].screenY-ty;if(Math.abs(dx)>Math.abs(dy)&&Math.abs(dx)>40){if(dx<0)nextPage();else prevPage();}},{passive:true});
init();
</script></body></html><?php exit;}

if (preg_match('#^/read/(\d+)$#',$path,$m)){
    $id=(int)$m[1];$stmt=$pdo->prepare("SELECT id,title,description,cover_imgbb_url,file_id,telegraph_url,likes,dislikes,is_series FROM manga WHERE id=?");$stmt->execute([$id]);$manga=$stmt->fetch();
    if(!$manga){http_response_code(404);die('404');}
    $userId=getEffectiveUserId($pdo);$stmtStatus=$pdo->prepare("SELECT status FROM user_manga_status WHERE user_id=? AND manga_id=?");$stmtStatus->execute([$userId,$id]);$currentStatus=$stmtStatus->fetchColumn()?:'';
    $pagesCount=0;if(!$manga['is_series']){$pagesStmt=$pdo->prepare("SELECT COUNT(*) FROM manga_pages WHERE manga_id=? AND page_url IS NOT NULL AND page_url!=''");$pagesStmt->execute([$id]);$pagesCount=(int)$pagesStmt->fetchColumn();}
    $coverSrc=!empty($manga['cover_imgbb_url'])?htmlspecialchars($manga['cover_imgbb_url']):(!empty($manga['file_id'])?'/api/cover/'.htmlspecialchars($manga['file_id']):'');
    // Get all custom statuses for user
    $customStatusesStmt=$pdo->prepare("SELECT id,name,color FROM user_custom_statuses WHERE user_id=? ORDER BY created_at ASC");$customStatusesStmt->execute([$userId]);$customStatuses=$customStatusesStmt->fetchAll();
    // Get rating
    $ratingStmt=$pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM manga_ratings WHERE manga_id=?");$ratingStmt->execute([$id]);$ratingData=$ratingStmt->fetch();
    $myRatingStmt=$pdo->prepare("SELECT rating FROM manga_ratings WHERE manga_id=? AND user_id=?");$myRatingStmt->execute([$id,$userId]);$myRating=$myRatingStmt->fetchColumn();
    // Similar manga
    $similarStmt=$pdo->prepare("SELECT id,title,cover_imgbb_url,likes FROM manga WHERE id!=? ORDER BY likes DESC, RANDOM() LIMIT 10");$similarStmt->execute([$id]);$similarManga=$similarStmt->fetchAll();
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($manga['title'])?> | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--accent:#e0e0e0;--muted:#666666;--green:#4ade80;--orange:#fb923c}
.dark{--bg:#0c0c0c;--card:#161616;--border:#242424;--text:#f2f2f2;--muted:#666666}
.light{--bg:#f7f7f7;--card:#ffffff;--border:#e2e2e2;--border2:#d0d0d0;--text:#141414;--text2:#3a3a3a;--accent:#333333;--muted:#a0a0a0;--green:#16a34a;--orange:#ea580c}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;transition:background 0.3s,color 0.3s}
.back{display:inline-flex;align-items:center;gap:8px;margin:18px 20px 0;color:var(--muted);text-decoration:none;font-size:13px;font-weight:500;transition:color 0.18s;letter-spacing:0.1px}
.back:hover{color:var(--text)}
.manga-page{max-width:820px;margin:0 auto;padding:16px 16px 80px}
.hero{display:flex;gap:22px;margin-bottom:24px}
.cover-wrap{flex-shrink:0;width:155px}
@media(max-width:500px){.cover-wrap{width:105px}.hero{gap:14px}}
.cover-img{width:100%;border-radius:12px;aspect-ratio:2/3;object-fit:cover;box-shadow:0 12px 36px rgba(0,0,0,0.5)}
.cover-ph{width:100%;aspect-ratio:2/3;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--card);border:1px solid var(--border);color:var(--muted);font-size:44px}
.meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:11px}
.manga-title{font-size:20px;font-weight:800;font-family:'Syne',sans-serif;line-height:1.2;color:var(--text);letter-spacing:0.2px}
@media(max-width:500px){.manga-title{font-size:16px}}
.badge-series{background:rgba(255,255,255,0.07);color:var(--text2);border:1px solid var(--border);padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;display:inline-block;letter-spacing:0.3px}
.manga-desc{color:var(--muted);font-size:13px;line-height:1.7}
.vote-row{display:flex;gap:7px;flex-wrap:wrap}
.vote-btn{padding:6px 14px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;transition:all 0.18s;font-weight:500}
.vote-btn.like:hover,.vote-btn.like.active{background:rgba(74,222,128,0.08);border-color:rgba(74,222,128,0.4);color:var(--green)}
.vote-btn.dislike:hover,.vote-btn.dislike.active{background:rgba(248,113,113,0.08);border-color:rgba(248,113,113,0.4);color:#f87171}
/* Star rating */
.rating-block{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.rate-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;transition:all 0.18s}
.rate-btn:hover{border-color:var(--border2);color:var(--text)}
.rate-avg{font-size:12px;color:var(--muted);font-weight:400}
.star-icon{font-size:12px}
/* Status */
.status-row{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.status-btn{padding:5px 12px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;font-weight:500;transition:all 0.18s}
.status-btn:hover{border-color:var(--border2);color:var(--text2)}
.status-btn.active-now{background:rgba(251,146,60,0.1);border-color:rgba(251,146,60,0.4);color:var(--orange)}
.status-btn.active-will{background:rgba(255,255,255,0.06);border-color:var(--border2);color:var(--text)}
.status-btn.active-read{background:rgba(74,222,128,0.08);border-color:rgba(74,222,128,0.35);color:var(--green)}
.status-btn.active-custom{border-width:1px}
.status-add-btn{width:26px;height:26px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.18s;font-family:inherit}
.status-add-btn:hover{border-color:var(--border2);color:var(--text)}
.read-section{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:16px;margin-bottom:14px}
.read-section h3{font-size:11px;font-weight:700;margin-bottom:11px;font-family:'Inter',sans-serif;letter-spacing:0.7px;text-transform:uppercase;color:var(--muted)}
.read-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;transition:all 0.18s;margin-right:7px;margin-bottom:7px}
.read-primary{background:var(--text);color:var(--bg)}
.read-primary:hover{opacity:0.88;transform:translateY(-1px)}
.read-secondary{background:transparent;color:var(--text2);border:1px solid var(--border)}
.read-secondary:hover{border-color:var(--border2)}
.chapters-list{display:flex;flex-direction:column;gap:4px;max-height:400px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.chapter-item{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:9px;text-decoration:none;color:var(--text2);transition:all 0.18s}
.chapter-item:hover{border-color:var(--border2);background:rgba(255,255,255,0.04);transform:translateX(3px)}
.ch-num{font-weight:700;font-size:13px;color:var(--text)}.ch-title{font-size:11px;color:var(--muted);margin-left:5px}.ch-date{font-size:10px;color:var(--muted)}.ch-pages{font-size:10px;color:var(--muted);background:var(--card);border:1px solid var(--border);padding:2px 7px;border-radius:7px}
/* Similar manga */
.similar-section{margin-top:22px}
.similar-title{font-size:10px;font-weight:700;font-family:'Inter',sans-serif;margin-bottom:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px}
.similar-list{display:flex;gap:9px;overflow-x:auto;padding-bottom:6px;scrollbar-width:none}
.similar-list::-webkit-scrollbar{display:none}
.sim-card{flex:0 0 86px;text-decoration:none;color:var(--text);transition:all 0.18s}
.sim-card:hover{transform:translateY(-3px)}
.sim-cover{width:86px;height:115px;object-fit:cover;border-radius:9px;background:var(--card);border:1px solid var(--border);display:block}
.sim-title{font-size:10px;font-weight:600;margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3;color:var(--text2)}
/* Modal for rating */
.rating-modal{position:fixed;inset:0;background:rgba(0,0,0,0.88);backdrop-filter:blur(14px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.rating-modal.open{display:flex}
.rating-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:26px;text-align:center;max-width:320px;width:100%}
.rating-box h3{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;margin-bottom:5px;color:var(--text)}
.rating-box p{color:var(--muted);font-size:13px;margin-bottom:18px}
.stars-row{display:flex;gap:5px;justify-content:center;margin-bottom:18px}
.star-r{font-size:26px;cursor:pointer;transition:transform 0.12s;filter:grayscale(1);opacity:0.35;color:var(--text)}
.star-r:hover,.star-r.active{filter:none;opacity:1;transform:scale(1.15)}
.rate-submit{width:100%;padding:11px;background:var(--text);color:var(--bg);border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity 0.18s}
.rate-submit:hover{opacity:0.85}
.rate-cancel{width:100%;padding:9px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;margin-top:7px}
/* Custom status modal */
.cstatus-modal{position:fixed;inset:0;background:rgba(0,0,0,0.88);backdrop-filter:blur(14px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.cstatus-modal.open{display:flex}
.cstatus-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px;max-width:320px;width:100%}
.cstatus-box h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:800;margin-bottom:14px;color:var(--text)}
.cstatus-box input{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:14px;padding:9px 11px;margin-bottom:10px;font-family:inherit}
.cstatus-box input:focus{outline:none;border-color:var(--border2)}
.color-row{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:13px}
.color-opt{width:24px;height:24px;border-radius:6px;cursor:pointer;border:2px solid transparent;transition:all 0.15s}
.color-opt.sel{border-color:#fff;transform:scale(1.15)}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,0.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;box-shadow:0 8px 28px rgba(0,0,0,0.6);animation:toastIn 0.25s ease;white-space:nowrap;border:1px solid var(--border2);backdrop-filter:blur(12px)}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.manga-page{max-width:820px;margin:0 auto;padding:16px 16px 80px}
.hero{display:flex;gap:24px;margin-bottom:28px}
.cover-wrap{flex-shrink:0;width:160px}
@media(max-width:500px){.cover-wrap{width:110px}.hero{gap:14px}}
.cover-img{width:100%;border-radius:14px;aspect-ratio:2/3;object-fit:cover;box-shadow:0 12px 40px rgba(0,0,0,0.5)}
.cover-ph{width:100%;aspect-ratio:2/3;border-radius:14px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a);font-size:50px}
.meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:12px}
.manga-title{font-size:22px;font-weight:800;font-family:'Syne',sans-serif;line-height:1.2}
@media(max-width:500px){.manga-title{font-size:17px}}
.badge-series{background:rgba(124,92,255,0.15);color:var(--accent);border:1px solid rgba(124,92,255,0.3);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
.manga-desc{color:var(--muted);font-size:13px;line-height:1.7}
.vote-row{display:flex;gap:8px;flex-wrap:wrap}
.vote-btn{padding:7px 16px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--text);font-size:13px;cursor:pointer;font-family:inherit;transition:all 0.2s}
.vote-btn.like:hover,.vote-btn.like.active{background:rgba(76,175,80,0.15);border-color:#4caf50;color:#4caf50}
.vote-btn.dislike:hover,.vote-btn.dislike.active{background:rgba(244,67,54,0.15);border-color:#f44336;color:#f44336}
/* Star rating */
.rating-block{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rate-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all 0.2s}
.rate-btn:hover{border-color:var(--accent);color:var(--accent)}
.rate-avg{font-size:12px;color:var(--muted)}
.star-icon{font-size:13px}
/* Status */
.status-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.status-btn{padding:6px 13px;border-radius:50px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;cursor:pointer;font-family:inherit;font-weight:600;transition:all 0.2s}
.status-btn:hover{border-color:var(--accent);color:var(--text)}
.status-btn.active-now{background:rgba(255,165,0,0.15);border-color:#ffa500;color:#ffa500}
.status-btn.active-will{background:rgba(124,92,255,0.15);border-color:var(--accent);color:var(--accent)}
.status-btn.active-read{background:rgba(76,175,80,0.15);border-color:#4caf50;color:#4caf50}
.status-btn.active-custom{border-width:2px}
.status-add-btn{width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;font-family:inherit}
.status-add-btn:hover{border-color:var(--accent);color:var(--accent)}
.read-section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:16px}
.read-section h3{font-size:14px;font-weight:700;margin-bottom:12px;font-family:'Syne',sans-serif}
.read-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:50px;text-decoration:none;font-weight:700;font-size:13px;transition:all 0.2s;margin-right:8px;margin-bottom:8px}
.read-primary{background:var(--accent);color:#fff}
.read-primary:hover{opacity:0.88;transform:translateY(-1px)}
.read-secondary{background:rgba(255,255,255,0.06);color:var(--text);border:1px solid var(--border)}
.read-secondary:hover{border-color:var(--accent)}
.chapters-list{display:flex;flex-direction:column;gap:5px;max-height:400px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.chapter-item{display:flex;align-items:center;justify-content:space-between;padding:10px 13px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px;text-decoration:none;color:var(--text);transition:all 0.2s}
.chapter-item:hover{border-color:var(--accent);background:rgba(124,92,255,0.06);transform:translateX(3px)}
.ch-num{font-weight:700;font-size:13px}.ch-title{font-size:11px;color:var(--muted);margin-left:6px}.ch-date{font-size:10px;color:var(--muted)}.ch-pages{font-size:10px;color:var(--muted);background:var(--card);border:1px solid var(--border);padding:2px 7px;border-radius:8px}
/* Similar manga */
.similar-section{margin-top:24px}
.similar-title{font-size:14px;font-weight:700;font-family:'Syne',sans-serif;margin-bottom:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;font-size:11px}
.similar-list{display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;scrollbar-width:none}
.similar-list::-webkit-scrollbar{display:none}
.sim-card{flex:0 0 90px;text-decoration:none;color:var(--text);transition:all 0.2s}
.sim-card:hover{transform:translateY(-3px)}
.sim-cover{width:90px;height:120px;object-fit:cover;border-radius:10px;background:var(--card);border:1px solid var(--border);display:block}
.sim-title{font-size:10px;font-weight:600;margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3}
/* Modal for rating */
.rating-modal{position:fixed;inset:0;background:rgba(0,0,0,0.8);backdrop-filter:blur(10px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.rating-modal.open{display:flex}
.rating-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px;text-align:center;max-width:320px;width:100%}
.rating-box h3{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:6px}
.rating-box p{color:var(--muted);font-size:13px;margin-bottom:20px}
.stars-row{display:flex;gap:6px;justify-content:center;margin-bottom:20px}
.star-r{font-size:28px;cursor:pointer;transition:transform 0.15s;filter:grayscale(1);opacity:0.4}
.star-r:hover,.star-r.active{filter:none;opacity:1;transform:scale(1.15)}
.rate-submit{width:100%;padding:12px;background:var(--accent);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}
.rate-cancel{width:100%;padding:10px;background:transparent;border:1px solid var(--border);border-radius:12px;color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit;margin-top:8px}
/* Custom status modal */
.cstatus-modal{position:fixed;inset:0;background:rgba(0,0,0,0.8);backdrop-filter:blur(10px);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.cstatus-modal.open{display:flex}
.cstatus-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:24px;max-width:320px;width:100%}
.cstatus-box h3{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;margin-bottom:16px}
.cstatus-box input{width:100%;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px;padding:10px 12px;margin-bottom:10px;font-family:inherit}
.cstatus-box input:focus{outline:none;border-color:var(--accent)}
.color-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.color-opt{width:26px;height:26px;border-radius:6px;cursor:pointer;border:2px solid transparent;transition:all 0.15s}
.color-opt.sel{border-color:#fff;transform:scale(1.15)}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(124,92,255,0.95);color:#fff;padding:10px 22px;border-radius:50px;font-size:14px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(124,92,255,0.4);animation:toastIn 0.3s ease;white-space:nowrap}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<a href="/" class="back">← Каталог</a>
<div class="manga-page">
    <div class="hero">
        <div class="cover-wrap">
            <?php if($coverSrc):?><img class="cover-img" src="<?=$coverSrc?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><div class="cover-ph" style="display:none">📖</div><?php else:?><div class="cover-ph">📖</div><?php endif;?>
        </div>
        <div class="meta">
            <div class="manga-title"><?=htmlspecialchars($manga['title'])?></div>
            <?php if($manga['is_series']):?><div><span class="badge-series">📚 Серия глав</span></div><?php endif;?>
            <?php if($manga['description']):?><div class="manga-desc"><?=nl2br(htmlspecialchars($manga['description']))?></div><?php endif;?>
            <div class="vote-row">
                <button class="vote-btn like" onclick="vote('like')">👍 <span id="likes"><?=(int)$manga['likes']?></span></button>
                <button class="vote-btn dislike" onclick="vote('dislike')">👎 <span id="dislikes"><?=(int)$manga['dislikes']?></span></button>
            </div>
            <!-- Rating block -->
            <div class="rating-block">
                <button class="rate-btn" onclick="openRatingModal()">
                    <span class="star-icon">★</span> Оценить
                </button>
                <span class="rate-avg" id="rate-avg-text"><?=($ratingData['cnt']>0)?round((float)$ratingData['avg'],1).' / 10 ('.(int)$ratingData['cnt'].' оценок)':'Нет оценок'?></span>
            </div>
            <div class="status-row" id="status-row">
                <button class="status-btn <?=$currentStatus==='now'?'active-now':''?>" onclick="setStatus('now')">📖 Читаю</button>
                <button class="status-btn <?=$currentStatus==='will'?'active-will':''?>" onclick="setStatus('will')">🔖 Буду читать</button>
                <button class="status-btn <?=$currentStatus==='read'?'active-read':''?>" onclick="setStatus('read')">✅ Прочитано</button>
                <?php foreach($customStatuses as $cs):?>
                <button class="status-btn <?=$currentStatus===$cs['name']?'active-custom':''?>" style="<?=$currentStatus===$cs['name']?'background:'.htmlspecialchars($cs['color']).'22;border-color:'.htmlspecialchars($cs['color']).';color:'.htmlspecialchars($cs['color']):''?>" onclick="setStatus('<?=htmlspecialchars(addslashes($cs['name']))?>')"><?=htmlspecialchars($cs['name'])?></button>
                <?php endforeach;?>
                <button class="status-add-btn" onclick="openCStatusModal()" title="Добавить свой статус">+</button>
            </div>
        </div>
    </div>
    <div class="read-section">
        <?php if($manga['is_series']):?>
        <h3>📚 Список глав</h3>
        <div class="chapters-list" id="chapters-list"><div style="color:var(--muted);padding:8px 0">Загрузка глав...</div></div>
        <?php else:?>
        <h3>📖 Читать</h3>
        <?php if($pagesCount>0):?><a href="/view/<?=$id?>" class="read-btn read-primary">📖 Читать (<?=$pagesCount?> стр.)</a><?php endif;?>
        <?php if($manga['telegraph_url']):?><a href="<?=htmlspecialchars($manga['telegraph_url'])?>" target="_blank" class="read-btn read-secondary">📄 Telegraph</a><?php endif;?>
        <?php if(!$pagesCount&&!$manga['telegraph_url']):?><p style="color:var(--muted);font-size:14px">😔 Страницы ещё не добавлены</p><?php endif;?>
        <?php endif;?>
    </div>
    <!-- Similar manga -->
    <?php if(!empty($similarManga)):?>
    <div class="similar-section">
        <div class="similar-title">Похожая манга</div>
        <div class="similar-list">
            <?php foreach($similarManga as $sm):$smCover=!empty($sm['cover_imgbb_url'])?htmlspecialchars($sm['cover_imgbb_url']):'';?>
            <a class="sim-card" href="/read/<?=(int)$sm['id']?>">
                <?php if($smCover):?><img class="sim-cover" src="<?=$smCover?>" alt="" onerror="this.style.background='#1a1a2e'">
                <?php else:?><div class="sim-cover" style="display:flex;align-items:center;justify-content:center;font-size:28px">📖</div><?php endif;?>
                <div class="sim-title"><?=htmlspecialchars($sm['title'])?></div>
            </a>
            <?php endforeach;?>
        </div>
    </div>
    <?php endif;?>
</div>

<!-- Rating modal -->
<div class="rating-modal" id="rating-modal">
<div class="rating-box">
    <h3>★ Оценить мангу</h3>
    <p>Выбери оценку от 1 до 10</p>
    <div class="stars-row" id="stars-row">
        <?php for($i=1;$i<=10;$i++):?><span class="star-r <?=($myRating>=$i)?'active':''?>" data-val="<?=$i?>">★</span><?php endfor;?>
    </div>
    <button class="rate-submit" onclick="submitRating()">Сохранить оценку</button>
    <button class="rate-cancel" onclick="closeRatingModal()">Отмена</button>
</div>
</div>

<!-- Custom status modal -->
<div class="cstatus-modal" id="cstatus-modal">
<div class="cstatus-box">
    <h3>+ Новый статус</h3>
    <?php if(!empty($customStatuses)):?>
    <div style="margin-bottom:12px">
        <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:7px">Мои статусы</div>
        <?php foreach($customStatuses as $cs):?>
        <div style="display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:9px;padding:7px 10px;margin-bottom:5px">
            <span style="font-size:12px;font-weight:600;color:<?=htmlspecialchars($cs['color'])?>"><?=htmlspecialchars($cs['name'])?></span>
            <button onclick="confirmDeleteStatus(<?=(int)$cs['id']?>, '<?=htmlspecialchars(addslashes($cs['name']))?>')" style="width:22px;height:22px;border-radius:6px;background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.25);color:var(--red);font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;transition:all 0.18s" title="Удалить статус">✕</button>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>
    <input type="text" id="cs-name" placeholder="Название статуса..." maxlength="50">
    <div class="color-row" id="cs-colors"></div>
    <button style="width:100%;padding:11px;background:var(--accent);border:none;border-radius:10px;color:#fff;font-weight:700;cursor:pointer;font-family:inherit;margin-bottom:8px;transition:all 0.2s" onclick="saveCustomStatus()">Добавить</button>
    <button style="width:100%;padding:9px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);cursor:pointer;font-family:inherit;transition:all 0.2s" onclick="closeCStatusModal()">Отмена</button>
</div>
</div>

<!-- Delete status confirm modal -->
<div id="delete-status-modal" style="position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(12px);z-index:600;display:none;align-items:center;justify-content:center;padding:16px">
<div style="background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;max-width:300px;width:100%;text-align:center">
    <div style="font-size:32px;margin-bottom:12px">⚠️</div>
    <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;margin-bottom:8px">Удалить статус?</div>
    <div style="color:var(--muted);font-size:13px;margin-bottom:20px">Вы действительно хотите удалить статус <strong id="del-status-name" style="color:var(--text)"></strong>?</div>
    <div style="display:flex;gap:8px">
        <button onclick="cancelDeleteStatus()" style="flex:1;padding:10px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);cursor:pointer;font-family:inherit;font-size:13px;transition:all 0.2s">Отмена</button>
        <button onclick="executeDeleteStatus()" style="flex:1;padding:10px;background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);border-radius:10px;color:var(--red);font-weight:700;cursor:pointer;font-family:inherit;font-size:13px;transition:all 0.2s">Удалить</button>
    </div>
</div>
</div>

<script>
const mangaId=<?=$id?>;
let selectedRating=<?=(int)($myRating??0)?>;
let selectedColor='#7c5cff';
const colors=['#7c5cff','#22c55e','#f59e0b','#ef4444','#3b82f6','#ec4899','#06b6d4','#a3e635'];

function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u){document.cookie='tg_user_id='+u+';max-age='+(86400*30)+';path=/';return u;}const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function showToast(msg){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className='toast';t.innerText=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2500);}
async function vote(type){try{const res=await fetch('/api/vote',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,vote_type:type,tg_user_id:getTgUser()})});const data=await res.json();if(data.success){document.getElementById('likes').textContent=data.likes;document.getElementById('dislikes').textContent=data.dislikes;showToast(type==='like'?'👍 Лайк!':'👎 Дизлайк');}}catch(e){}}
async function setStatus(s){try{await fetch('/api/status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,status:s,tg_user_id:getTgUser()})});document.querySelectorAll('.status-btn').forEach(b=>{b.className='status-btn';b.removeAttribute('style');});const map={'now':'active-now','will':'active-will','read':'active-read'};if(map[s])event.target.classList.add(map[s]);else{event.target.classList.add('active-custom');const clr=event.target.style.borderColor||'var(--accent)';event.target.style.cssText=`background:${clr}22;border-color:${clr};color:${clr};border-width:2px`;}showToast('✅ Статус обновлён');}catch(e){}}
// Rating
function openRatingModal(){document.getElementById('rating-modal').classList.add('open');initStars();}
function closeRatingModal(){document.getElementById('rating-modal').classList.remove('open');}
function initStars(){const stars=document.querySelectorAll('.star-r');stars.forEach(s=>{s.addEventListener('mouseenter',()=>{const v=parseInt(s.dataset.val);stars.forEach((st,i)=>{st.classList.toggle('active',i<v);});});s.addEventListener('click',()=>{selectedRating=parseInt(s.dataset.val);stars.forEach((st,i)=>{st.classList.toggle('active',i<selectedRating);});});});document.getElementById('stars-row').addEventListener('mouseleave',()=>{stars.forEach((st,i)=>{st.classList.toggle('active',i<selectedRating);});});}
async function submitRating(){if(!selectedRating){showToast('Выбери оценку');return;}try{const res=await fetch('/api/rate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:mangaId,rating:selectedRating,tg_user_id:getTgUser()})});const d=await res.json();if(d.success){document.getElementById('rate-avg-text').textContent=d.avg+' / 10 ('+d.count+' оценок)';showToast('★ Оценка '+selectedRating+' сохранена!');closeRatingModal();}}catch(e){}}
// Custom statuses
function openCStatusModal(){const cr=document.getElementById('cs-colors');cr.innerHTML=colors.map(c=>`<div class="color-opt${c===selectedColor?' sel':''}" style="background:${c}" data-c="${c}" onclick="pickColor(this,'${c}')"></div>`).join('');document.getElementById('cstatus-modal').classList.add('open');}
function closeCStatusModal(){document.getElementById('cstatus-modal').classList.remove('open');}
function pickColor(el,c){selectedColor=c;document.querySelectorAll('.color-opt').forEach(o=>o.classList.remove('sel'));el.classList.add('sel');}
async function saveCustomStatus(){const name=document.getElementById('cs-name').value.trim();if(!name){showToast('Введи название');return;}try{await fetch('/api/custom-statuses/add',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,color:selectedColor,tg_user_id:getTgUser()})});showToast('✅ Статус добавлен');closeCStatusModal();setTimeout(()=>location.reload(),800);}catch(e){}}
let _delStatusId=null;
function confirmDeleteStatus(id,name){_delStatusId=id;document.getElementById('del-status-name').textContent=name;const m=document.getElementById('delete-status-modal');m.style.display='flex';}
function cancelDeleteStatus(){_delStatusId=null;document.getElementById('delete-status-modal').style.display='none';}
async function executeDeleteStatus(){if(!_delStatusId)return;try{await fetch(`/api/custom-statuses/${_delStatusId}/delete`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tg_user_id:getTgUser()})});showToast('🗑 Статус удалён');cancelDeleteStatus();setTimeout(()=>location.reload(),600);}catch(e){showToast('❌ Ошибка');}}
<?php if($manga['is_series']):?>
async function loadChapters(){try{const res=await fetch('/api/chapters/<?=$id?>');const data=await res.json();const list=document.getElementById('chapters-list');if(!data.chapters?.length){list.innerHTML='<div style="color:var(--muted);padding:8px 0">Глав пока нет</div>';return;}list.innerHTML=data.chapters.map(ch=>`<a class="chapter-item" href="/view-chapter/${ch.id}"><div><span class="ch-num">Глава ${ch.chapter_num}</span>${ch.title?`<span class="ch-title">${escapeHtml(ch.title)}</span>`:''}</div><div style="display:flex;gap:8px;align-items:center">${ch.page_count>0?`<span class="ch-pages">${ch.page_count} стр.</span>`:''}<span class="ch-date">${new Date(ch.created_at).toLocaleDateString('ru-RU')}</span></div></a>`).join('');}catch(e){document.getElementById('chapters-list').innerHTML='<div style="color:var(--muted)">Ошибка загрузки</div>';}}
loadChapters();
<?php endif;?>
</script></body></html><?php exit;}

if ($path==='/library'){
    $userId=getEffectiveUserId($pdo);
    // Get custom statuses
    $csStmt=$pdo->prepare("SELECT id,name,color FROM user_custom_statuses WHERE user_id=? ORDER BY created_at ASC");$csStmt->execute([$userId]);$customStatuses=$csStmt->fetchAll();
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Библиотека | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0a0f;--card:#12121a;--border:#1e1e2e;--text:#f0f0f5;--accent:#7c5cff;--muted:#6b6b80;--green:#22c55e;--orange:#f59e0b}
.light{--bg:#f5f5f7;--card:#ffffff;--border:#e0e0e8;--text:#1a1a2e;--muted:#8888a0;--accent:#6644ee}
*{margin:0;padding:0;box-sizing:border-box}body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;transition:background 0.3s,color 0.3s}
header{position:sticky;top:0;z-index:100;backdrop-filter:blur(20px);background:rgba(10,10,15,0.92);border-bottom:1px solid var(--border);padding:12px 20px}
.light header{background:rgba(245,245,247,0.92)}
.header-inner{max-width:1200px;margin:auto;display:flex;align-items:center;gap:12px}
.logo{font-size:20px;font-weight:800;font-family:'Syne',sans-serif;background:linear-gradient(135deg,#fff 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-decoration:none}
.light .logo{background:linear-gradient(135deg,#1a1a2e 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.back-btn{color:var(--muted);text-decoration:none;font-size:13px;margin-left:auto;display:flex;align-items:center;gap:6px;padding:7px 14px;border:1px solid var(--border);border-radius:50px;transition:all 0.2s}
.back-btn:hover{border-color:var(--accent);color:var(--accent)}
.wrap{max-width:1200px;margin:auto;padding:24px 20px 60px}
/* Controls */
.lib-controls{display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap}
.lib-search{flex:1;min-width:180px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:50px;color:var(--text);font-size:13px;padding:9px 16px;font-family:inherit}
.lib-search:focus{outline:none;border-color:var(--accent)}
.light .lib-search{background:rgba(0,0,0,0.04)}
.sort-select{padding:8px 14px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:50px;color:var(--text);font-size:13px;font-family:inherit;cursor:pointer}
.light .sort-select{background:rgba(0,0,0,0.04)}
.sort-select:focus{outline:none}
.view-btns{display:flex;gap:4px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:50px;padding:3px}
.light .view-btns{background:rgba(0,0,0,0.04)}
.view-btn{padding:6px 12px;border-radius:40px;border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:12px;font-family:inherit;transition:all 0.2s}
.view-btn.active{background:var(--accent);color:#fff}
/* GRID view */
.grid-view{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px}
@media(max-width:480px){.grid-view{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.25s}
.card:hover{transform:translateY(-4px);border-color:var(--accent)}
.card{transition:all 0.25s cubic-bezier(0.34,1.56,0.64,1)}
.card:active{transform:scale(0.97)!important}
.back-btn{transition:all 0.2s cubic-bezier(0.34,1.56,0.64,1)}
.back-btn:hover{transform:translateX(-2px)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a1a2e,#0a0a0a)}
.card-info{padding:10px}
.card-title{font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;margin-bottom:5px}
/* LIST view */
.list-view{display:flex;flex-direction:column;gap:6px}
.list-item{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px 14px;text-decoration:none;color:var(--text);transition:all 0.2s}
.list-item:hover{border-color:var(--accent);transform:translateX(3px)}
.list-cover{width:44px;height:60px;object-fit:cover;border-radius:7px;flex-shrink:0;background:linear-gradient(135deg,#1a1a2e,#0a0a0a);display:block}
.list-cover-ph{width:44px;height:60px;border-radius:7px;flex-shrink:0;background:linear-gradient(135deg,#1a1a2e,#0a0a0a);display:flex;align-items:center;justify-content:center;font-size:18px}
.list-body{flex:1;min-width:0}
.list-title{font-size:13px;font-weight:600;font-family:'Syne',sans-serif;margin-bottom:4px}
.list-date{font-size:11px;color:var(--muted)}
.list-status{margin-left:auto;flex-shrink:0}
/* Badges */
.badge{display:inline-block;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700}
.badge-now{background:rgba(255,165,0,0.15);color:#ffa500;border:1px solid rgba(255,165,0,0.3)}
.badge-will{background:rgba(124,92,255,0.15);color:var(--accent);border:1px solid rgba(124,92,255,0.3)}
.badge-read{background:rgba(76,175,80,0.15);color:#4caf50;border:1px solid rgba(76,175,80,0.3)}
.section-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.section{margin-bottom:28px}
.empty-page{text-align:center;padding:80px 20px;color:var(--muted);font-size:15px}
</style><script src="https://telegram.org/js/telegram-web-app.js"></script></head>
<body>
<div id="lib-loader" style="position:fixed;inset:0;z-index:9998;background:var(--bg);display:flex;align-items:center;justify-content:center;transition:opacity 0.4s,visibility 0.4s"><div style="position:relative;width:56px;height:56px"><?php for($i=0;$i<8;$i++):?><div style="position:absolute;width:10px;height:10px;border-radius:50%;background:var(--border2);animation:loaderSpin 1.2s linear infinite;animation-delay:<?=$i*0.15?>s;top:<?=[0,14,50,86,100,86,50,14][$i]>?%;left:<?=[50,86,100,86,50,14,0,14][$i]>?%;transform:translate(-50%,-50%)"></div><?php endfor;?></div></div>
<style>@keyframes loaderSpin{0%,100%{opacity:0.15;transform:translate(-50%,-50%) scale(0.7)}50%{opacity:1;transform:translate(-50%,-50%) scale(1.1);background:var(--text2)}}</style>
<header><div class="header-inner">
    <a href="/" class="logo">⬛ <span>BLACKWATCH</span></a>
    <a href="/" class="back-btn" style="margin-left:auto">← Каталог</a>
</div></header>
<div class="wrap">
    <div class="lib-controls">
        <input class="lib-search" type="text" placeholder="🔍 Поиск в библиотеке..." id="lib-search" oninput="filterLib()">
        <select class="sort-select" id="sort-select" onchange="filterLib()">
            <option value="all">Все статусы</option>
            <option value="popular">По популярности</option>
            <option value="now">📖 Читаю</option>
            <option value="read">✅ Прочитано</option>
            <option value="will">🔖 Буду читать</option>
            <?php foreach($customStatuses as $cs):?><option value="custom_<?=htmlspecialchars($cs['name'])?>"><?=htmlspecialchars($cs['name'])?></option><?php endforeach;?>
        </select>
        <div class="view-btns">
            <button class="view-btn active" id="vb-grid" onclick="setView('grid')">⊞ Плитка</button>
            <button class="view-btn" id="vb-list" onclick="setView('list')">☰ Список</button>
        </div>
    </div>
    <div id="content"><div class="empty-page">📖 Загрузка...</div></div>
</div>
<script>
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u)return u;const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
// Hide loader after page ready
window.addEventListener('load',()=>{const l=document.getElementById('lib-loader');if(l){l.style.opacity='0';l.style.visibility='hidden';setTimeout(()=>l.remove(),450);}});
let allItems=[],currentView='grid';
const badgeMap={now:'badge-now',will:'badge-will',read:'badge-read'};
const labelMap={now:'📖 Читаю',will:'🔖 Буду читать',read:'✅ Прочитано'};
function getBadgeClass(status){return badgeMap[status]||'badge-will';}
function getBadgeLabel(status){return labelMap[status]||status;}
function setView(v){currentView=v;document.getElementById('vb-grid').classList.toggle('active',v==='grid');document.getElementById('vb-list').classList.toggle('active',v==='list');renderLib(allItems);}
function filterLib(){const q=document.getElementById('lib-search').value.toLowerCase();const sort=document.getElementById('sort-select').value;let items=allItems.filter(m=>m.title.toLowerCase().includes(q));if(sort==='now')items=items.filter(m=>m.status==='now');else if(sort==='will')items=items.filter(m=>m.status==='will');else if(sort==='read')items=items.filter(m=>m.status==='read');else if(sort==='popular')items=items.sort((a,b)=>(b.likes||0)-(a.likes||0));else if(sort.startsWith('custom_')){const sn=sort.slice(7);items=items.filter(m=>m.status===sn);}renderLib(items);}
function renderLib(items){
    const c=document.getElementById('content');
    if(!items.length){c.innerHTML='<div class="empty-page">📭 Ничего не найдено<br><br><a href="/" style="color:var(--accent)">Каталог →</a></div>';return;}
    if(currentView==='grid'){
        // Group by status
        const groups={now:[],will:[],read:[],custom:{}};
        items.forEach(m=>{if(m.status==='now')groups.now.push(m);else if(m.status==='will')groups.will.push(m);else if(m.status==='read')groups.read.push(m);else{if(!groups.custom[m.status])groups.custom[m.status]=[];groups.custom[m.status].push(m);}});
        let html='';
        const renderSection=(icon,title,arr)=>{if(!arr.length)return '';let src='';let cards=arr.map(m=>{src=m.cover_imgbb_url||'';if(!src&&m.file_id)src='/api/cover/'+m.file_id;const covId='cv'+m.id,phId='ph'+m.id;const imgHtml=src?`<img class="cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="document.getElementById('${covId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`:'' ;const rHtml=m.avg_rating>0?`<div style="font-size:9px;color:#f59e0b;margin-top:2px">${'★'.repeat(Math.round(m.avg_rating/2))}${'☆'.repeat(5-Math.round(m.avg_rating/2))}<span style="color:var(--muted);margin-left:3px">${m.avg_rating}</span></div>`:'';return `<a class="card" href="/read/${m.id}">${imgHtml}<div class="cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:36px">📖</span></div><div class="card-info"><div class="card-title">${escapeHtml(m.title)}</div>${rHtml}</div></a>`;}).join('');return `<div class="section"><div class="section-title"><span>${icon}</span>${title} <span style="color:var(--muted);font-size:13px;font-weight:400">(${arr.length})</span></div><div class="grid-view">${cards}</div></div>`;};
        if(groups.now.length)html+=renderSection('📖','Читаю сейчас',groups.now);
        if(groups.will.length)html+=renderSection('🔖','Буду читать',groups.will);
        if(groups.read.length)html+=renderSection('✅','Прочитано',groups.read);
        Object.entries(groups.custom).forEach(([name,arr])=>{if(arr.length)html+=renderSection('🏷',''+name,arr);});
        c.innerHTML=html||'<div class="empty-page">📭 Список пуст</div>';
    } else {
        // List view
        let html='<div class="list-view">';
        items.forEach(m=>{let src=m.cover_imgbb_url||'';if(!src&&m.file_id)src='/api/cover/'+m.file_id;const covId='lc'+m.id,phId='lp'+m.id;html+=`<a class="list-item" href="/read/${m.id}">${src?`<img class="list-cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';document.getElementById('${phId}').style.display='flex'">`:''}<div class="list-cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}">📖</div><div class="list-body"><div class="list-title">${escapeHtml(m.title)}</div><div class="list-date">${getBadgeLabel(m.status)}</div></div><div class="list-status"><span class="badge ${getBadgeClass(m.status)}">${getBadgeLabel(m.status)}</span></div></a>`;});
        html+='</div>';c.innerHTML=html;
    }
}
async function load(){try{const res=await fetch('/api/library?tg_user_id='+getTgUser());const data=await res.json();const content=document.getElementById('content');if(!data.items?.length){content.innerHTML='<div class="empty-page">📭 Пока нет добавленной манги<br><br><a href="/" style="color:var(--accent)">В каталог →</a></div>';return;}allItems=data.items;renderLib(allItems);}catch(e){document.getElementById('content').innerHTML='<div class="empty-page">❌ Ошибка загрузки</div>';}}
load();
</script></body></html><?php exit;}

# ========================= HOME =========================
$total=$pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
$botUsername=getenv('BOT_USERNAME')?:'blackwatch_manga_bot';
// Count unread admin messages
$msgCount=(int)$pdo->query("SELECT COUNT(*) FROM admin_messages WHERE is_deleted=FALSE")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BLACKWATCH | Manga Reader</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ===== THEME VARS ===== */
:root{
    --bg:#0c0c0c;
    --bg2:#111111;
    --card:#161616;
    --card2:#121212;
    --border:#242424;
    --border2:#2e2e2e;
    --text:#f2f2f2;
    --text2:#c8c8c8;
    --muted:#666666;
    --accent:#e0e0e0;
    --accent2:#ffffff;
    --green:#4ade80;
    --orange:#fb923c;
    --red:#f87171;
    --blue:#60a5fa;
    --shadow:0 8px 40px rgba(0,0,0,0.7);
    --shadow2:0 2px 12px rgba(0,0,0,0.4);
    --grad:linear-gradient(180deg,#0c0c0c 0%,#141414 100%);
}
.light{
    --bg:#f7f7f7;
    --bg2:#efefef;
    --card:#ffffff;
    --card2:#fafafa;
    --border:#e2e2e2;
    --border2:#d4d4d4;
    --text:#141414;
    --text2:#3a3a3a;
    --muted:#a0a0a0;
    --accent:#333333;
    --accent2:#111111;
    --shadow:0 4px 24px rgba(0,0,0,0.1);
    --shadow2:0 2px 8px rgba(0,0,0,0.06);
    --grad:linear-gradient(180deg,#f7f7f7 0%,#efefef 100%);
}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:var(--bg);background:var(--grad);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;transition:background 0.3s,color 0.3s}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 50% at 50% -10%,rgba(255,255,255,0.025) 0%,transparent 60%);pointer-events:none;z-index:0}

/* ===== HEADER ===== */
header{position:sticky;top:0;z-index:200;backdrop-filter:blur(28px);-webkit-backdrop-filter:blur(28px);background:rgba(12,12,12,0.92);border-bottom:1px solid var(--border)}
.light header{background:rgba(247,247,247,0.92);border-bottom:1px solid var(--border)}
.header-inner{max-width:1280px;margin:auto;height:58px;display:flex;align-items:center;gap:12px;padding:0 22px;position:relative}
.logo{font-size:17px;font-weight:800;font-family:'Syne',sans-serif;color:var(--text2);text-decoration:none;flex-shrink:0;letter-spacing:2px;text-transform:uppercase;opacity:0.9}
.logo span{color:var(--text);opacity:1}
/* Search */
.search-wrap{position:absolute;left:50%;transform:translateX(-50%);width:100%;max-width:440px;z-index:10}
@media(max-width:768px){.search-wrap{position:relative;left:auto;transform:none;max-width:none;flex:1}}
.search{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Inter',sans-serif;font-size:13px;padding:9px 15px 9px 38px;transition:all 0.2s;outline:none;letter-spacing:0.1px}
.light .search{background:rgba(0,0,0,0.03);border-color:var(--border)}
.search:focus{border-color:var(--border2);background:rgba(255,255,255,0.06);box-shadow:0 0 0 3px rgba(255,255,255,0.04)}
.light .search:focus{box-shadow:0 0 0 3px rgba(0,0,0,0.06)}
.search-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none}
/* Search dropdown — smooth fade-in */
.search-dropdown{position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);z-index:300;overflow:hidden;max-height:380px;overflow-y:auto;opacity:0;transform:translateY(-8px) scale(0.99);transition:opacity 0.2s ease,transform 0.2s ease;pointer-events:none}
.search-dropdown.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
.sd-item{display:flex;align-items:center;gap:11px;padding:9px 13px;cursor:pointer;transition:background 0.12s;text-decoration:none;color:var(--text);animation:sdSlideIn 0.18s ease both}
@keyframes sdSlideIn{from{opacity:0;transform:translateX(-6px)}to{opacity:1;transform:translateX(0)}}
.sd-item:hover{background:rgba(255,255,255,0.05)}
.light .sd-item:hover{background:rgba(0,0,0,0.04)}
.sd-cover{width:34px;height:46px;object-fit:cover;border-radius:6px;flex-shrink:0;background:var(--border)}
.sd-cover-ph{width:34px;height:46px;border-radius:6px;flex-shrink:0;background:var(--card2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:15px}
.sd-info{flex:1;min-width:0}
.sd-title{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.sd-meta{font-size:10px;color:var(--muted);margin-top:2px;font-weight:400}
/* Header actions */
.header-actions{margin-left:auto;display:flex;align-items:center;gap:5px;flex-shrink:0}
.hbtn{padding:7px 13px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text2);font-size:12px;font-weight:500;cursor:pointer;text-decoration:none;transition:all 0.18s;display:inline-flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;white-space:nowrap;letter-spacing:0.2px}
.hbtn:hover{border-color:var(--border2);color:var(--text);background:rgba(255,255,255,0.04)}
.light .hbtn:hover{background:rgba(0,0,0,0.04)}
.hbtn-admin{display:none}
.hbtn-admin.visible{display:inline-flex}
/* Theme toggle */
.theme-btn{width:34px;height:34px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.18s;flex-shrink:0}
.theme-btn:hover{border-color:var(--border2);color:var(--text)}

/* ===== SIDEBAR ICONS ===== */
.sidebar-icons{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:150}
.sidebar-rail{background:rgba(20,20,20,0.95);backdrop-filter:blur(20px);border:1px solid var(--border);border-right:none;border-radius:12px 0 0 12px;padding:8px 7px;display:flex;flex-direction:column;gap:6px}
.light .sidebar-rail{background:rgba(255,255,255,0.97)}
.sidebar-icon-btn{width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.18s;position:relative;text-decoration:none}
.sidebar-icon-btn:hover{border-color:var(--border2);color:var(--text);background:rgba(255,255,255,0.05)}
.light .sidebar-icon-btn:hover{background:rgba(0,0,0,0.05)}
.sidebar-badge{position:absolute;top:-4px;right:-4px;width:11px;height:11px;border-radius:50%;background:#f87171;border:2px solid var(--bg);animation:pulse-red 2s infinite}
@keyframes pulse-red{0%,100%{box-shadow:0 0 0 0 rgba(248,113,113,0.5)}50%{box-shadow:0 0 0 5px rgba(248,113,113,0)}}
@media(max-width:600px){.sidebar-icons{display:none}}

/* ===== MAIN WRAP ===== */
.wrap{max-width:1280px;margin:auto;padding:20px 22px 80px;position:relative;z-index:1}

/* ===== SECTION HEADER ===== */
.sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.sec-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;font-family:'Syne',sans-serif;letter-spacing:0.8px;text-transform:uppercase;color:var(--text2)}
.sec-count{background:rgba(255,255,255,0.08);color:var(--muted);border-radius:20px;padding:1px 8px;font-size:10px;font-weight:600;letter-spacing:0.3px}
.light .sec-count{background:rgba(0,0,0,0.07)}

/* ===== NEW MANGA SLIDER ===== */
.new-section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px 18px 14px;margin-bottom:16px;position:relative;overflow:hidden}
.new-section::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.1),transparent)}
.slider-wrap{position:relative;display:flex;align-items:center;gap:8px}
.slider-outer{flex:1;overflow:hidden}
.slider-track{display:flex;gap:10px;transition:transform 0.38s cubic-bezier(0.4,0,0.2,1)}
.slide-card{flex:0 0 112px;text-decoration:none;color:var(--text);transition:transform 0.2s}
.slide-card:hover{transform:translateY(-4px)}
.slide-cover{width:112px;height:150px;object-fit:cover;border-radius:10px;display:block;background:var(--border)}
.slide-cover-ph{width:112px;height:150px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--card2);border:1px solid var(--border);font-size:26px;color:var(--muted)}
.slide-title{font-size:10px;font-weight:600;margin-top:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;color:var(--text2)}
.sarrow{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--card2);color:var(--muted);font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.18s;flex-shrink:0}
.sarrow:hover{border-color:var(--border2);color:var(--text)}
.sarrow.hidden{opacity:0;pointer-events:none}

/* ===== CONTINUE READING ===== */
.cont-section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px 18px;margin-bottom:16px;position:relative;overflow:hidden}
.cont-section::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(251,146,60,0.3),transparent)}
.cont-list{display:flex;gap:9px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.cont-list::-webkit-scrollbar{display:none}
.cont-card{flex:0 0 200px;background:var(--card2);border:1px solid var(--border);border-radius:10px;overflow:hidden;text-decoration:none;color:var(--text);display:flex;transition:all 0.2s}
.cont-card:hover{border-color:var(--border2);transform:translateY(-2px);box-shadow:var(--shadow2)}
.cont-cover-wrap{width:50px;flex-shrink:0}
.cont-cover{width:100%;height:100%;object-fit:cover;display:block;min-height:75px}
.cont-cover-ph{width:100%;min-height:75px;display:flex;align-items:center;justify-content:center;background:var(--card);font-size:18px;color:var(--muted)}
.cont-body{flex:1;padding:8px 10px;display:flex;flex-direction:column;justify-content:space-between;min-width:0}
.cont-title{font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;margin-bottom:3px;color:var(--text)}
.cont-chapter{font-size:10px;color:var(--muted);margin-bottom:3px;font-weight:500}
.cont-bar-bg{height:2px;background:var(--border);border-radius:2px;overflow:hidden;margin-bottom:5px}
.cont-bar-fill{height:100%;background:rgba(251,146,60,0.6);border-radius:2px;transition:width 0.4s}
.cont-btn{display:inline-block;padding:2px 8px;background:rgba(251,146,60,0.1);border:1px solid rgba(251,146,60,0.2);border-radius:20px;color:var(--orange);font-size:9px;font-weight:600;letter-spacing:0.2px}

/* ===== FILTERS ===== */
.filters{display:flex;gap:6px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.filter-btn{padding:6px 14px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;font-weight:500;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;letter-spacing:0.2px}
.filter-btn:hover{border-color:var(--border2);color:var(--text2)}
.filter-btn.active{background:var(--card);border-color:var(--border2);color:var(--text);font-weight:600}
.stats-label{color:var(--muted);font-size:11px;margin-left:auto;font-weight:400}

/* ===== GRID ===== */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:14px}
@media(max-width:600px){.grid{grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px}}
@media(max-width:380px){.grid{grid-template-columns:repeat(2,1fr);gap:8px}}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;text-decoration:none;color:var(--text);transition:all 0.22s;position:relative;animation:cardIn 0.28s ease both}
@keyframes cardIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.card:hover{transform:translateY(-4px);border-color:var(--border2);box-shadow:0 8px 28px rgba(0,0,0,0.3)}
.cover{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}
.cover-ph{width:100%;aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;background:var(--card2);color:var(--muted);font-size:32px}
.info{padding:9px 10px}
.title{font-size:11px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.45;font-family:'Inter',sans-serif;color:var(--text2)}
.likes{margin-top:5px;color:var(--muted);font-size:10px;font-weight:400}
.card-new-badge{position:absolute;top:7px;left:7px;background:rgba(248,113,113,0.9);color:#fff;font-size:8px;font-weight:700;padding:2px 7px;border-radius:5px;text-transform:uppercase;letter-spacing:0.5px;backdrop-filter:blur(4px)}
.card-series-badge{display:inline-block;background:rgba(255,255,255,0.08);color:var(--muted);border:1px solid var(--border);padding:2px 7px;border-radius:5px;font-size:8px;font-weight:600;margin-top:4px;letter-spacing:0.3px}
/* Rating on card */
.card-rating{display:flex;align-items:center;gap:3px;margin-top:4px}
.card-stars{color:#f59e0b;font-size:9px;letter-spacing:0.5px}
.card-rating-val{font-size:9px;color:var(--muted);font-weight:600}
/* Button micro-animations */
.hbtn{transition:all 0.18s cubic-bezier(0.34,1.56,0.64,1)!important}
.hbtn:hover{transform:translateY(-2px)!important;box-shadow:0 4px 12px rgba(0,0,0,0.3)!important}
.hbtn:active{transform:scale(0.96)!important}
.filter-btn{transition:all 0.2s cubic-bezier(0.34,1.56,0.64,1)!important}
.filter-btn:hover{transform:translateY(-1px)}
.filter-btn.active{transform:translateY(-1px)}
.sarrow{transition:all 0.2s cubic-bezier(0.34,1.56,0.64,1)!important}
.sarrow:hover{transform:scale(1.12)!important}
.sidebar-icon-btn{transition:all 0.2s cubic-bezier(0.34,1.56,0.64,1)!important}
.sidebar-icon-btn:hover{transform:translateX(-3px) scale(1.08)!important}
.load-more{transition:all 0.22s cubic-bezier(0.34,1.56,0.64,1)!important}
.load-more:hover{transform:translateY(-2px)!important}
/* Manga card hover overlay */
.card::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,0.03);opacity:0;transition:opacity 0.2s;pointer-events:none;border-radius:12px}
.card:hover::after{opacity:1}
.card:hover .cover{transform:scale(1.03);transition:transform 0.35s ease}
.cover{transition:transform 0.35s ease}
.card:active{transform:scale(0.97)!important}

/* ===== LOAD MORE ===== */
.load-more{margin:32px auto;display:block;padding:11px 30px;background:transparent;border:1px solid var(--border);color:var(--muted);border-radius:8px;cursor:pointer;font-size:12px;font-weight:500;font-family:'Inter',sans-serif;transition:all 0.18s;letter-spacing:0.3px}
.load-more:hover{border-color:var(--border2);color:var(--text);background:rgba(255,255,255,0.03)}
.empty{text-align:center;padding:80px 20px;color:var(--muted);font-size:13px}

/* ===== TOAST ===== */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,0.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;box-shadow:var(--shadow);animation:toastIn 0.25s ease;white-space:nowrap;pointer-events:none;border:1px solid var(--border2);backdrop-filter:blur(12px)}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}

/* ===== PAGE LOADER ===== */
#page-loader{position:fixed;inset:0;z-index:9998;background:var(--bg);display:flex;align-items:center;justify-content:center;transition:opacity 0.4s ease,visibility 0.4s ease}
#page-loader.hidden{opacity:0;visibility:hidden;pointer-events:none}
.loader-ring{position:relative;width:56px;height:56px}
.loader-dot{position:absolute;width:10px;height:10px;border-radius:50%;background:var(--border2);animation:loaderSpin 1.2s linear infinite}
.loader-dot:nth-child(1){top:0;left:50%;transform:translateX(-50%);animation-delay:0s}
.loader-dot:nth-child(2){top:14%;right:14%;animation-delay:0.15s}
.loader-dot:nth-child(3){top:50%;right:0;transform:translateY(-50%);animation-delay:0.3s}
.loader-dot:nth-child(4){bottom:14%;right:14%;animation-delay:0.45s}
.loader-dot:nth-child(5){bottom:0;left:50%;transform:translateX(-50%);animation-delay:0.6s}
.loader-dot:nth-child(6){bottom:14%;left:14%;animation-delay:0.75s}
.loader-dot:nth-child(7){top:50%;left:0;transform:translateY(-50%);animation-delay:0.9s}
.loader-dot:nth-child(8){top:14%;left:14%;animation-delay:1.05s}
@keyframes loaderSpin{0%,100%{opacity:0.15;transform:scale(0.7)}50%{opacity:1;transform:scale(1.1);background:var(--text2)}}

/* ===== MODAL BASE ===== */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.88);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);z-index:1000;display:none;align-items:center;justify-content:center;padding:12px;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:18px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;padding:24px;position:relative;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.modal::-webkit-scrollbar{width:3px}
.modal::-webkit-scrollbar-thumb{background:var(--border2);border-radius:10px}
.modal-x{position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--muted);font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.18s}
.modal-x:hover{background:rgba(248,113,113,0.1);color:#f87171;border-color:#f87171}
.modal-head{font-size:18px;font-weight:800;font-family:'Syne',sans-serif;color:var(--text);margin-bottom:4px;letter-spacing:0.3px}
.modal-sub{color:var(--muted);font-size:12px;margin-bottom:20px;font-weight:400}

/* FORM */
.fg{margin-bottom:13px}
.fl{display:block;font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:5px}
.fi,.fta,.fsel{width:100%;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:'Inter',sans-serif;font-size:13px;padding:9px 12px;transition:border-color 0.18s;resize:none}
.light .fi,.light .fta,.light .fsel{background:rgba(0,0,0,0.03)}
.fi:focus,.fta:focus,.fsel:focus{outline:none;border-color:var(--border2)}
.fta{min-height:72px}
.toggle-row{display:flex;gap:6px;margin-bottom:13px}
.toggle-btn{flex:1;padding:8px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:12px;font-weight:500;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.18s}
.toggle-btn.active{background:var(--card2);border-color:var(--border2);color:var(--text);font-weight:600}
.upload-zone{border:1px dashed var(--border);border-radius:10px;padding:16px;text-align:center;cursor:pointer;transition:all 0.18s;position:relative}
.upload-zone:hover,.upload-zone.drag{border-color:var(--border2);background:rgba(255,255,255,0.02)}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-icon{font-size:22px;margin-bottom:5px}
.upload-text{font-size:11px;color:var(--muted);line-height:1.5}
.upload-preview{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;justify-content:center}
.preview-img{width:50px;height:68px;object-fit:cover;border-radius:5px}
.preview-count{font-size:11px;font-weight:600;color:var(--text2);padding:6px;background:rgba(255,255,255,0.04);border-radius:7px;text-align:center;width:100%}
.file-tabs{display:flex;gap:4px;margin-bottom:10px}
.file-tab{flex:1;padding:7px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:11px;font-weight:500;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.18s;text-align:center}
.file-tab.active{background:var(--card2);border-color:var(--border2);color:var(--text);font-weight:600}
.file-panel{display:none}.file-panel.active{display:block}
.upbar{height:3px;background:var(--border);border-radius:3px;overflow:hidden;margin-bottom:14px;display:none}
.upbar.active{display:block}
.upbar-fill{height:100%;background:var(--text2);border-radius:3px;width:0;transition:width 0.3s}
.sbtn{width:100%;padding:12px;background:var(--text);color:var(--bg);border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.18s;display:flex;align-items:center;justify-content:center;gap:8px;letter-spacing:0.2px}
.sbtn:hover:not(:disabled){opacity:0.88;transform:translateY(-1px)}
.sbtn:disabled{opacity:0.35;cursor:not-allowed}
.sbtn.loading .btn-text{opacity:0.7}
.spinner{width:14px;height:14px;border:2px solid rgba(0,0,0,0.3);border-top-color:#000;border-radius:50%;animation:spin 0.8s linear infinite;display:none}
.sbtn.loading .spinner{display:block}
.result-banner{border-radius:9px;padding:0;max-height:0;overflow:hidden;transition:all 0.3s;font-size:12px;line-height:1.6}
.result-banner.open{padding:10px 13px;max-height:300px;margin-top:11px}
.result-banner.success{background:rgba(74,222,128,0.07);border:1px solid rgba(74,222,128,0.2);color:#86efac}
.result-banner.error{background:rgba(248,113,113,0.07);border:1px solid rgba(248,113,113,0.2);color:#fca5a5}
.result-banner a{color:inherit;font-weight:700}
@keyframes spin{to{transform:rotate(360deg)}}

/* ===== ADMIN MODAL ===== */
.admin-modal{max-width:960px}
.admin-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:18px;overflow-x:auto;scrollbar-width:none;gap:0}
.admin-tabs::-webkit-scrollbar{display:none}
.atab{padding:10px 14px;background:transparent;border:none;color:var(--muted);font-size:12px;font-weight:500;cursor:pointer;font-family:'Inter',sans-serif;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all 0.18s;white-space:nowrap;letter-spacing:0.2px}
.atab.active{color:var(--text);border-bottom-color:var(--text2)}
.atab:hover{color:var(--text2)}
.apanel{display:none}.apanel.active{display:block}
.stats-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
@media(max-width:640px){.stats-layout{grid-template-columns:1fr}}
.stats-tabs{display:flex;gap:0;margin-bottom:13px;background:rgba(255,255,255,0.03);border-radius:9px;padding:3px;border:1px solid var(--border)}
.stab{flex:1;padding:7px;background:transparent;border:none;color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;border-radius:7px;transition:all 0.18s;text-align:center;letter-spacing:0.2px}
.stab.active{background:var(--card2);color:var(--text);border:1px solid var(--border)}
.scard-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
.scard{background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px;padding:12px 10px;text-align:center;transition:all 0.18s}
.scard:hover{border-color:var(--border2)}
.scard-num{font-size:22px;font-weight:800;color:var(--text2);line-height:1;font-family:'Syne',sans-serif}
.scard-lbl{font-size:10px;color:var(--muted);margin-top:3px;font-weight:400}
.scard.green .scard-num{color:var(--green)}
.scard.orange .scard-num{color:var(--orange)}
.edit-manga-btn{width:100%;padding:9px;background:transparent;border:1px solid var(--border);border-radius:9px;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.18s;display:flex;align-items:center;justify-content:center;gap:6px;margin-top:8px}
.edit-manga-btn:hover{border-color:var(--border2);color:var(--text)}
.top-list{display:flex;flex-direction:column;gap:4px;margin-top:8px}
.top-row{display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:8px}
.top-name{font-size:11px;font-weight:500;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:8px;color:var(--text2)}
.top-likes{font-size:11px;color:var(--muted);font-weight:600}
.archive-list{display:flex;flex-direction:column;gap:5px;max-height:300px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.aitem{background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:8px;padding:8px 10px}
.atype{font-size:9px;font-weight:700;color:var(--text2);text-transform:uppercase;margin-bottom:2px;letter-spacing:0.4px}
.atext{font-size:11px;color:var(--text2)}.adate{font-size:9px;color:var(--muted);margin-top:2px}
.func-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:8px}
.func-btn{width:100%;padding:11px 13px;border:1px solid var(--border);background:var(--card2);border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.18s;display:flex;align-items:center;justify-content:center;gap:7px;margin-bottom:7px;color:var(--text2)}
.func-btn:hover{border-color:var(--border2);color:var(--text);background:var(--card);transform:translateY(-1px)}
.func-btn:last-child{margin-bottom:0}
.func-green{border-color:rgba(74,222,128,0.25);color:var(--green)}
.func-green:hover{background:rgba(74,222,128,0.06)!important;border-color:rgba(74,222,128,0.4)!important}
.func-orange-row{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:7px}
.func-orange-row .func-btn{margin-bottom:0;font-size:11px;padding:9px 7px}
.func-amber{border-color:rgba(251,146,60,0.25);color:var(--orange)}
.func-amber:hover{background:rgba(251,146,60,0.06)!important;border-color:rgba(251,146,60,0.4)!important}
.func-blue{border-color:rgba(96,165,250,0.25);color:var(--blue)}
.func-blue:hover{background:rgba(96,165,250,0.06)!important;border-color:rgba(96,165,250,0.4)!important}
.func-purple{border-color:rgba(255,255,255,0.15);color:var(--text2)}
/* Admin messages */
.msg-list{display:flex;flex-direction:column;gap:7px;max-height:360px;overflow-y:auto;scrollbar-width:thin}
.msg-item{background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px;padding:11px 13px}
.msg-text{font-size:13px;color:var(--text2);margin-bottom:5px;line-height:1.55}
.msg-meta{font-size:10px;color:var(--muted);font-weight:400}
.msg-del{padding:2px 9px;background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.2);border-radius:6px;color:var(--red);font-size:10px;cursor:pointer;font-family:inherit;font-weight:600;float:right;margin-left:8px}
.msg-compose{margin-bottom:13px}
.msg-compose textarea{width:100%;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:'Inter',sans-serif;font-size:13px;padding:9px 12px;min-height:80px;resize:none}
.msg-compose textarea:focus{outline:none;border-color:var(--border2)}
.msg-send-btn{padding:8px 18px;background:var(--text);color:var(--bg);border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit;font-size:12px;margin-top:7px;transition:opacity 0.18s}
.msg-send-btn:hover{opacity:0.85}
.admins-wrap{margin-top:4px}
.admin-lbl{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:7px}
.admin-row{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:4px}
.admin-row:last-child{margin-bottom:0}
.admin-tag{font-size:12px;font-weight:600;color:var(--text2)}
.admin-id{font-size:10px;color:var(--muted);font-weight:400}
.copy-btn{padding:3px 9px;background:transparent;border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:10px;cursor:pointer;font-family:inherit;font-weight:500;transition:all 0.18s;flex-shrink:0}
.copy-btn:hover{border-color:var(--border2);color:var(--text)}
.suggest-preview{display:flex;flex-direction:column;gap:5px;max-height:180px;overflow-y:auto;scrollbar-width:thin;margin-top:7px}
.sug-item{background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:8px;padding:8px 10px;display:flex;flex-direction:column;gap:3px}
.sug-text{font-size:11px;color:var(--text2);line-height:1.5}
.sug-meta{font-size:9px;color:var(--muted)}
.sug-read-btn{padding:2px 9px;background:rgba(74,222,128,0.06);border:1px solid rgba(74,222,128,0.2);border-radius:6px;color:var(--green);font-size:9px;cursor:pointer;font-family:inherit;font-weight:600;margin-top:3px;align-self:flex-start}
.esearch-row{display:flex;gap:6px;margin-bottom:11px}
.esearch-inp{flex:1;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:'Inter',sans-serif;font-size:12px;padding:7px 11px}
.light .esearch-inp{background:rgba(0,0,0,0.03)}
.esearch-inp:focus{outline:none;border-color:var(--border2)}
.esearch-btn{padding:7px 15px;background:var(--text);color:var(--bg);border:none;border-radius:9px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;font-size:12px;transition:opacity 0.18s}
.esearch-btn:hover{opacity:0.85}
.manga-edit-list{display:flex;flex-direction:column;gap:5px;max-height:300px;overflow-y:auto;scrollbar-width:thin}
.meitem{display:flex;align-items:center;gap:9px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:9px;padding:8px 10px;cursor:pointer;transition:all 0.18s}
.meitem:hover{border-color:var(--border2)}
.me-cover{width:32px;height:44px;object-fit:cover;border-radius:5px;flex-shrink:0;background:var(--card2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--muted)}
.me-title{font-size:11px;font-weight:600;flex:1;min-width:0;color:var(--text2)}
.me-meta{font-size:9px;color:var(--muted)}
.edit-form-wrap{background:rgba(255,255,255,0.01);border:1px solid var(--border);border-radius:12px;padding:14px}
.ef{margin-bottom:11px}
.ef label{display:block;font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px}
.ef input,.ef textarea{width:100%;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'Inter',sans-serif;font-size:12px;padding:7px 10px;transition:border-color 0.18s;resize:none}
.ef input:focus,.ef textarea:focus{outline:none;border-color:var(--border2)}
.ef textarea{min-height:66px}
.edit-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:11px}
.save-btn{padding:7px 18px;background:var(--text);color:var(--bg);border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;font-size:12px;transition:opacity 0.18s}
.save-btn:hover{opacity:0.85}
.del-btn{padding:7px 14px;background:transparent;border:1px solid rgba(248,113,113,0.3);border-radius:8px;color:var(--red);font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;font-size:12px}
.back-edit-btn{padding:7px 13px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--muted);font-weight:500;cursor:pointer;font-family:'Inter',sans-serif;font-size:12px;margin-bottom:11px}
.ch-admin-list{display:flex;flex-direction:column;gap:4px;margin-top:7px}
.ch-admin-item{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:7px;padding:6px 10px}
.del-ch-btn{padding:2px 8px;background:rgba(248,113,113,0.06);border:1px solid rgba(248,113,113,0.2);border-radius:5px;color:var(--red);font-size:9px;cursor:pointer;font-family:inherit}
.pagination{display:flex;gap:5px;margin-top:11px;justify-content:center;align-items:center}
.page-btn{padding:5px 11px;background:transparent;border:1px solid var(--border);border-radius:7px;color:var(--text2);cursor:pointer;font-family:'Inter',sans-serif;font-size:11px;transition:all 0.18s}
.page-btn:hover{border-color:var(--border2);color:var(--text)}
.add-ch-form{background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px;padding:12px;margin-top:9px}
.add-ch-form h4{font-size:11px;font-weight:700;margin-bottom:8px;color:var(--text2)}
.ch-inputs{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap}
.ch-inputs input{flex:1;min-width:90px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:7px;color:var(--text);font-family:'Inter',sans-serif;font-size:12px;padding:7px 10px}
.ch-inputs input:focus{outline:none;border-color:var(--border2)}

/* ===== MESSAGES MODAL ===== */
.messages-modal{max-width:500px}

/* ===== SUPPORT MODAL ===== */
.support-modal{max-width:460px}

/* ===== MOBILE ===== */
@media(max-width:768px){
    .header-inner{height:50px;padding:0 14px}
    .search-wrap{max-width:none;flex:1}
    .hbtn-tg{display:none}
    .wrap{padding:14px 12px 70px}
    .new-section,.cont-section{padding:13px 12px 11px;border-radius:13px}
    .grid{grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px}
    .admin-modal{max-width:100%}
    .stats-layout{grid-template-columns:1fr}
    .func-orange-row{grid-template-columns:1fr 1fr}
    .modal{padding:18px 14px;border-radius:15px}
}
@media(max-width:480px){
    .logo{font-size:14px}
    .hbtn-lib span{display:none}
    .hbtn-admin span{display:none}
    .grid{grid-template-columns:repeat(auto-fill,minmax(122px,1fr));gap:8px}
    .cont-card{flex:0 0 175px}
    .slide-card{flex:0 0 100px}
    .slide-cover{width:100px;height:134px}
    .slide-cover-ph{width:100px;height:134px}
    .scard-grid{grid-template-columns:1fr 1fr}
}
</style>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>

<!-- PAGE LOADER -->
<div id="page-loader">
  <div class="loader-ring">
    <div class="loader-dot"></div><div class="loader-dot"></div>
    <div class="loader-dot"></div><div class="loader-dot"></div>
    <div class="loader-dot"></div><div class="loader-dot"></div>
    <div class="loader-dot"></div><div class="loader-dot"></div>
  </div>
</div>

<header>
<div class="header-inner">
    <a href="/" class="logo">⚫ BLACKWATCH</a>
    <div class="search-wrap">
        <span class="search-icon">🔍</span>
        <input class="search" type="text" placeholder="Поиск манги..." id="search" oninput="onSearch(this.value)" autocomplete="off">
        <div class="search-dropdown" id="search-dropdown"></div>
    </div>
    <div class="header-actions">
        <button class="theme-btn" onclick="toggleTheme()" title="Сменить тему" id="theme-btn">🌙</button>
        <button class="hbtn hbtn-ghost" onclick="openRandom()">🎲</button>
        <a href="/library" class="hbtn hbtn-lib">📚 <span>Библиотека</span></a>
        <a href="https://t.me/<?=htmlspecialchars($botUsername)?>" target="_blank" class="hbtn hbtn-tg">🤖 Бот</a>
        <button class="hbtn hbtn-admin" id="admin-btn" onclick="openAdminPanel()">⚙️ <span>Админ</span></button>
    </div>
</div>
</header>

<!-- SIDEBAR ICONS -->
<div class="sidebar-icons">
    <div class="sidebar-rail">
        <button class="sidebar-icon-btn" onclick="openMessagesModal()" title="Сообщения">
            💬
            <?php if($msgCount>0):?><span class="sidebar-badge" id="msg-badge"></span><?php endif;?>
        </button>
        <button class="sidebar-icon-btn" onclick="openSupportModal()" title="Поддержка">
            🛟
        </button>
    </div>
</div>

<div class="wrap">
    <!-- НОВИНКИ -->
    <div class="new-section" id="new-section" style="display:none">
        <div class="sec-header">
            <div class="sec-title"><span>🔥</span>Новинки<span class="sec-count" id="new-count">0</span></div>
        </div>
        <div class="slider-wrap">
            <div class="sarrow left hidden" id="sl-left" onclick="slideLeft()">‹</div>
            <div class="slider-outer"><div class="slider-track" id="slider-track"></div></div>
            <div class="sarrow right hidden" id="sl-right" onclick="slideRight()">›</div>
        </div>
    </div>

    <!-- ПРОДОЛЖИТЬ -->
    <div class="cont-section" id="cont-section" style="display:none">
        <div class="sec-header"><div class="sec-title"><span>▶</span>Продолжить читать</div></div>
        <div class="cont-list" id="cont-list"></div>
    </div>

    <!-- FILTERS -->
    <div class="filters">
        <button class="filter-btn active" id="f-new" onclick="setFilter('new')">🕒 Новые</button>
        <button class="filter-btn" id="f-popular" onclick="setFilter('popular')">🔥 Популярные</button>
        <button class="filter-btn" id="f-alpha" onclick="setFilter('alpha')">🔤 А-Я</button>
        <span class="stats-label" id="stats">Манг: <strong><?=(int)$total?></strong></span>
    </div>
    <div class="grid" id="grid"></div>
    <button class="load-more" id="more" onclick="load()" style="display:none">Загрузить ещё</button>
</div>

<!-- MODAL: ADD MANGA -->
<div class="modal-overlay" id="add-modal" onclick="if(event.target===this)closeAddModal()">
<div class="modal">
    <button class="modal-x" onclick="closeAddModal()">✕</button>
    <div class="modal-head">Добавить мангу</div>
    <div class="modal-sub">Загрузи обложку и страницы</div>
    <div class="fg">
        <div class="toggle-row">
            <button class="toggle-btn active" id="type-single" onclick="setMangaType('single')">📄 Обычная</button>
            <button class="toggle-btn" id="type-series" onclick="setMangaType('series')">📚 Серия</button>
        </div>
    </div>
    <div class="fg"><label class="fl">Название</label><input class="fi" type="text" id="manga-title" placeholder="Название манги..."></div>
    <div class="fg"><label class="fl">Описание</label><textarea class="fta" id="manga-desc" placeholder="Краткое описание..."></textarea></div>
    <div class="fg">
        <label class="fl">Обложка</label>
        <div class="upload-zone" id="cover-zone">
            <input type="file" id="cover-input" accept="image/*" onchange="onCoverChange(this)">
            <div class="upload-icon">🖼</div>
            <div class="upload-text"><strong>Загрузить обложку</strong><br>JPG, PNG, WebP</div>
            <div class="upload-preview" id="cover-preview"></div>
        </div>
    </div>
    <div id="pages-section">
        <div class="fg">
            <label class="fl">Страницы</label>
            <div class="file-tabs">
                <div class="file-tab active" id="tab-zip" onclick="switchTab('zip')">📦 ZIP</div>
                <div class="file-tab" id="tab-photos" onclick="switchTab('photos')">📸 Фото</div>
            </div>
            <div class="file-panel active" id="panel-zip">
                <div class="upload-zone" id="zip-zone">
                    <input type="file" id="zip-input" accept=".zip" onchange="onZipChange(this)">
                    <div class="upload-icon">📦</div>
                    <div class="upload-text"><strong>ZIP-архив страниц</strong><br>Сортировка по дате</div>
                    <div class="upload-preview" id="zip-preview"></div>
                </div>
            </div>
            <div class="file-panel" id="panel-photos">
                <div class="upload-zone" id="photos-zone">
                    <input type="file" id="photos-input" accept="image/*" multiple onchange="onPhotosChange(this)">
                    <div class="upload-icon">📸</div>
                    <div class="upload-text"><strong>Выбери страницы</strong><br>001.jpg, 002.jpg...</div>
                    <div class="upload-preview" id="photos-preview"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="upbar" id="upload-progress"><div class="upbar-fill" id="upload-progress-fill"></div></div>
    <button class="sbtn" id="submit-btn" onclick="submitManga()"><span class="btn-text">🚀 Опубликовать</span><div class="spinner"></div></button>
    <div class="result-banner" id="result-banner"></div>
</div>
</div>

<!-- MODAL: MESSAGES -->
<div class="modal-overlay" id="messages-modal" onclick="if(event.target===this)closeMessagesModal()">
<div class="modal messages-modal">
    <button class="modal-x" onclick="closeMessagesModal()">✕</button>
    <div class="modal-head">💬 Сообщения</div>
    <div class="modal-sub">Сообщения от администрации</div>
    <div class="msg-list" id="msg-list"><div style="color:var(--muted);text-align:center;padding:20px;font-size:13px">Загрузка...</div></div>
</div>
</div>

<!-- MODAL: SUPPORT -->
<div class="modal-overlay" id="support-modal" onclick="if(event.target===this)closeSupportModal()">
<div class="modal support-modal">
    <button class="modal-x" onclick="closeSupportModal()">✕</button>
    <div class="modal-head">🛟 Поддержка</div>
    <div class="modal-sub">Напиши нам — мы ответим!</div>
    <div class="fg"><label class="fl">Твоё сообщение</label><textarea class="fta" id="support-text" placeholder="Опиши проблему или задай вопрос..." style="min-height:110px"></textarea></div>
    <button class="sbtn" onclick="sendSupport()"><span class="btn-text">📨 Отправить</span><div class="spinner"></div></button>
    <div class="result-banner" id="support-result"></div>
</div>
</div>

<!-- MODAL: ADMIN PANEL -->
<div class="modal-overlay" id="admin-modal" onclick="if(event.target===this)closeAdminPanel()">
<div class="modal admin-modal">
    <button class="modal-x" onclick="closeAdminPanel()">✕</button>
    <div class="modal-head">⚙️ Админ-панель</div>
    <div class="admin-tabs">
        <button class="atab active" onclick="switchAdminTab('stats')">📊 Статистика</button>
        <button class="atab" onclick="switchAdminTab('messages')">📨 Сообщения</button>
        <button class="atab" onclick="switchAdminTab('edit')">✏️ Редактирование</button>
        <button class="atab" onclick="switchAdminTab('add-chapter')">📚 Добавить главу</button>
    </div>

    <!-- STATS -->
    <div class="apanel active" id="panel-stats">
        <div class="stats-layout">
            <div class="stats-left">
                <div class="stats-tabs">
                    <button class="stab active" onclick="showStatsView('grid')">📊 Статистика</button>
                    <button class="stab" onclick="showStatsView('archive')">🗂 Архив</button>
                </div>
                <div id="stats-grid-view">
                    <div class="scard-grid" id="stat-grid"><div style="color:var(--muted);grid-column:1/-1;padding:10px 0;font-size:12px">Загрузка...</div></div>
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:7px">Топ по лайкам</div>
                    <div class="top-list" id="top-list"></div>
                    <button class="edit-manga-btn" onclick="switchAdminTab('edit')">✏️ Редактировать мангу</button>
                </div>
                <div id="stats-archive-view" style="display:none">
                    <div class="archive-list" id="archive-list"><div style="color:var(--muted);font-size:12px">Загрузка...</div></div>
                    <div class="pagination" id="archive-pagination"></div>
                </div>
            </div>
            <div class="stats-right">
                <div class="func-title">Функционал</div>
                <button class="func-btn func-green" onclick="closeAdminPanel();openAddModal()">➕ Добавить мангу<br><small style="font-size:10px;opacity:0.8">ZIP, обложка, описание</small></button>
                <div class="func-orange-row">
                    <button class="func-btn func-amber" onclick="switchAdminTab('add-chapter')">📚 Добавить серию</button>
                    <button class="func-btn func-amber" onclick="switchAdminTab('add-chapter')">📑 Добавить главу</button>
                </div>
                <button class="func-btn func-purple" onclick="switchAdminTab('messages')">📨 Написать всем <span id="suggest-badge" style="background:rgba(255,255,255,0.2);border-radius:10px;padding:1px 7px;font-size:10px"></span></button>
                <button class="func-btn func-blue" id="suggest-btn" onclick="showStatsView('suggestions')">💡 Предложки <span id="suggest-badge2" style="background:rgba(255,255,255,0.2);border-radius:10px;padding:1px 7px;font-size:10px"></span></button>
                <div class="admins-wrap" style="margin-top:14px">
                    <div class="admin-lbl">Список админов</div>
                    <div id="admins-list"><div style="color:var(--muted);font-size:11px">Загрузка...</div></div>
                </div>
            </div>
        </div>
        <div id="stats-suggestions-view" style="display:none;margin-top:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px">
                <div style="font-size:12px;font-weight:700">💡 Предложения пользователей</div>
                <button onclick="showStatsView('grid')" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:12px">← Назад</button>
            </div>
            <div class="suggest-preview" id="suggest-list"><div style="color:var(--muted);font-size:12px">Загрузка...</div></div>
            <div class="pagination" id="suggest-pagination"></div>
        </div>
    </div>

    <!-- MESSAGES panel -->
    <div class="apanel" id="panel-messages">
        <div class="msg-compose">
            <label class="fl" style="margin-bottom:6px">Написать всем пользователям</label>
            <textarea id="admin-msg-text" placeholder="Введи сообщение..."></textarea>
            <button class="msg-send-btn" onclick="sendAdminMessage()">📨 Отправить всем</button>
        </div>
        <div class="func-title" style="margin-top:14px">Отправленные сообщения</div>
        <div class="msg-list" id="admin-msg-list"><div style="color:var(--muted);font-size:12px;padding:10px 0">Загрузка...</div></div>
    </div>

    <!-- EDIT -->
    <div class="apanel" id="panel-edit">
        <div class="esearch-row">
            <input class="esearch-inp" id="edit-search-input" type="text" placeholder="🔍 Поиск манги...">
            <button class="esearch-btn" onclick="searchMangaEdit()">Найти</button>
        </div>
        <div class="manga-edit-list" id="manga-edit-list"><div style="color:var(--muted);padding:10px 0;font-size:12px">Введи название или оставь пустым</div></div>
        <div class="pagination" id="edit-pagination"></div>
        <div id="edit-manga-form-wrap" style="display:none">
            <button class="back-edit-btn" onclick="backToMangaList()">← Назад к списку</button>
            <div class="edit-form-wrap" id="edit-manga-form"></div>
        </div>
    </div>

    <!-- ADD CHAPTER -->
    <div class="apanel" id="panel-add-chapter">
        <div class="fg">
            <label class="fl">Манга / Серия</label>
            <div class="esearch-row">
                <input class="esearch-inp" id="ch-manga-search" type="text" placeholder="Поиск серии...">
                <button class="esearch-btn" onclick="searchMangaForChapter()">Найти</button>
            </div>
            <div class="manga-edit-list" id="ch-manga-list" style="max-height:160px"><div style="color:var(--muted);padding:9px 0;font-size:12px">Найдите серию выше</div></div>
        </div>
        <div id="ch-add-form" style="display:none">
            <div class="add-ch-form">
                <h4>➕ Новая глава</h4>
                <div class="ch-inputs">
                    <input type="number" id="ch-num" placeholder="Номер (1, 2, 2.5...)" step="0.1" min="0">
                    <input type="text" id="ch-title-input" placeholder="Название (необязательно)">
                </div>
                <div class="file-tabs">
                    <div class="file-tab active" id="ch-tab-zip" onclick="switchChTab('zip')">📦 ZIP</div>
                    <div class="file-tab" id="ch-tab-photos" onclick="switchChTab('photos')">📸 Фото</div>
                </div>
                <div class="file-panel active" id="ch-panel-zip">
                    <div class="upload-zone" id="ch-zip-zone" style="padding:13px">
                        <input type="file" id="ch-zip-input" accept=".zip" onchange="onChZipChange(this)">
                        <div class="upload-icon" style="font-size:20px">📦</div>
                        <div class="upload-text" style="font-size:11px">ZIP со страницами</div>
                        <div class="upload-preview" id="ch-zip-preview"></div>
                    </div>
                </div>
                <div class="file-panel" id="ch-panel-photos">
                    <div class="upload-zone" id="ch-photos-zone" style="padding:13px">
                        <input type="file" id="ch-photos-input" accept="image/*" multiple onchange="onChPhotosChange(this)">
                        <div class="upload-icon" style="font-size:20px">📸</div>
                        <div class="upload-text" style="font-size:11px">Страницы главы</div>
                        <div class="upload-preview" id="ch-photos-preview"></div>
                    </div>
                </div>
                <div class="upbar" id="ch-upload-progress"><div class="upbar-fill" id="ch-upload-fill"></div></div>
                <button class="sbtn" id="ch-submit-btn" onclick="submitChapter()" style="margin-top:7px"><span class="btn-text">📤 Загрузить главу</span><div class="spinner"></div></button>
                <div class="result-banner" id="ch-result-banner"></div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
// ===== THEME =====
(function(){
    const saved=localStorage.getItem('bw_theme')||'dark';
    if(saved==='light')document.body.classList.add('light');
    document.getElementById('theme-btn').textContent=saved==='light'?'🌙':'☀️';
})();
function toggleTheme(){
    const isLight=document.body.classList.toggle('light');
    localStorage.setItem('bw_theme',isLight?'light':'dark');
    document.getElementById('theme-btn').textContent=isLight?'🌙':'☀️';
}

// ===== TG =====
(function(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';}}catch(e){}})();
function getTgUser(){try{if(window.Telegram?.WebApp?.initDataUnsafe?.user){const id=window.Telegram.WebApp.initDataUnsafe.user.id;document.cookie='tg_user_id='+id+';max-age='+(86400*30)+';path=/';return id;}}catch(e){}const p=new URLSearchParams(location.search);const u=p.get('tg_user_id');if(u){document.cookie='tg_user_id='+u+';max-age='+(86400*30)+';path=/';return u;}const c=document.cookie.match(/tg_user_id=(\d+)/);return c?c[1]:'';}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function showToast(msg){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className='toast';t.innerText=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2600);}

// ===== ADMIN CHECK =====
async function checkAdmin(){
    try{const res=await fetch('/api/check-admin?tg_user_id='+getTgUser());const data=await res.json();if(data.is_admin)document.getElementById('admin-btn').classList.add('visible');}catch(e){}
}

// ===== CATALOG =====
let page=0,q='',loading=false,hasMore=true,currentSort='new';
const grid=document.getElementById('grid'),moreBtn=document.getElementById('more'),statsDiv=document.getElementById('stats');
function setFilter(sort){if(currentSort===sort)return;currentSort=sort;['new','popular','alpha'].forEach(s=>document.getElementById('f-'+s).classList.toggle('active',s===sort));load(true);}

// ===== SEARCH DROPDOWN =====
let searchTimeout;
const searchDrop=document.getElementById('search-dropdown');
function onSearch(val){
    clearTimeout(searchTimeout);
    q=val.trim();
    if(!q){searchDrop.classList.remove('open');load(true);return;}
    searchTimeout=setTimeout(async()=>{
        load(true);
        try{
            const res=await fetch(`/api/manga?page=0&q=${encodeURIComponent(q)}&sort=new`);
            const data=await res.json();
            if(!data.items.length){searchDrop.innerHTML='<div style="padding:14px;color:var(--muted);font-size:12px;text-align:center">Ничего не найдено</div>';searchDrop.classList.add('open');return;}
            searchDrop.innerHTML=data.items.slice(0,6).map(m=>{
                let src=m.cover_display||'';if(src&&src.startsWith('tg://'))src='';
                return `<a class="sd-item" href="/read/${m.id}">
                    ${src?`<img class="sd-cover" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';this.nextSibling.style.display='flex'">`:''}<div class="sd-cover-ph" style="${src?'display:none':'display:flex'}">📖</div>
                    <div class="sd-info"><div class="sd-title">${escapeHtml(m.title)}</div><div class="sd-meta">${m.is_series?'📚 Серия':'📄 Манга'}${m.likes>0?' · ♥ '+m.likes:''}</div></div>
                </a>`;
            }).join('');
            searchDrop.classList.add('open');
        }catch(e){}
    },280);
}
document.addEventListener('click',e=>{if(!e.target.closest('.search-wrap'))searchDrop.classList.remove('open');});

async function load(reset=false){
    if(loading)return;loading=true;
    if(reset){page=0;grid.innerHTML='';hasMore=true;moreBtn.style.display='none';}
    if(page===0&&!grid.children.length)grid.innerHTML='<div class="empty">📖 Загрузка...</div>';
    try{
        const res=await fetch(`/api/manga?page=${page}&q=${encodeURIComponent(q)}&sort=${currentSort}`);
        const data=await res.json();
        if(page===0){grid.innerHTML='';statsDiv.innerHTML=q?`Найдено: <strong>${data.total}</strong>`:`Манг: <strong>${data.total}</strong>`;}
        if(!data.items.length&&page===0){grid.innerHTML='<div class="empty">😔 Ничего не найдено</div>';loading=false;return;}
        const delay=reset?0:0;
        data.items.forEach((m,i)=>{
            const src=(m.cover_display&&!m.cover_display.startsWith('tg://'))?m.cover_display:'';
            const covId='cv'+m.id,phId='ph'+m.id;
            const el=document.createElement('a');
            el.className='card';el.href='/read/'+m.id;
            el.style.animationDelay=(i*30)+'ms';
            el.innerHTML=`${m.is_new?'<div class="card-new-badge">Новое</div>':''}
                ${src?`<img class="cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="document.getElementById('${covId}').style.display='none';document.getElementById('${phId}').style.display='flex'">`:'' }
                <div class="cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:36px">📖</span></div>
                <div class="info">
                    <div class="title">${escapeHtml(m.title)}</div>
                    ${m.is_series?'<div class="card-series-badge">📚 Серия</div>':''}
                    ${m.avg_rating>0?`<div class="card-rating"><span class="card-stars">${'★'.repeat(Math.round(m.avg_rating/2))}${'☆'.repeat(5-Math.round(m.avg_rating/2))}</span><span class="card-rating-val">${m.avg_rating}</span></div>`:(m.likes>0?`<div class="likes">♥ ${m.likes}</div>`:'')}
                </div>`;
            grid.appendChild(el);
        });
        hasMore=data.items.length>=data.limit;
        moreBtn.style.display=hasMore?'block':'none';
        page++;
    }catch(e){if(page===0)grid.innerHTML='<div class="empty">❌ Ошибка загрузки</div>';}
    loading=false;
}

async function openRandom(){
    try{const res=await fetch('/api/random?tg_user_id='+getTgUser());const d=await res.json();if(d.id)window.location.href='/read/'+d.id;else showToast('😔 Нет манги');}catch(e){}
}

// ===== NEW MANGA SLIDER =====
let sliderPos=0,sliderItems=[];
async function loadNew(){
    try{const res=await fetch('/api/new-manga');const data=await res.json();sliderItems=data.items||[];if(!sliderItems.length)return;const sec=document.getElementById('new-section');sec.style.display='block';document.getElementById('new-count').textContent=sliderItems.length;
    const track=document.getElementById('slider-track');
    track.innerHTML=sliderItems.map(m=>{const src=(m.cover_display&&!m.cover_display.startsWith('tg://'))?m.cover_display:'';return`<a class="slide-card" href="/read/${m.id}">${src?`<img class="slide-cover" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';this.nextSibling.style.display='flex'">`:''}<div class="slide-cover-ph" style="${src?'display:none':'display:flex'}">📖</div><div class="slide-title">${escapeHtml(m.title)}</div></a>`;}).join('');
    updateSliderArrows();}catch(e){}
}
function slideLeft(){if(sliderPos>0){sliderPos--;updateSlider();}}
function slideRight(){sliderPos++;updateSlider();}
function updateSlider(){document.getElementById('slider-track').style.transform=`translateX(-${sliderPos*130}px)`;updateSliderArrows();}
function updateSliderArrows(){const t=document.getElementById('slider-track');const maxPos=Math.max(0,sliderItems.length-Math.floor(t.parentElement.offsetWidth/130));document.getElementById('sl-left').classList.toggle('hidden',sliderPos<=0);document.getElementById('sl-right').classList.toggle('hidden',sliderPos>=maxPos);}

// ===== CONTINUE READING =====
async function loadContinue(){
    try{const tgId=getTgUser();if(!tgId)return;const res=await fetch('/api/progress?tg_user_id='+tgId);const data=await res.json();
    if(!data.items?.length)return;const sec=document.getElementById('cont-section');sec.style.display='block';
    const list=document.getElementById('cont-list');
    list.innerHTML=data.items.map(m=>{
        const pct=m.total_pages>0?Math.round(m.page_num/m.total_pages*100):0;
        let src=m.cover_imgbb_url||'';if(!src&&m.file_id)src='/api/cover/'+m.file_id;
        const covId='cc'+m.id,phId='cp'+m.id;
        const href=m.chapter_id?`/view-chapter/${m.chapter_id}`:`/read/${m.id}`;
        let btnLabel;
        if(m.is_series){if(m.is_first_chapter)btnLabel='Читать →';else if(m.total_pages>0)btnLabel=`Гл.${m.chapter_num} · ${m.page_num}/${m.total_pages}`;else btnLabel=`Гл. ${m.chapter_num||1}`;}
        else{btnLabel=m.total_pages>0?`Стр. ${m.page_num}/${m.total_pages}`:'Читать →';}
        const chapterLabel=m.chapter_id&&!m.is_first_chapter?`<div class="cont-chapter">Гл. ${escapeHtml(String(m.chapter_num||''))}</div>`:(m.is_series&&m.is_first_chapter?'<div class="cont-chapter">Серия</div>':'');
        return `<a class="cont-card" href="${href}"><div class="cont-cover-wrap">${src?`<img class="cont-cover" id="${covId}" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none';document.getElementById('${phId}').style.display='flex'">`:''}<div class="cont-cover-ph" id="${phId}" style="${src?'display:none':'display:flex'}"><span style="font-size:16px">📖</span></div></div><div class="cont-body"><div class="cont-title">${escapeHtml(m.title)}</div>${chapterLabel}${m.total_pages>0&&!m.is_first_chapter?`<div class="cont-bar-bg"><div class="cont-bar-fill" style="width:${pct}%"></div></div>`:''}<div class="cont-btn">${btnLabel}</div></div></a>`;
    }).join('');}catch(e){}
}

// ===== MESSAGES MODAL =====
function openMessagesModal(){document.getElementById('messages-modal').classList.add('open');loadUserMessages();}
function closeMessagesModal(){document.getElementById('messages-modal').classList.remove('open');}
async function loadUserMessages(){
    try{const res=await fetch('/api/admin/messages');const data=await res.json();const list=document.getElementById('msg-list');
    if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);text-align:center;padding:20px;font-size:13px">📭 Сообщений нет</div>';return;}
    list.innerHTML=data.items.map(m=>`<div class="msg-item"><div class="msg-text">${escapeHtml(m.text)}</div><div class="msg-meta">${new Date(m.created_at).toLocaleString('ru-RU')}</div></div>`).join('');}catch(e){}
}

// ===== SUPPORT MODAL =====
function openSupportModal(){document.getElementById('support-modal').classList.add('open');}
function closeSupportModal(){document.getElementById('support-modal').classList.remove('open');}
async function sendSupport(){const text=document.getElementById('support-text').value.trim();if(!text){showToast('Введи сообщение');return;}
try{const res=await fetch('/api/suggest',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text,tg_user_id:getTgUser()})});const d=await res.json();const b=document.getElementById('support-result');if(d.success){b.className='result-banner success open';b.innerHTML='✅ Сообщение отправлено!';document.getElementById('support-text').value='';}else{b.className='result-banner error open';b.innerHTML='❌ Ошибка';}}catch(e){}}

// ===== ADD MANGA MODAL =====
let coverFile=null,photoFiles=[],currentMangaType='single';
function openAddModal(){document.getElementById('add-modal').classList.add('open');}
function closeAddModal(){document.getElementById('add-modal').classList.remove('open');}
function setMangaType(t){currentMangaType=t;document.getElementById('type-single').classList.toggle('active',t==='single');document.getElementById('type-series').classList.toggle('active',t==='series');document.getElementById('pages-section').style.display=t==='single'?'block':'none';}
function switchTab(tab){['zip','photos'].forEach(t=>{document.getElementById('tab-'+t).classList.toggle('active',t===tab);document.getElementById('panel-'+t).classList.toggle('active',t===tab);});}
function onCoverChange(input){if(!input.files[0])return;coverFile=input.files[0];const preview=document.getElementById('cover-preview');const reader=new FileReader();reader.onload=e=>{preview.innerHTML=`<img class="preview-img" src="${e.target.result}" style="width:70px;height:94px">`};reader.readAsDataURL(coverFile);}
function onPhotosChange(input){photoFiles=Array.from(input.files).sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'}));if(!photoFiles.length)return;const preview=document.getElementById('photos-preview');preview.innerHTML=`<div class="preview-count">📸 ${photoFiles.length} фото</div>`;photoFiles.slice(0,5).forEach(f=>{const r=new FileReader();r.onload=e=>{const img=document.createElement('img');img.className='preview-img';img.src=e.target.result;preview.appendChild(img);};r.readAsDataURL(f);});}
async function onZipChange(input){if(!input.files[0])return;const zipFile=input.files[0];const preview=document.getElementById('zip-preview');preview.innerHTML=`<div class="preview-count">⏳ Распаковка...</div>`;try{const{JSZip}=await loadJSZip();const zip=await JSZip.loadAsync(zipFile);const allowed=['jpg','jpeg','png','webp','gif'];const files=[];zip.forEach((relPath,file)=>{if(file.dir)return;const ext=relPath.split('.').pop().toLowerCase();if(!allowed.includes(ext))return;files.push({path:relPath,file,lastMod:file.date||new Date(0),name:relPath.split('/').pop()});});files.sort((a,b)=>{const dt=a.lastMod-b.lastMod;if(dt!==0)return dt;return a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'});});const blobs=[];for(const{path,file}of files){const ext=path.split('.').pop().toLowerCase();const mime={'jpg':'image/jpeg','jpeg':'image/jpeg','png':'image/png','webp':'image/webp','gif':'image/gif'}[ext]||'image/jpeg';const blob=await file.async('blob');blobs.push(new File([blob],path.replace(/\//g,'_'),{type:mime,lastModified:file.date?file.date.getTime():0}));}photoFiles=blobs;preview.innerHTML=`<div class="preview-count">📦 ${blobs.length} страниц</div>`;}catch(e){preview.innerHTML=`<div class="preview-count" style="color:#ff5050">❌ ${escapeHtml(e.message)}</div>`;}}
let _jszip=null;
async function loadJSZip(){if(_jszip)return _jszip;await new Promise((res,rej)=>{const s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';s.onload=res;s.onerror=rej;document.head.appendChild(s);});_jszip=window;return _jszip;}
async function uploadOneToImgbb(blob,keys){for(const key of keys){try{const b64=await new Promise((res,rej)=>{const r=new FileReader();r.onload=e=>res(e.target.result.split(',')[1]);r.onerror=()=>rej(new Error('read error'));r.readAsDataURL(blob);});const fd=new FormData();fd.append('key',key);fd.append('image',b64);const r=await fetch('https://api.imgbb.com/1/upload',{method:'POST',body:fd});if(r.ok){const d=await r.json();if(d?.data?.url)return d.data.url;}}catch(e){}}return null;}
function showResult(type,msg){const b=document.getElementById('result-banner');if(!type){b.className='result-banner';b.innerHTML='';return;}b.className='result-banner '+type+' open';b.innerHTML=msg;b.scrollIntoView({behavior:'smooth',block:'nearest'});}
async function submitManga(){
    const title=document.getElementById('manga-title').value.trim();const desc=document.getElementById('manga-desc').value.trim();const isSeries=currentMangaType==='series';
    if(!title){showResult('error','❌ Введи название!');return;}
    if(!isSeries&&!photoFiles.length&&!coverFile){showResult('error','❌ Загрузи обложку или страницы!');return;}
    const btn=document.getElementById('submit-btn');btn.disabled=true;btn.classList.add('loading');showResult('','');
    const pb=document.getElementById('upload-progress'),pf=document.getElementById('upload-progress-fill');pb.classList.add('active');pf.style.width='2%';
    try{
        const keysRes=await fetch('/api/imgbb-keys?tg_user_id='+getTgUser());const keysData=await keysRes.json();
        if(!keysData.success||!keysData.keys?.length){showResult('error','❌ Нет доступа к ключам');btn.disabled=false;btn.classList.remove('loading');return;}
        const keys=keysData.keys;let coverUrl=null;
        if(coverFile){pf.style.width='5%';coverUrl=await uploadOneToImgbb(coverFile,keys);}
        const pageUrls=[];
        if(!isSeries&&photoFiles.length){const total=photoFiles.length;for(let i=0;i<total;i++){pf.style.width=(5+Math.round((i/total)*88))+'%';const t=btn.querySelector('.btn-text');if(t)t.textContent=`⬆️ ${i+1}/${total}`;const url=await uploadOneToImgbb(photoFiles[i],keys);if(url)pageUrls.push(url);}}
        pf.style.width='95%';
        const res=await fetch('/api/save-manga',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title,description:desc,cover_url:coverUrl,page_urls:pageUrls,tg_user_id:getTgUser(),is_series:isSeries})});
        const data=await res.json();pf.style.width='100%';
        if(data.success){showResult('success',`✅ <strong>Манга добавлена!</strong><br>${isSeries?'📚 Серия создана<br>':''}${data.pages>0?`📄 ${data.pages} страниц<br>`:''}${data.telegraph?`🔗 <a href="${escapeHtml(data.telegraph)}" target="_blank">Telegraph</a><br>`:''}<a href="${escapeHtml(data.site_url)}" target="_blank">🌐 Открыть →</a>`);setTimeout(()=>{load(true);loadNew();},1500);}
        else{showResult('error','❌ '+(data.error||'Неизвестная ошибка'));}
    }catch(e){showResult('error','❌ '+e.message);}
    const t=btn.querySelector('.btn-text');if(t)t.textContent='🚀 Опубликовать';btn.disabled=false;btn.classList.remove('loading');
}
['cover-zone','zip-zone','photos-zone'].forEach(zId=>{const z=document.getElementById(zId);if(!z)return;z.addEventListener('dragover',e=>{e.preventDefault();z.classList.add('drag');});z.addEventListener('dragleave',()=>z.classList.remove('drag'));z.addEventListener('drop',e=>{e.preventDefault();z.classList.remove('drag');const inp=z.querySelector('input[type=file]');if(inp&&e.dataTransfer.files.length){const dt=new DataTransfer();Array.from(e.dataTransfer.files).forEach(f=>dt.items.add(f));inp.files=dt.files;inp.dispatchEvent(new Event('change'));}});});

// ===== ADMIN PANEL =====
function openAdminPanel(){document.getElementById('admin-modal').classList.add('open');loadAdminStats();loadAdmins();}
function closeAdminPanel(){document.getElementById('admin-modal').classList.remove('open');}
function switchAdminTab(tab){
    const tabs=['stats','messages','edit','add-chapter'];
    document.querySelectorAll('.atab').forEach((t,i)=>t.classList.toggle('active',tabs[i]===tab));
    document.querySelectorAll('.apanel').forEach(p=>p.classList.remove('active'));
    document.getElementById('panel-'+tab).classList.add('active');
    if(tab==='stats')loadAdminStats();
    if(tab==='messages')loadAdminMessages();
    if(tab==='edit')loadMangaEditList('',0);
}
function showStatsView(view){
    document.getElementById('stats-grid-view').style.display=view==='grid'?'block':'none';
    document.getElementById('stats-archive-view').style.display=view==='archive'?'block':'none';
    document.getElementById('stats-suggestions-view').style.display=view==='suggestions'?'block':'none';
    if(view==='archive')loadArchive(0);
    if(view==='suggestions')loadSuggestions(0);
}
async function loadAdminStats(){
    try{const res=await fetch('/api/admin/stats?tg_user_id='+getTgUser());const data=await res.json();
    if(data.error){document.getElementById('stat-grid').innerHTML='<div style="color:var(--muted);grid-column:1/-1;font-size:12px">Нет прав</div>';return;}
    document.getElementById('stat-grid').innerHTML=`
        <div class="scard"><div class="scard-num">${data.manga_count}</div><div class="scard-lbl">📚 Манг</div></div>
        <div class="scard green"><div class="scard-num">${data.users_count}</div><div class="scard-lbl">👤 Юзеров</div></div>
        <div class="scard orange"><div class="scard-num">${data.votes_count}</div><div class="scard-lbl">👍 Голосов</div></div>
        <div class="scard"><div class="scard-num">${data.chapters_count}</div><div class="scard-lbl">📖 Глав</div></div>
        <div class="scard green"><div class="scard-num">${data.new_today}</div><div class="scard-lbl">🔥 Сегодня</div></div>
        <div class="scard"><div class="scard-num">${data.suggest_count}</div><div class="scard-lbl">💡 Предложек</div></div>`;
    document.getElementById('top-list').innerHTML=(data.top_manga||[]).map(m=>`<div class="top-row"><div class="top-name">${escapeHtml(m.title)}</div><div class="top-likes">♥ ${m.likes}</div></div>`).join('');
    const sb=document.getElementById('suggest-badge2');if(sb)sb.textContent=data.suggest_count>0?data.suggest_count:'';}catch(e){}
}
async function loadArchive(pg){
    try{const res=await fetch(`/api/admin/archive?page=${pg}&tg_user_id=`+getTgUser());const data=await res.json();
    document.getElementById('archive-list').innerHTML=data.items.map(a=>`<div class="aitem"><div class="atype">${escapeHtml(a.action_type)}</div><div class="atext">${escapeHtml(a.action_text)}</div><div class="adate">${new Date(a.created_at).toLocaleString('ru-RU')}</div></div>`).join('');
    const totalPages=Math.ceil(data.total/20);let pages='';if(pg>0)pages+=`<button class="page-btn" onclick="loadArchive(${pg-1})">← Назад</button>`;if(totalPages>1)pages+=`<span style="color:var(--muted);font-size:11px">${pg+1}/${totalPages}</span>`;if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadArchive(${pg+1})">Вперёд →</button>`;
    document.getElementById('archive-pagination').innerHTML=pages;}catch(e){}
}
async function loadSuggestions(pg){
    try{const res=await fetch(`/api/admin/suggestions?page=${pg}&tg_user_id=`+getTgUser());const data=await res.json();
    document.getElementById('suggest-list').innerHTML=data.items.map(s=>`<div class="sug-item"><div class="sug-text">${escapeHtml(s.text)}</div><div class="sug-meta">User #${s.user_id} · ${new Date(s.created_at).toLocaleDateString('ru-RU')}</div><button class="sug-read-btn" onclick="markSuggestion(${s.id},this)">✓ Прочитано</button></div>`).join('')||'<div style="color:var(--muted);font-size:12px">Новых нет</div>';
    const totalPages=Math.ceil(data.total/15);let pages='';if(pg>0)pages+=`<button class="page-btn" onclick="loadSuggestions(${pg-1})">← Назад</button>`;if(totalPages>1)pages+=`<span style="color:var(--muted);font-size:11px">${pg+1}/${totalPages}</span>`;if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadSuggestions(${pg+1})">Вперёд →</button>`;
    document.getElementById('suggest-pagination').innerHTML=pages;}catch(e){}
}
async function markSuggestion(id,btn){try{await fetch(`/api/admin/suggestions/${id}/status`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({status:'read',tg_user_id:getTgUser()})});btn.closest('.sug-item').remove();}catch(e){}}
async function loadAdmins(){try{const res=await fetch('/api/admin/admins?tg_user_id='+getTgUser());const data=await res.json();if(!data.admins)return;document.getElementById('admins-list').innerHTML=data.admins.map(a=>`<div class="admin-row"><div><div class="admin-tag">${escapeHtml(a.tag)}</div><div class="admin-id">ID: ${a.user_id}</div></div><button class="copy-btn" onclick="navigator.clipboard?.writeText?.('${a.user_id}');showToast('📋 Скопировано')">Копировать</button></div>`).join('');}catch(e){}}

// ===== ADMIN MESSAGES =====
async function loadAdminMessages(){
    try{const res=await fetch('/api/admin/messages?tg_user_id='+getTgUser());const data=await res.json();
    const list=document.getElementById('admin-msg-list');
    if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);font-size:12px;padding:10px 0">Сообщений нет</div>';return;}
    list.innerHTML=data.items.map(m=>`<div class="msg-item" id="amsg-${m.id}"><button class="msg-del" onclick="deleteAdminMessage(${m.id})">Удалить</button><div class="msg-text">${escapeHtml(m.text)}</div><div class="msg-meta">${new Date(m.created_at).toLocaleString('ru-RU')}</div></div>`).join('');}catch(e){}
}
async function sendAdminMessage(){
    const text=document.getElementById('admin-msg-text').value.trim();
    if(!text){showToast('Введи сообщение');return;}
    try{const res=await fetch('/api/admin/messages/send',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text,tg_user_id:getTgUser()})});const d=await res.json();if(d.success){showToast('✅ Сообщение отправлено');document.getElementById('admin-msg-text').value='';loadAdminMessages();}else showToast('❌ Ошибка');}catch(e){}
}
async function deleteAdminMessage(id){if(!confirm('Удалить сообщение?'))return;try{await fetch(`/api/admin/messages/${id}/delete`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tg_user_id:getTgUser()})});const el=document.getElementById('amsg-'+id);if(el)el.remove();showToast('🗑 Удалено');}catch(e){}}

// ===== EDIT =====
let editQuery='',editPage=0;
async function loadMangaEditList(q='',pg=0){
    editQuery=q;editPage=pg;
    try{const res=await fetch(`/api/admin/manga-list?q=${encodeURIComponent(q)}&page=${pg}&tg_user_id=`+getTgUser());const data=await res.json();
    const list=document.getElementById('manga-edit-list');
    if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);padding:10px 0;font-size:12px">Ничего не найдено</div>';document.getElementById('edit-pagination').innerHTML='';return;}
    list.innerHTML=data.items.map(m=>`<div class="meitem" onclick="openEditManga(${m.id})"><div class="me-cover">📖</div><div><div class="me-title">${escapeHtml(m.title)}</div><div class="me-meta">${m.is_series?'📚 Серия':'📄 Обычная'}</div></div></div>`).join('');
    const totalPages=Math.ceil(data.total/10);let pages='';if(pg>0)pages+=`<button class="page-btn" onclick="loadMangaEditList('${escapeHtml(editQuery)}',${pg-1})">← Назад</button>`;if(totalPages>1)pages+=`<span style="color:var(--muted);font-size:11px">${pg+1}/${totalPages}</span>`;if((pg+1)<totalPages)pages+=`<button class="page-btn" onclick="loadMangaEditList('${escapeHtml(editQuery)}',${pg+1})">Вперёд →</button>`;
    document.getElementById('edit-pagination').innerHTML=pages;}catch(e){}
}
function searchMangaEdit(){loadMangaEditList(document.getElementById('edit-search-input').value.trim(),0);}
document.getElementById('edit-search-input').addEventListener('keydown',e=>{if(e.key==='Enter')searchMangaEdit();});
async function openEditManga(mangaId){
    try{const res=await fetch(`/api/admin/manga/${mangaId}?tg_user_id=`+getTgUser());const manga=await res.json();
    document.getElementById('manga-edit-list').style.display='none';document.getElementById('edit-pagination').style.display='none';document.querySelector('#panel-edit .esearch-row').style.display='none';
    const fw=document.getElementById('edit-manga-form-wrap');fw.style.display='block';
    let chaptersHtml='';
    if(manga.is_series&&manga.chapters?.length){chaptersHtml=`<div style="margin-top:12px"><div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:6px">Главы</div><div class="ch-admin-list">${manga.chapters.map(ch=>`<div class="ch-admin-item"><span style="font-size:11px;font-weight:600">Гл. ${ch.chapter_num}${ch.title?' — '+escapeHtml(ch.title):''}</span><button class="del-ch-btn" onclick="deleteChapter(${ch.id},this)">🗑</button></div>`).join('')}</div></div>`;}
    document.getElementById('edit-manga-form').innerHTML=`
        <div class="ef"><label>Название</label><input type="text" id="ef-title" value="${escapeHtml(manga.title)}"></div>
        <div class="ef"><label>Описание</label><textarea id="ef-desc">${escapeHtml(manga.description||'')}</textarea></div>
        <div class="ef"><label>Ссылка Telegraph</label><input type="text" id="ef-link" value="${escapeHtml(manga.telegraph_url||'')}"></div>
        <div class="ef"><label>URL обложки</label><input type="text" id="ef-cover" value="${escapeHtml(manga.cover_imgbb_url||'')}"></div>
        ${manga.cover_imgbb_url?`<img src="${escapeHtml(manga.cover_imgbb_url)}" style="width:64px;height:86px;object-fit:cover;border-radius:8px;margin-bottom:9px">`:''}
        ${chaptersHtml}
        <div class="edit-actions">
            <button class="save-btn" onclick="saveMangaEdit(${mangaId})">💾 Сохранить</button>
            <button class="del-btn" onclick="deleteManga(${mangaId})">🗑 Удалить</button>
        </div>
        <div class="result-banner" id="ef-result"></div>`;}catch(e){showToast('❌ Ошибка загрузки');}
}
function backToMangaList(){document.getElementById('edit-manga-form-wrap').style.display='none';document.getElementById('manga-edit-list').style.display='flex';document.getElementById('edit-pagination').style.display='flex';document.querySelector('#panel-edit .esearch-row').style.display='flex';}
async function saveMangaEdit(mangaId){try{const res=await fetch(`/api/admin/manga/${mangaId}?tg_user_id=`+getTgUser(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title:document.getElementById('ef-title').value.trim(),description:document.getElementById('ef-desc').value.trim(),telegraph_url:document.getElementById('ef-link').value.trim(),cover_imgbb_url:document.getElementById('ef-cover').value.trim()})});const data=await res.json();const b=document.getElementById('ef-result');if(data.success){b.className='result-banner success open';b.innerHTML='✅ Сохранено!';load(true);}else{b.className='result-banner error open';b.innerHTML='❌ Ошибка';}}catch(e){showToast('❌ Ошибка');}}
async function deleteManga(mangaId){if(!confirm('Удалить эту мангу?'))return;try{const res=await fetch(`/api/admin/manga/${mangaId}/delete?tg_user_id=`+getTgUser(),{method:'POST'});const data=await res.json();if(data.success){showToast('🗑 Удалено');backToMangaList();loadMangaEditList(editQuery,editPage);load(true);}}catch(e){}}
async function deleteChapter(chapterId,btn){if(!confirm('Удалить главу?'))return;try{const res=await fetch(`/api/admin/chapter/${chapterId}/delete?tg_user_id=`+getTgUser(),{method:'POST'});const data=await res.json();if(data.success){btn.closest('.ch-admin-item').remove();showToast('🗑 Глава удалена');}}catch(e){}}

// ===== ADD CHAPTER =====
let selectedMangaId=null,chPhotoFiles=[];
async function searchMangaForChapter(){
    const q=document.getElementById('ch-manga-search').value.trim();
    try{const res=await fetch(`/api/admin/manga-list?q=${encodeURIComponent(q)}&page=0&tg_user_id=`+getTgUser());const data=await res.json();const list=document.getElementById('ch-manga-list');if(!data.items?.length){list.innerHTML='<div style="color:var(--muted);padding:9px 0;font-size:12px">Ничего не найдено</div>';return;}list.innerHTML=data.items.map(m=>`<div class="meitem" onclick="selectMangaForChapter(${m.id},'${escapeHtml(m.title).replace(/'/g,"\\'")}')"><div class="me-cover">📖</div><div><div class="me-title">${escapeHtml(m.title)}</div><div class="me-meta">${m.is_series?'📚 Серия':'📄 Обычная'}</div></div></div>`).join('');}catch(e){}
}
function selectMangaForChapter(id,title){selectedMangaId=id;document.getElementById('ch-add-form').style.display='block';document.getElementById('ch-manga-list').innerHTML=`<div style="background:rgba(124,92,255,0.07);border:1px solid rgba(124,92,255,0.22);border-radius:9px;padding:8px 11px;font-weight:600;color:var(--accent);font-size:12px">✅ ${escapeHtml(title)}</div>`;showToast('Выбрана: '+title);}
document.getElementById('ch-manga-search').addEventListener('keydown',e=>{if(e.key==='Enter')searchMangaForChapter();});
function switchChTab(tab){['zip','photos'].forEach(t=>{document.getElementById('ch-tab-'+t).classList.toggle('active',t===tab);document.getElementById('ch-panel-'+t).classList.toggle('active',t===tab);});}
function onChPhotosChange(input){chPhotoFiles=Array.from(input.files).sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'}));document.getElementById('ch-photos-preview').innerHTML=`<div class="preview-count">📸 ${chPhotoFiles.length} стр.</div>`;}
async function onChZipChange(input){if(!input.files[0])return;const preview=document.getElementById('ch-zip-preview');preview.innerHTML=`<div class="preview-count">⏳ Распаковка...</div>`;try{const{JSZip}=await loadJSZip();const zip=await JSZip.loadAsync(input.files[0]);const allowed=['jpg','jpeg','png','webp','gif'];const files=[];zip.forEach((relPath,file)=>{if(file.dir)return;const ext=relPath.split('.').pop().toLowerCase();if(!allowed.includes(ext))return;files.push({path:relPath,file,lastMod:file.date||new Date(0),name:relPath.split('/').pop()});});files.sort((a,b)=>{const dt=a.lastMod-b.lastMod;if(dt!==0)return dt;return a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'});});const blobs=[];for(const{path,file}of files){const ext=path.split('.').pop().toLowerCase();const mime={'jpg':'image/jpeg','jpeg':'image/jpeg','png':'image/png','webp':'image/webp','gif':'image/gif'}[ext]||'image/jpeg';const blob=await file.async('blob');blobs.push(new File([blob],path.replace(/\//g,'_'),{type:mime}));}chPhotoFiles=blobs;preview.innerHTML=`<div class="preview-count">📦 ${blobs.length} стр.</div>`;}catch(e){preview.innerHTML=`<div class="preview-count" style="color:#ff5050">❌ ${escapeHtml(e.message)}</div>`;}}
async function submitChapter(){
    if(!selectedMangaId){showToast('❌ Выбери мангу!');return;}
    const chNum=parseFloat(document.getElementById('ch-num').value);const chTitle=document.getElementById('ch-title-input').value.trim();
    if(!chNum||chNum<0){showToast('❌ Укажи номер!');return;}if(!chPhotoFiles.length){showToast('❌ Загрузи страницы!');return;}
    const btn=document.getElementById('ch-submit-btn');btn.disabled=true;btn.classList.add('loading');
    const pb=document.getElementById('ch-upload-progress'),pf=document.getElementById('ch-upload-fill');pb.classList.add('active');pf.style.width='2%';
    const rb=document.getElementById('ch-result-banner');rb.className='result-banner';rb.innerHTML='';
    try{
        const keysRes=await fetch('/api/imgbb-keys?tg_user_id='+getTgUser());const keysData=await keysRes.json();if(!keysData.success){showToast('❌ Нет доступа');btn.disabled=false;btn.classList.remove('loading');return;}
        const keys=keysData.keys;const pageUrls=[];const total=chPhotoFiles.length;
        for(let i=0;i<total;i++){pf.style.width=(2+Math.round((i/total)*90))+'%';const t=btn.querySelector('.btn-text');if(t)t.textContent=`⬆️ ${i+1}/${total}`;const url=await uploadOneToImgbb(chPhotoFiles[i],keys);if(url)pageUrls.push(url);}
        pf.style.width='95%';
        const res=await fetch('/api/save-chapter',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({manga_id:selectedMangaId,chapter_num:chNum,chapter_title:chTitle,page_urls:pageUrls,tg_user_id:getTgUser()})});
        const data=await res.json();pf.style.width='100%';
        if(data.success){rb.className='result-banner success open';rb.innerHTML=`✅ Глава ${chNum} добавлена! ${pageUrls.length} стр.${data.telegraph?`<br><a href="${escapeHtml(data.telegraph)}" target="_blank">📄 Telegraph</a>`:''}`;document.getElementById('ch-num').value='';document.getElementById('ch-title-input').value='';chPhotoFiles=[];document.getElementById('ch-zip-preview').innerHTML='';document.getElementById('ch-photos-preview').innerHTML='';}
        else{rb.className='result-banner error open';rb.innerHTML='❌ '+(data.error||'Ошибка');}
    }catch(e){rb.className='result-banner error open';rb.innerHTML='❌ '+e.message;}
    const t=btn.querySelector('.btn-text');if(t)t.textContent='📤 Загрузить главу';btn.disabled=false;btn.classList.remove('loading');
}

// ===== INIT =====
// Hide page loader
window.addEventListener('load',()=>{const l=document.getElementById('page-loader');if(l){l.style.opacity='0';l.style.visibility='hidden';setTimeout(()=>l.remove(),450);}});
load();loadNew();loadContinue();checkAdmin();
</script>
</body>
</html>