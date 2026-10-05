<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
function reply($status,$body){http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE);exit;}
try {
$method=$_SERVER['REQUEST_METHOD'];
if(!in_array($method,['GET','POST'],true))reply(405,['error'=>'Метод не поддерживается']);
if($method==='POST' && (($_SERVER['HTTP_ORIGIN']??'')!=='https://3d-net.ru' || !str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')))reply(403,['error'=>'Запрос запрещён']);
$catalog=json_decode(file_get_contents(__DIR__.'/articles.json'),true,512,JSON_THROW_ON_ERROR);
$ids=array_column($catalog,'id');
$directory=dirname(__DIR__).'/.3d-net-likes';
if(!is_dir($directory) && !mkdir($directory,0700,true))throw new Exception('Storage');
$db=new PDO('sqlite:'.$directory.'/likes.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA busy_timeout=5000');
$db->exec('CREATE TABLE IF NOT EXISTS votes(article TEXT NOT NULL, visitor TEXT NOT NULL, PRIMARY KEY(article,visitor));CREATE TABLE IF NOT EXISTS rate(bucket TEXT PRIMARY KEY, started INTEGER, hits INTEGER);CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT)');
$db->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('salt','".bin2hex(random_bytes(32))."')");
if($method==='GET' && ($_GET['summary']??'')==='1'){
 $counts=[];foreach($ids as $id)$counts[$id]=0;foreach($db->query('SELECT article,COUNT(*) AS total FROM votes GROUP BY article') as $r)if(isset($counts[$r['article']]))$counts[$r['article']]=(int)$r['total'];reply(200,['counts'=>$counts]);
}
$input=$method==='POST'?file_get_contents('php://input',false,null,0,4097):[];
if($method==='POST'){if(strlen($input)>4096)reply(413,['error'=>'Запрос слишком большой']);$input=json_decode($input,true,16,JSON_THROW_ON_ERROR);}
$article=$method==='POST'?($input['article']??''):($_GET['article']??'');
if(!is_string($article)||!in_array($article,$ids,true))reply(404,['error'=>'Статья не найдена']);
$visitor=$_COOKIE['site_visitor']??'';if(!preg_match('/^[a-f0-9]{64}$/',$visitor)){$visitor=bin2hex(random_bytes(32));setcookie('site_visitor',$visitor,['expires'=>time()+31536000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);}
$visitor=hash('sha256',$visitor);
if($method==='POST'){
 if(!isset($input['liked'])||!is_bool($input['liked']))reply(400,['error'=>'Укажите реакцию']);
 $salt=$db->query("SELECT value FROM settings WHERE key='salt'")->fetchColumn();$now=time();$bucket=hash_hmac('sha256',($_SERVER['REMOTE_ADDR']??'').date('Y-m-d'),$salt);
 $db->beginTransaction();$db->exec('DELETE FROM rate WHERE started < '.($now-86400));
 $q=$db->prepare('INSERT INTO rate(bucket,started,hits) VALUES(?,?,1) ON CONFLICT(bucket) DO UPDATE SET hits=CASE WHEN started<? THEN 1 ELSE hits+1 END,started=CASE WHEN started<? THEN ? ELSE started END');$q->execute([$bucket,$now,$now-60,$now-60,$now]);
 $q=$db->prepare('SELECT hits FROM rate WHERE bucket=?');$q->execute([$bucket]);if($q->fetchColumn()>30){$db->commit();header('Retry-After: 60');reply(429,['error'=>'Подождите минуту']);}
 $q=$db->prepare($input['liked']?'INSERT OR IGNORE INTO votes(article,visitor) VALUES(?,?)':'DELETE FROM votes WHERE article=? AND visitor=?');$q->execute([$article,$visitor]);$db->commit();
}
$q=$db->prepare('SELECT COUNT(*) FROM votes WHERE article=?');$q->execute([$article]);$count=(int)$q->fetchColumn();$q=$db->prepare('SELECT COUNT(*) FROM votes WHERE article=? AND visitor=?');$q->execute([$article,$visitor]);reply(200,['count'=>$count,'liked'=>(bool)$q->fetchColumn()]);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();reply(500,['error'=>'Не удалось загрузить реакции. Попробуйте позже.']);}
