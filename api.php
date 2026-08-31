<?php
require __DIR__ . '/bootstrap.php';
$user = require_auth();
$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') verify_csrf();

switch ($action) {
case 'note':
    $id=(int)($_GET['id']??0);
    $s=db()->prepare("SELECT n.*,f.name folder_name FROM notes n LEFT JOIN folders f ON f.id=n.folder_id WHERE n.id=?");
    $s->execute([$id]); $n=$s->fetch();
    if(!$n) json_response(['ok'=>false,'message'=>'Note not found'],404);
    json_response(['ok'=>true,'note'=>$n]);

case 'create-note':
    $data=json_decode(file_get_contents('php://input'),true)?:[];
    $folder = !empty($data['folder_id']) ? (int)$data['folder_id'] : null;
    $s=db()->prepare("INSERT INTO notes(folder_id,title,content) VALUES(?,?,?)");
    $s->execute([$folder,'Untitled note','']);
    json_response(['ok'=>true,'id'=>(int)db()->lastInsertId()]);

case 'save-note':
    $data=json_decode(file_get_contents('php://input'),true)?:[];
    $id=(int)($data['id']??0);
    $title=trim((string)($data['title']??'')) ?: 'Untitled note';
    $content=(string)($data['content']??'');
    $folder = isset($data['folder_id']) && $data['folder_id']!=='' ? (int)$data['folder_id'] : null;
    $icon=preg_match('/^fa-[a-z0-9-]+$/',(string)($data['icon']??'')) ? $data['icon'] : 'fa-note-sticky';
    $color=preg_match('/^#[A-Fa-f0-9]{6}$/',(string)($data['color']??'')) ? $data['color'] : '#6F5EE8';
    $pinned=!empty($data['is_pinned'])?1:0;
    $s=db()->prepare("UPDATE notes SET folder_id=?,title=?,content=?,icon=?,color=?,is_pinned=?,updated_at=NOW() WHERE id=?");
    $s->execute([$folder,$title,$content,$icon,$color,$pinned,$id]);
    json_response(['ok'=>true,'updated_at'=>date('c')]);

case 'delete-note':
    $data=json_decode(file_get_contents('php://input'),true)?:[];
    db()->prepare("DELETE FROM notes WHERE id=?")->execute([(int)($data['id']??0)]);
    json_response(['ok'=>true]);

case 'folder':
    $data=json_decode(file_get_contents('php://input'),true)?:[];
    $name=trim((string)($data['name']??''));
    if(!$name) json_response(['ok'=>false,'message'=>'Folder name required'],422);
    $icon=preg_match('/^fa-[a-z0-9-]+$/',(string)($data['icon']??''))?$data['icon']:'fa-folder';
    $color=preg_match('/^#[A-Fa-f0-9]{6}$/',(string)($data['color']??''))?$data['color']:'#6F5EE8';
    $s=db()->prepare("INSERT INTO folders(name,icon,color,sort_order) VALUES(?,?,?,999)");
    $s->execute([$name,$icon,$color]);
    json_response(['ok'=>true,'id'=>(int)db()->lastInsertId(),'name'=>$name,'icon'=>$icon,'color'=>$color]);

case 'search':
    $q=trim($_GET['q']??'');
    $folder=$_GET['folder']??'';
    $pinned=$_GET['pinned']??'';
    $sql="SELECT n.id,n.folder_id,n.title,n.content,n.icon,n.color,n.is_pinned,n.updated_at,f.name folder_name FROM notes n LEFT JOIN folders f ON f.id=n.folder_id WHERE 1=1";
    $params=[];
    if($q!==''){ $sql.=" AND (n.title LIKE ? OR n.content LIKE ?)"; $params[]="%$q%";$params[]="%$q%"; }
    if($folder!==''){ $sql.=" AND n.folder_id=?";$params[]=(int)$folder; }
    if($pinned==='1'){ $sql.=" AND n.is_pinned=1"; }
    $sql.=" ORDER BY n.is_pinned DESC,n.updated_at DESC LIMIT 100";
    $s=db()->prepare($sql);$s->execute($params);
    json_response(['ok'=>true,'notes'=>$s->fetchAll()]);

case 'upload':
    if(empty($_FILES['image']) || $_FILES['image']['error']!==UPLOAD_ERR_OK) json_response(['ok'=>false,'message'=>'Upload failed'],422);
    if($_FILES['image']['size']>8*1024*1024) json_response(['ok'=>false,'message'=>'Image must be under 8MB'],422);
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($_FILES['image']['tmp_name']);
    $map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if(!isset($map[$mime])) json_response(['ok'=>false,'message'=>'Unsupported image type'],422);
    $ym=date('Y/m');$dir=__DIR__."/uploads/$ym";if(!is_dir($dir)) mkdir($dir,0755,true);
    $name=bin2hex(random_bytes(12)).'.'.$map[$mime];
    if(!move_uploaded_file($_FILES['image']['tmp_name'],"$dir/$name")) json_response(['ok'=>false,'message'=>'Could not save image'],500);
    global $config;
    json_response(['ok'=>true,'url'=>rtrim($config['app']['url'],'/')."/uploads/$ym/$name"]);

case 'settings':
    $data=json_decode(file_get_contents('php://input'),true)?:[];
    $existing=db()->query("SELECT * FROM settings WHERE id=1")->fetch();
    $pass=($data['smtp_pass']??'')!==''?$data['smtp_pass']:($existing['smtp_pass']??null);
    $s=db()->prepare("UPDATE settings SET app_name=?,smtp_host=?,smtp_port=?,smtp_security=?,smtp_user=?,smtp_pass=?,smtp_from=? WHERE id=1");
    $s->execute([
        trim($data['app_name']??'Memoir')?:'Memoir',
        trim($data['smtp_host']??'')?:null,
        (int)($data['smtp_port']??587),
        in_array(($data['smtp_security']??'tls'),['tls','ssl','none'],true)?$data['smtp_security']:'tls',
        trim($data['smtp_user']??'')?:null,
        $pass,
        trim($data['smtp_from']??'')?:null
    ]);
    json_response(['ok'=>true]);

default:
    json_response(['ok'=>false,'message'=>'Unknown action'],404);
}
