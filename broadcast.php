<?php
// Рассылка новинок дня MangaBot — Render + Neon PostgreSQL
error_reporting(E_ALL);
ini_set('display_errors',1);

$dsn=sprintf(
 'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
 getenv('DB_HOST'),
 getenv('DB_PORT')?:'5432',
 getenv('DB_NAME')
);
$pdo=new PDO($dsn,getenv('DB_USER'),getenv('DB_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);

$token=getenv('BOT_TOKEN');
$apiUrl="[api.telegram.org](https://api.telegram.org/bot$token)";

// --- Новые манги за 24 часа ---
$stmt=$pdo->query("SELECT * FROM manga WHERE created_at >= NOW() - INTERVAL '1 day'");
$new=$stmt->fetchAll();
if(!$new){exit("Новинок нет за последние 24 ч.\n");}

// --- Все пользователи бота ---
$users=$pdo->query("SELECT user_id FROM users")->fetchAll(PDO::FETCH_COLUMN);

foreach($users as $uid){
 echo "Рассылка пользователю $uid...\n";
 foreach($new as $m){
  $check=$pdo->prepare("SELECT 1 FROM user_manga_status WHERE user_id=? AND manga_id=? AND status='read'");
  $check->execute([$uid,$m['id']]);
  if($check->fetch())continue;

  $text="🔔 *Новинка дня!*\n\n📖 *{$m['title']}*\n\n{$m['description']}";
  $kb=json_encode([
   'inline_keyboard'=>[
     [['text'=>'✅ Прочитано','callback_data'=>'read_'.$m['id']]],
     [['text'=>'🔗 Читать','url'=>$m['file_id']]]
   ]
  ]);

  $photo=urlencode($m['cover_imgbb_url'] ?: $m['file_id']);
  $url="$apiUrl/sendPhoto?chat_id=$uid&photo=$photo&caption=".urlencode($text).
       "&parse_mode=Markdown&reply_markup=$kb";
  @file_get_contents($url);
 }
}
echo "✅ Рассылка завершена успешно.\n";
